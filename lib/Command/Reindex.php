<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\BackgroundJobs\SchedulerJob;
use OCP\BackgroundJob\IJobList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-seed the one-shot crawl chain on demand.
 *
 * SchedulerJob -> StorageCrawlJob -> IndexerJob is seeded only by the <install> repair step and
 * removes itself once the initial crawl finishes. There is otherwise no way to re-enumerate
 * mounts (e.g. after installing on an instance whose files predate the app, or to recover a crawl
 * that did not complete) short of reinstalling the app. This command re-adds SchedulerJob so the
 * full enumeration runs again; it is a no-op if one is already scheduled, and already-indexed
 * files are skipped (the queue de-duplicates).
 */
class Reindex extends Command {

	public function __construct(
		private IJobList $jobList,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:reindex')
			->setDescription('Schedule a full re-crawl of all mounts (re-seeds the indexing chain; indexed files are skipped)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->jobList->has(SchedulerJob::class, null)) {
			$output->writeln('<comment>A full re-crawl is already scheduled; nothing to do.</comment>');
			return 0;
		}

		$this->jobList->add(SchedulerJob::class);
		$output->writeln('<info>Scheduled a full re-crawl. SchedulerJob will enumerate all mounts on its next run.</info>');
		return 0;
	}
}
