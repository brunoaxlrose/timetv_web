<?php

namespace Application;

use Application\Controller\AuthController;
use Application\Controller\CatalogController;
use Application\Controller\TrackingController;
use Application\Controller\NotificationController;
use Application\Controller\ImportExportController;
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
            'lists' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/lists',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'lists',
                    ],
                ],
            ],
            'search' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/search',
                    'defaults' => [
                        'controller' => CatalogController::class,
                        'action'     => 'search',
                    ],
                ],
            ],
            'api-lists-create' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/lists/create',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiCreateList',
                    ],
                ],
            ],
            'api-lists-delete' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/lists/delete',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiDeleteList',
                    ],
                ],
            ],
            'api-lists-add' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/lists/add',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiAddToList',
                    ],
                ],
            ],
            'api-lists-remove' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/lists/remove',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiRemoveFromList',
                    ],
                ],
            ],
            'api-lists-item-lists' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/lists/item-lists',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiGetItemLists',
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
            'api-episode-create' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/episode/create',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiCreateEpisode',
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
            'api-notifications' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/notifications',
                    'defaults' => [
                        'controller' => NotificationController::class,
                        'action'     => 'list',
                    ],
                ],
            ],
            'api-notifications-read' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/notifications/read',
                    'defaults' => [
                        'controller' => NotificationController::class,
                        'action'     => 'markRead',
                    ],
                ],
            ],
            'api-import' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/import',
                    'defaults' => [
                        'controller' => ImportExportController::class,
                        'action'     => 'import',
                    ],
                ],
            ],
            'api-export' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/export',
                    'defaults' => [
                        'controller' => ImportExportController::class,
                        'action'     => 'export',
                    ],
                ],
            ],
            'import-tvtime' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/import/tvtime',
                    'defaults' => [
                        'controller' => ImportExportController::class,
                        'action'     => 'tvtime',
                    ],
                ],
            ],
            'import-imdb' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/import/imdb',
                    'defaults' => [
                        'controller' => ImportExportController::class,
                        'action'     => 'imdb',
                    ],
                ],
            ],
            'import-trakt' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/import/trakt',
                    'defaults' => [
                        'controller' => ImportExportController::class,
                        'action'     => 'trakt',
                    ],
                ],
            ],
            'api-import-tvtime' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/import/tvtime',
                    'defaults' => [
                        'controller' => ImportExportController::class,
                        'action'     => 'apiImportTvtime',
                    ],
                ],
            ],
            'api-import-imdb' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/import/imdb',
                    'defaults' => [
                        'controller' => ImportExportController::class,
                        'action'     => 'apiImportImdb',
                    ],
                ],
            ],
            'api-save-review' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/save-review',
                    'defaults' => [
                        'controller' => CatalogController::class,
                        'action'     => 'apiSaveReview',
                    ],
                ],
            ],
            'api-rewatch-episode' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/rewatch-episode',
                    'defaults' => [
                        'controller' => TrackingController::class,
                        'action'     => 'apiRewatchEpisode',
                    ],
                ],
            ],
            'api-import-trakt' => [
                'type' => Literal::class,
                'options' => [
                    'route'    => '/api/import/trakt',
                    'defaults' => [
                        'controller' => ImportExportController::class,
                        'action'     => 'apiImportTrakt',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            AuthController::class => \Application\Controller\Factory\AuthControllerFactory::class,
            CatalogController::class => \Application\Controller\Factory\CatalogControllerFactory::class,
            TrackingController::class => \Application\Controller\Factory\TrackingControllerFactory::class,
            NotificationController::class => \Application\Controller\Factory\NotificationControllerFactory::class,
            ImportExportController::class => \Application\Controller\Factory\ImportExportControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            \PDO::class => \Application\Factory\PdoFactory::class,
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
            
            'application/auth/login'          => __DIR__ . '/../view/auth/login.phtml',
            'application/auth/register'       => __DIR__ . '/../view/auth/register.phtml',
            'application/catalog/index'       => __DIR__ . '/../view/catalog/index.phtml',
            'application/catalog/detail'      => __DIR__ . '/../view/catalog/detail.phtml',
            'application/tracking/dashboard'  => __DIR__ . '/../view/tracking/dashboard.phtml',
            'application/tracking/stats'      => __DIR__ . '/../view/tracking/stats.phtml',
            'application/tracking/lists'      => __DIR__ . '/../view/tracking/lists.phtml',
            'application/tracking/diary'      => __DIR__ . '/../view/tracking/diary.phtml',
            'application/catalog/search'      => __DIR__ . '/../view/catalog/search.phtml',
            'application/import-export/tvtime' => __DIR__ . '/../view/import-export/tvtime.phtml',
            'application/import-export/imdb'   => __DIR__ . '/../view/import-export/imdb.phtml',
            'application/import-export/trakt'  => __DIR__ . '/../view/import-export/trakt.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
];
