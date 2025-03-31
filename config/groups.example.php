<?php
// File: config/groups.php

return [
    'default_key' => 'customer',
    'groups'  => [
        'acme' => [
            'aliases' => ['acme', 'acmecorp', 'acme-inc'],
            'parent'  => null,
        ],
        'globex' => [
            'aliases' => ['globex', 'globex-corp'],
            'parent'  => null,
        ],
        'initech' => [
            'aliases' => ['initech', 'initech-inc'],
            'parent'  => null,
        ],
        'initech-support' => [
            'aliases' => ['initech-support', 'support'],
            'parent'  => 'initech',
        ],
    ],
    'email_domains' => [
        'acme'    => ['acme.com'],
        'globex'  => ['globex.com'],
        'initech' => ['initech.com'],
    ],
];
