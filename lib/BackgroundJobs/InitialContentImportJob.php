<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Public\IContentProvider;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class InitialContentImportJob extends QueuedJob {
	public function __construct(
		private IAppManager $appManager,
		private ProviderConfigService $providerConfig,
		private Logger $logger,
		private IUserManager $userMan,
		ITimeFactory $timeFactory,
		private ?string $userId,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param class-string<IContentProvider> $argument Provider class name
	 * @return void
	 */
	protected function run($argument): void {
		if (!is_string($argument)) {
			return;
		}

		try {
			/** @var IContentProvider */
			$providerObj = Server::get($argument);
		} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
			$this->logger->warning('[InitialContentImportJob] Could not run initial import for content provider', ['exception' => $e]);
			return;
		}

		if (!$this->appManager->isEnabledForUser($providerObj->getAppId())) {
			$this->logger->info('[InitialContentImportJob] App is not enabled for user, skipping content import', ['appId' => $providerObj->getAppId()]);
			return;
		}

		$registeredProviders = $this->providerConfig->getProviders();
		$identifier = ProviderConfigService::getConfigKey($providerObj->getAppId(), $providerObj->getId());
		if (!isset($registeredProviders[$identifier])
			|| $registeredProviders[$identifier]['isInitiated']
		) {
			$this->logger->info('[InitialContentImportJob] Provider has already been initiated, skipping content import', ['provider' => $identifier]);
			return;
		}

		$providerObj->triggerInitialImport();
		$this->providerConfig->updateProvider($providerObj->getAppId(), $providerObj->getId(), $argument, true);
	}
}
