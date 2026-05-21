<?php

declare(strict_types=1);

namespace OCA\MetaData\Service;

use OCA\FilesSharding\Service\ShardingService;

/** Thin wrapper around ShardingService when files_sharding is installed. */
class FilesShardingAdapter implements IShardingAdapter {
	public function __construct(private ShardingService $service) {}

	public function isMaster(): bool                       { return $this->service->isMaster(); }
	public function getAllServers(): array                  { return $this->service->getAllServers(); }
	public function apiUrlForServer(mixed $server): string { return $this->service->apiUrlForServer($server); }
	public function masterInternalUrl(): string             { return $this->service->masterInternalUrl(); }
}
