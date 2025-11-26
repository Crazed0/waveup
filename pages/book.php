  <?php
  require __DIR__ . '/../api/_common.php';

  $user   = current_user();
  $colors = get_theme_colors();
  $logos  = get_logos();

  $avatarPath = $user['avatar'] ?? null;
  if ($avatarPath) {
    // garantir que tem prefixo relativo correto
    $avatarUrl = './' . ltrim($avatarPath, '/');
  } else {
    // fallback para um avatar default teu
    $avatarUrl = './images/users/default-avatar.png';
  }

  if (!$user) {
    header('Location: ./index.php?page=login');
    exit;
  }

  if (($user['type'] ?? '') === 'skipper') {
    // Skippers não fazem bookings aqui
    header('Location: ./index.php?page=claim');
    exit;
  }

  // load_json_file já aponta para /data
  $categories       = load_json_file('categories.json', []);
  $embarkPointsRaw  = load_json_file('embarkPoints.json', []);
  $trips            = load_json_file('trips.json', []);
  $licenseTypes     = load_json_file('licenseTypes.json', []);
  $licenseRoutes    = load_json_file('licenseRoutes.json', []);
  $portConnections  = load_json_file('portConnections.json', []);

  // NOVO: users (skippers + barcos)
  $users            = load_json_file('users.json', []);
  if (!is_array($users)) $users = [];

  // --- DETERMINAR VIAGEM ATIVA DO UTILIZADOR ---
  $activeTrip = null;

  if (is_array($trips)) {
    $activeTrip = null;
    $pendingTrips = [];

    // apanha TODAS as trips pending/assigned do user
    foreach ($trips as $t) {
      if (
        isset($t['customerId']) &&
        $t['customerId'] === $user['id'] &&
        in_array($t['status'], ['pending', 'assigned', 'in-progress'])
      ) {
        $pendingTrips[] = $t;
      }
    }

    // escolhe a mais recente (maior createdAt ou id)
    if (!empty($pendingTrips)) {
      usort($pendingTrips, fn($a, $b) => strtotime($b['createdAt']) <=> strtotime($a['createdAt']));
      $activeTrip = $pendingTrips[0];
    }
  }

  // garantir 'id'
  if ($activeTrip && !isset($activeTrip['id']) && isset($activeTrip['tripId'])) {
    $activeTrip['id'] = $activeTrip['tripId'];
  }

  // garantir arrays
  if (!is_array($categories))      $categories      = [];
  if (!is_array($embarkPointsRaw)) $embarkPointsRaw = [];
  if (!is_array($licenseTypes))    $licenseTypes    = [];
  if (!is_array($licenseRoutes))   $licenseRoutes   = [];
  if (!is_array($portConnections)) $portConnections = [];

  // converter maxDistanceNm → maxDistanceKm se ainda não existir
  foreach ($categories as &$cat) {
    if (isset($cat['maxDistanceNm'])) {
      // converter NM → KM corretamente
      $cat['maxDistanceKm'] = $cat['maxDistanceNm'] * 1.852;
    }
  }
  unset($cat);

  // garantir que o frontend tem sempre 'id'
  if ($activeTrip) {
    if (!isset($activeTrip['id']) && isset($activeTrip['tripId'])) {
      $activeTrip['id'] = $activeTrip['tripId'];
    }
  }


  $initialData = [
    'user'           => [
      'id'         => $user['id'],
      'name'       => $user['name'],
      'type'       => $user['type'],
      'phone'      => $user['phone'] ?? '',
      'email'      => $user['email'] ?? '',
      'avatar'     => $user['avatar'] ?? null,
      'rating'     => $user['rating'] ?? null,
      'trips'      => $user['trips'] ?? 0,
      'createdAt'  => $user['createdAt'] ?? null,
    ],
    'categories'      => $categories,
    'embarkPoints'    => $embarkPointsRaw,
    'licenseTypes'    => $licenseTypes,
    'licenseRoutes'   => $licenseRoutes,
    'portConnections' => $portConnections,
    'activeTrip'      => $activeTrip,
  ];

  //echo "<pre>";
  //echo print_r($activeTrip, true);
  //echo "</pre>";

  ?>
  <!DOCTYPE html>
  <html lang="pt">

  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>WaveUp</title>
    <link rel="icon" href="<?= htmlspecialchars($logos['favicon']) ?>">
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Leaflet -->
    <link
      rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin="" />
    <script
      src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
      integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
      crossorigin="">
    </script>

    <link rel="stylesheet"
      href="/css/app9.css">
    <style>
      :root {
        --color-primary: <?= htmlspecialchars($colors['primary']) ?>;
        --color-secondary: <?= htmlspecialchars($colors['secondary']) ?>;
      }

      #booking-map {
        width: 100%;
        height: 260px;
        border-radius: 0.75rem;
        overflow: hidden;
        margin-left: auto;
        margin-right: auto;
        padding-left: 5px;
        padding-right: 5px;
      }
    </style>
  </head>

  <body class="bg-primary">
    <div class="min-h-screen flex flex-col">
      <!-- Top bar -->
      <header class="border-b border-slate-800/80 bg-slate-950/80 backdrop-blur">
        <div class="waveup-shell flex items-center justify-between gap-4 py-3 px-2 sm:px-4">
          <div class="flex items-center gap-3">
            <img src="<?= htmlspecialchars($logos['icon']) ?>" alt="WaveUp" class="w-9 h-9 rounded-lg" />
            <div>
              <div class="flex items-center gap-2">
                <h1 class="text-lg font-semibold">WaveUp</h1>
                <span class="waveup-badge text-[10px]">
                  <span class="text-secondary">●</span>
                  Demo
                </span>
              </div>
              <p class="text-[11px] text-slate-400">
                Reservar viagens de barco em Lisboa &amp; Cascais
              </p>
            </div>
          </div>
          <div class="flex items-center gap-3 text-xs text-slate-300">
            <img
              src="<?= htmlspecialchars($avatarUrl) ?>"
              alt="<?= htmlspecialchars($user['name']) ?>"
              class="w-8 h-8 rounded-full object-cover border border-slate-700" />
            <div class="text-right hidden sm:block">
              <div class="font-medium"><?= htmlspecialchars($user['name']) ?></div>
              <div class="text-[11px] text-slate-400"><?= htmlspecialchars($user['email']) ?></div>
            </div>
            <a href="./index.php?page=logout" class="waveup-btn-outline px-3 py-1 text-[11px]">
              Sair
            </a>
          </div>

        </div>
      </header>

      <main class="flex-1">
        <div class="waveup-shell py-6 px-2 sm:px-4">
          <div id="root" class="w-full max-w-full"></div>
        </div>
      </main>
    </div>

    <!-- React UMD -->
    <script src="https://unpkg.com/react@18/umd/react.development.js" crossorigin></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.development.js" crossorigin></script>
    <!-- Babel para compilar JSX no browser -->
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

    <!-- Dados iniciais -->
    <script id="initial-data" type="application/json">
      <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n" ?>
    </script>

    <!-- App React -->
    <script type="text/babel">
      const { useState, useMemo, useEffect, useRef } = React;

