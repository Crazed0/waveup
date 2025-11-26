<?php
require __DIR__ . '/../api/_common.php';
$colors = get_theme_colors();
$logos  = get_logos();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <title>WaveUp · 404</title>
  <link rel="icon" href="<?= htmlspecialchars($logos['favicon']) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="./css/app9.css" />
  <style>
    :root {
      --color-primary: <?= htmlspecialchars($colors['primary']) ?>;
      --color-secondary: <?= htmlspecialchars($colors['secondary']) ?>;
    }
  </style>
</head>
<body class="flex items-center justify-center min-h-screen bg-primary">
  <div class="waveup-card text-center">
    <h1 class="text-3xl font-bold mb-2">404 · Página não encontrada</h1>
    <p class="text-slate-400 mb-4">A página que procuras não existe na WaveUp.</p>
    <a href="/index.php?page=book" class="waveup-btn">Voltar à app</a>
  </div>
</body>
</html>
