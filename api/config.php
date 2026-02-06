<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'GuitarFix Pro',
        'env' => getenv('APP_ENV') ?: 'production',
        'base_url' => getenv('APP_BASE_URL') ?: 'https://your-domain.tld',
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'guitarfix_pro',
        'user' => getenv('DB_USER') ?: 'guitarfix_user',
        'pass' => getenv('DB_PASS') ?: 'change-me',
        'charset' => 'utf8mb4',
    ],
    'jwt' => [
        'secret' => getenv('JWT_SECRET') ?: 'change-me-in-env',
        'issuer' => getenv('JWT_ISSUER') ?: 'guitarfix-pro',
        'ttl_seconds' => (int) (getenv('JWT_TTL') ?: 3600),
    ],
    'cors' => [
        'allow_origin' => getenv('CORS_ALLOW_ORIGIN') ?: '*',
        'allow_headers' => 'Content-Type, Authorization',
        'allow_methods' => 'GET, POST, PUT, DELETE, OPTIONS',
    ],
];
