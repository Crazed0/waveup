<?php
require __DIR__ . '/../api/_common.php';

$colors = get_theme_colors();
$logos  = get_logos();

// Carregar tipos de licença
$licenseTypesFile = __DIR__ . '/../data/licenseTypes.json';
$licenseTypes     = [];

if (file_exists($licenseTypesFile)) {
    $json = file_get_contents($licenseTypesFile);
    $licenseTypes = json_decode($json, true) ?: [];
}

// Carregar categorias para a tabela de referência
$categoriesFile = __DIR__ . '/../data/categories.json';
$categories = [];
if (file_exists($categoriesFile)) {
    $json = file_get_contents($categoriesFile);
    $categories = json_decode($json, true) ?: [];
}

$error = null;

// Caminho do ficheiro de utilizadores
$usersFile = __DIR__ . '/../data/users.json';

// Carregar utilizadores existentes
$users = file_exists($usersFile) ? json_decode(file_get_contents($usersFile), true) : [];

function save_users($usersFile, $users) {
  file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Extrair dados do POST
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password_confirmation'] ?? '';
    $type = $_POST['type'] ?? 'customer';
    $licenseType = trim($_POST['licenseType'] ?? '');
    $boatName = trim($_POST['boatName'] ?? '');
    $maxPassengers = $_POST['maxPassengers'] ?? '';

    // -------------------------------
    // 🔍 Validações básicas
    // -------------------------------
    if (empty($name) || empty($email) || empty($phone) || empty($password) || empty($password2)) {
        $error = "Preenche todos os campos obrigatórios.";
    }

    if (!$error && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Email inválido.";
    }

    if (!$error && $password !== $password2) {
        $error = "As passwords não coincidem.";
    }

    // Validações específicas para skipper
    if (!$error && $type === 'skipper') {
        if (empty($licenseType) || empty($boatName) || empty($maxPassengers)) {
            $error = "Preenche todos os campos obrigatórios de skipper.";
        }

        // Garantir número inteiro >= 1
        if (
            !$error &&
            !filter_var($maxPassengers, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        ) {
            $error = "Capacidade máxima de passageiros inválida. Deve ser um número inteiro maior ou igual a 1.";
        }
    }

    // Email duplicado
    if (!$error) {
        foreach ($users as $u) {
            if (strtolower($u['email']) === strtolower($email)) {
                $error = "Este email já está registado.";
                break;
            }
        }
    }

    // Se não houver erro, processar o registo
    if (!$error) {
        // Criar ID único
        $userId = 'u_' . bin2hex(random_bytes(8));

        // Hash seguro
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        // Upload do avatar
        $avatarPath = null;
        if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
            $avatarPath = "images/users/{$userId}." . strtolower($ext);
            move_uploaded_file($_FILES['avatar']['tmp_name'], __DIR__ . '/../' . $avatarPath);
        }

        // Upload da imagem do barco (apenas para skippers)
        $boatImagePath = null;
        if ($type === 'skipper' && !empty($_FILES['boatImage']['tmp_name']) && $_FILES['boatImage']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['boatImage']['name'], PATHINFO_EXTENSION);
            $boatImagePath = "images/boats/{$userId}." . strtolower($ext);
            move_uploaded_file($_FILES['boatImage']['tmp_name'], __DIR__ . '/../' . $boatImagePath);
        }

        // -------------------------------
        // Criar utilizador final
        // -------------------------------
        $newUser = [
            "id"           => $userId,
            "type"         => $type,
            "email"        => $email,
            "passwordHash" => $passwordHash,
            "name"         => $name,
            "phone"        => $phone,
            "licenseType"  => $type === 'skipper' ? $licenseType : null,
            "boatName"     => $type === 'skipper' ? $boatName : null,
            "maxPassengers"=> $type === 'skipper' ? (int)$maxPassengers : null,
            "avatar"       => $avatarPath,
            "boatImage"    => $boatImagePath,
            "rating"       => 5,
            "trips"        => 0,
            "createdAt"    => date('c'),
        ];

        // Guardar
        $users[] = $newUser;
        save_users($usersFile, $users);

        // Login automático
        $_SESSION['user'] = $newUser;

        // Redirecionar
        header("Location: ./index.php");
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WaveUp - Criar Conta</title>
  <link rel="icon" href="<?= htmlspecialchars($logos['favicon'] ?? '') ?>">
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
  <div class="min-h-screen flex items-center justify-center p-4 py-8 md:py-12">
    <div class="waveup-card w-full max-w-2xl waveup-fade-in">
      
      <!-- Header -->
      <div class="flex items-center gap-3 mb-6 md:mb-8">
        <img src="<?= htmlspecialchars($logos['icon']) ?>" alt="WaveUp" class="w-12 h-12 md:w-14 md:h-14 rounded-lg" />
        <div>
          <h1 class="text-xl md:text-2xl lg:text-3xl font-semibold">Criar conta WaveUp</h1>
          <p class="text-xs md:text-sm text-slate-400">Cliente ou Skipper — escolhe o teu papel.</p>
        </div>
      </div>

      <!-- Mensagem de erro -->
      <?php if ($error): ?>
        <div class="mb-4 md:mb-6 text-sm md:text-base text-red-400 bg-red-950/40 border border-red-500/40 rounded-lg px-3 md:px-4 py-2 md:py-3">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <!-- Formulário -->
      <form method="post" class="space-y-4 md:space-y-5" enctype="multipart/form-data">
        
        <!-- Nome e Telemóvel -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Nome <span class="text-red-400">*</span></label>
            <input
              type="text" name="name" required
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
              value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
            />
          </div>
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Telemóvel <span class="text-red-400">*</span></label>
            <input
              type="text" name="phone" required
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
              placeholder="+351 ..."
              value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
            />
          </div>
        </div>

        <!-- Foto de perfil -->
        <div>
          <label class="block text-sm md:text-base text-slate-300 mb-2">Foto de perfil (opcional)</label>
          <input
            type="file" name="avatar" accept="image/*"
            class="w-full text-sm md:text-base text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:bg-sky-500/10 file:text-sky-400 hover:file:bg-sky-500/20"
          />
          <p class="mt-1.5 text-xs md:text-sm text-slate-400">
            JPG, PNG ou WEBP até 2MB.
          </p>
        </div>

        <!-- Email e Tipo de conta -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Email <span class="text-red-400">*</span></label>
            <input
              type="email" name="email" required
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            />
          </div>
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Tipo de conta <span class="text-red-400">*</span></label>
            <?php $typeValue = $_POST['type'] ?? 'customer'; ?>
            <select
              name="type"
              id="type"
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
            >
              <option value="customer" <?= $typeValue === 'customer' ? 'selected' : '' ?>>Cliente</option>
              <option value="skipper" <?= $typeValue === 'skipper' ? 'selected' : '' ?>>Skipper</option>
            </select>
          </div>
        </div>

        <!-- Passwords -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Password <span class="text-red-400">*</span></label>
            <input
              type="password" name="password" required
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
            />
          </div>
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Confirmar password <span class="text-red-400">*</span></label>
            <input
              type="password" name="password_confirmation" required
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
            />
          </div>
        </div>

        <!-- Extra para skippers -->
        <div id="skipper-extra" class="hidden space-y-4 md:space-y-5 pt-4 border-t border-slate-800">
          
          <!-- Carta de navegação -->
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Carta / Licença de navegação <span class="text-red-400">*</span></label>
            <?php $licValue = $_POST['licenseType'] ?? ''; ?>
            <select
              name="licenseType"
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
            >
              <option value="">Seleciona a carta</option>
              <?php foreach ($licenseTypes as $lt): ?>
                <option
                  value="<?= htmlspecialchars($lt['id']) ?>"
                  <?= $licValue === ($lt['id'] ?? '') ? 'selected' : '' ?>
                >
                  <?= htmlspecialchars($lt['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1.5 text-xs md:text-sm text-slate-400">
              A tua carta define que categorias de barcos podes conduzir.
            </p>
          </div>

          <!-- Nome do barco -->
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Nome do barco <span class="text-red-400">*</span></label>
            <input
              type="text" name="boatName" required
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
              placeholder="Ex: Poseidon, WaveUp Mini #3..."
              value="<?= htmlspecialchars($_POST['boatName'] ?? '') ?>"
            />
            <p class="mt-1.5 text-xs md:text-sm text-slate-400">
              Este nome identifica o barco que vai aparecer aos clientes quando aceitares viagens.
            </p>
          </div>

          <!-- Capacidade -->
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Capacidade máxima de passageiros <span class="text-red-400">*</span></label>
            <input
              type="number"
              name="maxPassengers"
              min="1"
              step="1"
              required
              class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 md:px-4 py-2.5 md:py-3 text-sm md:text-base focus:outline-none focus:ring-2 focus:ring-sky-500"
              placeholder="Ex: 4, 8, 12..."
              value="<?= htmlspecialchars($_POST['maxPassengers'] ?? '') ?>"
            />
            <p class="mt-1.5 text-xs md:text-sm text-slate-400">
              Número máximo de passageiros que levas em segurança neste barco (excluindo skipper).
              Usamos isto para encaixar o teu barco na categoria WaveUp certa.
            </p>
          </div>

          <!-- Foto do barco -->
          <div>
            <label class="block text-sm md:text-base text-slate-300 mb-2">Foto do barco (opcional)</label>
            <input
              type="file" name="boatImage" accept="image/*"
              class="w-full text-sm md:text-base text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:bg-sky-500/10 file:text-sky-400 hover:file:bg-sky-500/20"
            />
            <p class="mt-1.5 text-xs md:text-sm text-slate-400">
              Será mostrada aos clientes nas viagens que aceitares. JPG/PNG/WEBP até 2MB.
            </p>
          </div>

          <!-- Tabela de referência das categorias -->
          <?php if (!empty($categories)): ?>
          <div class="border border-slate-800 rounded-lg p-3 md:p-4 bg-slate-950/60">
            <p class="text-xs md:text-sm text-slate-300 mb-3">
              Referência rápida das categorias WaveUp (capacidade &amp; alcance em milhas náuticas).
            </p>
            <div class="max-h-64 md:max-h-80 overflow-y-auto">
              <table class="w-full text-xs md:text-sm text-left">
                <thead class="text-slate-400 border-b border-slate-800">
                  <tr>
                    <th class="py-2 pr-2 md:pr-4">Categoria</th>
                    <th class="py-2 pr-2 md:pr-4">Capacidade</th>
                    <th class="py-2 pr-2 md:pr-4">Mín. recomendado</th>
                    <th class="py-2">Alcance (nm)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($categories as $cat): ?>
                    <?php
                      $cap = (int)($cat['capacity'] ?? 0);
                      $minPax = $cat['minPassengers'] ?? 1;
                      $maxNm = $cat['maxDistanceNm'] ?? null;
                    ?>
                    <tr class="border-b border-slate-900/70">
                      <td class="py-2 pr-2 md:pr-4 text-slate-100">
                        <?= htmlspecialchars($cat['name'] ?? $cat['id']) ?>
                      </td>
                      <td class="py-2 pr-2 md:pr-4">
                        até <?= $cap > 0 ? $cap : '?' ?> pax
                      </td>
                      <td class="py-2 pr-2 md:pr-4">
                        ≥ <?= $minPax ?> pax
                      </td>
                      <td class="py-2 text-slate-300">
                        <?= $maxNm !== null ? (float)$maxNm . ' nm' : '—' ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="mt-3 text-[10px] md:text-xs text-slate-500">
              Exemplo: se o teu barco leva 6 passageiros e tens carta adequada, encaixamos-te
              automaticamente na categoria cuja capacidade suporte esses 6 lugares
              (mini, comfort, executive, party, deliver, conforme a carta).
            </p>
          </div>
          <?php endif; ?>
        </div>

        <!-- Botão submit -->
        <button type="submit" class="waveup-btn w-full justify-center mt-6 py-3 md:py-3.5 text-base md:text-lg">
          Criar conta
        </button>
      </form>

      <!-- Link de login -->
      <p class="mt-6 text-sm md:text-base text-slate-400 text-center">
        Já tens conta?
        <a href="./index.php?page=login" class="text-secondary underline hover:text-sky-400 transition-colors">
          Fazer login
        </a>
      </p>
    </div>
  </div>

  <script>
    const typeSelect     = document.getElementById('type');
    const skipperExtraEl = document.getElementById('skipper-extra');

    function updateSkipperVisibility() {
      if (typeSelect.value === 'skipper') {
        skipperExtraEl.classList.remove('hidden');
        // Tornar campos obrigatórios visuais
        const requiredFields = skipperExtraEl.querySelectorAll('input[required], select[required]');
        requiredFields.forEach(field => {
          field.required = true;
        });
      } else {
        skipperExtraEl.classList.add('hidden');
        // Remover obrigatoriedade dos campos skipper quando não visíveis
        const skipperFields = skipperExtraEl.querySelectorAll('input, select');
        skipperFields.forEach(field => {
          field.required = false;
        });
      }
    }

    typeSelect.addEventListener('change', updateSkipperVisibility);
    updateSkipperVisibility();
  </script>
</body>
</html>