<?php
require __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');

$tripId = $_GET['tripId'] ?? null;
if (!$tripId) {
  echo json_encode(['success' => false, 'error' => 'tripId em falta']);
  exit;
}

$trips = load_json_file('trips.json', []);
if (!is_array($trips)) $trips = [];

$idx = null;
foreach ($trips as $i => $t) {
  if (($t['id'] ?? '') === $tripId) {
    $idx = $i;
    break;
  }
}

if ($idx === null) {
  echo json_encode(['success' => false, 'error' => 'Viagem não encontrada']);
  exit;
}

$trip = $trips[$idx];

/**
 * Helper para obter posição “estática” (antes / sem simulação)
 */
function firstTripPosition(array $trip): array {
  if (!empty($trip['route']) && is_array($trip['route'])) {
    $p = $trip['route'][0];
    if (isset($p['lat'], $p['lng'])) {
      return ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng']];
    }
  }

  if (isset($trip['embarkLat'], $trip['embarkLng'])) {
    return ['lat' => (float)$trip['embarkLat'], 'lng' => (float)$trip['embarkLng']];
  }

  // fallback qualquer
  return ['lat' => 38.72, 'lng' => -9.14];
}

/**
 * Haversine em km
 */
function haversineKm($aLat, $aLng, $bLat, $bLng): float {
  $R = 6371;
  $toRad = function ($deg) { return $deg * M_PI / 180; };

  $dLat = $toRad($bLat - $aLat);
  $dLon = $toRad($bLng - $aLng);

  $lat1 = $toRad($aLat);
  $lat2 = $toRad($bLat);

  $h = sin($dLat/2)**2 + cos($lat1)*cos($lat2)*sin($dLon/2)**2;
  $c = 2 * atan2(sqrt($h), sqrt(1-$h));
  return $R * $c;
}

/**
 * Devolve posição interpolada dada uma fracção [0,1] ao longo da rota.
 */
function interpolateRoutePosition(array $route, float $tNorm): array {
  $n = count($route);
  if ($n === 0) {
    return ['lat' => 38.72, 'lng' => -9.14];
  }
  if ($n === 1) {
    return ['lat' => (float)$route[0]['lat'], 'lng' => (float)$route[0]['lng']];
  }

  $tNorm = max(0.0, min(1.0, $tNorm));
  $totalSegments = $n - 1;

  $exactPos = $tNorm * $totalSegments;
  $segIdx   = (int) floor($exactPos);
  if ($segIdx >= $totalSegments) {
    $segIdx = $totalSegments - 1;
    $localT = 1.0;
  } else {
    $localT = $exactPos - $segIdx;
  }

  $a = $route[$segIdx];
  $b = $route[$segIdx + 1];

  $lat = (float)$a['lat'] + ((float)$b['lat'] - (float)$a['lat']) * $localT;
  $lng = (float)$a['lng'] + ((float)$b['lng'] - (float)$a['lng']) * $localT;

  return ['lat' => $lat, 'lng' => $lng];
}

// --- 1) se ainda não há simulação, devolver posição estática ---
if (empty($trip['simulationStartedAt'])) {
  $pos = firstTripPosition($trip);
  echo json_encode([
    'success'  => true,
    'position' => $pos,
    'status'   => $trip['status'] ?? 'pending',
  ]);
  exit;
}

// --- 2) calcular progresso da simulação ---
$startTs = strtotime($trip['simulationStartedAt']);
if ($startTs === false) {
  // data marada → não crashar, só devolve 0%
  $startTs = time();
}

$nowTs         = time();
$elapsedSec    = max(0, $nowTs - $startTs);
$simSpeed      = isset($trip['simulationSpeed']) ? (float)$trip['simulationSpeed'] : 60.0;
// interpretação: simSpeed = quantos MINUTOS simulados por cada segundo real
// logo 60 → 1s real = 60 min simulados
$elapsedSimMin = $elapsedSec * $simSpeed;

// garantir que tens duração total
$baseMinutes  = (int)($trip['estimatedDurationMinutes'] ?? 0);
$stopsMinutes = (int)($trip['stopsTotalMinutes']       ?? 0);
$totalMinutes = $baseMinutes + $stopsMinutes;

// se por algum motivo for 0 ou negativo, força 1 para não dar divisão por 0
if ($totalMinutes <= 0) {
  $totalMinutes = 1;
}

$progress = $elapsedSimMin / $totalMinutes;
$progress = max(0.0, min(1.0, $progress));

// --- 3) obter posição na rota ---
$route = (is_array($trip['route']) && count($trip['route']) >= 2)
  ? $trip['route']
  : [];

$pos = !empty($route)
  ? interpolateRoutePosition($route, $progress)
  : firstTripPosition($trip);

// --- 4) se chegou ao fim, marcar completed (uma vez) ---
$status = $trip['status'] ?? 'in-progress';

if ($progress >= 1.0 && $status !== 'completed') {
  $trips[$idx]['status'] = 'completed';
  $status = 'completed';
  save_json_file('trips.json', $trips);
}

// --- 5) devolver payload para o front ---
echo json_encode([
  'success'  => true,
  'position' => $pos,
  'status'   => $status,
]);
