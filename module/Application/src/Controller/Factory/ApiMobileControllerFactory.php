<?php

namespace Application\Controller\Factory;

use Application\Controller\Api\MobileController;
use Application\Model\AuthModel;
use Application\Model\CatalogModel;
use Application\Model\TrackingModel;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use PDO;

class ApiMobileControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null): MobileController {
        $pdo = $container->get(PDO::class);

        return new MobileController(
            new TrackingModel($pdo),
            new CatalogModel($pdo),
            new AuthModel($pdo)
        );
    }
}
