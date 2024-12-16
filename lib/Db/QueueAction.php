<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Class QueueAction
 *
 * @package OCA\ContextChat\Db
 * @method string getType()
 * @method setType(string $type)
 * @method string getPayload()
 * @method setPayload(string $payload)
 */
class QueueAction extends Entity {
	public $id;
	protected $type;
	protected $payload;

	/** @var string[] */
	public static array $columns = ['id', 'type', 'payload'];
	/** @var string[] */
	public static array $fields = ['id', 'type', 'payload'];

	public function __construct() {
		// add types in constructor
		$this->addType('id', 'integer');
		$this->addType('type', 'string');
		$this->addType('payload', 'string');
	}
}
