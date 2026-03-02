<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024-2026 Nextcloud GmbH and Nextcloud contributors
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
 * @method \DateTime getLockedAt()
 * @method setLockedAt(\DateTime $dateTime)
 */
class QueueAction extends Entity implements \JsonSerializable {
	protected $type;
	protected $payload;
	protected $lockedAt;

	/** @var string[] */
	public static array $columns = ['id', 'type', 'payload', 'locked_at'];
	/** @var string[] */
	public static array $fields = ['id', 'type', 'payload', 'lockedAt'];

	public function __construct() {
		// add types in constructor
		$this->addType('type', 'string');
		$this->addType('payload', 'string');
		$this->addType('lockedAt', 'datetime');
	}

	public function jsonSerialize() : array {
		return [
			'id' => $this->id,
			'type' => $this->type,
			'payload' => $this->payload,
		];
	}
}