// Update the initial data parsing:
const initialData = JSON.parse(document.getElementById('initial-data').textContent);
const categories = initialData.categories || [];
const allPoints = initialData.embarkPoints || [];
const licenseTypes = initialData.licenseTypes || [];
const licenseRoutes = initialData.licenseRoutes || [];
const portConnections = initialData.portConnections || [];
const currentUser = initialData.user || null;
const activeTripFromServer = initialData.activeTrip || null;
const completedTripFromServer = initialData.completedTrip || null; // New

      // --- helpers geográficos ---
      function toRad(deg) {
        return deg * Math.PI / 180;
      }

      function formatDateTimePt(isoString) {
    if (!isoString) return "—";
    const d = new Date(isoString);
    if (Number.isNaN(d.getTime())) return "—";
    return d.toLocaleString("pt-PT", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  }


  // Suaviza a rota usando média móvel sobre lat/lng
  // windowSize deve ser ímpar (ex: 5), e mantém primeiros/últimos pontos
  // e também não mexe em pontos com paragem (stopMinutes > 0) para não
  // deslocar waypoints importantes.
  function smoothRoute(points, windowSize = 5) {
    if (!Array.isArray(points) || points.length <= 2) return points;

    const n = points.length;
    const half = Math.floor(windowSize / 2);

    // cópia para não mutar o array original
    const result = points.map(p => ({ ...p }));

    for (let i = 1; i < n - 1; i++) {
      const p = points[i];

      // ancora pontos com paragem (waypoints importantes)
      if (p.stopMinutes && p.stopMinutes > 0) {
        continue;
      }

      let sumLat = 0;
      let sumLng = 0;
      let count = 0;

      for (let j = i - half; j <= i + half; j++) {
        if (j < 0 || j >= n) continue;
        const pj = points[j];
        sumLat += pj.lat;
        sumLng += pj.lng;
        count++;
      }

      if (count > 0) {
        result[i].lat = sumLat / count;
        result[i].lng = sumLng / count;
      }
    }

    return result;
  }
  // Suaviza toda a rota puxando-a para a "linha" da main.
  // - NÃO mexe no primeiro nem no último ponto da rota completa
  // - Usa média móvel com preferência por pontos segment === "main"
  // - Marca pontos mexidos com _edited = true (para pintar a laranja no mapa)
// Suaviza a zona de junção porto <-> rota principal
function optimizeJunctions(points, windowSize = 9) {
  if (!Array.isArray(points) || points.length < 3) return points;

  const n = points.length;
  const result = points.map(p => ({ ...p, _edited: p._edited || false }));
  const half = Math.floor(windowSize / 2);

  // descobrir onde começa / acaba o segmento "main"
  let firstMain = -1;
  let lastMain  = -1;
  for (let i = 0; i < n; i++) {
    if (points[i].segment === "main") {
      if (firstMain === -1) firstMain = i;
      lastMain = i;
    }
  }

  // se não houver main, faz só um smoothing simples e curto
  const hasMain = firstMain !== -1;

  for (let i = 1; i < n - 1; i++) {
    const p = points[i];

    // nunca mexer no primeiro/último
    if (i === 0 || i === n - 1) continue;

    // não mexer em waypoints com paragem (para não deslocar stops)
    if (p.stopMinutes && p.stopMinutes > 0) continue;

    // proteger bem a zona mesmo junto aos portos
    if (hasMain) {
      // portA: deixa apenas os últimos 2–3 pontos perto da main mexerem
      if (p.segment === "portA" && i < firstMain - 3) continue;
      // portB: deixa apenas os primeiros 2–3 pontos depois da main mexerem
      if (p.segment === "portB" && i > lastMain + 3) continue;
    }

    let sumLatAll = 0;
    let sumLngAll = 0;
    let countAll  = 0;

    let sumLatMain = 0;
    let sumLngMain = 0;
    let countMain  = 0;

    for (let j = i - half; j <= i + half; j++) {
      if (j < 0 || j >= n) continue;
      const pj = points[j];
      if (typeof pj.lat !== "number" || typeof pj.lng !== "number") continue;

      // média global (fallback)
      sumLatAll += pj.lat;
      sumLngAll += pj.lng;
      countAll++;

      // tratamos como "main-like":
      //  - pontos main
      //  - e os pontos de portA/portB muito perto da junção
      let isMainLike = pj.segment === "main";

      if (hasMain) {
        if (
          pj.segment === "portA" &&
          j >= firstMain - 4 && j <= firstMain + 2
        ) {
          isMainLike = true;
        }
        if (
          pj.segment === "portB" &&
          j >= lastMain - 2 && j <= lastMain + 4
        ) {
          isMainLike = true;
        }
      }

      if (isMainLike) {
        sumLatMain += pj.lat;
        sumLngMain += pj.lng;
        countMain++;
      }
    }

    if (!countAll) continue;

    const useMain = countMain >= 2;
    const newLat  = useMain ? (sumLatMain / countMain) : (sumLatAll / countAll);
    const newLng  = useMain ? (sumLngMain / countMain) : (sumLngAll / countAll);

    result[i].lat     = newLat;
    result[i].lng     = newLng;
    result[i]._edited = true;
  }

  return result;
}

// Remove vértices que criam cotovelos muito apertados mas quase não aumentam a distância
function simplifySharpAngles(points, angleThresholdDeg = 25, maxExtraKm = 0.4) {
  if (!Array.isArray(points) || points.length < 3) return points;

  const out = [];
  out.push(points[0]); // primeiro fica sempre

  for (let i = 1; i < points.length - 1; i++) {
    const prev = out[out.length - 1];
    const curr = points[i];
    const next = points[i + 1];

    // nunca mexer em stops (waypoints importantes)
    if (curr.stopMinutes && curr.stopMinutes > 0) {
      out.push(curr);
      continue;
    }

    // vectores em plano aproximado (lat/lng)
    const v1x = curr.lng - prev.lng;
    const v1y = curr.lat - prev.lat;
    const v2x = next.lng - curr.lng;
    const v2y = next.lat - curr.lat;

    const mag1 = Math.hypot(v1x, v1y);
    const mag2 = Math.hypot(v2x, v2y);
    if (!mag1 || !mag2) {
      out.push(curr);
      continue;
    }

    let cos = (v1x * v2x + v1y * v2y) / (mag1 * mag2);
    cos = Math.min(1, Math.max(-1, cos));
    const angleDeg = Math.acos(cos) * 180 / Math.PI;

    // medir "desperdício" em distância
    const dPrevCurr = haversineKm(prev, curr);
    const dCurrNext = haversineKm(curr, next);
    const dPrevNext = haversineKm(prev, next);
    const extraKm   = (dPrevCurr + dCurrNext) - dPrevNext;

    const isSharp = angleDeg < angleThresholdDeg;
    const isTiny  = extraKm >= 0 && extraKm <= maxExtraKm;

    // se é canto muito fechado e só acrescenta poucos metros -> removemos o ponto
    if (isSharp && isTiny) {
      // não fazemos push deste ponto → “endireita” o segmento
      continue;
    }

    out.push(curr);
  }

  out.push(points[points.length - 1]); // último fica sempre
  return out;
}


      function haversineKm(a, b) {
        const R = 6371;
        const dLat = toRad(b.lat - a.lat);
        const dLon = toRad(b.lng - a.lng);
        const lat1 = toRad(a.lat);
        const lat2 = toRad(b.lat);

        const h = Math.sin(dLat/2) ** 2 +
                  Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon/2) ** 2;
        const c = 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1-h));
        return R * c;
      }

      function pathDistanceKm(points) {
        if (!Array.isArray(points) || points.length < 2) return 0;
        let total = 0;
        for (let i = 0; i < points.length - 1; i++) {
          total += haversineKm(points[i], points[i + 1]);
        }
        return total;
      }

      // devolve também o índice no array de pontos
      function findNearestPoint(coords, points) {
        if (!coords || !points.length) return null;
        let bestIndex = -1;
        let bestDist = Infinity;

        for (let i = 0; i < points.length; i++) {
          const p = points[i];
          if (typeof p.lat !== "number" || typeof p.lng !== "number") continue;

          const d = haversineKm(
            { lat: coords.latitude, lng: coords.longitude },
            { lat: p.lat,          lng: p.lng          }
          );
          if (d < bestDist) {
            bestDist = d;
            bestIndex = p._index; // usamos o índice original
          }
        }

        if (bestIndex === -1) return null;
        const found = allPoints[bestIndex];
        return {
          index: bestIndex,
          point: found,
          distanceKm: bestDist,
        };
      }

      function StepBadge({ step, current, label }) {
        const active = step === current;
        const done   = step < current;
        return (
          <div className="flex items-center gap-2 text-xs">
            <div className={
              "flex items-center justify-center w-6 h-6 rounded-full border text-[11px] " +
              (active ? "bg-secondary/10 border-secondary text-secondary" :
              done   ? "bg-emerald-500/10 border-emerald-500 text-emerald-400" :
                        "border-slate-600 text-slate-400")
            }>
              {step}
            </div>
            <span className={active ? "text-slate-100" : "text-slate-400"}>{label}</span>
          </div>
        );
      }

      // --- ligar categorias às licenças ---
      function buildCategoryLicenseMap(categories, licenseTypes) {
        const byName = {};
        licenseTypes.forEach(lt => {
          if (lt && lt.name) byName[lt.name] = lt;
        });
        const map = {};
        categories.forEach(cat => {
          const lt = byName[cat.licenseRequired] || null;
          map[cat.id] = lt ? lt.id : null;
        });
        return map;
      }

      function buildLicenseRouteMap(licenseRoutes) {
        const m = {};
        licenseRoutes.forEach(r => {
          if (!r || !r.licenseId) return;
          m[r.licenseId] = r;
        });
        return m;
      }
  // Cria mais pontos entre cada par de pontos, mantendo o segment / meta.
  // maxStepKm define a distância máxima entre pontos consecutivos.
  function densifyRoute(points, maxStepKm = 0.2) {
    if (!Array.isArray(points) || points.length < 2) return points;

    const out = [];
    for (let i = 0; i < points.length - 1; i++) {
      const p0 = points[i];
      const p1 = points[i + 1];

      // garante que o ponto original entra
      out.push({ ...p0 });

      if (
        typeof p0.lat !== "number" || typeof p0.lng !== "number" ||
        typeof p1.lat !== "number" || typeof p1.lng !== "number"
      ) {
        continue;
      }

      const dist = haversineKm(
        { lat: p0.lat, lng: p0.lng },
        { lat: p1.lat, lng: p1.lng }
      );

      const steps = Math.max(1, Math.ceil(dist / maxStepKm));

      // s = 1 .. steps-1  → pontos intermédios
      for (let s = 1; s < steps; s++) {
        const t = s / steps;
        out.push({
          lat: p0.lat + (p1.lat - p0.lat) * t,
          lng: p0.lng + (p1.lng - p0.lng) * t,
          // meta herdada do ponto "anterior"
          name: p0.name,
          stopMinutes: 0,
          segment: p0.segment,
          _edited: false,
        });
      }
    }

    // último ponto
    out.push({ ...points[points.length - 1] });
    return out;
  }

  function buildNetworkPath(
    embarkIndex,
    destinationIndex,
    categoryId,
    portConnections,
    licenseRouteMap,
    categoryLicenseMap
  ) {
    const licenseId = categoryLicenseMap[categoryId];
    if (!licenseId) return null;

    const route = licenseRouteMap[licenseId];
    const routePoints = route && Array.isArray(route.points) ? route.points : null;
    if (!routePoints || routePoints.length < 2) return null;

    const connA = portConnections.find(
      c => c.portIndex === embarkIndex && c.licenseId === licenseId && c.enabled !== false
    );
    const connB = portConnections.find(
      c => c.portIndex === destinationIndex && c.licenseId === licenseId && c.enabled !== false
    );
    if (!connA || !connB) return null;

    const ptsA = Array.isArray(connA.points) ? connA.points : [];
    const ptsB = Array.isArray(connB.points) ? connB.points : [];
    if (!ptsA.length || !ptsB.length) return null;
    if (connA.attachIndex == null || connB.attachIndex == null) return null;

    const aIdx = connA.attachIndex;
    const bIdx = connB.attachIndex;

    let mid;
    if (aIdx <= bIdx) {
      mid = routePoints.slice(aIdx, bIdx + 1);
    } else {
      const slice = routePoints.slice(bIdx, aIdx + 1);
      mid = slice.slice().reverse();
    }

    const path = [];

    // porto A → rota (segmento portA)
    ptsA.forEach((p, idx) => {
      path.push({
        lat: p.lat,
        lng: p.lng,
        name: idx === 0 ? 'Embarque' : (p.name || 'Rota Auxiliar'),
        stopMinutes: 0,
        segment: 'portA',
      });
    });

    // rota principal (segmento main)
    mid.forEach((p, idx) => {
      path.push({
        lat: p.lat,
        lng: p.lng,
        name: p.name || 'Rota principal',
        stopMinutes: 0,
        segment: 'main',
      });
    });

    // rota → porto B (segmento portB, invertido)
    const revB = ptsB.slice().reverse();
    revB.forEach((p, idx) => {
      const lastIndex = revB.length - 1;
      path.push({
        lat: p.lat,
        lng: p.lng,
        name: idx === lastIndex ? 'Destino' : (p.name || 'Rota Auxiliar'),
        stopMinutes: 0,
        segment: 'portB',
      });
    });

    const distanceKm = pathDistanceKm(path);

    return { path, distanceKm, licenseId, connA, connB, route };
  }

