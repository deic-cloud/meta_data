<?php

declare(strict_types=1);

namespace OCA\MetaData\Service;

use OCA\MetaData\Db\DocKey;
use OCA\MetaData\Db\DocKeyMapper;
use OCA\MetaData\Db\MetaKey;
use OCA\MetaData\Db\MetaKeyMapper;
use OCA\MetaData\Db\TagExtraMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

class TagService {
	public function __construct(
		private ISystemTagManager $systemTagManager,
		private ISystemTagObjectMapper $systemTagObjectMapper,
		private TagExtraMapper $tagExtraMapper,
		private MetaKeyMapper $keyMapper,
		private DocKeyMapper $docKeyMapper,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
		private ?TagSyncService $syncService = null,
		private ?IConfig $config = null,
		private ?IClientService $clientService = null,
		private ?IDBConnection $db = null,
	) {
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** @param array<int, array{description: string, created_by: string}> $extras */
	private function tagToArray(ISystemTag $tag, array $extras = []): array {
		$e = $extras[(int)$tag->getId()] ?? ['description' => '', 'created_by' => ''];
		return [
			'id'             => (int)$tag->getId(),
			'name'           => $tag->getName(),
			'description'    => $e['description'],
			'owner'          => $e['created_by'],
			'updated_at'     => $e['updated_at'] ?? 0,
			'color'          => $tag->getColor() ?? '',
			'userVisible'    => $tag->isUserVisible(),
			'userAssignable' => $tag->isUserAssignable(),
		];
	}

	// ── Ownership ────────────────────────────────────────────────────────────

	/**
	 * May $uid change this tag (rename, description, colour, fields, delete)?
	 * Only its owner or an admin; a tag without owner (seeded schemas, tags from
	 * before ownership) is admin-only. Assigning tags to files is not gated.
	 */
	public function canEdit(int $tagId, string $uid, bool $isAdmin): bool {
		if ($isAdmin) {
			return true;
		}
		if ($uid === '') {
			return false;
		}
		$extras = $this->tagExtraMapper->findExtrasByIds([$tagId]);
		$owner  = $extras[$tagId]['created_by'] ?? '';
		return $owner !== '' && $owner === $uid;
	}

	public function setOwner(int $tagId, string $uid): void {
		$this->tagExtraMapper->setOwner($tagId, $uid);
		$this->pushSync($tagId);
	}

	// ── Sync helper ──────────────────────────────────────────────────────────

	/**
	 * A LOCAL change to a tag's schema: stamp a new version, then push the
	 * full schema to the peers. Receivers ignore snapshots older than the one
	 * they hold, so concurrent pushes cannot roll a schema back (the 2026-09-13
	 * field-loss race); the master never echoes a snapshot to its origin.
	 */
	private function pushSync(int $tagId): void {
		$this->tagExtraMapper->touch($tagId);
		if ($this->syncService === null) return;
		$tag = $this->getTagById($tagId);
		if ($tag === null) return;
		$keys = array_map(fn(array $k) => [
			'name'          => $k['name'],
			'type'          => $k['type'],
			'allowedValues' => $k['allowed_values'] ?? '',
		], $this->getKeys($tagId));
		$this->syncService->pushTagToAllSilos(
			$tag['name'],
			$tag['color'],
			$tag['description'],
			$keys,
			$tag['owner'],
			(int)$tag['updated_at'],
			$this->syncService->selfUrl(),
		);
	}

	// ── Name / path resolution ───────────────────────────────────────────────

	/** Resolve a tag name to its system-tag ID, or null if not found. */
	public function getTagIdByName(string $name): ?int {
		foreach ($this->systemTagManager->getAllTags(true, $name) as $tag) {
			if ($tag->getName() === $name) {
				return (int)$tag->getId();
			}
		}
		return null;
	}

	/** Resolve a key name to its row ID within a given tag, or null if not found. */
	public function getKeyIdByName(int $tagId, string $keyName): ?int {
		foreach ($this->keyMapper->findByTag($tagId) as $key) {
			if ($key->getName() === $keyName) {
				return $key->getId();
			}
		}
		return null;
	}

	/**
	 * Ids of the fields named $keyName (case-insensitive), in $tagId or, when
	 * null, in any tag — the FIELD:VALUE search criterion.
	 * @return int[]
	 */
	public function findKeyIdsByName(string $keyName, ?int $tagId = null): array {
		return array_map(fn(MetaKey $k) => (int)$k->getId(), $this->keyMapper->findByName($keyName, $tagId));
	}

	/** Resolve a user-relative file path to a file ID, or null if not found. */
	public function resolveFilePath(string $path, string $userId): ?int {
		try {
			return $this->rootFolder->getUserFolder($userId)->get($path)->getId();
		} catch (\Throwable) {
			return null;
		}
	}

	// ── Tag CRUD ──────────────────────────────────────────────────────────────

	/** @return array[] */
	public function searchTags(string $pattern, bool $withFileCount = false): array {
		$namePattern = ($pattern === '%' || $pattern === '') ? null : $pattern;
		$tags = $this->systemTagManager->getAllTags(true, $namePattern);

		if (empty($tags)) {
			return [];
		}

		$extras = $this->tagExtraMapper->findExtrasByIds(
			array_map(fn(ISystemTag $t) => (int)$t->getId(), $tags)
		);

		return array_values(array_map(function (ISystemTag $t) use ($extras, $withFileCount): array {
			$data = $this->tagToArray($t, $extras);
			if ($withFileCount) {
				$fileIds = $this->systemTagObjectMapper->getObjectIdsForTags([$t->getId()], 'files');
				$data['size'] = count($fileIds);
			}
			return $data;
		}, $tags));
	}

	public function getTagById(int $tagId): ?array {
		try {
			$tags = $this->systemTagManager->getTagsByIds([(string)$tagId]);
			$tag = reset($tags);
			if (!$tag) {
				return null;
			}
			$extras = $this->tagExtraMapper->findExtrasByIds([$tagId]);
			return $this->tagToArray($tag, $extras);
		} catch (TagNotFoundException) {
			return null;
		}
	}

	/** @return array<int, array> */
	public function getTagsByIds(array $ids): array {
		if (empty($ids)) {
			return [];
		}
		try {
			$tags = $this->systemTagManager->getTagsByIds(array_map('strval', $ids));
		} catch (TagNotFoundException) {
			return [];
		}
		$extras = $this->tagExtraMapper->findExtrasByIds(
			array_map(fn(ISystemTag $t) => (int)$t->getId(), $tags)
		);
		$result = [];
		foreach ($tags as $tag) {
			$result[(int)$tag->getId()] = $this->tagToArray($tag, $extras);
		}
		return $result;
	}

	public function newTag(string $name, string $color = '', string $owner = ''): ?array {
		if (trim($name) === '') {
			return null;
		}
		try {
			$tag = $this->systemTagManager->createTag($name, true, true);
		} catch (TagAlreadyExistsException) {
			return null;
		}
		// Owner from the start ('' for seeded schemas → admin-only).
		$this->tagExtraMapper->upsert((int)$tag->getId(), '', $owner);
		if ($color !== '') {
			$this->systemTagManager->updateTag($tag->getId(), $name, true, true, $color);
			try {
				$tags = $this->systemTagManager->getTagsByIds([$tag->getId()]);
				$tag = reset($tags);
			} catch (TagNotFoundException) {
				return null;
			}
		}
		// Re-read so the result carries description/owner from the extras row.
		$result = $this->getTagById((int)$tag->getId()) ?? $this->tagToArray($tag);
		$this->pushSync((int)$tag->getId());
		return $result;
	}

	public function updateTag(int $tagId, ?string $name, ?string $description, ?string $color): bool {
		try {
			$tags = $this->systemTagManager->getTagsByIds([(string)$tagId]);
			$tag = reset($tags);
		} catch (TagNotFoundException) {
			return false;
		}
		$this->systemTagManager->updateTag(
			(string)$tagId,
			$name ?? $tag->getName(),
			$tag->isUserVisible(),
			$tag->isUserAssignable(),
			$color ?? $tag->getColor(),
		);
		if ($description !== null) {
			$this->tagExtraMapper->upsert($tagId, $description);
		}
		$this->pushSync($tagId);
		return true;
	}

	public function deleteTag(int $tagId): bool {
		$tag = $this->getTagById($tagId);
		try {
			$this->systemTagManager->deleteTags([(string)$tagId]);
		} catch (TagNotFoundException) {
			return false;
		}
		$this->tagExtraMapper->deleteBySystemTagId($tagId);
		$this->docKeyMapper->deleteByTagId($tagId);
		$this->keyMapper->deleteByTagId($tagId);
		if ($tag !== null) {
			$this->syncService?->deleteTagOnAllSilos($tag['name'], $this->syncService->selfUrl());
		}
		return true;
	}

	// ── Key CRUD ──────────────────────────────────────────────────────────────

	/** @return array[] */
	public function getKeys(int $tagId): array {
		return array_map(
			fn(MetaKey $k) => $k->jsonSerialize(),
			$this->keyMapper->findByTag($tagId)
		);
	}

	public function getKeyById(int $keyId): ?array {
		try {
			return $this->keyMapper->findById($keyId)->jsonSerialize();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return array[] */
	public function getKeysByIds(array $ids): array {
		$result = [];
		foreach ($this->keyMapper->findByIds($ids) as $key) {
			$result[$key->getId()] = $key->jsonSerialize();
		}
		return $result;
	}

	public function newKey(int $tagId, string $keyName, string $type = '', string $allowedValues = ''): ?array {
		$keyName = trim($keyName);
		if ($keyName === '') {
			return null;
		}
		// (tagid, name) is unique: an existing field of that name is returned as is.
		foreach ($this->keyMapper->findByTag($tagId) as $existing) {
			if ($existing->getName() === $keyName) {
				return $existing->jsonSerialize();
			}
		}
		$key = new MetaKey();
		$key->setTagid($tagId);
		$key->setName($keyName);
		$key->setType($type);
		$key->setAllowedValues($allowedValues);
		try {
			$saved = $this->keyMapper->insert($key);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// lost a race with a concurrent insert of the same name — use that one
			foreach ($this->keyMapper->findByTag($tagId) as $existing) {
				if ($existing->getName() === $keyName) {
					return $existing->jsonSerialize();
				}
			}
			throw $e;
		}
		$this->pushSync($tagId);
		return $saved->jsonSerialize();
	}

	public function updateKey(int $tagId, int $keyId, string $keyName, string $type = '', string $allowedValues = ''): bool {
		try {
			$key = $this->keyMapper->findById($keyId);
		} catch (DoesNotExistException) {
			return false;
		}
		if ($key->getTagid() !== $tagId) {
			return false;
		}
		$key->setName($keyName);
		if ($type !== '') {
			$key->setType($type);
		}
		if ($allowedValues !== '') {
			$key->setAllowedValues($allowedValues);
		}
		$this->keyMapper->update($key);
		$this->pushSync($tagId);
		return true;
	}

	public function deleteKey(int $tagId, int $keyId): void {
		$this->keyMapper->deleteByTagAndId($tagId, $keyId);
		$this->pushSync($tagId);
	}

	// ── File-Tag associations ─────────────────────────────────────────────────

	/**
	 * @param int[] $fileIds
	 * @return array<int, array{id: int, name: string, color: string}[]>
	 */
	public function getFileTags(array $fileIds): array {
		$tagIdsPerFile = $this->systemTagObjectMapper->getTagIdsForObjects(
			array_map('strval', $fileIds),
			'files'
		);

		$allTagIds = [];
		foreach ($tagIdsPerFile as $ids) {
			foreach ($ids as $id) {
				$allTagIds[] = (int)$id;
			}
		}
		$tagIndex = $this->getTagsByIds(array_unique($allTagIds));

		$result = [];
		foreach ($fileIds as $fid) {
			$result[$fid] = [];
			foreach ($tagIdsPerFile[(string)$fid] ?? [] as $tid) {
				$intTid = (int)$tid;
				if (isset($tagIndex[$intTid])) {
					$result[$fid][] = $tagIndex[$intTid];
				}
			}
		}
		return $result;
	}

	/**
	 * Returns tags for a file on the local silo by share token + internal path.
	 * Used by InternalController when a remote silo queries for a federated file's tags.
	 *
	 * @return array{id: int, name: string, color: string}[]|null  null if share/file not found
	 */
	public function getFileTagsByShareToken(string $token, string $internalPath): ?array {
		if ($this->db === null) {
			return null;
		}

		// Look up the share by token directly (works for all share types including TYPE_REMOTE).
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('uid_owner', 'item_source')
			   ->from('share')
			   ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
			$cursor = $qb->executeQuery();
			$row    = $cursor->fetch();
			$cursor->closeCursor();
		} catch (\Throwable) {
			return null;
		}

		if (!$row) {
			return null;
		}

		$owner  = (string)$row['uid_owner'];
		$fileId = (int)$row['item_source'];

		try {
			$shareNodes = $this->rootFolder->getUserFolder($owner)->getById($fileId);
			$shareNode  = $shareNodes[0] ?? null;
			if ($shareNode === null) {
				return null;
			}
			$fileNode = ($internalPath !== '' && $internalPath !== '.')
				? $shareNode->get($internalPath)
				: $shareNode;
		} catch (\Throwable) {
			return null;
		}

		$tagsPerFile = $this->getFileTags([$fileNode->getId()]);
		$tags = $tagsPerFile[$fileNode->getId()] ?? [];

		// Attach the metadata VALUES per tag, keyed by key NAME — names are the
		// cross-node identity (numeric tag/key ids differ between nodes).
		$fid = $fileNode->getId();
		foreach ($tags as &$tag) {
			$tagId = (int)($tag['id'] ?? 0);
			if ($tagId <= 0) {
				continue;
			}
			$keyNames = [];
			foreach ($this->getKeys($tagId) as $k) {
				$keyNames[(int)$k['id']] = (string)$k['name'];
			}
			$values = [];
			foreach ($this->getFileKeys($fid, $tagId) as $row) {
				$kid = (int)($row['keyid'] ?? 0);
				$val = (string)($row['value'] ?? '');
				if ($val !== '' && isset($keyNames[$kid])) {
					$values[$keyNames[$kid]] = $val;
				}
			}
			if ($values !== []) {
				$tag['values'] = $values;
			}
		}
		unset($tag);
		return $tags;
	}

	/**
	 * For a locally-mounted external (federated) share, query the origin silo for
	 * the file's tags and translate them to local tag IDs by name.
	 *
	 * Returns null if the file is not a remote share, or if the lookup fails.
	 *
	 * @return array{id: int, name: string, color: string}[]|null
	 */
	public function getRemoteFileTags(int $fileId, string $userId): ?array {
		$remoteTags = $this->fetchRemoteTagData($fileId, $userId);
		if ($remoteTags === null) {
			return null;
		}

		// Translate remote tag names to local IDs
		$localTags = [];
		foreach ($remoteTags as $remoteTag) {
			$name = (string)($remoteTag['name'] ?? '');
			if ($name === '') {
				continue;
			}
			$localId = $this->getTagIdByName($name);
			if ($localId !== null) {
				$localTagInfo = $this->getTagById($localId);
				if ($localTagInfo !== null) {
					$localTags[] = $localTagInfo;
				}
			}
		}

		return $localTags;
	}

	/**
	 * Metadata VALUES of a federated file, translated to the LOCAL key ids of
	 * $localTagId — same [{keyid, value}] shape as getFileKeys(), so callers can
	 * fall back to it transparently when the local table has nothing (the file
	 * lives on the owner\'s node; values are read through by share token, like
	 * tags already were).
	 *
	 * @return array<array{keyid:int, value:string}>|null null = not a federated
	 *         file / remote unreachable (callers keep their local result)
	 */
	public function getRemoteFileKeys(int $fileId, string $userId, int $localTagId): ?array {
		$remoteTags = $this->fetchRemoteTagData($fileId, $userId);
		if ($remoteTags === null) {
			return null;
		}
		$localTag = $this->getTagById($localTagId);
		$tagName  = (string)($localTag['name'] ?? '');
		if ($tagName === '') {
			return null;
		}
		foreach ($remoteTags as $remoteTag) {
			if ((string)($remoteTag['name'] ?? '') !== $tagName) {
				continue;
			}
			$values = $remoteTag['values'] ?? [];
			if (!is_array($values) || $values === []) {
				return [];
			}
			// key NAMES → local key ids
			$idByName = [];
			foreach ($this->getKeys($localTagId) as $k) {
				$idByName[(string)$k['name']] = (int)$k['id'];
			}
			$rows = [];
			foreach ($values as $name => $value) {
				if (isset($idByName[(string)$name]) && (string)$value !== '') {
					$rows[] = ['keyid' => $idByName[(string)$name], 'value' => (string)$value];
				}
			}
			return $rows;
		}
		return [];
	}

	/**
	 * Resolve a local fileid belonging to a federated mount to its owner node and
	 * fetch the owner\'s tag+values data by share token. null = not federated /
	 * secret unset / remote unreachable.
	 */
	private function fetchRemoteTagData(int $fileId, string $userId): ?array {
		$loc = $this->federatedLocation($fileId, $userId);
		if ($loc === null) {
			return null;
		}
		$body = $this->callOwner($loc['remote'], 'internal/filetags-by-token', ['token' => $loc['token'], 'path' => $loc['path']]);
		if (!is_array($body) || !isset($body['tags'])) {
			return null;
		}
		return is_array($body['tags']) ? $body['tags'] : [];
	}

	/**
	 * Where a file of $userId's lives when it is in a share they received from
	 * another node: the owner node's URL, the share token and the path inside
	 * the share. Null for anything else (their own files, local shares) or when
	 * no shared secret is configured.
	 *
	 * @return array{remote: string, token: string, path: string}|null
	 */
	public function federatedLocation(int $fileId, string $userId): ?array {
		if ($userId === '' || $this->config === null || $this->clientService === null || $this->db === null) {
			return null;
		}
		if ((string)$this->config->getSystemValue('files_sharding_shared_secret', '') === '') {
			return null;
		}

		// Resolve external share info via DB to avoid triggering the file-node
		// API chain that loads the DAV contacts manager (throws in NC34).
		try {
			// 1. storage numeric id + file path from filecache
			$qb = $this->db->getQueryBuilder();
			$qb->select('storage', 'path')
				->from('filecache')
				->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
			$cursor = $qb->executeQuery();
			$fcRow  = $cursor->fetch();
			$cursor->closeCursor();
			if (!$fcRow) {
				return null;
			}
			$storageNumericId = (int)$fcRow['storage'];
			$internalPath     = (string)$fcRow['path'];

			// 2. storage string id (shared::<hash> for federated shares)
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('storages')
				->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($storageNumericId, IQueryBuilder::PARAM_INT)));
			$cursor = $qb->executeQuery();
			$stRow  = $cursor->fetch();
			$cursor->closeCursor();
			if (!$stRow || !str_starts_with((string)$stRow['id'], 'shared::')) {
				return null;
			}
			$storageHash = substr((string)$stRow['id'], 8);

			// 3. match against this user's external shares
			// Storage ID = 'shared::' . md5($token . '@' . rtrim($remote, '/'))
			$qb = $this->db->getQueryBuilder();
			$qb->select('remote', 'share_token')
				->from('share_external')
				->where($qb->expr()->eq('user', $qb->createNamedParameter($userId)));
			$cursor = $qb->executeQuery();
			$remote = null;
			$token  = null;
			while ($row = $cursor->fetch()) {
				if (md5($row['share_token'] . '@' . rtrim((string)$row['remote'], '/')) === $storageHash) {
					$remote = rtrim((string)$row['remote'], '/');
					$token  = (string)$row['share_token'];
					break;
				}
			}
			$cursor->closeCursor();
		} catch (\Throwable) {
			return null;
		}

		if ($remote === null || $token === null) {
			return null;
		}
		return ['remote' => $remote, 'token' => $token, 'path' => $internalPath];
	}

