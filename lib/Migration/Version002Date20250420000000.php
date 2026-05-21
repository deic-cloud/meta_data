<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002Date20250420000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('meta_data_tag_extras')) {
			$table = $schema->createTable('meta_data_tag_extras');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'length' => 11]);
			$table->addColumn('systemtag_id', Types::INTEGER, ['notnull' => true, 'length' => 11]);
			$table->addColumn('description', Types::TEXT, ['notnull' => true, 'default' => '']);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['systemtag_id'], 'meta_data_tag_extras_stid');
		}

		if ($schema->hasTable('meta_data_docTags')) {
			$schema->dropTable('meta_data_docTags');
		}

		if ($schema->hasTable('meta_data_tags')) {
			$schema->dropTable('meta_data_tags');
		}

		return $schema;
	}
}
