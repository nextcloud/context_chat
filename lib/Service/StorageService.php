<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OC\Files\Cache\CacheQueryBuilder;
use OC\SystemConfig;
use OCA\ContextChat\AppInfo\Application;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class StorageService {
	public const ALLOWED_MOUNT_TYPES = [
		'OC\Files\Mount\LocalHomeMountProvider',
		'OC\Files\Mount\ObjectHomeMountProvider',
		'OCA\Files_External\Config\ConfigAdapter',
		'OCA\GroupFolders\Mount\MountProvider'
	];

	public const HOME_MOUNT_TYPES = [
		'OC\Files\Mount\LocalHomeMountProvider',
		'OC\Files\Mount\ObjectHomeMountProvider',
	];

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
		private SystemConfig $systemConfig,
		private IMimeTypeLoader $mimeTypes,
		private IUserMountCache $userMountCache,
		private IFilesMetadataManager $metadataManager,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * @throws Exception
	 */
	public function countFiles(): int {
		$totalCount = 0;
		foreach ($this->getMounts() as $mount) {
			$totalCount += $this->countFilesInMount($mount['storage_id'], $mount['root_id']);
		}
		return $totalCount;
	}

	/**
	 * @param int $storageId
	 * @param int $rootId
	 * @return int
	 */
	public function countFilesInMount(int $storageId, int $rootId): int {
		$qb = $this->getCacheQueryBuilder();
		try {
			$qb->selectFileCache();
			$qb->andWhere($qb->expr()->eq('filecache.fileid', $qb->createNamedParameter($rootId, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			/** @var array{path:string}|false $root */
			$root = $result->fetch();
			$result->closeCursor();
		} catch (Exception $e) {
			$this->logger->error('Could not fetch storage root', ['exception' => $e]);
			return 0;
		}

		if ($root === false) {
			$this->logger->error('Could not fetch storage root');
			return 0;
		}

		$mimeTypes = array_map(fn ($mimeType) => $this->mimeTypes->getId($mimeType), Application::MIMETYPES);

		$qb = $this->getCacheQueryBuilder();

		try {
			$path = $root['path'] === '' ? '' : $root['path'] . '/';

			$qb->select($qb->func()->count('*'))
				->from('filecache', 'filecache');

			// End to end encrypted files are descendants of a folder with encrypted=1
			// Use a subquery to check the `encrypted` status of the parent folder
			$subQuery = $this->getCacheQueryBuilder()->select('p.encrypted')
				->from('filecache', 'p')
				->andWhere($qb->expr()->eq('p.fileid', 'filecache.parent'))
				->getSQL();

			$qb->andWhere(
				$qb->expr()->eq($qb->createFunction(sprintf('(%s)', $subQuery)), $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			);
			$qb->andWhere($qb->expr()->eq('filecache.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));
			$qb
				->andWhere($qb->expr()->like('filecache.path', $qb->createNamedParameter($path . '%')))
				->andWhere($qb->expr()->eq('filecache.storage', $qb->createNamedParameter($storageId)))
				->andWhere($qb->expr()->in('filecache.mimetype', $qb->createNamedParameter($mimeTypes, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->lte('filecache.size', $qb->createNamedParameter(Application::CC_MAX_SIZE, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('filecache.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
		} catch (Exception $e) {
			$this->logger->error('Could not count files in mount: storage=' . $storageId . ' root=' . $rootId, ['exception' => $e]);
			return 0;
		}

		$countInMount = $result->fetchOne();
		$result->closeCursor();
		if ($countInMount === false) {
			$this->logger->warning('Could not count files in mount: storage=' . $storageId . ' root=' . $rootId);
			return 0;
		}
		return $countInMount;
	}

	/**
	 * @return \Generator<array{root_id: int, override_root: int, storage_id: int}>
	 * @throws \OCP\DB\Exception
	 */
	public function getMounts(): \Generator {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['root_id', 'storage_id', 'mount_provider_class']) // to avoid scanning each occurrence of a groupfolder
			->from('mounts')
			->where($qb->expr()->in('mount_provider_class', $qb->createPositionalParameter(self::ALLOWED_MOUNT_TYPES, IQueryBuilder::PARAM_STR_ARRAY)))
			// Exclude groupfolder trashbin mounts
			->andWhere($qb->expr()->notLike('mount_point', $qb->createPositionalParameter('/%/files_trashbin/%')));
		$result = $qb->executeQuery();


		while (
			/** @var array{storage_id:int, root_id:int,mount_provider_class:string} $row */
			$row = $result->fetch()
		) {
			$storageId = (int)$row['storage_id'];
			$rootId = (int)$row['root_id'];
			$overrideRoot = $rootId;
			if (in_array($row['mount_provider_class'], self::HOME_MOUNT_TYPES)) {
				// Only crawl files, not cache or trashbin
				$qb = $this->getCacheQueryBuilder();
				try {
					$qb->selectFileCache();
					/** @var array|false $root */
					$root = $qb
						->andWhere($qb->expr()->eq('filecache.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
						->andWhere($qb->expr()->eq('filecache.name', $qb->createNamedParameter('files')))
						->andWhere($qb->expr()->eq('filecache.parent', $qb->createNamedParameter($rootId, IQueryBuilder::PARAM_INT)))
						->executeQuery()->fetch();
					if ($root !== false) {
						$overrideRoot = (int)$root['fileid'];
					}
				} catch (Exception $e) {
					$this->logger->error('Could not fetch home storage files root for storage ' . $storageId, ['exception' => $e]);
					continue;
				}
			}
			yield [
				'storage_id' => $storageId,
				'root_id' => $rootId,
				'override_root' => $overrideRoot,
			];
		}
		$result->closeCursor();
	}

	/**
	 * @param int $storageId
	 * @param int $rootId
	 * @param int $lastFileId
	 * @param int $maxResults
	 * @return \Generator<int,int,mixed,void>
	 */
	public function getFilesInMount(int $storageId, int $rootId, int $lastFileId = 0, int $maxResults = 100): \Generator {
		$qb = $this->getCacheQueryBuilder();
		try {
			$qb->selectFileCache();
			$qb->andWhere($qb->expr()->eq('filecache.fileid', $qb->createNamedParameter($rootId, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			/** @var array{path:string}|false $root */
			$root = $result->fetch();
			$result->closeCursor();
		} catch (Exception $e) {
			$this->logger->error('Could not fetch storage root', ['exception' => $e]);
			return;
		}

		if ($root === false) {
			$this->logger->error('Could not fetch storage root');
			return;
		}

		$mimeTypes = array_map(fn ($mimeType) => $this->mimeTypes->getId($mimeType), Application::MIMETYPES);

		$qb = $this->getCacheQueryBuilder();

		try {
			$path = $root['path'] === '' ? '' : $root['path'] . '/';

			$qb->select('*')
				->from('filecache', 'filecache');
			// End to end encrypted files are descendants of a folder with encrypted=1
			// Use a subquery to check the `encrypted` status of the parent folder
			$subQuery = $this->getCacheQueryBuilder()->select('p.encrypted')
				->from('filecache', 'p')
				->andWhere($qb->expr()->eq('p.fileid', 'filecache.parent'))
				->getSQL();

			$qb->andWhere(
				$qb->expr()->eq($qb->createFunction(sprintf('(%s)', $subQuery)), $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			);
			$qb
				->andWhere($qb->expr()->like('filecache.path', $qb->createNamedParameter($path . '%')))
				->andWhere($qb->expr()->eq('filecache.storage', $qb->createNamedParameter($storageId)))
				->andWhere($qb->expr()->gt('filecache.fileid', $qb->createNamedParameter($lastFileId)))
				->andWhere($qb->expr()->in('filecache.mimetype', $qb->createNamedParameter($mimeTypes, IQueryBuilder::PARAM_INT_ARRAY)));

			if ($maxResults !== 0) {
				$qb->setMaxResults($maxResults);
			}
			$files = $qb->orderBy('filecache.fileid', 'ASC')
				->executeQuery();
		} catch (Exception $e) {
			$this->logger->error('Could not fetch files', ['exception' => $e]);
			return;
		}

		while (
			/** @var array */
			$file = $files->fetch()
		) {
			yield (int)$file['fileid'];
		}

		$files->closeCursor();
	}

	/**
	 * @param int $fileId
	 * @return string[]
	 */
	public function getUsersForFileId(int $fileId): array {
		$mountInfos = $this->userMountCache->getMountsForFileId($fileId);
		return array_map(static function (ICachedMountInfo $mountInfo) {
			return $mountInfo->getUser()->getUID();
		}, $mountInfos);
	}

	/**
	 * @param Node $node
	 * @return \Generator
	 */
	public function getAllFilesInFolder(Node $node): \Generator {
		if (!$node instanceof Folder) {
			return [];
		}
		$mount = $node->getMountPoint();
		if ($mount->getNumericStorageId() === null) {
			return [];
		}
		$filesGen = $this->getFilesInMount($mount->getNumericStorageId(), $node->getId(), 0, 0);
		$files = [];

		foreach ($filesGen as $fileId) {
			$node = current($this->rootFolder->getById($fileId));
			if (!$node instanceof File) {
				continue;
			}
			yield $node;
		}
	}

	private function getCacheQueryBuilder(): CacheQueryBuilder {
		return new CacheQueryBuilder($this->db->getQueryBuilder(), $this->metadataManager);
	}
}
