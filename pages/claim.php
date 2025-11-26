<?php
require __DIR__ . '/../api/_common.php';

$user = current_user();
if (!$user) {
  header('Location: ./index.php?page=login');
  exit;
}

if (($user['type'] ?? '') !== 'skipper') {
  header('Location: ./index.php?page=book');
  exit;
}

$colors       = get_theme_colors();
$logos        = get_logos();
$trips        = load_json_file('trips.json', []);
$categories   = load_json_file('categories.json', []);
$points       = load_json_file('embarkPoints.json', []);
$licenseTypes = load_json_file('licenseTypes.json', []);

// ----------------- Carta & categorias permitidas -----------------
$allowedCategories = [];
$userLicenseId     = $user['licenseType'] ?? null;
$licenseName       = 'Sem carta configurada';

if ($userLicenseId) {
  foreach ($licenseTypes as $lt) {
    if (($lt['id'] ?? null) === $userLicenseId) {
      $licenseName       = $lt['name'] ?? $licenseName;
      $allowedCategories = $lt['allowedCategories'] ?? [];
      break;
    }
  }
}

// Small helper para obter nome da categoria
function category_name(array $categories, string $id): string
{
  foreach ($categories as $c) {
    if (($c['id'] ?? '') === $id) {
      return $c['name'] ?? $id;
    }
  }
  return $id;
}

// ----------------- Claim via POST -----------------
$message    = null;
$error      = null;
$activeTrip = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_trip_id'])) {
  $tripId = $_POST['claim_trip_id'];

  $foundIdx = null;
  foreach ($trips as $idx => $t) {
    if (($t['id'] ?? '') === $tripId) {
      $foundIdx = $idx;
      break;
    }
  }

  if ($foundIdx === null) {
    $error = 'Viagem não encontrada.';
  } else {
    $t = $trips[$foundIdx];

    if (($t['status'] ?? '') !== 'pending') {
      $error = 'Esta viagem já não está pendente.';
    } elseif (($t['paymentStatus'] ?? '') !== 'paid-demo') {
      $error = 'Esta viagem ainda não tem pagamento demo concluído.';
    } elseif (!in_array($t['categoryId'] ?? '', $allowedCategories, true)) {
      $error = 'A tua carta não permite esta categoria.';
    } else {
      $trips[$foundIdx]['status']      = 'assigned';
      $trips[$foundIdx]['skipperId']   = $user['id'];
      $trips[$foundIdx]['skipperName'] = $user['name'];

      // info do skipper para o cliente ver depois
      $trips[$foundIdx]['skipperBoatName']  = $user['boatName']  ?? null;
      $trips[$foundIdx]['skipperAvatar']    = $user['avatar']    ?? null;
      $trips[$foundIdx]['skipperBoatImage'] = $user['boatImage'] ?? null;

      // inicializar rota simples (direta) se ainda não existir
      if (
        empty($trips[$foundIdx]['route']) &&
        isset(
          $trips[$foundIdx]['embarkLat'],
          $trips[$foundIdx]['embarkLng'],
          $trips[$foundIdx]['destinationLat'],
          $trips[$foundIdx]['destinationLng']
        )
      ) {
        $trips[$foundIdx]['route'] = [
          [
            'lat'         => $trips[$foundIdx]['embarkLat'],
            'lng'         => $trips[$foundIdx]['embarkLng'],
            'stopMinutes' => 0,
            'name'        => 'Embarque',
          ],
          [
            'lat'         => $trips[$foundIdx]['destinationLat'],
            'lng'         => $trips[$foundIdx]['destinationLng'],
            'stopMinutes' => 0,
            'name'        => 'Destino',
          ]
        ];
      }
      save_json_file('trips.json', $trips);
      $message    = 'Viagem atribuída a ti com sucesso ✅';
      $activeTrip = $trips[$foundIdx];
    }
  }
}

