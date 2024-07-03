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
