<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Files\Storage\Wrapper {

	use OCP\Files\Storage\IStorage;

	/**
	 * Private server classes, only the methods used by the app
	 */
	abstract class Wrapper implements IStorage {
		/**
		 * @template T of IStorage
		 * @param class-string<T> $class
		 * @return ?T
		 */
		public function getInstanceOfStorage(string $class): ?IStorage {
		}
	}

	abstract class Jail extends Wrapper {
		public function getUnjailedPath(string $path): string {
		}

		public function getUnjailedStorage(): IStorage {
		}
	}
}
