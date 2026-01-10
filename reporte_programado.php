<?php
// ======================================================================================
// SCRIPT DE REPORTE PROGRAMADO (VERSIÓN 2.4 - PRODUCCIÓN)
// ======================================================================================
// - Tarea 1: Determinar el rango de fechas (según el día o modo de prueba).
// - Tarea 2: Autenticarse en Firebase y Consultar la BD (vía API REST).
// - Tarea 3: Procesar datos y generar URLs de gráficas (QuickChart.io).
// - Tarea 4: Construir y Enviar el reporte por correo (PHPMailer).
//
// - MEJORAS V2.4 (PRODUCCIÓN):
//   1. MODO PRODUCCIÓN: 'display_errors' se establece en 0.
//   2. LISTA DE CORREO: Se añade una variable clara ($destinatarios_produccion)
//      para los correos del cliente.
//   3. LÓGICA DE ENVÍO: El script envía a la lista de producción SOLO
//      si NO está en modo de prueba.
// ======================================================================================

// --- 0. INCLUIR PHPMailer ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Retrocedemos un nivel (de /api/ a /) para encontrar phpmailer
require __DIR__ . '/../phpmailer/Exception.php'; 
require __DIR__ . '/../phpmailer/PHPMailer.php';
require __DIR__ . '/../phpmailer/SMTP.php';

// --- 1. CONFIGURACIÓN INICIAL ---
error_reporting(E_ALL);
ini_set('display_errors', 0); // 0 PARA PRODUCCIÓN
ini_set('log_errors', 1);
set_time_limit(300); // 5 minutos de tiempo de ejecución
date_default_timezone_set('America/Mexico_City');
setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'es');

// --- Configuración de Firebase API REST ---
$projectId = 'dashboard-alertas-gps';
$apiKey = 'AQUI_VA_TU_API'; // Tu API Key
$collectionId = "alertas_gps"; // El ID de la colección (para el grupo)

// --- Configuración del Correo ---
$from_email = "TU@MAIL.mx"; 
$logo_url = "https://imgfz.com/i/y4CNaE5.png"; // Logo con HTTPS

// --- MEJORA V2.4: Listas de Destinatarios ---
$report_recipient_test = 'AQUI_VA_TU_CORREO'; // Destinatario para pruebas

// ========================================================================
// ¡IMPORTANTE! EDITA LA SIGUIENTE LÍNEA CON LOS CORREOS DEL CLIENTE
// (Separados por coma)
// ========================================================================
$destinatarios_produccion = 'AQUI VAN LOS CORREOS DE LOS DESTINATARIOS SEPARADOS POR COMA';
// ========================================================================

// --- Configuración de WhatsApp ---
$whatsapp_number = 'AQUI_VA_TU_WHATSAPP'; 
$whatsapp_message = 'C4, una consulta sobre el reporte de alertas.'; 
$whatsapp_link = "https://wa.me/{$whatsapp_number}?text=" . urlencode($whatsapp_message);

// --- 2. LÓGICA DE FECHAS ---

$esModoPrueba = isset($_GET['test']) && $_GET['test'] === 'true';
$hoy = new DateTime();
$hoy->setTimezone(new DateTimeZone('America/Mexico_City'));
$diaDeLaSemana = (int)$hoy->format('N'); // 1 (Lunes) a 7 (Domingo)

$fechaInicioReporte = null;
$fechaFinReporte = null;
$tituloRango = "";

