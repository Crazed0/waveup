<?php
require __DIR__ . '/_common.php';

$trips = load_json_file('trips.json', []);

// ---------------- GET /api/trips.php?tripId=... ----------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['tripId'])) {
    $tripId = $_GET['tripId'];
    foreach ($trips as $t) {
        if (($t['id'] ?? '') === $tripId) {
            echo json_encode(['success' => true, 'trip' => $t]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Trip not found']);
    exit;
}

// ---------------- POST JSON ----------------
$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? null;

if ($action === 'cancel') {
    $tripId = $data['tripId'] ?? null;
    if (!$tripId) {
        echo json_encode(['success' => false, 'error' => 'Missing tripId']);
        exit;
    }

    $found = false;
    foreach ($trips as &$t) {
        if (($t['id'] ?? '') === $tripId) {
            $t['status'] = 'canceled'; // <-- FIX
            $found = true;
            break;
        }
    }
    unset($t);

    if ($found) {
        save_json_file('trips.json', $trips);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Trip not found']);
    }
    exit;
}

// -------- create --------
if ($action !== 'create') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Ação inválida. Usa action = "create".',
        'receivedAction' => $action,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user || (($user['type'] ?? '') !== 'customer')) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Precisas de sessão de cliente para criar viagens.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Extrair campos vindos do front ----
$embarkId        = $data['embarkId']        ?? null;
$embarkName      = $data['embarkName']      ?? null;
$embarkZone      = $data['embarkZone']      ?? '';
$embarkLat       = $data['embarkLat']       ?? null;
$embarkLng       = $data['embarkLng']       ?? null;

$destinationId   = $data['destinationId']   ?? null;
$destinationName = $data['destinationName'] ?? null;
$destinationZone = $data['destinationZone'] ?? '';
$destinationLat  = $data['destinationLat']  ?? null;
$destinationLng  = $data['destinationLng']  ?? null;

$categoryId      = $data['categoryId']      ?? null;
$categoryName    = $data['categoryName']    ?? '';

$distanceKm      = $data['distanceKm']               ?? null;
$durationMin     = $data['estimatedDurationMinutes'] ?? null;
$price           = $data['estimatedPrice']           ?? null;

// NOVO: hora de saída vinda do React (ISO string)
$departureAt     = $data['departureAt'] ?? null;
if (!is_string($departureAt) || $departureAt === '') {
    $departureAt = null;
}

// NOVO: rota vinda do React (array de pontos)
$routeRaw = $data['route'] ?? [];
if (!is_array($routeRaw)) {
    $routeRaw = [];
}
$route = [];
foreach ($routeRaw as $p) {
    if (!is_array($p)) continue;
    if (!isset($p['lat'], $p['lng'])) continue;

    $lat = (float)$p['lat'];
    $lng = (float)$p['lng'];

    // sanity check básico
    if (!is_finite($lat) || !is_finite($lng)) continue;

    $route[] = [
        'lat'         => $lat,
        'lng'         => $lng,
        'name'        => isset($p['name']) ? (string)$p['name'] : '',
        'stopMinutes' => isset($p['stopMinutes']) ? (int)$p['stopMinutes'] : 0,
    ];
}

// Cast numérico básico
$distanceKm  = is_null($distanceKm)  ? null : (float)$distanceKm;
$durationMin = is_null($durationMin) ? null : (int)$durationMin;
$price       = is_null($price)       ? null : (float)$price;

// ---- Validação mínima ----
$missing = [];

if ($embarkName === null || $embarkName === '') {
    $missing[] = 'embarkName';
}
if ($destinationName === null || $destinationName === '') {
    $missing[] = 'destinationName';
}
if ($categoryId === null || $categoryId === '') {
    $missing[] = 'categoryId';
}
if (!is_finite($distanceKm)  || $distanceKm  <= 0) {
    $missing[] = 'distanceKm';
}
if (!is_finite($durationMin) || $durationMin <= 0) {
    $missing[] = 'estimatedDurationMinutes';
}
if (!is_finite($price)       || $price       <= 0) {
    $missing[] = 'estimatedPrice';
}

if (!empty($missing)) {
    http_response_code(422);
    echo json_encode([
        'success'  => false,
        'error'    => 'Dados de viagem incompletos',
        'missing'  => $missing,
        'received' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- Carregar trips existentes ----
$trips = load_json_file('trips.json', []);
if (!is_array($trips)) {
    $trips = [];
}

$newTrip = [
    'id'                        => uniqid('trip_', true),

    'customerId'                => $user['id'],
    'customerName'              => $user['name'] ?? '',
    'customerPhone'             => $user['phone'] ?? '',
    'customerEmail'             => $user['email'] ?? '',

    'embarkId'                  => $embarkId,
    'embarkName'                => $embarkName,
    'embarkZone'                => $embarkZone,
    'embarkLat'                 => $embarkLat,
    'embarkLng'                 => $embarkLng,

    'destinationId'             => $destinationId,
    'destinationName'           => $destinationName,
    'destinationZone'           => $destinationZone,
    'destinationLat'            => $destinationLat,
    'destinationLng'            => $destinationLng,

    'categoryId'                => $categoryId,
    'categoryName'              => $categoryName,

    'distanceKm'                => $distanceKm,
    'estimatedDurationMinutes'  => $durationMin,
    'estimatedPrice'            => $price,

    // NOVO: rota calculada no book (network + ligações)
    'route'                     => $route,

    // NOVO: hora de saída (pode ser null se "agora")
    'departureAt'               => $departureAt,

    // campos de estado
    'status'                    => 'pending',
    'paymentStatus'             => 'paid-demo',
    'skipperId'                 => null,
    'skipperName'               => null,
    'skipperPhone'              => null,   // 👈 adicionar isto

    // para custos extra que o skipper possa vir a adicionar
    'extraCost'                 => 0,

    'createdAt'                 => date('c'),
];

$trips[] = $newTrip;
save_json_file('trips.json', $trips);

echo json_encode([
    'success' => true,
    'trip'    => $newTrip,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