function TripChatMessages({ tripId, currentUser }) {
  const [messages, setMessages] = React.useState([]);
  const containerRef = React.useRef(null);

  React.useEffect(() => {
    if (!tripId) return;

    let cancelled = false;

    function loadMessages() {
      if (cancelled) return;

      fetch("./api/chat.php?tripId=" + encodeURIComponent(tripId))
        .then((r) => r.json())
        .then((data) => {
          if (cancelled) return;
          if (!data.success || !Array.isArray(data.messages)) return;
          setMessages(data.messages);
        })
        .catch(() => {});
    }

    loadMessages();
    const interval = setInterval(loadMessages, 5000);

    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, [tripId]);

  // 👉 SEMPRE ao fundo, o mais baixo possível
  React.useLayoutEffect(() => {
    const el = containerRef.current;
    if (!el) return;

    // máximo teórico
    const maxScrollTop = el.scrollHeight - el.clientHeight;
    if (maxScrollTop > 0) {
      el.scrollTop = maxScrollTop;
    } else {
      el.scrollTop = 0;
    }
  }, [messages.length]); // só quando muda o nº de mensagens

  return (
    <div
      ref={containerRef}
      className="flex-1 overflow-y-auto text-[11px] space-y-1 border border-slate-800 rounded-md p-2 bg-slate-950/60 min-h-[200px] h-[200px]"
    >
      {!tripId ? (
        <p className="text-[11px] text-slate-500">
          Chat indisponível para esta viagem.
        </p>
      ) : messages.length === 0 ? (
        <p className="text-[11px] text-slate-500">
          Ainda não há mensagens nesta viagem.
        </p>
      ) : (
        messages.map((m, idx) => {
          const isMe = m.senderId === currentUser.id;
          return (
            <div
              key={m.id ?? idx}
              className={"flex " + (isMe ? "justify-end" : "justify-start")}
            >
              <div
                className={
                  "max-w-[80%] rounded-md px-2 py-1 mb-0.5 " +
                  (isMe
                    ? "bg-sky-600 text-white"
                    : "bg-slate-800 text-slate-100")
                }
              >
                <div className="text-[10px] opacity-75 mb-0.5">
                  {m.senderName || ""} ·{" "}
                  {m.createdAt
                    ? new Date(m.createdAt).toLocaleTimeString("pt-PT", {
                        hour: "2-digit",
                        minute: "2-digit",
                      })
                    : ""}
                </div>
                <div className="text-[11px] whitespace-pre-wrap">
                  {m.text || ""}
                </div>
              </div>
            </div>
          );
        })
      )}
    </div>
  );
}



// 🔹 INPUT TOTALMENTE SEPARADO (POST) – DOM “hardcoded”
function InputChatSection({ tripId, currentUser }) {
  const containerRef = React.useRef(null);

  React.useEffect(() => {
    const el = containerRef.current;
    if (!el) return;

    // só inicializar uma vez por montagem
    if (el.dataset.initialized === "1") return;
    el.dataset.initialized = "1";

    el.innerHTML = `
      <form class="mt-2 flex gap-2 text-[11px]">
        <input
          type="text"
          class="flex-1 bg-slate-950 border border-slate-700 rounded-md px-2 py-1 focus:outline-none focus:ring-1 focus:ring-sky-500"
          placeholder="${!tripId ? "Chat indisponível..." : "Escrever mensagem..."}"
          autocomplete="off"
          ${!tripId ? "disabled" : ""}
        />
        <button
          type="submit"
          class="waveup-btn px-3 py-1"
        >
          Enviar
        </button>
      </form>
    `;

    const form   = el.querySelector("form");
    const input  = el.querySelector("input");
    const button = el.querySelector("button");

    if (!form || !input || !button) return;

    function setDisabled(disabled) {
      if (disabled) {
        input.setAttribute("disabled", "disabled");
        button.setAttribute("disabled", "disabled");
        button.classList.add("opacity-60", "cursor-not-allowed");
      } else {
        input.removeAttribute("disabled");
        button.removeAttribute("disabled");
        button.classList.remove("opacity-60", "cursor-not-allowed");
      }
    }

    if (!tripId) {
      setDisabled(true);
    }

    function handleSubmit(e) {
      e.preventDefault();
      if (!tripId) return;

      const text = input.value.trim();
      if (!text) return;

      setDisabled(true);

      fetch("./api/chat.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          tripId,
          senderId: currentUser.id,
          senderName: currentUser.name,
          text,
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.success) {
            throw new Error(data.error || "Erro ao enviar mensagem");
          }
          input.value = "";
          // janela vai buscar via polling
        })
        .catch(() => {
          alert("Falha ao enviar mensagem.");
        })
        .finally(() => {
          if (tripId) setDisabled(false);
        });
    }

    form.addEventListener("submit", handleSubmit);

    return () => {
      form.removeEventListener("submit", handleSubmit);
    };
  }, [tripId, currentUser.id, currentUser.name]);

  
  return <div ref={containerRef} />;
}


      // --- App ---
      function App() {
  const [step, setStep] = useState(1);
  const [category, setCategory] = useState(null);
  const [isPaying, setIsPaying] = useState(false);
  const [paymentStatus, setPaymentStatus] = useState(null);
  const [error, setError] = useState(null);
  const [createdTrip, setCreatedTrip] = useState(null);
  const [departureType, setDepartureType] = useState("now");
  const [departureOffsetHours, setDepartureOffsetHours] = useState(1);
  const [routePoints, setRoutePoints] = useState([]);
  const [activeTrip, setActiveTrip] = useState(activeTripFromServer);
  const [embarkIndex, setEmbarkIndex] = useState(null);
  const [destinationIndex, setDestinationIndex] = useState(null);
  const [userLocation, setUserLocation] = useState({
    status: "idle",
    coords: null,
    error: null,
    nearest: null,
  });

        // refs Leaflet
        const mapRef           = useRef(null);
        const polylineRef      = useRef(null);
        const markersRef       = useRef([]);

        // --- mapas auxiliares ---
        const categoryLicenseMap = useMemo(
          () => buildCategoryLicenseMap(categories, licenseTypes),
          [categories, licenseTypes]
        );

        const licenseRouteMap = useMemo(
          () => buildLicenseRouteMap(licenseRoutes),
          [licenseRoutes]
        );

        // apenas portos que têm ligações válidas a rotas principais
        const networkPortIndexes = useMemo(() => {
          const validLicenses = new Set(
            licenseRoutes
              .filter(r => Array.isArray(r.points) && r.points.length)
              .map(r => r.licenseId)
          );
          const s = new Set();
          portConnections.forEach(c => {
            if (validLicenses.has(c.licenseId) && c.enabled !== false) {
              s.add(c.portIndex);
            }
          });
          return s;
        }, [licenseRoutes, portConnections]);

        const networkPorts = useMemo(
          () =>
            allPoints
              .map((p, idx) => ({ ...p, _index: idx }))
              .filter(p => networkPortIndexes.has(p._index)),
          [allPoints, networkPortIndexes]
        );

        const embark = embarkIndex != null && allPoints[embarkIndex]
          ? allPoints[embarkIndex]
          : null;

        const destination = destinationIndex != null && allPoints[destinationIndex]
          ? allPoints[destinationIndex]
          : null;

        // plano de rede para a categoria selecionada (se existir)
  const networkPlan = useMemo(() => {
    if (embarkIndex == null || destinationIndex == null) return null;
    if (!category) return null;

    const plan = buildNetworkPath(
      embarkIndex,
      destinationIndex,
      category.id,
      portConnections,
      licenseRouteMap,
      categoryLicenseMap
    );

    if (!plan || !Array.isArray(plan.path) || plan.path.length < 2) {
      return plan;
    }

    // aqui a rota vem "crua" (ligações porto + rota principal)
    return {
      ...plan,
      distanceKm: pathDistanceKm(plan.path),
    };
  }, [
    embarkIndex,
    destinationIndex,
    category,
    portConnections,
    licenseRouteMap,
    categoryLicenseMap,
  ]);


  const {
    isDepartureValid,
    departureErrorMessage,
    departureIso,
    departurePreview,      // string bonitinha para mostrar ao user
    departurePreviewTime,  // só hora/min
  } = useMemo(() => {
    const now = new Date();

    // “agora” é sempre válido
    if (departureType === "now") {
      const iso = now.toISOString();
      return {
        isDepartureValid: true,
        departureErrorMessage: null,
        departureIso: iso,
        departurePreview: now.toLocaleString("pt-PT", {
          day: "2-digit",
          month: "2-digit",
          year: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        }),
        departurePreviewTime: "Agora",
      };
    }

    // modo “daqui a N horas”
    const raw = Number(departureOffsetHours);
    if (!Number.isFinite(raw)) {
      return {
        isDepartureValid: false,
        departureErrorMessage: "Indica em quantas horas queres sair.",
        departureIso: null,
        departurePreview: null,
        departurePreviewTime: null,
      };
    }

    const hours = Math.max(0, raw);

    if (hours === 0) {
      return {
        isDepartureValid: false,
        departureErrorMessage: "Usa 'Sair agora' ou pelo menos 1 hora.",
        departureIso: null,
        departurePreview: null,
        departurePreviewTime: null,
      };
    }

    if (hours > 12) {
      return {
        isDepartureValid: false,
        departureErrorMessage: "Só podes agendar até 12 horas a partir de agora.",
        departureIso: null,
        departurePreview: null,
        departurePreviewTime: null,
      };
    }

    const departDate = new Date(now.getTime() + hours * 60 * 60 * 1000);

    return {
      isDepartureValid: true,
      departureErrorMessage: null,
      departureIso: departDate.toISOString(),
      departurePreview: departDate.toLocaleString("pt-PT", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      }),
      departurePreviewTime: departDate.toLocaleTimeString("pt-PT", {
        hour: "2-digit",
        minute: "2-digit",
      }),
    };
  }, [departureType, departureOffsetHours]);

        // análise de todas as categorias para esta origem/destino
        const routeAnalysis = useMemo(() => {
          if (embarkIndex == null || destinationIndex == null) return null;
          if (!embark || !destination) return null;

          return categories.map(cat => {
            const plan = buildNetworkPath(
              embarkIndex,
              destinationIndex,
              cat.id,
              portConnections,
              licenseRouteMap,
              categoryLicenseMap
            );

            const distanceKm = plan
              ? plan.distanceKm
              : haversineKm(
                  { lat: embark.lat, lng: embark.lng },
                  { lat: destination.lat, lng: destination.lng }
                );

            const maxOk = typeof cat.maxDistanceKm === "number"
        ? distanceKm <= cat.maxDistanceKm
        : true;

      const base  = cat.basePrice || 0;
      const perKm = cat.pricePerKm || 0;
      const priceEstimate = base + distanceKm * perKm;

      return {
        categoryId: cat.id,
        distanceKm,
        maxOk,
        priceEstimate,
        plan,
      };
          });
        }, [embarkIndex, destinationIndex, embark, destination, categories, portConnections, licenseRouteMap, categoryLicenseMap]);

        // categoria mais barata entre as que aguentam esta rota
  const cheapestAllowed = useMemo(() => {
    if (!routeAnalysis) return null;

    const allowed = routeAnalysis.filter(r => r.maxOk);
    if (!allowed.length) return null;

    return allowed.reduce((best, r) =>
      !best || r.priceEstimate < best.priceEstimate ? r : best,
      null
    );
  }, [routeAnalysis]);


        // distância baseada na rota atual (rede se existir, senão linha reta)
        const distanceKm = useMemo(() => {
          if (Array.isArray(routePoints) && routePoints.length >= 2) {
            return pathDistanceKm(routePoints);
          }
          if (embark && destination) {
            return haversineKm(
              { lat: embark.lat, lng: embark.lng },
              { lat: destination.lat, lng: destination.lng }
            );
          }
          return 0;
        }, [routePoints, embark, destination]);

        const totalStopMinutes = useMemo(
          () => (routePoints || []).reduce((sum, p) => sum + (p.stopMinutes || 0), 0),
          [routePoints]
        );

        const estimate = useMemo(() => {
          if (!category || !distanceKm) return null;
          const base       = category.basePrice || 0;
          const perKm      = category.pricePerKm || 0;
          const price      = base + distanceKm * perKm;
          const speedKnots = category.avgSpeedKnots || 18;
          const speedKmH   = speedKnots * 1.852;
          const movingDuration = Math.max(10, Math.round(distanceKm / speedKmH * 60));
          const duration   = movingDuration + totalStopMinutes;

          return {
            distanceKm: Math.round(distanceKm * 10) / 10,
            price: Math.round(price * 100) / 100,
            durationMinutes: duration,
            movingMinutes: movingDuration,
            stopMinutes: totalStopMinutes,
          };
        }, [category, distanceKm, totalStopMinutes]);

        const exceedsMaxDistance = useMemo(() => {
          if (!category || !distanceKm) return false;
          if (typeof category.maxDistanceKm !== "number") return false;
          return distanceKm > category.maxDistanceKm;
        }, [category, distanceKm]);

