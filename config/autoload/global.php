<?php

use Laminas\Session\Storage\SessionArrayStorage;
use Laminas\Session\Validator\HttpUserAgent;
use Laminas\Session\Validator\RemoteAddr;

$databaseUrl = trim((string)getenv('DATABASE_URL'));
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '5433';
$dbName = getenv('DB_NAME') ?: 'tvtime_db';
$dbUser = getenv('DB_USER') ?: null;
$dbPassword = getenv('DB_PASSWORD') ?: null;
$dbSslMode = getenv('DB_SSLMODE') ?: ($databaseUrl !== '' ? 'require' : null);

if ($databaseUrl !== '') {
    $connection = parse_url($databaseUrl);
    if ($connection === false || empty($connection['host']) || empty($connection['path'])) {
        throw new RuntimeException('DATABASE_URL invalida. Use a connection string PostgreSQL completa.');
    }

    $dbHost = $connection['host'];
    $dbPort = (string)($connection['port'] ?? 5432);
    $dbName = ltrim($connection['path'], '/');
    $dbUser = isset($connection['user']) ? rawurldecode($connection['user']) : null;
    $dbPassword = isset($connection['pass']) ? rawurldecode($connection['pass']) : null;

    if (!empty($connection['query'])) {
        parse_str($connection['query'], $query);
        $dbSslMode = $query['sslmode'] ?? $dbSslMode;
    }
}

$dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
if ($dbSslMode) {
    $dsn .= ";sslmode={$dbSslMode}";
}

return [
    'db' => [
        'driver' => 'Pdo',
        'dsn'      => $dsn,
        'username' => $dbUser,
        'password' => $dbPassword,
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
