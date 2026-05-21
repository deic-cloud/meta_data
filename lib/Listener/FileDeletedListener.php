<?php

declare(strict_types=1);

namespace OCA\MetaData\Listener;

use OCA\MetaData\Service\TagService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;

/** @template-implements IEventListener<NodeDeletedEvent> */
class FileDeletedListener implements IEventListener {
	public function __construct(private TagService $tagService) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeDeletedEvent)) {
			return;
		}
		$this->tagService->deleteFileMetadata($event->getNode()->getId());
	}
}
