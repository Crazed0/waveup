<?php
require __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sem sessão.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido.']);
    exit;
}

$tripId = $data['tripId'] ?? null;
$route  = $data['route']  ?? null;

if (!$tripId || !is_array($route)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'tripId ou rota em falta.']);
    exit;
}

// ---------- helpers geométricos simples ----------

function deg2radF(float $deg): float {
    return $deg * M_PI / 180;
}

function haversineMeters(array $a, array $b): float
{
    $R = 6371000.0; // m
    $lat1 = deg2radF($a['lat']);
    $lat2 = deg2radF($b['lat']);
    $dLat = deg2radF($b['lat'] - $a['lat']);
    $dLng = deg2radF($b['lng'] - $a['lng']);

    $h = sin($dLat / 2) ** 2 +
         cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

    return 2 * $R * atan2(sqrt($h), sqrt(1 - $h));
}

/**
 * Distância perpendicular de P ao segmento AB (em metros).
 * Se a projeção sair fora do segmento, devolve min(|AP|, |BP|).
 */
function perpendicularDistanceMeters(array $p, array $a, array $b): float
{
    // aproximação planar local (bom para distâncias curtas)
    $latScale = cos(deg2radF($p['lat']));
    $ax = $a['lng'] * $latScale;
    $ay = $a['lat'];
    $bx = $b['lng'] * $latScale;
    $by = $b['lat'];
    $px = $p['lng'] * $latScale;
    $py = $p['lat'];

    $vx = $bx - $ax;
    $vy = $by - $ay;

    $wx = $px - $ax;
    $wy = $py - $ay;

    $vLen2 = $vx * $vx + $vy * $vy;
    if ($vLen2 <= 0.0) {
        // A e B coincidentes
        return haversineMeters($p, $a);
    }

    // projeção escalar normalizada
    $t = ($wx * $vx + $wy * $vy) / $vLen2;

    if ($t < 0.0) {
        return haversineMeters($p, $a);
    }
    if ($t > 1.0) {
        return haversineMeters($p, $b);
    }

    $projX = $ax + $t * $vx;
    $projY = $ay + $t * $vy;

    $proj = ['lat' => $projY, 'lng' => $projX / $latScale];

    return haversineMeters($p, $proj);
}

/**
 * Suaviza pontos intermédios com média móvel.
 * - Não mexe no primeiro nem no último
 * - Não mexe em pontos com stopMinutes > 0 (anchors)
 */
function smoothRoute(array $route, int $windowSize = 5): array
{
    $n = count($route);
    if ($n <= 2 || $windowSize < 3) {
        return $route;
    }

    $half = intdiv($windowSize, 2);
    $out  = $route;

    for ($i = 1; $i < $n - 1; $i++) {
        if (($route[$i]['stopMinutes'] ?? 0) > 0) {
            continue; // ponto de paragem fica fixo
        }

        $sumLat = 0.0;
        $sumLng = 0.0;
        $count  = 0;

        for ($j = $i - $half; $j <= $i + $half; $j++) {
            if ($j < 0 || $j >= $n) {
                continue;
            }
            $sumLat += $route[$j]['lat'];
            $sumLng += $route[$j]['lng'];
            $count++;
        }

        if ($count > 0) {
            $out[$i]['lat'] = $sumLat / $count;
            $out[$i]['lng'] = $sumLng / $count;
        }
    }

    return $out;
}

/**
 * Remove pontos intermédios cuja curva é “pequena demais”.
 * thresholdMeters ~ 50m por omissão (podes ajustar).
 * Não remove:
 * - primeiro e último
 * - pontos com stopMinutes > 0
 */
function simplifyRoute(array $route, float $thresholdMeters = 50.0): array
{
    $n = count($route);
    if ($n <= 2) {
        return $route;
    }

    $keep = [];
    $keep[] = $route[0]; // primeiro

    for ($i = 1; $i < $n - 1; $i++) {
        $p = $route[$i];

        // anchors não se mexem
        if (($p['stopMinutes'] ?? 0) > 0) {
            $keep[] = $p;
            continue;
        }

        $a = $route[$i - 1];
        $b = $route[$i + 1];

        $dist = perpendicularDistanceMeters(
            ['lat' => $p['lat'], 'lng' => $p['lng']],
            ['lat' => $a['lat'], 'lng' => $a['lng']],
            ['lat' => $b['lat'], 'lng' => $b['lng']]
        );

        // se está muito perto da linha AB, é “cotovelo” desnecessário -> remove
        if ($dist >= $thresholdMeters) {
            $keep[] = $p;
        }
    }

    $keep[] = $route[$n - 1]; // último
    return $keep;
}

// ---------- carregar trips ----------

$trips = load_json_file('trips.json', []);
if (!is_array($trips)) {
    $trips = [];
}

$found = false;

foreach ($trips as &$t) {
    if (($t['id'] ?? '') === $tripId) {
        // só o skipper dono pode editar
        if (($t['skipperId'] ?? null) !== $user['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Não podes editar rota desta viagem.']);
            exit;
        }

        // limpar rota vinda do front
        $cleanRoute = [];
        foreach ($route as $p) {
            if (!isset($p['lat'], $p['lng'])) {
                continue;
            }
            $cleanRoute[] = [
                'lat'         => (float)$p['lat'],
                'lng'         => (float)$p['lng'],
                'stopMinutes' => isset($p['stopMinutes']) ? (int)$p['stopMinutes'] : 0,
                'name'        => isset($p['name']) ? (string)$p['name'] : '',
            ];
        }

        if (count($cleanRoute) < 2) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Rota precisa de pelo menos dois pontos.']);
            exit;
        }

        // 1) suavizar
        $smoothed = smoothRoute($cleanRoute, 5);      // média móvel

        // 2) simplificar removendo micro-cotovelos
        $optimized = simplifyRoute($smoothed, 50.0);  // 50m; afina à vontade

        $t['route'] = $optimized;
        $found      = true;
        break;
    }
}
unset($t);

if (!$found) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Viagem não encontrada.']);
    exit;
}

save_json_file('trips.json', $trips);

echo json_encode(['success' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
