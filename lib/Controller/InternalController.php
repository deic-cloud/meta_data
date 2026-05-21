<?php

declare(strict_types=1);

namespace OCA\MetaData\Controller;

use OCA\MetaData\Db\MetaKey;
use OCA\MetaData\Db\MetaKeyMapper;
use OCA\MetaData\Db\TagExtraMapper;
use OCA\MetaData\Service\IShardingAdapter;
use OCA\MetaData\Service\TagSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagNotFoundException;

/**
 * Shared-secret-gated endpoints for tag-schema synchronisation between nodes.
 * When running on master, write operations are relayed to all silos so that
 * silo → master → silos propagation works (silos only know the master URL).
 *
 * Tag identity across nodes is by NAME, not by systemtag ID (IDs differ per node).
 */
class InternalController extends Controller {
	public function __construct(
		string                  $appName,
		IRequest                $request,
		private ISystemTagManager $systemTagManager,
		private MetaKeyMapper   $keyMapper,
		private TagExtraMapper  $tagExtraMapper,
		private TagSyncService  $syncService,
		private IShardingAdapter $sharding,
		private IConfig         $config,
		private IDBConnection   $db,
	) {
		parent::__construct($appName, $request);
	}

	private function checkSecret(): ?JSONResponse {
		$secret = (string)$this->config->getSystemValue('files_sharding_shared_secret', '');
		if ($secret === '' || $this->request->getHeader('Authorization') !== 'Bearer ' . $secret) {
			return new JSONResponse(['message' => 'Unauthorized'], 401);
		}
		return null;
	}

	/**
	 * Upsert a tag schema (tag + keys) identified by name.
	 * Keys are synced by name: existing keys are updated, new ones inserted,
	 * removed ones deleted — all without touching meta_data_docKeys IDs.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function syncTag(
		string $name,
		string $color       = '',
		string $description = '',
		string $keys        = '[]',
	): JSONResponse {
		if ($err = $this->checkSecret()) return $err;

		if (trim($name) === '') {
			return new JSONResponse(['message' => 'name is required'], 400);
		}

		$keysData = json_decode($keys, true);
		if (!is_array($keysData)) {
			return new JSONResponse(['message' => 'invalid keys payload'], 400);
		}

		// ── Upsert system tag ─────────────────────────────────────────────────
		$localTagId = $this->upsertSystemTag($name, $color);

		// ── Upsert description ────────────────────────────────────────────────
		$this->tagExtraMapper->upsert($localTagId, $description);

		// ── Sync keys by name ─────────────────────────────────────────────────
		$this->syncKeys($localTagId, $keysData);

		// ── Relay (master only) ───────────────────────────────────────────────
		if ($this->sharding->isMaster()) {
			$this->syncService->pushTagToAllSilos($name, $color, $description, $keysData);
		}

		return new JSONResponse(['status' => 'ok']);
	}

	/** Delete a tag (and its keys/extras) identified by name. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteTag(string $name = ''): JSONResponse {
		if ($err = $this->checkSecret()) return $err;

		if (trim($name) === '') {
			return new JSONResponse(['message' => 'name is required'], 400);
		}

		$localTagId = $this->localTagIdByName($name);
		if ($localTagId !== null) {
			try {
				$this->systemTagManager->deleteTags([(string)$localTagId]);
			} catch (TagNotFoundException) {}
			$this->tagExtraMapper->deleteBySystemTagId($localTagId);
			$this->keyMapper->deleteByTagId($localTagId);
		}

		if ($this->sharding->isMaster()) {
			$this->syncService->deleteTagOnAllSilos($name);
		}

		return new JSONResponse(['status' => 'ok']);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Return local oc_systemtag.id for the given name, creating or updating as needed.
	 * Uses direct DB writes to avoid ISystemTagManager permission checks on public pages.
	 */
	private function upsertSystemTag(string $name, string $color): int {
		$truncated = substr($name, 0, 64);
		$existing  = $this->localTagIdByName($name);

		if ($existing !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('systemtag')
				->set('name',       $qb->createNamedParameter($truncated))
				->set('visibility', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->set('editable',   $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->set('color',      $qb->createNamedParameter($color !== '' ? $color : null))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($existing, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			return $existing;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert('systemtag')
			->values([
				'name'       => $qb->createNamedParameter($truncated),
				'visibility' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'editable'   => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'etag'       => $qb->createNamedParameter(md5((string)time())),
				'color'      => $qb->createNamedParameter($color !== '' ? $color : null),
			]);
		try {
			$qb->executeStatement();
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				// race: another node inserted it first
				return $this->localTagIdByName($name)
					?? throw new \RuntimeException("Tag '{$name}' not found after race");
			}
			throw $e;
		}
		return (int)$qb->getLastInsertId();
	}

	private function localTagIdByName(string $name): ?int {
		foreach ($this->systemTagManager->getAllTags(true, $name) as $tag) {
			if ($tag->getName() === $name) {
				return (int)$tag->getId();
			}
		}
		return null;
	}

	/**
	 * Sync keys for a local tag ID.
	 * Updates existing keys (by name), inserts new ones, deletes removed ones.
	 * Local key IDs are preserved so meta_data_docKeys references stay valid.
	 *
	 * @param array{name:string,type:string,allowedValues:string}[] $keysData
	 */
	private function syncKeys(int $localTagId, array $keysData): void {
		// Index existing keys by name.
		$existing = [];
		foreach ($this->keyMapper->findByTag($localTagId) as $k) {
			$existing[$k->getName()] = $k;
		}

		$syncedNames = [];
		foreach ($keysData as $kd) {
			$kName   = (string)($kd['name']          ?? '');
			$kType   = (string)($kd['type']           ?? '');
			$kAllowed = (string)($kd['allowedValues'] ?? '');
			if ($kName === '') continue;
			$syncedNames[] = $kName;

			if (isset($existing[$kName])) {
				$key = $existing[$kName];
				$key->setType($kType);
				$key->setAllowedValues($kAllowed);
				$this->keyMapper->update($key);
			} else {
				$key = new MetaKey();
				$key->setTagid($localTagId);
				$key->setName($kName);
				$key->setType($kType);
				$key->setAllowedValues($kAllowed);
				$this->keyMapper->insert($key);
			}
		}

		// Remove keys that no longer exist on master.
		$this->keyMapper->deleteByTagIdNotInNames($localTagId, $syncedNames);
	}
}
