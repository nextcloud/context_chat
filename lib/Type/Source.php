<?php
/**
 * Nextcloud - Cwyd
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2023
 */

namespace OCA\Cwyd\Type;

class Source {
	public function __construct(
		public string $reference,
		public mixed $content,
		public string $modified,
		public string $type,
	) {
	}
}