	/** POST to the owner node's meta_data internal API (shared-secret). Null on any failure. */
	private function callOwner(string $remote, string $path, array $json): ?array {
		$secret = (string)($this->config?->getSystemValue('files_sharding_shared_secret', '') ?? '');
		if ($secret === '' || $this->clientService === null) {
			return null;
		}
		try {
			$response = $this->clientService->newClient()->post($remote . '/index.php/apps/meta_data/' . $path, [
				'json'    => $json,
				'headers' => ['Authorization' => 'Bearer ' . $secret],
				'timeout' => 5,
				'connect_timeout' => 3,
				'verify'  => true,
			]);
			$body = json_decode((string)$response->getBody(), true);
			return is_array($body) ? $body : null;
		} catch (\Throwable $e) {
			// A refusal (4xx) carries its reason in the body.
			$resp = method_exists($e, 'getResponse') ? $e->getResponse() : null;
			$body = $resp !== null ? json_decode((string)$resp->getBody(), true) : null;
			if (is_array($body)) {
				return $body;
			}
			$this->logger->debug('meta_data: ' . $path . ' at ' . $remote . ' failed: ' . $e->getMessage());
			return null;
		}
	}

	public function addFileTag(int $fileId, int $tagId): void {
		$this->systemTagObjectMapper->assignTags((string)$fileId, 'files', [$tagId]);
	}

