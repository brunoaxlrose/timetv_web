<?php

namespace Application\Controller\Factory;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Application\Controller\AuthController;
use Application\Model\AuthModel;
use PDO;

class AuthControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null) {
        $pdo = $container->get(PDO::class);
        $authModel = new AuthModel($pdo);
        return new AuthController($authModel);
    }
}
