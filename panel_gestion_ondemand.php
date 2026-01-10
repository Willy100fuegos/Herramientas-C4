<?php
// ======================================================================================
// PANEL DE GESTIÓN ON-DEMAND V5 - ALTA PRECISIÓN
// ======================================================================================
// 1. Selector de Cuenta.
// 2. Tarjeta de Velocidad (Activa - Algoritmo Punto a Punto).
// 3. Tarjeta de Geocercas (Inactiva - Próximamente).
// ======================================================================================

$cuentas = [
    'UIPSA'     => 'AQUI_VA_TU_API',
    'CENTURION' => 'AQUI_VA_TU_API',
    'ETF'       => 'AQUI_VA_TU_API'
];

$selected_account = $_GET['account'] ?? 'UIPSA';
$current_api_key = $cuentas[$selected_account] ?? $cuentas['UIPSA'];

define('GORATRACK_BASE_URL', 'https://TU-WEB.mx/api/api.php');

// Cargar Unidades
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, GORATRACK_BASE_URL . '?api=user&key=' . $current_api_key . '&cmd=USER_GET_OBJECTS');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$unidades = json_decode($response, true) ?? [];
if (is_array($unidades)) {
    usort($unidades, function($a, $b) { return strcasecmp($a['name'] ?? '', $b['name'] ?? ''); });
} else {
    $unidades = [];
}

// Cargar configuración guardada
$configFile = __DIR__ . "/config_{$selected_account}.json";
$config_guardada = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$correos_guardados = $config_guardada['correos_destino'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Inteligencia - <?php echo $selected_account; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1f1f1; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 10px; }
        .disabled-card { opacity: 0.6; filter: grayscale(100%); pointer-events: none; }
    </style>
