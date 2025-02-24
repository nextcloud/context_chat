<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueContentItem;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Exceptions\RetryIndexException;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Type\Source;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class SubmitContentJob extends QueuedJob {
	private const BATCH_SIZE = 20;

	public function __construct(
		ITimeFactory $timeFactory,
		private LangRopeService $service,
		private QueueContentItemMapper $mapper,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param $argument
	 * @return void
	 */
	protected function run($argument): void {
		$entities = $this->mapper->getFromQueue(static::BATCH_SIZE);
		$maxSize = $this->appConfig->getAppValueInt('indexing_max_size', Application::CC_MAX_SIZE);

		if (empty($entities)) {
			return;
		}

		$sources = array_map(function (QueueContentItem $item) use ($maxSize) {
			$contentSize = mb_strlen($item->getContent(), '8bit');
			if ($contentSize > $maxSize) {
				$this->logger->warning('Content too large to index', [
					'contentSize' => $contentSize,
					'maxSize' => $maxSize,
					'itemId' => $item->getItemId(),
					'providerId' => $item->getProviderId(),
					'appId' => $item->getAppId(),
				]);
				return null;
			}

			$providerKey = ProviderConfigService::getConfigKey($item->getAppId(), $item->getProviderId());
			$sourceId = ProviderConfigService::getSourceId($item->getItemId(), $providerKey);
			return new Source(
				explode(',', $item->getUsers()),
				$sourceId,
				$item->getTitle(),
				$item->getContent(),
				$item->getLastModified()->getTimeStamp(),
				$item->getDocumentType(),
				$providerKey,
			);
		}, $entities);
		$sources = array_filter($sources);

		try {
			$loadSourcesResult = $this->service->indexSources($sources);
			$this->logger->info('Indexed sources for providers', [
				'count' => count($loadSourcesResult['loaded_sources']),
				'loaded_sources' => $loadSourcesResult['loaded_sources'],
				'sources_to_retry' => $loadSourcesResult['sources_to_retry'],
			]);
		} catch (RetryIndexException $e) {
			$this->logger->debug('At least one source is already being processed from another request, trying again soon', ['exception' => $e]);
			// schedule in 5mins
			$this->jobList->scheduleAfter(static::class, $this->time->getTime() + 5 * 60);
			return;
		}

		foreach ($entities as $entity) {
			$providerKey = ProviderConfigService::getConfigKey($entity->getAppId(), $entity->getProviderId());
			$sourceId = ProviderConfigService::getSourceId($entity->getItemId(), $providerKey);
			if (!in_array($sourceId, $loadSourcesResult['sources_to_retry'])) {
				$this->mapper->removeFromQueue($entity);
			}
		}

		// schedule in 5mins
		$this->jobList->scheduleAfter(static::class, $this->time->getTime() + 5 * 60);
	}
}
