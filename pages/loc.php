<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <title>Teste de Coordenadas · Click no mapa</title>

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

  <!-- Tailwind opcional (só para ficar bonito) -->
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    html, body {
      height: 100%;
      margin: 0;
    }
    #map {
      width: 100%;
      height: 70vh;
    }
    .coords-item {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
  </style>
</head>
<body class="bg-slate-950 text-slate-100">
  <div class="max-w-4xl mx-auto py-4 space-y-4">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold">Click no mapa para obter coordenadas</h1>
        <p class="text-xs text-slate-400">
          Cada clique cria um marcador e adiciona a latitude/longitude à lista em baixo.
        </p>
      </div>
      <button
        id="btn-clear"
        class="px-3 py-1 text-xs rounded-md border border-slate-600 bg-slate-900 hover:bg-slate-800"
      >
        Limpar marcadores &amp; lista
      </button>
    </header>

    <div id="map" class="rounded-lg overflow-hidden border border-slate-800"></div>

    <section class="space-y-2">
      <div class="text-sm">
        <span class="font-semibold">Último clique:</span>
        <span id="last-coords" class="ml-1 text-sky-300">
          (nenhum ainda)
        </span>
      </div>

      <div class="text-xs text-slate-400">
        Dica: clica numa linha da lista para copiar as coordenadas para a área de transferência.
      </div>

      <div
        id="coords-list"
        class="mt-2 max-h-64 overflow-auto border border-slate-800 rounded-lg bg-slate-900/70 text-xs divide-y divide-slate-800"
      >
        <!-- Items serão adicionados aqui -->
      </div>
    </section>
  </div>

  <script>
    // Inicializar mapa (foco em Lisboa; muda se quiseres)
    const map = L.map('map', {
      center: [38.7223, -9.1393],
      zoom: 10,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
    }).addTo(map);

    const markers = [];
    const lastCoordsEl = document.getElementById('last-coords');
    const listEl = document.getElementById('coords-list');
    const clearBtn = document.getElementById('btn-clear');

    function addCoordsItem(lat, lng) {
      const row = document.createElement('button');
      row.type = 'button';
      row.className =
        'w-full text-left px-3 py-1.5 coords-item hover:bg-slate-800 flex items-center justify-between';
      const latStr = lat.toFixed(6);
      const lngStr = lng.toFixed(6);
      row.textContent = `lat: ${latStr}, lng: ${lngStr}`;

      row.addEventListener('click', async () => {
        const text = `${latStr}, ${lngStr}`;
        try {
          await navigator.clipboard.writeText(text);
          row.classList.add('text-emerald-400');
          setTimeout(() => row.classList.remove('text-emerald-400'), 700);
        } catch (e) {
          alert('Copiar falhou, mas aqui estão as coords: ' + text);
        }
      });

      listEl.prepend(row);
    }

    map.on('click', (e) => {
      const { lat, lng } = e.latlng;

      // Atualizar texto de último clique
      lastCoordsEl.textContent = `lat: ${lat.toFixed(6)}, lng: ${lng.toFixed(6)}`;

      // Adicionar marcador no mapa
      const marker = L.marker([lat, lng]).addTo(map);
      marker.bindPopup(
        `<div style="font-family: monospace; font-size: 11px;">
          lat: ${lat.toFixed(6)}<br/>
          lng: ${lng.toFixed(6)}
        </div>`
      );
      markers.push(marker);

      // Adicionar linha na lista
      addCoordsItem(lat, lng);
    });

    clearBtn.addEventListener('click', () => {
      markers.forEach(m => map.removeLayer(m));
      markers.length = 0;
      listEl.innerHTML = '';
      lastCoordsEl.textContent = '(nenhum ainda)';
    });
  </script>
</body>
</html>
