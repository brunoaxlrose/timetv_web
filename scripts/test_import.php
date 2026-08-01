<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '/var/www/html/vendor/autoload.php';

$host = getenv('DB_HOST') ?: 'db';
$port = getenv('DB_PORT') ?: '5432';
$database = getenv('DB_NAME') ?: 'tvtime_db';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $username, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Amor à Vida tvmaze_id = 265
$tvmazeId = 265;

// Delete if exists so we can re-import
$pdo->exec("DELETE FROM item WHERE tvmaze_id = $tvmazeId");

try {
    $result = Application\Helper\TvmazeHelper::importFromTVMaze($pdo, $tvmazeId);
    echo "Result: ";
    var_dump($result);
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
