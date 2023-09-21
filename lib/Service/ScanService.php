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

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;

class ScanService {

	public function __construct(
		private IRootFolder $root,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private LangRopeService $langRopeService,
		IClientService $clientService
	) {
	}

	public function scanUserFiles(string $userId, ?string $mimeTypeFilter): \Generator {
		$userFolder = $this->root->getUserFolder($userId);
		foreach ($this->scanDirectory($userId, $userFolder) as $fileName) {
			yield $fileName;
		}
		return [];
	}

	public function scanDirectory(string $userId, Folder $directory): \Generator {
		foreach ($directory->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$this->scanFile($userId, $node);
				yield $node->getPath();
			}
		}
		foreach ($directory->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				foreach ($this->scanDirectory($userId, $node) as $fileName) {
					yield $fileName;
				}
			}
		}
		return [];
	}

	public function scanFile(string $userId, File $file): void {
		$this->langRopeService->indexFile($userId, $file);
	}
}
