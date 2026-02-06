<?php
define('NC_CLI_MODE', true);
require_once '/var/www/html/console.php';

use OCP\Server;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Db\ExAppMapper;

try {
    if (!class_exists(ExAppMapper::class)) {
        echo "AppAPI classes not found.\n";
        exit(1);
    }

    $mapper = Server::get(ExAppMapper::class);

    // Try to find existing
    try {
        $existing = $mapper->find('context_chat_backend');
        $mapper->delete($existing);
        echo "Deleted existing registration.\n";
    } catch (\Exception $e) {
        // Not found, ignore
    }

    $exApp = new ExApp();
    $exApp->setAppId('context_chat_backend');
    $exApp->setName('Context Chat Backend');
    $exApp->setDeployMethod('manual_install');
    $exApp->setVersion('1.0.0');
    $exApp->setEnabled(1);
    $exApp->setHost('context_chat_backend');
    $exApp->setPort(23000);
    $exApp->setProtocol('http');
    $exApp->setSecret('secret');
    $exApp->setHash('hash');
    $exApp->setLastUpdated(time());

    // Set other required fields if any (based on standard ExApp entity)
    // Some versions require 'scopes' or 'daemon_config_name'
    if (method_exists($exApp, 'setDaemonConfigName')) {
        $exApp->setDaemonConfigName('manual_install');
    }

    $mapper->insert($exApp);

    echo "Registered context_chat_backend successfully via Mapper.\n";

} catch (\Exception $e) {
    echo "Error registering app: " . $e->getMessage() . "\n";
    exit(1);
}
