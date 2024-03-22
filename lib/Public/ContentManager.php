<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2024
 */

namespace OCA\ContextChat\Public;

use OCA\ContextChat\BackgroundJobs\InitialContentImportJob;
use OCA\ContextChat\BackgroundJobs\SubmitContentJob;
use OCA\ContextChat\Db\QueueContentItem;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

class ContentManager {
	public function __construct(
		private IJobList $jobList,
		private ProviderConfigService $providerConfig,
		private LangRopeService $service,
		private QueueContentItemMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param class-string<IContentProvider> $providerClass
	 * @return void
	 * @since 1.1.0
	 */
	public function registerContentProvider(string $providerClass): void {
		try {
			/** @var IContentProvider */
			$providerObj = Server::get($providerClass);
		} catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
			$this->logger->warning('Could not register content provider', ['exception' => $e]);
			return;
		}

		if ($this->providerConfig->hasProvider($providerObj->getAppId(), $providerObj->getId())) {
			return;
		}

		$this->providerConfig->updateProvider($providerObj->getAppId(), $providerObj->getId(), $providerClass);

		if (!$this->jobList->has(InitialContentImportJob::class, $providerClass)) {
			$this->jobList->add(InitialContentImportJob::class, $providerClass);
		}
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

			$this->mapper->insert($dbItem);
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
	 * @param array $users
	 * @return void
	 * @since 1.1.0
	 */
	public function removeContentForUsers(string $appId, string $providerId, string $itemId, array $users): void {
		foreach ($users as $userId) {
			$this->service->deleteSources($userId, [
				ProviderConfigService::getSourceId($itemId, ProviderConfigService::getConfigKey($appId, $providerId))
			]);
		}
	}

	/**
	 * Remove all content items from the knowledge base of context chat for specified users
	 *
	 * @param string $appId
	 * @param string $providerId
	 * @param array $users
	 * @return void
	 */
	public function removeAllContentForUsers(string $appId, string $providerId, array $users): void {
		foreach ($users as $userId) {
			$this->service->deleteSourcesByProvider($userId, ProviderConfigService::getConfigKey($appId, $providerId));
		}
	}
}
