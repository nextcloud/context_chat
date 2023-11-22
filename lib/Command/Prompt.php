<?php

/**
 * Nextcloud - Cwyd
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

namespace OCA\Cwyd\Command;

use OCA\Cwyd\TextProcessing\CwydTaskType;
use OCP\TextProcessing\IManager;
use OCP\TextProcessing\Task;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Prompt extends Command {

	public function __construct(
		private IManager $textProcessingManager,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('cwyd:prompt')
			->setDescription('Prompt Chat with your documents')
			->addArgument(
				'user_id',
				InputArgument::REQUIRED,
				'The ID of the user to prompt the documents of'
			)
			->addArgument(
				'prompt',
				InputArgument::REQUIRED,
				'The prompt'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('user_id');
		$prompt = $input->getArgument('prompt');
		$task = new Task(CwydTaskType::class, $prompt, 'cwyd', $userId);

		$this->textProcessingManager->runTask($task);
		$output->writeln($task->getOutput());

		return 0;
	}
}
