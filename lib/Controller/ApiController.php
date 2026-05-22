<?php

declare(strict_types=1);

namespace OCA\MetaData\Controller;

use OCA\MetaData\Service\TagService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private TagService $tagService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function userId(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	// ── Resolution helpers ────────────────────────────────────────────────────

	/**
	 * Accept either a numeric ID or a tag name.
	 * Returns the resolved integer ID, or null if not found.
	 */
	private function resolveTag(string $tagId): ?int {
		if (ctype_digit($tagId)) {
			return (int)$tagId;
		}
		return $this->tagService->getTagIdByName($tagId);
	}

	/**
	 * Accept either a numeric ID or a key name within a tag.
	 * Returns the resolved integer ID, or null if not found.
	 */
	private function resolveKey(int $tagId, string $keyId): ?int {
		if (ctype_digit($keyId)) {
			return (int)$keyId;
		}
		return $this->tagService->getKeyIdByName($tagId, $keyId);
	}

	/**
	 * Accept either a numeric file ID or a user-relative file path.
	 * Returns the resolved integer file ID, or null if not found.
	 */
	private function resolveFile(int $fileId, string $filePath): ?int {
		if ($fileId > 0) {
			return $fileId;
		}
		if ($filePath !== '') {
			return $this->tagService->resolveFilePath($filePath, $this->userId());
		}
		return null;
	}

	// ── Tags ──────────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function getTags(string $name = '%', string $fileCount = ''): DataResponse {
		$withFileCount = ($fileCount === '1' || $fileCount === 'true');
		$tags = $this->tagService->searchTags($name, $withFileCount);
		return new DataResponse(['tags' => $tags]);
	}

	#[NoAdminRequired]
	public function getTagById(string $tagId): DataResponse {
		$id = $this->resolveTag($tagId);
		if ($id === null) {
			return new DataResponse([], 404);
		}
		$tag = $this->tagService->getTagById($id);
		if ($tag === null) {
			return new DataResponse([], 404);
		}
		return new DataResponse(['tag' => $tag]);
	}

	#[NoAdminRequired]
	public function newTag(string $name, string $color = ''): DataResponse {
		$tag = $this->tagService->newTag($name, $color);
		if ($tag === null) {
			return new DataResponse(['message' => 'Tag already exists or name is empty'], 400);
		}
		return new DataResponse(['tag' => $tag]);
	}

	#[NoAdminRequired]
	public function updateTag(
		string $tagId,
		?string $name = null,
		?string $description = null,
		?string $color = null,
	): DataResponse {
		$id = $this->resolveTag($tagId);
		if ($id === null) {
			return new DataResponse(['message' => 'Tag not found'], 404);
		}
		$ok = $this->tagService->updateTag($id, $name, $description, $color);
		return new DataResponse(['success' => $ok]);
	}

	#[NoAdminRequired]
	public function deleteTag(string $tagId): DataResponse {
		$id = $this->resolveTag($tagId);
		if ($id === null) {
			return new DataResponse(['message' => 'Tag not found'], 404);
		}
		$ok = $this->tagService->deleteTag($id);
		return new DataResponse(['success' => $ok]);
	}

	// ── Keys ──────────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function getKeys(string $tagId): DataResponse {
		$id = $this->resolveTag($tagId);
		if ($id === null) {
			return new DataResponse([], 404);
		}
		$keys = $this->tagService->getKeys($id);
		return new DataResponse(['keys' => $keys]);
	}

	#[NoAdminRequired]
	public function newKey(
		string $tagId,
		string $keyname,
		string $type = '',
		string $controlledvalues = '',
	): DataResponse {
		$id = $this->resolveTag($tagId);
		if ($id === null) {
			return new DataResponse(['message' => 'Tag not found'], 404);
		}
		$key = $this->tagService->newKey($id, $keyname, $type, $controlledvalues);
		if ($key === null) {
			return new DataResponse(['message' => 'Key name is empty'], 400);
		}
		return new DataResponse(['key' => $key]);
	}

	#[NoAdminRequired]
	public function updateKey(
		string $tagId,
		string $keyId,
		string $keyname,
		string $type = '',
		string $controlledvalues = '',
	): DataResponse {
		$tid = $this->resolveTag($tagId);
		if ($tid === null) {
			return new DataResponse(['message' => 'Tag not found'], 404);
		}
		$kid = $this->resolveKey($tid, $keyId);
		if ($kid === null) {
			return new DataResponse(['message' => 'Key not found'], 404);
		}
		$ok = $this->tagService->updateKey($tid, $kid, $keyname, $type, $controlledvalues);
		return new DataResponse(['success' => $ok]);
	}

	#[NoAdminRequired]
	public function deleteKey(string $tagId, string $keyId): DataResponse {
		$tid = $this->resolveTag($tagId);
		if ($tid === null) {
			return new DataResponse(['message' => 'Tag not found'], 404);
		}
		$kid = $this->resolveKey($tid, $keyId);
		if ($kid === null) {
			return new DataResponse(['message' => 'Key not found'], 404);
		}
		$this->tagService->deleteKey($tid, $kid);
		return new DataResponse(['success' => true]);
	}

	// ── File-Tag associations ─────────────────────────────────────────────────

	/**
	 * Get tags for one or more files.
	 * Accepts fileids (int array) or files (path array), or a single file path.
	 *
	 * @param int[]    $fileids
	 * @param string[] $files
	 */
	#[NoAdminRequired]
	public function getFileTags(array $fileids = [], array $files = [], string $file = ''): DataResponse {
		$ids = array_map('intval', $fileids);

		foreach ($files as $path) {
			$resolved = $this->tagService->resolveFilePath($path, $this->userId());
			if ($resolved !== null) {
				$ids[] = $resolved;
			}
		}
		if ($file !== '') {
			$resolved = $this->tagService->resolveFilePath($file, $this->userId());
			if ($resolved !== null) {
				$ids[] = $resolved;
			}
		}

		if (empty($ids)) {
			return new DataResponse(['files' => []]);
		}

		$userId   = $this->userId();
		$uniqueIds = array_unique($ids);
		$fileTags = $this->tagService->getFileTags($uniqueIds);

		// For files with no local tags, check if they live on a remote (federated) mount
		foreach ($uniqueIds as $fileId) {
			if (empty($fileTags[$fileId])) {
				$remoteTags = $this->tagService->getRemoteFileTags($fileId, $userId);
				if ($remoteTags !== null && !empty($remoteTags)) {
					$fileTags[$fileId] = $remoteTags;
				}
			}
		}

		$result = [];
		foreach ($fileTags as $fid => $tags) {
			$result[] = ['id' => $fid, 'tags' => $tags];
		}
		return new DataResponse(['files' => $result]);
	}

	/**
	 * Add a tag to a file.
	 * Accepts fileid+tagid (numeric IDs) or file+tag (path and name).
	 */
	#[NoAdminRequired]
	public function addFileTag(int $fileid = 0, int $tagid = 0, string $file = '', string $tag = ''): DataResponse {
		$fid = $this->resolveFile($fileid, $file);
		$tid = $tag !== '' ? $this->resolveTag($tag) : ($tagid > 0 ? $tagid : null);

		if ($fid === null || $tid === null) {
			return new DataResponse(['message' => 'File or tag not found'], 404);
		}
		$this->tagService->addFileTag($fid, $tid);
		return new DataResponse(['success' => true]);
	}

	/**
	 * Remove a tag from a file.
	 * Accepts fileid+tagid (numeric IDs) or file+tag (path and name).
	 */
	#[NoAdminRequired]
	public function removeFileTag(int $fileid = 0, int $tagid = 0, string $file = '', string $tag = ''): DataResponse {
		$fid = $this->resolveFile($fileid, $file);
		$tid = $tag !== '' ? $this->resolveTag($tag) : ($tagid > 0 ? $tagid : null);

		if ($fid === null || $tid === null) {
			return new DataResponse(['message' => 'File or tag not found'], 404);
		}
		$this->tagService->removeFileTag($fid, $tid);
		return new DataResponse(['success' => true]);
	}

	/**
	 * List files that carry a given tag.
	 * tagId may be a numeric ID or a tag name.
	 */
	#[NoAdminRequired]
	public function getTaggedFiles(string $tagId): DataResponse {
		$id = $this->resolveTag($tagId);
		if ($id === null) {
			return new DataResponse([], 404);
		}
		$files = $this->tagService->getTaggedFiles($id, $this->userId());
		return new DataResponse(['files' => $files]);
	}

	/**
	 * Find files tagged with $tag whose attribute $attribute has a value
	 * matching $value (SQL LIKE syntax — % is a wildcard).
	 */
	#[NoAdminRequired]
	public function searchFiles(string $tag, string $attribute = '', string $value = ''): DataResponse {
		$tid = $this->resolveTag($tag);
		if ($tid === null) {
			return new DataResponse(['message' => 'Tag not found'], 404);
		}

		$kid = null;
		if ($attribute !== '') {
			$kid = $this->tagService->getKeyIdByName($tid, $attribute);
			if ($kid === null) {
				return new DataResponse(['message' => 'Attribute not found'], 404);
			}
		}

		$files = $this->tagService->searchFiles($tid, $kid, $value, $this->userId());
		return new DataResponse(['files' => $files]);
	}

	// ── File key-value metadata ───────────────────────────────────────────────

	/**
	 * Get key-value metadata for a file+tag combination.
	 * Returns raw {keyid, value} pairs (used by the sidebar UI).
	 * Accepts fileid+tagid or file+tag.
	 */
	#[NoAdminRequired]
	public function getFileKeys(int $fileid = 0, int $tagid = 0, string $file = '', string $tag = ''): DataResponse {
		$fid = $this->resolveFile($fileid, $file);
		$tid = $tag !== '' ? $this->resolveTag($tag) : ($tagid > 0 ? $tagid : null);

		if ($fid === null || $tid === null) {
			return new DataResponse([], 404);
		}
		$keys = $this->tagService->getFileKeys($fid, $tid);
		return new DataResponse(['data' => $keys]);
	}

	/**
	 * Get human-readable metadata for a file+tag combination.
	 * Returns attribute names instead of numeric key IDs.
	 * Accepts fileid+tagid or file+tag.
	 */
	#[NoAdminRequired]
	public function getMetadata(int $fileid = 0, int $tagid = 0, string $file = '', string $tag = ''): DataResponse {
		$fid = $this->resolveFile($fileid, $file);
		$tid = $tag !== '' ? $this->resolveTag($tag) : ($tagid > 0 ? $tagid : null);

		if ($fid === null || $tid === null) {
			return new DataResponse([], 404);
		}
		$metadata = $this->tagService->getFileMetadata($fid, $tid);
		return new DataResponse($metadata);
	}

	/**
	 * Set a metadata key-value pair for a file+tag.
	 * Accepts fileid+tagid+keyid or file+tag+attribute (by name).
	 */
	#[NoAdminRequired]
	public function updateFileKey(
		int $fileid = 0,
		int $tagid = 0,
		int $keyid = 0,
		string $value = '',
		string $file = '',
		string $tag = '',
		string $attribute = '',
	): DataResponse {
		$fid = $this->resolveFile($fileid, $file);
		$tid = $tag !== '' ? $this->resolveTag($tag) : ($tagid > 0 ? $tagid : null);
		$kid = null;

		if ($tid !== null) {
			if ($attribute !== '') {
				$kid = $this->tagService->getKeyIdByName($tid, $attribute);
			} elseif ($keyid > 0) {
				$kid = $keyid;
			}
		}

		if ($fid === null || $tid === null || $kid === null) {
			return new DataResponse(['message' => 'File, tag, or attribute not found'], 404);
		}
		$this->tagService->updateFileKey($fid, $tid, $kid, $value);
		return new DataResponse(['success' => true]);
	}
}
