<?php
// api/auth.php
require __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_response(['success' => false, 'error' => 'JSON inválido'], 400);
}

$action = $input['action'] ?? null;

if ($action === 'login') {
    $email    = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if ($email === '' || $password === '') {
        json_response(['success' => false, 'error' => 'Email e password são obrigatórios'], 422);
    }

    $users = load_json_file('users.json', []);

    $found = null;
    foreach ($users as $u) {
        if (strtolower($u['email']) === $email) {
            $found = $u;
            break;
        }
    }

    if (!$found || !password_verify($password, $found['passwordHash'])) {
        json_response(['success' => false, 'error' => 'Credenciais inválidas'], 401);
    }

    $_SESSION['user'] = $found;

    json_response([
        'success' => true,
        'user'    => [
            'id'          => $found['id'],
            'name'        => $found['name'],
            'email'       => $found['email'],
            'type'        => $found['type'],
            'licenseType' => $found['licenseType'] ?? null,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

json_response(['success' => false, 'error' => 'Ação inválida'], 400);
