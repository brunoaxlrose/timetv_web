<?php

namespace Application\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Application\Controller\TrackingController;
use Application\Model\TrackingModel;
use PDO;

class TrackingControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null) {
        $pdo = $container->get(PDO::class);
        $trackingModel = new TrackingModel($pdo);
        return new TrackingController($trackingModel);
    }
}
