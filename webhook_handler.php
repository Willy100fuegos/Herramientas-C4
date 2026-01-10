<?php
// ======================================================================================
// SCRIPT MANEJADOR DE WEBHOOK (VERSIÓN 12.1 - MODO SILENCIOSO)
// ======================================================================================
// - Tarea 1 (Correos) está DESACTIVADA.
// - Tarea 2 (Escritura en Firebase) SIGUE ACTIVA.
// ======================================================================================

// --- 0. CARGA DE DEPENDENCIAS (PHPMailer) ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Retrocedemos un nivel (de /api/ a /) para encontrar phpmailer
require __DIR__ . '/../phpmailer/Exception.php'; 
require __DIR__ . '/../phpmailer/PHPMailer.php';
require __DIR__ . '/../phpmailer/SMTP.php';

// --- 1. CONFIGURACIÓN DEL EMAIL, LOGO Y WHATSAPP ---
$default_recipients = "MAIL QUE RECIBE POR DEFECTO LOS REPORTES"; 
$recipient_map = [
    'BRASKEM DETENIDO 10 MIN' => 'AQUI VAN LOS CORREOS DE LOS DESTINATARIOS SEPARADOS POR COMA',
    'SALIDA GEOCERCA UNIDADES BI INTRAMUROS' => 'AQUI VAN LOS CORREOS DE LOS DESTINATARIOS SEPARADOS POR COMA',
    'DESVIO DE RUTA BI EXTRAMUROS' => 'AQUI VAN LOS CORREOS DE LOS DESTINATARIOS SEPARADOS POR COMA',
];
$from_email = "MAIL REMITENTE"; 
$logo_url = "https://TU-WEB.mx/img/logo.png"; 
$whatsapp_number = 'EL NUMERO DE WHATSAPP'; 
$whatsapp_message = 'C4, una consulta'; 
$whatsapp_link = "https://wa.me/{$whatsapp_number}?text=" . urlencode($whatsapp_message);
date_default_timezone_set('America/Mexico_City');

// --- 2. RECEPCIÓN Y EXTRACCIÓN DE DATOS MEDIANTE HTTP GET ---
$data = $_GET;
$unit_name = $data['name'] ?? 'UNIDAD: N/A';
$imei = $data['imei'] ?? 'N/A';
$event_description_raw = $data['desc'] ?? ''; 
$zone_raw = $data['zone_name'] ?? 'N/A'; // Geocerca (puede ser N/A)
$latitude = $data['lat'] ?? 18.1408; 
$longitude = $data['lng'] ?? -94.4691;
$speed = $data['speed'] ?? 0;
$dt_server_raw = $data['dt_server'] ?? date('Y-m-d H:i:s'); 

// --- 3. PROCESAMIENTO DE FECHA Y LÓGICA NARRATIVA ---
$dt_obj = null; 
try {
    $dt_obj = new DateTime($dt_server_raw, new DateTimeZone('GMT'));
    $dt_obj->setTimezone(new DateTimeZone('America/Mexico_City'));
    $timestamp = $dt_obj->getTimestamp();
    setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'es');
    
    // FORMATO DE FECHA CORREGIDO (Sin strftime)
    $time_formatted = $dt_obj->format('h:i a'); 
    $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $mes_actual = $meses[(int)$dt_obj->format('n') - 1];
    $date_formatted = $dt_obj->format('d') . ' de ' . $mes_actual;
    
} catch (Exception $e) {
    $time_formatted = "hora no disponible";
    $date_formatted = "fecha no disponible";
    error_log("Error al procesar la fecha: " . $e->getMessage());
    $dt_obj = new DateTime(); // Usar 'now' como fallback
}

// Inicialización de variables
$event_type_display = $event_description_raw; 
$box_color = "#cc0000"; 
$box_bg = "#fff0f0";    
$box_border_color = "#ffcc00";
$subject = "🚨 ALERTA GPS: {$event_description_raw}"; 
$narrative_message = ""; 
$current_recipients = $default_recipients;
$desc_key = strtoupper(trim($event_description_raw));

