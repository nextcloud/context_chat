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

declare(strict_types=1);

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Db\QueueContentItem;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Type\Source;
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
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param $argument
	 * @return void
	 */
	protected function run($argument): void {
		$entities = $this->mapper->getFromQueue(static::BATCH_SIZE);

		if (empty($entities)) {
			return;
		}

		$sources = array_map(function (QueueContentItem $item) {
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

		$loadedSources = $this->service->indexSources($sources);
		$this->logger->info('Indexed sources for providers', [
			'count' => count($loadedSources),
			'sources' => $loadedSources,
		]);

		foreach ($entities as $entity) {
			$this->mapper->removeFromQueue($entity);
		}

		$this->jobList->add(static::class);
	}
}
