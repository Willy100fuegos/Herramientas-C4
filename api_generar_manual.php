<?php
// ======================================================================================
// API BACKEND V12 - AUTONOMÍA TOTAL (INDIVIDUAL PARSING)
// ======================================================================================
// 1. Detección Individual: Escanea la columna de velocidad para CADA unidad independientemente.
//    (Soluciona el error donde una unidad "rara" rompía la lectura de las demás).
// 2. Lotes Moderados: Mantiene Lote=3 para estabilidad.
// 3. Time Limit 0: Ejecución infinita permitida.
// ======================================================================================

ini_set('display_errors', 0); 
ini_set('log_errors', 1);     
set_time_limit(0); 
ini_set('memory_limit', '4096M'); 
ignore_user_abort(true); 

header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function debug_log($msg) {
    $logFile = __DIR__ . '/debug_log.txt';
    $time = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$time] $msg" . PHP_EOL, FILE_APPEND);
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        if (!headers_sent()) echo json_encode(['success' => false, 'message' => 'Fatal Error: ' . $error['message']]);
        debug_log("FATAL ERROR: " . $error['message']);
    }
});

try {
    date_default_timezone_set('America/Mexico_City');

    // 1. CARGAR PHPMAILER
    $rutasPosibles = [__DIR__ . '/../phpmailer/PHPMailer.php', 'phpmailer/PHPMailer.php'];
    $libsCargadas = false;
    foreach ($rutasPosibles as $rutaBase) {
        if (file_exists($rutaBase)) {
            $dir = dirname($rutaBase);
            require_once $dir . '/Exception.php';
            require_once $dir . '/PHPMailer.php';
            require_once $dir . '/SMTP.php';
            $libsCargadas = true;
            break;
        }
    }
    if (!$libsCargadas) throw new Exception("Falta PHPMailer.");

    // 2. RECEPCIÓN DE DATOS
    $action = $_POST['action'] ?? '';
    $api_key = $_POST['api_key'] ?? 'TU_API'; 
    $account_name = $_POST['account_name'] ?? 'General';
    $configFile = __DIR__ . "/config_{$account_name}.json";
    
    $reportesDir = __DIR__ . '/reportes/tqpm';
    $reportesUrlBase = 'https://TU-WEB.mx/api/reportes/tqpm';
    if (!is_dir($reportesDir)) mkdir($reportesDir, 0777, true);

    define('GORATRACK_BASE_URL', 'https://TU-WEB.mx/api/api.php');
    $umbral_velocidad = 60; 
    $LOTE_SIZE = 3; 

    if ($action === 'save_config') {
        $emails = $_POST['emails'] ?? '';
        $data = ["correos_destino" => $emails, "updated_at" => date('Y-m-d H:i:s')];
        file_put_contents($configFile, json_encode($data, JSON_PRETTY_PRINT));
        exit;
    }

    if ($action === 'generate_now') {
        
        $target_imeis = json_decode($_POST['units'] ?? '[]', true);
        $recipients_list = $_POST['emails'] ?? '';
        $start_raw = $_POST['start'] ?? '';
        $end_raw = $_POST['end'] ?? '';

        if (empty($target_imeis)) throw new Exception("Sin unidades seleccionadas.");

        $start_date = str_replace('T', ' ', $start_raw) . ':00';
        $end_date = str_replace('T', ' ', $end_raw) . ':00';

        if (strtotime($end_date) > time()) {
            $end_date = date('Y-m-d H:i:s');
        }

        // Texto Periodo
        $meses_es = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $dia_inicio = date('d', strtotime($start_date));
        $mes_inicio = $meses_es[(int)date('n', strtotime($start_date))];
        $dia_fin = date('d', strtotime($end_date));
        $mes_fin = $meses_es[(int)date('n', strtotime($end_date))];
        $anio = date('Y', strtotime($end_date));
        $periodo_texto = "del $dia_inicio de $mes_inicio al $dia_fin de $mes_fin de $anio";

        // Obtener Nombres
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, GORATRACK_BASE_URL . '?api=user&key=' . $api_key . '&cmd=USER_GET_OBJECTS');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $units_data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $imei_map = [];
        if (is_array($units_data)) { foreach ($units_data as $u) { if (isset($u['imei'])) $imei_map[$u['imei']] = $u['name']; } }

        // --- PROCESAMIENTO ---
        $datos_dashboard = [];
        $total_incidentes = 0;
        $vel_maxima_global = 0;
        
        // Variables Diagnóstico
        $debug_first_raw_point = null;
        $debug_api_response_empty = false;
        $unidades_con_error = [];

        $chunks = array_chunk($target_imeis, $LOTE_SIZE);

        foreach ($chunks as $chunk_index => $imeis_lote) {
            
            $multi_curl = curl_multi_init();
            $handles = [];

            foreach ($imeis_lote as $imei) {
                $cmd = sprintf('OBJECT_GET_ROUTE,%s,%s,%s,1', rawurlencode($imei), rawurlencode($start_date), rawurlencode($end_date));
                $url = GORATRACK_BASE_URL . '?api=user&key=' . $api_key . '&cmd=' . $cmd;
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 180); 
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
                curl_multi_add_handle($multi_curl, $ch);
                $handles[$imei] = $ch;
            }

            $active = null;
            do { $mrc = curl_multi_exec($multi_curl, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            while ($active && $mrc == CURLM_OK) {
                if (curl_multi_select($multi_curl) == -1) usleep(100000);
                do { $mrc = curl_multi_exec($multi_curl, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }

            foreach ($handles as $imei => $ch) {
                $raw_response = curl_multi_getcontent($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($multi_curl, $ch);
                curl_close($ch);

                try {
                    if ($http_code != 200 || !$raw_response) throw new Exception("HTTP $http_code");

                    $unit_name = $imei_map[$imei] ?? "IMEI: $imei";
                    $data = json_decode($raw_response, true);
                    unset($raw_response); 

                    if (!isset($data['route']) || !is_array($data['route'])) continue; 

                    if (count($data['route']) > 0) {
                        
                        // >>> CAMBIO CRÍTICO V12: DETECCIÓN INDEPENDIENTE POR UNIDAD <<<
                        // Reiniciamos el índice para cada unidad. No asumimos que todas son iguales.
                        $detected_idx_for_this_unit = 4; // Default seguro
                        
                        $candidates = [3, 4, 5, 6, 7];
                        $scores = [];
                        // Tomamos una muestra representativa de ESTA unidad
                        $sample = array_slice($data['route'], 0, 150);
                        
                        foreach ($candidates as $idx) {
                            $max_val = 0; $zeros = 0; $count = 0;
                            foreach ($sample as $p) {
                                if (isset($p[$idx])) {
                                    $val = floatval($p[$idx]);
                                    if ($val > $max_val) $max_val = $val;
                                    if ($val == 0) $zeros++;
                                    $count++;
                                }
                            }
                            $score = 0;
                            if ($max_val > 240) $score = -100; // Heading probable
                            elseif ($count > 0 && ($zeros / $count) > 0.99) $score = -10; // Columna vacía
                            elseif ($max_val > 0) $score = 100 + $max_val; 
                            $scores[$idx] = $score;
                        }
                        
                        arsort($scores);
                        $best = array_key_first($scores);
                        if ($best !== null && $scores[$best] > -50) {
                            $detected_idx_for_this_unit = $best;
                        }

                        // Guardamos una muestra para diagnóstico si es el primero del lote
                        if ($debug_first_raw_point === null) $debug_first_raw_point = $data['route'][0];

                        // PROCESAMIENTO CON EL ÍNDICE ESPECÍFICO
                        $idx_vel = $detected_idx_for_this_unit;
                        $in_event = false;
                        $current_event = [];

                        foreach ($data['route'] as $point) {
                            if (!is_array($point) || count($point) < 4) continue;

                            $raw_time = $point[0];
                            if (is_numeric($raw_time)) {
                                $p_time_ts = intval($raw_time);
                                $p_time_fmt = date('Y-m-d H:i:s', $p_time_ts);
                            } else {
                                $p_time_ts = strtotime($raw_time);
                                $p_time_fmt = $raw_time;
                            }

                            $p_lat = floatval($point[1]);
                            $p_lon = floatval($point[2]);
                            $p_speed = isset($point[$idx_vel]) ? intval($point[$idx_vel]) : 0;

                            if ($p_speed > $umbral_velocidad) {
                                if (!$in_event) {
                                    $in_event = true;
                                    $current_event = [
                                        'unit' => $unit_name,
                                        'start_ts' => $p_time_ts,
                                        'start_fmt' => $p_time_fmt,
                                        'max_speed' => $p_speed,
                                        'lat_peak' => $p_lat,
                                        'lon_peak' => $p_lon
                                    ];
                                } else {
                                    if ($p_speed > $current_event['max_speed']) {
                                        $current_event['max_speed'] = $p_speed;
                                        $current_event['lat_peak'] = $p_lat;
                                        $current_event['lon_peak'] = $p_lon;
                                    }
                                }
                                $current_event['end_ts'] = $p_time_ts;
                            } else {
                                if ($in_event) {
                                    $in_event = false;
                                    $duration_sec = $current_event['end_ts'] - $current_event['start_ts'];
                                    $hours = floor($duration_sec / 3600);
                                    $mins = floor(($duration_sec % 3600) / 60);
                                    $secs = $duration_sec % 60;
                                    $dur_str = ($hours>0?"{$hours}h ":"").($mins>0?"{$mins}m ":"")."{$secs}s";
                                    if (empty($dur_str)) $dur_str = "0s";
                                    $minutes_float = max(0.1, $duration_sec / 60);

                                    $datos_dashboard[] = [
                                        'unit' => $current_event['unit'],
                                        'inicio' => $current_event['start_fmt'],
                                        'duracion' => $dur_str,
                                        'durationMinutes' => round($minutes_float, 2),
                                        'maxSpeed' => $current_event['max_speed'],
                                        'lat' => $current_event['lat_peak'],
                                        'lon' => $current_event['lon_peak']
                                    ];
                                    $total_incidentes++;
                                    if ($current_event['max_speed'] > $vel_maxima_global) $vel_maxima_global = $current_event['max_speed'];
                                }
                            }
                        }
                        
                        if ($in_event) {
                            $duration_sec = $current_event['end_ts'] - $current_event['start_ts'];
                            $minutes_float = max(0.1, $duration_sec / 60);
                            $datos_dashboard[] = [
                                'unit' => $current_event['unit'],
                                'inicio' => $current_event['start_fmt'],
                                'duracion' => round($duration_sec/60, 1) . " min",
                                'durationMinutes' => round($minutes_float, 2),
                                'maxSpeed' => $current_event['max_speed'],
                                'lat' => $current_event['lat_peak'],
                                'lon' => $current_event['lon_peak']
                            ];
                            $total_incidentes++;
                        }
                    } else {
                        $debug_api_response_empty = true;
                    }
                    unset($data);

                } catch (Throwable $t) {
                    $unidades_con_error[] = $imei;
                    debug_log("Error en unidad $imei: " . $t->getMessage());
                }
            } 

            curl_multi_close($multi_curl);
            usleep(200000); 
            gc_collect_cycles(); 
        } 

        // --- PREPARAR CORREO ---
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'TU_SERVIDOR_DE_CORREO'; $mail->SMTPAuth = true; $mail->Username = 'TU_USUARIO_DE_CORREO'; $mail->Password = 'CONTRAÑSEÑA_DE_TU_CORREO'; $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; $mail->Port = 465; $mail->CharSet = 'UTF-8';
        $mail->setFrom('MAIL_REMITENTE', 'Centro de Reportes');
        
        $destinatarios = explode(',', $recipients_list);
        foreach ($destinatarios as $email) { if (filter_var(trim($email), FILTER_VALIDATE_EMAIL)) $mail->addAddress(trim($email)); }
        $mail->isHTML(true);

        $url_archivo = "#";

        if ($total_incidentes === 0) {
            // MODO DIAGNÓSTICO
            $sample_data_json = json_encode($debug_first_raw_point);
            $msg_extra = $debug_api_response_empty ? "La API devolvió 0 puntos de ruta." : "Se procesaron rutas pero no se detectaron excesos.";
            if (!empty($unidades_con_error)) {
                $msg_extra .= " (Nota: " . count($unidades_con_error) . " unidades fallaron).";
            }
            
            $mail->Subject = "⚠️ Diagnóstico de Reporte - Sin Incidentes";
            $mail->Body = "
            <div style='font-family:Arial;padding:20px;border:1px solid #ccc;'>
                <h2 style='color:#d97706;'>Reporte de Diagnóstico</h2>
                <p>El sistema ejecutó la consulta (V12 Individual) pero <strong>no encontró incidentes</strong>.</p>
                <p><strong>Estado:</strong> $msg_extra</p>
                <hr>
                <h3 style='color:#333;'>Datos Técnicos</h3>
                <p><strong>Muestra de Datos (Primer Punto procesado):</strong></p>
                <div style='background:#f5f5f5;padding:10px;font-family:monospace;font-size:11px;overflow-x:auto;'>
                    $sample_data_json
                </div>
            </div>";

            $html_debug = "<h1>Diagnóstico</h1><p>Verifique su correo para los detalles técnicos.</p>";
            $nombre_debug = "diagnostico_" . date('Ymd_His') . ".html";
            file_put_contents("$reportesDir/$nombre_debug", $html_debug);
            $url_archivo = "$reportesUrlBase/$nombre_debug";

        } else {
            // MODO ÉXITO
            usort($datos_dashboard, function($a, $b) { return $b['maxSpeed'] <=> $a['maxSpeed']; });
            $json_data = json_encode($datos_dashboard);
            
            $nombre_archivo = "reporte_velocidad_" . date('Ymd_His') . ".html";
            $ruta_archivo = "$reportesDir/$nombre_archivo";
            $url_archivo = "$reportesUrlBase/$nombre_archivo";
            
            $html_template = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Ejecutivo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; }
        #heatmap-map { height: 500px; border-radius: 0.75rem; border: 1px solid #e2e8f0; }
        .details-table-container { max-height: 500px; overflow-y: auto; }
        .table-row-details:hover { cursor: pointer; background-color: #eff6ff; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
    </style>
</head>
<body class="text-gray-800 p-6">
    <div class="mb-6 bg-white p-6 rounded-xl shadow border-b-4 border-blue-600 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-800">Reporte Ejecutivo de Velocidad</h1>
            <p class="text-sm font-medium text-gray-500 uppercase mt-1">$periodo_texto</p>
        </div>
        <img src="https://TU-WEB.mx/img/logo.png" class="h-10">
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <aside class="lg:col-span-2 bg-white p-4 rounded-xl shadow">
            <h2 class="text-xs font-bold text-gray-400 uppercase mb-3">Filtrar Unidad</h2>
            <div id="unit-filters-container" class="space-y-1 max-h-[400px] overflow-y-auto custom-scroll"></div>
            <button id="clear-filter-btn" class="w-full mt-4 bg-blue-50 text-blue-600 font-bold py-2 rounded text-sm">Mostrar Todas</button>
        </aside>

        <main class="lg:col-span-10 space-y-6">
            <section class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-xl shadow border-t-4 border-blue-500">
                    <p class="text-xs font-bold text-gray-400 uppercase">Incidentes</p>
                    <p id="kpi-total-incidents" class="text-4xl font-extrabold text-blue-600 mt-2">0</p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow border-t-4 border-red-500">
                    <p class="text-xs font-bold text-gray-400 uppercase">Vel. Máxima</p>
                    <p id="kpi-max-speed" class="text-4xl font-extrabold text-red-600 mt-2">0 <span class="text-lg text-gray-400">km/h</span></p>
                </div>
                <div class="bg-white p-6 rounded-xl shadow border-t-4 border-orange-500">
                    <p class="text-xs font-bold text-gray-400 uppercase">Mayor Riesgo</p>
                    <p id="kpi-top-unit" class="text-xl font-bold text-orange-600 mt-3 truncate">N/A</p>
                </div>
            </section>

            <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="bg-white p-6 rounded-xl shadow">
                    <h3 class="font-bold text-gray-700 mb-4">Top 5 Velocidad</h3>
                    <div id="ranking-speed-chart" class="space-y-3"></div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow">
                    <h3 class="font-bold text-gray-700 mb-4">Top 5 Duración</h3>
                    <div id="ranking-duration-chart" class="space-y-3"></div>
                </div>
            </section>

            <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="bg-white p-1 rounded-xl shadow">
                    <div class="p-4 pb-0"><h3 class="font-bold text-gray-700 mb-2">Mapa de Calor</h3></div>
                    <div id="heatmap-map"></div>
                </div>
                <div class="bg-white rounded-xl shadow flex flex-col overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b"><h3 class="font-bold text-gray-700">Detalle</h3></div>
                    <div class="details-table-container flex-1 bg-white">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-gray-500 uppercase bg-gray-50 sticky top-0">
                                <tr><th class="px-6 py-3">Unidad</th><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Duración</th><th class="px-6 py-3 text-right">Vel</th></tr>
                            </thead>
                            <tbody id="details-table-body" class="divide-y divide-gray-100"></tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
    <script>
        const processedData = $json_data;
        let activeUnitFilter = null, map, heatLayer, markersGroup;

        const formatMinutesToHours = (m) => { const h=Math.floor(m/60), min=Math.round(m%60); return h>0 ? `\${h}h \${min}m` : `\${min}m`; };
        const getColor = (s) => s>110 ? 'bg-red-600' : (s>90 ? 'bg-red-500' : 'bg-yellow-500');

        function renderAll(data) {
            document.getElementById('kpi-total-incidents').textContent = data.length;
            const max = data.length ? Math.max(...data.map(d=>d.maxSpeed)) : 0;
            document.getElementById('kpi-max-speed').innerHTML = `\${max} <span class="text-lg text-gray-400">km/h</span>`;
            
            const durU = {}; data.forEach(d=>durU[d.unit]=(durU[d.unit]||0)+d.durationMinutes);
            const topArr = Object.entries(durU).sort((a,b)=>b[1]-a[1]);
            document.getElementById('kpi-top-unit').textContent = topArr[0] ? `\${topArr[0][0]} (\${formatMinutesToHours(topArr[0][1])})` : 'N/A';

            const sp = document.getElementById('ranking-speed-chart'); sp.innerHTML = '';
            const unitsMax = {}; data.forEach(d=>{ if(!unitsMax[d.unit]||d.maxSpeed>unitsMax[d.unit]) unitsMax[d.unit]=d.maxSpeed; });
            Object.entries(unitsMax).sort((a,b)=>b[1]-a[1]).slice(0,5).forEach(i => {
                sp.innerHTML += `<div><div class="flex justify-between text-xs font-bold text-gray-500 mb-1"><span>\${i[0]}</span><span>\${i[1]} km/h</span></div><div class="w-full bg-gray-100 rounded-full h-2"><div class="h-2 rounded-full \${getColor(i[1])}" style="width:\${(i[1]/200)*100}%"></div></div></div>`;
            });

            const dur = document.getElementById('ranking-duration-chart'); dur.innerHTML = '';
            topArr.slice(0,5).forEach(i => {
                dur.innerHTML += `<div><div class="flex justify-between text-xs font-bold text-gray-500 mb-1"><span>\${i[0]}</span><span>\${formatMinutesToHours(i[1])}</span></div><div class="w-full bg-gray-100 rounded-full h-2"><div class="h-2 rounded-full bg-orange-500" style="width:\${(i[1]/topArr[0][1])*100}%"></div></div></div>`;
            });

            if(!map) { map = L.map('heatmap-map').setView([19.43,-99.13], 5); L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png').addTo(map); }
            if(heatLayer) map.removeLayer(heatLayer);
            if(markersGroup) map.removeLayer(markersGroup);

            const pts = data.filter(d=>d.lat!=0).map(d=>[d.lat,d.lon,(d.maxSpeed-60)/80]);
            if(pts.length) {
                heatLayer = L.heatLayer(pts, {radius:20, blur:15, maxZoom:14, gradient:{0.4:'blue', 0.65:'lime', 1:'red'}}).addTo(map);
                markersGroup = L.featureGroup(data.filter(d=>d.lat!=0).map(d=>L.circleMarker([d.lat,d.lon],{radius:1,opacity:0}).bindPopup(`<b>\${d.unit}</b><br>\${d.maxSpeed} km/h`))).addTo(map);
                map.fitBounds(markersGroup.getBounds().pad(0.1));
            }

            const tb = document.getElementById('details-table-body'); tb.innerHTML = '';
            data.sort((a,b)=>b.maxSpeed-a.maxSpeed).forEach(i => {
                const row = document.createElement('tr'); row.className="bg-white border-b table-row-details";
                row.onclick = () => { if(i.lat!=0) { map.setView([i.lat,i.lon], 16); L.popup().setLatLng([i.lat,i.lon]).setContent(`<b>\${i.unit}</b><br>\${i.maxSpeed} km/h`).openOn(map); } };
                row.innerHTML = `<td class="px-6 py-3 font-semibold">\${i.unit}</td><td class="px-6 py-3 text-gray-500">\${i.inicio}</td><td class="px-6 py-3 text-xs font-mono">\${i.duracion}</td><td class="px-6 py-3 text-right"><span class="px-2 py-1 text-white text-xs font-bold rounded \${getColor(i.maxSpeed)}">\${i.maxSpeed}</span></td>`;
                tb.appendChild(row);
            });
        }

        const fc = document.getElementById('unit-filters-container'); fc.innerHTML = '';
        [...new Set(processedData.map(d=>d.unit))].sort().forEach(u => {
            const btn = document.createElement('button');
            const count = processedData.filter(d=>d.unit===u).length;
            const active = activeUnitFilter===u;
            btn.className = `w-full text-left px-3 py-2 rounded mb-1 text-xs flex justify-between \${active?'bg-blue-600 text-white':'hover:bg-gray-100 text-gray-600'}`;
            btn.innerHTML = `<span>\${u}</span><span class="bg-white/20 px-1 rounded">\${count}</span>`;
            btn.onclick = () => { activeUnitFilter = active ? null : u; renderAll(activeUnitFilter ? processedData.filter(d=>d.unit===activeUnitFilter) : processedData); };
            fc.appendChild(btn);
        });
        document.getElementById('clear-filter-btn').onclick = () => { activeUnitFilter=null; renderAll(processedData); };

        renderAll(processedData);
    </script>
</body>
</html>
HTML;
            file_put_contents($ruta_archivo, $html_template);

            $top_10 = array_slice($datos_dashboard, 0, 10);
            $rows = "";
            foreach($top_10 as $d) {
                $c = $d['maxSpeed']>100?'#dc2626':'#ea580c';
                $rows .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;'><b>{$d['unit']}</b></td><td style='padding:8px;border-bottom:1px solid #eee;'>{$d['inicio']}</td><td style='padding:8px;border-bottom:1px solid #eee;'>{$d['duracion']}</td><td style='padding:8px;border-bottom:1px solid #eee;color:$c;font-weight:bold;'>{$d['maxSpeed']} kph</td></tr>";
            }
            
            $mail->Subject = "📊 Reporte de Velocidad - $periodo_texto";
            $mail->Body = "
            <div style='font-family:Arial;max-width:600px;margin:auto;border:1px solid #eee;border-radius:8px;'>
                <div style='background:#fff;padding:20px;text-align:center;border-bottom:4px solid #2563eb;'>
                    <img src='https://TU-WEB.mx/img/logo.png' height='40'>
                    <h2 style='color:#333;margin:10px 0;'>Reporte Ejecutivo</h2>
                    <p style='color:#666;font-size:14px;'>$periodo_texto</p>
                </div>
                <div style='padding:15px;background:#f9fafb;text-align:center;'>
                    <span style='margin:0 15px;'>INCIDENCIAS: <b style='color:#2563eb;font-size:20px'>$total_incidentes</b></span>
                    <span style='margin:0 15px;'>MÁXIMA: <b style='color:#dc2626;font-size:20px'>$vel_maxima_global kph</b></span>
                </div>
                <div style='padding:20px;'>
                    <h3 style='font-size:16px;color:#333;border-bottom:2px solid #eee;padding-bottom:5px;'>Top 10 Riesgos</h3>
                    <table width='100%' cellspacing='0' style='font-size:13px;'>
                        <thead style='background:#f3f4f6;'><tr><th align='left' style='padding:8px;'>Unidad</th><th align='left' style='padding:8px;'>Inicio</th><th align='left' style='padding:8px;'>Duración</th><th align='left' style='padding:8px;'>Vel</th></tr></thead>
                        <tbody>$rows</tbody>
                    </table>
                </div>
                <div style='background:#f1f5f9;padding:30px;text-align:center;'>
                    <a href='$url_archivo' style='background:#2563eb;color:#fff;padding:12px 25px;text-decoration:none;border-radius:6px;font-weight:bold;'>Ver Dashboard Interactivo</a>
                </div>
            </div>";
        }

        $mail->send();
        echo json_encode(['success' => true, 'url' => $url_archivo]);
    }

} catch (Throwable $e) {
    debug_log("EXCEPCIÓN GLOBAL: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>