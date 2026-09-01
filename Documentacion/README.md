<p align="center">
  <img src="https://img.shields.io/badge/Laravel-11-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 11"/>
  <img src="https://img.shields.io/badge/Angular-17-DD0031?style=for-the-badge&logo=angular&logoColor=white" alt="Angular 17"/>
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2"/>
  <img src="https://img.shields.io/badge/SQL%20Server-2019+-CC2927?style=for-the-badge&logo=microsoftsqlserver&logoColor=white" alt="SQL Server"/>
  <img src="https://img.shields.io/badge/Azure-Blob%20Storage-0078D4?style=for-the-badge&logo=microsoftazure&logoColor=white" alt="Azure Blob"/>
</p>

<h1 align="center">🏢 Kyrema</h1>

<p align="center">
  <strong>Plataforma integral de gestión de sociedades, productos, comerciales, socios y pagos.</strong><br/>
  Backend API REST con <a href="https://laravel.com/">Laravel 11</a> · Frontend SPA con <a href="https://angular.io/">Angular 17</a>
</p>

---

## 📑 Tabla de Contenidos

- [Descripción del Proyecto](#-descripción-del-proyecto)
- [Arquitectura del Sistema](#-arquitectura-del-sistema)
- [Requisitos Previos](#-requisitos-previos)
- [Clonar los Repositorios](#1%EF%B8%8F⃣-clonar-los-repositorios)
- [Configurar la Base de Datos](#2%EF%B8%8F⃣-configurar-la-base-de-datos-sql-server-express)
- [Configurar Azure Blob Storage Local (Azurite)](#3%EF%B8%8F⃣-configurar-azure-blob-storage-local-azurite)
- [Configurar el Backend (Laravel)](#4%EF%B8%8F⃣-configurar-el-backend-laravel)
- [Configurar el Frontend (Angular)](#5%EF%B8%8F⃣-configurar-el-frontend-angular)
- [Estructura del Proyecto](#-estructura-del-proyecto)
- [Módulos Funcionales](#-módulos-funcionales)
- [Pasarelas de Pago](#-pasarelas-de-pago)
- [Comandos Útiles](#-comandos-útiles)
- [Despliegue en Azure](#-despliegue-en-azure)
- [Solución de Problemas](#-solución-de-problemas)
- [Notas Adicionales](#-notas-adicionales)

---

## 🧩 Descripción del Proyecto

**Kyrema** (anteriormente *Cánama Seguros*) es una plataforma de gestión integral diseñada para administrar:

- **Sociedades** — Jerarquía de sociedades padre/hija con permisos configurables.
- **Tipos de Producto** — Definición dinámica de campos, tarifas, anexos y plantillas.
- **Productos** — Creación, edición, anulación, historial y exportación a PDF.
- **Comerciales** — Gestión de usuarios comerciales con sistema de comisiones (fijo/porcentual).
- **Socios** — Registro, autenticación propia y vinculación con productos.
- **Pagos** — Giro bancario (SEPA Q19), Redsys InSite y Stripe, con gestión de remesas y CSV.
- **Pólizas** — Vinculación de pólizas con compañías aseguradoras y tipos de producto.
- **Categorías** — Sistema multi-tenant con logos y configuración por categoría.
- **Informes & Exportaciones** — Generación de PDFs (DomPDF / mPDF) y hojas Excel (PhpSpreadsheet).
- **Almacenamiento** — Documentos y plantillas en Azure Blob Storage con URLs firmadas (SAS).
- **Notificaciones** — Notificaciones por email de cambios en productos.

---

## 🏗️ Arquitectura del Sistema

```
┌─────────────────────┐         ┌─────────────────────┐
│   Frontend (SPA)    │  HTTP   │   Backend (API)     │
│   Angular 17        │◄───────►│   Laravel 11        │
│   :4200             │         │   :8000             │
└────────┬────────────┘         └────────┬────────────┘
         │                               │
         │                    ┌──────────┼──────────────┐
         │                    │          │              │
         │               ┌────▼────┐ ┌───▼────┐  ┌─────▼─────┐
         │               │SQL Srvr │ │ Azure  │  │  Redsys/  │
         │               │Express  │ │ Blob   │  │  Stripe   │
         │               │ :1433   │ │Storage │  │  (Pagos)  │
         │               └─────────┘ └────────┘  └───────────┘
         │
    ┌────▼────────┐
    │  Azurite    │  (Emulador local de Azure Blob)
    │  :10000     │
    └─────────────┘
```

---

## 📋 Requisitos Previos

Asegúrate de tener instalado lo siguiente en tu PC:

| Herramienta             | Versión mínima | Enlace de descarga                                                                                     |
| ----------------------- | -------------- | ------------------------------------------------------------------------------------------------------ |
| **PHP**                 | 8.2            | [php.net](https://www.php.net/downloads)                                                               |
| **Composer**            | 2.x            | [getcomposer.org](https://getcomposer.org/download/)                                                   |
| **Node.js**             | 18.x           | [nodejs.org](https://nodejs.org/)                                                                      |
| **Angular CLI**         | 17.x           | `npm install -g @angular/cli@17`                                                                       |
| **SQL Server Express**  | 2019+          | [microsoft.com](https://www.microsoft.com/es-es/sql-server/sql-server-downloads)                       |
| **SSMS** _(opcional)_   | —              | [microsoft.com](https://learn.microsoft.com/es-es/sql/ssms/download-sql-server-management-studio-ssms) |
| **Azurite** _(npm)_     | —              | `npm install -g azurite`                                                                               |
| **Git**                 | 2.x            | [git-scm.com](https://git-scm.com/downloads)                                                          |

### Extensiones PHP requeridas

Verifica que estas extensiones estén habilitadas en tu `php.ini`:

```ini
extension=pdo_sqlsrv
extension=sqlsrv
extension=gd
extension=mbstring
extension=openssl
extension=fileinfo
extension=zip
```

> **📥 Nota:** Los drivers `pdo_sqlsrv` y `sqlsrv` se descargan aparte desde [Microsoft Drivers for PHP for SQL Server](https://learn.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server). Asegúrate de descargar la versión compatible con tu versión de PHP (8.2) y arquitectura (x64 / NTS o TS).

---

## 1️⃣ Clonar los Repositorios

```bash
# Backend (Laravel)
git clone https://github.com/KongSoftwareSpain/backend-kyrema.git

# Frontend (Angular)
git clone https://github.com/KongSoftwareSpain/Kyrema.git
```

> ⚠️ **Importante:** La rama por defecto al clonar **NO** es la rama correcta. Después de clonar, cambia a la rama `main` en ambos repositorios:
>
> ```bash
> cd backend-kyrema
> git checkout main
> git pull origin main
>
> cd ../Kyrema
> git checkout main
> git pull origin main
> ```

La estructura resultante debería ser:

```
📂 tu-carpeta/
├── 📂 backend-kyrema/          ← Backend Laravel
└── 📂 Kyrema/
    └── 📂 frontend/            ← Frontend Angular
```

---

## 2️⃣ Configurar la Base de Datos (SQL Server Express)

### 2.1 Instalación de SQL Server Express

Para la instalación y configuración completa de SQL Server Express (instancia, TCP/IP, autenticación mixta, etc.), sigue la guía disponible en:

👉 [Documentación de Base de Datos Local](https://kongsoftwarespain.github.io/projects-docs/bbdd_local/)

### 2.2 Crear la base de datos

Una vez configurada la instancia, crea una base de datos llamada **`KYREMA_DEV_1`** (o el nombre que indique el administrador del proyecto):

```sql
CREATE DATABASE KYREMA_DEV_1;
```

> ⚠️ **Importante:** Consulta con el administrador del proyecto si debes ejecutar migraciones/seeders o importar un **dump** de la base de datos existente.

### 2.3 Verificar conectividad

Asegúrate de que:

- ✅ El servicio `SQL Server (SQLEXPRESS)` esté **corriendo**.
- ✅ **TCP/IP** esté habilitado en SQL Server Configuration Manager.
- ✅ El puerto **1433** esté configurado.
- ✅ La **autenticación mixta** (Windows + SQL Server) esté activada.
- ✅ Tengas un usuario SQL con permisos sobre la base de datos.

---

## 3️⃣ Configurar Azure Blob Storage Local (Azurite)

El proyecto utiliza **Azure Blob Storage** para almacenar documentos (plantillas PDF, logos, pólizas). En desarrollo local se usa **Azurite**, el emulador oficial de Azure Storage.

### 3.1 Instalar Azurite

```bash
npm install -g azurite
```

### 3.2 Iniciar Azurite

```bash
azurite --silent --location ./azurite-data --debug ./azurite-debug.log
```

> **💡 Tip:** Puedes crear un script o usar la extensión de VS Code [Azurite](https://marketplace.visualstudio.com/items?itemName=Azurite.azurite) para iniciarlo directamente desde el editor.

### 3.3 Crear el contenedor `canama`

Usa [Azure Storage Explorer](https://azure.microsoft.com/es-es/products/storage/storage-explorer/) o la CLI:

```bash
az storage container create --name canama --connection-string "DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1;"
```

---

## 4️⃣ Configurar el Backend (Laravel)

### 4.1 Instalar dependencias

```bash
cd backend-kyrema
composer install
npm install
```

### 4.2 Configurar el archivo `.env`

Copia el archivo `.env.example` (si existe) o crea `.env` en la raíz del backend con el siguiente contenido:

```env
APP_NAME=Kyrema
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000/
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

# ── Logging ────────────────────────────────────────
LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug
LOG_STACK=single

# ── Constantes de negocio ──────────────────────────
COMISION_TIPO_FIJO=fijo
COMISION_TIPO_PORCENTUAL=porcentual
TIPO_LOGO_SOCIEDAD=sociedad

# ── Autenticación ──────────────────────────────────
AUTH_PASSWORD_RESET_TOKEN_TABLE=password_resets
BCRYPT_ROUNDS=12
JWT_SECRET=                         # ← Generar con: php artisan jwt:secret

# ── Base de Datos (SQL Server Express) ─────────────
DB_CONNECTION=sqlsrv
DB_HOST=127.0.0.1\SQLEXPRESS        # ← Tu instancia de SQL Server
DB_DATABASE=KYREMA_DEV_1             # ← Nombre de tu base de datos
DB_USERNAME=tu_usuario               # ← Tu usuario de SQL Server
DB_PASSWORD=tu_contraseña            # ← Tu contraseña
DB_ENCRYPT=true
DB_TRUST_SERVER_CERTIFICATE=true

# ── MySQL Legacy (opcional, solo si necesitas sincronizar datos antiguos)
# DB_MYSQL_HOST=localhost
# DB_MYSQL_DATABASE=kyrema_gestion
# DB_MYSQL_USERNAME=root
# DB_MYSQL_PASSWORD=

# ── Azure Blob Storage (Azurite - Local) ───────────
AZURE_STORAGE_NAME=devstoreaccount1
AZURE_STORAGE_KEY=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==
AZURE_STORAGE_CONTAINER=canama
AZURE_STORAGE_CONNECTION_STRING="DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1;"
AZURE_STORAGE_SAS_PROTOCOL=https,http
AZURE_STORAGE_BASE_URL=http://127.0.0.1:10000/devstoreaccount1/canama

# ── Cola de Trabajos ──────────────────────────────
QUEUE_CONNECTION=database

# ── Email (SMTP) ──────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=mail.canamaseguros.com     # ← Solicitar al administrador
MAIL_PORT=465
MAIL_USERNAME=                       # ← Solicitar al administrador
MAIL_PASSWORD=                       # ← Solicitar al administrador
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=no-reply@canamaseguros.com
MAIL_FROM_NAME="Kyrema"

# ── Redsys (Pasarela de pago) ─────────────────────
REDSYS_ENVIRONMENT=local
REDSYS_MERCHANT_CODE=                # ← Solicitar al administrador
REDSYS_KEY=                          # ← Solicitar al administrador
REDSYS_TERMINAL=100
REDSYS_NOTIFY=https://tu-backend.com/api/payments/redsys/notify

# ── Stripe (Pasarela de pago) ─────────────────────
STRIPE_SECRET=                       # ← Solicitar al administrador

# ── URLs del Frontend ─────────────────────────────
FRONTEND_SUCCESS_URL=http://localhost:4200/pago-ok
FRONTEND_FAILED_URL=http://localhost:4200/pago-ko
RESET_PWD_URL=http://localhost:4200/login

# ── Misc ──────────────────────────────────────────
SOCIEDAD_ADMIN_ID=1
```

> ⚠️ **Importante:** Solicita los valores de `REDSYS_KEY`, `REDSYS_MERCHANT_CODE`, `STRIPE_SECRET` y las credenciales de email al administrador del proyecto.

### 4.3 Generar claves de la aplicación

```bash
# Clave de la aplicación Laravel
php artisan key:generate

# Clave JWT para autenticación por tokens
php artisan jwt:secret
```

### 4.4 Limpiar cachés

```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### 4.5 Ejecutar migraciones y seeders

> ⚠️ **Consulta con el administrador del proyecto** antes de ejecutar estos comandos, ya que puede ser necesario importar un dump de la BD en lugar de ejecutar migraciones/seeders desde cero.

```bash
php artisan migrate
php artisan db:seed
```

### 4.6 Iniciar el servidor de desarrollo

```bash
php artisan serve
```

El backend estará disponible en: **http://localhost:8000**

### 4.7 (Opcional) Iniciar el worker de colas

Si necesitas procesar trabajos en segundo plano (envío de emails, generación de remesas, etc.):

```bash
php artisan queue:work
```

---

## 5️⃣ Configurar el Frontend (Angular)

### 5.1 Instalar dependencias

```bash
cd Kyrema/frontend
npm install
```

### 5.2 Configurar el entorno de desarrollo

Edita el archivo `src/environments/environment.ts` para apuntar al backend local:

```typescript
export const environment = {
  production: false,
  url: 'http://localhost:4200',
  apiUrl: 'http://localhost:8000/api',                    // ← Backend local
  storageUrl: 'http://localhost:8000/storage/',            // ← Backend local
  blobStorageUrl: 'http://127.0.0.1:10000/devstoreaccount1/canama',  // ← Azurite local
  paymentUrl: 'https://sis-t.redsys.es:25443/sis/realizarPago',      // ← TPV de test Redsys
  stripePublicKey: 'pk_test_...',                         // ← Solicitar al administrador
  appName: 'Kyrema',
  sociedadAdminId: '1',
  prefijoAnexos: 'ANEXOS_',
  prefijoProducto: 'PRODUCTO_',
};
```

### 5.3 Iniciar el servidor de desarrollo

```bash
ng serve
```

El frontend estará disponible en: **http://localhost:4200**

---

## 📁 Estructura del Proyecto

### Backend (`backend-kyrema/`)

```
backend-kyrema/
├── app/
│   ├── Console/Commands/        # Artisan commands (19 comandos)
│   ├── Exports/                 # Exportaciones Excel (PhpSpreadsheet)
│   ├── Http/
│   │   ├── Controllers/         # 30+ controladores REST
│   │   │   ├── Auth/            # Login, registro, reset password
│   │   │   ├── Health/          # Health-check endpoint
│   │   │   ├── Notifications/   # Notificaciones por email
│   │   │   └── Payments/        # Remesas, Redsys InSite, Stripe
│   │   └── Middleware/          # Autenticación, CORS, etc.
│   ├── Listeners/               # Event listeners
│   ├── Models/                  # 28 modelos Eloquent
│   │   └── Payments/            # Modelos de pagos
│   ├── Notifications/           # Clases de notificación (email)
│   ├── Services/                # Lógica de negocio
│   │   ├── AzureSasService      # Generación de URLs SAS
│   │   ├── Payments/            # Servicio de pagos Redsys
│   │   └── Remesas/             # Generación de ficheros SEPA Q19
│   └── Support/                 # Helpers y utilidades
├── config/                      # Configuración (JWT, Redsys, Sanctum, DomPDF…)
├── database/
│   ├── migrations/              # 43 migraciones
│   └── seeders/                 # 11 seeders
├── despliegue/                  # Scripts de despliegue Azure
├── routes/
│   ├── api.php                  # Rutas de la API REST (~300 líneas)
│   └── web.php                  # Rutas web
├── resources/                   # Vistas Blade y assets
├── storage/                     # Logs, archivos subidos, cachés
├── .env                         # Variables de entorno (NO subir a Git)
├── composer.json                # Dependencias PHP
└── package.json                 # Dependencias Node (Vite, Bootstrap)
```

### Frontend (`Kyrema/frontend/`)

```
Kyrema/frontend/
├── src/
│   ├── app/
│   │   ├── components/          # Componentes reutilizables (modales, tablas, etc.)
│   │   ├── directives/          # Directivas personalizadas
│   │   ├── interceptors/        # Interceptor HTTP (JWT token)
│   │   ├── interfaces/          # Interfaces TypeScript
│   │   ├── pages/               # 39 páginas/vistas
│   │   │   ├── login/           # Autenticación
│   │   │   ├── society-manager/ # Gestión de sociedades
│   │   │   ├── products-manager/# Gestión de productos
│   │   │   ├── payments-manager/# Gestión de pagos
│   │   │   ├── socios-manager/  # Gestión de socios
│   │   │   ├── companies/       # Compañías aseguradoras
│   │   │   ├── polizas/         # Gestión de pólizas
│   │   │   ├── reports/         # Informes
│   │   │   ├── commissions/     # Comisiones de comerciales
│   │   │   └── ...              # Más páginas
│   │   ├── services/            # 56 servicios (HTTP, estado, lógica)
│   │   ├── styles/              # Estilos globales
│   │   ├── validators/          # Validadores de formularios
│   │   ├── app.routes.ts        # Configuración de rutas
│   │   └── auth.guard.ts        # Guard de autenticación
│   ├── assets/                  # Recursos estáticos (imágenes, iconos)
│   ├── environments/            # Configuración por entorno (dev/prod)
│   └── styles.css               # Estilos globales
├── angular.json                 # Configuración del proyecto Angular
├── package.json                 # Dependencias Node
├── server.ts                    # SSR (Server-Side Rendering)
└── tsconfig.json                # Configuración TypeScript
```

---

## 🧱 Módulos Funcionales

| Módulo                  | Backend (Controllers)                                    | Frontend (Pages)                                |
| ----------------------- | -------------------------------------------------------- | ----------------------------------------------- |
| **Autenticación**       | `AuthController`, `ForgotPasswordController`             | `login`, `reset-password`, `change-password`    |
| **Sociedades**          | `SociedadController`                                     | `society-manager`, `society-form`, `permissions` |
| **Tipos de Producto**   | `TipoProductoController`, `CampoController`              | `product-configurator`, `subproduct-manager`    |
| **Productos**           | `ProductoController`, `AnuladosController`                | `products-manager`, `product-operations`        |
| **Anexos**              | `AnexosController`, `TipoAnexoController`                | `anexos-manager`, `anexos-configurator`         |
| **Comerciales**         | `ComercialController`, `ComercialComisionController`      | `commercial-form`, `commissions`                |
| **Socios**              | `SocioController`                                        | `socios-manager`, `socio-form`, `datos-socio`   |
| **Pagos**               | `RemesaController`, `RedsysInsiteController`, `PaymentController` | `payments-manager`, `payments-and-prices` |
| **Compañías & Pólizas** | `CompaniaController`, `PolizaController`                 | `companies`, `polizas`, `polizas-form`          |
| **Tarifas**             | `TarifaProductoController`, `TarifaAnexoController`      | `tarifas`                                       |
| **Exportaciones**       | `ExportController`, `PagoExportController`                | (descarga directa desde la UI)                  |
| **Informes**            | `ExportController::getReportData`                        | `reports`                                       |
| **Categorías**          | `CategoriaController`                                    | `categories-manager`, `categories-configurator` |
| **Blob Storage**        | `BlobController`                                         | (integrado en componentes de documentos)        |
| **Notificaciones**      | `NotificationsController`                                | (emails automáticos)                            |

---

## 💳 Pasarelas de Pago

### Redsys InSite (Tarjeta de crédito/débito)

Flujo de pago Redsys InSite:

```
Frontend                     Backend                         Redsys
   │                            │                               │
   ├── POST /payments/          │                               │
   │   redsys/insite/start ────►│ Crea operación con datos Ds_* │
   │                            │◄──────────────────────────────│
   │◄── iframeAction + inputs ──│                               │
   │                            │                               │
   │   (Iframe Redsys abierto)  │                               │
   │── El usuario introduce ───►│                               │
   │   datos de tarjeta         │                               │
   │                            │   POST /payments/redsys/      │
   │                            │◄── notify (webhook) ──────────│
   │                            │   Actualiza estado del pago   │
   │                            │                               │
   │── GET /payments/{id}/status│                               │
   │◄── { estado: "pagado" } ───│                               │
```

### Stripe

Integrado mediante el paquete `stripe/stripe-php` en el backend y `ngx-stripe` + `@stripe/stripe-js` en el frontend.

### Giro Bancario (SEPA / Q19)

Generación de ficheros Q19 para remesas bancarias a través de `RemesaController`, con descarga de ficheros XML para presentar al banco.

---

## 🔧 Comandos Útiles

### Backend

| Comando                           | Descripción                                    |
| --------------------------------- | ---------------------------------------------- |
| `php artisan serve`               | Inicia el servidor de desarrollo               |
| `php artisan migrate`             | Ejecuta las migraciones pendientes             |
| `php artisan migrate:rollback`    | Revierte la última migración                   |
| `php artisan db:seed`             | Ejecuta los seeders                            |
| `php artisan cache:clear`         | Limpia la caché de la aplicación               |
| `php artisan config:clear`        | Limpia la caché de configuración               |
| `php artisan route:clear`         | Limpia la caché de rutas                       |
| `php artisan view:clear`          | Limpia la caché de vistas                      |
| `php artisan route:list`          | Lista todas las rutas registradas              |
| `php artisan queue:work`          | Inicia el worker de colas                      |
| `php artisan jwt:secret`          | Genera un nuevo JWT secret                     |
| `php artisan key:generate`        | Genera la clave de la aplicación               |
| `php artisan tinker`              | Abre un REPL interactivo de Laravel            |

### Frontend

| Comando                             | Descripción                             |
| ----------------------------------- | --------------------------------------- |
| `ng serve`                          | Inicia el servidor de desarrollo        |
| `ng build`                          | Genera el build de producción           |
| `ng build --configuration=development` | Genera el build de desarrollo        |
| `ng generate component nombre`      | Genera un nuevo componente              |
| `ng generate service nombre`        | Genera un nuevo servicio                |
| `ng test`                           | Ejecuta los tests unitarios (Karma)     |
| `npm run serve:ssr:frontend`        | Sirve la app con SSR                    |

---

## ☁️ Despliegue en Azure

El backend se despliega en **Azure App Service** (Linux). Los scripts de despliegue están en la carpeta `despliegue/`:

| Archivo          | Descripción                                                   |
| ---------------- | ------------------------------------------------------------- |
| `startup.txt`    | Script de arranque: migraciones, cachés, supervisor, nginx    |
| `default.txt`    | Configuración de nginx                                        |
| `phpini.txt`     | Configuración personalizada de `php.ini` (timezone, sqlsrv)   |

### Flujo de despliegue

1. El código se sube a Azure App Service.
2. `startup.sh` ejecuta automáticamente:
   - Instalación de extensiones GD (imágenes).
   - Configuración de nginx y supervisor (queue worker).
   - `php artisan down` → migraciones → cachés → `php artisan up`.
   - Inicio del queue worker en segundo plano.

---

## ❓ Solución de Problemas

### Error de conexión a SQL Server

- Verifica que el servicio `SQL Server (SQLEXPRESS)` esté corriendo.
- Asegúrate de que **TCP/IP** esté habilitado y el puerto `1433` esté configurado en SQL Server Configuration Manager.
- Comprueba que los datos de `.env` (`DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`) sean correctos.
- Verifica las extensiones: `php -m | findstr sqlsrv` (en Windows).
- Si usas `DB_ENCRYPT=true`, asegúrate de tener `DB_TRUST_SERVER_CERTIFICATE=true` para desarrollo local.

### Error `php artisan serve` no arranca

- Verifica que PHP 8.2+ esté instalado: `php -v`.
- Comprueba que `composer install` se haya ejecutado sin errores.
- Asegúrate de que el archivo `.env` existe y tiene `APP_KEY` configurada.

### Error `ng serve` no arranca

- Verifica que Node.js 18+ esté instalado: `node -v`.
- Verifica Angular CLI: `ng version`.
- Borra y reinstala: `Remove-Item -Recurse node_modules; npm install`.

### El frontend no conecta con el backend

- Verifica que `environment.ts` apunte a `http://localhost:8000/api`.
- Asegúrate de que el backend esté corriendo en el puerto 8000.
- Revisa la configuración de **CORS** en `config/cors.php` del backend.

### Error con Azure Blob Storage / Azurite

- Verifica que Azurite esté corriendo: `azurite --silent`.
- Asegúrate de que el contenedor `canama` existe.
- Verifica que las variables `AZURE_STORAGE_*` en `.env` apunten a Azurite (`127.0.0.1:10000`).

### Error JWT / Autenticación

- Regenera el secret: `php artisan jwt:secret`.
- Limpia la caché de configuración: `php artisan config:clear`.
- Verifica que el archivo `config/jwt.php` existe.

### Emails no se envían

- En desarrollo local puedes usar `MAIL_MAILER=log` para ver los emails en `storage/logs/laravel.log`.
- Para envío real, solicita las credenciales SMTP al administrador.

---

## 📝 Notas Adicionales

- **Autenticación dual**: El sistema soporta login de usuarios comerciales/admin (JWT + Sanctum) y login de socios (`loginSocio`), cada uno con su propio flujo.
- **Multi-tenant por Categoría**: Cada categoría puede tener su propio logo y configuración, permitiendo servir a múltiples marcas desde la misma plataforma.
- **SSR (Server-Side Rendering)**: El frontend soporta SSR para producción (desactivado en desarrollo por defecto: `"ssr": false` en `angular.json`).
- **Queue Worker**: Muchas operaciones (emails, notificaciones, generación de remesas) se ejecutan en background — asegúrate de tener `php artisan queue:work` activo o un supervisor configurado.
- **Health Check**: Endpoint disponible en `GET /api/health` para monitoreo.
- **Principales librerías del frontend**:
  - [Angular Material](https://material.angular.io/) — Componentes UI.
  - [AG Grid](https://www.ag-grid.com/) — Tablas avanzadas con filtros, ordenación y agrupación.
  - [ngx-stripe](https://github.com/richnologies/ngx-stripe) — Integración con Stripe.
  - [jsPDF](https://github.com/parallax/jsPDF) — Generación de PDFs en cliente.
  - [ExcelJS](https://github.com/exceljs/exceljs) — Generación de hojas Excel en cliente.
  - [file-saver](https://github.com/eligrey/FileSaver.js) — Descarga de archivos generados.
  - [ng-select](https://github.com/ng-select/ng-select) — Selects avanzados con búsqueda.

---

<p align="center">
  Desarrollado por <strong><a href="https://github.com/KongSoftwareSpain">Kong Software Spain</a></strong>
</p>
