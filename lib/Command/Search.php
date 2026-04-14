<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Type\ScopeType;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Search extends Command {
	private const SEARCH_TASK_TYPE_ID = Application::APP_ID . ':context_chat_search';

	public function __construct(
		private IManager $taskProcessingManager,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:search')
			->setDescription('Search with Nextcloud Assistant Context Chat')
			->addArgument(
				'uid',
				InputArgument::REQUIRED,
				'The ID of the user to search the documents of'
			)
			->addArgument(
				'prompt',
				InputArgument::REQUIRED,
				'The prompt'
			)
			->addOption(
				'context-providers',
				null,
				InputOption::VALUE_REQUIRED,
				'Context providers to use (as a comma-separated list without brackets)',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('uid');
		$prompt = $input->getArgument('prompt');
		$contextProviders = $input->getOption('context-providers');

		if (!empty($contextProviders)) {
			$contextProviders = preg_replace('/\s*,+\s*/', ',', $contextProviders);
			if ($contextProviders === null) {
				$output->writeln('<error>Regex comma de-duplication returned null</error>');
				return 1;
			}
			if (is_array($contextProviders)) {
				$contextProviders = $contextProviders[0];
			}

			$contextProvidersArray = array_values(array_filter(explode(',', $contextProviders), fn ($source) => !empty($source)));
			$task = new Task(self::SEARCH_TASK_TYPE_ID, [
				'prompt' => $prompt,
				'scopeType' => ScopeType::PROVIDER,
				'scopeList' => $contextProvidersArray,
				'scopeListMeta' => '',
			], Application::APP_ID, $userId);
		} else {
			$task = new Task(self::SEARCH_TASK_TYPE_ID, [
				'prompt' => $prompt,
				'scopeType' => ScopeType::NONE,
				'scopeList' => [],
				'scopeListMeta' => '',
			], Application::APP_ID, $userId);
		}

		$this->taskProcessingManager->scheduleTask($task);
		$taskId = $task->getId();
		if ($taskId === null) {
			$output->writeln('<error>Task schedule failed, taskId is null</error>');
			return 1;
		}

		while (!in_array(($task = $this->taskProcessingManager->getTask($taskId))->getStatus(), [Task::STATUS_FAILED, Task::STATUS_SUCCESSFUL], true)) {
			sleep(2);
		}
		if ($task->getStatus() === Task::STATUS_SUCCESSFUL) {
			$output->writeln(var_export($task->getOutput(), true));
			return 0;
		} else {
			$output->writeln('<error>' . ($task->getErrorMessage() ?? '(empty error message)') . '</error>');
			return 1;
		}
	}
}
