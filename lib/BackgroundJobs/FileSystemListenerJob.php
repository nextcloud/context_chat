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
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;

class FileSystemListenerJob extends TimedJob {
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
		$this->allowParallelRuns = false;
		$this->setInterval(5 * 60); // 5 minutes
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
					$node = current($this->rootFolder->getUserFolder($fsEvent->getUserId())->getById($fsEvent->getNodeId()));
					if ($node === false) {
						$this->logger->warning('Node with ID ' . $fsEvent->getNodeId() . ' not found for fs event "' . $fsEvent->getType() . '"');
						$this->fsEventMapper->delete($fsEvent);
						continue;
					}

					switch ($fsEvent->getTypeObject()) {
						case FsEventType::CREATE:
							$this->fsEventService->onInsert($node);
							break;
						case FsEventType::ACCESS_UPDATE_DECL:
							$this->fsEventService->onAccessUpdateDecl($node);
							break;
					}
					$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
					$this->fsEventMapper->delete($fsEvent);
				} catch (\RuntimeException $e) {
					$this->logger->warning('Error handling fs event "' . $fsEvent->getType() . '": ' . $e->getMessage(), ['exception' => $e]);
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
