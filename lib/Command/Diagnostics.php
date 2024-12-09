<?php

/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Marcel Klehr <mklehr@gmx.net>
 * @copyright Marcel Klehr 2024
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\BackgroundJobs\DeleteJob;
use OCA\ContextChat\BackgroundJobs\IndexerJob;
use OCA\ContextChat\BackgroundJobs\StorageCrawlJob;
use OCA\ContextChat\Service\DiagnosticService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Diagnostics extends Command {

	public function __construct(
		private DiagnosticService $diagnosticService,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:diagnostics')
			->setDescription('Check currently running ContextChat background processes');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		foreach ([
			IndexerJob::class,
			DeleteJob::class,
			StorageCrawlJob::class
		] as $jobCategory) {
			$output->writeln($jobCategory);
			$count = 0;
			foreach ($this->diagnosticService->getBackgroundJobDiagnostics() as $job => $stats) {
				[$jobClass, $jobId] = explode('-', $job, 2);
				if ($jobClass !== $jobCategory) {
					continue;
				}
				$count++;
				$output->write("\t$jobId\t");
				foreach ($stats as $stat => $value) {
					if ($stat === 'last_seen') {
						$output->write('last_seen=' . (new \DateTime('@' . $value))->format('Y-m-d H:i:s'));
					}
				}
				$output->writeln('');
			}
			if ($count === 0) {
				$output->writeln('No jobs running.');
			}
			$output->writeln('');
		}
		return 0;
	}
}
