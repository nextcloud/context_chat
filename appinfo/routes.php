<?php

/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

return [
	'routes' => [
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		['name' => 'provider#getProviders', 'url' => '/providers', 'verb' => 'GET'],
		['name' => 'provider#getDefaultProviderKey', 'url' => '/default-provider-key', 'verb' => 'GET'],
		['name' => 'provider#getMetadataFor', 'url' => '/sources-metadata', 'verb' => 'POST'],
	],
];
