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

/**
 * This interface defines methods to implement a content provider
 * @since 1.1.0
 */
interface IContentProvider {
	/**
	 * The ID of the provider
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public function getId(): string;

	/**
	 * The ID of the app making the provider avaialble
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public function getAppId(): string;

	/**
	 * The absolute URL to the content item
	 *
	 * @param string $id
	 * @return string
	 * @since 1.1.0
	 */
	public function getItemUrl(string $id): string;

	/**
	 * Starts the initial import of content items into content chat
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function triggerInitialImport(): void;
}
