<?php

declare(strict_types=1);

namespace OCA\MetaData\Service;

/** Used when files_sharding is not installed: single-instance, no sync. */
class StandaloneShardingAdapter implements IShardingAdapter {
	public function isMaster(): bool                       { return true; }
	public function getAllServers(): array                  { return []; }
	public function apiUrlForServer(mixed $server): string { return ''; }
	public function isSelf(mixed $server): bool             { return false; }
	public function masterInternalUrl(): string             { return ''; }
}