// Se não houve claim agora, tentar apanhar uma viagem já atribuída (em curso)
if (!$activeTrip) {

  foreach ($trips as $t) {
    $status = $t['status'] ?? '';

    // apenas "assigned" e "in-progress" contam como viagem ativa
    if (
      ($t['skipperId'] ?? null) === $user['id'] &&
      in_array($status, ['assigned', 'in-progress'], true)
    ) {
      $activeTrip = $t;
      break;
    }
  }
}


// descobrir categoria ativa (para pricing / limites)
$activeCategory = null;
if ($activeTrip) {
  foreach ($categories as $c) {
    if (($c['id'] ?? null) === ($activeTrip['categoryId'] ?? null)) {
      $activeCategory = $c;
      break;
    }
  }
}

$pricingInfo = [
  'pricePerKm'          => $activeCategory['pricePerKm'] ?? 0,
  'pricePerMinute'      => $activeCategory['pricePerMinute'] ?? 0,
  'maxLegNauticalMiles' => $activeCategory['maxLegNauticalMiles'] ?? null,
];

// preparar ports para JS (podes usar no futuro p/ heurísticas, mas já não é obrigatório)
$portsForJs = [];
foreach ($points as $p) {
  if (isset($p['lat'], $p['lng'])) {
    $portsForJs[] = [
      'lat'  => (float)$p['lat'],
      'lng'  => (float)$p['lng'],
      'name' => $p['name'] ?? 'Porto',
    ];
  }
}

// ----------------- Trips pendentes compatíveis -----------------
$pendingTrips = array_values(array_filter(
  $trips,
  function ($t) use ($allowedCategories) {
    return ($t['status'] ?? '') === 'pending'
      && ($t['paymentStatus'] ?? '') === 'paid-demo'
      && in_array($t['categoryId'] ?? '', $allowedCategories, true);
  }
));
?>
<!DOCTYPE html>
<html lang="pt">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WaveUp · Área de Skippers</title>
  <link rel="icon" href="<?= htmlspecialchars($logos['favicon']) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet"
      href="/css/app9.css">
  <!-- Leaflet para o mapa -->
  <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
    crossorigin="" />
  <style>
    :root {
      --color-primary: <?= htmlspecialchars($colors['primary']) ?>;
      --color-secondary: <?= htmlspecialchars($colors['secondary']) ?>;
    }

    #trip-map {
      width: 100%;
      height: 260px;
      border-radius: 0.75rem;
      overflow: hidden;
    }
    
    /* Loading indicator para atualizações */
    .refresh-loading {
      opacity: 0.7;
      pointer-events: none;
    }
    
    .refresh-spinner {
      display: inline-block;
      width: 12px;
      height: 12px;
      border: 2px solid #ffffff;
      border-radius: 50%;
      border-top-color: transparent;
      animation: spin 1s ease-in-out infinite;
    }
    
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  </style>
</head>

<body class="bg-primary min-h-screen flex flex-col">
  <header class="border-b border-slate-800/80 bg-slate-950/80 backdrop-blur">
    <div class="waveup-shell flex items-center justify-between gap-4 py-3">
      <div class="flex items-center gap-3">
        <img src="<?= htmlspecialchars($logos['icon']) ?>" alt="WaveUp" class="w-9 h-9 rounded-lg" />
        <div>
          <div class="flex items-center gap-2">
            <h1 class="text-lg font-semibold">WaveUp Skippers</h1>
            <span class="waveup-badge">
              <span class="text-secondary text-[8px]">●</span>
              Live
            </span>
          </div>
          <p class="text-[11px] text-slate-400">
            Pedidos de viagem filtrados pela tua carta.
          </p>
        </div>
      </div>
      <div class="flex items-center gap-3 text-xs text-slate-300">
        <div class="text-right hidden sm:block">
          <div class="font-medium"><?= htmlspecialchars($user['name']) ?></div>
          <div class="text-[11px] text-slate-400">
            <?= htmlspecialchars($licenseName) ?>
          </div>
        </div>
        <a href="./index.php?page=logout" class="waveup-btn-outline px-3 py-1 text-[11px]">
          Sair
        </a>
      </div>
    </div>
  </header>

  <main class="flex-1">
    <div class="waveup-shell py-6 space-y-4">

