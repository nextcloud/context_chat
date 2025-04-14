<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\DiagnosticService;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Type\ActionType;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

class ActionJob extends QueuedJob {
	private const BATCH_SIZE = 100;

	public function __construct(
		ITimeFactory $timeFactory,
		private LangRopeService $networkService,
		private QueueActionMapper $actionMapper,
		private IJobList $jobList,
		private Logger $logger,
		private DiagnosticService $diagnosticService,
		private IAppManager $appManager,
	) {
		parent::__construct($timeFactory);
	}

	protected function run($argument): void {
		if (!$this->appManager->isInstalled('app_api')) {
			$this->logger->warning('ActionJob is skipped as app_api is disabled');
			return;
		}

		$this->diagnosticService->sendJobStart(static::class, $this->getId());
		$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
		$entities = $this->actionMapper->getFromQueue(static::BATCH_SIZE);

		if (empty($entities)) {
			return;
		}

		try {
			foreach ($entities as $entity) {
				$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

				try {
					switch ($entity->getType()) {
						case ActionType::DELETE_SOURCE_IDS:
							$decoded = json_decode($entity->getPayload(), true);
							if (!is_array($decoded) || !isset($decoded['sourceIds'])) {
								$this->logger->warning('Invalid payload for DELETE_SOURCE_IDS action', ['payload' => $entity->getPayload()]);
								break;
							}
							$this->networkService->deleteSources($decoded['sourceIds']);
							break;

						case ActionType::DELETE_PROVIDER_ID:
							$decoded = json_decode($entity->getPayload(), true);
							if (!is_array($decoded) || !isset($decoded['providerId'])) {
								$this->logger->warning('Invalid payload for DELETE_PROVIDER_ID action', ['payload' => $entity->getPayload()]);
								break;
							}
							$this->networkService->deleteProvider($decoded['providerId']);
							break;

						case ActionType::DELETE_USER_ID:
							$decoded = json_decode($entity->getPayload(), true);
							if (!is_array($decoded) || !isset($decoded['userId'])) {
								$this->logger->warning('Invalid payload for DELETE_USER_ID action', ['payload' => $entity->getPayload()]);
								break;
							}
							$this->networkService->deleteUser($decoded['userId']);
							break;

						case ActionType::UPDATE_ACCESS_SOURCE_ID:
							$decoded = json_decode($entity->getPayload(), true);
							if (!is_array($decoded) || !isset($decoded['op']) || !isset($decoded['userIds']) || !isset($decoded['sourceId'])) {
								$this->logger->warning('Invalid payload for UPDATE_ACCESS_SOURCE_ID action', ['payload' => $entity->getPayload()]);
								break;
							}
							$this->networkService->updateAccess($decoded['op'], $decoded['userIds'], $decoded['sourceId']);
							break;

						case ActionType::UPDATE_ACCESS_PROVIDER_ID:
							$decoded = json_decode($entity->getPayload(), true);
							if (!is_array($decoded) || !isset($decoded['op']) || !isset($decoded['userIds']) || !isset($decoded['providerId'])) {
								$this->logger->warning('Invalid payload for UPDATE_ACCESS_PROVIDER_ID action', ['payload' => $entity->getPayload()]);
								break;
							}
							$this->networkService->updateAccessProvider($decoded['op'], $decoded['userIds'], $decoded['providerId']);
							break;

						case ActionType::UPDATE_ACCESS_DECL_SOURCE_ID:
							$decoded = json_decode($entity->getPayload(), true);
							if (!is_array($decoded) || !isset($decoded['userIds']) || !isset($decoded['sourceId'])) {
								$this->logger->warning('Invalid payload for UPDATE_ACCESS_DECL_SOURCE_ID action', ['payload' => $entity->getPayload()]);
								break;
							}
							$this->networkService->updateAccessDeclarative($decoded['userIds'], $decoded['sourceId']);
							break;

						default:
							$this->logger->warning('Unknown action type', ['type' => $entity->getType()]);
					}
					$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
					$this->actionMapper->removeFromQueue($entity);
				} catch (\RuntimeException $e) {
					$this->logger->warning('Error performing action "' . $entity->getType() . '": ' . $e->getMessage(), ['exception' => $e]);
				}
			}
		} catch (\Throwable $e) {
			// schedule in 5mins
			$this->jobList->scheduleAfter(static::class, $this->time->getTime() + 5 * 60);
			throw $e;
		}

		// schedule in 5mins
		$this->jobList->scheduleAfter(static::class, $this->time->getTime() + 5 * 60);
		$this->diagnosticService->sendJobEnd(static::class, $this->getId());
	}
}
