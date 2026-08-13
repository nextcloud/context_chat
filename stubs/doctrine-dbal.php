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

namespace Doctrine\DBAL\Schema {

	/**
	 * Stub for Doctrine\DBAL\Schema\Table.
	 *
	 * Based on doctrine/dbal 3.10.x (used by Nextcloud stable32–34).
	 *
	 * @see https://github.com/doctrine/dbal/blob/3.10.x/src/Schema/Table.php
	 */
	class Table {

		/**
		 * @param string                       $name
		 * @param Column[]                     $columns
		 * @param Index[]                      $indexes
		 * @param UniqueConstraint[]           $uniqueConstraints
		 * @param ForeignKeyConstraint[]       $fkConstraints
		 * @param mixed[]                      $options
		 */
		public function __construct(
			string $name,
			array $columns = [],
			array $indexes = [],
			array $uniqueConstraints = [],
			array $fkConstraints = [],
			array $options = [],
		) {
		}

		/** @return void */
		public function setSchemaConfig(SchemaConfig $schemaConfig) {
		}

		/**
		 * @param string[] $columnNames
		 * @return self
		 */
		public function setPrimaryKey(array $columnNames, ?string $indexName = null) {
		}

		/**
		 * @param string[] $columnNames
		 * @param string[] $flags
		 * @param mixed[]  $options
		 * @return self
		 */
		public function addIndex(array $columnNames, ?string $indexName = null, array $flags = [], array $options = []) {
		}

		/**
		 * @return void
		 */
		public function dropPrimaryKey() {
		}

		/**
		 * @param string $name
		 * @return void
		 */
		public function dropIndex($name) {
		}

		/**
		 * @param string[] $columnNames
		 * @param string[] $flags
		 * @param mixed[]  $options
		 * @return self
		 */
		public function addUniqueIndex(array $columnNames, ?string $indexName = null, array $options = []) {
		}

		/**
		 * @param string[] $columnNames
		 * @param string[] $flags
		 * @param mixed[]  $options
		 * @return Table
		 */
		public function addUniqueConstraint(array $columnNames, ?string $indexName = null, array $flags = [], array $options = []) {
		}

		/**
		 * @param string      $oldName
		 * @param string|null $newName
		 * @return self
		 */
		public function renameIndex($oldName, $newName = null) {
		}

		/**
		 * @param string[] $columnNames
		 * @return bool
		 */
		public function columnsAreIndexed(array $columnNames) {
		}

		/**
		 * @param string  $name
		 * @param string  $typeName
		 * @param mixed[] $options
		 * @return Column
		 */
		public function addColumn($name, $typeName, array $options = []) {
		}

		/**
		 * @deprecated Use modifyColumn() instead.
		 * @param string  $name
		 * @param mixed[] $options
		 * @return self
		 */
		public function changeColumn($name, array $options) {
		}

		/**
		 * @param string  $name
		 * @param mixed[] $options
		 * @return self
		 */
		public function modifyColumn($name, array $options) {
		}

		/**
		 * @param string $name
		 * @return self
		 */
		public function dropColumn($name) {
		}

		/**
		 * @param string|Table $foreignTable
		 * @param string[]     $localColumnNames
		 * @param string[]     $foreignColumnNames
		 * @param mixed[]      $options
		 * @param string|null  $name
		 * @return self
		 */
		public function addForeignKeyConstraint($foreignTable, array $localColumnNames, array $foreignColumnNames, array $options = [], $name = null) {
		}

		/**
		 * @param string $name
		 * @param mixed  $value
		 * @return self
		 */
		public function addOption($name, $value) {
		}

		/**
		 * @param string $name
		 * @return bool
		 */
		public function hasForeignKey($name) {
		}

		/**
		 * @param string $name
		 * @return ForeignKeyConstraint
		 */
		public function getForeignKey($name) {
		}

		/**
		 * @param string $name
		 * @return void
		 */
		public function removeForeignKey($name) {
		}

		/**
		 * @param string $name
		 * @return bool
		 */
		public function hasUniqueConstraint(string $name) {
		}

		/**
		 * @param string $name
		 * @return UniqueConstraint
		 */
		public function getUniqueConstraint(string $name) {
		}

		/**
		 * @return void
		 */
		public function removeUniqueConstraint(string $name) {
		}

		/**
		 * @return Column[]
		 */
		public function getColumns() {
		}

		/**
		 * @deprecated
		 * @return Column[]
		 */
		public function getForeignKeyColumns() {
		}

		/**
		 * @param string $name
		 * @return bool
		 */
		public function hasColumn($name) {
		}

		/**
		 * @param string $name
		 * @return Column
		 */
		public function getColumn($name) {
		}

		/**
		 * @return ?Index
		 */
		public function getPrimaryKey() {
		}

