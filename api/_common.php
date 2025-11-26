<?php
// api/_common.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function data_dir(): string {
    return __DIR__ . '/../data';
}

function load_json_file(string $filename, $default = []) {
    $path = data_dir() . '/' . $filename;
    if (!file_exists($path)) {
        return $default;
    }

    $json = file_get_contents($path);
    if ($json === false || $json === '') {
        return $default;
    }

    $data = json_decode($json, true);
    return $data ?? $default;
}

function save_json_file(string $filename, $data): void {
    $dir = data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path    = $dir . '/' . $filename;
    $tmpPath = $path . '.tmp';

    $fp = fopen($tmpPath, 'c+');
    if (!$fp) {
        throw new RuntimeException('Não foi possível abrir ficheiro de dados: ' . $tmpPath);
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('Não foi possível bloquear o ficheiro de dados.');
    }

    ftruncate($fp, 0);
    rewind($fp);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    fwrite($fp, $json);

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    rename($tmpPath, $path);
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function generate_id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_auth(?string $type = null): array {
    $user = current_user();
    if (!$user) {
        json_response(['success' => false, 'error' => 'Não autenticado'], 401);
    }
    if ($type !== null && ($user['type'] ?? null) !== $type) {
        json_response(['success' => false, 'error' => 'Sem permissões'], 403);
    }
    return $user;
}

function get_theme_colors(): array {
    $colors = load_json_file('cores.json', [
        'primary'   => '#020617',
        'secondary' => '#0ea5e9',
    ]);
    return [
        'primary'   => $colors['primary'] ?? '#020617',
        'secondary' => $colors['secondary'] ?? '#0ea5e9',
    ];
}

function get_logos(): array {
    return load_json_file('logos.json', [
        'main'    => '/waveup.png',
        'icon'    => '/waveup.ico',
        'favicon' => '/waveup.ico',
    ]);
}
