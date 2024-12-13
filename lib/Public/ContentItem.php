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

namespace OCA\ContextChat\Public;

class ContentItem {
	/**
	 * @param string $itemId
	 * @param string $providerId
	 * @param string $title
	 * @param string $content
	 * @param string $documentType
	 * @param \DateTime $lastModified
	 * @param string[] $users
	 */
	public function __construct(
		public string $itemId,
		public string $providerId,
		public string $title,
		public string $content,
		public string $documentType,
		public \DateTime $lastModified,
		public array $users,
	) {
	}
}
