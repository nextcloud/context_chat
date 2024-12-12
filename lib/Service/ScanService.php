<?php

/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @author Anupam Kumar
 * @copyright Julien Veyssier 2023
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Type\Source;
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
		$sources = [];
		$size = 0;
		foreach ($directory->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$node_size = $node->getSize();

				if ($size + $node_size > Application::CC_MAX_SIZE || count($sources) >= Application::CC_MAX_FILES) {
					$this->langRopeService->indexSources($sources);
					$sources = [];
					$size = 0;
				}

				$source = $this->getSourceFromFile($mimeTypeFilter, $node);
				if ($source === null) {
					continue;
				}

				$sources[] = $source;
				$size += $node_size;

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
