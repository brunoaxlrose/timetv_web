<?php

namespace Application\Controller\Factory;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class ControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null) {
        $config = $container->get('config');
        $dbConfig = $config['db'] ?? [];
        
        $dsn = $dbConfig['dsn'] ?? '';
        $username = $dbConfig['username'] ?? '';
        $password = $dbConfig['password'] ?? '';
        
        $pdo = new \PDO($dsn, $username, $password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        
        // Check if database schema needs initialization
        $stmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'item' LIMIT 1");
        $schemaExists = $stmt->fetch();
        if (!$schemaExists) {
            \Application\Helper\DatabaseSchemaHelper::ensureTablesExist($pdo);
        } else {
            \Application\Helper\DatabaseSchemaHelper::ensureTablesExist($pdo);
        }

        return new $requestedName($pdo);
    }
}
