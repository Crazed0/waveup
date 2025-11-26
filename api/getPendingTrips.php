<?php
require __DIR__ . '/_common.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user || ($user['type'] ?? '') !== 'skipper') {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

// Carregar dados necessários
$trips = load_json_file('trips.json', []);
$categories = load_json_file('categories.json', []);
$licenseTypes = load_json_file('licenseTypes.json', []);

// Determinar categorias permitidas
$allowedCategories = [];
$userLicenseId = $user['licenseType'] ?? null;

if ($userLicenseId) {
    foreach ($licenseTypes as $lt) {
        if (($lt['id'] ?? null) === $userLicenseId) {
            $allowedCategories = $lt['allowedCategories'] ?? [];
            break;
        }
    }
}

// Filtrar viagens pendentes compatíveis
$pendingTrips = array_values(array_filter(
    $trips,
    function ($t) use ($allowedCategories) {
        return ($t['status'] ?? '') === 'pending'
            && ($t['paymentStatus'] ?? '') === 'paid-demo'
            && in_array($t['categoryId'] ?? '', $allowedCategories, true);
    }
));

// Função auxiliar para obter nome da categoria
function get_category_name($categories, $categoryId) {
    foreach ($categories as $cat) {
        if (($cat['id'] ?? '') === $categoryId) {
            return $cat['name'] ?? $categoryId;
        }
    }
    return $categoryId;
}

// Preparar resposta
$responseTrips = [];
foreach ($pendingTrips as $trip) {
    $responseTrips[] = [
        'id' => $trip['id'] ?? '',
        'embarkName' => $trip['embarkName'] ?? '',
        'destinationName' => $trip['destinationName'] ?? '',
        'customerName' => $trip['customerName'] ?? '',
        'customerPhone' => $trip['customerPhone'] ?? '',
        'distanceKm' => $trip['distanceKm'] ?? 0,
        'estimatedDurationMinutes' => $trip['estimatedDurationMinutes'] ?? 0,
        'estimatedPrice' => $trip['estimatedPrice'] ?? 0,
        'categoryId' => $trip['categoryId'] ?? '',
        'categoryName' => get_category_name($categories, $trip['categoryId'] ?? ''),
        'createdAt' => $trip['createdAt'] ?? ''
    ];
}

echo json_encode([
    'success' => true,
    'count' => count($responseTrips),
    'trips' => $responseTrips
]);