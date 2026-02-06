<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . $config['cors']['allow_origin']);
header('Access-Control-Allow-Headers: ' . $config['cors']['allow_headers']);
header('Access-Control-Allow-Methods: ' . $config['cors']['allow_methods']);

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['port'],
    $config['db']['name'],
    $config['db']['charset']
);

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'detail' => $exception->getMessage()]);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/engine.php';

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');
$path = preg_replace('#^api/#', '', $path);
$segments = $path === '' ? [] : explode('/', $path);

$context = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'segments' => $segments,
    'pdo' => $pdo,
    'config' => $config,
];

if (($segments[0] ?? '') === 'auth') {
    auth_handle($context);
}

engine_handle($context);
