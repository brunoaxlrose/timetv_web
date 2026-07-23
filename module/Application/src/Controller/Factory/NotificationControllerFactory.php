<?php

namespace Application\Controller\Factory;

use Application\Controller\NotificationController;
use Application\Model\NotificationModel;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class NotificationControllerFactory implements FactoryInterface {
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): NotificationController {
        $pdo = $container->get(\PDO::class);
        $notificationModel = new NotificationModel($pdo);
        return new NotificationController($notificationModel);
    }
}
