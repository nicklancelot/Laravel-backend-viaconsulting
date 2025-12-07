<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Autoriser ton site en production
    'allowed_origins' => [
        'https://viaconsulting.mg',
        'https://www.viaconsulting.mg',
        'http://viaconsulting.mg',          
        'http://www.viaconsulting.mg',
        'http://localhost:3000',            // pour dev
        'http://127.0.0.1:3000',            // pour dev
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // important si tu utilises des cookies ou tokens
    'supports_credentials' => true,
];
