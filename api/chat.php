<?php
require __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');

$chatsDir = realpath(__DIR__ . '/../chats');
if (!$chatsDir) {
    // tentar criar pasta
    $tryDir = __DIR__ . '/../chats';
    if (!is_dir($tryDir)) {
        mkdir($tryDir, 0775, true);
    }
    $chatsDir = realpath($tryDir);
}

if (!$chatsDir) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Pasta de chats não disponível.']);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Precisas de sessão.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $tripId = $_GET['tripId'] ?? null;
    if (!$tripId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'tripId em falta.']);
        exit;
    }

    $file = $chatsDir . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $tripId) . '.json';
    if (!file_exists($file)) {
        echo json_encode(['success' => true, 'messages' => []]);
        exit;
    }

    $raw = file_get_contents($file);
    $msgs = json_decode($raw, true);
    if (!is_array($msgs)) $msgs = [];

    echo json_encode(['success' => true, 'messages' => $msgs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON inválido.']);
        exit;
    }

    $tripId     = $data['tripId']     ?? null;
    $senderId   = $data['senderId']   ?? $user['id'];
    $senderName = $data['senderName'] ?? ($user['name'] ?? 'User');
    $text       = trim($data['text'] ?? '');

    if (!$tripId || $text === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Dados de chat em falta.']);
        exit;
    }

    $file = $chatsDir . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $tripId) . '.json';

    $msgs = [];
    if (file_exists($file)) {
        $rawOld = file_get_contents($file);
        $msgs = json_decode($rawOld, true);
        if (!is_array($msgs)) $msgs = [];
    }

    $msgs[] = [
        'id'         => uniqid('msg_', true),
        'tripId'     => $tripId,
        'senderId'   => $senderId,
        'senderName' => $senderName,
        'text'       => $text,
        'createdAt'  => date('c'),
    ];

    file_put_contents($file, json_encode($msgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    echo json_encode(['success' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
