<?php

namespace Application\Controller\Factory;

use Application\Controller\ImportExportController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ImportExportControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ImportExportController {
        $pdo = $container->get(\PDO::class);
        return new ImportExportController($pdo);
    }
}
