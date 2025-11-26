<?php
// api/routes.php
declare(strict_types=1);

require __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Helper para responder e sair.
 */
function json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$user = current_user();

// Se quiseres restringir:
if (!$user || ($user['type'] ?? '') !== 'admin') {
    json_response(403, ['success' => false, 'error' => 'Acesso negado']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        $routes = load_json_file('routes.json', []);
        if (!is_array($routes)) {
            $routes = [];
        }

        json_response(200, [
            'success' => true,
            'routes'  => $routes,
        ]);
        break;

    case 'POST':
        // Valida Content-Type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            json_response(415, [
                'success' => false,
                'error'   => 'Content-Type deve ser application/json',
            ]);
        }

        $raw = file_get_contents('php://input');
        if ($raw === false) {
            json_response(400, ['success' => false, 'error' => 'Corpo da requisição em falta']);
        }

        $input = json_decode($raw, true);
        if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
            json_response(400, [
                'success' => false,
                'error'   => 'JSON inválido: ' . json_last_error_msg(),
            ]);
        }

        if (!isset($input['routes']) || !is_array($input['routes'])) {
            json_response(400, [
                'success' => false,
                'error'   => 'Payload inválido: falta campo "routes"',
            ]);
        }

        $routes = [];
        foreach ($input['routes'] as $r) {
            if (!is_array($r)) {
                continue;
            }

            // valida campos obrigatórios
            if (
                !isset($r['id'], $r['name'], $r['type']) ||
                $r['id'] === '' ||
                $r['name'] === '' ||
                $r['type'] === ''
            ) {
                continue;
            }

            $points = $r['points'] ?? [];
            if (!is_array($points)) {
                $points = [];
            }

            $points = array_values(array_filter(
                $points,
                static function ($p): bool {
                    if (!is_array($p)) {
                        return false;
                    }
                    if (!isset($p['lat'], $p['lng'])) {
                        return false;
                    }
                    if (!is_numeric($p['lat']) || !is_numeric($p['lng'])) {
                        return false;
                    }

                    // podes endurecer aqui (lat/lng válidos)
                    $lat = (float) $p['lat'];
                    $lng = (float) $p['lng'];

                    return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
                }
            ));

            $routes[] = [
                'id'     => (string) $r['id'],
                'name'   => (string) $r['name'],
                'type'   => (string) $r['type'],
                'points' => $points,
                // se tiveres mais campos opcionais, copia-os aqui de forma controlada
            ];
        }

        if (!save_json_file('routes.json', $routes)) {
            json_response(500, [
                'success' => false,
                'error'   => 'Falha ao gravar routes.json',
            ]);
        }

        json_response(200, ['success' => true]);
        break;

    default:
        json_response(405, [
            'success' => false,
            'error'   => 'Método não permitido',
        ]);
}
