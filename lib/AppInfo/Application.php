<?php

declare(strict_types=1);

namespace OCA\MetaData\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\MetaData\Listener\FileDeletedListener;
use OCA\MetaData\Listener\LoadScriptsListener;
use OCA\MetaData\Search\MetaDataSearchProvider;
use OCA\MetaData\Service\FilesShardingAdapter;
use OCA\MetaData\Service\IShardingAdapter;
use OCA\MetaData\Service\StandaloneShardingAdapter;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeDeletedEvent;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'meta_data';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			LoadAdditionalScriptsEvent::class,
			LoadScriptsListener::class,
		);
		$context->registerEventListener(
			NodeDeletedEvent::class,
			FileDeletedListener::class,
		);
		$context->registerSearchProvider(MetaDataSearchProvider::class);

		$context->registerService(IShardingAdapter::class, function (ContainerInterface $c): IShardingAdapter {
			if ($c->get(IAppManager::class)->isInstalled('files_sharding')) {
				return new FilesShardingAdapter(
					$c->get(\OCA\FilesSharding\Service\ShardingService::class)
				);
			}
			return new StandaloneShardingAdapter();
		});
	}

	public function boot(IBootContext $context): void {
	}
}
