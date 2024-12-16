<?php

/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2024
 */

declare(strict_types=1);

namespace OCA\ContextChat\Repair;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

class AppInstallStep implements IRepairStep {

	public function __construct(
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}

	public function getName(): string {
		return 'Initial setup for Context Chat';
	}

	/**
	 * @param IOutput $output
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueInt(Application::APP_ID, 'installed_time', 0, false) === 0) {
			$this->logger->info('Setting up Context Chat for the first time');
			$this->appConfig->setValueInt(Application::APP_ID, 'installed_time', time(), false);
		}

		// todo: migrate to IAppConfig
		$providerConfigService = new ProviderConfigService($this->config);
		/** @psalm-suppress ArgumentTypeCoercion, UndefinedClass  */
		$providerConfigService->updateProvider('files', 'default', '', true);
	}
}
