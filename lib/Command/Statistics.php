<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\Service\ActionService;
use OCA\ContextChat\Service\QueueService;
use OCP\AppFramework\Services\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Statistics extends Command {

	public function __construct(
		private IAppConfig $appConfig,
		private ActionService $actionService,
		private QueueService $queueService,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:stats')
			->setDescription('Check ContextChat statistics');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$output->writeln('ContextChat statistics:');
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0) === 0) {
			$output->writeln('The indexing is not complete yet.');
		} else {
			$installedTime = $this->appConfig->getAppValueInt('installed_time', 0);
			$lastIndexedTime = $this->appConfig->getAppValueInt('last_indexed_time', 0);
			$indexTime = $lastIndexedTime - $installedTime;

			$output->writeln('Installed time: ' . (new \DateTime('@' . $installedTime))->format('Y-m-d H:i') . ' UTC');
			$output->writeln('Index complete time: ' . (new \DateTime('@' . $lastIndexedTime))->format('Y-m-d H:i') . ' UTC');
			$output->writeln('Total time taken for a complete index: ' . gmdate('H:i', $indexTime) . ' (hh:mm)');
		}

		$queueCount = $this->queueService->count();
		$output->writeln('Files in indexing queue: ' . $queueCount);

		$actionsCount = $this->actionService->count();
		$output->writeln('Actions in queue: ' . $actionsCount);
		return 0;
	}
}
