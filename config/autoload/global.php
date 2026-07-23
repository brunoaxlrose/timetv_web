<?php

use Laminas\Session\Storage\SessionArrayStorage;
use Laminas\Session\Validator\HttpUserAgent;
use Laminas\Session\Validator\RemoteAddr;

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '5433';
$dbName = getenv('DB_NAME') ?: 'tvtime_db';

return [
    'db' => [
        'driver' => 'Pdo',
        'dsn'    => "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}",
    ],
    'session_config' => [
        'cookie_lifetime'     => 86400,
        'gc_maxlifetime'      => 86400,
        'remember_me_seconds' => 86400,
        'name'                => 'tvtime_session',
    ],
    'session_storage' => [
        'type' => SessionArrayStorage::class,
    ],
    'session_validators' => [
        RemoteAddr::class,
        HttpUserAgent::class,
    ],
];
