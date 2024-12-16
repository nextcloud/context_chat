<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Event;

use OCA\ContextChat\Public\ContentManager;
use OCA\ContextChat\Public\IContentProvider;
use OCP\EventDispatcher\Event;

class ContentProviderRegisterEvent extends Event {
	public function __construct(
		private ContentManager $contentManager,
	) {
	}

	/**
	 * @param string $appId
	 * @param string $providerId
	 * @param class-string<IContentProvider> $providerClass
	 * @return void
	 * @since 2.2.2
	 */
	public function registerContentProvider(string $appId, string $providerId, string $providerClass): void {
		$this->contentManager->registerContentProvider($appId, $providerId, $providerClass);
	}
}
