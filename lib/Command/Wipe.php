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
			->setDescription(
				'Delete the indexed documents from context_chat_backend\'s vector DB and clear the queues.'
				. ' With --provider option, only the provider\'s data is deleted, the actions queue, and the indexing statistics are left intact.'
				. ' Nothing gets indexed again until context_chat:reindex is run or the --reindex option is used.'
			)
			->addOption(
				'provider',
				'p',
				InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Only wipe this provider, e.g. "files__default" (appId__providerId) for the files. Multiple values can be specified by repeating the option.',
			)
			->addOption(
				'reindex',
				null,
				InputOption::VALUE_NONE,
				'Schedule a re-crawl of the files and a re-import of the content providers that were wiped',
			)
			->addOption(
				'force',
				'f',
				InputOption::VALUE_NONE,
				'Do not ask for confirmation',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		/** @var list<string> $providerKeys */
		$providerKeys = $input->getOption('provider');
		$scope = $providerKeys === [] ? null : $providerKeys;

		if ($scope !== null) {
			try {
				$known = $this->reindexService->getWipeableProviderKeys();
			} catch (\RuntimeException $e) {
				$output->writeln('<error>Could not list the indexed providers: ' . $e->getMessage() . '</error>');
				return 1;
			}
			$unknown = array_diff($scope, $known);
			if ($unknown !== []) {
				$output->writeln('<error>Unknown provider(s): ' . implode(', ', $unknown) . '</error>');
				$output->writeln('Known providers: ' . implode(', ', $known));
				return 1;
			}
		}

		$scopeText = $scope === null ? '' : ' of ' . implode(', ', $scope);

		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');
		if (!$input->getOption('force')) {
			$question = new ConfirmationQuestion(
				'All the indexed documents in Context Chat'
				. $scopeText
				. ' will be deleted. The original documents are not touched. Continue? [y/N] ',
				false,
			);
			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('<comment>Aborted, nothing was deleted. Use --force to skip the confirmation.</comment>');
				return 1;
			}
		}

		try {
			$this->reindexService->wipe($scope);
		} catch (\Throwable $e) {
			$output->writeln('<error>Wipe failed. The command can be safely run again once the problem is fixed.</error>');
			$output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
			$output->writeln('<error>' . $e->getTraceAsString() . '</error>');
			return 1;
		}
		$output->writeln('<info>Deleted all the indexed documents' . $scopeText . ' and cleared the queues.</info>');

		// providers that are not registered anymore cannot be indexed again
		$reindexScope = $scope === null
			? null
			: array_values(array_intersect($scope, $this->reindexService->getRegisteredProviderKeys()));
		if ($reindexScope === []) {
			return 0;
		}

		if ($input->getOption('reindex')) {
			try {
				$this->reindexService->scheduleReindex($reindexScope);
			} catch (\Throwable $e) {
				$output->writeln('<error>Scheduling re-index failed. It can be tried again with context_chat:reindex.</error>');
				$output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
				$output->writeln('<error>' . $e->getTraceAsString() . '</error>');
				return 1;
			}
			$output->writeln('<info>Scheduled the re-index.</info>');
		} elseif ($reindexScope === null) {
			$output->writeln('Run context_chat:reindex to index the documents again.');
		} else {
			$output->writeln('Run context_chat:reindex --provider ' . implode(' --provider ', $reindexScope) . ' to index the documents again.');
		}

		return 0;
	}
}
