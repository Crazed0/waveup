<?php
require __DIR__ . '/../api/_common.php';

$colors = get_theme_colors();
$logos  = get_logos();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Email e password são obrigatórios.';
    } else {
        $users = load_json_file('users.json', []);
        $found = null;
        foreach ($users as $u) {
            if (strtolower($u['email']) === $email) {
                $found = $u;
                break;
            }
        }
        if (!$found || !password_verify($password, $found['passwordHash'])) {
            $error = 'Credenciais inválidas.';
        } else {
            $_SESSION['user'] = $found;
            if (($found['type'] ?? '') === 'skipper') {
                header('Location: ./index.php?page=claim');
            } else {
                header('Location: ./index.php?page=book');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WaveUp</title>
  <link rel="icon" href="<?= htmlspecialchars($logos['favicon']) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/css/app9.css">
  <style>
    :root {
      --color-primary: <?= htmlspecialchars($colors['primary']) ?>;
      --color-secondary: <?= htmlspecialchars($colors['secondary']) ?>;
    }
  </style>
</head>
<body class="bg-primary">
  <div class="min-h-screen flex items-center justify-center p-4">
    <div class="waveup-card w-full max-w-md waveup-fade-in">
      
      <!-- Header com logo -->
      <div class="flex items-center gap-3 mb-8">
        <img src="<?= htmlspecialchars($logos['icon']) ?>" alt="WaveUp" class="w-12 h-12 md:w-14 md:h-14 rounded-lg" />
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold">WaveUp</h1>
          <p class="text-xs md:text-sm text-slate-400">YOUR RIDE IN THE WATER</p>
        </div>
      </div>

      <!-- Mensagem de erro -->
      <?php if ($error): ?>
        <div class="mb-6 text-sm md:text-base text-red-400 bg-red-950/40 border border-red-500/40 rounded-lg px-4 py-3">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <!-- Formulário -->
      <form method="post" class="space-y-5">
        <div>
          <label class="block text-sm md:text-base text-slate-300 mb-2">Email</label>
          <input
            type="email"
            name="email"
            required
            class="w-full rounded-lg bg-slate-900 border border-slate-700 px-4 py-3 md:py-3.5 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500 transition-all"
            placeholder="tu@email.com"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
          />
        </div>
        
        <div>
          <label class="block text-sm md:text-base text-slate-300 mb-2">Password</label>
          <input
            type="password"
            name="password"
            required
            class="w-full rounded-lg bg-slate-900 border border-slate-700 px-4 py-3 md:py-3.5 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500 transition-all"
            placeholder="••••••••"
          />
        </div>

        <button type="submit" class="waveup-btn w-full justify-center mt-6 py-3 md:py-3.5 text-base md:text-lg">
          Entrar
        </button>
      </form>

      <!-- Link de registro -->
      <p class="mt-6 text-sm md:text-base text-slate-400 text-center">
        Ainda não tens conta?
        <a href="./index.php?page=register" class="text-secondary underline hover:text-sky-400 transition-colors">
          Criar conta WaveUp
        </a>
      </p>
    </div>
  </div>
<script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a066a807fbd8df7',t:'MTc2MzQ1Nzg3OQ=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script>
<script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a09b3478e555816',t:'MTc2MzQ5MjMxNw=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script></body>
</html>