if ($esModoPrueba) {
    echo "MODO DE PRUEBA (V2.4) - SIMULANDO REPORTE DE LUNES.<br>";
    
    $fechaInicioReporte = new DateTime('last friday', new DateTimeZone('America/Mexico_City'));
    $fechaInicioReporte->setTime(0, 0, 0);
    $fechaFinReporte = clone $fechaInicioReporte;
    $fechaFinReporte->modify('+2 days');
    $fechaFinReporte->setTime(23, 59, 59);
    $tituloRango = "Resumen de Fin de Semana";
    
} else {
    // Lógica de Producción (para el Cron Job)
    switch ($diaDeLaSemana) {
        case 1: // Lunes (Reporta Vi, Sa, Do)
            $fechaInicioReporte = new DateTime('last friday', new DateTimeZone('America/Mexico_City'));
            $fechaInicioReporte->setTime(0, 0, 0);
            $fechaFinReporte = new DateTime('last sunday', new DateTimeZone('America/Mexico_City'));
            $fechaFinReporte->setTime(23, 59, 59);
            $tituloRango = "Resumen de Fin de Semana";
            break;
        case 3: // Miércoles (Reporta Lu, Ma)
            $fechaInicioReporte = new DateTime('monday this week', new DateTimeZone('America/Mexico_City'));
            $fechaInicioReporte->setTime(0, 0, 0);
            $fechaFinReporte = new DateTime('tuesday this week', new DateTimeZone('America/Mexico_City'));
            $fechaFinReporte->setTime(23, 59, 59);
            $tituloRango = "Resumen de Inicio de Semana";
            break;
        case 5: // Viernes (Reporta Mi, Ju)
            $fechaInicioReporte = new DateTime('wednesday this week', new DateTimeZone('America/Mexico_City'));
            $fechaInicioReporte->setTime(0, 0, 0);
            $fechaFinReporte = new DateTime('thursday this week', new DateTimeZone('America/Mexico_City'));
            $fechaFinReporte->setTime(23, 59, 59);
            $tituloRango = "Resumen de Media Semana";
            break;
        default:
            // Si no es un día de reporte, el Cron Job se ejecuta pero el script no hace nada.
            error_log("Reporte Programado: Se ejecutó en un día no válido (Día: {$diaDeLaSemana}). Saliendo.");
            exit;
    }
}

// --- Título detallado con fechas y horas ---
$inicioStr = strftime('%A %d de %B %H:%M hrs', $fechaInicioReporte->getTimestamp());
$finStr = strftime('%A %d de %B %H:%M hrs', $fechaFinReporte->getTimestamp());
$inicioStr = ucfirst($inicioStr);
$finStr = ucfirst($finStr);
$tituloDetallado = "{$tituloRango}<br><span style='font-size: 16px; color: #555; font-weight: normal;'>({$inicioStr} a {$finStr})</span>";


// Convertir a Timestamps UTC ISO 8601 para la API de Firestore
$fechaInicioUTC_obj = clone $fechaInicioReporte;
$fechaFinUTC_obj = clone $fechaFinReporte;
$fechaInicioUTC = $fechaInicioUTC_obj->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$fechaFinUTC = $fechaFinUTC_obj->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

if ($esModoPrueba) {
    echo "Generando reporte para el rango: {$fechaInicioReporte->format('Y-m-d H:i:s')} a {$fechaFinReporte->format('Y-m-d H:i:s')} (Zona Horaria America/Mexico_City)<br>";
    echo "Rango UTC para Firestore: {$fechaInicioUTC} a {$fechaFinUTC} <br>";
    echo "Título detallado: {$tituloDetallado} <br>";
}

// --- 3. TAREA 2: AUTENTICARSE Y CONSULTAR FIREBASE (API REST) ---

function runCurl($url, $method = 'GET', $postData = null, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
    if ($postData) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $headers[] = 'Content-Length: ' . strlen($postData);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        return json_decode($response, true);
    } else {
        error_log("Reporte Programado Error cURL: HTTP {$http_code}, Error: {$curl_error}, URL: {$url}, Respuesta: {$response}");
        if (isset($_GET['test']) && $_GET['test'] === 'true') {
            echo "<hr><b>Error de cURL:</b><br>";
            echo "<b>URL:</b> {$url}<br>";
            echo "<b>HTTP Code:</b> {$http_code}<br>";
            echo "<b>Error:</b> {$curl_error}<br>";
            echo "<b>Respuesta:</b> {$response}<hr>";
        }
        return null;
    }
}

$authUrl = "https://identitytoolkit.googleapis.com/v1/accounts:signUp?key={$apiKey}";
$authData = json_encode(['returnSecureToken' => true]);
$authHeaders = ['Content-Type: application/json'];
$authResponse = runCurl($authUrl, 'POST', $authData, $authHeaders);

