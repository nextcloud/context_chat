<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Class QueueContentItem
 *
 * @package OCA\ContextChat\Db
 * @method setItemId(string $itemId)
 * @method string getItemId()
 * @method setAppId(string $appId)
 * @method string getAppId()
 * @method setProviderId(string $providerId)
 * @method string getProviderId()
 * @method setTitle(string $title)
 * @method string getTitle()
 * @method setContent(string $content)
 * @method string getContent()
 * @method setDocumentType(string $documentType)
 * @method string getDocumentType()
 * @method setLastModified(\DateTime $lastModified)
 * @method \DateTime getLastModified()
 * @method setUsers(string $users)
 * @method string getUsers()
 */
class QueueContentItem extends Entity {
	public $id;
	protected $itemId;
	protected $appId;
	protected $providerId;
	protected $title;
	protected $content;
	protected $documentType;
	protected $lastModified;
	protected $users;

	public static $columns = [
		'id',
		'item_id',
		'app_id',
		'provider_id',
		'title',
		'content',
		'document_type',
		'last_modified',
		'users'
	];
	public static $fields = [
		'id',
		'itemId',
		'appId',
		'providerId',
		'title',
		'content',
		'documentType',
		'lastModified',
		'users'
	];

	public function __construct() {
		// add types in constructor
		$this->addType('id', Types::INTEGER);
		$this->addType('itemId', Types::STRING);
		$this->addType('appId', Types::STRING);
		$this->addType('providerId', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('content', Types::STRING);
		$this->addType('documentType', Types::STRING);
		$this->addType('lastModified', Types::DATETIME);
		$this->addType('users', Types::STRING);
	}
}
