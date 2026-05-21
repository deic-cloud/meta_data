<?php

declare(strict_types=1);

namespace OCA\MetaData\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<TagExtra> */
class TagExtraMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'meta_data_tag_extras', TagExtra::class);
	}

	/** @throws DoesNotExistException */
	public function findBySystemTagId(int $systemTagId): TagExtra {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('systemtag_id', $qb->createNamedParameter($systemTagId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $systemTagIds
	 * @return array<int, string> map of systemTagId => description
	 */
	public function findDescriptionsByIds(array $systemTagIds): array {
		if (empty($systemTagIds)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('systemtag_id', 'description')->from($this->getTableName())
			->where($qb->expr()->in('systemtag_id', $qb->createNamedParameter($systemTagIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetch()) {
			$map[(int)$row['systemtag_id']] = (string)$row['description'];
		}
		$result->closeCursor();
		return $map;
	}

	public function upsert(int $systemTagId, string $description): void {
		try {
			$extra = $this->findBySystemTagId($systemTagId);
			$extra->setDescription($description);
			$this->update($extra);
		} catch (DoesNotExistException) {
			$extra = new TagExtra();
			$extra->setSystemtagId($systemTagId);
			$extra->setDescription($description);
			$this->insert($extra);
		}
	}

	public function deleteBySystemTagId(int $systemTagId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('systemtag_id', $qb->createNamedParameter($systemTagId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
