<?php
// api/landZones.php
require __DIR__ . '/_common.php';

// OPCIONAL: só admins
// $user = current_user();
// if (!$user || ($user['type'] ?? '') !== 'admin') {
//     http_response_code(403);
//     echo json_encode(['success' => false, 'error' => 'Forbidden']);
//     exit;
// }

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $zones = load_json_file('landZones.json', []);
    echo json_encode([
        'success' => true,
        'zones'   => $zones,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    // validação básica
    foreach ($data as $zone) {
        if (!isset($zone['id'], $zone['name'], $zone['points']) || !is_array($zone['points'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid zone format']);
            exit;
        }
    }

    save_json_file('landZones.json', $data);

    echo json_encode(['success' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