if (!$authResponse || !isset($authResponse['idToken'])) {
    if ($esModoPrueba) echo "Error fatal: No se pudo autenticar anónimamente con Firebase.";
    error_log("Reporte Programado: Falla de autenticación anónima con Firebase.");
    exit;
}
$idToken = $authResponse['idToken'];
if ($esModoPrueba) echo "Autenticación anónima exitosa.<br>";

$queryParentPath = "projects/{$projectId}/databases/(default)/documents"; 
$queryUrl = "https://firestore.googleapis.com/v1/{$queryParentPath}:runQuery"; 
$queryHeaders = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $idToken
];
$queryPayload = json_encode([
    'structuredQuery' => [
        'from' => [[
            'collectionId' => $collectionId,
            'allDescendants' => true 
        ]],
        'where' => [
            'compositeFilter' => [
                'op' => 'AND',
                'filters' => [
                    [
                        'fieldFilter' => [
                            'field' => ['fieldPath' => 'estado_gestion'],
                            'op' => 'EQUAL',
                            'value' => ['stringValue' => 'GESTIONADO']
                        ]
                    ],
                    [
                        'fieldFilter' => [
                            'field' => ['fieldPath' => 'dt_gestion'],
                            'op' => 'GREATER_THAN_OR_EQUAL',
                            'value' => ['timestampValue' => $fechaInicioUTC]
                        ]
                    ],
                    [
                        'fieldFilter' => [
                            'field' => ['fieldPath' => 'dt_gestion'],
                            'op' => 'LESS_THAN_OR_EQUAL',
                            'value' => ['timestampValue' => $fechaFinUTC]
                        ]
                    ]
                ]
            ]
        ],
        'limit' => 1000 
    ]
]);

if ($esModoPrueba) echo "Ejecutando Consulta de Grupo de Colección...<br>";
$queryResponse = runCurl($queryUrl, 'POST', $queryPayload, $queryHeaders);

if ($queryResponse === null) {
    if ($esModoPrueba) echo "Error fatal: La consulta a Firestore falló.";
    error_log("Reporte Programado: Falla en runQuery (Consulta de Grupo) a Firestore.");
    exit;
}

// --- 4. TAREA 3: PROCESAR DATOS Y GENERAR GRÁFICAS ---
if ($esModoPrueba) echo "Procesando datos...<br>";
$alertas = [];

if (empty($queryResponse)) {
     if ($esModoPrueba) echo "Respuesta vacía de Firestore (0 documentos encontrados).<br>";
} else {
    foreach ($queryResponse as $docWrapper) {
        if (isset($docWrapper['document']['fields'])) {
            $alertas[] = $docWrapper['document']['fields'];
        }
    }
}

$totalAlertas = count($alertas);
if ($totalAlertas == 0) {
    if ($esModoPrueba) echo "No se encontraron alertas gestionadas en el rango de fechas. No se enviará el reporte (a menos que sea prueba).";
    
    // Si NO es modo prueba Y no hay alertas, no enviamos nada.
    if (!$esModoPrueba) {
        error_log("Reporte Programado: 0 alertas encontradas para {$tituloRango}. No se envió correo.");
        exit;
    }
}
if ($esModoPrueba) echo "Se encontraron {$totalAlertas} alertas gestionadas.<br>";

$countsByType = [];
$countsByReason = [];
$countsByGeofence = [];
$countsByUnit = [];

foreach ($alertas as $alert) {
    $getString = function($field) use ($alert) {
        if (isset($alert[$field]['stringValue'])) return $alert[$field]['stringValue'];
        if (isset($alert[$field]['nullValue'])) return 'N/A';
        return 'N/A';
    };

    $tipo = $getString('tipo_alerta_normalizado');
    $motivo = $getString('motivo_seleccionado');
    $geocerca = $getString('geocerca');
    $unidad = $getString('unidad');
    
    if ($tipo != 'N/A' && $tipo != '') $countsByType[$tipo] = ($countsByType[$tipo] ?? 0) + 1;
    if ($motivo != 'N/A' && $motivo != '') $countsByReason[$motivo] = ($countsByReason[$motivo] ?? 0) + 1;
    if ($geocerca != 'N/A' && $geocerca != '') $countsByGeofence[$geocerca] = ($countsByGeofence[$geocerca] ?? 0) + 1;
    if ($unidad != 'N/A' && $unidad != '') $countsByUnit[$unidad] = ($countsByUnit[$unidad] ?? 0) + 1;
}

