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
use OCA\ContextChat\Public\IContentProvider;
use OCP\App\IAppManager;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

class MetadataService {
	public function __construct(
		private LoggerInterface $logger,
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
		preg_match('/^[^_: ]+__[^_: ]+: (\d+)$/', $sourceId, $matches);
		if (count($matches) !== 2) {
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
			throw new \InvalidArgumentException("Invalid node id $id");
		}

		$node = $nodes[0];
		return [
			'id' => $sourceId,
			'label' => $node->getName(),
			'icon' => $node->getType() == FileInfo::TYPE_FOLDER
				// ? $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('/apps/theming/img/core/filetypes/folder.svg')
				? $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'folder.svg'))
				: $this->urlGenerator->linkToRouteAbsolute('assistant.preview.getFileImage', ['id' => $id, 'x' => 24, 'y' => 24]),
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

			$providerKey = explode('__', $source, 2)[0];
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
				$url = $klass->getItemUrl($this->getIdFromSource($source));
				$provider['url'] = $url;
				$enrichedSources[] = $provider;
			} catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
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