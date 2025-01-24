<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\BackgroundJobs\ActionJob;
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
			ActionJob::class,
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
					if ($stat === 'last_triggered') {
						$output->write('last_triggered=' . (new \DateTime('@' . $value))->format('Y-m-d H:i:s'));
					}
					if ($stat === 'started_count') {
						$output->write('started_count=' . $value);
					}
					if ($stat === 'last_started') {
						$output->write('last_started=' . (new \DateTime('@' . $value))->format('Y-m-d H:i:s'));
					}
					if ($stat === 'last_seen') {
						$output->write('last_seen=' . (new \DateTime('@' . $value))->format('Y-m-d H:i:s'));
					}
					if ($stat === 'last_ended') {
						$output->write('last_ended=' . (new \DateTime('@' . $value))->format('Y-m-d H:i:s'));
					}
					$output->write(' ');
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
