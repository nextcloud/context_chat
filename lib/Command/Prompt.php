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

use OCA\ContextChat\TextProcessing\ContextChatTaskType;
use OCP\TextProcessing\FreePromptTaskType;
use OCP\TextProcessing\IManager;
use OCP\TextProcessing\Task;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Prompt extends Command {

	public function __construct(
		private IManager $textProcessingManager,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:prompt')
			->setDescription('Prompt Nextcloud Assistant Context Chat')
			->addArgument(
				'uid',
				InputArgument::REQUIRED,
				'The ID of the user to prompt the documents of'
			)
			->addArgument(
				'prompt',
				InputArgument::REQUIRED,
				'The prompt'
			)
			->addOption('no-context', null, InputOption::VALUE_NONE, 'Do not use context');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('uid');
		$prompt = $input->getArgument('prompt');
		$noContext = $input->getOption('no-context');

		if ($noContext) {
			$task = new Task(FreePromptTaskType::class, $prompt, 'context_chat', $userId);
		} else {
			$task = new Task(ContextChatTaskType::class, $prompt, 'context_chat', $userId);
		}

		$this->textProcessingManager->runTask($task);
		$output->writeln($task->getOutput());

		return 0;
	}
}
