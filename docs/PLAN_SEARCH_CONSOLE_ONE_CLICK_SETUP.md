# Plan: Search Console One-Click Setup

**Objetivo**: Unificar todo el flujo de Search Console (agregar web, crear archivo de verificacion, verificar sitio, enviar sitemaps) en un solo paso desde el plugin de WordPress.

**Estado**: Draft
**Fecha**: 2026-03-25

---

## 1. Situacion Actual

### Flujo Manual (3 pasos con recarga de pagina)

```
Usuario ve panel "Add Website"
    ↓ Click "Add to Search Console"
    ↓ API call: action=add → obtiene token + file_name
    ↓ API call: action=sitemaps → envia sitemaps
    ↓ Redirect → panel "Verification Pending"

Usuario ve panel "Verification Pending"
    ↓ Click "Create File Automatically"
    ↓ Crea archivo HTML en document root
    ↓ Redirect → panel sigue en "Verification Pending"

Usuario ve panel "Verification Pending"
    ↓ Click "Verify Website"
    ↓ API call: action=verify → Google verifica el archivo
    ↓ Redirect → panel "Verified"
```

**Problema**: 3 clics + 3 recargas para algo que puede ser 1 clic.

### Componente clave que ya existe

`SearchConsoleSetupService::activateSearchConsole()` en `services/setup/SearchConsoleSetupService.php` ya orquesta el flujo completo:

```php
// 1. API call: action=add → registra sitio, obtiene token
// 2. Local: crea archivo de verificacion en document root
// 3. API call: action=verify → Google verifica el archivo
// 4. API call: action=sitemaps → envia sitemaps descubiertos
```

Solo se usa en el AI Site Generator. No esta conectado al panel manual de Search Console.

---

## 2. Cambios

### 2.1 Form Handler: nueva accion `contai_setup_search_console`

**Archivo**: `includes/admin/apps/handlers/SearchConsoleFormHandler.php`

Agregar accion que delega al `SearchConsoleSetupService` existente:

```php
if (isset($_POST['contai_setup_search_console'])) {
    $this->handleOneClickSetup();
}

private function handleOneClickSetup(): void
{
    $setupService = new ContaiSearchConsoleSetupService(
        $this->websiteProvider,
        $this->service
    );

    $result = $setupService->activateSearchConsole();

    if (!$result['success']) {
        $errorMsg = implode('. ', $result['errors']);
        $this->redirectWithMessage('error', $errorMsg);
        return;
    }

    $stepsMsg = implode(' → ', $result['steps']);
    $this->redirectWithMessage('success', $stepsMsg);
}
```

Eliminar `handleAddWebsite()` — queda reemplazado por `handleOneClickSetup()`. Las acciones manuales individuales (verify, create file, disconnect, delete) se mantienen porque el panel de VerificationSection las usa como fallback cuando el usuario quedo en estado intermedio.

### 2.2 UI: boton unico en AddWebsiteSection

**Archivo**: `includes/admin/apps/panels/search-console/AddWebsiteSection.php`

Cambiar el form para enviar `contai_setup_search_console` en vez de `contai_add_website`. Agregar lista de pasos que se ejecutaran:

```
┌──────────────────────────────────────────────┐
│ Connect to Search Console                     │
│                                               │
│ Website URL: https://example.com              │
│ Sitemaps: sitemap.xml                         │
│                                               │
│ This will automatically:                      │
│  1. Register your site with Search Console    │
│  2. Create the verification file              │
│  3. Verify your website ownership             │
│  4. Submit your sitemaps                      │
│                                               │
│ [Connect to Search Console]                   │
└──────────────────────────────────────────────┘
```

---

## 3. Flujo Resultante

```
Usuario ve panel "Connect to Search Console"
    ↓ Click "Connect to Search Console"
    ↓ SearchConsoleSetupService::activateSearchConsole()
    ↓   1. API action=add → registra sitio, guarda config
    ↓   2. WP_Filesystem → crea google{token}.html
    ↓   3. API action=verify → Google verifica el archivo
    ↓   4. API action=sitemaps → envia sitemaps
    ↓ Redirect → panel "Verified"
```

1 clic + 1 recarga.

---

## 4. Consideraciones

### Timeout

3 llamadas API secuenciales + 1 escritura local: ~5-15s total. Dentro del timeout de PHP (30s) y de la API (120s).

### Permisos de Filesystem

`createVerificationFile()` usa `WP_Filesystem`. Si el servidor requiere credenciales FTP (raro), el archivo no se crea y el flujo falla en step 2. El usuario queda en estado "agregado pero sin verificar" → el panel muestra VerificationSection con los botones manuales existentes (Create File, Verify) como fallback natural.

### Error Recovery

No se necesita logica nueva. Los estados intermedios ya tienen UI:

| Fallo en | Estado | Panel que ve el usuario |
|---|---|---|
| Step 1 (add) | Sin datos | AddWebsiteSection → puede reintentar |
| Step 2 (file) | Agregado sin archivo | VerificationSection → boton "Create File" |
| Step 3 (verify) | Archivo existe, no verificado | VerificationSection → boton "Verify" |
| Step 4 (sitemaps) | Verificado sin sitemaps | VerifiedSection (sitemaps pending) |

### No se necesitan cambios en la API

La API ya soporta las 4 acciones por separado con idempotencia. La orquestacion vive en el plugin porque el paso de crear el archivo es local.

---

## 5. Archivos a Modificar

| # | Archivo | Cambio |
|---|---|---|
| 1 | `admin/apps/handlers/SearchConsoleFormHandler.php` | Agregar `handleOneClickSetup()`, eliminar `handleAddWebsite()`, require del setup service |
| 2 | `admin/apps/panels/search-console/AddWebsiteSection.php` | Cambiar boton a `contai_setup_search_console`, agregar lista de pasos |
| 3 | `tests/unit/admin/apps/handlers/SearchConsoleFormHandlerTest.php` | Agregar test para `handleOneClickSetup` (success y error) |

3 archivos.

---

## 6. Fuera de Scope

- Cambios en la API
- Refactor del `SearchConsoleSetupService` (ya funciona)
- AJAX con progress bar (el form POST es suficiente para ~10s de espera)
- Cambios en VerificationSection (ya sirve como fallback)
