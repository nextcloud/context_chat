<?php
/*
 * Copyright (c) 2022 The Recognize contributors.
 * Copyright (c) 2023 Marcel Klehr <mklehr@gmx.net>
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\ContextChat\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Class QueueFile
 *
 * @package OCA\ContextChat\Db
 * @method int getFileId()
 * @method setFileId(int $fileId)
 * @method int getStorageId()
 * @method setStorageId(int $storageId)
 * @method int getRootId()
 * @method setRootId(int $rootId)
 * @method setUpdate(boolean $update)
 * @method bool getUpdate()
 */
class QueueFile extends Entity {
	public $id;
	protected $fileId;
	protected $storageId;
	protected $rootId;
	protected $update;

	/** @var string[]  */
	public static array $columns = ['id', 'file_id', 'storage_id', 'root_id', 'update'];
	/** @var string[]  */
	public static array $fields = ['id', 'fileId', 'storageId', 'rootId', 'update'];

	public function __construct() {
		// add types in constructor
		$this->addType('id', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('storageId', 'integer');
		$this->addType('rootId', 'integer');
		$this->addType('update', 'boolean');
	}
}
