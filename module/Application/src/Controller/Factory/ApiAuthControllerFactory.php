<?php

namespace Application\Controller\Factory;

use Application\Controller\Api\AuthController;
use Application\Model\AuthModel;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use PDO;

class ApiAuthControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null): AuthController {
        return new AuthController(new AuthModel($container->get(PDO::class)));
    }
}
