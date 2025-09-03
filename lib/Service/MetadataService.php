<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Public\ContentManager;
use OCA\ContextChat\Public\IContentProvider;
use OCP\App\IAppManager;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class MetadataService {
	public function __construct(
		private Logger $logger,
		private IL10N $l10n,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private ProviderConfigService $providerConfig,
		private IURLGenerator $urlGenerator,
		private ContentManager $contentManager,
		private IRootFolder $rootFolder,
		private ?string $userId,
	) {
	}

	/**
	 * @return array<string, array{ id: string, label: string, icon: string }>
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

			$sanitizedProviders[$providerKey] = [
				'id' => $providerKey,
				'label' => $appName . ' - ' . ucfirst($providerId),
				'icon' => $icon,
			];
		}
		return $sanitizedProviders;
	}

	private function getIdFromSource(string $sourceId): string {
		if (!preg_match('/^[^: ]+__[^: ]+: (\d+)$/', $sourceId, $matches)) {
			throw new \InvalidArgumentException("Invalid source id $sourceId");
		}
		return $matches[1];
	}

	/**
	 * For files
	 * @return array{ id: string, label: string, icon: string, url: string }
	 */
	private function getMetadataObjectForId(string $userId, string $sourceId): array {
		$id = $this->getIdFromSource($sourceId);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById(intval($id));
		if (count($nodes) < 1) {
			// show a deleted file icon instead of failing the entire request
			return [
				'id' => $sourceId,
				'label' => $this->l10n->t('Deleted file'),
				'icon' => $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'filetypes/file.svg')),
				'url' => $this->urlGenerator->linkToRouteAbsolute('files.View.showFile', ['fileid' => $id]),
			];
		}

		$user = $this->userManager->get($userId);
		$assistantEnabled = $this->appManager->isEnabledForUser('assistant', $user);
		$node = $nodes[0];
		return [
			'id' => $sourceId,
			'label' => $node->getName(),
			'icon' => $node->getType() == FileInfo::TYPE_FOLDER
				?  $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'filetypes/folder.svg'))
				: ($assistantEnabled
					?  $this->urlGenerator->linkToRouteAbsolute('assistant.preview.getFileImage', ['id' => $id, 'x' => 24, 'y' => 24])
					: $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'filetypes/file.svg'))
				),
			'url' => $this->urlGenerator->linkToRouteAbsolute('files.View.showFile', ['fileid' => $id]),
		];
	}

	/**
	 * @return list<array{ id: string, label: string, icon: string, url: string }>
	 */
	public function getEnrichedSources(string $userId, string ...$sources): array {
		$enrichedProviders = $this->getEnrichedProviders();
		$enrichedSources = [];

		# for providers
		foreach ($sources as $source) {
			if (str_starts_with($source, ProviderConfigService::getDefaultProviderKey() . ': ')) {
				continue;
			}

			$providerKey = explode(': ', $source, 2)[0];
			if (!array_key_exists($providerKey, $enrichedProviders)) {
				$this->logger->warning('Could not find content provider by key', ['providerKey' => $providerKey, 'enrichedProviders' => $enrichedProviders]);
				continue;
			}

			$provider = $enrichedProviders[$providerKey];
			$providerConfig = $this->providerConfig->getProvider($providerKey);
			if ($providerConfig === null) {
				$this->logger->warning('Could not find provider by key', ['providerKey' => $providerKey]);
				continue;
			}

			try {
				/** @var IContentProvider */
				$klass = Server::get($providerConfig['classString']);
				$itemId = $this->getIdFromSource($source);
				$url = $klass->getItemUrl($itemId);
				$provider['url'] = $url;
				$provider['label'] .= ' #' . $itemId;
				$enrichedSources[] = $provider;
			} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
				$this->logger->warning('Could not find content provider by class name', ['classString' => $providerConfig['classString'], 'exception' => $e]);
				continue;
			}
		}

		# for files
		foreach ($sources as $source) {
			if (!str_starts_with($source, ProviderConfigService::getDefaultProviderKey() . ': ')) {
				continue;
			}
			$enrichedSources[] = $this->getMetadataObjectForId($userId, $source);
		}

		return $enrichedSources;
	}
}
