<?php
// routes.php – editor de rotas principais por licença (mini / comfort / executive / party)

require __DIR__ . '/../api/_common.php';

$user   = current_user();
$colors = get_theme_colors();
$logos  = get_logos();

if (!$user) {
  header('Location: ./index.php?page=login');
  exit;
}

$licenses = load_json_file('licenseTypes.json', []);
$routes   = load_json_file('licenseRoutes.json', []);

// garantimos pelo menos um objeto por licença
$byLicense = [];
if (is_array($routes)) {
  foreach ($routes as $r) {
    if (!isset($r['licenseId'])) continue;
    $byLicense[$r['licenseId']] = $r;
  }
}

$fullRoutes = [];
if (is_array($licenses)) {
  foreach ($licenses as $lic) {
    $id   = $lic['id']   ?? null;
    $name = $lic['name'] ?? '';
    if (!$id) continue;

    if (isset($byLicense[$id])) {
      // completamos com name se faltar
      $r = $byLicense[$id];
      if (empty($r['licenseName'])) {
        $r['licenseName'] = $name;
      }
      if (!isset($r['enabled'])) {
        $r['enabled'] = true;
      }
      if (!isset($r['controlPoints']) || !is_array($r['controlPoints'])) {
        $r['controlPoints'] = $r['points'] ?? [];
      }
      if (!isset($r['points']) || !is_array($r['points'])) {
        $r['points'] = $r['controlPoints'];
      }
      $fullRoutes[] = $r;
    } else {
      // rota vazia para esta licença
      $fullRoutes[] = [
        'licenseId'     => $id,
        'licenseName'   => $name,
        'enabled'       => false,
        'controlPoints' => [],
        'points'        => [],
      ];
    }
  }
}

$initialData = [
  'user'        => [
    'id'    => $user['id'],
    'name'  => $user['name'],
    'type'  => $user['type'],
    'email' => $user['email'] ?? '',
  ],
  'licenseTypes' => $licenses,
  'routes'       => $fullRoutes,
];

?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <title>WaveUp · Rotas por Licença</title>
  <link rel="icon" href="<?= htmlspecialchars($logos['favicon']) ?>">
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Leaflet -->
  <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
    crossorigin=""
  />
  <script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
  ></script>

  <link rel="stylesheet" href="./css/app9.css">
  <style>
    :root {
      --color-primary: <?= htmlspecialchars($colors['primary']) ?>;
      --color-secondary: <?= htmlspecialchars($colors['secondary']) ?>;
    }

    #license-routes-map {
      width: 100%;
      height: 520px;
      border-radius: 0.75rem;
      overflow: hidden;
    }
  </style>
</head>
<body class="bg-primary text-slate-50">
  <div class="min-h-screen flex flex-col">
    <!-- Top bar -->
    <header class="border-b border-slate-800/80 bg-slate-950/80 backdrop-blur">
      <div class="waveup-shell flex items-center justify-between gap-4 py-3">
        <div class="flex items-center gap-3">
          <img src="<?= htmlspecialchars($logos['icon']) ?>" alt="WaveUp" class="w-9 h-9 rounded-lg" />
          <div>
            <div class="flex items-center gap-2">
              <h1 class="text-lg font-semibold">WaveUp · Rotas por Licença</h1>
              <span class="waveup-badge text-[10px]">
                <span class="text-secondary">●</span>
                Admin
              </span>
            </div>
            <p class="text-[11px] text-slate-400">
              Define a rota padrão ao longo da costa para cada tipo de carta.
            </p>
          </div>
        </div>
        <div class="flex items-center gap-3 text-xs text-slate-300">
          <div class="text-right hidden sm:block">
            <div class="font-medium"><?= htmlspecialchars($user['name']) ?></div>
            <div class="text-[11px] text-slate-400"><?= htmlspecialchars($user['email'] ?? '') ?></div>
          </div>
          <a href="./index.php?page=dashboard" class="waveup-btn-outline px-3 py-1 text-[11px]">
            Voltar
          </a>
        </div>
      </div>
    </header>

    <main class="flex-1">
      <div class="waveup-shell py-6">
        <div id="root"></div>
      </div>
    </main>
  </div>

  <!-- React UMD -->
  <script src="https://unpkg.com/react@18/umd/react.development.js" crossorigin></script>
  <script src="https://unpkg.com/react-dom@18/umd/react-dom.development.js" crossorigin></script>
  <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

  <!-- Dados iniciais -->
  <script id="initial-data" type="application/json">
