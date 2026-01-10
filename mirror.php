<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    
    <title>Monitoreo Espejo | C4</title>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        body, html { height: 100%; margin: 0; padding: 0; overflow: hidden; font-family: 'Segoe UI', system-ui, sans-serif; }
        #map { height: 100%; width: 100%; z-index: 1; background: #e5e7eb; }
        
        /* HEADER FLOTANTE MEJORADO */
        .floating-header {
            position: absolute; top: 12px; left: 12px; right: 12px; z-index: 1000;
            background: rgba(255, 255, 255, 0.98); padding: 8px 16px; border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15); display: flex; align-items: center; justify-content: space-between;
            border: 1px solid rgba(0,0,0,0.05);
        }

        /* Indicadores de Estado */
        .status-pill {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
            background: #f1f5f9; border-radius: 20px; font-size: 11px; font-weight: 700; color: #475569;
            margin-left: 8px; border: 1px solid #e2e8f0;
        }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; }

        /* Botón WhatsApp */
        .whatsapp-capsule {
            position: absolute; bottom: 35px; left: 50%; transform: translateX(-50%); z-index: 1000;
            background: #25D366; color: white; padding: 10px 24px; border-radius: 50px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 8px;
            font-weight: 600; text-decoration: none; transition: all 0.2s; font-size: 15px;
            white-space: nowrap; border: 2px solid white;
        }
        .whatsapp-capsule:hover { transform: translateX(-50%) scale(1.05); }

        /* Marcadores y Etiquetas */
        .vehicle-marker-container { filter: drop-shadow(0 3px 5px rgba(0,0,0,0.5)); transition: all 0.5s linear; }
        
        .unit-label {
            background: rgba(15, 23, 42, 0.95); color: white; border-radius: 4px; 
            padding: 3px 8px; font-size: 11px; white-space: nowrap; font-weight: 700;
            border: 1px solid rgba(255,255,255,0.3); margin-top: -55px; text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3); text-shadow: 0 1px 2px black;
        }
        .unit-label::after {
            content: ''; position: absolute; bottom: -5px; left: 50%; transform: translateX(-50%);
            border-width: 5px 5px 0; border-style: solid;
            border-color: rgba(15, 23, 42, 0.95) transparent transparent transparent;
        }
        
        .leaflet-popup-content-wrapper { border-radius: 12px; padding: 0; overflow: hidden; }
        .leaflet-popup-content { margin: 0; width: 240px !important; }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="floating-header" id="headerInfo">
        <div class="flex items-center gap-3">
            <img src="https://TU-WEB.COM/img/logo.png" alt="Goratrack" class="h-6 md:h-8">
            <div id="fleetStatus" class="hidden md:flex">
                <!-- Se llena dinámicamente -->
            </div>
        </div>
        <div class="text-right">
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Última Señal</p>
            <p id="lastUpdate" class="text-xs font-bold text-gray-700">Cargando...</p>
        </div>
    </div>

    <!-- Mapa -->
    <div id="map"></div>

    <!-- Overlay Error -->
    <div id="overlayMessage" class="hidden absolute inset-0 bg-slate-900/95 z-[2000] flex items-center justify-center p-6 backdrop-blur-md">
        <div class="text-center text-white max-w-sm bg-white/10 p-8 rounded-2xl border border-white/10">
            <div id="overlayIcon" class="mb-4 text-6xl">⚠️</div>
            <h2 id="overlayTitle" class="text-2xl font-bold mb-2">Aviso</h2>
            <p id="overlayDesc" class="text-gray-300 text-sm leading-relaxed">...</p>
        </div>
    </div>

    <!-- Botón WA -->
    <a href="#" id="waLink" target="_blank" class="whatsapp-capsule">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M13.601 2.326A7.854 7.854 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.933 7.933 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.898 7.898 0 0 0 13.6 2.326zM7.994 14.521a6.573 6.573 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.557 6.557 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592z"/></svg>
        <span>Ayuda C4</span>
    </a>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const TOKEN = urlParams.get('token');
        const REFRESH_MS = 30000;
        const DISCONNECT_MIN = 20; 

        // Saludo WA
        const h = new Date().getHours();
        document.getElementById('waLink').href = `https://wa.me/TU-NUMERO-DE-WHATSAPP?text=${encodeURIComponent((h<12?"Buenos días":h<19?"Buenas tardes":"Buenas noches") + " C4")}`;

        // Mapa
        const map = L.map('map', { zoomControl: false }).setView([18.14, -94.46], 10);
        L.control.zoom({ position: 'bottomleft' }).addTo(map);
        
        const satellite = L.tileLayer('http://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}',{maxZoom:20,subdomains:['mt0','mt1','mt2','mt3']});
        const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19});
        L.control.layers({"Satélite":satellite, "Mapa":street}, null, {position:'bottomright'}).addTo(map);
        satellite.addTo(map);

        let markers = {};
        let hasCentered = false;

        function toCDMX(dt) {
            if(!dt || dt === 'Sin conexión') return '--:--';
            try {
                let iso = dt.replace(' ', 'T') + (dt.endsWith('Z') ? '' : 'Z');
                return new Intl.DateTimeFormat('es-MX', {timeZone:'America/Mexico_City',hour:'2-digit',minute:'2-digit',hour12:true}).format(new Date(iso));
            } catch(e) { return dt.split(' ')[1] || dt; }
        }

        function getIcon(angle, color) {
            return L.divIcon({
                className: 'vehicle-marker-container',
                html: `<svg viewBox="0 0 24 24" style="width:100%;height:100%;transform:rotate(${angle}deg);" fill="none"><path d="M12 2L2 22l10-3 10 3L12 2z" fill="${color}" stroke="white" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="white"/></svg>`,
                iconSize: [40, 40], iconAnchor: [20, 20], popupAnchor: [0, -10]
            });
        }

        async function update() {
            try {
                const res = await fetch(`backend.php?action=get_positions&token=${TOKEN}&_=${Date.now()}`);
                const data = await res.json();
                
                if(data.error) {
                    if(data.error === 'expired') showOverlay("Caducado", "Enlace finalizado.", "🕒");
                    else showOverlay("Error", data.error);
                    return;
                }

                if(!data.positions) return;

                const bounds = [];
                const now = new Date();
                let activeCount = 0;
                let errorCount = 0;

                data.positions.forEach(u => {
                    // Limpieza estricta de nombre
                    let name = u.name;
                    if(!name || name === 'null' || name === 'undefined') name = `Unidad ${u.imei.slice(-4)}`;

                    // Si no es válida (0,0 o sin reporte), no la ponemos en el mapa, pero la contamos
                    if(!u.valid) {
                        errorCount++;
                        return; // Saltamos renderizado
                    }
                    
                    activeCount++;
                    const pos = [u.lat, u.lng];
                    bounds.push(pos);

                    // Lógica de Estado
                    let iso = u.dt.replace(' ', 'T') + (u.dt.endsWith('Z')?'':'Z');
                    let mins = Math.floor((now - new Date(iso))/60000);
                    let color = (mins > DISCONNECT_MIN) ? '#f97316' : (u.speed > 3 ? '#22c55e' : '#eab308');
                    let timeStr = toCDMX(u.dt);

                    // HTML Popup
                    let statusTxt = (mins > DISCONNECT_MIN) ? `<span class="text-orange-600 font-bold">⚠️ Sin Señal (${mins}m)</span>` :
                                    (u.speed > 3) ? `<span class="text-green-600 font-bold">En Movimiento (${u.speed} km/h)</span>` :
                                    `<span class="text-yellow-600 font-bold">Detenido</span>`;

                    let popupContent = `
                        <div class="font-sans">
                            <div class="bg-slate-100 p-3 border-b border-slate-200"><h3 class="font-bold text-slate-800 text-sm">${name}</h3></div>
                            <div class="p-3 text-sm">
                                <div class="mb-2">${statusTxt}</div>
                                <div class="text-xs text-gray-500">Último reporte: ${timeStr}</div>
                            </div>
                        </div>`;

                    if(markers[u.imei]) {
                        let m = markers[u.imei];
                        m.marker.setLatLng(pos).setIcon(getIcon(u.angle, color));
                        if(!m.marker.isPopupOpen()) m.marker.setPopupContent(popupContent);
                        m.label.setLatLng(pos);
                        m.label._icon.innerHTML = `<div class="unit-label">${name}</div>`;
                    } else {
                        let marker = L.marker(pos, {icon: getIcon(u.angle, color)}).addTo(map).bindPopup(popupContent);
                        let label = L.marker(pos, {
                            icon: L.divIcon({className:'bg-transparent', html:`<div class="unit-label">${name}</div>`, iconSize:[150,20], iconAnchor:[75,0]}),
                            interactive: false, zIndexOffset: 1000
                        }).addTo(map);
                        markers[u.imei] = {marker, label};
                    }
                });

                // Actualizar contadores del Header
                const fleetEl = document.getElementById('fleetStatus');
                fleetEl.innerHTML = `
                    <div class="status-pill"><div class="status-dot bg-green-500"></div> ${activeCount} En Mapa</div>
                    ${errorCount > 0 ? `<div class="status-pill text-red-600 border-red-200 bg-red-50"><div class="status-dot bg-red-500 animate-pulse"></div> ${errorCount} Sin Señal</div>` : ''}
                `;

                document.getElementById('lastUpdate').innerText = toCDMX(new Date().toISOString());

                if(!hasCentered && bounds.length) {
                    map.fitBounds(bounds, {padding:[80,80], maxZoom:16});
                    hasCentered = true;
                }

            } catch(e) { console.error(e); }
        }

        function showOverlay(t, d, i="⚠️") {
            document.getElementById('overlayMessage').classList.remove('hidden');
            document.getElementById('overlayTitle').innerText=t; document.getElementById('overlayDesc').innerText=d; document.getElementById('overlayIcon').innerText=i;
            if(map) map.remove();
        }

        if(!TOKEN) showOverlay("Error", "Falta token");
        else { update(); setInterval(update, REFRESH_MS); }
    </script>
</body>
</html>