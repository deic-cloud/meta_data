<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001Date20250417000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('meta_data_tags')) {
			$table = $schema->createTable('meta_data_tags');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'length' => 11]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 80]);
			$table->addColumn('description', Types::TEXT, ['notnull' => true, 'default' => '']);
			$table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 80]);
			$table->addColumn('public', Types::SMALLINT, ['notnull' => false, 'default' => 0]);
			$table->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 12, 'default' => 'color-1']);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner'], 'meta_data_tags_owner');
			$table->addIndex(['name'], 'meta_data_tags_name');
		}

		if (!$schema->hasTable('meta_data_keys')) {
			$table = $schema->createTable('meta_data_keys');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'length' => 11]);
			$table->addColumn('tagid', Types::INTEGER, ['notnull' => true, 'length' => 11]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 80]);
			$table->addColumn('allowed_values', Types::TEXT, ['notnull' => false, 'default' => '']);
			$table->addColumn('type', Types::STRING, ['notnull' => false, 'length' => 80, 'default' => '']);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['tagid'], 'meta_data_keys_tagid');
		}

		if (!$schema->hasTable('meta_data_docTags')) {
			$table = $schema->createTable('meta_data_docTags');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'length' => 11]);
			$table->addColumn('fileid', Types::INTEGER, ['notnull' => true, 'length' => 11]);
			$table->addColumn('tagid', Types::INTEGER, ['notnull' => true, 'length' => 11]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['fileid', 'tagid'], 'meta_data_doctags_unique');
			$table->addIndex(['tagid'], 'meta_data_doctags_tagid');
		}

		if (!$schema->hasTable('meta_data_docKeys')) {
			$table = $schema->createTable('meta_data_docKeys');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true, 'length' => 11]);
			$table->addColumn('fileid', Types::INTEGER, ['notnull' => true, 'length' => 11]);
			$table->addColumn('tagid', Types::INTEGER, ['notnull' => true, 'length' => 11]);
			$table->addColumn('keyid', Types::INTEGER, ['notnull' => true, 'length' => 11]);
			$table->addColumn('value', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['fileid', 'tagid'], 'meta_data_dockeys_file_tag');
		}

		return $schema;
	}
}
