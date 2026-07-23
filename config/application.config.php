<?php

return [
    'modules' => [
        'Laminas\InputFilter',
        'Laminas\Filter',
        'Laminas\Router',
        'Laminas\Session',
        'Laminas\Db',
        'Application',
    ],

    'module_listener_options' => [
        'module_paths' => [
            './module',
            './vendor',
        ],

        'config_glob_paths' => [
            realpath(__DIR__) . '/autoload/{{,*.}global,{,*.}local}.php',
        ],

        'config_cache_enabled' => false,
        'module_map_cache_enabled' => false,
    ],
];
