<?php
// ======================================================================================
// MOTOR DE REPORTE AUTOMATIZADO V7 - FINAL (CRON JOB)
// ======================================================================================
// Ajustes: Icono en asunto y formato de fecha corto (dd/M/aaaa).
// ======================================================================================

// --- 0. CONFIGURACIÓN ---
ini_set('display_errors', 1);
ini_set('error_reporting', E_ALL);
date_default_timezone_set('America/Mexico_City');

// Librerías de Correo
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (file_exists(__DIR__ . '/../phpmailer/PHPMailer.php')) {
    require __DIR__ . '/../phpmailer/Exception.php';
    require __DIR__ . '/../phpmailer/PHPMailer.php';
    require __DIR__ . '/../phpmailer/SMTP.php';
} else {
    require 'phpmailer/Exception.php';
    require 'phpmailer/PHPMailer.php';
    require 'phpmailer/SMTP.php';
}

// Configuración API
define('GORATRACK_API_KEY', 'AQUI_VA_TU_API'); 
define('GORATRACK_BASE_URL', 'https://TU-WEB.mx/api/api.php');
define('UMBRAL_VELOCIDAD', 60); 

// Rutas
$configFile = __DIR__ . '/config_reportes.json';
$reportesDir = __DIR__ . '/reportes/tqpm';
$reportesUrlBase = 'https://TU-WEB.mx/api/reportes/tqpm';

if (!is_dir($reportesDir)) mkdir($reportesDir, 0777, true);

// --- 1. OBTENER MAPA DE NOMBRES ---
$ch_units = curl_init();
curl_setopt($ch_units, CURLOPT_URL, GORATRACK_BASE_URL . '?api=user&key=' . GORATRACK_API_KEY . '&cmd=USER_GET_OBJECTS');
curl_setopt($ch_units, CURLOPT_RETURNTRANSFER, true);
$resp_units = curl_exec($ch_units);
curl_close($ch_units);
$units_data = json_decode($resp_units, true);

$imei_map = [];
if (is_array($units_data)) {
    foreach ($units_data as $u) {
        if (isset($u['imei'])) $imei_map[$u['imei']] = $u['name'];
    }
}

// --- 2. FECHAS Y CONFIGURACIÓN ---
if (!file_exists($configFile)) die("Error: Falta config_reportes.json");
$config = json_decode(file_get_contents($configFile), true);
$target_imeis = $config['unidades_seleccionadas'] ?? [];
$recipients_list = $config['correos_destino'] ?? '';

if (empty($target_imeis)) die("No hay unidades seleccionadas.");

$start_date = date('Y-m-d 00:00:00', strtotime('monday last week'));
$end_date = date('Y-m-d 23:59:59', strtotime('sunday last week'));

// Traducción de meses cortos para formato "17/Nov/2025"
$meses_en = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$meses_es = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

$fecha_inicio_fmt = str_replace($meses_en, $meses_es, date('d/M/Y', strtotime($start_date)));
$fecha_fin_fmt = str_replace($meses_en, $meses_es, date('d/M/Y', strtotime($end_date)));

$periodo_asunto = "$fecha_inicio_fmt al $fecha_fin_fmt"; // Para el asunto del mail
$periodo_texto_largo = "del $fecha_inicio_fmt al $fecha_fin_fmt"; // Para el cuerpo del reporte

$nombre_archivo_html = "reporte_semana_" . date('W', strtotime($start_date)) . "_" . date('Y') . ".html";
$ruta_completa_archivo = "$reportesDir/$nombre_archivo_html";
$url_archivo_final = "$reportesUrlBase/$nombre_archivo_html";

// --- 3. PROCESAMIENTO DE DATOS ---
$datos_dashboard = [];
$total_incidentes = 0;
$vel_maxima_global = 0;
$top_riesgo_unit = "N/A";

