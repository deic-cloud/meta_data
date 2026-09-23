<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCA\MetaData\Service\TagService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Two schema decisions, 2026-09-23 (Frederik):
 *
 *  - lab_notebook gains `status`, controlled, planned / running / done: the
 *    stage of an experiment, which is what makes a campaign of thirty entries
 *    readable as a register in the Notes list.
 *  - todo loses `due` and `status`. A to-do's due date and completion are
 *    Joplin's own note fields, kept in the footer and synced to every client
 *    unchanged; Notes shows them in its native columns. The old service
 *    mirrored them into these two metadata fields; the port never did, so here
 *    they were orphans that could only drift. `priority` stays.
 *
 * Idempotent: adds the field only if missing, removes the two only if present.
 */
class Version009Date20260923120000 extends SimpleMigrationStep {

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		try {
			$svc    = \OCP\Server::get(TagService::class);
			$logger = \OCP\Server::get(LoggerInterface::class);
		} catch (\Throwable $e) {
			return;
		}
		try {
			$lab = $svc->getTagIdByName('lab_notebook');
			if ($lab !== null && $svc->getKeyIdByName($lab, 'status') === null) {
				$svc->newKey($lab, 'status', 'controlled', '["planned", "running", "done"]');
				$output->info('meta_data: lab_notebook gained a status field');
			}
			$todo = $svc->getTagIdByName('todo');
			if ($todo !== null) {
				foreach (['due', 'status'] as $name) {
					$keyId = $svc->getKeyIdByName($todo, $name);
					if ($keyId !== null) {
						$svc->deleteKey($todo, $keyId);
						$output->info('meta_data: todo lost its ' . $name . ' field');
					}
				}
			}
		} catch (\Throwable $e) {
			$logger->warning('meta_data: Version009 schema update: ' . $e->getMessage(), ['app' => 'meta_data']);
		}
	}
}