<?php if ($activeTrip): ?>
  <div id="active-trip-card" class="waveup-card">
    <h3 class="text-sm font-semibold mb-2">Viagem em curso</h3>

          <div class="grid md:grid-cols-[2fr,1.4fr] gap-4">
            <div>
              <div id="trip-map"></div>
              <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400">
                <div>
                  <span id="sim-time-label">Simulação parada</span><br>
                  <span id="extra-cost-label" class="text-amber-300"></span>
                </div>
                <div class="flex gap-2">
                  <button id="btn-start-sim" class="waveup-btn px-3 py-1 text-[11px]">
                    Iniciar simulação
                  </button>
                  <button id="btn-stop-sim" class="waveup-btn-outline px-3 py-1 text-[11px]">
                    Parar
                  </button>

                </div>
              </div>
            </div>

            <div class="flex flex-col gap-3 text-xs text-slate-300">
              <div>
                <h4 class="font-semibold mb-1">Detalhes rápidos</h4>
                <p><?= htmlspecialchars($activeTrip['embarkName']) ?> → <?= htmlspecialchars($activeTrip['destinationName']) ?></p>
                <p class="text-slate-400">
                  <?= number_format((float)$activeTrip['distanceKm'], 1) ?> km ·
                  ~<?= (int)$activeTrip['estimatedDurationMinutes'] ?> min ·
                  <?= number_format((float)$activeTrip['estimatedPrice'], 2) ?> €
                </p>
              </div>

              <div class="border border-slate-700 rounded-lg p-2 flex-1 flex flex-col">
                <div class="flex items-center justify-between mb-1">
                  <h4 class="font-semibold text-xs">Chat com o cliente</h4>
                  <span class="text-[10px] text-slate-500">
                    Trip ID: <?= htmlspecialchars($activeTrip['id']) ?>
                  </span>
                </div>
                <div id="chat-messages" class="flex-1 overflow-y-auto min-h-[200px] h-[200px] text-[11px] space-y-1 border border-slate-800 rounded-md p-2 bg-slate-950/60">
                  <!-- mensagens via JS -->
                </div>
                <form id="chat-form" class="mt-2 flex gap-2 text-[11px]">
                  <input
                    type="text"
                    id="chat-input"
                    class="flex-1 bg-slate-950 border border-slate-700 rounded-md px-2 py-1 focus:outline-none focus:ring-1 focus:ring-sky-500"
                    placeholder="Escrever mensagem..."
                    autocomplete="off" />
                  <button type="submit" class="waveup-btn px-3 py-1">
                    Enviar
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div id="trips-card" class="waveup-card"<?php if ($activeTrip) echo ' style="display:none"'; ?>>
        <div class="flex items-center justify-between mb-3">
          <div>
            <h2 class="text-sm font-semibold">Viagens disponíveis</h2>
            <p class="text-xs text-slate-400">
              Só vês pedidos cuja categoria é permitida pela tua carta. Ao reclamar,
              o pedido deixa de aparecer para outros skippers.
            </p>
          </div>
          <div class="hidden md:flex flex-col items-end gap-1 text-[11px] text-slate-400">
            <span>Pedidos pendentes:</span>
            <span id="pending-count" class="text-secondary font-semibold text-xs">
              <?= count($pendingTrips) ?>
            </span>
          </div>
        </div>

        <?php if (empty($allowedCategories)): ?>
          <div class="mb-3 waveup-alert-error">
            A tua conta de skipper não tem categorias associadas à carta.
            Fala com o administrador para configurar <code>licenseType</code> e <code>licenseTypes.json</code>.
          </div>
        <?php endif; ?>

        <?php if ($message): ?>
          <div class="mb-3 waveup-alert-success">
            <?= htmlspecialchars($message) ?>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="mb-3 waveup-alert-error">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <div id="trips-container">
          <?php if (empty($pendingTrips)): ?>
            <p class="text-xs text-slate-400">
              Neste momento não há viagens pendentes compatíveis com a tua carta.
            </p>
          <?php else: ?>
            <div class="space-y-3">
              <?php foreach ($pendingTrips as $trip): ?>
                <div class="border border-slate-700 rounded-lg px-3 py-2 text-xs bg-slate-950/60">
                  <div class="flex justify-between gap-2">
                    <div>
                      <div class="font-medium text-slate-100">
                        <?= htmlspecialchars($trip['embarkName']) ?> →
                        <?= htmlspecialchars($trip['destinationName']) ?>
                      </div>
                      <div class="text-[11px] text-slate-400">
                        Cliente: <?= htmlspecialchars($trip['customerName']) ?>
                        <?php if (!empty($trip['customerPhone'])): ?>
                          · <?= htmlspecialchars($trip['customerPhone']) ?>
                        <?php endif; ?>
                      </div>
                      <div class="text-[11px] text-slate-500 mt-0.5">
                        Pedido em <?= htmlspecialchars(
                                    date('d/m/Y H:i', strtotime($trip['createdAt'] ?? 'now'))
                                  ) ?>
                      </div>
                    </div>
                    <div class="text-right">
                      <div class="waveup-chip text-[10px] text-slate-300">
                        <?= htmlspecialchars(category_name($categories, $trip['categoryId'])) ?>
                      </div>
                      <div class="text-[11px] text-slate-400 mt-1">
                        <?= number_format((float)$trip['distanceKm'], 1) ?> km ·
                        ~<?= (int)$trip['estimatedDurationMinutes'] ?> min
                      </div>
                      <div class="text-[11px] text-secondary font-semibold">
                        <?= number_format((float)$trip['estimatedPrice'], 2) ?> €
                      </div>
                    </div>
                  </div>
                  <form method="post" class="mt-2 flex justify-end">
                    <input type="hidden" name="claim_trip_id" value="<?= htmlspecialchars($trip['id']) ?>" />
                    <button type="submit" class="waveup-btn px-3 py-1 text-[11px]">
                      Aceitar viagem
                    </button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin="">
  </script>

  <script>
    // Função para atualizar a lista de viagens
    function refreshTripsList() {
      const tripsContainer = document.getElementById('trips-container');
      const pendingCount = document.getElementById('pending-count');
      
      if (!tripsContainer) return;
    
      
      // Adicionar classe de loading ao container
      tripsContainer.classList.add('refresh-loading');
      
      fetch('./api/getPendingTrips.php')
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Atualizar contador
            if (pendingCount) {
              pendingCount.textContent = data.count;
            }
            
            // Atualizar conteúdo
            if (data.count === 0) {
              tripsContainer.innerHTML = '<p class="text-xs text-slate-400">Neste momento não há viagens pendentes compatíveis com a tua carta.</p>';
            } else {
              let html = '<div class="space-y-3">';
              data.trips.forEach(trip => {
                html += `
                  <div class="border border-slate-700 rounded-lg px-3 py-2 text-xs bg-slate-950/60">
                    <div class="flex justify-between gap-2">
                      <div>
                        <div class="font-medium text-slate-100">
                          ${trip.embarkName} → ${trip.destinationName}
                        </div>
                        <div class="text-[11px] text-slate-400">
                          Cliente: ${trip.customerName}
                          ${trip.customerPhone ? ' · ' + trip.customerPhone : ''}
                        </div>
                        <div class="text-[11px] text-slate-500 mt-0.5">
                          Pedido em ${new Date(trip.createdAt).toLocaleString('pt-PT')}
                        </div>
                      </div>
                      <div class="text-right">
                        <div class="waveup-chip text-[10px] text-slate-300">
                          ${trip.categoryName}
                        </div>
                        <div class="text-[11px] text-slate-400 mt-1">
                          ${parseFloat(trip.distanceKm).toFixed(1)} km ·
                          ~${parseInt(trip.estimatedDurationMinutes)} min
                        </div>
                        <div class="text-[11px] text-secondary font-semibold">
                          ${parseFloat(trip.estimatedPrice).toFixed(2)} €
                        </div>
                      </div>
                    </div>
                    <form method="post" class="mt-2 flex justify-end">
                      <input type="hidden" name="claim_trip_id" value="${trip.id}" />
                      <button type="submit" class="waveup-btn px-3 py-1 text-[11px]">
                        Aceitar viagem
                      </button>
                    </form>
                  </div>
                `;
              });
              html += '</div>';
              tripsContainer.innerHTML = html;
            }
          } else {
            console.error('Erro ao carregar viagens:', data.error);
          }
        })
        .catch(error => {
          console.error('Erro na atualização:', error);
        })
        .finally(() => {
          tripsContainer.classList.remove('refresh-loading');
        });
    }

    // Atualizar a cada segundo
    setInterval(refreshTripsList, 1000);

    // Atualizar imediatamente ao carregar a página
    document.addEventListener('DOMContentLoaded', function() {
      refreshTripsList();
    });
  </script>

  <?php if ($activeTrip): ?>
    <script>
      window.waveupActiveTrip  = <?= json_encode($activeTrip, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      window.waveupCurrentUser = <?= json_encode([
        'id'   => $user['id'],
        'name' => $user['name'] ?? 'Skipper'
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      window.waveupPricing     = <?= json_encode($pricingInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      window.waveupPorts       = <?= json_encode($portsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
<script>
  (function() {
    const trip     = window.waveupActiveTrip;
    const pricing  = window.waveupPricing || { pricePerKm: 0, pricePerMinute: 0, maxLegNauticalMiles: null };
    const ports    = window.waveupPorts || [];

    if (!trip || typeof L === "undefined") return;

    function haversineKm(a, b) {
      const R = 6371;
      const toRad = deg => deg * Math.PI / 180;
      const dLat = toRad(b.lat - a.lat);
      const dLon = toRad(b.lng - a.lng);
      const lat1 = toRad(a.lat);
      const lat2 = toRad(b.lat);

      const h = Math.sin(dLat/2) ** 2 +
                Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon/2) ** 2;
      const c = 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1-h));
      return R * c;
    }

    // --- API is-on-water ---
    async function isPointWaterApi(lat, lng) {
      const url = `https://is-on-water.balbona.me/api/v1/get/${lat}/${lng}`;
      const res = await fetch(url);
      if (!res.ok) {
        throw new Error("Water API HTTP " + res.status);
      }
      const data = await res.json();
      return !!data.isWater;
    }

    async function validateRouteWaterApi(route) {
      if (!Array.isArray(route) || route.length < 2) {
        return { ok: false, message: "Rota inválida (menos de 2 pontos)." };
      }

      const samplesPerSegment = 3;

      if (route.length > 2) {
        for (let i = 1; i < route.length - 1; i++) {
          const p = route[i];
          const isWater = await isPointWaterApi(p.lat, p.lng);
          if (!isWater) {
            return {
              ok: false,
              message: `O ponto "${p.name || "#" + (i + 1)}" (${p.lat.toFixed(
                5
              )}, ${p.lng.toFixed(
                5
              )}) não está em água segundo o serviço is-on-water. Ajusta a rota.`,
              problemPoint: { lat: p.lat, lng: p.lng },
            };
          }
        }
      }

      for (let i = 0; i < route.length - 1; i++) {
        const a = route[i];
        const b = route[i + 1];
        for (let s = 1; s <= samplesPerSegment; s++) {
          const t = s / (samplesPerSegment + 1);
          const lat = a.lat + (b.lat - a.lat) * t;
          const lng = a.lng + (b.lng - a.lng) * t;
          const isWater = await isPointWaterApi(lat, lng);
          if (!isWater) {
            return {
              ok: false,
              message: `A rota entre "${a.name || "#" + (i + 1)}" e "${
                b.name || "#" + (i + 2)
              }" passa por terra perto de (${lat.toFixed(5)}, ${lng.toFixed(
                5
              )}). Ajusta os pontos no mapa.`,
              problemPoint: { lat, lng },
            };
          }
        }
      }

      return { ok: true };
    }

    // --------- ROTA / MAPA ----------
    const mapEl = document.getElementById('trip-map');
    if (!mapEl) return;

    const iberiaBounds = L.latLngBounds(
      [34.0, -11.0],
      [45.0, 4.0]
    );

    const map = L.map('trip-map', {
      center: [40, -4],
      zoom: 5,
      maxBounds: iberiaBounds,
      maxBoundsViscosity: 0.9,
      minZoom: 4
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
    }).addTo(map);

    // forçar cálculo de tamanho no primeiro render (evita “mapa encolhido”)
    setTimeout(() => {
      try {
        map.invalidateSize();
      } catch (e) {}
    }, 0);

    let routePoints = Array.isArray(trip.route) && trip.route.length
      ? trip.route.map(p => ({
          lat: Number(p.lat),
          lng: Number(p.lng),
          stopMinutes: Number(p.stopMinutes || 0),
          name: p.name || null,
        }))
      : [
          {
            lat: Number(trip.embarkLat),
            lng: Number(trip.embarkLng),
            stopMinutes: 0,
            name: 'Embarque',
          },
          {
            lat: Number(trip.destinationLat),
            lng: Number(trip.destinationLng),
            stopMinutes: 0,
            name: 'Destino',
          }
        ];

    let polyline = L.polyline(routePoints.map(p => [p.lat, p.lng]), {
      color: '#383838ff',
      weight: 3
    }).addTo(map);

    const markers = [];
    let invalidMarker = null;

    function clearInvalidMarker() {
      if (invalidMarker) {
        map.removeLayer(invalidMarker);
        invalidMarker = null;
      }
    }

    function markProblemPoint(problemPoint) {
      clearInvalidMarker();
      if (!problemPoint) return;
      invalidMarker = L.circleMarker([problemPoint.lat, problemPoint.lng], {
        radius: 7,
        color: '#f97316',
        fillColor: '#f97316',
        weight: 3,
        fillOpacity: 1,
      }).addTo(map);
      invalidMarker.bindTooltip("Problema de rota aqui", { permanent: false });
      map.flyTo([problemPoint.lat, problemPoint.lng], 12, { animate: true });
    }

    function refreshMarkers() {
      markers.forEach(m => map.removeLayer(m));
      markers.length = 0;
      routePoints.forEach((p, idx) => {
        const m = L.circleMarker([p.lat, p.lng], {
          radius: 7,
          color: idx === 0 ? '#22c55e' : (idx === routePoints.length - 1 ? '#f91616ff' : '#00000000'),
          fillColor: idx === 0 ? '#22c55e' : (idx === routePoints.length - 1 ? '#f91616ff' : '#00000000'),
          weight: 2,
          fillOpacity: 1
        }).addTo(map);
        const label =
          idx === 0 ? 'Embarque' :
          idx === routePoints.length - 1 ? 'Destino' :
          (p.stopMinutes && p.stopMinutes > 0
            ? `${p.name || 'Stop'} · ${p.stopMinutes} min`
            : (p.name || 'Waypoint'));
        m.bindTooltip(label, { permanent: false });
        markers.push(m);
      });
    }
    refreshMarkers();
    map.fitBounds(polyline.getBounds(), { padding: [16, 16] });

    // --------- CUSTO EXTRA ----------
    let extraCost = Number(trip.extraCost || 0);
    const extraLabel = document.getElementById('extra-cost-label');
    function refreshExtraCostLabel() {
      if (!extraLabel) return;
      const basePrice = Number(trip.estimatedPrice || 0);
      const total = basePrice + extraCost;
      extraLabel.textContent =
        `Extra previsto: ${extraCost.toFixed(2)} € (total ~ ${total.toFixed(2)} €)`;
    }
    refreshExtraCostLabel();

    const maxLegNm = Number(pricing.maxLegNauticalMiles || 0) || null;
    const maxLegKm = maxLegNm ? maxLegNm : null;

    // --------- SIMULAÇÃO / STATUS ----------
    const simLabel = document.getElementById('sim-time-label');
    const btnStart = document.getElementById('btn-start-sim');
    const btnStop  = document.getElementById('btn-stop-sim');

    let boatMarker = L.circleMarker(
      [routePoints[0].lat, routePoints[0].lng],
      {
        radius: 7,
        color: '#f0f',
        weight: 2,
        fillColor: '#f0f',
        fillOpacity: 1,
      }
    ).addTo(map);

    let localPollingTimer = null;
    let statusPollTimer   = null;

    function onTripFinished(status) {
      if (localPollingTimer) {
        clearInterval(localPollingTimer);
        localPollingTimer = null;
      }
      if (statusPollTimer) {
        clearInterval(statusPollTimer);
        statusPollTimer = null;
      }

      if (simLabel) {
        simLabel.textContent =
          status === 'completed'
            ? 'Viagem concluída.'
            : 'Viagem terminada.';
      }

      const card = document.getElementById('active-trip-card');
      if (card) {
        card.style.display = 'none';
      }

      // atualizar lista de viagens disponíveis
      if (typeof refreshTripsList === 'function') {
        refreshTripsList();
      }
    }

function refreshBoatFromServer() {
  fetch('./api/tripTrack.php?tripId=' + encodeURIComponent(trip.id))
    .then(r => r.json())
    .then(data => {
      if (!data.success || !data.position) return;

      const { lat, lng } = data.position;
      boatMarker.setLatLng([lat, lng]);

      // centra no barco para não ficar “world map”
      map.setView([lat, lng], 10);

      if (!simLabel) return;

      if (data.status === 'completed') {
        simLabel.textContent = 'Viagem concluída (simulação server-side)';

        // parar o polling desta simulação
        if (localPollingTimer) {
          clearInterval(localPollingTimer);
          localPollingTimer = null;
        }

        // esconder o card da viagem ativa
        const activeCard = document.getElementById('active-trip-card');
        if (activeCard) {
          activeCard.style.display = 'none';
        }

        // voltar a mostrar a lista de viagens disponíveis
        const tripsCard = document.getElementById('trips-card');
        if (tripsCard) {
          tripsCard.style.display = '';
        }

        // opcional: forçar refresh da lista (já tens setInterval, mas aqui é imediato)
        if (typeof refreshTripsList === 'function') {
          refreshTripsList();
        }
      } else {
        simLabel.textContent = 'Simulação em curso (server-side)';
      }
    })
    .catch(err => {
      console.error(err);
    });
}


    // polling independente do estado da trip (para apanhar completed/cancelled mesmo sem simulação)
    function pollTripStatus() {
      fetch('./api/trips.php?tripId=' + encodeURIComponent(trip.id))
        .then(r => r.json())
        .then(data => {
          if (!data.success || !data.trip) return;
          const s = data.trip.status;
          if (s === 'completed' || s === 'cancelled') {
            onTripFinished(s);
          }
        })
        .catch(() => {});
    }

    // estado inicial da label
    if (simLabel) {
      if (trip.status === 'assigned') {
        simLabel.textContent = 'Viagem atribuída. Aguardando início da simulação.';
      } else if (trip.status === 'in-progress') {
        simLabel.textContent = 'Viagem em curso.';
      }
    }

    if (btnStart) {
      btnStart.addEventListener('click', () => {
        fetch('./api/startSimulation.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ tripId: trip.id }),
        })
          .then(r => r.json())
          .then(data => {
            if (!data.success) throw new Error(data.error || 'Erro ao iniciar simulação');

            if (simLabel) {
              simLabel.textContent = 'Simulação iniciada (server-side)';
            }

            if (!localPollingTimer) {
              refreshBoatFromServer();
              localPollingTimer = setInterval(refreshBoatFromServer, 1000);
            }
          })
          .catch(err => {
            console.error(err);
            alert('Erro ao iniciar simulação.');
          });
      });
    }

    if (btnStop) {
      btnStop.addEventListener('click', () => {
        if (localPollingTimer) {
          clearInterval(localPollingTimer);
          localPollingTimer = null;
          if (simLabel) {
            simLabel.textContent = 'Simulação pausada neste dispositivo.';
          }
        }
      });
    }

    // Se a trip já estiver em progresso quando entras, começa logo a seguir o barco
    refreshBoatFromServer();
    localPollingTimer = setInterval(refreshBoatFromServer, 1000);
    statusPollTimer   = setInterval(pollTripStatus, 5000);
    pollTripStatus(); // primeira verificação imediata

    // --------- CHAT ----------
    const chatBox   = document.getElementById('chat-messages');
    const chatForm  = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const user      = window.waveupCurrentUser || {
      id: 'skipper',
      name: 'Skipper'
    };

    function renderMessages(msgs) {
      if (!Array.isArray(msgs)) msgs = [];
      chatBox.innerHTML = '';
      msgs.forEach(m => {
        const div = document.createElement('div');
        const isMe = m.senderId === user.id;
        div.className = 'flex ' + (isMe ? 'justify-end' : 'justify-start');
        div.innerHTML = `
          <div class="max-w-[80%] rounded-md px-2 py-1 mb-0.5
              ${isMe ? 'bg-sky-600 text-white' : 'bg-slate-800 text-slate-100'}">
            <div class="text-[10px] opacity-75 mb-0.5">
              ${m.senderName || ''}
              · ${m.createdAt ? new Date(m.createdAt).toLocaleTimeString('pt-PT',{hour:'2-digit',minute:'2-digit'}) : ''}
            </div>
            <div class="text-[11px] whitespace-pre-wrap">${m.text || ''}</div>
          </div>
        `;
        chatBox.appendChild(div);
      });
      chatBox.scrollTop = chatBox.scrollHeight;
    }

    function loadMessages() {
      fetch('./api/chat.php?tripId=' + encodeURIComponent(trip.id))
        .then(r => r.json())
        .then(data => {
          if (!data.success) throw new Error(data.error || 'Erro ao carregar chat');
          renderMessages(data.messages || []);
        })
        .catch(err => {
          console.error(err);
        });
    }

    loadMessages();
    setInterval(loadMessages, 5000);

    if (chatForm && chatInput) {
      chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const text = chatInput.value.trim();
        if (!text) return;
        fetch('./api/chat.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            tripId: trip.id,
            senderId: user.id,
            senderName: user.name,
            text
          })
        })
          .then(r => r.json())
          .then(data => {
            if (!data.success) throw new Error(data.error || 'Erro ao enviar mensagem');
            chatInput.value = '';
            loadMessages();
          })
          .catch(err => {
            console.error(err);
            alert('Falha ao enviar mensagem.');
          });
      });
    }
  })();
</script>

  <?php endif; ?>
<script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a0f2c1b5f40e3b4',t:'MTc2MzU0OTY5NQ=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script><script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a1052924a29489d',t:'MTc2MzU2MTc1Ng=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script><script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a1054a5cd70e3b8',t:'MTc2MzU2MTg0MQ=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script><script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a1065fa48bf8df7',t:'MTc2MzU2MjU1MQ=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script><script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a1249dfa985eef9',t:'MTc2MzU4MjM3MQ=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script><script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a12caefdc5bef51',t:'MTc2MzU4NzY1OA=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script><script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a130fee8d84ef51',t:'MTc2MzU5MDQ4NA=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script></body>
</html>