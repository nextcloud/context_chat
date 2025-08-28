<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\AppInfo;

use OCA\ContextChat\Listener\AddMissingIndicesListener;
use OCA\ContextChat\Listener\AppDisableListener;
use OCA\ContextChat\Listener\FileListener;
use OCA\ContextChat\Listener\ShareListener;
use OCA\ContextChat\Listener\UserDeletedListener;
use OCA\ContextChat\TaskProcessing\ContextChatProvider;
use OCA\ContextChat\TaskProcessing\ContextChatSearchProvider;
use OCA\ContextChat\TaskProcessing\ContextChatSearchTaskType;
use OCA\ContextChat\TaskProcessing\ContextChatTaskType;
use OCP\App\Events\AppDisableEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\DB\Events\AddMissingIndicesEvent;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\IConfig;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {

	public const APP_ID = 'context_chat';
	public const MIN_APP_API_VERSION = '3.0.0';

	public const CC_DEFAULT_REQUEST_TIMEOUT = 60 * 50; // 50 mins
	// max size per file + max size of the batch of files to be embedded in a single request
	public const CC_MAX_SIZE = 100 * 1024 * 1024; // 100MB
	public const CC_MAX_FILES = 25;
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
		$context->registerEventListener(AddMissingIndicesEvent::class, AddMissingIndicesListener::class);
		$context->registerEventListener(BeforeNodeDeletedEvent::class, FileListener::class);
		$context->registerEventListener(NodeCreatedEvent::class, FileListener::class);
		$context->registerEventListener(CacheEntryInsertedEvent::class, FileListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileListener::class);
		$context->registerEventListener(NodeRemovedFromCache::class, FileListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileListener::class);
		$context->registerEventListener(AppDisableEvent::class, AppDisableListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		// These events were added mid-way through NC 30, 31
		if (class_exists('OCP\Files\Config\Event\UserMountAddedEvent')) {
			$context->registerEventListener('OCP\Files\Config\Event\UserMountAddedEvent', FileListener::class);
			$context->registerEventListener('OCP\Files\Config\Event\UserMountRemovedEvent', FileListener::class);
			// it is not fired as of now, Added and Removed events are fired instead in that order
			// $context->registerEventListener('OCP\Files\Config\Event\UserMountUpdatedEvent', FileListener::class);
		} else {
			$context->registerEventListener(ShareCreatedEvent::class, ShareListener::class);
			$context->registerEventListener(ShareDeletedEvent::class, ShareListener::class);
		}
		$context->registerTaskProcessingTaskType(ContextChatTaskType::class);
		$context->registerTaskProcessingProvider(ContextChatProvider::class);
		$context->registerTaskProcessingTaskType(ContextChatSearchTaskType::class);
		$context->registerTaskProcessingProvider(ContextChatSearchProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}