foreach ($target_imeis as $imei) {
    $unit_name = $imei_map[$imei] ?? "IMEI: $imei";
    
    $cmd = sprintf('OBJECT_GET_ROUTE,%s,%s,%s,1', rawurlencode($imei), rawurlencode($start_date), rawurlencode($end_date));
    $url = GORATRACK_BASE_URL . '?api=user&key=' . GORATRACK_API_KEY . '&cmd=' . $cmd;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $resp = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($resp, true);

    if (isset($data['drives'])) {
        foreach ($data['drives'] as $drive) {
            $max_speed = intval($drive['top_speed']);
            
            if ($max_speed > UMBRAL_VELOCIDAD) {
                $dur_str = $drive['duration'];
                $minutos = 0;
                // Parseo simple de duración
                if (preg_match('/(\d+)\s*h/', $dur_str, $h)) $minutos += intval($h[1]) * 60;
                if (preg_match('/(\d+)\s*min/', $dur_str, $m)) $minutos += intval($m[1]);
                if ($minutos == 0) $minutos = 0.5;

                $lat = 0; $lng = 0;
                if(isset($data['route']) && !empty($data['route'])) {
                    $lat = $data['route'][0][1] ?? 0;
                    $lng = $data['route'][0][2] ?? 0;
                }

                $datos_dashboard[] = [
                    'unit' => $unit_name,
                    'inicio' => $drive['dt_start'],
                    'duracion' => $dur_str,
                    'durationMinutes' => $minutos,
                    'maxSpeed' => $max_speed,
                    'lat' => $lat,
                    'lon' => $lng
                ];
                
                $total_incidentes++;
                if ($max_speed > $vel_maxima_global) $vel_maxima_global = $max_speed;
            }
        }
    }
}

// Calcular unidad de mayor riesgo
$duracion_por_unidad = [];
foreach ($datos_dashboard as $d) {
    if (!isset($duracion_por_unidad[$d['unit']])) $duracion_por_unidad[$d['unit']] = 0;
    $duracion_por_unidad[$d['unit']] += $d['durationMinutes'];
}
if (!empty($duracion_por_unidad)) {
    arsort($duracion_por_unidad);
    $top_riesgo_unit = array_key_first($duracion_por_unidad);
}

$json_data = json_encode($datos_dashboard);

