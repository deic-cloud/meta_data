<?php

declare(strict_types=1);

namespace OCA\MetaData\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<DocKey> */
class DocKeyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'meta_data_docKeys', DocKey::class);
	}

	/**
	 * Returns [['keyid' => int, 'value' => string], ...]
	 * @return array<int, array{keyid: int, value: string}>
	 */
	public function findByFileAndTag(int $fileId, int $tagId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('keyid', 'value')
			->from($this->getTableName())
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));

		$result = [];
		$rows = $qb->executeQuery();
		while ($row = $rows->fetch()) {
			$result[] = ['keyid' => (int)$row['keyid'], 'value' => html_entity_decode($row['value'])];
		}
		$rows->closeCursor();
		return $result;
	}

	public function upsert(int $fileId, int $tagId, int $keyId, string $value): void {
		$encoded = $this->encodeValue($value);

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('keyid', $qb->createNamedParameter($keyId, IQueryBuilder::PARAM_INT)));

		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			$ins = $this->db->getQueryBuilder();
			$ins->insert($this->getTableName())
				->values([
					'fileid' => $ins->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
					'tagid'  => $ins->createNamedParameter($tagId, IQueryBuilder::PARAM_INT),
					'keyid'  => $ins->createNamedParameter($keyId, IQueryBuilder::PARAM_INT),
					'value'  => $ins->createNamedParameter($encoded),
				]);
			$ins->executeStatement();
		} else {
			$upd = $this->db->getQueryBuilder();
			$upd->update($this->getTableName())
				->set('value', $upd->createNamedParameter($encoded))
				->where($upd->expr()->eq('fileid', $upd->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('tagid', $upd->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
				->andWhere($upd->expr()->eq('keyid', $upd->createNamedParameter($keyId, IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
		}
	}

	public function deleteByFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByTagId(int $tagId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByFileAndTag(int $fileId, int $tagId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Search across docKeys by value, optionally filtered by tag/key.
	 * Value is wrapped with % for a substring match.
	 * @return array<int, array{fileid: int, tagid: int, keyid: int, value: string}>
	 */
	public function search(string $value, ?int $tagId = null, ?int $keyId = null): array {
		$pattern = $value !== '' ? '%' . $this->db->escapeLikeParameter($value) . '%' : '';
		return $this->searchByPattern($pattern, $tagId, $keyId);
	}

	/**
	 * Search using value as a raw LIKE pattern (% is a wildcard, passed through as-is).
	 * @return array<int, array{fileid: int, tagid: int, keyid: int, value: string}>
	 */
	public function searchByPattern(string $valuePattern, ?int $tagId = null, ?int $keyId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid', 'tagid', 'keyid', 'value')
			->from($this->getTableName());

		if ($valuePattern !== '') {
			$qb->where($qb->expr()->like('value', $qb->createNamedParameter($valuePattern)));
		}
		if ($tagId !== null) {
			$qb->andWhere($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));
		}
		if ($keyId !== null) {
			$qb->andWhere($qb->expr()->eq('keyid', $qb->createNamedParameter($keyId, IQueryBuilder::PARAM_INT)));
		}

		$result = [];
		$rows = $qb->executeQuery();
		while ($row = $rows->fetch()) {
			$result[] = [
				'fileid' => (int)$row['fileid'],
				'tagid'  => (int)$row['tagid'],
				'keyid'  => (int)$row['keyid'],
				'value'  => html_entity_decode($row['value']),
			];
		}
		$rows->closeCursor();
		return $result;
	}

	private function encodeValue(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
