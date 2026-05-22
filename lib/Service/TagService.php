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

	/** @param array<int,string> $descriptions */
	private function tagToArray(ISystemTag $tag, array $descriptions = []): array {
		return [
			'id'             => (int)$tag->getId(),
			'name'           => $tag->getName(),
			'description'    => $descriptions[(int)$tag->getId()] ?? '',
			'color'          => $tag->getColor() ?? '',
			'userVisible'    => $tag->isUserVisible(),
			'userAssignable' => $tag->isUserAssignable(),
		];
	}

	// ── Sync helper ──────────────────────────────────────────────────────────

	/** Push the current full schema of a tag to all silos (no-op if no sync service). */
	private function pushSync(int $tagId): void {
		if ($this->syncService === null) return;
		$tag = $this->getTagById($tagId);
		if ($tag === null) return;
		$keys = array_map(fn(array $k) => [
			'name'          => $k['name'],
			'type'          => $k['type'],
			'allowedValues' => $k['allowed_values'] ?? '',
		], $this->getKeys($tagId));
		$descriptions = $this->tagExtraMapper->findDescriptionsByIds([$tagId]);
		$this->syncService->pushTagToAllSilos(
			$tag['name'],
			$tag['color'],
			$descriptions[$tagId] ?? '',
			$keys,
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

		$descriptions = $this->tagExtraMapper->findDescriptionsByIds(
			array_map(fn(ISystemTag $t) => (int)$t->getId(), $tags)
		);

		return array_values(array_map(function (ISystemTag $t) use ($descriptions, $withFileCount): array {
			$data = $this->tagToArray($t, $descriptions);
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
			$descriptions = $this->tagExtraMapper->findDescriptionsByIds([$tagId]);
			return $this->tagToArray($tag, $descriptions);
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
		$descriptions = $this->tagExtraMapper->findDescriptionsByIds(
			array_map(fn(ISystemTag $t) => (int)$t->getId(), $tags)
		);
		$result = [];
		foreach ($tags as $tag) {
			$result[(int)$tag->getId()] = $this->tagToArray($tag, $descriptions);
		}
		return $result;
	}

	public function newTag(string $name, string $color = ''): ?array {
		if (trim($name) === '') {
			return null;
		}
		try {
			$tag = $this->systemTagManager->createTag($name, true, true);
		} catch (TagAlreadyExistsException) {
			return null;
		}
		if ($color !== '') {
			$this->systemTagManager->updateTag($tag->getId(), $name, true, true, $color);
			try {
				$tags = $this->systemTagManager->getTagsByIds([$tag->getId()]);
				$tag = reset($tags);
			} catch (TagNotFoundException) {
				return null;
			}
		}
		$result = $this->tagToArray($tag);
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
			$this->syncService?->deleteTagOnAllSilos($tag['name']);
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
		if (trim($keyName) === '') {
			return null;
		}
		$key = new MetaKey();
		$key->setTagid($tagId);
		$key->setName($keyName);
		$key->setType($type);
		$key->setAllowedValues($allowedValues);
		$saved = $this->keyMapper->insert($key);
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
		return $tagsPerFile[$fileNode->getId()] ?? [];
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
		if ($this->config === null || $this->clientService === null || $this->db === null) {
			return null;
		}

		$secret = (string)$this->config->getSystemValue('files_sharding_shared_secret', '');
		if ($secret === '') {
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

		$url = $remote . '/index.php/apps/meta_data/internal/filetags-by-token';

		try {
			$client   = $this->clientService->newClient();
			$response = $client->post($url, [
				'json'    => ['token' => $token, 'path' => $internalPath],
				'headers' => ['Authorization' => 'Bearer ' . $secret],
				'timeout' => 5,
				'connect_timeout' => 3,
				'verify'  => true,
			]);
			$body = json_decode((string)$response->getBody(), true);
			if (!is_array($body) || !isset($body['tags'])) {
				return null;
			}
		} catch (\Throwable $e) {
			$this->logger->debug('meta_data: remote tag lookup failed for file ' . $fileId . ': ' . $e->getMessage());
			return null;
		}

		// Translate remote tag names to local IDs
		$localTags = [];
		foreach ($body['tags'] as $remoteTag) {
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

	public function updateFileKey(int $fileId, int $tagId, int $keyId, string $value): void {
		$this->docKeyMapper->upsert($fileId, $tagId, $keyId, $value);
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
