<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Public;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\BackgroundJobs\InitialContentImportJob;
use OCA\ContextChat\BackgroundJobs\SubmitContentJob;
use OCA\ContextChat\Db\QueueContentItem;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Event\ContentProviderRegisterEvent;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\ActionScheduler;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\ContextChat\ContentItem;
use OCP\ContextChat\IContentManager;
use OCP\ContextChat\IContentProvider;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class ContentManager implements IContentManager {
	public function __construct(
		private IJobList $jobList,
		private IConfig $config,
		private ProviderConfigService $providerConfig,
		private QueueContentItemMapper $mapper,
		private ActionScheduler $actionService,
		private Logger $logger,
		private IEventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * Checks if the context chat app is enabled or not
	 *
	 * @return bool
	 * @since 4.6.0
	 */
	public function isContextChatAvailable(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'backend_init', 'false') === 'true';
	}

	/**
	 * @param string $appId
	 * @param string $providerId
	 * @param class-string<IContentProvider> $providerClass
	 * @return void
	 * @since 2.2.2
	 */
	public function registerContentProvider(string $appId, string $providerId, string $providerClass): void {
		if ($this->providerConfig->hasProvider($appId, $providerId)) {
			return;
		}

		try {
			Server::get($providerClass);
		} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
			$this->logger->warning('Could not find content provider by class name', ['classString' => $providerClass, 'exception' => $e]);
			return;
		}

		if (str_contains($appId, '__') || str_contains($appId, ' ') || str_contains($appId, ':')) {
			$this->logger->warning('App ID contains invalid characters, refrain from using double underscores, spaces or colons.', ['appId' => $appId]);
			return;
		}

		if (str_contains($providerId, '__') || str_contains($providerId, ' ') || str_contains($providerId, ':')) {
			$this->logger->warning('Provider ID contains invalid characters, refrain from using double underscores, spaces or colons.', ['providerId' => $providerId]);
			return;
		}

		$this->providerConfig->updateProvider($appId, $providerId, $providerClass);

		if (!$this->jobList->has(InitialContentImportJob::class, $providerClass)) {
			$this->jobList->add(InitialContentImportJob::class, $providerClass);
		}
	}

	/**
	 * Emits an event to collect all content providers
	 * @return void
	 * @since 2.2.2
	 */
	public function collectAllContentProviders(): void {
		$providerCollectionEvent = new ContentProviderRegisterEvent($this);
		$this->eventDispatcher->dispatchTyped($providerCollectionEvent);
	}

	/**
	 * Providers can use this to submit content for indexing in context chat
	 *
	 * @param string $appId
	 * @param ContentItem[] $items
	 * @return void
	 * @since 1.1.0
	 */
	public function submitContent(string $appId, array $items): void {
		$this->collectAllContentProviders();

		foreach ($items as $item) {
			$dbItem = new QueueContentItem();
			$dbItem->setItemId($item->itemId);
			$dbItem->setAppId($appId);
			$dbItem->setProviderId($item->providerId);
			$dbItem->setTitle($item->title);
			$dbItem->setContent($item->content);
			$dbItem->setDocumentType($item->documentType);
			$dbItem->setLastModified($item->lastModified);
			$dbItem->setUsers(implode(',', $item->users));

			try {
				$this->mapper->insert($dbItem);
			} catch (Exception $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
		}

		if (!$this->jobList->has(SubmitContentJob::class, null)) {
			$this->jobList->add(SubmitContentJob::class, null);
		}
	}

	/**
	 * Remove a content item from the knowledge base of context chat for specified users
	 *
	 * @param string $appId
	 * @param string $providerId
	 * @param string $itemId
	 * @param array<string> $users
	 * @return void
	 * @since 1.1.0
	 * @deprecated 4.0.0
	 */
	public function removeContentForUsers(string $appId, string $providerId, string $itemId, array $users): void {
		$this->collectAllContentProviders();

		try {
			$this->actionService->updateAccess(
				UpdateAccessOp::DENY,
				$users,
				ProviderConfigService::getSourceId($itemId, ProviderConfigService::getConfigKey($appId, $providerId)),
			);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Deny access to all content items for the given provider for specified users.
	 * If no user has access to the content items, it will be removed from the knowledge base.
	 *
	 * @param string $appId
	 * @param string $providerId
	 * @param array<string> $users
	 * @return void
	 * @since 1.1.0
	 * @deprecated 4.0.0
	 */
	public function removeAllContentForUsers(string $appId, string $providerId, array $users): void {
		$this->collectAllContentProviders();
		try {
			$this->actionService->updateAccessProvider(
				UpdateAccessOp::DENY,
				$users,
				ProviderConfigService::getConfigKey($appId, $providerId),
			);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Update access for a content item for specified users.
	 * This modifies the access list for the content item,
	 * 	allowing or denying access to the specified users.
	 * If no user has access to the content item, it will be removed from the knowledge base.
	 *
	 * @param string $appId
	 * @param string $providerId
	 * @param string $itemId
	 * @param UpdateAccessOp::* $op
	 * @param array $userIds
	 * @return void
	 * @since 4.0.0
	 */
	public function updateAccess(string $appId, string $providerId, string $itemId, string $op, array $userIds): void {
		$this->collectAllContentProviders();

		try {
			$this->actionService->updateAccess(
				$op,
				$userIds,
				ProviderConfigService::getSourceId($itemId, ProviderConfigService::getConfigKey($appId, $providerId)),
			);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Update access for content items from the given provider for specified users.
	 * If no user has access to the content item, it will be removed from the knowledge base.
	 *
	 * @param string $appId
	 * @param string $providerId
	 * @param UpdateAccessOp::* $op
	 * @param array $userIds
	 * @return void
	 * @since 4.0.0
	 */
	public function updateAccessProvider(string $appId, string $providerId, string $op, array $userIds): void {
		$this->collectAllContentProviders();

		try {
			$this->actionService->updateAccessProvider(
				$op,
				$userIds,
				ProviderConfigService::getConfigKey($appId, $providerId),
			);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Update access for a content item for specified users declaratively.
	 * This overwrites the access list for the content item,
	 * 	allowing only the specified users access to it.
	 *
	 * @param string $appId
	 * @param string $providerId
	 * @param string $itemId
	 * @param array $userIds
	 * @return void
	 * @since 4.0.0
	 */
	public function updateAccessDeclarative(string $appId, string $providerId, string $itemId, array $userIds): void {
		$this->collectAllContentProviders();

		try {
			$this->actionService->updateAccessDeclSource(
				$userIds,
				ProviderConfigService::getSourceId($itemId, ProviderConfigService::getConfigKey($appId, $providerId)),
			);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Delete all content items and access lists for a provider.
	 * This does not unregister the provider itself.
	 *
	 * @param string $appId
	 * @param string $providerId
	 * @return void
	 * @since 4.0.0
	 */
	public function deleteProvider(string $appId, string $providerId): void {
		$this->collectAllContentProviders();
		try {
			$this->actionService->deleteProvider(ProviderConfigService::getConfigKey($appId, $providerId));
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * Remove a content item from the knowledge base of context chat.
	 *
	 * @param string $appId
	 * @param string $providerId
	 * @param string[] $itemIds
	 * @return void
	 * @since 4.0.0
	 */
	public function deleteContent(string $appId, string $providerId, array $itemIds): void {
		$this->collectAllContentProviders();

		$providerKey = ProviderConfigService::getConfigKey($appId, $providerId);
		try {
			$this->actionService->deleteSources(array_map(function (string $itemId) use ($providerKey) {
				return ProviderConfigService::getSourceId($itemId, $providerKey);
			}, $itemIds));
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}
}
