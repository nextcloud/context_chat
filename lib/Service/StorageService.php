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
		$mimeTypes = array_map(fn ($mimeType) => $this->mimeTypes->getId($mimeType), Application::MIMETYPES);
		$qb = $this->getCacheQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('filecache', 'filecache')
			->where($qb->expr()->in('mimetype', $qb->createNamedParameter($mimeTypes, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();
		if ($count === false) {
			return 0;
		}
		return $count;
	}

	/**
	 * @return \Generator<array{root_id: int, override_root: int, storage_id: int}>
	 * @throws \OCP\DB\Exception
	 */
	public function getMounts(): \Generator {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['root_id', 'storage_id', 'mount_provider_class']) // to avoid scanning each occurrence of a groupfolder
			->from('mounts')
			->where($qb->expr()->in('mount_provider_class', $qb->createPositionalParameter(self::ALLOWED_MOUNT_TYPES, IQueryBuilder::PARAM_STR_ARRAY)));
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
					/** @var array|false $root */
					$root = $qb->selectFileCache()
						->andWhere($qb->expr()->eq('filecache.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
						->andWhere($qb->expr()->eq('filecache.path', $qb->createNamedParameter('files')))
						->executeQuery()->fetch();
					if ($root !== false) {
						$overrideRoot = intval($root['fileid']);
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
	 * @param array $models
	 * @param int $lastFileId
	 * @param int $maxResults
	 * @return \Generator<int,int,mixed,void>
	 */
	public function getFilesInMount(int $storageId, int $rootId, int $lastFileId = 0, int $maxResults = 100): \Generator {
		$qb = $this->getCacheQueryBuilder();
		try {
			$result = $qb->selectFileCache()
				->andWhere($qb->expr()->eq('filecache.fileid', $qb->createNamedParameter($rootId, IQueryBuilder::PARAM_INT)))
				->executeQuery();
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

			$qb->selectFileCache()
				->whereStorageId($storageId)
				->andWhere($qb->expr()->like('path', $qb->createNamedParameter($path . '%')))
				->andWhere($qb->expr()->eq('storage', $qb->createNamedParameter($storageId)))
				->andWhere($qb->expr()->gt('filecache.fileid', $qb->createNamedParameter($lastFileId)))
				->andWhere($qb->expr()->in('mimetype', $qb->createNamedParameter($mimeTypes, IQueryBuilder::PARAM_INT_ARRAY)));

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
	 * @return array<File>
	 */
	public function getAllFilesInFolder(Node $node): array {
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
			$files[] = $node;
		}

		return $files;
	}

	private function getCacheQueryBuilder(): CacheQueryBuilder {
		return new CacheQueryBuilder($this->db->getQueryBuilder(), $this->metadataManager);
	}
}
