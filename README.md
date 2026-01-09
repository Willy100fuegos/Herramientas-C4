# Herramientas C4 - Suite de Orquestación Operativa 🚨

> **Sistema de Gestión de Incidentes y Telemetría Unificada.**
> *Middleware de integración para Centros de Monitoreo (C4) que centraliza alertas, reportería y cuentas espejo.*

---

## 🎯 Objetivo del Sistema

Esta plataforma actúa como una **capa de inteligencia (Middleware)** sobre la plataforma comercial de rastreo GPS (Goratrack). Su función es resolver las limitaciones nativas del proveedor, permitiendo:

1.  **Interoperabilidad:** Crear enlaces espejo temporales ("Uber-like links") para clientes externos sin crear usuarios en la plataforma base.
2.  **Alertamiento SOAR:** Centralizar alertas críticas (SOS, Geocercas) en un dashboard de tiempo real con aviso sonoro.
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

### 📂 Estructura de Archivos Clave

* `dashboard.html`: Interfaz principal del monitorista (Conectada a Firebase).
* `gps_proxy_unified.php`: Gateway que unifica la autenticación de múltiples cuentas de rastreo.
* `mirror.php`: Visor público ligero para los enlaces espejo.
* `api_generar_manual.php`: Motor de procesamiento masivo de coordenadas para reportes de velocidad.

---
**William Velázquez Valenzuela**
*Director de Tecnologías | Pixmedia Agency*
