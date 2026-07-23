<?php

namespace Application;

use Application\Controller\AuthController;
use Application\Controller\CatalogController;
use Application\Controller\TrackingController;
use Laminas\Router\Http\Literal;

return [
    'router' => [
        'routes' => [
            'home' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'dashboard',
                    ],
                ],
            ],
            'dashboard' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/dashboard',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'dashboard',
                    ],
                ],
            ],
            'login' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/login',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'login',
                    ],
                ],
            ],
            'register' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/register',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'register',
                    ],
                ],
            ],
            'logout' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/logout',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'logout',
                    ],
                ],
            ],
            'catalog' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/catalog',
                    'defaults' => [
                        'controller' => CatalogController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'detail' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/detail',
                    'defaults' => [
                        'controller' => CatalogController::class,
                        'action'     => 'detail',
                    ],
                ],
            ],
            'stats' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/stats',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'stats',
                    ],
                ],
            ],
            'diary' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/diary',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'diary',
                    ],
                ],
            ],
            'api-track' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/track',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiToggleTrack',
                    ],
                ],
            ],
            'api-episode' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/episode',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiToggleEpisode',
                    ],
                ],
            ],
            'api-google-login' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/google-login',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'googleLogin',
                    ],
                ],
            ],
            'api-update-profile' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/update-profile',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'updateProfile',
                    ],
                ],
            ],
            'api-clear-library' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/clear-library',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'clearLibrary',
                    ],
                ],
            ],
            'api-delete-account' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/delete-account',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'deleteAccount',
                    ],
                ],
            ],
            'api-feedback' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/feedback',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'feedback',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            AuthController::class => \Application\Controller\Factory\ControllerFactory::class,
            CatalogController::class => \Application\Controller\Factory\ControllerFactory::class,
            TrackingController::class => \Application\Controller\Factory\ControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions'       => true,
        'doctype'                  => 'HTML5',
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map' => [
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'error/404'               => __DIR__ . '/../view/error/404.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
];
