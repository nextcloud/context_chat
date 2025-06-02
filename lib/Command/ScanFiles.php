<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Service\ScanService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ScanFiles extends Command {

	public function __construct(
		private ScanService $scanService,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:scan')
			->setDescription('Scan user files')
			->addArgument(
				'user_id',
				InputArgument::REQUIRED,
				'The user ID to scan the storage of'
			)
			->addOption('mimetype', 'm', InputOption::VALUE_REQUIRED, 'The mime type filter')
			->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The directory to scan, relative to the user\'s home directory');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$mimeTypeFilter = $input->getOption('mimetype') !== null
			? explode(',', $input->getOption('mimetype'))
			: Application::MIMETYPES;

		if ($mimeTypeFilter === false) {
			$output->writeln('Invalid mime type filter');
			return 1;
		}

		$userId = $input->getArgument('user_id');
		$scan = $this->scanService->scanUserFiles($userId, $mimeTypeFilter, $input->getOption('directory'));
		foreach ($scan as $s) {
			$output->writeln('[' . $userId . '] Scanned ' . $s->title);
		}

		return 0;
	}
}
