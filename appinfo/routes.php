<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		['name' => 'provider#getProviders', 'url' => '/providers', 'verb' => 'GET'],
		['name' => 'provider#getDefaultProviderKey', 'url' => '/default-provider-key', 'verb' => 'GET'],
		['name' => 'provider#getMetadataFor', 'url' => '/sources-metadata', 'verb' => 'POST'],
		['name' => 'log#getNextcloudLogs', 'url' => '/download-logs-nextcloud', 'verb' => 'GET'],
	],
];
