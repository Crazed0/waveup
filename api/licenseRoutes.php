<?php
// api/licenseRoutes.php
declare(strict_types=1);

require __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Diretório real de /data (ao lado de /api)
$dataDir = realpath(__DIR__ . '/../data');
if ($dataDir === false || !is_dir($dataDir)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Pasta /data não encontrada a partir de api/.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$filePath = $dataDir . DIRECTORY_SEPARATOR . 'licenseRoutes.json';

if ($method === 'GET') {
    if (!file_exists($filePath)) {
        echo json_encode([
            'success' => true,
            'routes'  => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $json = file_get_contents($filePath);
    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Não foi possível ler licenseRoutes.json em ' . $filePath,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $routes = json_decode($json, true);
    if (!is_array($routes)) {
        $routes = [];
    }

    echo json_encode([
        'success' => true,
        'routes'  => $routes,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input) || !isset($input['routes']) || !is_array($input['routes'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Payload inválido (esperado { routes: [...] })',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $routes = [];

    foreach ($input['routes'] as $r) {
        if (!is_array($r)) continue;

        $licenseId = $r['licenseId'] ?? null;
        if (!$licenseId) continue;

        // normalizar controlPoints
        $control = array_values(array_filter(
            $r['controlPoints'] ?? [],
            static function ($p) {
                return is_array($p)
                    && isset($p['lat'], $p['lng'])
                    && is_numeric($p['lat'])
                    && is_numeric($p['lng']);
            }
        ));

        // normalizar points (já densificados vindos do React)
        $points = array_values(array_filter(
            $r['points'] ?? [],
            static function ($p) {
                return is_array($p)
                    && isset($p['lat'], $p['lng'])
                    && is_numeric($p['lat'])
                    && is_numeric($p['lng']);
            }
        ));

        $routes[] = [
            'licenseId'     => (string)$licenseId,
            'licenseName'   => (string)($r['licenseName'] ?? ''),
            'enabled'       => !empty($r['enabled']),
            'controlPoints' => $control,
            'points'        => $points,
        ];
    }

    // 1) codificar JSON com flags “amigas”
    $json = json_encode(
        $routes,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'json_encode falhou: ' . json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2) tentar gravar diretamente com file_put_contents
    $bytes = @file_put_contents($filePath, $json);

    if ($bytes === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'file_put_contents falhou em ' . $filePath .
                         ' (verifica permissões da pasta /data).',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode([
    'success' => false,
    'error'   => 'Método não permitido',
], JSON_UNESCAPED_UNICODE);
