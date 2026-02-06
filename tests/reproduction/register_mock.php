<?php
define('NC_CLI_MODE', true);
require_once '/var/www/html/console.php';

use OCP\Server;

try {
    $db = Server::get(\OCP\IDBConnection::class);

    // Check if table exists
    if (!$db->tableExists('app_api_apps')) {
        echo "Table app_api_apps does not exist.\n";
        exit(1);
    }

    // Delete existing if any
    $qb = $db->getQueryBuilder();
    $qb->delete('app_api_apps')
       ->where($qb->expr()->eq('app_id', $qb->createNamedParameter('context_chat_backend')))
       ->execute();

    // Insert
    $qb = $db->getQueryBuilder();
    $qb->insert('app_api_apps')
       ->setValue('app_id', $qb->createNamedParameter('context_chat_backend'))
       ->setValue('name', $qb->createNamedParameter('Context Chat Backend'))
       ->setValue('deploy_method', $qb->createNamedParameter('manual_install'))
       ->setValue('version', $qb->createNamedParameter('1.0.0'))
       ->setValue('enabled', $qb->createNamedParameter(1))
       ->setValue('host', $qb->createNamedParameter('context_chat_backend'))
       ->setValue('port', $qb->createNamedParameter(23000))
       ->setValue('protocol', $qb->createNamedParameter('http'))
       ->setValue('secret', $qb->createNamedParameter('secret'))
       ->setValue('hash', $qb->createNamedParameter('hash'))
       ->setValue('last_updated', $qb->createNamedParameter(time()))
       ->execute();

    echo "Registered context_chat_backend successfully.\n";

} catch (\Exception $e) {
    echo "Error registering app: " . $e->getMessage() . "\n";
    exit(1);
}
