<?php
require __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');

$input  = json_decode(file_get_contents('php://input'), true);
$tripId = $input['tripId'] ?? null;

if (!$tripId) {
  echo json_encode(['success' => false, 'error' => 'tripId em falta']);
  exit;
}

$trips = load_json_file('trips.json', []);
if (!is_array($trips)) $trips = [];

$found = false;

foreach ($trips as &$t) {
  if (($t['id'] ?? '') !== $tripId) continue;

  // se já começou, não reiniciar (podes mudar isto se quiseres)
  if (!empty($t['simulationStartedAt'])) {
    $found = true;
    break;
  }

  // podes calcular stops on the fly, ou no tripRoute.php
  $stopsTotal = 0;
  if (!empty($t['route']) && is_array($t['route'])) {
    foreach ($t['route'] as $p) {
      $stopsTotal += (int)($p['stopMinutes'] ?? 0);
    }
  }

  $t['simulationStartedAt'] = gmdate('c');  // agora (UTC)
  $t['simulationSpeed']     = 3;           // 1s real = 1 minuto “simulado”
  $t['stopsTotalMinutes']   = $stopsTotal;
  $t['status']              = 'in-progress';

  $found = true;
  break;
}
unset($t);

if (!$found) {
  echo json_encode(['success' => false, 'error' => 'Viagem não encontrada']);
  exit;
}

save_json_file('trips.json', $trips);

echo json_encode(['success' => true]);
