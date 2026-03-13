<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\BackgroundJobs\StorageCrawlJob;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\StorageService;
use OCA\ContextChat\Service\TaskTypeService;
use OCP\BackgroundJob\IJobList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class QueueMultimodalFiles extends Command {

	public function __construct(
		private TaskTypeService $taskTypeService,
		private StorageService $storageService,
		private IJobList $jobList,
		private Logger $logger,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:queue-multimodal-files')
			->setDescription(
				'Queue existing multimodal files (Images and Audio) for indexation.'
				. ' Each type of files is queued only if the required TaskProcessing task provider is available.'
				. ' OCR for Images and Speech-to-text for Audio.'
				. ' See https://docs.nextcloud.com/server/latest/admin_manual/ai/overview.html for more information.'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		if (!$this->taskTypeService->isOcrTaskTypeAvailable()) {
			$output->writeln('<warning>OCR task type is not available.</warning>');
		}
		if (!$this->taskTypeService->isSpeechToTextTaskTypeAvailable()) {
			$output->writeln('<warning>Speech-to-text task type is not available.</warning>');
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
			$output->writeln('<error>Failed to schedule StorageCrawlJob to find files for indexation: ' . $e->getMessage() . '</error>');
			return 1;
		}

		$output->writeln('<info>Multimodal files have been scheduled to be queued for indexation.</info>');
		return 0;
	}
}
