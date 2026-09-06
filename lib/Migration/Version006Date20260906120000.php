<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

use Closure;
use OCA\MetaData\Service\TagService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

/**
 * Re-seed pass for the notes-family schemas (todo, diary, recipe, log, paper,
 * lab_notebook, illustration): the first extraction of predefined_schemas.php
 * lost their keys entirely (seeded with 'keys' => []), so e.g. 'todo' showed no
 * fields at all — the old service has due / priority["1","2","3"] /
 * status["open","done"]. The keys were re-extracted from the live old service
 * (2026-09-06) WITH their types and allowed values; the seed format now accepts
 * ['name'=>, 'type'=>, 'allowed_values'=>] entries alongside plain names.
 *
 * Idempotent like Version005: missing keys are created (with type/allowed
 * values); an existing key whose type AND allowed values are empty is upgraded
 * in place when the seed carries richer data; anything a user customised
 * (non-empty type or values) is left alone.
 */
class Version006Date20260906120000 extends SimpleMigrationStep {

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

		$added = 0;
		$upgraded = 0;
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
					if ($tagId > 0) {
						$svc->updateTag($tagId, null, (string)($schema['description'] ?? ''), (string)($schema['color'] ?? ''));
					}
				}
				if ($tagId <= 0) {
					continue;
				}
				// Existing keys by name, with their current type/allowed values.
				$existing = [];
				foreach ($svc->getKeys($tagId) as $k) {
					$existing[(string)$k['name']] = $k;
				}
				foreach (($schema['keys'] ?? []) as $key) {
					$keyName = is_array($key) ? (string)($key['name'] ?? '') : (string)$key;
					$type    = is_array($key) ? (string)($key['type'] ?? '') : '';
					$allowed = is_array($key) ? (string)($key['allowed_values'] ?? '') : '';
					if ($keyName === '') {
						continue;
					}
					if (!isset($existing[$keyName])) {
						$svc->newKey($tagId, $keyName, $type, $allowed);
						$added++;
						continue;
					}
					// Upgrade an existing bare key when the seed is richer and the
					// key was never customised (both fields empty).
					$cur = $existing[$keyName];
					$curType    = (string)($cur['type'] ?? '');
					$curAllowed = (string)($cur['allowed_values'] ?? ($cur['allowedValues'] ?? ''));
					if ($curType === '' && $curAllowed === '' && ($type !== '' || $allowed !== '')) {
						$svc->updateKey($tagId, (int)$cur['id'], $keyName, $type, $allowed);
						$upgraded++;
					}
				}
			} catch (\Throwable $e) {
				$logger->warning('meta_data: reseed schema "' . $name . '" failed: ' . $e->getMessage());
			}
		}
		$output->info("meta_data: notes-family keys reseeded ($added added, $upgraded upgraded)");
	}
}
