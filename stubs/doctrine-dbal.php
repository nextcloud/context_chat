<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Doctrine\DBAL {
	final class ParameterType {
		/**
		 * Represents the SQL NULL data type.
		 */
		public const NULL = 0;

		/**
		 * Represents the SQL INTEGER data type.
		 */
		public const INTEGER = 1;

		/**
		 * Represents the SQL CHAR, VARCHAR, or other string data type.
		 *
		 * @see \PDO::PARAM_STR
		 */
		public const STRING = 2;

		/**
		 * Represents the SQL large object data type.
		 */
		public const LARGE_OBJECT = 3;

		/**
		 * Represents a boolean data type.
		 *
		 * @see \PDO::PARAM_BOOL
		 */
		public const BOOLEAN = 5;

		/**
		 * Represents a binary string data type.
		 */
		public const BINARY = 16;

		/**
		 * Represents an ASCII string data type
		 */
		public const ASCII = 17;

		/**
		 * This class cannot be instantiated.
		 *
		 * @codeCoverageIgnore
		 */
		private function __construct() {
		}
	}

	final class ArrayParameterType {
		/**
		 * Represents an array of ints to be expanded by Doctrine SQL parsing.
		 */
		public const INTEGER = ParameterType::INTEGER + Connection::ARRAY_PARAM_OFFSET;

		/**
		 * Represents an array of strings to be expanded by Doctrine SQL parsing.
		 */
		public const STRING = ParameterType::STRING + Connection::ARRAY_PARAM_OFFSET;

		/**
		 * Represents an array of ascii strings to be expanded by Doctrine SQL parsing.
		 */
		public const ASCII = ParameterType::ASCII + Connection::ARRAY_PARAM_OFFSET;

		/**
		 * Represents an array of ascii strings to be expanded by Doctrine SQL parsing.
		 */
		public const BINARY = ParameterType::BINARY + Connection::ARRAY_PARAM_OFFSET;

		/**
		 * @internal
		 *
		 * @psalm-param self::* $type
		 *
		 * @psalm-return ParameterType::INTEGER|ParameterType::STRING|ParameterType::ASCII|ParameterType::BINARY
		 */
		public static function toElementParameterType(int $type): int {
		}

		private function __construct() {
		}
	}

	class Connection {
		/**
		 * Represents an array of ints to be expanded by Doctrine SQL parsing.
		 */
		public const PARAM_INT_ARRAY = ParameterType::INTEGER + self::ARRAY_PARAM_OFFSET;

		/**
		 * Represents an array of strings to be expanded by Doctrine SQL parsing.
		 */
		public const PARAM_STR_ARRAY = ParameterType::STRING + self::ARRAY_PARAM_OFFSET;

		/**
		 * Offset by which PARAM_* constants are detected as arrays of the param type.
		 */
		public const ARRAY_PARAM_OFFSET = 100;
	}
}
