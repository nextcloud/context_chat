<?php
define('NC_CLI_MODE', true);
require_once '/var/www/html/lib/base.php';

use OCP\Server;
use OCP\Files\IRootFolder;

function checkFile($path) {
    try {
        $rootFolder = Server::get(IRootFolder::class);
        $userFolder = $rootFolder->getUserFolder('admin');
        if (!$userFolder->nodeExists($path)) {
            echo "File $path not found.\n";
            return;
        }
        $file = $userFolder->get($path);

        $reportedSize = $file->getSize();
        echo "File::getSize() for $path: " . $reportedSize . "\n";

        $handle = $file->fopen('rb');
        $stat = fstat($handle);
        echo "fstat()['size'] for $path: " . $stat['size'] . "\n";

        $contents = stream_get_contents($handle);
        $actualReadSize = strlen($contents);
        echo "Actual Read Size for $path: " . $actualReadSize . "\n";

        echo "Mismatch for $path: " . ($reportedSize - $actualReadSize) . "\n";
    } catch (\Exception $e) {
        echo "Error checking $path: " . $e->getMessage() . "\n";
    }
}

checkFile('test.txt');
checkFile('Nextcloud Manual.pdf'); // Check the default file too
