<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Db\FsEventMapper;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\DiagnosticService;
use OCA\ContextChat\Service\FsEventService;
use OCA\ContextChat\Type\FsEventType;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;

class FileSystemListenerJob extends QueuedJob {
	private const BATCH_SIZE = 500;

	public function __construct(
		ITimeFactory $timeFactory,
		private FsEventMapper $fsEventMapper,
		private IJobList $jobList,
		private Logger $logger,
		private DiagnosticService $diagnosticService,
		private IAppManager $appManager,
		private FsEventService $fsEventService,
		private IRootFolder $rootFolder,
	) {
		parent::__construct($timeFactory);
	}

	protected function run($argument): void {
		if (!$this->appManager->isInstalled('app_api')) {
			$this->logger->warning('FileSystemListenerJob is skipped as app_api is disabled');
			return;
		}

		$this->diagnosticService->sendJobStart(static::class, $this->getId());
		$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
		$fsEvents = $this->fsEventMapper->getFromQueue(static::BATCH_SIZE);

		if (empty($fsEvents)) {
			return;
		}

		try {
			foreach ($fsEvents as $fsEvent) {
				$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

				try {

					$node = current($this->rootFolder->getById($fsEvent->getNodeId()));
					if ($node === false) {
						return;
					}

					switch ($fsEvent->getType()) {
						case FsEventType::CREATE:
							$this->fsEventService->onInsert($node);
							break;
						case FsEventType::ACCESS_UPDATE:
							$this->fsEventService->onAccessUpdate($node);
							break;
						case FsEventType::DELETE:
							$this->fsEventService->onDelete($node);
							break;

						default:
							$this->logger->warning('Unknown fs event  type', ['type' => $fsEvent->getType()]);
					}
					$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
					$this->fsEventMapper->delete($fsEvent);
				} catch (\RuntimeException $e) {
					$this->logger->warning('Error handling fs event "' . $fsEvent->getType()->value . '": ' . $e->getMessage(), ['exception' => $e]);
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
