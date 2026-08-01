<?php

namespace Application\Controller\Factory;

use Application\Controller\Api\FeedbackController;
use Application\Model\AuthModel;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use PDO;

class ApiFeedbackControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null): FeedbackController {
        return new FeedbackController(new AuthModel($container->get(PDO::class)));
    }
}
