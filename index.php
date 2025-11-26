<?php
// index.php (root)
session_start();

$defaultPage = 'book';

$routes = [
    'login'    => __DIR__ . '/pages/login.php',
    'register' => __DIR__ . '/pages/register.php',
    'book'     => __DIR__ . '/pages/book.php',
    'claim'    => __DIR__ . '/pages/claim.php',
    'logout'   => __DIR__ . '/pages/logout.php',
      'loc'   => __DIR__ . '/pages/loc.php',
  'routes'   => __DIR__ . '/pages/routes.php',
  'port' => __DIR__ . '/pages/port-connections.php',
];

$page = $_GET['page'] ?? $defaultPage;

if (!array_key_exists($page, $routes)) {
    http_response_code(404);
    require __DIR__ . '/pages/404.php';
    exit;
}

require $routes[$page];
