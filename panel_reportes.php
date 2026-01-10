<?php
// PANEL DE GESTIÓN DE REPORTES AUTOMATIZADOS
// Permite seleccionar unidades y correos para el reporte semanal.

$configFile = __DIR__ . '/config_reportes.json';
$mensaje = "";

// --- GUARDAR CONFIGURACIÓN (Si se envía el formulario) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuevas_unidades = $_POST['units'] ?? [];
    $nuevos_correos = $_POST['emails'] ?? '';
    
    $data_to_save = [
        "unidades_seleccionadas" => $nuevas_unidades,
        "correos_destino" => $nuevos_correos,
        "ultima_actualizacion" => date('Y-m-d H:i:s')
    ];
    
    if (file_put_contents($configFile, json_encode($data_to_save, JSON_PRETTY_PRINT))) {
        $mensaje = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4'>✅ Configuración guardada exitosamente. El próximo Lunes se usará esta lista.</div>";
    } else {
        $mensaje = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4'>❌ Error al guardar. Verifica permisos de escritura en config_reportes.json</div>";
    }
}

// --- LEER CONFIGURACIÓN ACTUAL ---
$current_config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$selected_units = $current_config['unidades_seleccionadas'] ?? [];
$saved_emails = $current_config['correos_destino'] ?? '';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Reportes Automáticos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-100 p-6">

    <div class="max-w-4xl mx-auto bg-white rounded-xl shadow-lg overflow-hidden">
        <!-- Header -->
        <div class="bg-blue-600 p-6 text-white flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold">Automatización de Reportes Semanales</h1>
                <p class="text-blue-100 text-sm mt-1">Configura qué unidades analizar y a quién enviar el reporte.</p>
            </div>
            <img src="https://tu-web.mx/img/logo.png" class="h-10 bg-white rounded p-1">
        </div>

        <div class="p-6">
            <?php echo $mensaje; ?>

            <form method="POST" id="configForm">
                
                <!-- Sección de Correos -->
                <div class="mb-8">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="emails">
                        📧 Correos Electrónicos de Destino (separados por coma)
                    </label>
                    <input type="text" name="emails" id="emails" value="<?php echo htmlspecialchars($saved_emails); ?>" 
                           class="shadow appearance-none border rounded w-full py-3 px-4 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500" 
                           placeholder="cliente@empresa.com, monitorista@tuempresa.mx">
                </div>

                <!-- Sección de Unidades -->
                <div class="mb-6">
                    <div class="flex justify-between items-end mb-2">
                        <label class="block text-gray-700 text-sm font-bold">
                            🚗 Seleccionar Unidades para el Reporte
                        </label>
                        <div class="text-xs space-x-2">
                            <button type="button" id="btnTodos" class="text-blue-600 hover:underline">Marcar Todas</button>
                            <button type="button" id="btnNinguna" class="text-blue-600 hover:underline">Desmarcar Todas</button>
                        </div>
                    </div>

                    <!-- Buscador -->
                    <input type="text" id="buscador" placeholder="🔍 Buscar unidad..." class="w-full mb-3 p-2 border rounded text-sm">

                    <!-- Contenedor de Checkboxes (Cargado vía AJAX) -->
                    <div id="loading-units" class="text-center py-10 text-gray-500">
                        <div class="animate-spin inline-block w-6 h-6 border-[3px] border-current border-t-transparent text-blue-600 rounded-full" role="status"></div>
                        <span class="ml-2">Cargando lista de unidades desde Goratrack...</span>
                    </div>
                    
                    <div id="units-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 max-h-96 overflow-y-auto p-2 border rounded bg-slate-50 hidden">
                        <!-- JS insertará aquí las unidades -->
                    </div>
                </div>

                <!-- Botón Guardar -->
                <div class="border-t pt-6 flex justify-end">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 rounded shadow transition transform hover:scale-105">
                        💾 Guardar Configuración
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Variables pasadas desde PHP
        const selectedUnits = <?php echo json_encode($selected_units); ?>;

        document.addEventListener('DOMContentLoaded', () => {
            fetchUnits();
            
            // Filtro de búsqueda
            document.getElementById('buscador').addEventListener('keyup', function(e) {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('.unit-item').forEach(div => {
                    const name = div.textContent.toLowerCase();
                    div.style.display = name.includes(term) ? 'flex' : 'none';
                });
            });

            // Botones masivos
            document.getElementById('btnTodos').onclick = () => checkAll(true);
            document.getElementById('btnNinguna').onclick = () => checkAll(false);
        });

        function checkAll(state) {
            document.querySelectorAll('input[name="units[]"]').forEach(cb => {
                // Solo afectar a los visibles si hay filtro
                if(cb.closest('.unit-item').style.display !== 'none') cb.checked = state;
            });
        }

        async function fetchUnits() {
            // Usamos tu proxy existente para obtener la lista real
            try {
                const response = await fetch('gps_proxy_uipsa.php?command_action=USER_GET_OBJECTS');
                const data = await response.json();
                
                const grid = document.getElementById('units-grid');
                const loading = document.getElementById('loading-units');
                
                loading.style.display = 'none';
                grid.classList.remove('hidden');

                // Ordenar alfabéticamente
                data.sort((a, b) => a.name.localeCompare(b.name));

                data.forEach(unit => {
                    const isChecked = selectedUnits.includes(unit.imei) ? 'checked' : '';
                    const div = document.createElement('div');
                    div.className = 'unit-item flex items-center bg-white p-2 rounded border shadow-sm hover:bg-blue-50 transition';
                    div.innerHTML = `
                        <input type="checkbox" id="u_${unit.imei}" name="units[]" value="${unit.imei}" class="h-4 w-4 text-blue-600" ${isChecked}>
                        <label for="u_${unit.imei}" class="ml-2 text-sm text-gray-700 cursor-pointer w-full truncate font-medium">${unit.name}</label>
                    `;
                    grid.appendChild(div);
                });

            } catch (error) {
                document.getElementById('loading-units').innerHTML = `<span class='text-red-500'>Error al cargar unidades: ${error.message}</span>`;
            }
        }
    </script>
</body>
</html>