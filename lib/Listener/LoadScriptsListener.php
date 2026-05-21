<?php

declare(strict_types=1);

namespace OCA\MetaData\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		Util::addScript('meta_data', 'jquery.min');
		Util::addScript('meta_data', 'meta_data');
		Util::addStyle('meta_data', 'meta_data');
	}
}
