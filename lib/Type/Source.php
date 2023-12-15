<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2023
 */

namespace OCA\ContextChat\Type;

class Source {
	public function __construct(
		public string $userId,
		public string $reference,
		public string $title,
		public mixed $content,
		public int | string $modified,
		public string $type,
	) {
	}
}