</head>
<body class="text-gray-800 p-6">

    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-center mb-10 bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <div class="flex items-center gap-4">
                <img src="https://TU-WEB.mx/img/logo.png" class="h-10">
                <div class="h-8 w-px bg-gray-300"></div>
                <div>
                    <h1 class="text-2xl font-extrabold text-gray-900 tracking-tight">Centro de Inteligencia</h1>
                    <p class="text-gray-500 text-xs">Gestión de reportes vehiculares</p>
                </div>
            </div>
            
            <div class="mt-4 md:mt-0 flex items-center gap-3 bg-gray-50 p-2 rounded-lg border border-gray-200">
                <span class="text-xs font-bold text-gray-500 uppercase ml-2">Cliente:</span>
                <select id="accountSelector" onchange="changeAccount(this.value)" class="bg-white border border-gray-300 text-gray-700 text-sm rounded-md focus:ring-blue-500 focus:border-blue-500 block p-2 font-bold">
                    <?php foreach($cuentas as $nombre => $key): ?>
                        <option value="<?php echo $nombre; ?>" <?php echo $nombre === $selected_account ? 'selected' : ''; ?>>
                            <?php echo $nombre; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            
            <!-- CARD 1: VELOCIDAD (ACTIVO) -->
            <div class="bg-white rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-300 group overflow-hidden border border-gray-100 relative">
                <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-red-500 to-red-700"></div>
                <div class="p-8">
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-14 h-14 bg-red-50 rounded-xl flex items-center justify-center text-red-600 text-2xl shadow-inner">
                            <i class="fa-solid fa-gauge-high"></i>
                        </div>
                        <span class="bg-green-100 text-green-700 text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wide">Activo</span>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-900 mb-2">Velocidad Crítica</h3>
                    <p class="text-gray-500 text-sm mb-8">Análisis forense punto a punto (> 60 km/h).</p>
                    <button onclick="openModal('speed')" class="w-full py-4 bg-gray-50 text-gray-700 font-bold rounded-xl hover:bg-blue-600 hover:text-white transition-colors flex items-center justify-center gap-3 group-hover:shadow-lg border border-gray-200 group-hover:border-blue-600">
                        <span>Gestionar Reporte</span> <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- CARD 2: GEOCERCAS (PRÓXIMAMENTE) -->
            <div class="bg-white rounded-2xl shadow-inner border border-gray-100 relative opacity-70">
                <div class="absolute top-0 left-0 w-full h-2 bg-gray-300"></div>
                <div class="p-8">
                    <div class="flex justify-between items-start mb-6">
                        <div class="w-14 h-14 bg-gray-100 rounded-xl flex items-center justify-center text-gray-400 text-2xl">
                            <i class="fa-solid fa-draw-polygon"></i>
                        </div>
                        <span class="bg-gray-200 text-gray-500 text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wide">Próximamente</span>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-400 mb-2">Geocercas</h3>
                    <p class="text-gray-400 text-sm mb-8">Análisis de entradas, salidas y tiempos de estancia.</p>
                    <button disabled class="w-full py-4 bg-gray-50 text-gray-400 font-bold rounded-xl cursor-not-allowed border border-gray-100">
                        <span>No Disponible</span>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- MODAL -->
    <div id="modalBackdrop" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-40 transition-opacity" onclick="closeModal()"></div>
    <div id="configPanel" class="fixed inset-y-0 right-0 w-full md:w-[600px] bg-white shadow-2xl z-50 transform translate-x-full transition-transform duration-300 flex flex-col">
        
        <div class="bg-gray-800 p-6 text-white flex justify-between items-center shrink-0" id="modalHeaderBg">
            <div>
                <h2 class="text-xl font-bold" id="modalTitle">Configuración</h2>
                <p class="text-gray-300 text-xs mt-1">Cliente: <?php echo $selected_account; ?></p>
            </div>
            <button onclick="closeModal()" class="p-2 hover:bg-white/10 rounded-full transition"><i class="fa-solid fa-xmark text-xl"></i></button>
        </div>

        <div class="flex-1 overflow-y-auto p-6 custom-scroll bg-gray-50 space-y-6">
            <!-- Fechas -->
            <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200">
                <h3 class="text-xs font-bold text-gray-500 uppercase mb-4"><i class="fa-regular fa-calendar-days mr-2"></i>Periodo</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="text-xs font-bold block mb-1">INICIO</label><input type="datetime-local" id="start_date" class="w-full p-2 border rounded text-sm"></div>
                    <div><label class="text-xs font-bold block mb-1">FIN</label><input type="datetime-local" id="end_date" class="w-full p-2 border rounded text-sm"></div>
                </div>
            </div>

            <!-- Emails -->
            <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200">
                <h3 class="text-xs font-bold text-gray-500 uppercase mb-4"><i class="fa-regular fa-envelope mr-2"></i>Destinatarios</h3>
                <textarea id="emails" rows="2" class="w-full p-3 border rounded text-sm" placeholder="correo@ejemplo.com"><?php echo htmlspecialchars($correos_guardados); ?></textarea>
            </div>

            <!-- Unidades -->
            <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xs font-bold text-gray-500 uppercase"><i class="fa-solid fa-car mr-2"></i>Unidades</h3>
                    <div class="text-xs space-x-2 text-blue-600">
                        <button onclick="toggleAll(true)">Todas</button><span>|</span><button onclick="toggleAll(false)">Ninguna</button>
                    </div>
                </div>
                <input type="text" id="searchUnit" placeholder="Buscar..." class="w-full mb-3 p-2 border rounded text-sm">
                <div class="overflow-y-auto max-h-60 border rounded custom-scroll p-1">
                    <?php foreach ($unidades as $u): if (!isset($u['imei'])) continue; ?>
                    <label class="flex items-center p-2 hover:bg-gray-50 cursor-pointer unit-row">
                        <input type="checkbox" value="<?php echo $u['imei']; ?>" class="unit-cb w-4 h-4 text-blue-600 rounded">
                        <span class="ml-3 text-sm text-gray-700 unit-name"><?php echo $u['name'] ?? 'N/A'; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="statusMsg" class="hidden p-4 rounded-lg text-center text-sm font-bold"></div>
        </div>

        <div class="p-6 bg-white border-t flex gap-3 shrink-0">
            <button onclick="processAction()" class="flex-1 py-3 bg-green-600 text-white font-bold rounded-xl hover:bg-green-700 shadow flex justify-center items-center gap-2">
                <i class="fa-solid fa-bolt"></i> <span>Generar Reporte</span>
            </button>
        </div>
    </div>

    <script>
        const currentApiKey = '<?php echo $current_api_key; ?>';
        const currentAccount = '<?php echo $selected_account; ?>';

        // Init Fechas
        const now = new Date();
        const monday = new Date(now); monday.setDate(now.getDate() - now.getDay() + 1); monday.setHours(0,0,0,0);
        const fmt = d => d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')+'T'+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
        document.getElementById('start_date').value = fmt(monday);
        document.getElementById('end_date').value = fmt(now);

        function changeAccount(account) { window.location.href = '?account=' + account; }

        function openModal(type) {
            const title = document.getElementById('modalTitle');
            const header = document.getElementById('modalHeaderBg');
            title.innerText = 'Reporte de Velocidad';
            header.className = 'bg-red-700 p-6 text-white flex justify-between items-center shrink-0';
            
            document.getElementById('modalBackdrop').classList.remove('hidden');
            document.getElementById('configPanel').classList.remove('translate-x-full');
        }

        function closeModal() {
            document.getElementById('configPanel').classList.add('translate-x-full');
            setTimeout(() => {
                document.getElementById('modalBackdrop').classList.add('hidden');
                document.getElementById('statusMsg').classList.add('hidden');
            }, 300);
        }

        document.getElementById('searchUnit').addEventListener('keyup', function(e) {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('.unit-row').forEach(row => {
                row.style.display = row.querySelector('.unit-name').textContent.toLowerCase().includes(term) ? 'flex' : 'none';
            });
        });

        function toggleAll(state) {
            document.querySelectorAll('.unit-cb').forEach(cb => {
                if(cb.closest('.unit-row').style.display !== 'none') cb.checked = state;
            });
        }

        async function processAction() {
            const units = Array.from(document.querySelectorAll('.unit-cb:checked')).map(cb => cb.value);
            const emails = document.getElementById('emails').value;
            const start = document.getElementById('start_date').value;
            const end = document.getElementById('end_date').value;
            const statusDiv = document.getElementById('statusMsg');

            if(units.length === 0) return alert("Seleccione unidades.");
            
            statusDiv.className = "p-4 rounded-lg text-center text-sm font-bold bg-blue-100 text-blue-700 block";
            statusDiv.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Generando reporte de alta precisión...';

            const formData = new FormData();
            formData.append('action', 'generate_now');
            formData.append('api_key', currentApiKey); 
            formData.append('account_name', currentAccount);
            formData.append('units', JSON.stringify(units));
            formData.append('emails', emails);
            formData.append('start', start);
            formData.append('end', end);

            try {
                const res = await fetch('api_generar_manual.php', { method: 'POST', body: formData });
                const data = await res.json();

                if(data.success) {
                    statusDiv.className = "p-4 rounded-lg text-center text-sm font-bold bg-green-100 text-green-700 block";
                    statusDiv.innerHTML = `✅ ¡Enviado! <a href="${data.url}" target="_blank" class="underline">Ver Reporte</a>`;
                    
                    // Guardar emails si cambiaron
                    const formConfig = new FormData();
                    formConfig.append('action', 'save_config');
                    formConfig.append('emails', emails);
                    fetch('api_generar_manual.php', { method: 'POST', body: formConfig });

                } else {
                    throw new Error(data.message);
                }
            } catch (e) {
                statusDiv.className = "p-4 rounded-lg text-center text-sm font-bold bg-red-100 text-red-700 block";
                statusDiv.innerHTML = 'Error: ' + e.message;
            }
        }
    </script>
</body>
</html>