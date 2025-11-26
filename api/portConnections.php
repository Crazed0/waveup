<?php
// api/portConnections.php
declare(strict_types=1);

require __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $ports          = load_json_file('embarkPoints.json', []);
    $licenseRoutes  = load_json_file('licenseRoutes.json', []);
    $connections    = load_json_file('portConnections.json', []);
    $licenseTypes   = load_json_file('licenseTypes.json', []);

    echo json_encode([
        'success'        => true,
        'embarkPoints'   => is_array($ports) ? $ports : [],
        'licenseRoutes'  => is_array($licenseRoutes) ? $licenseRoutes : [],
        'licenseTypes'   => is_array($licenseTypes) ? $licenseTypes : [],
        'connections'    => is_array($connections) ? $connections : [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input) || !isset($input['connections']) || !is_array($input['connections'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Payload inválido']);
        exit;
    }

    $connectionsOut = [];

    foreach ($input['connections'] as $c) {
        if (!is_array($c)) continue;
        if (!isset($c['portIndex'], $c['licenseId'])) continue;

        $item = [
            'portIndex'   => (int)$c['portIndex'],
            'portName'    => isset($c['portName']) ? (string)$c['portName'] : '',
            'licenseId'   => (string)$c['licenseId'],
            'licenseName' => isset($c['licenseName']) ? (string)$c['licenseName'] : '',
            'enabled'     => !empty($c['enabled']),
            'attachIndex' => isset($c['attachIndex']) ? (int)$c['attachIndex'] : null,
            'attachPoint' => null,
            'controlPoints' => [],
            'points'        => [],
        ];

        if (isset($c['attachPoint']) && is_array($c['attachPoint']) &&
            isset($c['attachPoint']['lat'], $c['attachPoint']['lng']) &&
            is_numeric($c['attachPoint']['lat']) && is_numeric($c['attachPoint']['lng'])
        ) {
            $item['attachPoint'] = [
                'lat' => (float)$c['attachPoint']['lat'],
                'lng' => (float)$c['attachPoint']['lng'],
            ];
        }

        if (isset($c['controlPoints']) && is_array($c['controlPoints'])) {
            foreach ($c['controlPoints'] as $p) {
                if (!is_array($p) || !isset($p['lat'], $p['lng'])) continue;
                if (!is_numeric($p['lat']) || !is_numeric($p['lng'])) continue;
                $item['controlPoints'][] = [
                    'lat' => (float)$p['lat'],
                    'lng' => (float)$p['lng'],
                ];
            }
        }

        if (isset($c['points']) && is_array($c['points'])) {
            foreach ($c['points'] as $p) {
                if (!is_array($p) || !isset($p['lat'], $p['lng'])) continue;
                if (!is_numeric($p['lat']) || !is_numeric($p['lng'])) continue;
                $item['points'][] = [
                    'lat' => (float)$p['lat'],
                    'lng' => (float)$p['lng'],
                ];
            }
        }

        $connectionsOut[] = $item;
    }

    if (!save_json_file('portConnections.json', $connectionsOut)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Falha ao gravar portConnections.json']);
        exit;
    }

    echo json_encode(['success' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
