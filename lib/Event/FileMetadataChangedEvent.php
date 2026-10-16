<?php

declare(strict_types=1);

namespace OCA\MetaData\Event;

use OCP\EventDispatcher\Event;

/**
 * A metadata value of a file was written (any schema, any field). Dispatched
 * after the value is stored, so listeners that cache derived views (e.g. the
 * files_picocms public catalogue) can drop them at once.
 */
class FileMetadataChangedEvent extends Event {
	public function __construct(
		private int $fileId,
	) {
		parent::__construct();
	}

	public function getFileId(): int {
		return $this->fileId;
	}
}