// --- 3.1 MEJORA V12.0: Lógica de Normalización y Geocercas ---
$tipo_alerta_normalizado = "OTRA ALERTA"; // Default para gráficas
$zone = $zone_raw; // Por defecto usamos la geocerca que llega

if (strpos($desc_key, 'DETENIDO 10 MIN') !== false) {
    $tipo_alerta_normalizado = "DETENIDO 10 MIN";
    // MEJORA: Si la geocerca es N/A, intentamos extraerla de la descripción
    if ($zone == 'N/A') {
        // Asume que la descripción es "NOMBRE GEOCERCA DETENIDO 10 MIN ?"
        $partes = explode(' ', $desc_key);
        if (count($partes) > 3) { // Asume al menos "A B DETENIDO 10"
            $palabrasGeocerca = [];
            foreach ($partes as $palabra) {
                if ($palabra == 'DETENIDO') break;
                $palabrasGeocerca[] = $palabra;
            }
            if (!empty($palabrasGeocerca)) {
                $zone = implode(' ', $palabrasGeocerca); // Ej: "BRASKEM EXTRAMUROS"
            }
        }
    }

} elseif (strpos($desc_key, 'DESVIO DE RUTA') !== false) {
    $tipo_alerta_normalizado = "DESVÍO DE RUTA";
    // MEJORA: Para desvío, la "geocerca" es en realidad el nombre de la RUTA
    // La plataforma envía esto en el campo 'desc' (ej: ... (NEW Ruta ...))
    if (preg_match('/\(+(.*?)\)+/', $event_description_raw, $matches)) {
        $zone = $matches[1]; // Captura "NEW Ruta disuasivo y acompañamiento extramuros"
    } else {
        $zone = "Ruta no especificada";
    }

} elseif (strpos($desc_key, 'SALIDA GEOCERCA') !== false) {
    $tipo_alerta_normalizado = "SALIDA DE GEOCERCA";
    // Ya tenemos el $zone_raw correcto
}

// --- 3.2 LÓGICA DE CLASIFICACIÓN (Esta sección ya no es necesaria para el correo) ---
// ... (Se omite la creación de $narrative_message, $subject, etc., ya que no se usarán) ...

// --- 4. CONSTRUCCIÓN DEL CONTENIDO HTML DEL CORREO ---
// ... (Toda la variable $html_content se omite) ...


// --- 5. TAREA 1: ENVÍO DEL CORREO (DESACTIVADA) ---

// Forzamos a 'true' para que el script responda 200 OK a la plataforma GPS,
// pero no se envía ningún correo.
$mail_sent = true; 

/* <-- INICIO DE BLOQUE COMENTADO
try {
    $mail = new PHPMailer(true); 
    $mail->SMTPDebug  = 0; 
    $mail->isSMTP();
    $mail->Host       = 'TU SERVIDOR DE CORREO';    
    $mail->SMTPAuth   = true;                     
    $mail->Username   = 'CORREO REMITENTE'; 
    $mail->Password   = 'CONTRASEÑA DEL CORREO';     
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
    $mail->Port       = 465;                      
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom($from_email, 'Alerta Monitoreo GPS');
    $recipients_array = explode(',', $current_recipients);
    foreach ($recipients_array as $recipient) {
        $mail->addAddress(trim($recipient));
    }
    $mail->isHTML(true);
    $mail->Subject = $subject; // $subject se define en la lógica de clasificación
    $mail->Body    = $html_content; // $html_content se define en el bloque 4
    $mail->AltBody = "ALERTA GPS - Unidad: {$unit_name}. Evento: {$event_description_raw}. Ubicación: {$latitude},{$longitude}"; 
    $mail->send();
    $mail_sent = true;
} catch (Exception $e) {
    error_log("Fallo el envío por SMTP. Mailer Error: {$e->getMessage()}");
    $mail_sent = false;
}
*/ // <-- FIN DE BLOQUE COMENTADO

