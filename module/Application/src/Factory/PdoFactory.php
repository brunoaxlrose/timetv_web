<?php

namespace Application\Factory;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use PDO;
use Application\Helper\DatabaseSchemaHelper;

class PdoFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null) {
        $config = $container->get('config');
        $dbConfig = $config['db'] ?? [];
        
        $dsn = $dbConfig['dsn'] ?? '';
        $username = $dbConfig['username'] ?? '';
        $password = $dbConfig['password'] ?? '';
        
        $pdo = new PDO($dsn, $username, $password);
        $pdo->exec("SET TIME ZONE 'America/Sao_Paulo'");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Keep lightweight CREATE/ALTER migrations applied even when the base schema already exists.
        DatabaseSchemaHelper::ensureTablesExist($pdo);
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS search_cache (
                query VARCHAR(255) PRIMARY KEY,
                results TEXT NOT NULL,
                ts_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        return $pdo;
    }
}