const canNextFrom1 =
  embarkIndex != null &&
  destinationIndex != null &&
  embarkIndex !== destinationIndex &&
  isDepartureValid;


  const canNextFrom2 = !!category && !exceedsMaxDistance;


        // sugestões inteligentes de categoria / plano
        const suggestions = useMemo(() => {
          if (!routeAnalysis || !category) return [];
          const out = [];
          const selectedPlan = routeAnalysis.find(r => r.categoryId === category.id);
          if (!selectedPlan) return out;

          // se categoria escolhida não aguenta a distância, sugerir upgrade
          if (!selectedPlan.maxOk) {
            const alternative = routeAnalysis
              .filter(r => r.maxOk)
              .sort((a, b) => a.distanceKm - b.distanceKm)[0];
            if (alternative) {
              const altCat = categories.find(c => c.id === alternative.categoryId);
              if (altCat) {
                out.push({
                  type: 'upgrade',
                  message: `A rota é demasiado longa para ${category.name}. Sugerimos ${altCat.name}, capaz de ~${altCat.maxDistanceKm.toFixed(1)} km.`,
                });
              }
            }
          }

          // se escolheu WaveParty para rota curta, sugerir barco mais pequeno / barato
          if (category.id === 'party' && selectedPlan.distanceKm < 15) {
            const cheaper = routeAnalysis
              .filter(r => r.categoryId !== 'party' && r.maxOk && r.priceEstimate < selectedPlan.priceEstimate)
              .sort((a, b) => a.priceEstimate - b.priceEstimate)[0];
            if (cheaper) {
              const cheaperCat = categories.find(c => c.id === cheaper.categoryId);
              if (cheaperCat) {
                out.push({
                  type: 'cheaper',
                  message: `A distância é curta. Em vez de WaveParty, podias usar ${cheaperCat.name}, com preço estimado de ~${cheaper.priceEstimate.toFixed(2)} €.`,
                });
              }
            }
          }

          // se é barco de alto-mar, avisar que precisa de barco auxiliar
          if (category.id === 'party') {
            out.push({
              type: 'aux',
              message: 'Barcos de alto-mar (WaveParty) devem usar um barco auxiliar (ex.: WaveUp Mini) para o transfer porto ↔ rota de alto mar.',
            });
          }

          return out;
        }, [routeAnalysis, category, categories]);

        function goToStep(nextStep) {
    // limpar erros sempre que mudas de passo
    setError(null);
    // If going to step 1 from step 5, do a full reset
    if (nextStep === 1 && step === 5) {
      resetBooking();
      return;
    }

    // sempre que voltas para ≤3, limpas estado de pagamento
    if (nextStep <= 3) {
      setPaymentStatus(null);
      setIsPaying(false);
    }

    // se voltares para ≤2, esqueces viagem criada
    if (nextStep <= 2) {
      setCreatedTrip(null);
    }

    // se voltares ao passo 1, reset total do “que vem depois”
    if (nextStep === 1) {
      setCategory(null);
      setRoutePoints([]);
  setDepartureType("now");
  setDepartureOffsetHours(1);

    }

    setStep(nextStep);
  }


        // localização do utilizador
        function handleUseMyLocation() {
          if (!navigator.geolocation) {
            setUserLocation({
              status: "error",
              coords: null,
              nearest: null,
              error: "Este navegador não suporta geolocalização.",
            });
            return;
          }

          setUserLocation(prev => ({
            ...prev,
            status: "getting",
            error: null,
          }));

          navigator.geolocation.getCurrentPosition(
            (pos) => {
              const coords = {
                latitude: pos.coords.latitude,
                longitude: pos.coords.longitude,
              };
              const nearest = findNearestPoint(coords, networkPorts);

              if (nearest && typeof nearest.index === "number") {
                setEmbarkIndex(nearest.index);
              }

              setUserLocation({
                status: "success",
                coords,
                nearest,
                error: null,
              });
            },
            (err) => {
              setUserLocation({
                status: "error",
                coords: null,
                nearest: null,
                error: err.message || "Não foi possível obter a tua localização.",
              });
            },
            {
              enableHighAccuracy: true,
              timeout: 10000,
              maximumAge: 60000,
            }
          );
        }

        const recommendedPortLabel = useMemo(() => {
          if (!userLocation.nearest || !userLocation.nearest.point) return null;
          const p = userLocation.nearest.point;
          const d = userLocation.nearest.distanceKm;
          return `${p.name}${p.zone ? " (" + p.zone + ")" : ""} · ${d.toFixed(1)} mN de ti`;
        }, [userLocation.nearest]);

        // funções para waypoints
        function handleInsertStop(afterIndex) {
          setRoutePoints(prev => {
            const arr = Array.isArray(prev) ? prev.slice() : [];
            if (afterIndex < 0 || afterIndex >= arr.length - 1) return prev;
            const a = arr[afterIndex];
            const b = arr[afterIndex + 1];
            const mid = {
              lat: (a.lat + b.lat) / 2,
              lng: (a.lng + b.lng) / 2,
              name: 'Paragem',
              stopMinutes: 15,
            };
            arr.splice(afterIndex + 1, 0, mid);
            return arr;
          });
        }

        function handleStopMinutesChange(idx, minutes) {
          const m = Number.isFinite(minutes) ? Math.max(0, minutes) : 0;
          setRoutePoints(prev => {
            const arr = Array.isArray(prev) ? prev.slice() : [];
            if (!arr[idx]) return prev;
            arr[idx] = { ...arr[idx], stopMinutes: m };
            return arr;
          });
        }

  async function handleStartPayment() {
    if (!estimate || !category || !embark || !destination) {
      setError("Faltam dados para criar a viagem.");
      return;
    }
    if (exceedsMaxDistance) {
      setError("Esta categoria não pode fazer uma viagem tão longa. Escolhe outra categoria ou ajusta os portos.");
      return;
    }

    // NOVO: validar horário escolhido
    if (!isDepartureValid) {
      setError(
        departureErrorMessage ||
        "Escolhe uma hora de saída válida (até 12h a partir de agora)."
      );
      return;
    }

    setError(null);
    setIsPaying(true);
    setPaymentStatus(null);

    const routeForBackend = (routePoints || []).map(p => ({
      lat: p.lat,
      lng: p.lng,
      name: p.name || "",
      stopMinutes: p.stopMinutes || 0,
    }));

          setTimeout(() => {
            fetch("./api/trips.php", {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                action: "create",

                embarkId: embark.id ?? null,
                destinationId: destination.id ?? null,
                categoryId: category.id,

                embarkName: embark.name,
                embarkZone: embark.zone ?? "",
                embarkLat: typeof embark.lat === "number" ? embark.lat : null,
                embarkLng: typeof embark.lng === "number" ? embark.lng : null,

                destinationName: destination.name,
                destinationZone: destination.zone ?? "",
                destinationLat: typeof destination.lat === "number" ? destination.lat : null,
                destinationLng: typeof destination.lng === "number" ? destination.lng : null,

                categoryName: category.name,

  distanceKm: estimate.distanceKm,
    estimatedDurationMinutes: estimate.durationMinutes,
    estimatedPrice: estimate.price,

    route: routeForBackend,

    departureAt: departureIso,

    customerId: currentUser.id,
    customerName: currentUser.name,
    customerPhone: currentUser.phone || "",
              })
            })
              .then(res => res.json())
              .then(data => {
                if (!data.success) {
                  throw new Error(data.error || "Erro ao criar viagem");
                }
                setCreatedTrip(data.trip);
                setActiveTrip(data.trip);
                setPaymentStatus("success");
                setStep(4);
              })
              .catch(err => {
                console.error(err);
                setPaymentStatus("error");
                setError("Erro ao registar a viagem depois do pagamento demo.");
              })
              .finally(() => {
                setIsPaying(false);
              });
          }, 500);
        }

    // When a new trip is created, update activeTrip
  useEffect(() => {
    if (createdTrip) {
      setActiveTrip(createdTrip);
    }
  }, [createdTrip]);

  // Reset function - properly clear everything
  function resetBooking() {
    setStep(1);
    setEmbarkIndex(null);
    setDestinationIndex(null);
    setCategory(null);
    setPaymentStatus(null);
    setCreatedTrip(null);
    setError(null);
    setRoutePoints([]);
    setDepartureType("now");
    setDepartureOffsetHours(1);
    setActiveTrip(null); // Clear active trip completely
  }
  
  function handleCancelActiveTrip() {
    if (!activeTrip) return;

    const tripId = activeTrip.id ?? activeTrip.tripId;
    if (!tripId) {
      setError("Não foi possível obter o ID da viagem.");
      return;
    }

    if (!confirm("Tens a certeza que queres cancelar esta viagem?")) return;

    fetch("./api/trips.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "cancel",
        tripId,
      }),
    })
      .then((res) => res.json())
      .then((data) => {
        if (!data.success) {
          throw new Error(data.error || "Erro ao cancelar a viagem.");
        }
        // Reset everything properly
        resetBooking();
      })
      .catch((err) => {
        console.error(err);
        setError("Erro ao cancelar a viagem.");
      });
  }

