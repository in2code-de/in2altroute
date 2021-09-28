<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'in2altroute',
    'description' => 'TYPO3 extension to support speaking urls without a subdomain or fixed path segment',
    'category' => 'fe',
    'author' => 'Oliver Eglseder',
    'author_email' => 'oliver.eglseder@in2code.de',
    'state' => 'experimental',
    'clearCacheOnLoad' => true,
    'version' => '2.0.2',
    'constraints' => [
        'depends' => [
            'typo3' => '10.0.0-10.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
