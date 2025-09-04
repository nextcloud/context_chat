<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Repair;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\BackgroundJobs\SchedulerJob;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class AppInstallStep implements IRepairStep {

	public function __construct(
		private Logger $logger,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IJobList $jobList,
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

		$this->jobList->add(SchedulerJob::class);
	}
}
