<?php
/*
 * Copyright (c) 2020-2022 The Recognize contributors.
 * Copyright (c) 2023 Marcel Klehr <mklehr@gmx.net>
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Cwyd\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000000Date20231102094721 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return void
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('cwyd_queue')) {
			$table = $schema->createTable('cwyd_queue');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('file_id', 'bigint', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('storage_id', 'bigint', [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('root_id', 'bigint', [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('update', 'boolean', [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id'], 'cwyd_q_id');
			$table->addIndex(['file_id'], 'cwyd_q_file');
			$table->addIndex(['storage_id', 'root_id'], 'cwyd_q_storage');
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}
}
