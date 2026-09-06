<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\BackgroundJobs\StorageCrawlJob;
use OCA\ContextChat\Logger;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\TaskProcessing\IManager as TaskProcessingManager;

class MultimodalService {
	public const OCR_TASK_TYPE = 'core:image2text:ocr';
	public const SPEECH_TO_TEXT_TASK_TYPE = 'core:audio2text';
	public const MULTIMODAL_CONFIG_KEY = 'multimodal_enabled';

	public function __construct(
		private Logger $logger,
		private TaskProcessingManager $taskProcessingManager,
		private IAppConfig $appConfig,
		private StorageService $storageService,
		private IJobList $jobList,
		private TaskTypeService $taskTypeService,
	) {
	}

	public function isMultimodalEnabled(): bool {
		return $this->appConfig->getAppValueBool(self::MULTIMODAL_CONFIG_KEY, false, lazy: true);
	}

	/**
	 * @return array{ocrAvailable: bool, sttAvailable: bool}
	 */
	public function checkTaskTypes(): array {
		return [
			'ocrAvailable' => $this->taskTypeService->isOcrTaskTypeAvailable(),
			'sttAvailable' => $this->taskTypeService->isSpeechToTextTaskTypeAvailable(),
		];
	}

	/**
	 * @param boolean $ignoreIfAlreadyEnabled
	 * @return void
	 * @throws \Exception
	 */
	public function enableMultimodal(bool $ignoreIfAlreadyEnabled = false): void {
		$multimodalEnabled = $this->appConfig->getAppValueBool(self::MULTIMODAL_CONFIG_KEY, false, lazy: true);
		if ($multimodalEnabled) {
			if ($ignoreIfAlreadyEnabled) {
				return;
			}
			throw new \Exception('Multimodal indexing is already enabled, doing nothing.');
		}
		$this->appConfig->setAppValueBool(self::MULTIMODAL_CONFIG_KEY, true, lazy: true);
	}

	/**
	 * @return list<string> Error messages for mounts that failed to be queued.
	 */
	public function queueExistingMultimodalFiles(): array {
		$errors = [];
		foreach ($this->storageService->getMounts() as $mount) {
			$this->logger->debug('Scheduling StorageCrawlJob storage_id=' . $mount['storage_id'] . ' root_id=' . $mount['root_id'] . ' override_root=' . $mount['overridden_root']);
			try {
				$this->jobList->add(StorageCrawlJob::class, [
					'storage_id' => $mount['storage_id'],
					'root_id' => $mount['root_id'],
					'overridden_root' => $mount['overridden_root'],
					'last_file_id' => 0,
					'only_non_textual' => true,
				]);
			} catch (\Exception $e) {
				$this->logger->error('Failed to schedule StorageCrawlJob for mount.', [
					'storage_id' => $mount['storage_id'],
					'exception' => $e,
				]);
				$errors[] = 'Failed to queue files from storage id "' . $mount['storage_id'] . '": ' . $e->getMessage();
			}
		}
		return $errors;
	}
}
