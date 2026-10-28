<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\Service\ReindexService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-seed the one-shot filesystem crawl/index on demand.
 *
 * SchedulerJob -> StorageCrawlJob -> IndexerJob is seeded only by the <install> repair step and
 * removes itself once the initial crawl finishes. There is otherwise no way to re-enumerate
 * mounts (e.g. to recover a crawl that did not complete, or after context_chat:wipe) short of
 * reinstalling the app. This command re-adds SchedulerJob so the full enumeration runs again if one
 * doesn't exist already.
 * Content providers' initial import also gets scheduled (e.g. after context_chat:wipe) if it has not run yet.
 */
class Reindex extends Command {

	public function __construct(
		private ReindexService $reindexService,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:reindex')
			->setDescription('Schedule a full re-crawl of all the files in all the mounts. Indexed files are not re-indexed when compared against context_chat_backend\'s vector DB. Content providers are only re-imported after context_chat:wipe.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->reindexService->scheduleReindex()) {
			$output->writeln('<comment>A full re-crawl is already scheduled; nothing to do.</comment>');
			return 0;
		}

		$output->writeln('<info>Scheduled a full re-crawl. SchedulerJob will enumerate all mounts on its next run.</info>');
		return 0;
	}
}
