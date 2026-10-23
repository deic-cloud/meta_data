<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Metadata values up to 4000 characters (was 255). 255 was too short for
 * descriptions, e.g. a catalog summary with a [text](url) link
 * (Frederik 2026-10-23). 4000 is the longest portable string column
 * (Oracle's varchar2 limit); the column has no index, so MySQL's index
 * length limit does not apply.
 */
class Version011Date20261023120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('meta_data_docKeys')) {
			return null;
		}
		$column = $schema->getTable('meta_data_docKeys')->getColumn('value');
		if ($column->getLength() !== null && $column->getLength() >= 4000) {
			return null;
		}
		$column->setLength(4000);
		return $schema;
	}
}