arsort($countsByGeofence);
arsort($countsByUnit);
arsort($countsByReason);
$topGeocercas = array_slice($countsByGeofence, 0, 5, true);
$topUnidades = array_slice($countsByUnit, 0, 5, true);
$allReasons = $countsByReason; 

function formatChartLabels($data, $lineLength = 25) {
    $labels = [];
    if (!empty($data)) {
        foreach (array_keys($data) as $label) {
            $wrappedLabel = wordwrap($label, $lineLength, "\n", true);
            $labels[] = explode("\n", $wrappedLabel);
        }
    } else {
        $labels = ['Sin Datos'];
    }
    return $labels;
}

function getQuickChartUrl($chartConfig) {
    $jsonConfig = json_encode($chartConfig);
    return 'https://quickchart.io/chart?width=500&height=300&c=' . urlencode($jsonConfig);
}

// Gráfica 1: Por Tipo (Pie)
$chartTiposUrl = getQuickChartUrl([
    'type' => 'pie',
    'data' => [
        'labels' => !empty($countsByType) ? array_keys($countsByType) : ['Sin Datos'],
        'datasets' => [[ 'data' => !empty($countsByType) ? array_values($countsByType) : [1] ]]
    ],
    'options' => ['title' => ['display' => true, 'text' => 'Alertas por Tipo']]
]);

// Gráfica 2: Por Motivo (Barra Horizontal)
$motivoLabels = formatChartLabels($allReasons, 25); 
$chartMotivosUrl = getQuickChartUrl([
    'type' => 'horizontalBar',
    'data' => [
        'labels' => $motivoLabels, 
        'datasets' => [[ 'label' => 'Gestiones', 'data' => !empty($allReasons) ? array_values($allReasons) : [0] ]]
    ],
    'options' => ['title' => ['display' => true, 'text' => 'Motivos de Gestión']]
]);

// Gráfica 3: Top Unidades (Barra Horizontal)
$unidadLabels = formatChartLabels($topUnidades, 25); 
$chartUnidadesUrl = getQuickChartUrl([
    'type' => 'horizontalBar',
    'data' => [
        'labels' => $unidadLabels, 
        'datasets' => [[ 'label' => 'Eventos', 'data' => !empty($topUnidades) ? array_values($topUnidades) : [0] ]]
    ],
    'options' => ['title' => ['display' => true, 'text' => 'Top 5 Unidades con Eventos']]
]);

// Gráfica 4: Top Geocercas (Barra Horizontal)
$geocercaLabels = formatChartLabels($topGeocercas, 20); 
$chartGeocercasUrl = getQuickChartUrl([
    'type' => 'horizontalBar',
    'data' => [
        'labels' => $geocercaLabels, 
        'datasets' => [[ 'label' => 'Eventos', 'data' => !empty($topGeocercas) ? array_values($topGeocercas) : [0] ]]
    ],
    'options' => ['title' => ['display' => true, 'text' => 'Top 5 Geocercas con Eventos']]
]);

if ($esModoPrueba) echo "Gráficas generadas.<br>";

