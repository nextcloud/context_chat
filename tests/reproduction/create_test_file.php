<?php
define('NC_CLI_MODE', true);
require_once '/var/www/html/lib/base.php';

use OCP\Server;
use OCP\Files\IRootFolder;

try {
    $rootFolder = Server::get(IRootFolder::class);
    $userFolder = $rootFolder->getUserFolder('admin');

    if ($userFolder->nodeExists('test.txt')) {
        $file = $userFolder->get('test.txt');
        $file->delete();
    }

    $file = $userFolder->newFile('test.txt');
    // Write 1MB of data
    $file->putContent(str_repeat('A', 1024 * 1024));

    echo "Created encrypted test.txt successfully.\n";

} catch (\Exception $e) {
    echo "Error creating file: " . $e->getMessage() . "\n";
    exit(1);
}
