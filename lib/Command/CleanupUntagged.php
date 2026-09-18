<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\BackgroundJobs\UntaggedCleanupSchedulerJob;
use OCP\BackgroundJob\IJobList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Schedule removal from the index of all files that do not have the
 * "AI knowledge" tag. Used when switching to the tag-only indexing mode.
 */
class CleanupUntagged extends Command {
	public function __construct(
		private IJobList $jobList,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:cleanup-untagged')
			->setDescription('Schedule removal from the index of all files that do not have the "AI knowledge" tag.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->jobList->has(UntaggedCleanupSchedulerJob::class, null)) {
			$output->writeln('<comment>A cleanup is already scheduled; nothing to do.</comment>');
			return 0;
		}
		$this->jobList->add(UntaggedCleanupSchedulerJob::class);
		$output->writeln('<info>Scheduled removal of untagged files from the index.</info>');
		return 0;
	}
}
