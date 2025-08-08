<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\Db\FsEventMapper;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Service\ActionScheduler;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Util;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Statistics extends Command {

	public function __construct(
		private IAppConfig $appConfig,
		private ActionScheduler $actionService,
		private QueueService $queueService,
		private StorageService $storageService,
		private LangRopeService $langRopeService,
		private QueueContentItemMapper $contentQueue,
		private FsEventMapper $fsEventMapper,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:stats')
			->setDescription('Check ContextChat statistics');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('ContextChat statistics:');
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0) === 0) {
			$output->writeln('The indexing is not complete yet.');
		} else {
			$installedTime = $this->appConfig->getAppValueInt('installed_time', 0);
			$lastIndexedTime = $this->appConfig->getAppValueInt('last_indexed_time', 0);
			$indexTime = $lastIndexedTime - $installedTime;

			$output->writeln('Installed time: ' . (new \DateTime('@' . $installedTime))->format('Y-m-d H:i') . ' UTC');
			$output->writeln('Index complete time: ' . (new \DateTime('@' . $lastIndexedTime))->format('Y-m-d H:i') . ' UTC');
			$output->writeln('Total time taken for complete index: ' . strval(floor($indexTime / (60 * 60 * 24))) . ' days ' . gmdate('H:i', $indexTime) . ' (hh:mm)');
		}

		$eligibleFilesCount = $this->storageService->countFiles();
		$output->writeln('Total eligible files: ' . $eligibleFilesCount);

		$queueCount = $this->queueService->count();
		$output->writeln('Files in indexing queue: ' . $queueCount);

		$queueNewCount = $this->queueService->countNewFiles();
		$output->writeln('New files in indexing queue (without updates): ' . $queueNewCount);

		$queuedDocumentsCount = $this->contentQueue->count();
		$output->writeln('Queued documents (without files):' . var_export($queuedDocumentsCount, true));

		$indexFilesCount = Util::numericToNumber($this->appConfig->getAppValueString('indexed_files_count', '0'));
		$output->writeln('Files successfully sent to backend: ' . strval($indexFilesCount));

		$indexedDocumentsCount = $this->langRopeService->getIndexedDocumentsCounts();
		$output->writeln('Indexed documents: ' . var_export($indexedDocumentsCount, true));

		$actionsCount = $this->actionService->count();
		$output->writeln('Actions in queue: ' . $actionsCount);

		$fsEventsCount = $this->fsEventMapper->count();
		$output->writeln('File system events in queue: ' . $fsEventsCount);
		return 0;
	}
}
