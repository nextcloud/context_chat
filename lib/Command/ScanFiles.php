<?php

/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
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
		private ScanService $scanService
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
			->addOption('mimetype', 'm', InputOption::VALUE_REQUIRED, 'The mime type filter');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$mimeTypeFilter = $input->getOption('mimetype') !== null
			? explode(',', $input->getOption('mimetype'))
			: Application::MIMETYPES;

		if ($mimeTypeFilter === false) {
			$output->writeln('Invalid mime type filter');
			return 1;
		}

		$userId = $input->getArgument('user_id');
		$scan = $this->scanService->scanUserFiles($userId, $mimeTypeFilter);
		foreach ($scan as $s) {
			$output->writeln('[' . $userId . '] Scanned ' . $s->title);
		}

		return 0;
	}
}
