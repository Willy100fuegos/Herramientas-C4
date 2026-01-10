<?php
/**
 * PROXY UNIFICADO GPS - GORATRACK
 * Gestiona múltiples cuentas (Centurion, UIPSA, ETF) en un solo punto de entrada.
 */

// --- 1. CONFIGURACIÓN DE CUENTAS Y CLAVES ---
// Mapeo de identificadores de cuenta a sus respectivas claves API.
$ACCOUNTS = [
    'centurion' => 'AQUI_VA_TU_API', // Clave original de gps_proxy.php
    'uipsa'     => 'AQUI_VA_TU_API', // Clave original de gps_proxy_uipsa.php
    'etf'       => 'AQUI_VA_TU_API'  // Clave original de gps_proxy_etf.php
];

define('GORATRACK_BASE_URL', 'https://TU.MAIL.mx/api/api.php');
define('MIN_STOP_DURATION', 1); 

// --- 2. SEGURIDAD Y HEADERS ---
header('Content-Type: application/json');
// header('Access-Control-Allow-Origin: *'); // Descomentar si es necesario

// --- 3. OBTENCIÓN Y VALIDACIÓN DE PARÁMETROS ---
$command_action = isset($_GET['command_action']) ? trim($_GET['command_action']) : null;
$account_id     = isset($_GET['account']) ? trim($_GET['account']) : null;

if (!$command_action) {
    echo json_encode(['error' => true, 'message' => 'Parámetro "command_action" es requerido.']);
    exit;
}

if (!$account_id || !array_key_exists($account_id, $ACCOUNTS)) {
    echo json_encode(['error' => true, 'message' => 'Cuenta no válida o no especificada.']);
    exit;
}

// Seleccionar la API KEY correcta basada en la cuenta solicitada
$CURRENT_API_KEY = $ACCOUNTS[$account_id];

// --- 4. CONSTRUCCIÓN DEL COMANDO ---
$api_command_param = ''; 
$error_message = null;

switch ($command_action) {
    case 'USER_GET_OBJECTS':
        $api_command_param = 'USER_GET_OBJECTS';
        break;

    case 'OBJECT_GET_ROUTE':
        $imei = isset($_GET['imei']) ? trim($_GET['imei']) : null;
        $start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : null;
        $end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : null;

        if (!$imei || !$start_date || !$end_date) {
            $error_message = 'Faltan parámetros para OBJECT_GET_ROUTE.';
        } else {
            $api_command_param = sprintf(
                'OBJECT_GET_ROUTE,%s,%s,%s,%d',
                rawurlencode($imei),
                rawurlencode($start_date),
                rawurlencode($end_date),
                MIN_STOP_DURATION
            );
        }
        break;

    default:
        $error_message = 'Comando no reconocido: ' . htmlspecialchars($command_action);
        break;
}

if ($error_message) {
    echo json_encode(['error' => true, 'message' => $error_message]);
    exit;
}

// --- 5. EJECUCIÓN CURL ---
$api_url = GORATRACK_BASE_URL . '?api=user&key=' . $CURRENT_API_KEY . '&cmd=' . $api_command_param;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response_body = curl_exec($ch);
$curl_error_no = curl_errno($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- 6. RESPUESTA ---
if ($curl_error_no) {
    echo json_encode(['error' => true, 'message' => 'Error cURL: ' . $curl_error_no]);
} elseif ($http_code != 200) {
    echo json_encode(['error' => true, 'message' => 'HTTP Error: ' . $http_code]);
} else {
    $decoded = json_decode($response_body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (isset($decoded['status']) && $decoded['status'] === 0) {
             echo json_encode(['error' => true, 'message' => 'API Error: ' . ($decoded['message'] ?? 'Desconocido')]);
        } else {
             echo json_encode($decoded);
        }
    } else {
        echo json_encode(['error' => true, 'message' => 'JSON Inválido recibido del proveedor.']);
    }
}
exit;
?>