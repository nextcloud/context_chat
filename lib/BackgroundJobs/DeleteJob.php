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

use OCA\ContextChat\Db\QueueDeleteMapper;
use OCA\ContextChat\Service\DeleteService;
use OCA\ContextChat\Service\DiagnosticService;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Type\DeleteContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

class DeleteJob extends QueuedJob {
	private const BATCH_SIZE = 1000;

	public function __construct(
		ITimeFactory $timeFactory,
		private LangRopeService $networkService,
		private QueueDeleteMapper $deleteMapper,
		private DeleteService $deleteService,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private DiagnosticService $diagnosticService,
	) {
		parent::__construct($timeFactory);
	}

	protected function run($argument): void {
		$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
		$entities = $this->deleteMapper->getFromQueue(static::BATCH_SIZE);

		if (empty($entities)) {
			return;
		}

		$bucket = $this->deleteService->bucketIntoTypes($entities);

		foreach ($bucket[DeleteContext::PROVIDER_ALL_USERS] as $providerKey) {
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			$this->networkService->deleteSourcesByProviderForAllUsers($providerKey);
		}

		foreach ($bucket[DeleteContext::PROVIDER_ONE_USER] as $userId => $providerKeys) {
			foreach ($providerKeys as $providerKey) {
				$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
				$this->networkService->deleteSourcesByProvider($userId, $providerKey);
			}
		}

		foreach ($bucket[DeleteContext::SOURCE_ONE_USER] as $userId => $sourceIds) {
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			$this->networkService->deleteSources($userId, $sourceIds);
		}

		foreach ($entities as $entity) {
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			$this->deleteMapper->removeFromQueue($entity);
		}

		$this->jobList->add(static::class);
	}
}
