<?php
require __DIR__ . '/_common.php';

$user = current_user();
if (!$user || ($user['type'] ?? '') !== 'skipper') {
  echo json_encode(['success' => false, 'error' => 'Not skipper']);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true);

$tripId = $body['tripId'] ?? null;
$lat    = $body['lat'] ?? null;
$lng    = $body['lng'] ?? null;

if (!$tripId || !$lat || !$lng) {
  echo json_encode(['success' => false, 'error' => 'Invalid payload']);
  exit;
}

$file = realpath(__DIR__ . '/../data/liveTrips.json');
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$data[$tripId] = [
  'lat' => $lat,
  'lng' => $lng,
  'updatedAt' => date('Y-m-d H:i:s'),
];

file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

echo json_encode(['success' => true]);