		/**
		 * @deprecated
		 * @return Column[]
		 */
		public function getPrimaryKeyColumns() {
		}

		/**
		 * @deprecated Use getPrimaryKey() instead.
		 * @return bool
		 */
		public function hasPrimaryKey() {
		}

		/**
		 * @param string $name
		 * @return bool
		 */
		public function hasIndex($name) {
		}

		/**
		 * @param string $name
		 * @return Index
		 */
		public function getIndex($name) {
		}

		/**
		 * @return Index[]
		 */
		public function getIndexes() {
		}

		/**
		 * @return ForeignKeyConstraint[]
		 */
		public function getForeignKeys() {
		}

		/**
		 * @return UniqueConstraint[]
		 */
		public function getUniqueConstraints() {
		}

		/**
		 * @param string $name
		 * @return bool
		 */
		public function hasOption($name) {
		}

		/**
		 * @param string $name
		 * @return mixed
		 */
		public function getOption($name) {
		}

		/**
		 * @return mixed[]
		 */
		public function getOptions() {
		}

		/**
		 * @return self
		 */
		public function setComment(?string $comment) {
		}

		/**
		 * @return ?string
		 */
		public function getComment() {
		}

		/**
		 * @return string
		 */
		public function getName() {
		}
	}

	/**
	 * Stub for Doctrine\DBAL\Schema\Column.
	 *
	 * Based on doctrine/dbal 3.10.x (used by Nextcloud stable32–34).
	 *
	 * @see https://github.com/doctrine/dbal/blob/3.10.x/src/Schema/Column.php
	 */
	class Column {

		/**
		 * @param string               $name
		 * @param \Doctrine\DBAL\Types\Type $type
		 * @param mixed[]              $options
		 */
		public function __construct(string $name, $type, array $options = []) {
		}

		/**
		 * @param mixed[] $options
		 * @return self
		 */
		public function setOptions(array $options) {
		}

		/**
		 * @param \Doctrine\DBAL\Types\Type $type
		 * @return self
		 */
		public function setType($type) {
		}

		/** @return \Doctrine\DBAL\Types\Type */
		public function getType() {
		}

		/**
		 * @param ?int $length
		 * @return self
		 */
		public function setLength(?int $length) {
		}

		/** @return ?int */
		public function getLength() {
		}

		/**
		 * @param int $precision
		 * @return self
		 */
		public function setPrecision(int $precision) {
		}

		/** @return int */
		public function getPrecision() {
		}

		/**
		 * @param int $scale
		 * @return self
		 */
		public function setScale(int $scale) {
		}

		/** @return int */
		public function getScale() {
		}

		/**
		 * @param bool $unsigned
		 * @return self
		 */
		public function setUnsigned(bool $unsigned) {
		}

		/** @return bool */
		public function getUnsigned() {
		}

		/**
		 * @param bool $fixed
		 * @return self
		 */
		public function setFixed(bool $fixed) {
		}

		/** @return bool */
		public function getFixed() {
		}

		/**
		 * @param bool $notnull
		 * @return self
		 */
		public function setNotnull(bool $notnull) {
		}

		/** @return bool */
		public function getNotnull() {
		}

		/**
		 * @param mixed $default
		 * @return self
		 */
		public function setDefault($default) {
		}

		/** @return mixed */
		public function getDefault() {
		}

		/**
		 * @param mixed[] $platformOptions
		 * @return self
		 */
		public function setPlatformOptions(array $platformOptions) {
		}

		/** @return mixed[] */
		public function getPlatformOptions() {
		}

		/**
		 * @param string $name
		 * @return bool
		 */
		public function hasPlatformOption($name) {
		}

		/**
		 * @param string $name
		 * @return mixed
		 */
		public function getPlatformOption($name) {
		}

		/**
		 * @param string $name
		 * @param mixed  $value
		 * @return self
		 */
		public function setPlatformOption($name, $value) {
		}

		/**
		 * @param ?string $columnDefinition
		 * @return self
		 */
		public function setColumnDefinition(?string $columnDefinition) {
		}

		/** @return ?string */
		public function getColumnDefinition() {
		}

		/**
		 * @param bool $flag
		 * @return self
		 */
		public function setAutoincrement(bool $flag) {
		}

		/** @return bool */
		public function getAutoincrement() {
		}

		/**
		 * @param ?string $comment
		 * @return self
		 */
		public function setComment(?string $comment) {
		}

		/** @return ?string */
		public function getComment() {
		}

		/** @return mixed[] */
		public function toArray() {
		}

		/** @return string */
		public function getName() {
		}
	}

	class Schema {
	}

	class SchemaException extends \Exception {
	}
}

namespace Doctrine\DBAL\Driver {
	interface Statement {
	}
}
