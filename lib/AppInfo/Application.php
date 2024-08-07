<?php
/**
 * Nextcloud - ContextChat
 *
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

namespace OCA\ContextChat\AppInfo;

use OCA\ContextChat\Listener\AppDisableListener;
use OCA\ContextChat\Listener\FileListener;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\TaskProcessing\ContextChatProvider;
use OCA\ContextChat\TaskProcessing\ContextChatTaskType;
use OCP\App\Events\AppDisableEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\IConfig;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;

class Application extends App implements IBootstrap {

	public const APP_ID = 'context_chat';
	public const MIN_APP_API_VERSION = '2.0.3';

	public const CC_DEFAULT_REQUEST_TIMEOUT = 60 * 50; // 50 mins
	// max size per file + max size of the batch of files to be embedded in a single request
	public const CC_MAX_SIZE = 20 * 1024 * 1024; // 20MB
	public const CC_MAX_FILES = 100;
	public const MIMETYPES = [
		'text/plain',
		'text/markdown',
		'application/json',
		'application/pdf',
		'text/csv',
		'application/epub+zip',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.ms-excel.sheet.macroEnabled.12',
		'application/vnd.oasis.opendocument.text',
		'text/rtf',
		'text/x-rst',
		'application/xml',
		'message/rfc822',
		'application/vnd.ms-outlook',
		'text/org',
	];

	private IConfig $config;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->config = $container->get(IConfig::class);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeNodeDeletedEvent::class, FileListener::class);
		$context->registerEventListener(NodeCreatedEvent::class, FileListener::class);
		$context->registerEventListener(ShareCreatedEvent::class, FileListener::class);
		$context->registerEventListener(ShareDeletedEvent::class, FileListener::class);
		$context->registerEventListener(CacheEntryInsertedEvent::class, FileListener::class);
		$context->registerEventListener(NodeRemovedFromCache::class, FileListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileListener::class);
		$context->registerEventListener(AppDisableEvent::class, AppDisableListener::class);
		$context->registerTaskProcessingTaskType(ContextChatTaskType::class);
		$context->registerTaskProcessingProvider(ContextChatProvider::class);

		$providerConfigService = new ProviderConfigService($this->config);
		/** @psalm-suppress ArgumentTypeCoercion, UndefinedClass  */
		$providerConfigService->updateProvider('files', 'default', '', true);
	}

	public function boot(IBootContext $context): void {
	}
}
