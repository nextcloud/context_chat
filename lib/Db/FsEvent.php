<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCA\ContextChat\Type\FsEventType;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Class FsEvent
 *
 * @package OCA\ContextChat\Db
 * @method string getUserId()
 * @method setUserId(string $userId)
 * @method int getNodeId()
 * @method setNodeId(int $nodeId)
 */
class FsEvent extends Entity {
	public $id;
	protected $type;
	protected $userId;
	protected $nodeId;

	/** @var string[] */
	public static array $columns = ['id', 'type', 'user_id', 'node_id'];
	/** @var string[] */
	public static array $fields = ['id', 'type', 'userId', 'nodeId'];

	public function __construct() {
		// add types in constructor
		$this->addType('type', Types::STRING);
		$this->addType('userId', Types::STRING);
		$this->addType('nodeId', 'integer'); // stable30 does not support Types::BIGINT here
	}

	/**
	 * @throws \ValueError
	 * @throws \InvalidArgumentException
	 */
	public function setType(FsEventType|string $type): void {
		if (is_string($type)) {
			$type = FsEventType::from($type);
		}
		$this->setter('type', [$type->value]);
	}

	/**
	 * @throws \ValueError
	 * @throws \InvalidArgumentException
	 */
	public function getType(): string {
		return $this->getter('type');
	}

	/**
	 * @throws \ValueError
	 * @throws \InvalidArgumentException
	 */
	public function getTypeObject(): FsEventType {
		return FsEventType::from($this->getter('type'));
	}
}
