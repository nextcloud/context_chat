<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCP\Files\Cache;

use OCP\EventDispatcher\Event;

/**
 * Added in Nextcloud 34, not available in the stable33 OCP package used for static analysis.
 */
class CacheEntriesRemovedEvent extends Event {
	/**
	 * @param ICacheEvent[] $cacheEntryRemovedEvents
	 */
	public function __construct(
		private readonly array $cacheEntryRemovedEvents,
	) {
		parent::__construct();
	}

	/**
	 * @return ICacheEvent[]
	 */
	public function getCacheEntryRemovedEvents(): array {
		return $this->cacheEntryRemovedEvents;
	}
}
