<?php
// index.php
//
// Front controller / router simples baseado em ?page=
// Exemplo:
//   index.php?page=book
//   index.php?page=login
//   index.php?page=claim

// Página por omissão
$defaultPage = 'book';

// Lista de páginas permitidas (whitelist)
$routes = [
    'login'    => __DIR__ . '/pages/login.php',
    'register' => __DIR__ . '/pages/register.php',
    'book'     => __DIR__ . '/pages/book.php',   // app principal React
    'claim'    => __DIR__ . '/pages/claim.php',  // área de workers/skippers
];

// Ler o parâmetro ?page=...
$page = $_GET['page'] ?? $defaultPage;

// Se a página não estiver na whitelist, mostrar 404
if (!array_key_exists($page, $routes)) {
    http_response_code(404);

    $notFoundPage = __DIR__ . '/pages/404.php';

    if (file_exists($notFoundPage)) {
        require $notFoundPage;
    } else {
        // fallback simples se 404.php ainda não existir
        echo "<!DOCTYPE html>
<html lang=\"pt\">
<head>
  <meta charset=\"UTF-8\">
  <title>Página não encontrada</title>
</head>
<body style=\"background:#020617;color:#e5e7eb;font-family:sans-serif;\">
  <h1>404 - Página não encontrada</h1>
  <p>A página que tentou aceder não existe.</p>
  <p><a href=\"index.php\" style=\"color:#22d3ee;\">Voltar à página inicial</a></p>
<script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'99e10e66aec4c0bd',t:'MTc2MzA2NjEzMw=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script></body>
</html>";
    }

    exit;
}

// Incluir a página correspondente
require $routes[$page];
