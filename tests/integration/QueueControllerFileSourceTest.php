<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests;

use OCA\ContextChat\Controller\QueueController;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Type\Source;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for QueueController::getFileSource() on storages with more than one mount.
 *
 * Layout (see https://github.com/nextcloud/context_chat/issues/281):
 *   owner's home storage has 3 rows in oc_mounts: the owner's home mount and two share mounts of
 *   the recipient (shared1/, shared2/). The rows are reordered so the share mounts come first,
 *   which made the old `$mounts[0]` logic pick the recipient and drop every file outside the shares.
 *
 * The tests go through the public getDocumentsQueueItems() endpoint because that is where a failed
 * lookup results in a silently deleted row.
 */
#[Group('DB')]
class QueueControllerFileSourceTest extends TestCase {
	private const FILES = [
		'private/private-doc.txt',
		'top-level.md',
		'shared1/shared1-doc.txt',
		'shared1/sub/nested.txt',
		'shared2/shared2-doc.txt',
	];

	private IDBConnection $db;
	private IRootFolder $rootFolder;
	private QueueMapper $queueMapper;
	private string $owner;
	private string $recipient;
	private int $storageId;
	/** @var array<string, File> relative path => node */
	private array $files = [];
	/** @var list<string> */
	private array $createdUsers = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// the share mounts are provided by files_sharing; tests/phpunit.xml sets TEST_DONT_LOAD_APPS
		// so that disabled apps (e.g. files_external without its tables) do not break filesystem setup
		Server::get(IAppManager::class)->loadApp('files_sharing');
	}

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->rootFolder = Server::get(IRootFolder::class);
		$this->queueMapper = Server::get(QueueMapper::class);

		$suffix = substr(md5(uniqid('', true)), 0, 8);
		$this->owner = $this->createUser('cc_owner_' . $suffix);
		$this->recipient = $this->createUser('cc_recipient_' . $suffix);

		$ownerFolder = $this->rootFolder->getUserFolder($this->owner);
		foreach (self::FILES as $path) {
			$this->files[$path] = $this->newFile($ownerFolder, $path);
		}
		$this->storageId = $ownerFolder->getStorage()->getCache()->getNumericStorageId();

		$this->shareWithRecipient($ownerFolder->get('shared1'));
		$this->shareWithRecipient($ownerFolder->get('shared2'));
		// set up the recipient's filesystem so its share mounts are registered in oc_mounts
		$this->rootFolder->getUserFolder($this->recipient)->getDirectoryListing();

		// mirror #281: owner's home mount is the LAST row for this storage
		$this->moveMountsToEnd([$this->owner]);
		$this->assertSame(
			[$this->recipient, $this->recipient, $this->owner],
			$this->mountUsersInDbOrder(),
			'Fixture: share mounts must come before the owner\'s home mount',
		);

		// context_chat's own listeners queue the files created above; start every test from an empty queue
		$this->queueMapper->clearQueue();
	}

	protected function tearDown(): void {
		$this->queueMapper->clearQueue();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('mounts')->where($qb->expr()->eq('user_id', $qb->createNamedParameter('cc_ghost_user')))->executeStatement();
		$userManager = Server::get(IUserManager::class);
		foreach ($this->createdUsers as $uid) {
			$userManager->get($uid)?->delete();
		}
		parent::tearDown();
	}

	/**
	 * The actual #281 bug: files outside the shared folders were dropped because the lookup was
	 * done as the share recipient.
	 */
	public function testAllFilesOfMultiMountStorageAreHandedToBackend(): void {
		$queued = $this->enqueue(array_keys($this->files));

		$sources = $this->fetchQueueItems();

		foreach ($queued as $path => $queueId) {
			$this->assertArrayHasKey($queueId, $sources, "File '$path' was not handed to the backend");
			$this->assertSame(ProviderConfigService::getSourceId($this->files[$path]->getId()), $sources[$queueId]->reference, "Wrong node resolved for '$path'");
			// the title is the internal path of whichever mount resolved the file; for a share mount it
			// is relative to the share root, so only the basename is stable
			$this->assertSame(basename($path), basename($sources[$queueId]->title));
		}
		$this->assertCount(count($queued), $sources);
		$this->assertSame(count($queued), $this->queueMapper->countLocked(), 'Every queue row must still exist and be locked (handed out), not deleted');
	}

	/**
	 * The access list must contain exactly the users that have a mount covering the file,
	 * i.e. what StorageService::getUsersForFileId() used to return, without duplicates.
	 */
	public function testAccessListContainsExactlyUsersWhoCanSeeTheFile(): void {
		// userB gets a second mount covering shared1/sub/ -> two recipient mounts cover nested.txt
		$this->shareWithRecipient($this->rootFolder->getUserFolder($this->owner)->get('shared1/sub'));
		$this->rootFolder->getUserFolder($this->recipient)->getDirectoryListing();
		$this->moveMountsToEnd([$this->owner]);
		$this->queueMapper->clearQueue();

		$queued = $this->enqueue(array_keys($this->files));
		$sources = $this->fetchQueueItems();

		$expected = [
			'private/private-doc.txt' => [$this->owner],
			'top-level.md' => [$this->owner],
			'shared1/shared1-doc.txt' => [$this->owner, $this->recipient],
			'shared1/sub/nested.txt' => [$this->owner, $this->recipient],
			'shared2/shared2-doc.txt' => [$this->owner, $this->recipient],
		];
		foreach ($expected as $path => $users) {
			$this->assertArrayHasKey($queued[$path], $sources, "File '$path' was not handed to the backend");
			$actual = $sources[$queued[$path]]->userIds;
			$this->assertEqualsCanonicalizing($users, $actual, "Wrong access list for '$path'");
			$this->assertSame(array_values(array_unique($actual)), $actual, "Duplicate users in access list for '$path'");
		}
	}

	/**
	 * A leftover mount of a user that no longer exists must not break resolution for everybody else.
	 */
	public function testStaleMountOfDeletedUserIsSkipped(): void {
		$ownerHome = $this->rootFolder->getUserFolder($this->owner)->getMountPoint()->getStorageRootId();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('mounts')->values([
			'storage_id' => $qb->createNamedParameter($this->storageId),
			'root_id' => $qb->createNamedParameter($ownerHome),
			'user_id' => $qb->createNamedParameter('cc_ghost_user'),
			'mount_point' => $qb->createNamedParameter('/cc_ghost_user/'),
			'mount_point_hash' => $qb->createNamedParameter(md5('/cc_ghost_user/')),
			'mount_provider_class' => $qb->createNamedParameter('OC\Files\Mount\LocalHomeMountProvider'),
		])->executeStatement();
		$this->moveMountsToEnd([$this->recipient, $this->owner]);
		$this->assertSame('cc_ghost_user', $this->mountUsersInDbOrder()[0], 'Fixture: ghost mount must come first');

		$queued = $this->enqueue(array_keys($this->files));
		$sources = $this->fetchQueueItems();

		foreach ($queued as $path => $queueId) {
			$this->assertArrayHasKey($queueId, $sources, "File '$path' was not handed to the backend");
			$this->assertNotContains('cc_ghost_user', $sources[$queueId]->userIds);
		}
	}

	/**
	 * Unchanged behaviour: a queue item whose file is gone is dropped from the queue, the rest still works.
	 */
	public function testQueueItemForMissingFileIsDropped(): void {
		$queued = $this->enqueue(['top-level.md']);
		$missing = new QueueFile();
		$missing->setFileId(PHP_INT_MAX >> 16);
		$missing->setStorageId($this->storageId);
		$missing->setRootId($this->files['top-level.md']->getParent()->getId());
		$missing->setUpdate(false);
		$missingId = $this->queueMapper->insertIntoQueue($missing)->getId();

		$sources = $this->fetchQueueItems();

		$this->assertArrayHasKey($queued['top-level.md'], $sources);
		$this->assertArrayNotHasKey($missingId, $sources);
		$this->assertNull($this->queueMapper->findQueueItemByFileId(PHP_INT_MAX >> 16), 'Missing file must be removed from the queue');
	}

	// ---------------------------------------------------------------- helpers

	private function createUser(string $uid): string {
		Server::get(IUserManager::class)->createUser($uid, 'Pass-' . $uid . '-1234567890');
		$this->createdUsers[] = $uid;
		return $uid;
	}

	private function newFile(Folder $userFolder, string $path): File {
		$parent = $userFolder;
		foreach (array_slice(explode('/', $path), 0, -1) as $dir) {
			$parent = $parent->nodeExists($dir) ? $parent->get($dir) : $parent->newFolder($dir);
		}
		return $parent->newFile(basename($path), "content of $path\n");
	}

	private function shareWithRecipient(Folder $folder): void {
		$shareManager = Server::get(IShareManager::class);
		$share = $shareManager->newShare()
			->setNode($folder)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith($this->recipient)
			->setSharedBy($this->owner)
			->setShareOwner($this->owner)
			->setPermissions(\OCP\Constants::PERMISSION_ALL);
		$shareManager->acceptShare($shareManager->createShare($share), $this->recipient);
	}

	/**
	 * Re-inserts the oc_mounts rows of the given users for the owner's storage, so they get the
	 * highest ids. getMountsForStorageId() has no ORDER BY, so this controls the order it returns.
	 *
	 * @param list<string> $userIds
	 */
	private function moveMountsToEnd(array $userIds): void {
		foreach ($userIds as $userId) {
			$qb = $this->db->getQueryBuilder();
			$result = $qb->select('*')->from('mounts')
				->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($this->storageId)))
				->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->orderBy('id')
				->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			foreach ($rows as $row) {
				$delete = $this->db->getQueryBuilder();
				$delete->delete('mounts')->where($delete->expr()->eq('id', $delete->createNamedParameter($row['id'])))->executeStatement();
				unset($row['id']);
				$insert = $this->db->getQueryBuilder();
				$insert->insert('mounts');
				foreach ($row as $column => $value) {
					$insert->setValue($column, $insert->createNamedParameter($value));
				}
				$insert->executeStatement();
			}
		}
	}

	/** @return list<string> */
	private function mountUsersInDbOrder(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('user_id')->from('mounts')
			->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($this->storageId)))
			->orderBy('id')
			->executeQuery();
		$users = [];
		while (($user = $result->fetchOne()) !== false) {
			$users[] = (string)$user;
		}
		$result->closeCursor();
		return $users;
	}

	/**
	 * @param list<string> $paths
	 * @return array<string, int> path => queue row id
	 */
	private function enqueue(array $paths): array {
		$ids = [];
		foreach ($paths as $path) {
			$item = new QueueFile();
			$item->setFileId($this->files[$path]->getId());
			$item->setStorageId($this->storageId);
			$item->setRootId($this->files[$path]->getParent()->getId());
			$item->setUpdate(false);
			$ids[$path] = $this->queueMapper->insertIntoQueue($item)->getId();
		}
		return $ids;
	}

	/**
	 * Calls the endpoint the backend polls. Method arguments are resolved like the AppFramework
	 * dispatcher does (by type from the DI container), so the test does not depend on which
	 * services the method happens to declare.
	 *
	 * @return array<int, Source> queue row id => source
	 */
	private function fetchQueueItems(): array {
		$controller = Server::get(QueueController::class);
		$method = new \ReflectionMethod($controller, 'getDocumentsQueueItems');
		$args = [];
		foreach ($method->getParameters() as $parameter) {
			$type = $parameter->getType();
			$args[] = ($type instanceof \ReflectionNamedType && !$type->isBuiltin())
				? Server::get($type->getName())
				: 1024;
		}
		$response = $method->invokeArgs($controller, $args);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		return (array)$response->getData()['files'];
	}
}
