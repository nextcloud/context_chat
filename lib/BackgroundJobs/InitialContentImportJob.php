<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Public\IContentProvider as OCAIContentProvider;
use OCA\ContextChat\Service\FetchGuard;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\ContextChat\IContentProvider as OCPIContentProvider;
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
		private FetchGuard $fetchGuard,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param class-string<OCAIContentProvider|OCPIContentProvider> $argument Provider class name
	 * @return void
	 */
	protected function run($argument): void {
		if (!is_string($argument)) {
			return;
		}

		try {
			/** @var OCAIContentProvider|OCPIContentProvider */
			$providerObj = Server::get($argument);
		} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
			$this->logger->warning('[InitialContentImportJob] Could not run initial import for content provider', ['exception' => $e]);
			return;
		}

		$user = $this->userMan->get($this->userId ?? '');
		if (!$this->appManager->isEnabledForUser($providerObj->getAppId(), $user)) {
			$this->logger->info('[InitialContentImportJob] App is not enabled for user, skipping content import', ['appId' => $providerObj->getAppId()]);
			return;
		}

		$identifier = ProviderConfigService::getConfigKey($providerObj->getAppId(), $providerObj->getId());
		$running = $this->fetchGuard->jobStarted($identifier, $this->getId(), FetchGuard::IMPORT_RUNNING_TTL);
		try {
			// a wipe of the provider removes this job, a re-index after it adds it again
			if ($this->fetchGuard->isPaused($identifier)) {
				return;
			}

			$registeredProviders = $this->providerConfig->getProviders();
			if (!isset($registeredProviders[$identifier])
				|| $registeredProviders[$identifier]['isInitiated']
			) {
				$this->logger->info('[InitialContentImportJob] Provider has already been initiated, skipping content import', ['provider' => $identifier]);
				return;
			}

			$providerObj->triggerInitialImport();
			$this->providerConfig->updateProvider($providerObj->getAppId(), $providerObj->getId(), $argument, isInitiated: true);
		} finally {
			$this->fetchGuard->jobEnded($running);
		}
	}
}
