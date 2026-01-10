<?php
/**
 * BACKEND V9 - ANTI-DUPLICADOS (FINAL)
 * Filtra unidades repetidas en múltiples cuentas y prioriza la que tiene mejor señal.
 */

// 1. Configuración
ini_set('display_errors', 0); 
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
date_default_timezone_set('America/Mexico_City');

// 2. Credenciales
$ACCOUNTS = [
    'centurion' => 'TU API',
    'uipsa'     => 'TU API',
    'etf'       => 'TU API'
];

define('GORATRACK_API', 'https://TU-WEB.COM/api/api.php');
define('DATA_DIR', __DIR__ . '/data/');
define('DEBUG_FILE', DATA_DIR . 'debug_gps.log'); 

if (!file_exists(DATA_DIR)) @mkdir(DATA_DIR, 0777, true);

// Función de Log
function logDebug($msg) {
    $time = date('[Y-m-d H:i:s] ');
    if (file_exists(DEBUG_FILE) && filesize(DEBUG_FILE) > 500000) {
        file_put_contents(DEBUG_FILE, $time . "--- Log Reiniciado ---\n");
    }
    file_put_contents(DEBUG_FILE, $time . $msg . PHP_EOL, FILE_APPEND);
}

$action = $_GET['action'] ?? '';

// --- ACCIÓN 1: OBTENER LISTA ---
if ($action === 'get_all_units') {
    $all = [];
    foreach ($ACCOUNTS as $acc => $key) {
        $url = GORATRACK_API . "?api=user&key={$key}&cmd=USER_GET_OBJECTS";
        $res = json_decode(fetchUrl($url), true);
        
        if (is_array($res)) {
            foreach ($res as $u) { 
                if (isset($u['imei'])) {
                    $u['account_source'] = $acc; 
                    $u['imei'] = (string)$u['imei']; 
                    $all[] = $u; 
                }
            }
        }
    }
    echo json_encode($all); exit;
}

// --- ACCIÓN 2: CREAR ENLACE ---
if ($action === 'create_link') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (empty($in['imeis'])) { echo json_encode(['error'=>'Sin datos']); exit; }

    $token = bin2hex(random_bytes(16));
    $units = [];
    foreach ($in['imeis'] as $u) {
        $units[] = [
            'imei' => (string)$u['imei'],
            'account' => $u['account'],
            'name' => !empty($u['name']) ? $u['name'] : "Unidad {$u['imei']}" 
        ];
    }
    
    $payload = [
        'created' => date('Y-m-d H:i:s'),
        'expiration' => time() + ($in['expiration_hours'] * 3600),
        'units' => $units
    ];
    
    if (file_put_contents(DATA_DIR . "$token.json", json_encode($payload))) {
        logDebug("Enlace creado: $token (" . count($units) . " unidades)");
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $link = "$protocol://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/mirror.php?token=$token";
        echo json_encode(['success' => true, 'link' => $link]);
    } else {
        echo json_encode(['error' => 'Error de escritura']);
    }
    exit;
}

// --- ACCIÓN 3: OBTENER POSICIONES (ANTI-DUPLICADOS) ---
if ($action === 'get_positions') {
    $token = preg_replace('/[^a-z0-9]/', '', $_GET['token'] ?? '');
    $file = DATA_DIR . "$token.json";
    
    if (!file_exists($file)) { echo json_encode(['error' => 'Enlace no encontrado']); exit; }
    
    $data = json_decode(file_get_contents($file), true);
    if (time() > $data['expiration']) { echo json_encode(['error' => 'expired']); exit; }

    $mapNames = []; 
    $mapAcc = [];
    $allowedImeis = []; 

    foreach ($data['units'] as $u) {
        $sid = (string)$u['imei'];
        $mapNames[$sid] = $u['name'];
        // Agrupamos por cuenta para pedir en bloque
        $mapAcc[$u['account']][] = $sid;
        // Lista plana para validar
        if (!in_array($sid, $allowedImeis)) $allowedImeis[] = $sid;
    }

    // Usamos un array asociativo por IMEI para evitar duplicados
    $uniquePositions = [];

    foreach ($mapAcc as $acc => $imeis) {
        if (!isset($ACCOUNTS[$acc])) continue;
        
        $imeiString = implode(';', $imeis);
        $cmdEncoded = urlencode("OBJECT_GET_LOCATIONS," . $imeiString);
        $url = GORATRACK_API . "?api=user&key={$ACCOUNTS[$acc]}&cmd=" . $cmdEncoded;
        
        $rawResponse = fetchUrl($url);
        $res = json_decode($rawResponse, true);

        if (!is_array($res)) {
            logDebug("ERROR API ($acc): Respuesta inválida.");
            continue;
        }

        foreach ($res as $key => $r) {
            $sid = null;
            if (isset($r['imei'])) $sid = (string)$r['imei'];
            else $sid = (string)$key;

            // Filtro de seguridad (solo lo solicitado)
            if (empty($sid) || !in_array($sid, $allowedImeis)) continue;

            $lat = floatval($r['lat'] ?? 0);
            $lng = floatval($r['lng'] ?? 0);
            $isValid = ($lat != 0 && $lng != 0);

            // LÓGICA ANTI-DUPLICADOS:
            // Si el IMEI ya existe en la lista, solo lo sobrescribimos si el nuevo es VÁLIDO y el anterior NO.
            // Si ambos son válidos o ambos inválidos, nos quedamos con el primero (ahorro de proceso).
            if (isset($uniquePositions[$sid])) {
                if ($uniquePositions[$sid]['valid'] === false && $isValid === true) {
                    // Actualizar porque encontramos una mejor señal en otra cuenta
                } else {
                    continue; // Ya tenemos este IMEI, saltar para no duplicar
                }
            }
            
            $uniquePositions[$sid] = [
                'imei' => $sid,
                'name' => $mapNames[$sid] ?? "Unidad",
                'lat' => $lat,
                'lng' => $lng,
                'speed' => intval($r['speed'] ?? 0),
                'angle' => intval($r['angle'] ?? 0),
                'dt' => $r['dt_server'] ?? date('Y-m-d H:i:s'),
                'valid' => $isValid
            ];
        }
    }

    // Rellenar los que faltaron por completo
    foreach ($allowedImeis as $im) {
        if (!isset($uniquePositions[$im])) {
            $uniquePositions[$im] = [
                'imei' => (string)$im, 
                'name' => $mapNames[$im] ?? "Unidad", 
                'lat' => 0, 
                'lng' => 0, 
                'speed' => 0, 
                'valid' => false, 
                'dt' => 'Sin conexión'
            ];
        }
    }

    // Devolver lista limpia indexada numéricamente
    echo json_encode(['positions' => array_values($uniquePositions)]); exit;
}

function fetchUrl($u) {
    $c = curl_init(); 
    curl_setopt_array($c, [
        CURLOPT_URL => $u, 
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_TIMEOUT => 25, 
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    ]);
    $r = curl_exec($c); 
    if(curl_errno($c)) {
        logDebug("CURL Error: " . curl_error($c));
        return '[]';
    }
    curl_close($c); 
    return $r ?: '[]';
}
?>