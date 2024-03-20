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

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Public\IContentProvider;
use OCA\ContextChat\Service\ProviderService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

class InitialContentImportJob extends QueuedJob {
	public function __construct(
		private IAppManager $appManager,
		private ProviderService $providerService,
		private LoggerInterface $logger,
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
		} catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
			$this->logger->warning('Could not run initial import for content provider', ['exception' => $e]);
			return;
		}

		if (!$this->appManager->isEnabledForUser($providerObj->getAppId())) {
			return;
		}

		$registeredProviders = $this->providerService->getProviders();
		$identifier = ProviderService::getConfigKey($providerObj->getAppId(), $providerObj->getId());
		if (!isset($registeredProviders[$identifier])
			|| $registeredProviders[$identifier]['isInitiated']
		) {
			return;
		}

		$providerObj->triggerInitialImport();
		$this->providerService->updateProvider($providerObj->getAppId(), $providerObj->getId(), $argument, true);
	}
}
