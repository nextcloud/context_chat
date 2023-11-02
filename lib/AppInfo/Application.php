<?php
/**
 * Nextcloud - Cwyd
 *
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

namespace OCA\Cwyd\AppInfo;

use OCA\Cwyd\Listener\FileListener;
use OCA\Cwyd\TextProcessing\CwydTextProcessingProvider;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\NodeRemovedFromCache;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;

class Application extends App implements IBootstrap {

	public const APP_ID = 'cwyd';

	public const LANGROPE_BASE_URL = 'http://localhost:8008';
	public const CWYD_DEFAULT_REQUEST_TIMEOUT = 60 * 4;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
        $context->registerEventListener(BeforeNodeDeletedEvent::class, FileListener::class);
        $context->registerEventListener(NodeCreatedEvent::class, FileListener::class);
        $context->registerEventListener(ShareCreatedEvent::class, FileListener::class);
        $context->registerEventListener(ShareDeletedEvent::class, FileListener::class);
        $context->registerEventListener(CacheEntryInsertedEvent::class, FileListener::class);
        $context->registerEventListener(NodeRemovedFromCache::class, FileListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, FileListener::class);
        $context->registerTextProcessingProvider(CwydTextProcessingProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}

