<?php

declare(strict_types=1);

namespace OCA\MetaData\Search;

use OCA\MetaData\Service\TagService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class MetaDataSearchProvider implements IProvider {
	public function __construct(
		private TagService $tagService,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getId(): string {
		return 'meta_data';
	}

	public function getName(): string {
		return $this->l10n->t('Metadata');
	}

	public function getOrder(string $route, array $routeParameters): int {
		return $route === 'files.View.index' ? 5 : 15;
	}

	/**
	 * Search syntax (old-service compatible):
	 *   tag:NAME                 → the matching tags (open one to list its files)
	 *   WORD …                   → files whose metadata VALUES contain the word(s)
	 *   FIELD:VALUE …            → files whose field FIELD contains VALUE
	 *   tag:NAME FIELD:VALUE …   → the same, restricted to files carrying the tag
	 * Several criteria are ANDed (a file must satisfy all of them).
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term   = trim($query->getTerm());
		$userId = $user->getUID();

		$tagName = null;
		$pairs   = [];
		$words   = [];
		foreach (preg_split('/\s+/', $term) ?: [] as $tok) {
			if ($tok === '') {
				continue;
			}
			if (preg_match('/^tag:(.+)$/i', $tok, $m)) {
				$tagName = $m[1];
			} elseif (preg_match('/^([^:\s]+):(.+)$/', $tok, $m)) {
				$pairs[] = [$m[1], $m[2]];
			} else {
				$words[] = $tok;
			}
		}

		// "tag:foo" alone: list the tags whose name contains foo.
		if ($tagName !== null && $pairs === [] && $words === []) {
			$entries = [];
			// ISystemTagManager::getAllTags() escapes LIKE wildcards in the pattern
			// and wraps it in its own %…% — pass the bare term (substring match).
			foreach ($this->tagService->searchTags($tagName) as $tag) {
				// NC34 Files router: a tag's file list is the Tags view with the tag id
				// as directory — /apps/files/tags?dir=/<id>. (A path segment after the
				// view would be the SELECTED file, and the old ?view=tag-<id> form is
				// not understood at all.)
				$url = $this->urlGenerator->linkToRoute('files.view.indexView', [
					'view' => 'tags',
					'dir'  => '/' . $tag['id'],
				]);
				$entries[] = new SearchResultEntry('', $tag['name'], $this->l10n->t('Tag'), $url, 'icon-tag');
			}
			return SearchResult::complete($this->getName(), $entries);
		}

		// Files: optional tag restriction (exact name, else a single substring match).
		$tagId = null;
		if ($tagName !== null) {
			$tagId = $this->tagService->getTagIdByName($tagName);
			if ($tagId === null) {
				$cands = $this->tagService->searchTags($tagName);
				if (count($cands) !== 1) {
					return SearchResult::complete($this->getName(), []);
				}
				$tagId = (int)$cands[0]['id'];
			}
		}

		// One candidate set per criterion, ANDed by file id.
		$sets = [];
		foreach ($pairs as [$kName, $kValue]) {
			$keyIds = $this->tagService->findKeyIdsByName($kName, $tagId);
			if ($keyIds === []) {
				return SearchResult::complete($this->getName(), []);
			}
			$rows = [];
			foreach ($keyIds as $kid) {
				$rows = array_merge($rows, $this->tagService->searchMetadata($kValue, $userId, $tagId, $kid));
			}
			$sets[] = $rows;
		}
		foreach ($words as $w) {
			$sets[] = $this->tagService->searchMetadata($w, $userId, $tagId, null);
		}
		if ($sets === []) {
			return SearchResult::complete($this->getName(), []);
		}
		$byFile = [];
		foreach ($sets[0] as $row) {
			if (!empty($row['path'])) {
				$byFile[(int)$row['fileid']] = $row;
			}
		}
		for ($i = 1; $i < count($sets); $i++) {
			$keep = array_flip(array_map('intval', array_column($sets[$i], 'fileid')));
			$byFile = array_intersect_key($byFile, $keep);
		}

		$tagIndex = $this->tagService->getTagsByIds(array_unique(array_column($byFile, 'tagid')));
		$keyIndex = $this->tagService->getKeysByIds(array_unique(array_column($byFile, 'keyid')));
		$entries  = [];
		foreach ($byFile as $row) {
			$tag = $tagIndex[$row['tagid']] ?? null;
			$key = $keyIndex[$row['keyid']] ?? null;
			$subline = $tag ? $tag['name'] : '';
			if ($key) {
				$subline .= ' › ' . $key['name'] . '=' . $row['value'];
			}
			// /f/<fileid>: core resolves the folder and highlights the file — no
			// path juggling (the row's path is absolute, /<uid>/files/…).
			$url = $this->urlGenerator->linkToRoute('files.View.showFile', ['fileid' => (int)$row['fileid']]);
			$entries[] = new SearchResultEntry('', $row['name'], $subline, $url, 'icon-tag');
		}
		return SearchResult::complete($this->getName(), $entries);
	}
}
