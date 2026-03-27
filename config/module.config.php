<?php
return [
    'controllers' => [
        'factories' => [
            'Wikibase\Controller\Proxy' =>
                \Wikibase\Service\Controller\ProxyControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'wikibase-proxy' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'options' => [
                    'route' => '/wikibase/proxy',
                    'defaults' => [
                        '__NAMESPACE__' => 'Wikibase\Controller',
                        'controller'    => 'Wikibase\Controller\Proxy',
                        'action'        => 'index',
                    ],
                ],
            ],
            'wikibase-labels' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'options' => [
                    'route' => '/wikibase/labels',
                    'defaults' => [
                        '__NAMESPACE__' => 'Wikibase\Controller',
                        'controller'    => 'Wikibase\Controller\Proxy',
                        'action'        => 'labels',
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'wikibase' => [
        'api_url'         => '',
        'languages'       => ['it', 'en'],
        'instance_of_pid' => 'P5',
        'property_mapping' => [
            'dcterms:creator'     => [
                'classes' => ['Q5'],  // senza label usa dcterms:creator
                'label'   => 'Autore',
                'preload' => true,  // se true, filtra i risultati in base alla classe anche se non è specificata la proprietà nella query
            ],
            'dcterms:contributor' => [
                'label'   => 'Contributore',
                'preload' => true,
            ],
            'dcterms:spatial'     => [
                'label'   => 'Luogo',
                'preload' => true,
            ],
        ],
    ],
];