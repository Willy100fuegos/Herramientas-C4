# Herramientas C4 - Suite de Orquestación Operativa 🚨

> **Sistema de Gestión de Incidentes y Telemetría Unificada.**
> *Middleware de integración para Centros de Monitoreo (C4) que centraliza alertas, reportería y cuentas espejo.*

---

## 🎯 Objetivo del Sistema

Esta plataforma actúa como una **capa de inteligencia (Middleware)** sobre la plataforma comercial de rastreo GPS (Goratrack). Su función es resolver las limitaciones nativas del proveedor, permitiendo:

1.  **Interoperabilidad:** Crear enlaces espejo temporales ("Uber-like links") para clientes externos sin crear usuarios en la plataforma base.
2.  **Alertamiento SOAR:** Centralizar alertas críticas (SOS, Geocercas) en un dashboard de tiempo real con aviso sonoro y visual.
3.  **Reportería Forense:** Generar mapas de calor y análisis de velocidad que la plataforma nativa no ofrece.

---

## 🔄 Flujo de Trabajo (Módulos)

### 1. Panel de Gestión de Alertas (Real-Time)
El monitorista recibe alertas instantáneas vía Webhook. El sistema utiliza **Firebase** para empujar la notificación visual y auditiva al navegador sin necesidad de recargar la página.
![Panel Realtime](http://imgfz.com/i/P2GsKqo.png)

Desde aquí, se gestiona el incidente y se genera una **Tarjeta Táctica** para WhatsApp:
![Share Card](https://imgfz.com/i/NBw9sOq.png)

### 2. Generador de Cuentas Espejo (On-Demand)
A través de una API Proxy Unificada (`gps_proxy_unified.php`), el sistema consulta múltiples cuentas maestras (UIPSA, ETF, Centurión), lista todas las unidades disponibles y permite generar un link temporal de visualización.
![Admin Espejo](https://imgfz.com/i/wX72QVa.png)

El cliente final recibe un enlace único que muestra solo las unidades seleccionadas en un mapa limpio:
![Mapa Espejo](https://imgfz.com/i/O4kpKdh.png)

### 3. Inteligencia Vial y Reportes
Generación de reportes de excesos de velocidad y tiempos/movimientos. El backend procesa miles de puntos GPS (`api_generar_manual.php`) para construir mapas de calor de incidencias.
![Heatmap](https://imgfz.com/i/6xs1TrO.png)

---

## 🛠️ Arquitectura Técnica

El sistema utiliza un enfoque de **Microservicios Híbridos**:

| Componente | Tecnología | Función |
| :--- | :--- | :--- |
| **Backend Core** | **PHP 8.2** | Proxy de APIs, generación de reportes y lógica de negocio. |
| **Real-Time DB** | **Firebase Firestore** | Sincronización de alertas en vivo y estado del dashboard. |
| **Map Engine** | **Leaflet JS** | Renderizado de mapas interactivos ligeros (OpenStreetMap). |
| **Ingesta** | **Webhooks** | `webhook_handler.php` recibe eventos RAW del proveedor GPS. |
| **Visualización** | **Chart.js** | Gráficos de tendencias y matrices de calor. |

---

## 👨‍💻 Guía de Despliegue (Para Desarrolladores)

Esta suite requiere configuración tanto en servidor web (PHP) como en servicios cloud (Firebase/Google).

### 1. Requisitos del Sistema
* Servidor LAMP (Linux, Apache, MySQL, PHP 8+).
* Extensiones PHP: `curl`, `json`, `mbstring`.
* Cuenta de Firebase (Para el módulo de tiempo real).
* API Key del proveedor de rastreo (Goratrack/Navixy/Wialon).

### 2. Configuración de Archivos Clave
El código ha sido sanitizado. Antes de desplegar, debes editar los siguientes archivos:

* **`gps_proxy_unified.php` y `backend.php`**:
    * Configura el array `$ACCOUNTS` con las API Keys reales de tus sub-cuentas.
    * Define la constante `GORATRACK_BASE_URL`.
* **`dashboard.html`**:
    * Actualiza el objeto `firebaseConfig` con tus credenciales de proyecto Firebase (API Key, AuthDomain, ProjectId).
* **`generador_reporte_cron.php`**:
    * Configura los datos SMTP para el envío automático de correos.

### 3. Webhooks (Ingesta de Datos)
El archivo `webhook_handler.php` actúa como el "oído" del sistema.
1.  Coloca este archivo en una ruta pública accesible (HTTPS).
2.  Configura tu plataforma GPS para enviar notificaciones POST a esta URL.
3.  El script procesará el JSON entrante y lo escribirá en Firebase para alertar al monitorista.

---

## 🔒 Nota de Seguridad

Por motivos de confidencialidad operativa:
*eliminé las credenciales de acceso a las plataformas de rastreo y servicios de correo de mi cliente.
* Se han ofuscado las URLs de los endpoints de producción.
* Este repositorio sirve como demostración de la arquitectura **SOAR** implementada.

**Desarrollado por:**
**William Velázquez Valenzuela**
*Director de Tecnologías | Pixmedia Agency*