// =========================================================================
// INICIO DE BLOQUE API REST (VERSIÓN 12.0)
// =========================================================================
// Esta TAREA 2 (Escritura en Firebase) SIGUE 100% ACTIVA.
// Esto es lo que alimentará nuestro reporte.

try {
    // 1. Configuración de la API REST
    $projectId = 'dashboard-alertas-gps';
    $apiKey = 'AQUI VA TU API DE FIREBASE'; // La clave que me diste
    $collectionPath = "artifacts/{$projectId}/public/data/alertas_gps";
    
    // 2. Construir la URL del Endpoint
    $restApiUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/{$collectionPath}?key={$apiKey}";

    // 3. Preparar el payload con el formato de Firestore
    // Convertir la fecha a formato ISO 8601 UTC (requerido por la API)
    $dt_obj->setTimezone(new DateTimeZone('UTC'));
    $utcTimestamp = $dt_obj->format('Y-m-d\TH:i:s') . '.' . substr($dt_obj->format('u'), 0, 6) . 'Z';

    $firestorePayload = [
        'fields' => [
            'dt_evento'           => ['timestampValue' => $utcTimestamp],
            'unidad'              => ['stringValue' => $unit_name],
            'tipo_alerta'         => ['stringValue' => $event_description_raw], // Texto completo original
            'latitud'             => ['doubleValue' => (float)$latitude],
            'longitud'            => ['doubleValue' => (float)$longitude],
            'velocidad'           => ['integerValue' => (int)$speed],
            'geocerca'            => ['stringValue' => $zone], // Geocerca/Ruta PROCESADA
            
            // --- NUEVOS CAMPOS V12.0 ---
            'tipo_alerta_normalizado' => ['stringValue' => $tipo_alerta_normalizado], // Campo limpio para gráficas
            'motivo_comentario'   => ['nullValue' => null], // Campo para el historial
            
            // --- Campos de Gestión ---
            'estado_gestion'      => ['stringValue' => 'PENDIENTE'],
            'motivo_seleccionado' => ['nullValue' => null],
            'monitorista_uid'     => ['nullValue' => null],
            'dt_gestion'          => ['nullValue' => null]
        ]
    ];
    $jsonData = json_encode($firestorePayload);

    // 4. Ejecutar la llamada cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $restApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST"); // Para crear un nuevo documento
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($jsonData)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000); // 5 segundos de timeout

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // 5. Registrar el resultado en el log
    if ($http_code == 200 || $http_code == 201) {
        // HTTP 200 (OK) o 201 (Created) significan éxito
        error_log("Éxito (API REST): Alerta guardada en Firestore.");
    } else {
        error_log("Error (API REST) al escribir en Firestore. Código: {$http_code}. Respuesta: {$response}. Error cURL: {$curl_error}");
    }

} catch (Exception $e) {
    // Si falla la escritura, solo lo registramos, no detenemos el script
    error_log("Error (API REST) fatal en bloque cURL: " . $e->getMessage());
}
// =========================================================================
// FIN DEL BLOQUE AÑADIDO
// =========================================================================


// --- 6. RESPUESTA AL WEBHOOK (IMPORTANTE) ---
if ($mail_sent) { // $mail_sent se forzó a 'true' al inicio
    http_response_code(200); 
    echo "Webhook procesado (Correos Desactivados). Alerta guardada en DB.";
} else {
    // Este caso ya no debería ocurrir, pero se deja por seguridad
    http_response_code(500); 
    echo "Error: Fallo inesperado. Consulte los logs del servidor.";
    error_log("Fallo el envío de correo para Webhook: {$unit_name} en {$dt_server_raw}.");
}

?>