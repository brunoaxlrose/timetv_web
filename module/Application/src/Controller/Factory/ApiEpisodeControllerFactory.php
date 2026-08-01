<?php

namespace Application\Controller\Factory;

use Application\Controller\Api\EpisodeController;
use Application\Model\TrackingModel;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use PDO;

class ApiEpisodeControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null): EpisodeController {
        return new EpisodeController(new TrackingModel($container->get(PDO::class)));
    }
}