<?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n" ?>
  </script>

  <!-- App React -->
  <script type="text/babel">
    const { useState, useEffect, useRef, useMemo } = React;

    const initialData   = JSON.parse(document.getElementById('initial-data').textContent);
    const licenseTypes  = initialData.licenseTypes || [];
    const initialRoutes = initialData.routes || [];

    // cores por categoria de licença (podes ajustar)
    function colorForLicense(licenseId) {
      switch (licenseId) {
        case "mini":      return "#22c55e"; // verde
        case "comfort":   return "#facc15"; // amarelo
        case "executive": return "#fb923c"; // laranja
        case "party":     return "#ef4444"; // vermelho
        default:          return "#0ea5e9"; // azul
      }
    }

    const STEP_KM = 0.5; // distância alvo entre pontos densificados

    function toRad(deg) {
      return deg * Math.PI / 180;
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

    // gera muitos pontos entre os que desenhas (p0, p1, ...)
    function densifyPolyline(controlPoints, stepKm = STEP_KM) {
      const pts = Array.isArray(controlPoints) ? controlPoints : [];
      if (pts.length <= 1) return pts.slice();

      const out = [];
      for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i];
        const p1 = pts[i+1];

        if (i === 0) {
          out.push({ lat: p0.lat, lng: p0.lng });
        }

        const dist = haversineKm(p0, p1);
        const steps = Math.max(1, Math.ceil(dist / stepKm));

        for (let s = 1; s < steps; s++) {
          const t = s / steps;
          out.push({
            lat: p0.lat + (p1.lat - p0.lat) * t,
            lng: p0.lng + (p1.lng - p0.lng) * t,
          });
        }
      }
      const last = pts[pts.length-1];
      out.push({ lat: last.lat, lng: last.lng });
      return out;
    }

    function App() {
      const [routes, setRoutes]           = useState(initialRoutes);
      const [selectedLicenseId, setSelectedLicenseId] =
        useState(initialRoutes[0]?.licenseId || (licenseTypes[0]?.id ?? null));
      const [drawing, setDrawing]         = useState(false);
      const [saving, setSaving]           = useState(false);
      const [statusMsg, setStatusMsg]     = useState(null);
      const [errorMsg, setErrorMsg]       = useState(null);

      const mapRef          = useRef(null);
      const layersRef       = useRef({});
      const clickHandlerRef = useRef(null);

      // garante que existe sempre um objeto de rota por licença em memória
      useEffect(() => {
        setRoutes(prev => {
          const indexByLic = {};
          prev.forEach((r, idx) => { indexByLic[r.licenseId] = idx; });

          const out = prev.slice();
          licenseTypes.forEach(lic => {
            if (!lic || !lic.id) return;
            if (indexByLic[lic.id] != null) return;

            out.push({
              licenseId: lic.id,
              licenseName: lic.name || lic.id,
              enabled: false,
              controlPoints: [],
              points: [],
            });
          });
          return out;
        });
      }, []);

      const selectedRoute = useMemo(
        () => routes.find(r => r.licenseId === selectedLicenseId) || null,
        [routes, selectedLicenseId]
      );

const densifiedPreview = useMemo(() => {
  if (!selectedRoute) return [];

  // Se a rota tiver points carregados do server E ainda não mexeste
  // nos controlPoints, podes simplesmente mostrar os points.
  if (Array.isArray(selectedRoute.points) && selectedRoute.points.length) {
    return selectedRoute.points;
  }

  // Se não houver points (primeira vez ou rota nova),
  // calcula em tempo real a partir dos controlo.
  return densifyPolyline(selectedRoute.controlPoints || [], STEP_KM);
}, [selectedRoute]);


      // inicializa mapa
      useEffect(() => {
        if (typeof L === "undefined") return;
        const el = document.getElementById("license-routes-map");
        if (!el) return;
        if (mapRef.current) return;

        const iberiaBounds = L.latLngBounds(
          [34.0, -11.0],
          [45.0,   4.0]
        );

        const map = L.map("license-routes-map", {
          center: [39.0, -8.5],
          zoom: 6,
          maxBounds: iberiaBounds,
          maxBoundsViscosity: 0.9,
          minZoom: 4,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 18,
        }).addTo(map);

        mapRef.current = map;
      }, []);

useEffect(() => {
  const map = mapRef.current;
  if (!map) return;

  Object.values(layersRef.current).forEach(layer => {
    map.removeLayer(layer);
  });
  layersRef.current = {};

  routes.forEach(route => {
    // se estiveres a desenhar, usa SEMPRE controlPoints
    // se não, usa points (densificados) se houver
    let pts;
    if (drawing) {
      pts = route.controlPoints || [];
    } else {
      if (Array.isArray(route.points) && route.points.length) {
        pts = route.points;
      } else {
        pts = route.controlPoints || [];
      }
    }

    if (!pts.length) return;

    const latlngs = pts.map(p => [p.lat, p.lng]);

    const poly = L.polyline(latlngs, {
      color: colorForLicense(route.licenseId),
      weight: route.licenseId === selectedLicenseId ? 5 : 3,
    }).addTo(map);

    poly.on("click", () => {
      setSelectedLicenseId(route.licenseId);
    });

    layersRef.current[route.licenseId] = poly;
  });

  // bounds também deve respeitar o modo actual
  const allPoints = routes.flatMap(r => {
    if (drawing) return r.controlPoints || [];
    if (Array.isArray(r.points) && r.points.length) return r.points;
    return r.controlPoints || [];
  });

  if (allPoints.length) {
    const latlngs = allPoints.map(p => [p.lat, p.lng]);
    const bounds = L.latLngBounds(latlngs);
    map.fitBounds(bounds.pad(0.2));
  }
}, [routes, selectedLicenseId, drawing]);


      // click no mapa para adicionar pontos à rota da licença selecionada
      useEffect(() => {
        const map = mapRef.current;
        if (!map) return;

        if (clickHandlerRef.current) {
          map.off("click", clickHandlerRef.current);
          clickHandlerRef.current = null;
        }

        if (!drawing || !selectedRoute) return;

        const handler = (e) => {
          const { lat, lng } = e.latlng;
          setRoutes(prev =>
            prev.map(r =>
              r.licenseId === selectedRoute.licenseId
                ? { ...r, controlPoints: [...(r.controlPoints || []), { lat, lng }] }
                : r
            )
          );
        };

        clickHandlerRef.current = handler;
        map.on("click", handler);

        return () => {
          if (clickHandlerRef.current) {
            map.off("click", clickHandlerRef.current);
            clickHandlerRef.current = null;
          }
        };
      }, [drawing, selectedRoute]);

      function updateSelected(patch) {
        if (!selectedRoute) return;
        setRoutes(prev =>
          prev.map(r =>
            r.licenseId === selectedRoute.licenseId
              ? { ...r, ...patch }
              : r
          )
        );
      }

      function handleClearPoints() {
        if (!selectedRoute) return;
        if (!confirm("Limpar todos os pontos desta rota?")) return;
        updateSelected({ controlPoints: [] });
      }

      function handleRemoveLastPoint() {
        if (!selectedRoute) return;
        const pts = selectedRoute.controlPoints || [];
        if (!pts.length) return;
        updateSelected({ controlPoints: pts.slice(0, -1) });
      }

      async function handleReload() {
        setStatusMsg(null);
        setErrorMsg(null);
        try {
          const res = await fetch("./api/licenseRoutes.php");
          const data = await res.json();
          if (!data.success) throw new Error(data.error || "Erro ao carregar");
          const loadedRoutes = Array.isArray(data.routes) ? data.routes : [];
          // garantir controlPoints
          const norm = loadedRoutes.map(r => ({
            licenseId: r.licenseId,
            licenseName: r.licenseName || "",
            enabled: r.enabled !== false,
            controlPoints: Array.isArray(r.controlPoints)
              ? r.controlPoints
              : (Array.isArray(r.points) ? r.points : []),
            points: Array.isArray(r.points) ? r.points : [],
          }));
          setRoutes(norm);
          setStatusMsg("Rotas recarregadas.");
        } catch (err) {
          console.error(err);
          setErrorMsg("Erro ao recarregar rotas.");
        }
      }

      async function handleSave() {
        setSaving(true);
        setStatusMsg(null);
        setErrorMsg(null);

        try {
          // densificar todas as rotas antes de enviar
          const payloadRoutes = routes.map(r => {
            const control = Array.isArray(r.controlPoints) ? r.controlPoints : [];
            const dense = densifyPolyline(control, STEP_KM);
            return {
              licenseId:   r.licenseId,
              licenseName: r.licenseName || "",
              enabled:     r.enabled !== false,
              controlPoints: control,
              points:        dense,
            };
          });

          const res = await fetch("./api/licenseRoutes.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ routes: payloadRoutes }),
          });
          const data = await res.json();
          if (!data.success) {
            throw new Error(data.error || "Erro ao gravar");
          }
          setStatusMsg("Rotas guardadas em licenseRoutes.json (com pontos densificados).");
        } catch (err) {
          console.error(err);
          setErrorMsg("Erro ao gravar licenseRoutes.json.");
        } finally {
          setSaving(false);
        }
      }

      return (
        <div className="grid xl:grid-cols-[260px,1fr,320px] gap-4 xl:gap-6">
          {/* Lista de licenças */}
          <div className="space-y-4">
            <div className="waveup-card">
              <div className="flex items-center justify-between mb-2">
                <h2 className="text-sm font-semibold">Cartas &amp; rotas</h2>
                <button
                  type="button"
                  onClick={handleReload}
                  className="text-[11px] text-slate-400 underline underline-offset-2"
                >
                  Recarregar
                </button>
              </div>

              <div className="space-y-1 max-h-[420px] overflow-y-auto text-[12px]">
                {licenseTypes.map(lic => {
                  const route = routes.find(r => r.licenseId === lic.id);
                  const isSelected = lic.id === selectedLicenseId;
                  const pts = route?.controlPoints?.length || 0;

                  return (
                    <button
                      key={lic.id}
                      type="button"
                      onClick={() => setSelectedLicenseId(lic.id)}
                      className={
                        "w-full text-left px-3 py-2 rounded-lg border flex flex-col gap-0.5 " +
                        (isSelected
                          ? "border-secondary bg-secondary/10"
                          : "border-slate-700 bg-slate-900/70 hover:border-slate-500")
                      }
                    >
                      <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <span
                            className="inline-flex w-4 h-4 rounded-full"
                            style={{ backgroundColor: colorForLicense(lic.id) }}
                          />
                          <span className="font-medium text-slate-100 truncate">
                            {lic.name}
                          </span>
                        </div>
                      </div>
                      <div className="flex items-center justify-between text-[10px] text-slate-400">
                        <span>{pts} pontos</span>
                        <span className="text-slate-500">
                          categoria: {lic.category}
                        </span>
                      </div>
                    </button>
                  );
                })}
                {!licenseTypes.length && (
                  <p className="text-[11px] text-slate-500">
                    Não há licenseTypes.json carregado.
                  </p>
                )}
              </div>
            </div>

            <div className="waveup-card">
              <h3 className="text-sm font-semibold mb-2">Guardar</h3>
              {statusMsg && (
                <div className="mb-2 text-[11px] text-emerald-400">
                  {statusMsg}
                </div>
              )}
              {errorMsg && (
                <div className="mb-2 text-[11px] text-red-400">
                  {errorMsg}
                </div>
              )}
              <button
                type="button"
                onClick={handleSave}
                disabled={saving}
                className={
                  "waveup-btn w-full px-4 py-2 text-sm " +
                  (saving ? "opacity-80 cursor-wait" : "")
                }
              >
                {saving ? "A gravar…" : "Guardar licenseRoutes.json"}
              </button>
            </div>
          </div>

          {/* Mapa */}
          <div className="space-y-4">
            <div className="waveup-card">
              <div className="flex items-center justify-between mb-2">
                <h2 className="text-sm font-semibold">Mapa de Portugal</h2>
                <button
                  type="button"
                  onClick={() => setDrawing(d => !d)}
                  disabled={!selectedRoute}
                  className={
                    "px-3 py-1 rounded-md text-[11px] border " +
                    (drawing
                      ? "border-emerald-500 bg-emerald-500/20 text-emerald-300"
                      : "border-slate-600 bg-slate-900 text-slate-200")
                  }
                >
                  {drawing ? "Modo desenho: ON" : "Modo desenho: OFF"}
                </button>
              </div>
              <div id="license-routes-map" />
              <p className="mt-2 text-[11px] text-slate-400">
                Seleciona uma carta, ativa o modo desenho e clica ao longo da costa
                para definir a rota base dessa carta. O sistema vai gerar pontos
                extra entre os que desenhaste ao guardar.
              </p>
            </div>
          </div>

          {/* Detalhes da rota selecionada */}
          <div className="space-y-4">
            <div className="waveup-card">
              <h2 className="text-sm font-semibold mb-2">Detalhes da carta</h2>

              {!selectedRoute && (
                <p className="text-[11px] text-slate-400">
                  Escolhe uma carta na coluna da esquerda.
                </p>
              )}

              {selectedRoute && (
                <div className="space-y-3 text-[12px]">
                  <div>
                    <label className="block text-[11px] text-slate-300 mb-1">
                      Carta
                    </label>
                    <input
                      type="text"
                      value={selectedRoute.licenseName || ""}
                      onChange={e => updateSelected({ licenseName: e.target.value })}
                      className="w-full bg-slate-900 border border-slate-700 rounded px-2 py-1 text-[11px]"
                    />
                    <p className="text-[10px] text-slate-500 mt-1">
                      ID interno: <code>{selectedRoute.licenseId}</code>
                    </p>
                  </div>

                  <div className="flex items-center justify-between">
                    <div>
                      <label className="block text-[11px] text-slate-300 mb-1">
                        Ativa
                      </label>
                      <input
                        type="checkbox"
                        checked={selectedRoute.enabled !== false}
                        onChange={e => updateSelected({ enabled: e.target.checked })}
                      />
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-[11px] text-slate-400">
                        Cor
                      </span>
                      <span
                        className="inline-flex w-5 h-5 rounded-full border border-slate-700"
                        style={{ backgroundColor: colorForLicense(selectedRoute.licenseId) }}
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-[11px] text-slate-300 mb-1">
                      Pontos desenhados
                    </label>
                    <div className="max-h-36 overflow-y-auto bg-slate-950/60 border border-slate-800 rounded p-2 text-[10px] space-y-1">
                      {(selectedRoute.controlPoints || []).map((p, idx) => (
                        <div key={idx}>
                          #{idx + 1} · {p.lat.toFixed(5)}, {p.lng.toFixed(5)}
                        </div>
                      ))}
                      {!(selectedRoute.controlPoints || []).length && (
                        <span className="text-slate-500">
                          Ainda não há pontos. Ativa o modo desenho e clica no mapa.
                        </span>
                      )}
                    </div>
                    <div className="flex gap-2 mt-2">
                      <button
                        type="button"
                        onClick={handleRemoveLastPoint}
                        className="waveup-btn-outline px-2 py-1 text-[11px]"
                      >
                        Remover último
                      </button>
                      <button
                        type="button"
                        onClick={handleClearPoints}
                        className="waveup-btn-outline px-2 py-1 text-[11px]"
                      >
                        Limpar todos
                      </button>
                    </div>
                  </div>

                  <div>
                    <label className="block text-[11px] text-slate-300 mb-1">
                      Preview densificação (≈ {STEP_KM} km entre pontos)
                    </label>
                    <p className="text-[10px] text-slate-400 mb-1">
                      {selectedRoute.controlPoints?.length || 0} pontos de controlo →{" "}
                      {densifiedPreview.length} pontos finais.
                    </p>
                    <div className="max-h-32 overflow-y-auto bg-slate-950/40 border border-slate-800 rounded p-2 text-[10px] space-y-1">
                      {densifiedPreview.slice(0, 40).map((p, idx) => (
                        <div key={idx}>
                          #{idx + 1} · {p.lat.toFixed(5)}, {p.lng.toFixed(5)}
                        </div>
                      ))}
                      {densifiedPreview.length > 40 && (
                        <div className="text-slate-500">
                          … + {densifiedPreview.length - 40} pontos
                        </div>
                      )}
                      {!densifiedPreview.length && (
                        <span className="text-slate-500">
                          Sem preview – desenha primeiro a rota base.
                        </span>
                      )}
                    </div>
                  </div>

                  <p className="text-[10px] text-slate-500 mt-1">
                    No runtime, quando alguém reservar, vais juntar:
                    rota&nbsp;do&nbsp;porto&nbsp;inicial → rota da carta{" "}
                    (<code>licenseRoutes.json</code>) → rota do porto de chegada.
                  </p>
                </div>
              )}
            </div>

            <div className="waveup-card">
              <h3 className="text-sm font-semibold mb-2">Notas</h3>
              <ul className="text-[11px] text-slate-400 space-y-1 list-disc list-inside">
                <li>Cada carta tem <strong>uma rota principal</strong> ao longo da costa.</li>
                <li>Os pontos que desenhas são guardados em <code>controlPoints</code>.</li>
                <li>Ao gravar, é criada uma versão densificada em <code>points</code> com muitos pontos intermédios.</li>
                <li>Mais tarde, a página de portos vai criar rotas de entrada/saída
                  de cada porto para o ponto mais próximo desta rota.</li>
              </ul>
            </div>
          </div>
        </div>
      );
    }

    const container = document.getElementById("root");
    const root = ReactDOM.createRoot(container);
    root.render(<App />);
  </script>
<script>(function(){function c(){var b=a.contentDocument||a.contentWindow.document;if(b){var d=b.createElement('script');d.innerHTML="window.__CF$cv$params={r:'9a13750d580cf650',t:'MTc2MzU5NDYyNg=='};var a=document.createElement('script');a.src='/cdn-cgi/challenge-platform/scripts/jsd/main.js';document.getElementsByTagName('head')[0].appendChild(a);";b.getElementsByTagName('head')[0].appendChild(d)}}if(document.body){var a=document.createElement('iframe');a.height=1;a.width=1;a.style.position='absolute';a.style.top=0;a.style.left=0;a.style.border='none';a.style.visibility='hidden';document.body.appendChild(a);if('loading'!==document.readyState)c();else if(window.addEventListener)document.addEventListener('DOMContentLoaded',c);else{var e=document.onreadystatechange||function(){};document.onreadystatechange=function(b){e(b);'loading'!==document.readyState&&(document.onreadystatechange=e,c())}}}})();</script></body>
</html>
