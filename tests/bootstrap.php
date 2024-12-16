<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/../../../tests/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use OCA\ContextChat\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Server;

Server::get(IAppManager::class)->loadApp(Application::APP_ID);
OC_Hook::clear();
