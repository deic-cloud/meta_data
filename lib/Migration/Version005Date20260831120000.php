<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCA\MetaData\Service\TagService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Seed the predefined metadata schemas (Dublin Core, CSMD, ICAT, AVM, Zenodo,
 * …) as public system tags with their attribute keys — old-service parity.
 * Data in data/predefined_schemas.php (sourced from the live old service).
 *
 * Idempotent: an existing tag (by name) is reused and only its description/
 * color are refreshed; existing keys are skipped. So user edits and re-runs
 * are safe. Seeds go through TagService (systemtags + tag_extras + keys), the
 * same path the app itself uses.
 */
class Version005Date20260831120000 extends SimpleMigrationStep {

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		try {
			$svc    = \OCP\Server::get(TagService::class);
			$logger = \OCP\Server::get(LoggerInterface::class);
		} catch (\Throwable $e) {
			return; // services unavailable — skip quietly
		}
		$schemas = require __DIR__ . '/data/predefined_schemas.php';
		if (!is_array($schemas)) {
			return;
		}
		// Self-heal: remove any duplicate (tagid,name) keys a prior interrupted/
		// concurrent run may have left, keeping the lowest id.
		try {
			$db = \OCP\Server::get(\OCP\IDBConnection::class);
			$dupes = $db->executeQuery(
				'SELECT tagid, name, MIN(id) AS keep, COUNT(*) AS n FROM `*PREFIX*meta_data_keys` '
				. 'GROUP BY tagid, name HAVING COUNT(*) > 1'
			)->fetchAll();
			foreach ($dupes as $d) {
				$del = $db->getQueryBuilder();
				$del->delete('meta_data_keys')
					->where($del->expr()->eq('tagid', $del->createNamedParameter((int)$d['tagid'])))
					->andWhere($del->expr()->eq('name', $del->createNamedParameter((string)$d['name'])))
					->andWhere($del->expr()->neq('id', $del->createNamedParameter((int)$d['keep'])));
				$del->executeStatement();
			}
			if ($dupes !== []) { $output->info('meta_data: removed ' . count($dupes) . ' duplicate key set(s)'); }
		} catch (\Throwable $e) {
		}

		$created = 0; $keysAdded = 0;
		foreach ($schemas as $schema) {
			$name = (string)($schema['name'] ?? '');
			if ($name === '') {
				continue;
			}
			try {
				$tagId = $svc->getTagIdByName($name);
				if ($tagId === null) {
					$tag = $svc->newTag($name, (string)($schema['color'] ?? ''));
					$tagId = is_array($tag) ? (int)($tag['id'] ?? 0) : 0;
					if ($tagId > 0) { $created++; }
				}
				if ($tagId <= 0) {
					continue;
				}
				// refresh description + color (name unchanged)
				$svc->updateTag($tagId, null, (string)($schema['description'] ?? ''), (string)($schema['color'] ?? ''));
				foreach (($schema['keys'] ?? []) as $key) {
					// A key is a plain name or ['name'=>, 'type'=>, 'allowed_values'=>].
					$keyName = is_array($key) ? (string)($key['name'] ?? '') : (string)$key;
					$type    = is_array($key) ? (string)($key['type'] ?? '') : '';
					$allowed = is_array($key) ? (string)($key['allowed_values'] ?? '') : '';
					if ($keyName === '') {
						continue;
					}
					if ($svc->getKeyIdByName($tagId, $keyName) === null) {
						$svc->newKey($tagId, $keyName, $type, $allowed);
						$keysAdded++;
					}
				}
			} catch (\Throwable $e) {
				$logger->warning('meta_data: seed schema "' . $name . '" failed: ' . $e->getMessage());
			}
		}
		$output->info("meta_data: predefined schemas seeded ($created new tags, $keysAdded new keys)");
	}
}
