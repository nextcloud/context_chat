<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests;

use OC\Files\Cache\Scanner;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\Files_External\Lib\Auth\NullMechanism;
use OCA\Files_External\Lib\Backend\Local;
use OCA\Files_External\Lib\StorageConfig;
use OCA\Files_External\Service\GlobalStoragesService;
use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\FolderStorageManager;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The layout of the paths in cache events is different on every kind of storage: "trash/" is
 * internal to a groupfolder but a user's folder on an external storage, "files_versions/" is
 * internal to the home storage and a user's folder everywhere else.
 *
 * This runs the real scanner and cache of the real storages of the server, the groupfolders app
 * and the files_external app, so that the test fails as soon as one of them changes the paths,
 * the storage ids or the events it fires, which the filtering of the FileListener relies on.
 * The mocked storages are in tests/unit/Listener/FileListenerTest.php.
 */
#[Group('DB')]
class FileListenerStoragesTest extends TestCase {
	private const USER = 'cc-file-listener-test';
	private const GROUP = 'cc-file-listener-test';

	private IDBConnection $db;
	private IRootFolder $rootFolder;
	private IUser $user;
	private ?int $groupFolderId = null;
	private ?int $externalStorageId = null;
	private ?string $externalDir = null;
	/** @var list<int> */
	private array $fileIds = [];

	protected function setUp(): void {
		parent::setUp();
		$appManager = Server::get(IAppManager::class);
		foreach (['groupfolders', 'files_external'] as $app) {
			if (!$appManager->isEnabledForUser($app)) {
				// the workflow that tests the storages sets this, the generic phpunit ones do not have the apps
				if (getenv('CONTEXT_CHAT_REQUIRE_STORAGE_APPS')) {
					$this->fail("The $app app is not enabled");
				}
				$this->markTestSkipped("The $app app is not enabled");
			}
		}
		$this->db = Server::get(IDBConnection::class);
		$this->rootFolder = Server::get(IRootFolder::class);

		$userManager = Server::get(IUserManager::class);
		$this->user = $userManager->get(self::USER) ?? $userManager->createUser(self::USER, 'cc-file-listener-test-pass-1234');
		$groupManager = Server::get(IGroupManager::class);
		$group = $groupManager->get(self::GROUP) ?? $groupManager->createGroup(self::GROUP);
		$group->addUser($this->user);
	}

