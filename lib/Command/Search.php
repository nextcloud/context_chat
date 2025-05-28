<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\TaskProcessing\ContextChatSearchTaskType;
use OCA\ContextChat\Type\ScopeType;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Search extends Command {

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
			$contextProvidersArray = array_filter(explode(',', $contextProviders), fn ($source) => !empty($source));
			$task = new Task(ContextChatSearchTaskType::ID, [
				'prompt' => $prompt,
				'scopeType' => ScopeType::PROVIDER,
				'scopeList' => $contextProvidersArray,
				'scopeListMeta' => '',
			], 'context_chat', $userId);
		} else {
			$task = new Task(ContextChatSearchTaskType::ID, [
				'prompt' => $prompt,
				'scopeType' => ScopeType::NONE,
				'scopeList' => [],
				'scopeListMeta' => '',
			], 'context_chat', $userId);
		}

		$this->taskProcessingManager->scheduleTask($task);
		while (!in_array(($task = $this->taskProcessingManager->getTask($task->getId()))->getStatus(), [Task::STATUS_FAILED, Task::STATUS_SUCCESSFUL], true)) {
			sleep(1);
		}
		if ($task->getStatus() === Task::STATUS_SUCCESSFUL) {
			$output->writeln(var_export($task->getOutput(), true));
			return 0;
		} else {
			$output->writeln('<error>' . $task->getErrorMessage() . '</error>');
			return 1;
		}
	}
}
