<?php

declare(strict_types=1);

namespace OCA\MetaData\Service;

interface IShardingAdapter {
	public function isMaster(): bool;
	/** @return mixed[] opaque server objects passed back to apiUrlForServer() */
	public function getAllServers(): array;
	public function apiUrlForServer(mixed $server): string;
	/** True if $server is this node's own registry row (skip it in push loops). */
	public function isSelf(mixed $server): bool;
	/** Internal base URL of the master server, or '' if unknown/standalone. */
	public function masterInternalUrl(): string;
}
