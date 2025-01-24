<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Type\Source;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class ScanService {

	public function __construct(
		private IRootFolder $root,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private LangRopeService $langRopeService,
		private StorageService $storageService,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param string $userId
	 * @param array $mimeTypeFilter
	 * @param string|null $directory
	 * @return \Generator<Source>
	 */
	public function scanUserFiles(string $userId, array $mimeTypeFilter, ?string $directory = null): \Generator {
		if ($directory === null) {
			$userFolder = $this->root->getUserFolder($userId);
		} else {
			$userFolder = $this->root->getUserFolder($userId)->get($directory);
		}

		yield from ($this->scanDirectory($mimeTypeFilter, $userFolder));
		return [];
	}

	/**
	 * @param array $mimeTypeFilter
	 * @param Folder $directory
	 * @return \Generator<Source>
	 */
	public function scanDirectory(array $mimeTypeFilter, Folder $directory): \Generator {
		$maxSize = $this->appConfig->getAppValueInt('indexing_max_size', Application::CC_MAX_SIZE);
		$sources = [];
		$size = 0;

		foreach ($directory->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$nodeSize = $node->getSize();

				if ($nodeSize > $maxSize) {
					$this->logger->warning('[ScanService] File too large to index', [
						'nodeSize' => $nodeSize,
						'maxSize' => $maxSize,
						'nodeId' => $node->getId(),
						'path' => $node->getPath(),
					]);
					continue;
				}

				if ($size + $nodeSize > $maxSize || count($sources) >= Application::CC_MAX_FILES) {
					$this->langRopeService->indexSources($sources);
					$sources = [];
					$size = 0;
				}

				$source = $this->getSourceFromFile($mimeTypeFilter, $node);
				if ($source === null) {
					continue;
				}

				$sources[] = $source;
				$size += $nodeSize;

				yield $source;
				continue;
			}
		}

		if (count($sources) > 0) {
			$this->langRopeService->indexSources($sources);
		}

		foreach ($directory->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				yield from $this->scanDirectory($mimeTypeFilter, $node);
			}
		}

		return [];
	}

	public function getSourceFromFile(array $mimeTypeFilter, File $node): ?Source {
		if (!in_array($node->getMimeType(), $mimeTypeFilter)) {
			return null;
		}

		try {
			$fileHandle = $node->fopen('r');
		} catch (\Exception $e) {
			$this->logger->error('Could not open file ' . $node->getPath() . ' for reading: ' . $e->getMessage());
			return null;
		}

		$providerKey = ProviderConfigService::getDefaultProviderKey();
		$sourceId = ProviderConfigService::getSourceId($node->getId());
		$userIds = $this->storageService->getUsersForFileId($node->getId());
		$path = substr($node->getInternalPath(), 6); // remove 'files/' prefix
		return new Source(
			$userIds,
			$sourceId,
			$path,
			$fileHandle,
			$node->getMTime(),
			$node->getMimeType(),
			$providerKey,
		);
	}
}
