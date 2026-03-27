<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCA\ContextChat\BackgroundJobs\StorageCrawlJob;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\StorageService;
use OCA\ContextChat\Service\TaskTypeService;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version006000000Date20260316135634 extends SimpleMigrationStep {
	public function __construct(
		private TaskTypeService $taskTypeService,
		private StorageService $storageService,
		private IJobList $jobList,
		private Logger $logger,
	) {
	}

	public function name(): string {
		return 'Queue existing multimodal files (Images and Audio) for indexation.';
	}

	public function description(): string {
		return 'This migration queues existing multimodal files (Images and Audio) for indexation.'
			. ' Each type of files is queued only if the required TaskProcessing task provider is available.'
			. ' OCR for Images and Speech-to-text for Audio.'
			. ' See https://docs.nextcloud.com/server/latest/admin_manual/ai/overview.html for more information.';
	}

	public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
		if (!$this->taskTypeService->isOcrTaskTypeAvailable()) {
			$output->warning('[Context Chat] OCR task type is not available, image files will not be indexed.');
		}
		if (!$this->taskTypeService->isSpeechToTextTaskTypeAvailable()) {
			$output->warning('[Context Chat] Speech-to-text task type is not available, audio files will not be indexed.');
		}

		try {
			foreach ($this->storageService->getMounts() as $mount) {
				$this->logger->debug('Scheduling StorageCrawlJob storage_id=' . $mount['storage_id'] . ' root_id=' . $mount['root_id' ] . 'override_root=' . $mount['overridden_root']);
				$this->jobList->add(StorageCrawlJob::class, [
					'storage_id' => $mount['storage_id'],
					'root_id' => $mount['root_id' ],
					'overridden_root' => $mount['overridden_root'],
					'last_file_id' => 0,
					'only_non_textual' => true,
				]);
			}
		} catch (\Exception $e) {
			$this->logger->error('Failed to schedule StorageCrawlJob to find files for indexation.', ['exception' => $e]);
			$output->warning('Failed to schedule StorageCrawlJob to find files for indexation: ' . $e->getMessage());
			return;
		}

		$output->info('Multimodal files have been scheduled to be queued for indexation.');
	}
}
