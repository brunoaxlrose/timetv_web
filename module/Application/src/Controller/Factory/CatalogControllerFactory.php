<?php

namespace Application\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Application\Controller\CatalogController;
use Application\Model\CatalogModel;
use Application\Model\TrackingModel;
use PDO;

class CatalogControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null) {
        $pdo = $container->get(PDO::class);
        $catalogModel = new CatalogModel($pdo);
        $trackingModel = new TrackingModel($pdo);
        return new CatalogController($catalogModel, $trackingModel);
    }
}
