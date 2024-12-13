<?php

/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2024
 */

declare(strict_types=1);
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