	protected function tearDown(): void {
		if ($this->groupFolderId !== null) {
			$folderManager = Server::get(FolderManager::class);
			$folder = $folderManager->getFolder($this->groupFolderId);
			if ($folder !== null) {
				Server::get(FolderStorageManager::class)->deleteStoragesForFolder($folder);
			}
			$folderManager->removeFolder($this->groupFolderId);
		}
		if ($this->externalStorageId !== null) {
			Server::get(GlobalStoragesService::class)->removeStorage($this->externalStorageId);
		}
		if ($this->externalDir !== null) {
			exec('rm -rf ' . escapeshellarg($this->externalDir));
		}
		if ($this->fileIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('context_chat_queue')
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($this->fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$this->db->getQueryBuilder()->delete('context_chat_action_queue')->executeStatement();
		Server::get(IGroupManager::class)->get(self::GROUP)?->delete();
		$this->user->delete();
		parent::tearDown();
	}

	private function createGroupFolder(): int {
		$folderManager = Server::get(FolderManager::class);
		$this->groupFolderId = $folderManager->createFolder('cc-file-listener-test');
		$folderManager->addApplicableGroup($this->groupFolderId, self::GROUP);
		$this->setUpUserFilesystem();
		return $this->groupFolderId;
	}

	/**
	 * @param array<string, string> $files content of the files by path inside the storage
	 */
	private function createExternalStorage(array $files): IStorage {
		$this->externalDir = sys_get_temp_dir() . '/cc-file-listener-test-' . bin2hex(random_bytes(4));
		foreach ($files as $path => $content) {
			@mkdir(dirname($this->externalDir . '/' . $path), 0777, true);
			file_put_contents($this->externalDir . '/' . $path, $content);
		}

		$config = new StorageConfig();
		$config->setMountPoint('cc-file-listener-test-external');
		$config->setBackend(Server::get(Local::class));
		$config->setAuthMechanism(Server::get(NullMechanism::class));
		$config->setBackendOptions(['datadir' => $this->externalDir]);
		$config->setApplicableUsers([self::USER]);
		$this->externalStorageId = Server::get(GlobalStoragesService::class)->addStorage($config)->getId();

		$this->setUpUserFilesystem();
		$storage = $this->rootFolder->getMount('/' . self::USER . '/files/cc-file-listener-test-external')->getStorage();
		$this->assertNotNull($storage);
		return $storage;
	}

	private function setUpUserFilesystem(): void {
		\OC_Util::tearDownFS();
		\OC_Util::setupFS(self::USER);
	}

	/**
	 * Scans the paths of a storage, firing the cache events of the new entries like
	 * "occ files:scan" and "occ groupfolders:scan" do.
	 *
	 * @param list<string> $paths
	 * @return array<string, int> file ids by path
	 */
	private function scan(IStorage $storage, array $paths): array {
		foreach ($paths as $path) {
			$storage->getScanner()->scan($path, Scanner::SCAN_RECURSIVE);
		}
		$ids = [];
		foreach ($paths as $path) {
			$id = $storage->getCache()->getId($path);
			$this->assertGreaterThan(-1, $id, "$path is not in the cache");
			$ids[$path] = $id;
		}
		$this->fileIds = array_merge($this->fileIds, array_values($ids));
		return $ids;
	}

	private function isQueued(int $fileId): bool {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('id')->from('context_chat_queue')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeQuery();
		$queued = $result->fetchOne() !== false;
		$result->closeCursor();
		return $queued;
	}

	private function isScheduledForDeletion(int $fileId): bool {
		$sourceId = ProviderConfigService::getSourceId($fileId);
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('payload')->from('context_chat_action_queue')
			->where($qb->expr()->eq('type', $qb->createNamedParameter('delete_source_ids')))
			->executeQuery();
		$found = false;
		while ($payload = $result->fetchOne()) {
			if (in_array($sourceId, json_decode($payload, true)['sourceIds'] ?? [], true)) {
				$found = true;
				break;
			}
		}
		$result->closeCursor();
		return $found;
	}

	public function testGroupFolderTrashAndVersionsAreNotQueued(): void {
		$folderId = $this->createGroupFolder();
		// the base storages of the groupfolder, like groupfolders:scan gets them
		$folderStorageManager = Server::get(FolderStorageManager::class);
		$base = $folderStorageManager->getBaseStorageForFolder($folderId, true, type: '');
		foreach (['files/docs', 'trash', 'versions/4242'] as $dir) {
			$base->mkdir($dir);
		}
		$base->file_put_contents('files/docs/readme.md', 'readme');
		$base->file_put_contents('trash/trashed.md.d1700000000', 'trashed');
		$base->file_put_contents('versions/4242/1700000000.md', 'version');

		$ids = $this->scan($base, ['files/docs/readme.md', 'trash/trashed.md.d1700000000', 'versions/4242/1700000000.md']);

		$this->assertTrue($this->isQueued($ids['files/docs/readme.md']), 'a file of the groupfolder is queued');
		$this->assertFalse($this->isQueued($ids['trash/trashed.md.d1700000000']), 'the groupfolder trashbin is not queued');
		$this->assertFalse($this->isQueued($ids['versions/4242/1700000000.md']), 'the groupfolder versions are not queued');
	}

	public function testGroupFolderTrashAndVersionsRemovalIsNotScheduled(): void {
		$folderId = $this->createGroupFolder();
		$base = Server::get(FolderStorageManager::class)->getBaseStorageForFolder($folderId, true, type: '');
		foreach (['files/docs', 'trash', 'versions/4242'] as $dir) {
			$base->mkdir($dir);
		}
		$base->file_put_contents('files/docs/readme.md', 'readme');
		$base->file_put_contents('trash/trashed.md.d1700000000', 'trashed');
		$base->file_put_contents('versions/4242/1700000000.md', 'version');
		$ids = $this->scan($base, ['files/docs/readme.md', 'trash/trashed.md.d1700000000', 'versions/4242/1700000000.md']);

		$cache = $base->getCache();
		foreach (array_keys($ids) as $path) {
			$cache->remove($path);
		}

		$this->assertTrue($this->isScheduledForDeletion($ids['files/docs/readme.md']), 'the removal of a file of the groupfolder is scheduled');
		$this->assertFalse($this->isScheduledForDeletion($ids['trash/trashed.md.d1700000000']), 'the removal from the groupfolder trashbin is not scheduled');
		$this->assertFalse($this->isScheduledForDeletion($ids['versions/4242/1700000000.md']), 'the removal of groupfolder versions is not scheduled');
	}

	public function testExternalStorageFoldersNamedLikeInternalOnesAreIndexed(): void {
		$storage = $this->createExternalStorage([
			'readme.md' => 'readme',
			'trash/readme.md' => 'trash',
			'versions/readme.md' => 'versions',
			'files_versions/readme.md' => 'files_versions',
			'files_trashbin/readme.md' => 'files_trashbin',
		]);

		$ids = $this->scan($storage, ['readme.md', 'trash/readme.md', 'versions/readme.md', 'files_versions/readme.md', 'files_trashbin/readme.md']);

		foreach ($ids as $path => $id) {
			$this->assertTrue($this->isQueued($id), "$path of the external storage is queued");
		}
	}

	public function testExternalStorageFoldersNamedLikeInternalOnesAreRemovedFromTheIndex(): void {
		$storage = $this->createExternalStorage([
			'trash/readme.md' => 'trash',
			'versions/readme.md' => 'versions',
			'files_versions/readme.md' => 'files_versions',
		]);
		$ids = $this->scan($storage, ['trash/readme.md', 'versions/readme.md', 'files_versions/readme.md']);

		// a file removed on the storage is removed from the cache by the next scan
		foreach (array_keys($ids) as $path) {
			unlink($this->externalDir . '/' . $path);
		}
		$storage->getScanner()->scan('', Scanner::SCAN_RECURSIVE);

		foreach ($ids as $path => $id) {
			$this->assertTrue($this->isScheduledForDeletion($id), "the removal of $path from the external storage is scheduled");
		}
	}

	public function testHomeStorageInternalFoldersAreNotQueued(): void {
		$this->setUpUserFilesystem();
		$storage = $this->rootFolder->getMount('/' . self::USER . '/files')->getStorage();
		$this->assertNotNull($storage);
		foreach (['files/docs', 'files_versions/docs', 'files_trashbin/files', 'uploads'] as $dir) {
			$storage->mkdir($dir);
		}
		$storage->file_put_contents('files/docs/readme.md', 'readme');
		$storage->file_put_contents('files_versions/docs/readme.md.v1700000000', 'version');
		$storage->file_put_contents('files_trashbin/files/trashed.md.d1700000000', 'trashed');
		$storage->file_put_contents('uploads/upload.md', 'upload');

		$ids = $this->scan($storage, ['files/docs/readme.md', 'files_versions/docs/readme.md.v1700000000', 'files_trashbin/files/trashed.md.d1700000000', 'uploads/upload.md']);

		$this->assertTrue($this->isQueued($ids['files/docs/readme.md']), 'a file of the user is queued');
		$this->assertFalse($this->isQueued($ids['files_versions/docs/readme.md.v1700000000']), 'the versions of the user are not queued');
		$this->assertFalse($this->isQueued($ids['files_trashbin/files/trashed.md.d1700000000']), 'the trashbin of the user is not queued');
		$this->assertFalse($this->isQueued($ids['uploads/upload.md']), 'the uploads of the user are not queued');
	}

	public function testAppDataOfTheRootStorageIsNotQueued(): void {
		$rootStorage = $this->rootFolder->getMount('/')->getStorage();
		$this->assertNotNull($rootStorage);
		$appData = $this->rootFolder->getAppDataDirectoryName();
		$path = $appData . '/cc-file-listener-test/readme.md';
		$rootStorage->mkdir($appData . '/cc-file-listener-test');
		$rootStorage->file_put_contents($path, 'readme');

		try {
			$ids = $this->scan($rootStorage, [$path]);

			$this->assertFalse($this->isQueued($ids[$path]), 'the app data is not queued');
		} finally {
			$rootStorage->getCache()->remove($appData . '/cc-file-listener-test');
			$rootStorage->rmdir($appData . '/cc-file-listener-test');
		}
	}
}
