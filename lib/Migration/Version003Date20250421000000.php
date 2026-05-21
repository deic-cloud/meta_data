<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version003Date20250421000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

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
