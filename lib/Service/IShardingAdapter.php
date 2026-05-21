<?php

declare(strict_types=1);

namespace OCA\MetaData\Service;

interface IShardingAdapter {
	public function isMaster(): bool;
	/** @return mixed[] opaque server objects passed back to apiUrlForServer() */
	public function getAllServers(): array;
	public function apiUrlForServer(mixed $server): string;
	/** Internal base URL of the master server, or '' if unknown/standalone. */
	public function masterInternalUrl(): string;
}
