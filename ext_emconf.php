<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Snapshot',
    'description' => 'Developer provisioning for TYPO3: pull database + fileadmin from any environment to your local machine. Not a backup tool.',
    'category' => 'module',
    'author' => 'Yellow Twins',
    'author_email' => 'hello@yellow-twins.com',
    'author_company' => 'Yellow Twins',
    'state' => 'beta',
    'version' => '0.9.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
