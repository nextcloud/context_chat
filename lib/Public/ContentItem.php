<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