useEffect(() => {
  if (!activeTrip) return;
  
  const tripId = activeTrip.id ?? activeTrip.tripId;
  if (!tripId) return;

  let cancelled = false;

  const pollTrip = () => {
    if (cancelled) return;

    fetch("./api/trips.php?tripId=" + encodeURIComponent(tripId))
      .then((res) => res.json())
      .then((data) => {
        if (cancelled) return;

        if (data.success && data.trip) {
          const updatedTrip = data.trip;
          setActiveTrip(updatedTrip);

          if (updatedTrip.status === 'completed') {
            setStep(5);
          }
        }
      })
      .catch(() => {});
  };

  pollTrip();
  const interval = setInterval(pollTrip, 3000);

  return () => {
    cancelled = true;
    clearInterval(interval);
  };
}, [activeTrip?.id]);


const travelMapRef = useRef(null);

useEffect(() => {
  if (!activeTrip) return;

  const tripKey = activeTrip.id ?? activeTrip.tripId;
  if (!tripKey) return;

  const mapEl = document.getElementById("travel-map");
  if (!mapEl) return;
  if (typeof L === "undefined") return;

  // limpa mapa antigo se existir
  if (travelMapRef.current) {
    try {
      travelMapRef.current.remove();
    } catch (e) {}
    travelMapRef.current = null;
  }

  // cria novo mapa
  const map = L.map(mapEl, {
    zoomControl: false,
    dragging: true,
    scrollWheelZoom: true,
    doubleClickZoom: true,
    boxZoom: true,
    keyboard: true,
    tap: true,
    touchZoom: true,
  });

  travelMapRef.current = map;

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 10,
  }).addTo(map);

  const routeLine = L.polyline([], {
    color: "#383838ff",
    weight: 3,
  }).addTo(map);

  // --- MARCADORES DE EMBARQUE E DESTINO ---
  let embarkMarker = null;
  let destMarker = null;

  if (activeTrip.embarkLat && activeTrip.embarkLng) {
    embarkMarker = L.circleMarker(
      [activeTrip.embarkLat, activeTrip.embarkLng],
      {
        radius: 6,
        color: "#22c55e",
        fillColor: "#22c55e",
        fillOpacity: 1,
        weight: 2,
      }
    ).addTo(map);
    embarkMarker.bindTooltip("Embarque");
  }

  if (activeTrip.destinationLat && activeTrip.destinationLng) {
    destMarker = L.circleMarker(
      [activeTrip.destinationLat, activeTrip.destinationLng],
      {
        radius: 6,
        color: "#f91616",
        fillColor: "#f91616",
        fillOpacity: 1,
        weight: 2,
      }
    ).addTo(map);
    destMarker.bindTooltip("Destino");
  }

  // --- PONTO INICIAL "INTELIGENTE" ---
  function getInitialLatLng() {
    // Para viagens completadas, mostrar o destino
    if (activeTrip.status === 'completed' && activeTrip.destinationLat && activeTrip.destinationLng) {
      return [activeTrip.destinationLat, activeTrip.destinationLng];
    }
    
    // SEMPRE começar no ponto de embarque para outras situações
    if (activeTrip.embarkLat && activeTrip.embarkLng) {
      return [activeTrip.embarkLat, activeTrip.embarkLng];
    }

    // fallback: primeiro ponto da rota
    if (Array.isArray(activeTrip.route) && activeTrip.route.length > 0) {
      const p0 = activeTrip.route[0];
      if (typeof p0.lat === "number" && typeof p0.lng === "number") {
        return [p0.lat, p0.lng];
      }
    }

    // fallback seguro (Lisboa)
    return [38.72, -9.14];
  }

  const initialLatLng = getInitialLatLng();

  // Criar marcador do skipper
  const skipperMarker = L.circleMarker(initialLatLng, {
    radius: 6,
    color: "#f0f",
    fillColor: "#f0f",
    fillOpacity: 1,
    weight: 2,
  }).addTo(map);

  // 👉 SEMPRE começar na vista correta
  map.setView(initialLatLng, 14);

  // --- ROTA ---
  if (Array.isArray(activeTrip.route) && activeTrip.route.length >= 2) {
    const latlngs = activeTrip.route
      .filter(p => typeof p.lat === "number" && typeof p.lng === "number")
      .map(p => [p.lat, p.lng]);

    if (latlngs.length >= 2) {
      routeLine.setLatLngs(latlngs);
    }
  }

  // --- TRACK DO SKIPPER (polling) ---
  const FOLLOW_ZOOM = 14;

  requestAnimationFrame(() => {
    try {
      map.invalidateSize();
    } catch (e) {}
  });

  let pollingInterval = null;

  // Só começar a tracking quando o skipper for atribuído E a viagem estiver ativa
  if (activeTrip.skipperId && (activeTrip.status === 'assigned' || activeTrip.status === 'in-progress')) {
    pollingInterval = setInterval(() => {
      fetch(`./api/tripTrack.php?tripId=${tripKey}`)
        .then((r) => r.json())
        .then((data) => {
          if (data.success && data.position) {
            const { lat, lng } = data.position;
            if (typeof lat === "number" && typeof lng === "number") {
              const ll = [lat, lng];
              
              skipperMarker.setLatLng(ll);
              
              // 👉 manter o mapa centrado no skipper
              map.setView(ll, FOLLOW_ZOOM);
            }
          }
        })
        .catch(() => {});
    }, 1000);
  } else if (activeTrip.status === 'completed') {
    // Para viagens completadas, mostrar no destino
    skipperMarker.bindTooltip("Viagem Concluída", { permanent: true });
    if (activeTrip.destinationLat && activeTrip.destinationLng) {
      skipperMarker.setLatLng([activeTrip.destinationLat, activeTrip.destinationLng]);
      map.setView([activeTrip.destinationLat, activeTrip.destinationLng], FOLLOW_ZOOM);
    }
  } else {
    // Se não há skipper ainda, mostrar mensagem no mapa
    skipperMarker.bindTooltip("À espera de skipper...", { permanent: true });
  }

 return () => {
    if (pollingInterval) clearInterval(pollingInterval);
    if (travelMapRef.current) {
      try {
        travelMapRef.current.remove();
      } catch (e) {}
      travelMapRef.current = null;
    }
  };
}, [activeTrip?.id ?? activeTrip?.tripId, activeTrip?.skipperId, activeTrip?.status]);

  useEffect(() => {
    const mapEl = document.getElementById("booking-map");

    // se não há categoria ou o elemento do mapa não existe,
    // faz cleanup do mapa Leaflet
    if (!category || !mapEl) {
      if (mapRef.current) {
        mapRef.current.remove();   // destrói o mapa Leaflet
        mapRef.current = null;
        polylineRef.current = null;
        markersRef.current = [];
      }
      return;
    }

    if (typeof L === "undefined") return;

    // se já existir mapa *válido*, não recriar
    if (mapRef.current) return;

    const iberiaBounds = L.latLngBounds(
      [34.0, -11.0],
      [45.0,   4.0]
    );

const map = L.map(mapEl, {
  center: [40, -4],
  zoom: 5,
  maxBounds: iberiaBounds,
  maxBoundsViscosity: 0.9,
  minZoom: 4,
  zoomControl: false,
  dragging: false,
  scrollWheelZoom: false,
  doubleClickZoom: false,
  boxZoom: false,
  keyboard: false,
  tap: false,
  touchZoom: false,
});

L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
  maxZoom: 10,
}).addTo(map);


    mapRef.current = map;

    const polyline = L.polyline([], {
      color: "#1f1f1fff",
      weight: 3,
    }).addTo(map);
    polylineRef.current = polyline;
    markersRef.current = [];
  }, [category]);


  useEffect(() => {
    if (!embark || !destination) {
      setRoutePoints([]);
      return;
    }

    // a partir do passo 3 já podes ter waypoints custom → não mexer mais na rota
    if (step >= 3) {
      return;
    }

    let baseRoute = [];

  if (
    category &&
    networkPlan &&
    Array.isArray(networkPlan.path) &&
    networkPlan.path.length >= 2
  ) {
    // rota completa: porto A → ligação A → rota principal → ligação B → porto B
    baseRoute = networkPlan.path.map((p, idx, arr) => ({
      lat: p.lat,
      lng: p.lng,
      name:
        idx === 0
          ? "Embarque"
          : idx === arr.length - 1
            ? "Destino"
            : (p.name || "Ponto"),
      stopMinutes: p.stopMinutes || 0,
      segment: p.segment || "main", // 'portA' | 'main' | 'portB'
      _edited: false,
    }));
  } else {
    // fallback linha reta
    baseRoute = [
      { lat: embark.lat,      lng: embark.lng,      name: "Embarque", stopMinutes: 0, segment: "portA", _edited: false },
      { lat: destination.lat, lng: destination.lng, name: "Destino",  stopMinutes: 0, segment: "portB", _edited: false },
    ];
  }

const denseRoute = densifyRoute(baseRoute, 0.1);
const smoothed   = optimizeJunctions(denseRoute);
const cleaned    = simplifySharpAngles(smoothed, 25, 0.4);

setRoutePoints(cleaned);


  }, [embark, destination, category, networkPlan, step]);







        // sync rota → mapa
        useEffect(() => {
          const map      = mapRef.current;
          const polyline = polylineRef.current;
          if (!map || !polyline) return;

          const route = Array.isArray(routePoints) ? routePoints : [];
          if (route.length < 2) {
            polyline.setLatLngs([]);
            markersRef.current.forEach(m => map.removeLayer(m));
            markersRef.current = [];
            return;
          }

          polyline.setLatLngs(route.map(p => [p.lat, p.lng]));

          markersRef.current.forEach(m => map.removeLayer(m));
          markersRef.current = [];

  route.forEach((p, idx) => {
    let color;

  if (idx === 0) {
    // embarque
    color = '#22c55e';
  } else if (idx === route.length - 1) {
    // destino
    color = '#f91616ff';
  } else {
    // ponto normal (invisível / sem cor)
    color = '#00000000';
  }


    const m = L.circleMarker([p.lat, p.lng], {
      radius: 4,
      color,
      fillColor: color,
      weight: 2,
      fillOpacity: 1,
    }).addTo(map);

    const label =
      idx === 0
        ? 'Embarque'
        : idx === route.length - 1
          ? 'Destino'
          : (p.name || 'Ponto');

    m.bindTooltip(label, { permanent: false });
    markersRef.current.push(m);
  });


          map.fitBounds(polyline.getBounds().pad(0.2));
        }, [routePoints]);

      // proteção: só clientes
      if (!currentUser || currentUser.type !== 'customer') {
        return (
          <div className="waveup-card px-3 py-3 sm:px-4 sm:py-4">
            <h2 className="text-lg font-semibold mb-2">Precisas de uma conta de cliente</h2>
            <p className="text-sm text-slate-400 mb-4">
              Estás autenticado como skipper ou não tens sessão iniciada. Usa uma conta de cliente para reservar.
            </p>
            <a href="./index.php?page=logout" className="waveup-btn">Mudar de conta</a>
          </div>
        );
      }

      // UI quando JÁ EXISTE viagem ativa
      if (activeTrip && activeTrip.status !== 'completed') {
        return (
          <div className="grid md:grid-cols-[2fr,1fr] gap-4">
            {/* MAPA */}
            <div className="waveup-card p-0">
              <div className="p-3 border-b border-slate-800">
                <h2 className="text-lg font-semibold">A tua viagem</h2>
                <p className="text-xs text-slate-400">
                  Acompanhamento em tempo real
                </p>
              </div>

              <div id="travel-map" className="w-full h-[380px]"></div>
            </div>

            {/* COLUNA DIREITA */}
            <div className="space-y-4 order-2 md:order-none">
<div className="waveup-card px-3 py-3 sm:px-4 sm:py-4">
  <h3 className="text-sm font-semibold mb-2">Skipper &amp; Barco</h3>

  {activeTrip.skipperId ? (
    <>
      <div className="flex items-center gap-3">
        <img
          src={activeTrip.skipperAvatar || "./images/users/default-avatar.png"}
          className="w-12 h-12 rounded-full border border-slate-700 object-cover"
        />
        <div>
          <div className="text-slate-100 text-sm font-medium">
            {activeTrip.skipperName}
          </div>
          <div className="text-[11px] text-slate-400">
            📞 {activeTrip.skipperPhone || "Contacto não disponível"}
          </div>
          {activeTrip.status === 'assigned' && (
            <div className="text-[10px] text-emerald-400 mt-1">
              ✅ Skipper a caminho do ponto de embarque
            </div>
          )}
        </div>
      </div>

      {activeTrip.skipperBoatName && (
        <div className="flex items-center gap-3 mt-3">
          <img
            src={activeTrip.skipperBoatImage || "./images/boats/default-boat.jpg"}
            className="w-20 h-16 rounded-lg border border-slate-700 object-cover"
          />
          <div>
            <div className="text-slate-100 text-xs">Barco</div>
            <div className="text-slate-300 text-sm">
              {activeTrip.skipperBoatName}
            </div>
          </div>
        </div>
      )}

      {/* Chat depois de viagem aceite */}
      {activeTrip.skipperId && (
        <div className="flex-1 overflow-y-auto mt-4 mb-4">
          <div className="flex flex-col h-64 text-xs">
            <TripChatMessages
              tripId={activeTrip.id ?? activeTrip.tripId}
              currentUser={currentUser}
            />
            <InputChatSection
              key={activeTrip.id ?? activeTrip.tripId}
              tripId={activeTrip.id ?? activeTrip.tripId}
              currentUser={currentUser}
            />
          </div>
        </div>
      )}
    </>
  ) : (
    <div className="text-center py-4">
      <div className="text-4xl mb-2">⛵</div>
      <p className="text-xs text-amber-400 mb-2">
        A procurar skipper disponível...
      </p>
      <p className="text-[10px] text-slate-500">
        O mapa mostra o ponto de embarque. Acompanhamento em tempo real começará quando o skipper aceitar a viagem.
      </p>
    </div>
  )}

                {/* Botão de contactos de emergência */}
                <div className="mt-2">
                  <button
                    onClick={() => alert("Número de emergência marítima: 112")}
                    className="waveup-btn w-full text-sm py-2"
                  >
                    Contactos de emergência
                  </button>
                </div>

                <div className="mt-2">
                  <button
                    onClick={handleCancelActiveTrip}
                    className="waveup-btn-outline w-full text-sm py-2 text-red-400 border-red-400"
                  >
                    Cancelar viagem
                  </button>
                </div>
              </div>
            </div>
          </div>
        );
      }

      // UI normal (sem viagem ativa) – ESTE É O RETURN PRINCIPAL
      return (
        <div className="grid grid-cols-1 md:grid-cols-[2fr_1fr] gap-4 md:gap-6">
          {/* Coluna principal: wizard da nova viagem */}
          <div className="space-y-4 order-1 md:order-none">
            <div className="waveup-card px-3 py-3 sm:px-4 sm:py-4">
            
              <div className="flex items-center justify-between mb-4">
                <div>
                  <h2 className="text-lg font-semibold">Nova viagem</h2>
                  <p className="text-xs text-slate-400">
                    Escolhe embarque, destino e categoria de barco.
                  </p>
                </div>
              </div>

              {error && (
                <div className="mb-3 text-xs text-red-400 bg-red-950/40 border border-red-500/40 rounded-lg px-3 py-2">
                  {error}
                </div>
              )}

              {/* STEP 1 */}
              {step === 1 && (
                <div className="space-y-4 order-1 md:order-none">
                  <div className="flex items-center justify-between gap-2">
                    <label className="text-xs text-slate-300 mb-1 block">
                      Local de embarque (apenas portos com rota configurada)
                    </label>
                    <button
                      type="button"
                      onClick={handleUseMyLocation}
                      className="text-[11px] text-secondary underline underline-offset-2"
                    >
                      {userLocation.status === "getting"
                        ? "A obter localização…"
                        : "Usar minha localização"}
                    </button>
                  </div>
                  <select
                    className="w-full bg-slate-900 text-sm min-w-0 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                    value={embarkIndex != null ? String(embarkIndex) : ""}
                    onChange={(e) => {
                      const v = e.target.value;
                      if (v === "") {
                        setEmbarkIndex(null);
                      } else {
                        const idx = Number(v);
                        setEmbarkIndex(Number.isNaN(idx) ? null : idx);
                      }
                    }}
                  >
                    <option value="">Seleciona a marina de embarque</option>
                    {networkPorts.map((p) => {
                      const isRecommended =
                        userLocation.nearest &&
                        typeof userLocation.nearest.index === "number" &&
                        userLocation.nearest.index === p._index;

                      return (
                        <option key={p._index} value={String(p._index)}>
                          {isRecommended ? "" : ""}
                          {p.name}{p.zone ? ` (${p.zone})` : ""}
                        </option>
                      );
                    })}
                  </select>

                  {userLocation.status === "success" && recommendedPortLabel && (
                    <p className="text-[11px] text-emerald-400">
                      Porto recomendado pela tua localização: {recommendedPortLabel}
                    </p>
                  )}
                  {userLocation.status === "error" && userLocation.error && (
                    <p className="text-[11px] text-amber-400">
                      {userLocation.error}
                    </p>
                  )}

                  <div>
                    <label className="text-xs text-slate-300 mb-1 block">
                      Local de desembarque (apenas portos com rota configurada)
                    </label>
                    <select
                      className="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                      value={destinationIndex != null ? String(destinationIndex) : ""}
                      onChange={(e) => {
                        const v = e.target.value;
                        if (v === "") {
                          setDestinationIndex(null);
                        } else {
                          const idx = Number(v);
                          setDestinationIndex(Number.isNaN(idx) ? null : idx);
                        }
                      }}
                    >
                      <option value="">Seleciona a marina de destino</option>
                      {networkPorts.map((p) => (
                        <option key={p._index} value={String(p._index)}>
                          {p.name}{p.zone ? ` (${p.zone})` : ""}
                        </option>
                      ))}
                    </select>

                    {embark && destination && embarkIndex === destinationIndex && (
                      <p className="mt-1 text-[11px] text-amber-400">
                        Embarque e destino são iguais — escolhe marinas diferentes.
                      </p>
                    )}
                  </div>

                  {/* Hora da saída */}
                  <div>
                    <h3 className="text-xs font-semibold mb-1 text-slate-200">
                      Hora da saída
                    </h3>
                    <p className="text-[11px] text-slate-400 mb-2">
                      Podes sair já ou agendar até 12 horas a partir de agora.
                    </p>

                    <div className="flex flex-col gap-2 text-xs">
                      <label className="inline-flex items-center gap-2">
                        <input
                          type="radio"
                          className="text-secondary"
                          checked={departureType === "now"}
                          onChange={() => setDepartureType("now")}
                        />
                        <span>Sair agora</span>
                      </label>

                      <label className="inline-flex items-center gap-2">
                        <input
                          type="radio"
                          className="text-secondary"
                          checked={departureType === "later"}
                          onChange={() => setDepartureType("later")}
                        />
                        <span>Agendar daqui a N horas</span>
                      </label>

                      {departureType === "later" && (
                        <div className="pl-6 space-y-1">
                          <div className="flex items-center gap-2">
                            <input
                              type="number"
                              min={1}
                              max={12}
                              step={1}
                              className="w-20 bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-sky-500"
                              value={departureOffsetHours}
                              onChange={(e) => setDepartureOffsetHours(e.target.value)}
                            />
                            <span className="text-[11px] text-slate-300">
                              horas a partir de agora
                            </span>
                          </div>

                          <p className="text-[11px] text-slate-500">
                            Máx. 12h a partir de agora.
                            {isDepartureValid && departurePreview && (
                              <> Hora prevista: <span className="text-slate-200">{departurePreview}</span></>
                            )}
                          </p>
                        </div>
                      )}

                      {!isDepartureValid && departureErrorMessage && (
                        <p className="text-[11px] text-amber-400">
                          {departureErrorMessage}
                        </p>
                      )}
                    </div>
                  </div>

                  <div className="flex justify-end">
                    <button
                      type="button"
                      disabled={!canNextFrom1}
                      onClick={() => goToStep(2)}
                      className={
                        "waveup-btn px-4 py-2 text-sm " +
                        (!canNextFrom1 ? "opacity-50 cursor-not-allowed" : "")
                      }
                    >
                      Continuar
                    </button>
                  </div>
                </div>
              )}

              {/* STEP 2 */}
              {step === 2 && (
                <div className="space-y-3">
                  <p className="text-xs text-slate-400 mb-1">
                    Escolhe o tipo de barco para esta viagem.
                  </p>
                  <div className="space-y-2 overflow-auto h-[60vh] pr-2">
                    {categories.map((cat) => {
                      const selected = category && category.id === cat.id;
                      const analysis = routeAnalysis
                        ? routeAnalysis.find(r => r.categoryId === cat.id)
                        : null;
                      const distance = analysis ? analysis.distanceKm : distanceKm;
                      const ok = analysis ? analysis.maxOk : true;

                      if (!ok) return null;

return (
  <button
    key={cat.id}
    type="button"
    onClick={() => setCategory(cat)}
    className={
      "w-full mb-2 rounded-xl overflow-hidden border text-left transition " +
      (selected
        ? "border-secondary bg-secondary/10 shadow-[0_0_0_1px_rgba(56,189,248,0.4)]"
        : "border-slate-700 bg-slate-950/70 hover:border-slate-500 hover:bg-slate-900/80")
    }
  >
    <div className="flex flex-col sm:flex-row">
      {/* Lado esquerdo: imagem grande */}
      <div className="relative w-full sm:w-40 h-32 sm:h-28 flex-shrink-0">
        {cat.image ? (
          <img
            src={cat.image}
            alt={cat.name}
            className="w-full h-full object-cover"
          />
        ) : (
          <div className="w-full h-full bg-slate-900 flex items-center justify-center text-[11px] text-slate-500">
            Sem imagem
          </div>
        )}

        {/* Badge em cima da imagem */}
        <div className="absolute bottom-1 left-1 px-2 py-[2px] rounded-full bg-black/60 backdrop-blur text-[10px] text-slate-100">
          {cat.capacity != 0 ? `Recomenda-se para ${cat.capacity} pax` : "Não transporta pessoas"}
        </div>

        {selected && (
          <div className="absolute top-1 right-1 w-5 h-5 rounded-full bg-secondary flex items-center justify-center text-[11px] text-slate-950">
            ✓
          </div>
        )}
      </div>

      {/* Lado direito: texto / detalhes */}
      <div className="flex-1 px-3 py-2 sm:px-4 sm:py-3 flex flex-col justify-between gap-1">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <div className="font-medium text-slate-100 text-sm truncate">
              {cat.name}
            </div>
            <div className="text-[11px] text-slate-400">
              ~{distance.toFixed(1)} mN
            </div>

            {analysis && (
              <div className="text-[10px] text-slate-500 mt-0.5">
                Desde {cat.basePrice}€
              </div>
            )}
          </div>

          <div className="text-right text-[11px] text-slate-400 flex-shrink-0">
            <div className="font-medium text-slate-100 text-sm truncate">
              {(analysis.priceEstimate || 0).toFixed(2)} €
            </div>
            <div>~{cat.avgSpeedKnots} nós</div>
            {typeof cat.maxDistanceKm === "number" && (
              <div className="text-[10px] text-slate-500">
                {cat.maxDistanceNm > 9000 ? (
                  "Sem limite de distância"
                ) : (
                  <>Máx. {cat.maxDistanceNm.toFixed(1)} mN</>
                )}
                {!ok && (
                  <span className="text-amber-400 ml-1">(excede)</span>
                )}
              </div>
            )}
          </div>
        </div>

        {cat.features && cat.features.length > 0 && (
          <div className="mt-1 flex flex-wrap gap-1">
            {cat.features.map((f, idx) => (
              <span
                key={`${cat.id}-${idx}`}
                className="waveup-chip text-[10px] text-slate-300"
              >
                {f}
              </span>
            ))}
          </div>
        )}
      </div>
    </div>
  </button>
);

                    })}
                  </div>

                  {category && exceedsMaxDistance && (
                    <p className="text-[11px] text-amber-400 mt-1">
                      A categoria <strong>{category.name}</strong> só permite até{" "}
                      {category.maxDistanceKm.toFixed(1)} mN e esta rota tem{" "}
                      {distanceKm.toFixed(1)} mN. Escolhe outra categoria ou ajusta os portos.
                    </p>
                  )}

                  {category && suggestions.length > 0 && (
                    <div className="mt-2 border border-slate-800 bg-slate-950/60 rounded-lg px-3 py-2 text-[11px] space-y-1">
                      {suggestions.map((s, idx) => (
                        <div key={idx} className="flex items-start gap-2">
                          <span className="mt-[2px]">
                            {s.type === 'cheaper' && ""}
                            {s.type === 'upgrade' && ""}
                            {s.type === 'aux' && ""}
                          </span>
                          <p className="text-slate-300">{s.message}</p>
                        </div>
                      ))}
                    </div>
                  )}

                  <div className="flex justify-between mt-2">
                    <button
                      type="button"
                      onClick={() => goToStep(1)}
                      className="waveup-btn-outline px-4 py-2 text-xs"
                    >
                      Voltar
                    </button>
                    <button
                      type="button"
                      disabled={!canNextFrom2}
                      onClick={() => setStep(3)}
                      className={
                        "waveup-btn px-4 py-2 text-sm " +
                        (!canNextFrom2 ? "opacity-50 cursor-not-allowed" : "")
                      }
                    >
                      Continuar
                    </button>
                  </div>
                </div>
              )}

              {/* STEP 3 */}
              {step === 3 && (
                <div className="space-y-4 order-1 md:order-none">
                  <div>
                    <h3 className="text-sm font-semibold mb-1">Resumo da viagem</h3>
                    <p className="text-xs text-slate-400 mb-2">
                      Confirma os detalhes e depois processa o pagamento demo.
                    </p>

                    <ul className="text-xs text-slate-300 space-y-1">
                      <li>
                        <span className="text-slate-400">Embarque:</span>{" "}
                        {embark?.name} {embark?.zone ? `(${embark.zone})` : ""}
                      </li>
                      <li>
                        <span className="text-slate-400">Destino:</span>{" "}
                        {destination?.name} {destination?.zone ? `(${destination.zone})` : ""}
                      </li>
                      <li>
                        <span className="text-slate-400">Categoria:</span>{" "}
                        {category?.name}
                      </li>
                      {estimate && (
                        <>
                          <li>
                            <span className="text-slate-400">Distância pela rota:</span>{" "}
                            {(estimate.distanceKm / 1.852).toFixed(1)} mN
                          </li>
                          <li>
                            <span className="text-slate-400">Tempo em movimento:</span>{" "}
                            ~{estimate.movingMinutes} min
                          </li>
                          <li>
                            <span className="text-slate-400">Paragens totais:</span>{" "}
                            {estimate.stopMinutes} min
                          </li>
                          <li>
                            <span className="text-slate-400">Duração estimada:</span>{" "}
                            ~{estimate.durationMinutes} min
                          </li>
                          <li>
                            <span className="text-slate-400">Preço demo:</span>{" "}
                            <span className="font-semibold text-slate-100">
                              {estimate.price.toFixed(2)} €
                            </span>
                          </li>
                          <li>
                            <span className="text-slate-400">Saída:</span>{" "}
                            {departureType === "now"
                              ? "Agora"
                              : (departurePreview || "—")}
                          </li>
                        </>
                      )}
                    </ul>
                  </div>

                  <div className="space-y-2 text-xs text-slate-400">
                    <p>
                      Este pagamento é <span className="text-secondary">apenas uma simulação</span>.
                      Nenhum valor real será cobrado.
                    </p>
                    {paymentStatus === "success" && (
                      <div className="text-emerald-400 bg-emerald-950/40 border border-emerald-500/40 rounded-lg px-3 py-2">
                        Pagamento demo concluído ✅ — a tua viagem foi registada.
                      </div>
                    )}
                    {paymentStatus === "error" && (
                      <div className="text-red-400 bg-red-950/40 border border-red-500/40 rounded-lg px-3 py-2">
                        Ocorreu um erro ao registar a viagem após o pagamento demo.
                      </div>
                    )}
                  </div>

                  <div className="flex justify-between">
                    <button
                      type="button"
                      onClick={() => goToStep(2)}
                      className="waveup-btn-outline px-4 py-2 text-xs"
                    >
                      Voltar
                    </button>
                    <button
                      type="button"
                      onClick={handleStartPayment}
                      disabled={isPaying || !isDepartureValid}
                      className={
                        "waveup-btn px-4 py-2 text-sm flex items-center gap-2 " +
                        (isPaying || !isDepartureValid ? "opacity-80 cursor-not-allowed" : "")
                      }
                    >
                      {isPaying ? (
                        <>
                          <span className="w-3 h-3 border-2 border-slate-100 border-t-transparent rounded-full animate-spin" />
                          <span>A processar pagamento demo…</span>
                        </>
                      ) : (
                        <span>Confirmar &amp; pagar demo</span>
                      )}
                    </button>
                  </div>
                </div>
              )}

              {/* STEP 4 */}
              {step === 4 && createdTrip && (
                <div className="space-y-4 order-1 md:order-none">
                  <h3 className="text-sm font-semibold">Viagem registada ✅</h3>
                  <p className="text-xs text-slate-400">
                    O teu pedido foi enviado para os skippers elegíveis, com base na categoria do barco.
                    O skipper poderá ajustar a rota detalhada no mapa antes da saída.
                  </p>
                  <ul className="text-xs text-slate-300 space-y-1">
                    <li>
                      <span className="text-slate-400">ID da viagem:</span>{" "}
                      {createdTrip.id}
                    </li>
                    <li>
                      <span className="text-slate-400">Rota:</span>{" "}
                      {createdTrip.embarkName} → {createdTrip.destinationName}
                    </li>
                    <li>
                      <span className="text-slate-400">Categoria:</span>{" "}
                      {createdTrip.categoryName}
                    </li>
                    {createdTrip.departureAt && (
                      <li>
                        <span className="text-slate-400">Saída:</span>{" "}
                        {formatDateTimePt(createdTrip.departureAt)}
                      </li>
                    )}
                    <li>
                      <span className="text-slate-400">Preço demo:</span>{" "}
                      {createdTrip.estimatedPrice.toFixed
                        ? createdTrip.estimatedPrice.toFixed(2)
                        : createdTrip.estimatedPrice}{" "}
                      €
                    </li>
                    <li>
                      <span className="text-slate-400">Estado:</span>{" "}
                      {createdTrip.status}
                    </li>
                  </ul>

                  <div className="flex justify-between">
                    <button
                      type="button"
                      onClick={resetBooking}
                      className="waveup-btn-outline px-4 py-2 text-xs"
                    >
                      Nova viagem
                    </button>
                    <a
                      href="./index.php?page=claim"
                      className="text-[11px] text-slate-400 underline"
                    >
                      (Se entrares como skipper, verás este pedido se a tua carta permitir)
                    </a>
                  </div>
                </div>
              )}

{step === 5 && activeTrip && activeTrip.status === 'completed' && (
  <div className="space-y-4 text-center py-8">
    <h3 className="text-xl font-semibold text-emerald-400">Viagem Concluída!</h3>
    <p className="text-sm text-slate-300 max-w-md mx-auto">
      Obrigado por viajar connosco. Esperamos que tenhas tido uma excelente experiência a bordo.
    </p>

    <div className="flex flex-col gap-3 justify-center items-center mt-6">
      <button
        onClick={resetBooking}
        className="waveup-btn px-6 py-3 text-sm"
      >
        Fazer Nova Reserva
      </button>
      <p className="text-xs text-slate-500">
        Pronto para a próxima aventura?
      </p>
    </div>
  </div>
)}
            </div>
          </div>

          {/* Detalhes do cliente */}
          <div>
          <div className="waveup-card px-3 py-3 sm:px-4 sm:py-4">
            <div className="flex flex-col gap-1 text-right">
    <StepBadge step={1} current={step} label="Embarque &amp; Destino" />
    <StepBadge step={2} current={step} label="Categoria" />
    <StepBadge step={3} current={step} label="Rota &amp; Pagamento" />
    <StepBadge step={4} current={step} label="Confirmação" />
    <StepBadge step={5} current={step} label="Concluída" />
  </div>
  </div>
          <div className="waveup-card px-3 py-3 mt-2 sm:px-4 sm:py-4">
            <h3 className="text-sm font-semibold mb-2">Detalhes do cliente</h3>

            <div className="flex items-center gap-3 mb-3">
              {currentUser.avatar && (
                <img
                  src={currentUser.avatar}
                  alt={currentUser.name}
                  className="w-10 h-10 rounded-full object-cover border border-slate-700"
                />
              )}
              <div>
                <div className="text-xs font-medium text-slate-100">
                  {currentUser.name}
                </div>
                <div className="text-[11px] text-slate-400">
                  {currentUser.email}
                </div>
              </div>
            </div>

            <ul className="text-xs text-slate-300 space-y-1">
              <li>
                <span className="text-slate-400">Contacto:</span>{" "}
                {currentUser.phone || "—"}
              </li>
              {embark && (
                <li>
                  <span className="text-slate-400">Embarque:</span>{" "}
                  {embark.name}
                </li>
              )}
              {destination && (
                <li>
                  <span className="text-slate-400">Destino:</span>{" "}
                  {destination.name}
                </li>
              )}
              {category && (
                <li>
                  <span className="text-slate-400">Categoria:</span>{" "}
                  {category.name}
                </li>
              )}
              {isDepartureValid && (
                <li>
                  <span className="text-slate-400">Saída:</span>{" "}
                  {departureType === "now"
                    ? "Agora"
                    : (departurePreviewTime || "—")}
                </li>
              )}
            </ul>
            </div>
                                {/* Mapa da rota (preview) */}
          {category && step <= 3 && (
            <div className="waveup-card px-3 py-3 mt-2 sm:px-4 sm:py-4">
              <div className="flex items-center justify-between mb-2">
                <h3 className="text-sm font-semibold">Mapa da rota (preview)</h3>
              </div>
              <div
                id="booking-map"
                className="w-full h-64 rounded-lg overflow-hidden border border-slate-800"
              />
            </div>
          )}
          {step == 5 && (
              <div className="waveup-card bg-slate-900/50 max-w-md mx-auto mt-4">
      <h4 className="text-sm font-semibold mb-2">Resumo da Viagem</h4>
      <ul className="text-xs text-slate-300 space-y-1 text-left">
        <li><span className="text-slate-400">Rota:</span> {activeTrip.embarkName} → {activeTrip.destinationName}</li>
        <li><span className="text-slate-400">Categoria:</span> {activeTrip.categoryName}</li>
        <li><span className="text-slate-400">Distância:</span> {activeTrip.distanceKm ? (activeTrip.distanceKm / 1.852).toFixed(1) + ' mN' : '—'}</li>
        <li><span className="text-slate-400">Preço:</span> {activeTrip.estimatedPrice ? activeTrip.estimatedPrice.toFixed(2) + ' €' : '—'}</li>
        {activeTrip.completedAt && (
          <li><span className="text-slate-400">Concluída em:</span> {formatDateTimePt(activeTrip.completedAt)}</li>
        )}
      </ul>
    </div>
    )}
          </div>
        </div>
      );

      }

      const container = document.getElementById("root");
      const root = ReactDOM.createRoot(container);
      root.render(<App />);
    </script>

    <!-- Cloudflare scripts originais (mantive) -->
    <script>
      (function() {
        function c() {
          var b = a.contentDocument || a.contentWindow.document;
          if (b) {
            var d = b.createElement('script');
            d.innerHTML = "window.__CF$cv$params={r:'99e240a8ca5f11e6',t:'MTc2MzA3ODY3Nw=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";
            b.getElementsByTagName('head')[0].appendChild(d)
          }
        }
        if (document.body) {
          var a = document.createElement('iframe');
          a.height = 1;
          a.width = 1;
          a.style.position = 'absolute';
          a.style.top = 0;
          a.style.left = 0;
          a.style.border = 'none';
          a.style.visibility = 'hidden';
          document.body.appendChild(a);
          if ('loading' !== document.readyState) c();
          else if (window.addEventListener) document.addEventListener('DOMContentLoaded', c);
          else {
            var e = document.onreadystatechange || function() {};
            document.onreadystatechange = function(b) {
              e(b);
              'loading' !== document.readyState && (document.onreadystatechange = e, c())
            }
          }
        }
      })();
    </script>
    <script>
      (function() {
        function c() {
          var b = a.contentDocument || a.contentWindow.document;
          if (b) {
            var d = b.createElement('script');
            d.innerHTML = "window.__CF$cv$params={r:'99e27d3b39b494ad',t:'MTc2MzA4MTE1OA=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";
            b.getElementsByTagName('head')[0].appendChild(d)
          }
        }
        if (document.body) {
          var a = document.createElement('iframe');
          a.height = 1;
          a.width = 1;
          a.style.position = 'absolute';
          a.style.top = 0;
          a.style.left = 0;
          a.style.border = 'none';
          a.style.visibility = 'hidden';
          document.body.appendChild(a);
          if ('loading' !== document.readyState) c();
          else if (window.addEventListener) document.addEventListener('DOMContentLoaded', c);
          else {
            var e = document.onreadystatechange || function() {};
            document.onreadystatechange = function(b) {
              e(b);
              'loading' !== document.readyState && (document.onreadystatechange = e, c())
            }
          }
        }
      })();
    </script>
    <script>
      (function() {
        function c() {
          var b = a.contentDocument || a.contentWindow.document;
          if (b) {
            var d = b.createElement('script');
            d.innerHTML = "window.__CF$cv$params={r:'99e2af395f2c8e38',t:'MTc2MzA4MzIwNg=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";
            b.getElementsByTagName('head')[0].appendChild(d)
          }
        }
        if (document.body) {
          var a = document.createElement('iframe');
          a.height = 1;
          a.width = 1;
          a.style.position = 'absolute';
          a.style.top = 0;
          a.style.left = 0;
          a.style.border = 'none';
          a.style.visibility = 'hidden';
          document.body.appendChild(a);
          if ('loading' !== document.readyState) c();
          else if (window.addEventListener) document.addEventListener('DOMContentLoaded', c);
          else {
            var e = document.onreadystatechange || function() {};
            document.onreadystatechange = function(b) {
              e(b);
              'loading' !== document.readyState && (document.onreadystatechange = e, c())
            }
          }
        }
      })();
    </script>
    <script>
      (function() {
        function c() {
          var b = a.contentDocument || a.contentWindow.document;
          if (b) {
            var d = b.createElement('script');
            d.innerHTML = "window.__CF$cv$params={r:'99e2ce9bda2d41f6',t:'MTc2MzA4NDQ5Mg=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";
            b.getElementsByTagName('head')[0].appendChild(d)
          }
        }
        if (document.body) {
          var a = document.createElement('iframe');
          a.height = 1;
          a.width = 1;
          a.style.position = 'absolute';
          a.style.top = 0;
          a.style.left = 0;
          a.style.border = 'none';
          a.style.visibility = 'hidden';
          document.body.appendChild(a);
          if ('loading' !== document.readyState) c();
          else if (window.addEventListener) document.addEventListener('DOMContentLoaded', c);
          else {
            var e = document.onreadystatechange || function() {};
            document.onreadystatechange = function(b) {
              e(b);
              'loading' !== document.readyState && (document.onreadystatechange = e, c())
            }
          }
        }
      })();
    </script>
    <script>
      (function() {
        function c() {
          var b = a.contentDocument || a.contentWindow.document;
          if (b) {
            var d = b.createElement('script');
            d.innerHTML = "window.__CF$cv$params={r:'99fbb509cf5ce3c0',t:'MTc2MzM0NTU4OA=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";
            b.getElementsByTagName('head')[0].appendChild(d)
          }
        }
        if (document.body) {
          var a = document.createElement('iframe');
          a.height = 1;
          a.width = 1;
          a.style.position = 'absolute';
          a.style.top = 0;
          a.style.left = 0;
          a.style.border = 'none';
          a.style.visibility = 'hidden';
          document.body.appendChild(a);
          if ('loading' !== document.readyState) c();
          else if (window.addEventListener) document.addEventListener('DOMContentLoaded', c);
          else {
            var e = document.onreadystatechange || function() {};
            document.onreadystatechange = function(b) {
              e(b);
              'loading' !== document.readyState && (document.onreadystatechange = e, c())
            }
          }
        }
      })();
    </script>
  </body>

  </html>