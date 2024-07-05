<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar
 * @copyright Anupam Kumar 2024
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\Public\ContentManager;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ProviderMetadataService {
	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private ProviderConfigService $providerConfig,
		private IURLGenerator $urlGenerator,
		private ContentManager $contentManager,
		private ?string $userId,
	) {
	}

	/**
	 * @return list<array{ id: string, label: string, icon: string }>
	 */
	public function getEnrichedProviders(): array {
		$this->contentManager->collectAllContentProviders();
		$providers = $this->providerConfig->getProviders();
		$sanitizedProviders = [];

		foreach ($providers as $providerKey => $metadata) {
			// providerKey ($appId__$providerId)
			/** @var string[] */
			$providerValues = explode('__', $providerKey, 2);

			if (count($providerValues) !== 2) {
				$this->logger->info("Invalid provider key $providerKey, skipping");
				continue;
			}

			[$appId, $providerId] = $providerValues;

			$user = $this->userId === null ? null : $this->userManager->get($this->userId);
			if (!$this->appManager->isEnabledForUser($appId, $user)) {
				$this->logger->info("App $appId is not enabled for user {$this->userId}, skipping");
				continue;
			}

			$appInfo = $this->appManager->getAppInfo($appId);
			if ($appInfo === null) {
				$this->logger->info("Could not get app info for $appId, skipping");
				continue;
			}

			try {
				$icon = $this->urlGenerator->imagePath($appId, 'app-dark.svg');
			} catch (\RuntimeException $e) {
				$this->logger->info("Could not get app image for $appId");
				$icon = '';
			}

			$appName = $appInfo['name'] ?? ucfirst($appId);

			$sanitizedProviders[] = [
				'id' => $providerKey,
				'label' => $appName . ' - ' . ucfirst($providerId),
				'icon' => $icon,
			];
		}
		return $sanitizedProviders;
	}
}
