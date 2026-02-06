<?php
define('NC_CLI_MODE', true);
require_once '/var/www/html/lib/base.php';

use OCP\Server;
use OCP\Files\IRootFolder;

try {
    $rootFolder = Server::get(IRootFolder::class);
    $userFolder = $rootFolder->getUserFolder('admin');
    $file = $userFolder->get('test.txt');

    $reportedSize = $file->getSize();
    echo "File::getSize(): " . $reportedSize . "\n";

    $handle = $file->fopen('rb');
    $stat = fstat($handle);
    echo "fstat()['size']: " . $stat['size'] . "\n";

    $contents = stream_get_contents($handle);
    $actualReadSize = strlen($contents);
    echo "Actual Read Size: " . $actualReadSize . "\n";

    echo "Mismatch: " . ($reportedSize - $actualReadSize) . "\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
