<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Cross-silo sync hardening after a real data-loss race (2026-09-13): three
 * fields saved in parallel produced three concurrent schema pushes carrying
 * different snapshots; the master applied them in arrival order (an OLDER
 * snapshot last → two fields deleted), echoed each back to the originating
 * silo (wiping the fields there too) and inserted the same key twice.
 *
 *  - meta_data_tag_extras.updated_at: per-tag version (ms since epoch, set by
 *    the node that made the change); receivers ignore older snapshots.
 *  - meta_data_keys: unique (tagid, name) — duplicates removed first, their
 *    values re-pointed to the surviving key.
 */
class Version008Date20260913120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Dedupe keys by (tagid, name): keep the lowest id, move values over.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'tagid', 'name')->from('meta_data_keys')->orderBy('id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		$seen = [];
		$removed = 0;
		foreach ($rows as $r) {
			$k = $r['tagid'] . "\0" . $r['name'];
			if (!isset($seen[$k])) {
				$seen[$k] = (int)$r['id'];
				continue;
			}
			$keep = $seen[$k];
			$dup  = (int)$r['id'];
			$u = $this->db->getQueryBuilder();
			$u->update('meta_data_docKeys')
				->set('keyid', $u->createNamedParameter($keep, IQueryBuilder::PARAM_INT))
				->where($u->expr()->eq('keyid', $u->createNamedParameter($dup, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$d = $this->db->getQueryBuilder();
			$d->delete('meta_data_keys')
				->where($d->expr()->eq('id', $d->createNamedParameter($dup, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$removed++;
		}
		if ($removed > 0) {
			$output->info("meta_data: removed $removed duplicate key(s) (values re-pointed)");
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('meta_data_tag_extras')) {
			$table = $schema->getTable('meta_data_tag_extras');
			if (!$table->hasColumn('updated_at')) {
				$table->addColumn('updated_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			}
		}
		if ($schema->hasTable('meta_data_keys')) {
			$table = $schema->getTable('meta_data_keys');
			if (!$table->hasIndex('meta_data_keys_tag_name')) {
				$table->addUniqueIndex(['tagid', 'name'], 'meta_data_keys_tag_name');
			}
		}
		return $schema;
	}
}
