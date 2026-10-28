<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\Service\ReindexService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Wipe extends Command {

	public function __construct(
		private ReindexService $reindexService,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:wipe')
			->setDescription('Delete all the indexed documents from context_chat_backend\'s vector DB, clear the indexing and actions queues and reset the indexing statistics. Nothing gets indexed again until context_chat:reindex is run or the --reindex option is used.')
			->addOption(
				'reindex',
				null,
				InputOption::VALUE_NONE,
				'Schedule a full re-crawl of all the files and a re-import of all the content providers after the wipe',
			)
			->addOption(
				'force',
				'f',
				InputOption::VALUE_NONE,
				'Do not ask for confirmation',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		if (!$input->getOption('force')) {
			$question = new ConfirmationQuestion('All the indexed documents in Context Chat will be deleted. The original documents are not touched. Continue? [y/N] ', false);
			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('<comment>Aborted, nothing was deleted. Use --force to skip the confirmation.</comment>');
				return 1;
			}
		}

		try {
			$this->reindexService->wipe();
		} catch (\Throwable $e) {
			$output->writeln('<error>Wipe failed: ' . $e->getMessage() . '</error>');
			$output->writeln('<error>The command can be safely run again once the problem is fixed.</error>');
			return 1;
		}
		$output->writeln('<info>Deleted all the indexed documents and cleared the queues.</info>');

		if ($input->getOption('reindex')) {
			$this->reindexService->scheduleReindex();
			$output->writeln('<info>Scheduled a full re-crawl of files and re-import of the content providers.</info>');
		} else {
			$question = new ConfirmationQuestion('Re-index the documents now? [Y/n] ', default: true);
			if ($helper->ask($input, $output, $question)) {
				$this->reindexService->scheduleReindex();
				$output->writeln('<info>Scheduled a full re-crawl of files and re-import of the content providers.</info>');
			} else {
				$output->writeln('Run context_chat:reindex to index the documents again later.');
			}
		}
		return 0;
	}
}
