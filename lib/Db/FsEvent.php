<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCA\ContextChat\Type\FsEventType;
use OCP\AppFramework\Db\Entity;

/**
 * Class FsEvent
 *
 * @package OCA\ContextChat\Db
 * @method int getNodeId()
 * @method setNodeId(int $nodeId)
 */
class FsEvent extends Entity {
	public $id;
	protected $type;
	protected $payload;

	/** @var string[] */
	public static array $columns = ['id', 'type', 'node_id'];
	/** @var string[] */
	public static array $fields = ['id', 'type', 'nodeId'];

	public function __construct() {
		// add types in constructor
		$this->addType('id', 'integer');
		$this->addType('type', 'string');
		$this->addType('nodeId', 'string');
	}

	/**
	 * @throws \ValueError
	 */
	public function setType(FsEventType|string $type): void {
		$this->setter('type', [FsEventType::from($type)->value]);
	}

	/**
	 * @throws \ValueError
	 */
	public function getType(): FsEventType {
		return FsEventType::from($this->getter('type'));
	}
}
