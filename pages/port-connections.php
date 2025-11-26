<?php
// port-connections.php – configurar ligações de cada porto às rotas das cartas (SEM optimização)

require __DIR__ . '/../api/_common.php';

$user   = current_user();
$colors = get_theme_colors();
$logos  = get_logos();

if (!$user) {
  header('Location: ./index.php?page=login');
  exit;
}

$ports         = load_json_file('embarkPoints.json', []);
$licenseTypes  = load_json_file('licenseTypes.json', []);
$licenseRoutes = load_json_file('licenseRoutes.json', []);
$connections   = load_json_file('portConnections.json', []);

// garantimos arrays
if (!is_array($ports))         $ports        = [];
if (!is_array($licenseTypes))  $licenseTypes = [];
if (!is_array($licenseRoutes)) $licenseRoutes= [];
if (!is_array($connections))   $connections  = [];

$initialData = [
  'user'          => [
    'id'    => $user['id'],
    'name'  => $user['name'],
    'type'  => $user['type'],
    'email' => $user['email'] ?? '',
  ],
  'embarkPoints'  => $ports,
  'licenseTypes'  => $licenseTypes,
  'licenseRoutes' => $licenseRoutes,
  'connections'   => $connections,
];

?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <title>WaveUp · Ligações dos Portos</title>
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

    #port-connections-map {
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
              <h1 class="text-lg font-semibold">WaveUp · Ligações dos Portos</h1>
              <span class="waveup-badge text-[10px]">
                <span class="text-secondary">●</span>
                Admin
              </span>
            </div>
            <p class="text-[11px] text-slate-400">
              Liga cada porto à rota principal de cada carta (mini / comfort / executive / party).
            </p>
          </div>
        </div>
        <div class="flex items-center gap-3 text-xs text-slate-300">
          <div class="text-right hidden sm:block">
            <div class="font-medium"><?= htmlspecialchars($user['name']) ?></div>
            <div class="text-[11px] text-slate-400"><?= htmlspecialchars($user['email'] ?? '') ?></div>
          </div>
          <a href="./routes.php" class="waveup-btn-outline px-3 py-1 text-[11px]">
            Rotas da costa
          </a>
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

    const initialData    = JSON.parse(document.getElementById('initial-data').textContent);
    const embarkPoints   = initialData.embarkPoints  || [];
    const licenseTypes   = initialData.licenseTypes  || [];
    const licenseRoutes  = initialData.licenseRoutes || [];
    const initialConns   = initialData.connections   || [];

    // === Helpers ===
    function colorForLicense(licenseId) {
      switch (licenseId) {
        case "mini":      return "#22c55e";
        case "comfort":   return "#facc15";
        case "executive": return "#fb923c";
        case "party":     return "#ef4444";
        default:          return "#0ea5e9";
      }
    }

    // SEM densify/smoothing aqui – só helpers para attachPoint
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

    // usado só para escolher o attachPoint na rota principal
    function findNearestOnRoute(point, routePoints) {
      if (!point || !Array.isArray(routePoints) || !routePoints.length) return null;
      let bestIndex = -1;
      let bestDist  = Infinity;
      routePoints.forEach((rp, idx) => {
        const d = haversineKm(point, rp);
        if (d < bestDist) {
          bestDist  = d;
          bestIndex = idx;
        }
      });
      if (bestIndex === -1) return null;
      return { index: bestIndex, point: routePoints[bestIndex], distanceKm: bestDist };
    }

    function App() {
      const [connections, setConnections] = useState(initialConns);
      const [selectedPortIndex, setSelectedPortIndex] = useState(0);
      const [selectedLicenseId, setSelectedLicenseId] = useState(
        licenseRoutes[0]?.licenseId || licenseTypes[0]?.id || null
      );
      const [drawing, setDrawing]   = useState(false);
      const [saving, setSaving]     = useState(false);
      const [statusMsg, setStatus]  = useState(null);
      const [errorMsg, setError]    = useState(null);

      // para inserção de ponto entre #i e #i+1
      const [pendingInsert, setPendingInsert] = useState(null);

      const mapRef        = useRef(null);
      const layersRef     = useRef({});
      const connLayerRef  = useRef(null);
      const portMarkerRef = useRef(null);
      const clickHandlerRef = useRef(null);

      const selectedPort = useMemo(
        () => embarkPoints[selectedPortIndex] || null,
        [selectedPortIndex]
      );

      const licenseRouteMap = useMemo(() => {
        const m = {};
        licenseRoutes.forEach(r => {
          if (!r || !r.licenseId) return;
          m[r.licenseId] = r;
        });
        return m;
      }, [licenseRoutes]);

      const selectedLicenseRoute = useMemo(
        () => (selectedLicenseId ? licenseRouteMap[selectedLicenseId] || null : null),
        [licenseRouteMap, selectedLicenseId]
      );

      function getConnection(portIndex, licenseId) {
        const idx = connections.findIndex(
          c => c.portIndex === portIndex && c.licenseId === licenseId
        );
        if (idx === -1) return { index: -1, conn: null };
        return { index: idx, conn: connections[idx] };
      }

      const { conn: selectedConnection } = useMemo(
        () => getConnection(selectedPortIndex, selectedLicenseId),
        [connections, selectedPortIndex, selectedLicenseId]
      );

      // caminho "cru" porto → controlPoints (sem densify)
      const connectionPath = useMemo(() => {
        if (!selectedPort) return [];
        const cps = selectedConnection?.controlPoints || [];
        return [
          { lat: selectedPort.lat, lng: selectedPort.lng, _isPort: true },
          ...cps.map(p => ({ ...p, _isPort: false })),
        ];
      }, [selectedPort, selectedConnection]);

      // === inicializar mapa ===
      useEffect(() => {
        if (typeof L === "undefined") return;
        const el = document.getElementById("port-connections-map");
        if (!el) return;
        if (mapRef.current) return;

        const iberiaBounds = L.latLngBounds(
          [34.0, -11.0],
          [45.0,   4.0]
        );

        const map = L.map("port-connections-map", {
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

      // desenhar rotas principais de cada carta (como definidas em licenseRoutes.json)
      useEffect(() => {
        const map = mapRef.current;
        if (!map) return;

        Object.values(layersRef.current).forEach(l => map.removeLayer(l));
        layersRef.current = {};

        licenseRoutes.forEach(r => {
          const pts = r.points || r.controlPoints || [];
          if (!pts.length) return;

          const latlngs = pts.map(p => [p.lat, p.lng]);
          const poly = L.polyline(latlngs, {
            color: colorForLicense(r.licenseId),
            weight: r.licenseId === selectedLicenseId ? 5 : 2,
            opacity: r.licenseId === selectedLicenseId ? 0.9 : 0.5,
          }).addTo(map);
          layersRef.current[r.licenseId] = poly;
        });

        const allPts = [];
        licenseRoutes.forEach(r => {
          (r.points || r.controlPoints || []).forEach(p => allPts.push([p.lat, p.lng]));
        });
        embarkPoints.forEach(p => allPts.push([p.lat, p.lng]));

        if (allPts.length) {
          const bounds = L.latLngBounds(allPts);
          map.fitBounds(bounds.pad(0.2));
        }
      }, [licenseRoutes, selectedLicenseId]);

      // desenhar porto selecionado
      useEffect(() => {
        const map = mapRef.current;
        if (!map) return;

        if (portMarkerRef.current) {
          map.removeLayer(portMarkerRef.current);
          portMarkerRef.current = null;
        }

        if (!selectedPort) return;

        const marker = L.circleMarker([selectedPort.lat, selectedPort.lng], {
          radius: 6,
          color: "#38bdf8",
          weight: 2,
          fillColor: "#0ea5e9",
          fillOpacity: 0.9,
        }).addTo(map);
        marker.bindTooltip(selectedPort.name, { permanent: false });
        portMarkerRef.current = marker;

        if (connectionPath.length >= 2) {
          map.fitBounds(L.latLngBounds(connectionPath.map(p => [p.lat, p.lng])).pad(0.2));
        }
      }, [selectedPort, connectionPath]);

      // desenhar ligação porto ↔ rota (sem optimização)
      useEffect(() => {
        const map = mapRef.current;
        if (!map) return;

        if (connLayerRef.current) {
          map.removeLayer(connLayerRef.current);
          connLayerRef.current = null;
        }

        if (!connectionPath.length) return;

        const latlngs = connectionPath.map(p => [p.lat, p.lng]);
        const poly = L.polyline(latlngs, {
          color: "#e5e7eb",
          weight: 3,
          dashArray: "4 4",
        }).addTo(map);

        connLayerRef.current = poly;
      }, [connectionPath]);

      // modo desenho: click no mapa adiciona ponto (append ou inserção entre pontos)
      useEffect(() => {
        const map = mapRef.current;
        if (!map) return;

        // limpar handler antigo
        if (clickHandlerRef.current) {
          map.off("click", clickHandlerRef.current);
          clickHandlerRef.current = null;
        }

        if (!drawing || !selectedPort || !selectedLicenseId) return;

        const handler = (e) => {
          const { lat, lng } = e.latlng;

          setConnections((prev) => {
            const idx = prev.findIndex(
              (c) => c.portIndex === selectedPortIndex && c.licenseId === selectedLicenseId
            );

            // se não existir ligação, criamos uma
            if (idx === -1) {
              const insertAt = pendingInsert?.insertAt ?? 0;
              const newControlPoints = [];
              // como não havia nada, independentemente de insertAt, o primeiro é este
              newControlPoints.splice(insertAt, 0, { lat, lng });

              return [
                ...prev,
                {
                  portIndex: selectedPortIndex,
                  portName: selectedPort.name,
                  licenseId: selectedLicenseId,
                  licenseName:
                    licenseTypes.find((l) => l.id === selectedLicenseId)?.name ||
                    selectedLicenseId,
                  enabled: true,
                  controlPoints: newControlPoints,
                  points: [],         // vai ser recalculado em handleSave
                  attachIndex: null,
                  attachPoint: null,
                },
              ];
            }

            const clone = prev.slice();
            const existing = clone[idx];
            const cps = Array.isArray(existing.controlPoints)
              ? existing.controlPoints.slice()
              : [];

            if (pendingInsert && Number.isInteger(pendingInsert.insertAt)) {
              // modo "inserir entre #i e #i+1"
              // basePath = [porto, ...cps]
              // inserir entre #i e #i+1 → cps.splice(i, 0, novo)
              const insertAt = Math.max(
                0,
                Math.min(cps.length, pendingInsert.insertAt)
              );
              cps.splice(insertAt, 0, { lat, lng });
            } else {
              // modo antigo: append no fim
              cps.push({ lat, lng });
            }

            clone[idx] = {
              ...existing,
              controlPoints: cps,
            };
            return clone;
          });

          // depois de um clique de inserção, desligamos o modo desenho dirigido
          if (pendingInsert) {
            setPendingInsert(null);
            setDrawing(false);
          }
        };

        clickHandlerRef.current = handler;
        map.on("click", handler);

        return () => {
          if (clickHandlerRef.current) {
            map.off("click", clickHandlerRef.current);
            clickHandlerRef.current = null;
          }
        };
      }, [
        drawing,
        selectedPort,
        selectedLicenseId,
        selectedPortIndex,
        licenseTypes,
        pendingInsert,
      ]);

      function updateSelectedConnection(patch) {
        if (!selectedPort || !selectedLicenseId) return;
        setConnections(prev => {
          const idx = prev.findIndex(
            c => c.portIndex === selectedPortIndex && c.licenseId === selectedLicenseId
          );
          if (idx === -1) {
            return [
              ...prev,
              {
                portIndex: selectedPortIndex,
                portName: selectedPort.name,
                licenseId: selectedLicenseId,
                licenseName: licenseTypes.find(l => l.id === selectedLicenseId)?.name || selectedLicenseId,
                enabled: true,
                controlPoints: [],
                points: [],
                attachIndex: null,
                attachPoint: null,
                ...patch,
              },
            ];
          }
          const clone = prev.slice();
          clone[idx] = { ...clone[idx], ...patch };
          return clone;
        });
      }

      function handleRemoveLastPoint() {
        if (!selectedConnection) return;
        const cps = selectedConnection.controlPoints || [];
        if (!cps.length) return;
        updateSelectedConnection({ controlPoints: cps.slice(0, -1) });
      }

      function handleClearConnection() {
        if (!selectedConnection) return;
        if (!confirm("Limpar ligação deste porto para esta carta?")) return;
        setConnections(prev => prev.filter(
          c => !(c.portIndex === selectedPortIndex && c.licenseId === selectedLicenseId)
        ));
        setPendingInsert(null);
      }

      async function handleReload() {
        setStatus(null);
        setError(null);
        try {
          const res = await fetch("./api/portConnections.php");
          const data = await res.json();
          if (!data.success) throw new Error(data.error || "Erro ao recarregar");
          setConnections(Array.isArray(data.connections) ? data.connections : []);
          setStatus("Dados recarregados de portConnections.json.");
        } catch (err) {
          console.error(err);
          setError("Erro ao recarregar dados.");
        }
      }

      async function handleSave() {
        setSaving(true);
        setStatus(null);
        setError(null);

        try {
          const payload = connections.map(c => {
            const port = embarkPoints[c.portIndex];
            const cps  = c.controlPoints || [];

            // SEM densify: guardamos trajetória exata que desenhaste
            const basePath = port
              ? [{ lat: port.lat, lng: port.lng }, ...cps]
              : cps.slice();

            const route = licenseRouteMap[c.licenseId];
            let attach = null;

            if (route && Array.isArray(route.points) && route.points.length && basePath.length) {
              const last = basePath[basePath.length - 1];  // último ponto real da ligação
              attach = findNearestOnRoute(last, route.points);
            }

            return {
              portIndex: c.portIndex,
              portName: port ? port.name : (c.portName || ""),
              licenseId: c.licenseId,
              licenseName: c.licenseName || (licenseTypes.find(l => l.id === c.licenseId)?.name || c.licenseId),
              enabled: c.enabled !== false,
              controlPoints: cps,            // só para edição no admin
              points: basePath,              // 👈 o runtime passa a usar isto diretamente
              attachIndex: attach ? attach.index : null,
              attachPoint: attach ? { lat: attach.point.lat, lng: attach.point.lng } : null,
            };
          });

          const res = await fetch("./api/portConnections.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ connections: payload }),
          });
          const data = await res.json();
          if (!data.success) throw new Error(data.error || "Erro ao gravar");
          setStatus("Ligações guardadas em portConnections.json.");
        } catch (err) {
          console.error(err);
          setError("Erro ao gravar portConnections.json.");
        } finally {
          setSaving(false);
        }
      }

      const connectionsByPort = useMemo(() => {
        const map = {};
        connections.forEach(c => {
          const key = c.portIndex;
          if (!map[key]) map[key] = [];
          map[key].push(c);
        });
        return map;
      }, [connections]);

      const selectedPortConns = useMemo(
        () => connectionsByPort[selectedPortIndex] || [],
        [connectionsByPort, selectedPortIndex]
      );

      return (
        <div className="grid xl:grid-cols-[260px,1fr,320px] gap-4 xl:gap-6">
          {/* Lista de portos */}
          <div className="space-y-4">
            <div className="waveup-card">
              <div className="flex items-center justify-between mb-2">
                <h2 className="text-sm font-semibold">Portos</h2>
                <button
                  type="button"
                  onClick={handleReload}
                  className="text-[11px] text-slate-400 underline underline-offset-2"
                >
                  Recarregar
                </button>
              </div>
              <div className="max-h-[420px] overflow-y-auto space-y-1 text-[12px]">
                {embarkPoints.map((p, idx) => {
                  const isSel = idx === selectedPortIndex;
                  const conns = connectionsByPort[idx] || [];
                  const hasAny = conns.length > 0;
                  return (
                    <button
                      key={idx}
                      type="button"
                      onClick={() => {
                        setSelectedPortIndex(idx);
                        setPendingInsert(null);
                      }}
                      className={
                        "w-full text-left px-3 py-2 rounded-lg border flex flex-col gap-0.5 " +
                        (isSel
                          ? "border-secondary bg-secondary/10"
                          : "border-slate-700 bg-slate-900/70 hover:border-slate-500")
                      }
                    >
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-medium text-slate-100 truncate">
                          {p.name}
                        </span>
                        {hasAny && (
                          <span className="text-[10px] text-emerald-400">
                            {conns.length} ligações
                          </span>
                        )}
                      </div>
                      <div className="text-[10px] text-slate-500">
                        {p.lat.toFixed(3)}, {p.lng.toFixed(3)}
                      </div>
                    </button>
                  );
                })}
                {!embarkPoints.length && (
                  <p className="text-[11px] text-slate-500">
                    Sem embarkPoints.json carregado.
                  </p>
                )}
              </div>
            </div>

            <div className="waveup-card">
              <h3 className="text-sm font-semibold mb-2">Guardar</h3>
              {statusMsg && (
                <div className="mb-1 text-[11px] text-emerald-400">
                  {statusMsg}
                </div>
              )}
              {errorMsg && (
                <div className="mb-1 text-[11px] text-red-400">
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
                {saving ? "A gravar…" : "Guardar ligações dos portos"}
              </button>
            </div>
          </div>

          {/* Mapa */}
          <div className="space-y-4">
            <div className="waveup-card">
              <div className="flex items-center justify-between mb-2">
                <div>
                  <h2 className="text-sm font-semibold mb-1">Mapa</h2>
                  {selectedPort && (
                    <p className="text-[11px] text-slate-400">
                      Porto selecionado: <span className="text-slate-100">{selectedPort.name}</span>
                    </p>
                  )}
                </div>
                <div className="flex flex-col items-end gap-1">
                  <select
                    className="bg-slate-900 border border-slate-700 rounded px-2 py-1 text-[11px]"
                    value={selectedLicenseId || ""}
                    onChange={e => {
                      setSelectedLicenseId(e.target.value || null);
                      setPendingInsert(null);
                    }}
                  >
                    {licenseTypes.map(lic => (
                      <option key={lic.id} value={lic.id}>
                        {lic.name}
                      </option>
                    ))}
                  </select>
                  <button
                    type="button"
                    onClick={() => setDrawing(d => !d)}
                    disabled={!selectedPort || !selectedLicenseId}
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
              </div>
              <div id="port-connections-map" />
              <p className="mt-2 text-[11px] text-slate-400">
                Com o modo desenho ativo, clica no mapa para definir a linha{" "}
                <strong>do porto até à rota da carta selecionada</strong>. A localização exata
                onde a linha toca na rota será calculada automaticamente (sem alterar os pontos
                que definiste).
              </p>
            </div>
          </div>

          {/* Detalhes da ligação selecionada */}
          <div className="space-y-4">
            <div className="waveup-card">
              <h2 className="text-sm font-semibold mb-2">Ligação atual</h2>

              {(!selectedPort || !selectedLicenseId) && (
                <p className="text-[11px] text-slate-400">
                  Escolhe um porto e uma carta.
                </p>
              )}

              {selectedPort && selectedLicenseId && (
                <div className="space-y-3 text-[12px]">
                  <div>
                    <div className="text-[11px] text-slate-400">
                      Porto
                    </div>
                    <div className="text-sm text-slate-100">
                      {selectedPort.name}
                    </div>
                    <div className="text-[10px] text-slate-500">
                      {selectedPort.lat.toFixed(5)}, {selectedPort.lng.toFixed(5)}
                    </div>

                    {/* Links para mapa do porto */}
                    <div className="mt-1 flex flex-wrap gap-2 text-[10px]">
                      <a
                        href={`https://www.google.com/maps?q=${selectedPort.lat},${selectedPort.lng}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-sky-400 underline underline-offset-2 hover:text-sky-300"
                      >
                        Abrir no Google&nbsp;Maps
                      </a>
                      <a
                        href={`https://www.openstreetmap.org/?mlat=${selectedPort.lat}&mlon=${selectedPort.lng}#map=16/${selectedPort.lat}/${selectedPort.lng}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-emerald-400 underline underline-offset-2 hover:text-emerald-300"
                      >
                        Abrir no OpenStreetMap
                      </a>
                    </div>
                  </div>

                  <div className="flex items-center justify-between">
                    <div>
                      <div className="text-[11px] text-slate-400">
                        Carta
                      </div>
                      <div className="text-sm text-slate-100">
                        {licenseTypes.find(l => l.id === selectedLicenseId)?.name || selectedLicenseId}
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-[11px] text-slate-400">Cor rota</span>
                      <span
                        className="inline-flex w-5 h-5 rounded-full border border-slate-700"
                        style={{ backgroundColor: colorForLicense(selectedLicenseId) }}
                      />
                    </div>
                  </div>

                  {selectedLicenseRoute ? (
                    <p className="text-[11px] text-slate-400">
                      Rota principal desta carta tem{" "}
                      {selectedLicenseRoute.points?.length || selectedLicenseRoute.controlPoints?.length || 0}{" "}
                      pontos.
                    </p>
                  ) : (
                    <p className="text-[11px] text-amber-400">
                      Não há rota principal definida para esta carta em <code>licenseRoutes.json</code>.
                    </p>
                  )}

                  {/* Lista numerada porto → ligação + botões + entre pontos */}
                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <span className="text-[11px] text-slate-300">
                        Rota porto → ligação (pontos numerados)
                      </span>
                      <span className="text-[10px] text-slate-400">
                        {connectionPath.length} pontos (inclui porto)
                      </span>
                    </div>

                    <div className="max-h-40 overflow-y-auto bg-slate-950/60 border border-slate-800 rounded p-2 text-[10px] space-y-1">
                      {selectedPort ? (
                        connectionPath.length ? (
                          connectionPath.map((p, idx) => (
                            <React.Fragment key={idx}>
                              <div className="flex items-center justify-between gap-2">
                                <span>
                                  #{idx} ·{" "}
                                  {p._isPort ? (
                                    <span className="text-sky-300 font-semibold">PORTO</span>
                                  ) : (
                                    <span className="text-slate-200">Ponto ligação</span>
                                  )}{" "}
                                  · {p.lat.toFixed(5)}, {p.lng.toFixed(5)}
                                </span>
                              </div>

                              {idx < connectionPath.length - 1 && (
                                <div className="flex justify-center my-0.5">
                                  <button
                                    type="button"
                                    onClick={() => {
                                      // basePath = [porto, ...controlPoints]
                                      // queremos inserir entre basePath[idx] e basePath[idx+1]
                                      // isso corresponde a controlPoints.splice(idx, 0, novoPonto)
                                      setPendingInsert({ insertAt: idx });
                                      setDrawing(true);
                                    }}
                                    className="px-2 py-0.5 rounded-full border border-sky-500/60 text-sky-300 hover:bg-sky-500/10 transition text-[10px]"
                                  >
                                    + Inserir ponto entre #{idx} e #{idx + 1}
                                  </button>
                                </div>
                              )}
                            </React.Fragment>
                          ))
                        ) : (
                          <span className="text-slate-500">
                            A ligação, por omissão, é apenas o ponto do porto. Desenha alguns pontos
                            para chegar à rota principal.
                          </span>
                        )
                      ) : (
                        <span className="text-slate-500">
                          Escolhe um porto para ver/editar a ligação.
                        </span>
                      )}
                    </div>

                    {pendingInsert && (
                      <p className="mt-1 text-[10px] text-emerald-400">
                        Modo inserção: clica no mapa para adicionar um novo ponto entre #{pendingInsert.insertAt} e #{pendingInsert.insertAt + 1}.
                      </p>
                    )}

                    <div className="flex gap-2 mt-2">
                      <button
                        type="button"
                        onClick={handleRemoveLastPoint}
                        className="waveup-btn-outline px-2 py-1 text-[11px]"
                        disabled={
                          !selectedConnection || !(selectedConnection.controlPoints || []).length
                        }
                      >
                        Remover último
                      </button>
                      <button
                        type="button"
                        onClick={handleClearConnection}
                        className="waveup-btn-outline px-2 py-1 text-[11px]"
                        disabled={!selectedConnection}
                      >
                        Limpar ligação
                      </button>
                    </div>
                  </div>

                  {selectedConnection?.attachPoint != null && (
                    <div>
                      <div className="text-[11px] text-slate-300 mb-1">
                        Ponto guardado de ligação à rota
                      </div>
                      <p className="text-[10px] text-slate-400">
                        Índice na rota: {selectedConnection.attachIndex} ·{" "}
                        {selectedConnection.attachPoint.lat.toFixed(5)},{" "}
                        {selectedConnection.attachPoint.lng.toFixed(5)}
                      </p>

                      {/* Links para mapa do ponto de ligação */}
                      <div className="mt-1 flex flex-wrap gap-2 text-[10px]">
                        <a
                          href={`https://www.google.com/maps?q=${selectedConnection.attachPoint.lat},${selectedConnection.attachPoint.lng}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-sky-400 underline underline-offset-2 hover:text-sky-300"
                        >
                          Ver ponto de ligação no Google&nbsp;Maps
                        </a>
                        <a
                          href={`https://www.openstreetmap.org/?mlat=${selectedConnection.attachPoint.lat}&mlon=${selectedConnection.attachPoint.lng}#map=16/${selectedConnection.attachPoint.lat}/${selectedConnection.attachPoint.lng}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-emerald-400 underline underline-offset-2 hover:text-emerald-300"
                        >
                          Ver ponto de ligação no OpenStreetMap
                        </a>
                      </div>
                    </div>
                  )}

                  <p className="text-[10px] text-slate-500 mt-1">
                    Ao gravar, calculamos automaticamente o ponto da rota principal mais próximo
                    do fim da ligação e guardamos em <code>attachIndex</code> /{" "}
                    <code>attachPoint</code>. No runtime podes simplesmente concatenar:
                    porto → ligação (sem optimização) → rota&nbsp;principal → ligação do porto de chegada
                    (e aí o <code>book.php</code> é que suaviza o que quiseres).
                  </p>
                </div>
              )}
            </div>

            <div className="waveup-card">
              <h3 className="text-sm font-semibold mb-2">Notas</h3>
              <ul className="text-[11px] text-slate-400 space-y-1 list-disc list-inside">
                <li>
                  Cada entrada em <code>portConnections.json</code> é uma combinação
                  porto+carta.
                </li>
                <li>
                  Guardamos apenas os pontos que desenhaste (porto + controlPoints) — sem densify.
                </li>
                <li>
                  O ponto exato onde a ligação encaixa na rota da carta é calculado com base
                  no último ponto da ligação que definiste.
                </li>
                <li>
                  Se alterares as rotas principais em <code>licenseRoutes.json</code>,
                  volta aqui e grava outra vez para recalcular os índices de ligação.
                </li>
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
</body>
</html>
