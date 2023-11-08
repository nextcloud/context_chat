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
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;

class Application extends App implements IBootstrap {

	public const APP_ID = 'cwyd';

	public const CWYD_DEFAULT_REQUEST_TIMEOUT = 60 * 4;
	// max size per file + max size of the batch of files to be embedded in a single request
	public const CWYD_MAX_SIZE = 20 * 1024 * 1024; // 20MB
	public const CWYD_MAX_FILES = 100;
	public const MIMETYPES = [
		'text/plain',
		'text/markdown',
		'application/json',
	];

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