// --- 4. GENERAR HTML DASHBOARD ---
$html_dashboard = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Ejecutivo de Riesgos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --color-rojo-critico: #E00000; --color-naranja-alto: #FF8C00; --color-amarillo-medio: #FFEB3B; --color-verde-cumplimiento: #00C853; --color-azul-corporativo: #1E88E5; }
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        #heatmap-map { height: 450px; border-radius: 0.75rem; }
        .details-table-container { max-height: 450px; overflow-y: auto; }
        .sidebar-button.active { background-color: var(--color-azul-corporativo); color: white; font-weight: 700; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .table-row-details:hover { cursor: pointer; background-color: #e2e8f0; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
    </style>
</head>
<body class="text-gray-800 p-4 lg:p-6">
    <div class="mb-6 bg-white p-4 rounded-xl shadow-lg text-center">
        <img src="https://goratrack.tecuidamos.mx/img/logo.png" alt="Goratrack" class="mx-auto h-12 w-auto mb-4">
        <h1 class="text-3xl font-extrabold text-gray-800">Dashboard Ejecutivo de Riesgos por Exceso de Velocidad</h1>
        <p id="report-date-range" class="text-lg text-gray-500 mt-1">$periodo_texto_largo</p>
    </div>
    <div class="flex justify-end items-center mb-6 gap-4">
         <button onclick="window.print()" class="bg-green-600 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:bg-green-700 transition">Imprimir / Guardar PDF</button>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <aside class="lg:col-span-2 bg-white p-4 rounded-xl shadow-lg">
            <h2 class="text-lg font-bold mb-4 border-b pb-2">Unidades</h2>
            <div id="unit-filters-container" class="space-y-2 text-sm"></div>
            <button id="clear-filter-btn" class="w-full mt-4 bg-gray-200 text-gray-700 font-semibold py-2 rounded-lg hover:bg-gray-300 transition">Mostrar Todas</button>
        </aside>
        <main class="lg:col-span-10 space-y-6">
            <section class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-5 rounded-xl shadow-lg border-l-4 border-blue-500">
                    <p class="text-sm font-semibold text-gray-500">INCIDENCIAS TOTALES</p>
                    <p id="kpi-total-incidents" class="text-4xl font-extrabold text-blue-600 mt-1">0</p>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-lg border-l-4 border-red-500">
                    <p class="text-sm font-semibold text-gray-500">VELOCIDAD MÁXIMA HISTÓRICA</p>
                    <p id="kpi-max-speed" class="text-4xl font-extrabold text-red-600 mt-1">0 kph</p>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-lg border-l-4 border-orange-500">
                    <p class="text-sm font-semibold text-gray-500">UNIDAD CON MAYOR RIESGO</p>
                    <p id="kpi-top-unit" class="text-2xl font-bold text-orange-600 mt-1 truncate">N/A</p>
                </div>
            </section>
            <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="bg-white p-5 rounded-xl shadow-lg">
                    <h3 class="font-bold text-lg mb-4">1. Top 5 por Velocidad Máxima</h3>
                    <div id="ranking-speed-chart" class="space-y-3"></div>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-lg">
                    <h3 class="font-bold text-lg mb-4">2. Top 5 por Duración Acumulada</h3>
                    <div id="ranking-duration-chart" class="space-y-3"></div>
                </div>
            </section>
            <section class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="bg-white p-5 rounded-xl shadow-lg">
                    <h3 class="font-bold text-lg mb-4">3. Mapa de Calor de Incidencias</h3>
                    <div id="heatmap-map"></div>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-lg">
                    <h3 class="font-bold text-lg mb-4">4. Detalle de Incidencias</h3>
                    <div class="details-table-container">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-100 sticky top-0">
                                <tr><th class="px-4 py-3">Unidad</th><th class="px-4 py-3">Inicio</th><th class="px-4 py-3">Duración</th><th class="px-4 py-3">Vel. Máx</th></tr>
                            </thead>
                            <tbody id="details-table-body"></tbody>
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
        let activeUnitFilter = null, map, heatLayer;
        const formatMinutesToHours = (m) => { const h=Math.floor(m/60), min=Math.round(m%60); return h>0 ? `\${h} h \${min} min` : `\${min} min`; };
        const getColorClasses = (s) => s>100 ? {bg:'bg-red-600',text:'text-red-600'} : (s>=80 ? {bg:'bg-orange-500',text:'text-orange-500'} : (s>=61 ? {bg:'bg-yellow-400',text:'text-yellow-500'} : {bg:'bg-green-500',text:'text-green-500'}));
        const createBarHtml = (l, v, dv, p, c) => `<div><div class="flex justify-between mb-1 text-sm font-medium"><span>\${l}</span><span>\${dv}</span></div><div class="w-full bg-gray-200 rounded-full h-2.5"><div class="\${c} h-2.5 rounded-full" style="width:\${p}%"></div></div></div>`;
        const renderRankings = (data) => {
            const sp = document.getElementById('ranking-speed-chart'), dur = document.getElementById('ranking-duration-chart');
            sp.innerHTML = ''; dur.innerHTML = '';
            const topS = Object.values(data.reduce((a,c)=>{if(!a[c.unit]||c.maxSpeed>a[c.unit].maxSpeed)a[c.unit]=c;return a;},{})).sort((a,b)=>b.maxSpeed-a.maxSpeed).slice(0,5);
            if(topS.length>0) topS.forEach(i => sp.innerHTML += createBarHtml(i.unit, i.maxSpeed, `\${i.maxSpeed} kph`, (i.maxSpeed/topS[0].maxSpeed)*100, getColorClasses(i.maxSpeed).bg));
            const durU = {}; data.forEach(d=>durU[d.unit]=(durU[d.unit]||0)+d.durationMinutes);
            const topD = Object.entries(durU).sort((a,b)=>b[1]-a[1]).slice(0,5);
            if(topD.length>0) topD.forEach(i => dur.innerHTML += createBarHtml(i[0], i[1], formatMinutesToHours(i[1]), (i[1]/topD[0][1])*100, 'bg-orange-500'));
        };
        const renderMap = (data) => {
            if(!map) { map = L.map('heatmap-map').setView([18.14,-94.45], 10); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map); }
            if(heatLayer) map.removeLayer(heatLayer);
            const pts = data.filter(d=>d.lat!=0).map(d=>[d.lat,d.lon,(d.maxSpeed-60)/80]);
            if(pts.length>0) { heatLayer = L.heatLayer(pts,{radius:25,blur:15,gradient:{0.4:'blue',0.6:'lime',0.8:'orange',1:'red'}}).addTo(map); map.fitBounds(L.featureGroup(data.filter(d=>d.lat!=0).map(d=>L.marker([d.lat,d.lon]))).getBounds().pad(0.1)); }
        };
        const renderTable = (data) => {
            const tb = document.getElementById('details-table-body'); tb.innerHTML = '';
            data.sort((a,b)=>b.maxSpeed-a.maxSpeed).forEach(i => {
                const row = document.createElement('tr'); row.className="bg-white border-b table-row-details";
                if(i.lat!=0) row.onclick = () => map.setView([i.lat,i.lon], 15);
                row.innerHTML = `<td class="px-4 py-2 font-semibold">\${i.unit}</td><td class="px-4 py-2">\${i.inicio}</td><td class="px-4 py-2">\${i.duracion}</td><td class="px-4 py-2"><span class="px-2 py-1 text-white text-xs font-bold rounded-full \${getColorClasses(i.maxSpeed).bg}">\${i.maxSpeed} kph</span></td>`;
                tb.appendChild(row);
            });
        };
        const renderAll = (data) => {
            document.getElementById('kpi-total-incidents').textContent = data.length;
            const max = data.length>0 ? Math.max(...data.map(d=>d.maxSpeed)) : 0;
            document.getElementById('kpi-max-speed').textContent = `\${max} kph`;
            const durU = {}; data.forEach(d=>durU[d.unit]=(durU[d.unit]||0)+d.durationMinutes);
            const top = Object.entries(durU).sort((a,b)=>b[1]-a[1])[0];
            document.getElementById('kpi-top-unit').textContent = top ? `\${top[0]} (\${formatMinutesToHours(top[1])})` : 'N/A';
            renderRankings(data); renderMap(data); renderTable(data); renderFilters(processedData);
        };
        const renderFilters = (fd) => {
            const c = document.getElementById('unit-filters-container'); c.innerHTML = '';
            [...new Set(fd.map(d=>d.unit))].sort().forEach(u => {
                const b = document.createElement('button');
                b.className = `w-full text-left p-2 rounded-lg font-medium transition sidebar-button hover:bg-gray-100 \${u===activeUnitFilter?'active bg-blue-600 text-white':''}`;
                b.textContent = u;
                b.onclick = () => { activeUnitFilter = activeUnitFilter===u ? null : u; renderAll(activeUnitFilter ? processedData.filter(d=>d.unit===activeUnitFilter) : processedData); };
                c.appendChild(b);
            });
        };
        document.getElementById('clear-filter-btn').onclick = () => { activeUnitFilter=null; renderAll(processedData); };
        renderAll(processedData);
    </script>
</body>
</html>
HTML;

file_put_contents($ruta_completa_archivo, $html_dashboard);

// --- 5. GENERAR EMAIL PREMIUM ---
usort($datos_dashboard, function($a, $b) { return $b['maxSpeed'] <=> $a['maxSpeed']; });
$top_10_email = array_slice($datos_dashboard, 0, 10);
$email_rows = "";
foreach ($top_10_email as $d) {
    $color_vel = ($d['maxSpeed'] > 100) ? '#d32f2f' : '#e65100';
    $email_rows .= "<tr style='border-bottom:1px solid #f0f0f0;'><td style='padding:10px;font-weight:600;color:#333;'>{$d['unit']}</td><td style='padding:10px;color:#666;'>{$d['inicio']}</td><td style='padding:10px;font-weight:bold;color:$color_vel;'>{$d['maxSpeed']} km/h</td></tr>";
}

$html_email = "
<div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;'>
    <div style='background-color: #ffffff; padding: 30px 20px; text-align: center; border-bottom: 4px solid #0056b3;'>
        <img src='https://goratrack.tecuidamos.mx/img/logo.png' alt='Goratrack' style='height: 40px; margin-bottom: 15px;'>
        <h2 style='margin: 0; color: #111827; font-size: 24px;'>Reporte Semanal de Riesgos</h2>
        <p style='margin: 5px 0 0; color: #6b7280; font-size: 14px;'>Periodo: $periodo_texto_largo</p>
    </div>
    <div style='background-color: #f9fafb; padding: 20px; border-bottom: 1px solid #e5e7eb;'>
        <table width='100%' cellpadding='0' cellspacing='0'>
            <tr>
                <td align='center' width='33%' style='border-right: 1px solid #e5e7eb;'>
                    <div style='font-size: 10px; font-weight: bold; color: #9ca3af; text-transform: uppercase;'>Incidencias</div>
                    <div style='font-size: 24px; font-weight: 800; color: #2563eb; margin-top: 5px;'>$total_incidentes</div>
                </td>
                <td align='center' width='33%' style='border-right: 1px solid #e5e7eb;'>
                    <div style='font-size: 10px; font-weight: bold; color: #9ca3af; text-transform: uppercase;'>Vel. Máx</div>
                    <div style='font-size: 24px; font-weight: 800; color: #dc2626; margin-top: 5px;'>$vel_maxima_global <span style='font-size:12px'>km/h</span></div>
                </td>
                <td align='center' width='33%'>
                    <div style='font-size: 10px; font-weight: bold; color: #9ca3af; text-transform: uppercase;'>Mayor Riesgo</div>
                    <div style='font-size: 16px; font-weight: 800; color: #d97706; margin-top: 5px;'>$top_riesgo_unit</div>
                </td>
            </tr>
        </table>
    </div>
    <div style='padding: 25px;'>
        <h3 style='margin-top: 0; color: #374151; font-size: 16px; border-bottom: 2px solid #f3f4f6; padding-bottom: 10px; margin-bottom: 15px;'>Top 10 Incidencias Críticas (> " . UMBRAL_VELOCIDAD . " km/h)</h3>
        <table width='100%' cellpadding='0' cellspacing='0' style='font-size: 13px; text-align: left;'>
            <tr style='background-color: #f3f4f6; color: #4b5563;'><th style='padding: 8px 10px;'>Unidad</th><th style='padding: 8px 10px;'>Fecha</th><th style='padding: 8px 10px;'>Velocidad</th></tr>
            $email_rows
        </table>
    </div>
    <div style='background-color: #f1f5f9; padding: 30px 20px; text-align: center;'>
        <p style='margin-bottom: 20px; color: #4b5563; font-size: 14px;'>Para visualizar el mapa de calor interactivo, análisis detallado y historial completo:</p>
        <a href='$url_archivo_final' style='background-color: #0056b3; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 15px; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>Ver Reporte Completo</a>
    </div>
    <div style='text-align: center; font-size: 11px; color: #9ca3af; padding: 20px;'>Generado automáticamente por Goratrack C4.<br>Tecnología de Seguridad Privada.</div>
</div>";

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'TU SERVIDOR DE CORREO';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'MAIL REMITENTE';
    $mail->Password   = 'CONTRASEÑA DEL CORREO';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom('MAIL REMITENTE', 'Reportes Goratrack');

    $destinatarios = explode(',', $recipients_list);
    foreach ($destinatarios as $email) {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mail->addAddress($email);
        }
    }
    $mail->isHTML(true);
    $mail->Subject = "📊 Reporte Semanal - Exceso de Velocidad - $periodo_asunto";
    $mail->Body    = $html_email;
    $mail->send();
    echo "<div style='font-family:sans-serif; padding:20px; background:#d1fae5; color:#065f46; border-radius:8px;'><strong>✅ Reporte Generado y Enviado Correctamente.</strong><br><a href='$url_archivo_final' target='_blank' style='color:#059669; text-decoration:underline;'>Ver Archivo Generado Aquí</a></div>";
} catch (Exception $e) { echo "Error Mailer: {$mail->ErrorInfo}"; }
?>