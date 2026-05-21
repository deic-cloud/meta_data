<?php

declare(strict_types=1);

namespace OCA\MetaData\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Propagates tag-schema changes to all peer servers.
 * From master: pushes directly to all registered silos.
 * From a silo:  pushes to master (master then relays to all silos).
 */
class TagSyncService {
	private string $secret;
	private bool   $verifySsl;

	public function __construct(
		private IShardingAdapter $sharding,
		private IClientService   $clientService,
		private IConfig          $config,
		private LoggerInterface  $logger,
	) {
		$this->secret    = (string)$config->getSystemValue('files_sharding_shared_secret', '');
		$this->verifySsl = (bool)$config->getSystemValue('files_sharding_verify_ssl', true);
	}

	/**
	 * Push a full tag schema (name, color, description, keys) to all peers.
	 *
	 * @param array{name:string,type:string,allowedValues:string}[] $keys
	 */
	public function pushTagToAllSilos(
		string $name,
		string $color,
		string $description,
		array  $keys,
	): void {
		$payload = [
			'name'        => $name,
			'color'       => $color,
			'description' => $description,
			'keys'        => json_encode($keys),
		];
		foreach ($this->syncTargets() as $url) {
			if (!$this->post($url, 'internal/tags/sync', $payload)) {
				$this->logger->error("meta_data: failed to sync tag '{$name}' to {$url}");
			}
		}
	}

	/** Tell all peers to delete a tag by name. */
	public function deleteTagOnAllSilos(string $name): void {
		$payload = ['name' => $name];
		foreach ($this->syncTargets() as $url) {
			$this->post($url, 'internal/tags/delete', $payload);
		}
	}

	// ── Internal HTTP helpers ─────────────────────────────────────────────────

	private function post(string $baseUrl, string $path, array $body = []): bool {
		if ($this->secret === '') return false;
		$url = rtrim($baseUrl, '/') . '/index.php/apps/meta_data/' . ltrim($path, '/');
		try {
			$this->clientService->newClient()->post($url, [
				'headers'     => ['Authorization' => 'Bearer ' . $this->secret, 'Accept' => 'application/json'],
				'form_params' => $body,
				'verify'      => $this->verifySsl,
				'timeout'     => 10,
			]);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning("meta_data: POST {$url} failed: " . $e->getMessage());
			return false;
		}
	}

	/** @return string[] base URLs of all peers to push to */
	private function syncTargets(): array {
		$urls = [];
		foreach ($this->sharding->getAllServers() as $server) {
			$urls[] = $this->sharding->apiUrlForServer($server);
		}
		if (!$this->sharding->isMaster()) {
			$masterUrl = $this->sharding->masterInternalUrl();
			if ($masterUrl !== '') {
				$urls[] = $masterUrl;
			}
		}
		return array_unique(array_filter($urls));
	}
}
