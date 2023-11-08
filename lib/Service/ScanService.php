<?php
/**
 * Nextcloud - Cwyd
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2023
 */

namespace OCA\Cwyd\Service;

use OCA\Cwyd\AppInfo\Application;
use OCA\Cwyd\Type\Source;
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
	) {
	}

	public function scanUserFiles(string $userId, array $mimeTypeFilter, ?string $directory = null): \Generator {
		if ($directory === null) {
			$userFolder = $this->root->getUserFolder($userId);
		} else {
			$userFolder = $this->root->getUserFolder($userId)->get($directory);
		}

		yield from ($this->scanDirectory($userId, $mimeTypeFilter, $userFolder));
		return [];
	}

	public function scanDirectory(string $userId, array $mimeTypeFilter, Folder $directory): \Generator {
		$sources = [];
		$size = 0;
		foreach ($directory->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				if (!in_array($node->getMimeType(), $mimeTypeFilter)) {
					continue;
				}

				$node_size = $node->getSize();

				if ($size + $node_size > Application::CWYD_MAX_SIZE || count($sources) >= Application::CWYD_MAX_FILES) {
					$this->indexSources($userId, $sources);
					$sources = [];
					$size = 0;
				}

				try {
					$fileHandle = $node->fopen('r');
				} catch (\Exception $e) {
					$this->logger->error('Could not open file ' . $node->getPath() . ' for reading: ' . $e->getMessage());
					continue;
				}

				$source = new Source(
					$userId,
					'file: ' . $node->getId(),
					$fileHandle,
					$node->getMTime(),
					$node->getMimeType(),
				);
				$sources[] = $source;
				$size += $node_size;

				yield $node->getPath();
				continue;
			}
		}

		if (count($sources) > 0) {
			$this->indexSources($userId, $sources);
		}

		foreach ($directory->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				yield from $this->scanDirectory($userId, $mimeTypeFilter, $node);
			}
		}

		return [];
	}

	public function indexSources(string $userId, array $sources): void {
		$this->langRopeService->indexSources($userId, $sources);
	}
}
