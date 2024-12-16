<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Type;

class Source {
	public function __construct(
		public array $userIds,
		public string $reference,
		public string $title,
		public mixed $content,
		public int|string $modified,
		public string $type,
		public string $provider,
	) {
	}
}
