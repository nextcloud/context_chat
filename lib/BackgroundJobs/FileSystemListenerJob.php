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
use OCP\BackgroundJob\TimedJob;
use OCP\DB\Exception;
use OCP\Files\IRootFolder;
use OCP\IConfig;

class FileSystemListenerJob extends TimedJob {
	private const BATCH_SIZE = 500;

	public function __construct(
		ITimeFactory $timeFactory,
		private FsEventMapper $fsEventMapper,
		private Logger $logger,
		private DiagnosticService $diagnosticService,
		private IAppManager $appManager,
		private FsEventService $fsEventService,
		private IRootFolder $rootFolder,
		private IConfig $config,
	) {
		parent::__construct($timeFactory);
		$this->allowParallelRuns = false;
		$this->setInterval($this->getJobInterval());
	}

	private function getJobInterval(): int {
		return intval($this->config->getAppValue('context_chat', 'fs_listener_job_interval', (string)(5 * 60))); // 5 minutes
	}

	protected function run($argument): void {
		if (!$this->appManager->isInstalled('app_api')) {
			$this->logger->warning('FileSystemListenerJob is skipped as app_api is disabled');
			return;
		}

		try {
			$this->diagnosticService->sendJobStart(static::class, $this->getId());
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			try {
				$fsEvents = $this->fsEventMapper->getFromQueue(static::BATCH_SIZE);
			} catch (Exception $e) {
				$this->logger->warning('Error fetching fs events: ' . $e->getMessage(), ['exception' => $e]);
				return;
			}

			if (empty($fsEvents)) {
				return;
			}

			$eventsByUser = [];
			foreach ($fsEvents as $fsEvent) {
				if (!isset($eventsByUser[$fsEvent->getUserId()])) {
					$eventsByUser[$fsEvent->getUserId()] = [];
				}
				$eventsByUser[$fsEvent->getUserId()][] = $fsEvent;
			}

			foreach ($eventsByUser as $userId => $events) {
				foreach ($events as $fsEvent) {
					$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

					try {
						$node = current($this->rootFolder->getUserFolder($fsEvent->getUserId())->getById($fsEvent->getNodeId()));
					} catch (\Exception $e) {
						$this->logger->warning('Error retrieving node for fs event "' . $fsEvent->getType() . '": ' . $e->getMessage(), ['exception' => $e]);
						$node = false;
					}
					if ($node === false) {
						$this->logger->warning('Node with ID ' . $fsEvent->getNodeId() . ' not found for fs event "' . $fsEvent->getType() . '"');
						try {
							$this->fsEventMapper->delete($fsEvent);
						} catch (Exception $e) {
							$this->logger->warning('Error deleting fs event "' . $fsEvent->getType() . '": ' . $e->getMessage(), ['exception' => $e]);
						}
						continue;
					}

					try {
						switch ($fsEvent->getTypeObject()) {
							case FsEventType::CREATE:
								$this->fsEventService->onInsert($node);
								break;
							case FsEventType::ACCESS_UPDATE_DECL:
								$this->fsEventService->onAccessUpdateDecl($node);
								break;
						}
						$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
					} catch (\Throwable $e) {
						$this->logger->warning('Error handling fs event "' . $fsEvent->getType() . '": ' . $e->getMessage(), ['exception' => $e]);
					}
					try {
						$this->fsEventMapper->delete($fsEvent);
					} catch (Exception $e) {
						$this->logger->warning('Error deleting fs event "' . $fsEvent->getType() . '": ' . $e->getMessage(), ['exception' => $e]);
					}
				}
				// Tear down to avoid memory leaks
				\OC_Util::tearDownFS();
			}
		} finally {
			$this->diagnosticService->sendJobEnd(static::class, $this->getId());
		}
	}
}
