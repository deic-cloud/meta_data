<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tag ownership: who created a tag (schema). Only the owner or an admin may
 * rename it, change its description/colour, edit its fields or delete it;
 * assigning it to files stays open to everyone. Existing and seeded tags have
 * no owner ('' → admin-only) until an admin assigns one.
 */
class Version007Date20260912200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('meta_data_tag_extras')) {
			$table = $schema->getTable('meta_data_tag_extras');
			if (!$table->hasColumn('created_by')) {
				$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 64]);
			}
		}
		return $schema;
	}
}