	public function removeFileTag(int $fileId, int $tagId): void {
		$this->systemTagObjectMapper->unassignTags((string)$fileId, 'files', [$tagId]);
	}

	/**
	 * Returns file info for all files tagged with $tagId that the user can access.
	 * @return array[]
	 */
	public function getTaggedFiles(int $tagId, string $userId): array {
		$fileIds = $this->systemTagObjectMapper->getObjectIdsForTags([(string)$tagId], 'files');
		if (empty($fileIds)) {
			return [];
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$result = [];

		foreach ($fileIds as $fileId) {
			$nodes = $userFolder->getById((int)$fileId);
			if (empty($nodes)) {
				continue;
			}
			$result[] = $this->formatFileInfo($nodes[0], $tagId);
		}
		return $result;
	}

	// ── File key-value metadata ───────────────────────────────────────────────

	/** @return array{keyid: int, value: string}[] */
	public function getFileKeys(int $fileId, int $tagId): array {
		return $this->docKeyMapper->findByFileAndTag($fileId, $tagId);
	}

	/**
	 * A file's values as $userId sees them: for a file in a share received from
	 * another node, the OWNER's values (the only copy); otherwise this node's.
	 *
	 * @return array{keyid: int, value: string}[]
	 */
	public function getFileKeysFor(int $fileId, int $tagId, string $userId): array {
		$remote = $this->getRemoteFileKeys($fileId, $userId, $tagId);
		return is_array($remote) ? $remote : $this->getFileKeys($fileId, $tagId);
	}

	/**
	 * Set a value. For a file in a share $userId received from another node the
	 * value is written on the OWNER's node (by share token, key by name), so the
	 * owner and everyone else the file is shared with see it; the owner's node
	 * refuses unless the share allows editing. Never falls back to a local
	 * write for such a file — a copy only the writer could see is worse than an
	 * error.
	 *
	 * @throws \RuntimeException when the owner's node refuses or cannot be reached
	 */
	public function updateFileKey(int $fileId, int $tagId, int $keyId, string $value, string $userId = ''): void {
		$loc = $userId !== '' ? $this->federatedLocation($fileId, $userId) : null;
		if ($loc === null) {
			$this->docKeyMapper->upsert($fileId, $tagId, $keyId, $value);
			return;
		}
		$tag = $this->getTagById($tagId);
		$key = $this->getKeyById($keyId);
		if ($tag === null || $key === null) {
			throw new \RuntimeException('Unknown tag or field');
		}
		$body = $this->callOwner($loc['remote'], 'internal/filekey-by-token', [
			'token' => $loc['token'], 'path' => $loc['path'],
			'tag' => (string)$tag['name'], 'key' => (string)$key['name'], 'value' => $value,
		]);
		if (!is_array($body) || empty($body['success'])) {
			throw new \RuntimeException((string)($body['message'] ?? 'The owner\'s server did not accept the change'));
		}
		// A local copy (written before write-through existed) would only mislead.
		$this->docKeyMapper->deleteByFileAndTag($fileId, $tagId);
	}

	/**
	 * Owner's node: set a value on the file at $internalPath inside the share
	 * with $token, if that share allows editing. Tag and key by NAME (numeric
	 * ids differ between nodes).
	 *
	 * @return string|null null = done; otherwise why not
	 */
	public function updateFileKeyByShareToken(string $token, string $internalPath, string $tagName, string $keyName, string $value): ?string {
		if ($this->db === null) {
			return 'Not available';
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid_owner', 'item_source', 'permissions')
			->from('share')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		$row = $qb->executeQuery()->fetch();
		if (!$row) {
			return 'Share not found';
		}
		if (((int)$row['permissions'] & \OCP\Constants::PERMISSION_UPDATE) === 0) {
			return 'The share does not allow editing';
		}
		try {
			$shareNode = $this->rootFolder->getUserFolder((string)$row['uid_owner'])->getFirstNodeById((int)$row['item_source']);
			if ($shareNode === null) {
				return 'Shared item not found';
			}
			$node = ($internalPath !== '' && $internalPath !== '.') ? $shareNode->get($internalPath) : $shareNode;
		} catch (\Throwable) {
			return 'File not found';
		}
		$tagId = $this->getTagIdByName($tagName);
		$keyId = $tagId !== null ? $this->getKeyIdByName($tagId, $keyName) : null;
		if ($tagId === null || $keyId === null) {
			return 'Unknown tag or field';
		}
		$this->docKeyMapper->upsert((int)$node->getId(), $tagId, $keyId, $value);
		return null;
	}

	// ── Cleanup ───────────────────────────────────────────────────────────────

	public function deleteFileMetadata(int $fileId): void {
		$this->docKeyMapper->deleteByFileId($fileId);
	}

	// ── Search ────────────────────────────────────────────────────────────────

	/** @return array[] */
	public function searchMetadata(string $value, string $userId, ?int $tagId = null, ?int $keyId = null): array {
		$rows = $this->docKeyMapper->search($value, $tagId, $keyId);
		$result = [];
		$userFolder = $this->rootFolder->getUserFolder($userId);

		foreach ($rows as $row) {
			$nodes = $userFolder->getById($row['fileid']);
			if (empty($nodes)) {
				continue;
			}
			$row['path'] = $nodes[0]->getPath();
			$row['name'] = $nodes[0]->getName();
			$result[] = $row;
		}
		return $result;
	}

	/**
	 * Find files tagged with $tagId whose attribute $keyId has a value matching
	 * $valuePattern (SQL LIKE syntax — % is a wildcard).
	 * @return array[]
	 */
	public function searchFiles(int $tagId, ?int $keyId, string $valuePattern, string $userId): array {
		$rows = $this->docKeyMapper->searchByPattern($valuePattern, $tagId, $keyId);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$result = [];
		$seen   = [];
		foreach ($rows as $row) {
			$fid = $row['fileid'];
			if (isset($seen[$fid])) {
				continue;
			}
			$seen[$fid] = true;
			$nodes = $userFolder->getById($fid);
			if (!empty($nodes)) {
				$result[] = $this->formatFileInfo($nodes[0], $tagId);
			}
		}
		return $result;
	}

	/**
	 * Return all key-value pairs for a file+tag, with key names resolved.
	 * @return array{tag: string, attributes: array<int, array{name: string, value: string}>}
	 */
	public function getFileMetadata(int $fileId, int $tagId): array {
		$tagArr  = $this->getTagById($tagId);
		$rawPairs = $this->docKeyMapper->findByFileAndTag($fileId, $tagId);
		$keyIndex = $this->getKeysByIds(array_column($rawPairs, 'keyid'));

		$attributes = [];
		foreach ($rawPairs as $pair) {
			$keyName = isset($keyIndex[$pair['keyid']]) ? $keyIndex[$pair['keyid']]['name'] : (string)$pair['keyid'];
			$attributes[] = ['name' => $keyName, 'value' => $pair['value']];
		}

		return [
			'tag'        => $tagArr['name'] ?? '',
			'attributes' => $attributes,
		];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function formatFileInfo(Node $node, int $tagId): array {
		$tagIdsForFile = $this->systemTagObjectMapper->getTagIdsForObjects([(string)$node->getId()], 'files');
		$tagObjs = $this->getTagsByIds(array_map('intval', $tagIdsForFile[(string)$node->getId()] ?? []));
		return [
			'id'          => $node->getId(),
			'name'        => $node->getName(),
			'path'        => $node->getPath(),
			'type'        => $node->getType(),
			'size'        => $node->getSize(),
			'mtime'       => $node->getMTime(),
			'mimetype'    => $node->getMimetype(),
			'permissions' => $node->getPermissions(),
			'tags'        => array_values($tagObjs),
		];
	}
}
