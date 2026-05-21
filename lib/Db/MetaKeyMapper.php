<?php

declare(strict_types=1);

namespace OCA\MetaData\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<MetaKey> */
class MetaKeyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'meta_data_keys', MetaKey::class);
	}

	/** @return MetaKey[] */
	public function findByTag(int $tagId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findById(int $id): MetaKey {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $ids
	 * @return MetaKey[]
	 */
	public function findByIds(array $ids): array {
		if (empty($ids)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		return $this->findEntities($qb);
	}

	/** @return MetaKey[] */
	public function findByTagAndName(int $tagId, string $pattern): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('name', $qb->createNamedParameter($pattern)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteByTagAndId(int $tagId, int $keyId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($keyId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function deleteByTagId(int $tagId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** Delete all keys for a tag whose name is NOT in the given list. */
	public function deleteByTagIdNotInNames(int $tagId, array $keepNames): void {
		if (empty($keepNames)) {
			$this->deleteByTagId($tagId);
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->notIn('name', $qb->createNamedParameter($keepNames, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
	}
}
