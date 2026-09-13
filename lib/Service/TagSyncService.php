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
		string $owner = '',
		int    $updatedAt = 0,
		string $origin = '',
	): void {
		$payload = [
			'name'        => $name,
			'color'       => $color,
			'description' => $description,
			'keys'        => json_encode($keys),
			'owner'       => $owner,
			'updated_at'  => (string)$updatedAt,
			'origin'      => $origin,
		];
		foreach ($this->syncTargets($origin) as $url) {
			if (!$this->post($url, 'internal/tags/sync', $payload)) {
				$this->logger->error("meta_data: failed to sync tag '{$name}' to {$url}");
			}
		}
	}

	/** Tell all peers to delete a tag by name. */
	public function deleteTagOnAllSilos(string $name, string $origin = ''): void {
		$payload = ['name' => $name, 'origin' => $origin];
		foreach ($this->syncTargets($origin) as $url) {
			$this->post($url, 'internal/tags/delete', $payload);
		}
	}

	// ── Internal HTTP helpers ─────────────────────────────────────────────────

	/**
	 * Verify the TLS certificate for $baseUrl? Yes by default, but not for a
	 * private/reserved IP target (10/8, 172.16/12, 192.168/16, …): those are the
	 * cluster's backend addresses (files_sharding_servers.internal_url), reached by
	 * IP on the firewalled backend network, so their certificate can never carry
	 * that name — and needn't (shared-secret authed). Same rule as files_sharding's
	 * InterServerClient; without it every push to a silo's internal URL failed
	 * with a certificate error. 'files_sharding_verify_ssl' => false disables
	 * verification everywhere.
	 */
	private function verifyFor(string $baseUrl): bool {
		if (!$this->verifySsl) {
			return false;
		}
		$host = (string)parse_url($baseUrl, PHP_URL_HOST);
		if ($host !== ''
			&& filter_var($host, FILTER_VALIDATE_IP) !== false
			&& filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return false;
		}
		return true;
	}

	private function post(string $baseUrl, string $path, array $body = []): bool {
		if ($this->secret === '') return false;
		$url = rtrim($baseUrl, '/') . '/index.php/apps/meta_data/' . ltrim($path, '/');
		try {
			$this->clientService->newClient()->post($url, [
				'headers'     => ['Authorization' => 'Bearer ' . $this->secret, 'Accept' => 'application/json'],
				'form_params' => $body,
				'verify'      => $this->verifyFor($baseUrl),
				// Registered cluster servers are addressed by their backend IP; NC's HTTP
				// client refuses private addresses ('violates local access rules') unless told
				// otherwise — same as files_sharding's InterServerClient.
				'nextcloud'   => ['allow_local_address' => true],
				'timeout'     => 10,
			]);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning("meta_data: POST {$url} failed: " . $e->getMessage());
			return false;
		}
	}

	/** This node's URL as the cluster knows it (the 'origin' of our pushes), '' if unknown. */
	public function selfUrl(): string {
		foreach ($this->sharding->getAllServers() as $server) {
			if ($this->sharding->isSelf($server)) {
				return rtrim($this->sharding->apiUrlForServer($server), '/');
			}
		}
		return $this->sharding->isMaster() ? rtrim($this->sharding->masterInternalUrl(), '/') : '';
	}

	private static function authority(string $url): string {
		$p = parse_url($url);
		return strtolower((string)($p['host'] ?? '')) . ':' . (string)($p['port'] ?? '');
	}

	/**
	 * @param string $excludeOrigin node the change came from — never echo a
	 *                              snapshot back to it (it holds the newest state)
	 * @return string[] base URLs of all peers to push to
	 */
	private function syncTargets(string $excludeOrigin = ''): array {
		$urls = [];
		$skip = $excludeOrigin !== '' ? self::authority($excludeOrigin) : null;
		foreach ($this->sharding->getAllServers() as $server) {
			if ($this->sharding->isSelf($server)) { continue; } // don't push to ourselves
			$url = $this->sharding->apiUrlForServer($server);
			if ($skip !== null && self::authority($url) === $skip) { continue; }
			$urls[] = $url;
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
