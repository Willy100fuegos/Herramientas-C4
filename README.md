# Herramientas C4 - Suite de Orquestación Operativa 🚨

> **Sistema de Gestión de Incidentes y Telemetría Unificada.**
> *Middleware de integración para Centros de Monitoreo (C4) que centraliza alertas, reportería y cuentas espejo.*

<p align="center">
  <img src="https://pixmedia.b-cdn.net/pixmedialogoblanco.png" width="200" alt="Pixmedia Agency">
</p>

---

## 🎯 Visión General

**Herramientas C4** es una suite de orquestación (SOAR) diseñada para resolver las limitaciones de las plataformas comerciales de rastreo GPS. Actúa como un cerebro central que:
1.  **Unifica:** Conecta múltiples cuentas maestras (Centurión, ETF, UIPSA) en una sola API.
2.  **Reacciona:** Detecta eventos críticos (SOS, Geocercas) y alerta en tiempo real vía Firebase.
3.  **Comparte:** Genera enlaces de rastreo temporal ("Espejos") para clientes externos sin exponer credenciales.



---

## 📸 Showcase de Módulos

### 1. Gestión de Alertas (SOAR)
El corazón operativo del C4. Un panel diseñado para la reacción inmediata ante incidentes.

| **Monitor de Alertas** | **Bitácora de Gestión** | **Tarjeta Táctica** |
|:---:|:---:|:---:|
| <img src="http://imgfz.com/i/P2GsKqo.png" width="250"> | <img src="https://imgfz.com/i/CJRKrMg.png" width="250"> | <img src="https://imgfz.com/i/NBw9sOq.png" width="250"> |
| **Firebase Live:** Recepción de eventos críticos (SOS) con alerta auditiva instantánea sin recargar la página. | **Auditoría:** Log detallado de todas las alertas atendidas, clasificadas por motivo y operador. | **Evidencia Digital:** Generación automática de resúmenes visuales listos para compartir por WhatsApp. |

### 2. Interoperabilidad (Cuentas Espejo)
Sistema para compartir ubicación en tiempo real de forma segura y temporal.

| **Generador On-Demand** | **Visor Unificado (Cliente)** |
|:---:|:---:|
| <img src="https://imgfz.com/i/wX72QVa.png" width="400"> | <img src="https://imgfz.com/i/O4kpKdh.png" width="400"> |
| **API Proxy:** Interfaz para seleccionar unidades de múltiples clientes y crear enlaces con vigencia programada. | **Leaflet JS:** Mapa interactivo limpio que recibe el cliente final. No requiere usuario ni contraseña. |

### 3. Reportería Inteligente
Motores de análisis de datos para la prevención de riesgos.

| **Output Dinámico (Heatmap)** | **Configuración de Reportes** | **Tiempos y Movimientos** |
|:---:|:---:|:---:|
| <img src="https://imgfz.com/i/6xs1TrO.png" width="250"> | <img src="https://imgfz.com/i/8KDEsR0.png" width="250"> | <img src="https://imgfz.com/i/hlHQoTr.png" width="250"> |
| Reporte interactivo con mapas de calor de incidencias y trazado de rutas críticas. | Panel para programar envíos automáticos de reportes de velocidad por correo. | Análisis detallado de ruta con paradas, encendidos y kilometraje. |

---

## 📂 Anatomía del Sistema (Diccionario de Archivos)

El repositorio está estructurado en 12 componentes clave divididos en 3 capas lógicas:

### 🔴 Capa de Tiempo Real & Visualización
* **`dashboard.html`**: El "Cerebro". Interfaz principal del monitorista conectada a **Firebase**. Escucha cambios en la base de datos para disparar alertas visuales y sonoras.
* **`dashboard_gps_unified.html`**: Mapa maestro que consume la API unificada para mostrar **todas** las unidades de todas las cuentas en una sola pantalla.
* **`admin.html`**: Panel administrativo para la selección de unidades y generación de tokens para las cuentas espejo.
* **`mirror.php`**: El visor público ("Front-facing"). Es la página que ven los clientes externos cuando reciben un enlace espejo. Valida el token y muestra el mapa.

### 🔵 Capa de Backend & Integración (Middleware)
* **`gps_proxy_unified.php`**: El "Traductor". Recibe peticiones del frontend y consulta las APIs de los diferentes proveedores (Centurión, UIPSA, etc.), devolviendo un formato JSON estandarizado.
* **`webhook_handler.php`**: El "Oído". Script que recibe los datos crudos (POST) desde la plataforma de rastreo cuando ocurre una alerta y los inyecta en Firebase.
* **`backend.php`**: Motor lógico para el sistema de espejos. Se encarga de guardar los tokens generados y validar su caducidad.

### 🟢 Capa de Reportería & Automatización
* **`api_generar_manual.php`**: Motor de cálculo pesado. Procesa miles de puntos GPS para detectar excesos de velocidad y generar los JSONs para los mapas de calor.
* **`panel_gestion_ondemand.php`**: Interfaz de usuario (UI) para solicitar reportes manuales de rangos de fecha específicos.
* **`panel_reportes.php`**: UI para configurar qué unidades y a qué correos se enviarán los reportes automáticos semanales.
* **`generador_reporte_cron.php`**: Script diseñado para ejecutarse automáticamente (Cron Job). Verifica la configuración y dispara los correos programados.
* **`reporte_programado.php`**: Plantilla lógica que estructura el contenido HTML del correo electrónico de reporte.

---

## 👨‍💻 Guía de Despliegue (Deploy)

### 1. Requisitos
* Servidor Web (Apache/Nginx) con PHP 8.0+.
* Proyecto en **Firebase Console** (Firestore Database).
* Acceso a Cron Jobs (para reportes automáticos).

### 2. Configuración
Antes de subir a producción, edita los siguientes archivos (ya sanitizados en el repo):
1.  **`gps_proxy_unified.php`**: Coloca tus API Keys reales de Goratrack/Navixy.
2.  **`dashboard.html`**: Actualiza el objeto `firebaseConfig` con tus credenciales.
3.  **`generador_reporte_cron.php`**: Configura las credenciales SMTP para el envío de correos.

### 3. Webhooks
Apunta los Webhooks de tu proveedor GPS a:
`https://tudominio.com/herramientas-c4/webhook_handler.php`

---

## 🔒 Seguridad

Este software ha sido diseñado bajo principios de **Security by Design**:
* Las credenciales de las cuentas maestras nunca se exponen al frontend (se quedan en el proxy PHP).
* Los enlaces espejo son de un solo uso o caducidad programada.
* El código fuente público ha sido sanitizado para remover llaves de producción.

**Desarrollado por:**
**William Velázquez Valenzuela**
*Director de Tecnologías | Pixmedia Agency*
