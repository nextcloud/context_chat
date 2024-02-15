<?php

declare(strict_types=1);

namespace OC\Files\Cache {
	class CacheQueryBuilder extends \OCP\DB\QueryBuilder\IQueryBuilder {
		public function __construct(\OCP\IDBCOnnection $db, \OC\SystemConfig $config, \Psr\Log\LoggerInterface $logger, \OCP\FilesMetadata\IFilesMetadataManager $filesMetadataManager) {}
		public function selectFileCache(string $alias = null, bool $joinExtendedCache = true):CacheQueryBuilder {}
		public function whereStorageId(int $storageId):CacheQueryBuilder {}
		public function whereFileId(int $fileId):CacheQueryBuilder {}
		public function wherePath(string $path):CacheQueryBuilder {}
		public function whereParent(int $parent):CacheQueryBuilder {}
		public function whereParentInParameter(string $parameter):CacheQueryBuilder {}
	}
}