// --- 5. TAREA 4: CONSTRUIR Y ENVIAR EL CORREO ---
$mail = new PHPMailer(true);
$html_body = "
<!DOCTYPE html>
<html lang='es'>
<head><meta charset='UTF-8'></head>
<body style='font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px;'>
    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color: #f4f4f4;'>
        <tr><td align='center'>
            <table width='700' cellpadding='0' cellspacing='0' border='0' style='background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); overflow: hidden;'>
                <!-- Encabezado -->
                <tr><td align='center' style='background-color: #004d99; padding: 20px;'>
                    <img src='{$logo_url}' alt='GORATRACK SEGURIDAD' style='max-width: 200px; height: auto; display: block;'>
                    <h1 style='color: #ffffff; margin: 10px 0 0 0; font-size: 24px;'>Reporte Ejecutivo de Alertas</h1>
                </td></tr>
                <!-- Contenido -->
                <tr><td style='padding: 30px;'>
                    <h2 style='color: #333; margin: 0; line-height: 1.4;'>{$tituloDetallado}</h2>
                    
                    <p style='font-size: 16px; color: #555; margin-top: 20px;'>
                        Se reporta un total de <strong>{$totalAlertas} alertas gestionadas</strong> en el periodo.
                    </p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                    
                    <!-- Gráficas -->
                    " . ($totalAlertas > 0 ? "
                    <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                        <tr>
                            <td align='center' style='padding: 10px;'><img src='{$chartTiposUrl}' alt='Alertas por Tipo'></td>
                        </tr>
                        <tr>
                            <td align='center' style='padding: 10px;'><img src='{$chartMotivosUrl}' alt='Motivos de Gestión'></td>
                        </tr>
                        <tr>
                            <td align='center' style='padding: 10px;'><img src='{$chartUnidadesUrl}' alt='Top Unidades'></td>
                        </tr>
                        <tr>
                            <td align='center' style='padding: 10px;'><img src='{$chartGeocercasUrl}' alt='Top Geocercas'></td>
                        </tr>
                    </table>
                    " : "<p style='font-size: 16px; color: #555; text-align: center;'>No se generaron gráficas ya que no hubo datos en este periodo.</p>") . "
                </td></tr>
                
                <!-- Pie de Página (MEJORA V2.1) -->
                <tr><td align='center' style='background-color: #eeeeee; padding: 20px; border-top: 1px solid #dddddd;'>
                    <p style='margin: 0; font-size: 14px; color: #333; font-weight: bold;'>
                        Centro de Monitoreo Goratrack
                    </p>
                    <p style='margin: 10px 0; font-size: 14px; color: #555;'>
                        &#128222; (921) 2520294
                    </p>
                    <a href='{$whatsapp_link}' target='_blank' style='color: #004d99; font-size: 14px; text-decoration: underline;'>
                        Click para iniciar chat con C4
                    </a>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
";

try {
    $mail->SMTPDebug  = 0; // 0 para producción
    $mail->isSMTP();
    $mail->Host       = 'TU_SERVIDOR_DE_CORREO';    
    $mail->SMTPAuth   = true;                     
    $mail->Username   = 'TU@MAIL.mx'; 
    $mail->Password   = 'TU CLAVE DE CORREO';     
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
    $mail->Port       = 465;                      
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom($from_email, 'Reporte de Alertas GPS');
    
    // --- LÓGICA DE ENVÍO V2.4 ---
    if ($esModoPrueba) {
        $mail->addAddress($report_recipient_test); // Solo a William para la prueba
    } else {
        // Modo Producción: Añadir la lista de clientes
        $recipients_array = explode(',', $destinatarios_produccion);
        foreach ($recipients_array as $recipient) {
            $mail->addAddress(trim($recipient));
        }
    }
    // --- FIN LÓGICA DE ENVÍO ---
    
    $mail->isHTML(true);
    $mail->Subject = "Reporte de Alertas Gestionadas - {$tituloRango}";
    $mail->Body    = $html_body;
    $mail->AltBody = "Reporte de Alertas Gestionadas. {$tituloRango}. Total: {$totalAlertas}."; 
    $mail->send();
    
    if ($esModoPrueba) echo "¡Éxito! Reporte de prueba (V2.4) enviado a {$report_recipient_test}.";
    error_log("Reporte Programado: ¡Éxito! Reporte '{$tituloRango}' enviado. {$totalAlertas} alertas.");


} catch (Exception $e) {
    if ($esModoPrueba) echo "Error fatal: No se pudo enviar el correo de reporte. Mailer Error: {$e->getMessage()}";
    error_log("Reporte Programado: Falla en PHPMailer: " . $e->getMessage());
}

?>