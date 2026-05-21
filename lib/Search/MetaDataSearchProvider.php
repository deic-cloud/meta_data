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

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = $query->getTerm();
		$userId = $user->getUID();
		$entries = [];

		// Tag name search: "tag:foo"
		if (preg_match('/^tag:(.+)$/i', $term, $m)) {
			$tags = $this->tagService->searchTags($m[1] . '%');
			foreach ($tags as $tag) {
				$url = $this->urlGenerator->linkToRoute('files.View.index', [
					'dir' => '/',
					'view' => 'tag-' . $tag['id'],
				]);
				$entries[] = new SearchResultEntry(
					'',
					$tag['name'],
					$this->l10n->t('Tag'),
					$url,
					'icon-tag'
				);
			}
			return SearchResult::complete($this->getName(), $entries);
		}

		// Metadata value search
		$rows = $this->tagService->searchMetadata($term, $userId);
		$tagIds = array_unique(array_column($rows, 'tagid'));
		$keyIds = array_unique(array_column($rows, 'keyid'));
		$tagIndex = $this->tagService->getTagsByIds($tagIds);
		$keyIndex = $this->tagService->getKeysByIds($keyIds);

		foreach ($rows as $row) {
			if (empty($row['path'])) {
				continue;
			}
			$tag = $tagIndex[$row['tagid']] ?? null;
			$key = $keyIndex[$row['keyid']] ?? null;
			$subline = $tag ? $tag['name'] : '';
			if ($key) {
				$subline .= ' › ' . $key['name'] . '=' . $row['value'];
			}

			$dir = dirname($row['path']);
			$url = $this->urlGenerator->linkToRoute('files.View.index', [
				'dir' => $dir,
				'scrollto' => $row['name'],
			]);
			$entries[] = new SearchResultEntry(
				'',
				$row['name'],
				$subline,
				$url,
				'icon-tag'
			);
		}

		return SearchResult::complete($this->getName(), $entries);
	}
}
