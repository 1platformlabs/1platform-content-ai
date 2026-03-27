# Plan: Publisuites One-Click Setup

**Objetivo**: Unificar todo el flujo de Publisuites (agregar sitio, crear archivo de verificacion, verificar sitio) en un solo paso desde el plugin de WordPress, igual que se hizo con Search Console.

**Estado**: Draft
**Fecha**: 2026-03-26

**Skills a invocar**: `wordpress-plugin-core`, `web-design-guidelines`

---

## 1. Contexto del Proyecto

### Stack

- **Plugin**: 1Platform Content AI (WordPress, PHP 7.4+)
- **API**: 1Platform API (FastAPI, Python 3.14+, MongoDB/Beanie)
- **Patron de referencia**: `SearchConsoleSetupService::activateSearchConsole()` — flujo 1-click ya implementado para Search Console
- **Test framework**: PHPUnit 9.6+ con WP_Mock + Mockery

### Estado Actual

#### Flujo Manual (3 pasos con recarga de pagina)

```
Usuario ve panel "Connect" (ConnectSection.php)
    ↓ Click "Connect to Publisuites" (contai_connect_publisuites)
    ↓ PublisuitesFormHandler::handleConnect()
    ↓ API call: action=add → obtiene publisuites_id + verification_file_name + verification_file_content
    ↓ savePublisuitesConfig() → guarda en wp_options
    ↓ Redirect → panel "Verification Pending" (VerificationSection.php)

Usuario ve panel "Verification Pending"
    ↓ Click "Create File Automatically" (contai_create_verification_file)
    ↓ PublisuitesFormHandler::handleCreateVerificationFile()
    ↓ WP_Filesystem → crea archivo HTML en ABSPATH
    ↓ Redirect → panel sigue en "Verification Pending"

Usuario ve panel "Verification Pending"
    ↓ Click "Verify Website" (contai_verify_publisuites)
    ↓ PublisuitesFormHandler::handleVerify()
    ↓ API call: action=verify → Publisuites verifica el archivo
    ↓ Redirect → panel "Connected" (ConnectedSection.php) o "Pending" (si fallo)
```

**Problema**: 3 clics + 3 recargas para algo que puede ser 1 clic.

### Componentes clave que ya existen

| Componente | Ubicacion | Proposito |
|---|---|---|
| `ContaiPublisuitesService::connectWebsite()` | `services/publisuites/PublisuitesService.php` | API call `action=add` → retorna `ContaiOnePlatformResponse` |
| `ContaiPublisuitesService::createVerificationFile()` | `services/publisuites/PublisuitesService.php` | Crea archivo en `ABSPATH` via `WP_Filesystem` → retorna `['success' => bool, 'message' => string]` |
| `ContaiPublisuitesService::verifyWebsite()` | `services/publisuites/PublisuitesService.php` | API call `action=verify` → retorna `ContaiOnePlatformResponse` |
| `ContaiPublisuitesService::savePublisuitesConfig()` | `services/publisuites/PublisuitesService.php` | Guarda config en `contai_publisuites_config` (wp_options) |
| `ContaiPublisuitesService::getPublisuitesConfig()` | `services/publisuites/PublisuitesService.php` | Lee config de wp_options |
| `ContaiPublisuitesFormHandler` | `admin/apps/handlers/PublisuitesFormHandler.php` | Maneja 4 acciones separadas: `contai_connect_publisuites`, `contai_verify_publisuites`, `contai_create_verification_file`, `contai_disconnect_publisuites` |
| `ContaiSearchConsoleSetupService` | `services/setup/SearchConsoleSetupService.php` | **Patron a seguir** — orquesta add → file → verify en 1 paso |
| `ContaiSearchConsoleFormHandler::handleSetup()` | `admin/apps/handlers/SearchConsoleFormHandler.php` | **Patron a seguir** — handler que delega al setup service |
| `PublisuitesService` (API) | `app/services/publisuites/publisuites_service.py` | Backend: add y verify con idempotencia |
| `PublisuitesProvider` (API) | `app/services/providers/publisuites/publisuites_provider.py` | Backend: login, step2, step3, download, verify |

### ⚠️ Clases existentes de test a reusar

| Test existente | Ubicacion | Patrones reutilizables |
|---|---|---|
| `PublisuitesFormHandlerTest` | `tests/unit/admin/apps/handlers/PublisuitesFormHandlerTest.php` | `mockValidRequest()`, `expectRedirect()`, `PublisuitesRedirectException`, fixtures de nonce/capability |
| `SearchConsoleFormHandlerTest` | `tests/unit/admin/apps/handlers/SearchConsoleFormHandlerTest.php` | Tests de `handleSetup()` — `test_setup_success_redirects_with_success_message`, `test_setup_failure_at_add_redirects_with_error`, etc. |

### Decisiones de Diseno Resueltas

1. **La orquestacion vive en el plugin**, no en la API — porque el paso de crear el archivo de verificacion es local (WP_Filesystem)
2. **No se necesitan cambios en la API** — la API ya soporta `add` y `verify` con idempotencia
3. **El patron es identico a Search Console** — nuevo `PublisuitesSetupService` que orquesta: add → create file → verify
4. **Form POST sincrono** — no AJAX. El flujo completo toma ~5-15s, dentro del timeout de PHP (30s)
5. **Error recovery via UI existente** — los estados intermedios ya tienen paneles con botones manuales
6. ⚠️ **No eliminar `handleConnect()`** — mantenerlo como fallback. Solo agregar `handleOneClickSetup()` como nueva accion. Razon: si un usuario quedo en estado intermedio (connect hecho pero no verificado), el boton de VerificationSection podria necesitar re-connect
7. ⚠️ **El handler usa `handleSetup()`** no `handleOneClickSetup()` — para consistencia con `SearchConsoleFormHandler::handleSetup()`
8. ⚠️ **El require del setup service va en el archivo**, no dentro del metodo — para consistencia con `SearchConsoleFormHandler` que hace `require_once` al inicio del archivo

---

## 2. Requerimientos Funcionales

1. **RF-01**: El usuario debe poder conectar, crear archivo y verificar su sitio con 1 solo clic
2. **RF-02**: Si algun paso falla, el usuario queda en un estado intermedio valido con acciones manuales de fallback
3. **RF-03**: Si el sitio ya esta agregado (idempotencia de API), el flujo continua desde donde quedo
4. **RF-04**: El boton debe mostrar claramente los pasos que se ejecutaran automaticamente
5. ⚠️ **RF-05**: Las acciones manuales individuales (connect, verify, create file, disconnect) deben seguir funcionando sin cambios

---

## 3. Cambios

### 3.1 Nuevo servicio: `PublisuitesSetupService`

**Archivo**: `includes/services/setup/PublisuitesSetupService.php`

Orquesta el flujo completo igual que `SearchConsoleSetupService`:

```php
<?php

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../publisuites/PublisuitesService.php';

class ContaiPublisuitesSetupService
{
    private ContaiPublisuitesService $publisuiteService;

    public function __construct(
        ?ContaiPublisuitesService $publisuiteService = null
    ) {
        $this->publisuiteService = $publisuiteService ?? new ContaiPublisuitesService();
    }

    public function activatePublisuites(): array
    {
        $results = [
            'success' => true,
            'steps' => [],
            'errors' => []
        ];

        try {
            // Step 1: Add website to marketplace (API call action=add)
            $connectResponse = $this->publisuiteService->connectWebsite();
            if (!$connectResponse->isSuccess()) {
                throw new Exception('Failed to register website: ' . $connectResponse->getMessage());
            }

            $data = $connectResponse->getData();
            $this->publisuiteService->savePublisuitesConfig([
                'publisuites_id' => $data['publisuites_id'] ?? '',
                'verification_file_name' => $data['verification_file_name'] ?? '',
                'verification_file_content' => $data['verification_file_content'] ?? '',
                'status' => 'pending_verification',
                'verified' => false,
            ]);
            $results['steps'][] = 'Website registered in marketplace';

            // Step 2: Create verification file in WordPress root
            $fileResult = $this->publisuiteService->createVerificationFile();
            if (!$fileResult['success']) {
                throw new Exception('Failed to create verification file: ' . $fileResult['message']);
            }
            $results['steps'][] = 'Verification file created';

            // Step 3: Verify website ownership (API call action=verify)
            $verifyResponse = $this->publisuiteService->verifyWebsite();
            if (!$verifyResponse->isSuccess()) {
                throw new Exception('Failed to verify website: ' . $verifyResponse->getMessage());
            }

            $verifyData = $verifyResponse->getData();
            $config = $this->publisuiteService->getPublisuitesConfig();
            if ($config) {
                $config['verified'] = $verifyData['verified'] ?? false;
                $config['verifiedAt'] = $verifyData['verified_at'] ?? null;
                $config['status'] = ($verifyData['verified'] ?? false) ? 'active' : 'pending_verification';
                $this->publisuiteService->savePublisuitesConfig($config);
            }
            $results['steps'][] = 'Website verified';

        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
            return $results;
        }

        return $results;
    }
}
```

### 3.2 Form Handler: nueva accion `contai_setup_publisuites`

**Archivo**: `includes/admin/apps/handlers/PublisuitesFormHandler.php`

⚠️ **Cambios respecto al plan original:**
- El `require_once` del setup service va al inicio del archivo (no dentro del metodo)
- El metodo se llama `handleSetup()` (no `handleOneClickSetup()`) para consistencia con `SearchConsoleFormHandler`
- `handleConnect()` NO se elimina — se mantiene como fallback

```php
// Al inicio del archivo, agregar:
require_once __DIR__ . '/../../../services/setup/PublisuitesSetupService.php';

// En handleRequest(), agregar ANTES del check de contai_connect_publisuites:
if (isset($_POST['contai_setup_publisuites'])) {
    $this->handleSetup();
}

// Nuevo metodo privado:
private function handleSetup(): void
{
    $setupService = new ContaiPublisuitesSetupService($this->service);
    $result = $setupService->activatePublisuites();

    if (!$result['success']) {
        $errorMsg = implode('. ', $result['errors']);
        $this->redirectWithMessage('error', $errorMsg);
        return;
    }

    $this->redirectWithMessage(
        'success',
        __('Website connected to marketplace successfully', '1platform-content-ai')
    );
}
```

⚠️ **Nota**: El mensaje de success usa `__()` para i18n, no `implode()` de steps. Esto es consistente con `SearchConsoleFormHandler::handleSetup()` que usa un mensaje fijo traducible, no la lista de steps.

### 3.3 UI: boton unico en ConnectSection

**Archivo**: `includes/admin/apps/panels/publisuites/ConnectSection.php`

Cambiar el form action de `contai_connect_publisuites` a `contai_setup_publisuites`. Agregar lista de pasos:

```
┌──────────────────────────────────────────────┐
│ Connect to Link Building                     │
│                                              │
│ Website URL: https://example.com             │
│                                              │
│ This will automatically:                     │
│  1. Register your site in the marketplace    │
│  2. Create the verification file             │
│  3. Verify your website ownership            │
│                                              │
│ [Connect to Marketplace]                     │
└──────────────────────────────────────────────┘
```

⚠️ **Detalle de implementacion**: El `ConnectSection.php` actual usa `$view_data['primary_cta_action']` como nombre del boton. El cambio es solo en `PublisuitesPanel.php` donde se define el `primary_cta_action`:

```php
// En PublisuitesPanel.php, cambiar:
'primary_cta_action' => 'contai_setup_publisuites',  // era 'contai_connect_publisuites'
'primary_cta_label'  => __('Connect to Marketplace', '1platform-content-ai'),  // era 'Connect to Publisuites'
```

### ⚠️ 3.4 Form Handler: limpiar tearDown de tests

**Archivo**: `tests/unit/admin/apps/handlers/PublisuitesFormHandlerTest.php`

Agregar `contai_setup_publisuites` al `tearDown()`:

```php
public function tearDown(): void
{
    unset(
        $_POST['contai_publisuites_nonce'],
        $_POST['contai_connect_publisuites'],
        $_POST['contai_verify_publisuites'],
        $_POST['contai_disconnect_publisuites'],
        $_POST['contai_create_verification_file'],
        $_POST['contai_setup_publisuites']  // ⚠️ NUEVO
    );
    WP_Mock::tearDown();
    Mockery::close();
    parent::tearDown();
}
```

---

## 4. Flujo Resultante

```
Usuario ve panel "Connect to Link Building" (ConnectSection.php)
    ↓ Click "Connect to Marketplace" (contai_setup_publisuites)
    ↓ PublisuitesFormHandler::handleSetup()
    ↓   → PublisuitesSetupService::activatePublisuites()
    ↓     1. connectWebsite() → API action=add → obtiene archivo de verificacion
    ↓     2. savePublisuitesConfig() → guarda en wp_options
    ↓     3. createVerificationFile() → WP_Filesystem → crea publisuites-verify-{token}.html
    ↓     4. verifyWebsite() → API action=verify → Publisuites verifica el archivo
    ↓     5. savePublisuitesConfig() → actualiza verified=true, status=active
    ↓ Redirect → panel "Connected" (ConnectedSection.php)
```

1 clic + 1 recarga.

---

## 5. Error Recovery

No se necesita logica nueva. Los estados intermedios ya tienen UI:

| Fallo en | Estado wp_options | Panel que ve el usuario | Fallback disponible |
|---|---|---|---|
| Step 1 (connect) | Sin datos | ConnectSection → puede reintentar con mismo boton | El boton reintenta todo el flujo |
| Step 2 (create file) | `publisuites_id` guardado, `verified=false` | VerificationSection → boton "Create File" + "Verify" | Acciones manuales individuales |
| Step 3 (verify) | Archivo existe, `verified=false` | VerificationSection → boton "Verify" | Accion manual de verify |

La API tiene idempotencia en ambos endpoints:
- `add`: si ya tiene `publisuites_id`, retorna datos existentes (no duplica registro en Publisuites)
- `verify`: si ya esta verificado, retorna resultado cacheado con `status=already_verified`

⚠️ **Edge case: fallo entre step 1 y step 2** — `publisuites_id` ya fue guardado pero el archivo no se creo. En este caso, el panel muestra `VerificationSection` con dos botones: "Create File" y "Verify". El usuario puede crear el archivo manualmente y luego verificar. Si el usuario hace clic en "Connect to Marketplace" de nuevo, la API retorna `already_added` con los datos existentes → el setup service continua al step 2.

---

## 6. Restricciones Tecnicas

1. **NO crear endpoints nuevos en la API** — los existentes (`action=add` y `action=verify`) son suficientes
2. **NO usar AJAX** — form POST sincrono es suficiente para ~5-15s
3. **NO exponer nombre "Publisuites"** en la UI — usar "Link Building" o "Marketplace"
4. **NO romper acciones manuales existentes** — verify, create file y disconnect siguen funcionando como fallback
5. ⚠️ **NO eliminar `handleConnect()`** — mantenerlo por backward compat y fallback de VerificationSection

---

## 7. Patrones a Seguir

- Mismo patron que `SearchConsoleSetupService`: constructor con DI opcional, metodo `activate*()` que retorna `['success', 'steps', 'errors']`
- Mismo patron de `SearchConsoleFormHandler::handleSetup()`: delega al setup service, usa `__()` para mensaje de exito
- Usar `WP_Filesystem` para crear archivos (nunca `file_put_contents`)
- Verificar nonce + `current_user_can('manage_options')` antes de procesar (ya lo hace `handleRequest()`)
- ⚠️ El `require_once` del setup service va al inicio del archivo handler, igual que en `SearchConsoleFormHandler` (linea 7)
- ⚠️ Test fixtures reusan `mockValidRequest()` y `expectRedirect()` del `PublisuitesFormHandlerTest` existente

---

## 8. Tests

### 8.1 Unit Tests — `PublisuitesSetupService`

**Archivo**: `tests/unit/services/setup/PublisuitesSetupServiceTest.php`

⚠️ **Namespace**: `ContAI\Tests\Unit\Services\Setup` (consistente con estructura existente)

```
class PublisuitesSetupServiceTest extends TestCase

TestActivatePublisuites:
├── test_activate_success_completes_three_steps
│   Setup: Mock service con connect→success(data con publisuites_id, file_name, file_content),
│          createFile→['success'=>true], verify→success(data con verified=true, verified_at)
│   Action: activatePublisuites()
│   Assert: success=true, count(steps)==3, errors vacio
│   Assert: savePublisuitesConfig llamado 2 veces (post-connect y post-verify)
│   Assert: getPublisuitesConfig llamado 1 vez (en post-verify)
│
├── test_activate_connect_fails_stops_flow
│   Setup: Mock connect→failure(message='API error', status=502)
│   Action: activatePublisuites()
│   Assert: success=false, steps vacio, errors contiene "Failed to register website: API error"
│   Assert: createVerificationFile NO llamado
│   Assert: verifyWebsite NO llamado
│
├── test_activate_create_file_fails_stops_at_step2
│   Setup: Mock connect→success, createFile→['success'=>false, 'message'=>'Permission denied']
│   Action: activatePublisuites()
│   Assert: success=false, count(steps)==1, errors contiene "Failed to create verification file: Permission denied"
│   Assert: verifyWebsite NO llamado
│   Assert: savePublisuitesConfig llamado 1 vez (post-connect)
│
├── test_activate_verify_fails_stops_at_step3
│   Setup: Mock connect→success, createFile→success, verify→failure(message='Verification pending')
│   Action: activatePublisuites()
│   Assert: success=false, count(steps)==2, errors contiene "Failed to verify website: Verification pending"
│
├── test_activate_idempotent_add_continues_flow
│   Setup: Mock connect→success con data con status="already_added"
│          (publisuites_id presente, verification data presente)
│   Action: activatePublisuites()
│   Assert: Flujo completa los 3 steps sin error
│
├── test_activate_saves_config_with_correct_data_after_connect
│   Setup: Mock connect→success con data={publisuites_id:'ps-123', verification_file_name:'verify.html', verification_file_content:'<html>verify</html>'}
│   Action: activatePublisuites()
│   Assert: savePublisuitesConfig llamado con:
│       publisuites_id='ps-123', verification_file_name='verify.html',
│       verification_file_content='<html>verify</html>',
│       status='pending_verification', verified=false
│
└── test_activate_updates_config_to_active_after_verify
    Setup: Mock completo hasta verify→success(verified=true, verified_at='2026-03-26T10:00:00Z')
           Mock getPublisuitesConfig→config existente con publisuites_id
    Action: activatePublisuites()
    Assert: Segundo savePublisuitesConfig llamado con verified=true, status='active', verifiedAt='2026-03-26T10:00:00Z'
```

### 8.2 Unit Tests — Form Handler (extender existente)

**Archivo**: `tests/unit/admin/apps/handlers/PublisuitesFormHandlerTest.php`

⚠️ **Agregar tests al archivo existente**, no crear archivo nuevo. Reusar `mockValidRequest()`, `expectRedirect()`, `PublisuitesRedirectException`.

```
// Agregar al bloque de tests existente:

├── test_setup_success_redirects_with_success_message
│   Setup: mockValidRequest('contai_setup_publisuites')
│          Mock PublisuitesSetupService via constructor injection o patch
│          → activatePublisuites() returns ['success'=>true, 'steps'=>['Step1','Step2','Step3'], 'errors'=>[]]
│   Action: handler.handleRequest()
│   Assert: Redirect con contai_ps_type=success
│   Assert: Redirect URL contiene "connected to marketplace"
│
├── test_setup_failure_redirects_with_error_message
│   Setup: mockValidRequest('contai_setup_publisuites')
│          → activatePublisuites() returns ['success'=>false, 'steps'=>['Step1'], 'errors'=>['Failed to create verification file']]
│   Action: handler.handleRequest()
│   Assert: Redirect con contai_ps_type=error
│   Assert: Redirect URL contiene "Failed to create verification file"
│
├── test_setup_does_not_break_existing_connect_action
│   Setup: mockValidRequest('contai_connect_publisuites')
│          Mock connectWebsite→success
│   Action: handler.handleRequest()
│   Assert: handleConnect() se ejecuta normalmente (backward compat)
│
└── test_setup_does_not_break_existing_verify_action
    Setup: mockValidRequest('contai_verify_publisuites')
           Mock verifyWebsite→success
    Action: handler.handleRequest()
    Assert: handleVerify() se ejecuta normalmente (backward compat)
```

⚠️ **Nota sobre inyeccion del setup service en tests**: El handler actual crea el setup service internamente (`new ContaiPublisuitesSetupService($this->service)`). Para testearlo, hay dos opciones:
1. **Opcion A (preferida)**: El setup service recibe el `PublisuitesService` mock que ya se inyecta en el handler → los mocks del service controlan el flujo
2. **Opcion B**: Hacer el setup service inyectable en el handler via constructor (pero rompe consistencia con `SearchConsoleFormHandler`)

→ Usar **Opcion A**: el `PublisuitesSetupService` usa el mismo `$this->service` que ya esta mockeado. Los tests del handler controlan el resultado mockendo `connectWebsite()`, `createVerificationFile()`, `verifyWebsite()` en el service mock.

---

## 9. Consideraciones

### Timeout

2 llamadas API secuenciales + 1 escritura local: ~5-15s total. Dentro del timeout de PHP (30s) y de la API (180s configurado en el plugin). El step 1 (add) es el mas lento (~5-10s) porque Publisuites extrae metricas SEO del sitio.

### Permisos de Filesystem

`createVerificationFile()` usa `WP_Filesystem`. Si el servidor requiere credenciales FTP (raro en hosting moderno), el archivo no se crea y el flujo falla en step 2. El usuario queda en estado "conectado pero sin verificar" → el panel muestra VerificationSection con los botones manuales existentes como fallback.

### Idempotencia

Si el usuario hace clic dos veces o recarga:
- **API `add`**: retorna datos existentes si ya tiene `publisuites_id` (no duplica registro)
- **File creation**: sobreescribe archivo si ya existe (`WP_Filesystem::put_contents` es idempotente)
- **API `verify`**: retorna `already_verified` si ya fue verificado

### ⚠️ Propagacion de errores API

La API retorna distintos HTTP status segun el error:

| Error | HTTP Status | Impacto en plugin |
|---|---|---|
| `PublisuitesCredentialsNotFoundError` | 400 | `isSuccess()=false`, usuario no tiene credenciales configuradas |
| `PublisuitesWebsiteNotFoundError` | 404 | `isSuccess()=false`, website ID invalido |
| `PublisuitesVerificationPendingError` | 400 | `isSuccess()=false`, verificacion aun pendiente (archivo no encontrado por Publisuites) |
| `PublisuitesServiceError` | 502 | `isSuccess()=false`, error externo de Publisuites |
| Exception generica | 500 | `isSuccess()=false`, error inesperado |

Todos estos retornan `isSuccess()=false` en `ContaiOnePlatformResponse`, lo cual hace que el setup service lance `Exception` y detenga el flujo. El mensaje de error de la API se propaga al usuario via el flash message.

### ⚠️ Estado de credenciales

La API requiere que el usuario tenga credenciales de Publisuites configuradas (`user.keys.publisuites.email` y `user.keys.publisuites.password`). Si no las tiene, la API retorna 400 con "Publisuites credentials not configured". Esto ya esta manejado por `PublisuitesCredentialsNotFoundError` → el plugin muestra el error. No se necesita logica adicional.

---

## 10. Archivos a Crear / Modificar

| # | Archivo | Cambio | Proyecto |
|---|---|---|---|
| 1 | `includes/services/setup/PublisuitesSetupService.php` | **CREAR** — Servicio de orquestacion 1-click (patron identico a `SearchConsoleSetupService`) | Plugin |
| 2 | `includes/admin/apps/handlers/PublisuitesFormHandler.php` | **MODIFICAR** — Agregar `require_once` del setup service + agregar `handleSetup()` + agregar check de `contai_setup_publisuites` en `handleRequest()` | Plugin |
| 3 | `includes/admin/apps/panels/publisuites/ConnectSection.php` | **MODIFICAR** — Agregar lista de pasos automaticos (texto descriptivo) | Plugin |
| 4 | ⚠️ `includes/admin/apps/panels/PublisuitesPanel.php` | **MODIFICAR** — Cambiar `primary_cta_action` a `contai_setup_publisuites` y `primary_cta_label` a "Connect to Marketplace" | Plugin |
| 5 | `tests/unit/services/setup/PublisuitesSetupServiceTest.php` | **CREAR** — Tests del setup service (7 tests) | Plugin |
| 6 | `tests/unit/admin/apps/handlers/PublisuitesFormHandlerTest.php` | **MODIFICAR** — Agregar 4 tests de setup + agregar `contai_setup_publisuites` al tearDown | Plugin |

⚠️ 6 archivos plugin (no 5). Se agrego `PublisuitesPanel.php` que es donde realmente se define el `primary_cta_action`.

### ⚠️ Cambios en la API (ya aplicados)

| # | Archivo | Cambio | Proyecto |
|---|---|---|---|
| 7 | `app/services/publisuites/publisuites_service.py` | **MODIFICADO** — Agregar `verification_file_content` a respuesta idempotente `already_added` | API |
| 8 | `tests/unit/services/test_publisuites_service.py` | **MODIFICADO** — Agregar test `test_add_website_already_added_returns_existing_data_with_file_content` | API |

**Estado**: Ya aplicados y tests pasando (8/8).

---

## 11. Plan de Implementacion

1. **Crear `PublisuitesSetupService.php`** en `includes/services/setup/` — copiar estructura de `SearchConsoleSetupService`, adaptar a Publisuites
2. **Modificar `PublisuitesFormHandler.php`** — agregar `require_once` al inicio, agregar `handleSetup()`, agregar check en `handleRequest()`
3. **Modificar `PublisuitesPanel.php`** — cambiar `primary_cta_action` y `primary_cta_label` para el estado `not_connected`
4. **Modificar `ConnectSection.php`** — agregar lista de pasos automaticos debajo del hero text
5. **Crear `PublisuitesSetupServiceTest.php`** en `tests/unit/services/setup/` — 7 tests unitarios
6. **Modificar `PublisuitesFormHandlerTest.php`** — agregar `contai_setup_publisuites` al tearDown + 4 tests nuevos
7. **Ejecutar tests** — `composer test` para verificar que tests existentes no se rompen
8. **Test manual** — verificar flujo completo en Local by Flywheel:
   - ⚠️ Verificar que 1-click funciona en estado limpio (sin config previa)
   - ⚠️ Verificar que 1-click funciona con config parcial (idempotencia)
   - ⚠️ Verificar que acciones manuales individuales siguen funcionando
   - ⚠️ Verificar que el error se muestra correctamente cuando falla un paso

---

## 12. Criterios de Aceptacion

- [ ] El usuario puede conectar, crear archivo y verificar con 1 solo clic
- [ ] Si el sitio ya esta agregado (idempotencia), el flujo continua sin error
- [ ] Si falla un paso, el usuario ve el error descriptivo y puede completar manualmente con acciones de fallback
- [ ] Las acciones manuales existentes (connect, verify, create file, disconnect) siguen funcionando sin cambios
- [ ] No se expone el nombre "Publisuites" en la UI — se usa "Marketplace" o "Link Building"
- [ ] No se necesitan cambios en la API
- [ ] Tests cubren happy path, cada punto de fallo, y backward compat de acciones existentes
- [ ] ⚠️ El test `test_setup_does_not_break_existing_connect_action` pasa (backward compat)
- [ ] ⚠️ El flash message de exito es traducible via `__()`

---

## 13. Riesgos y Mitigacion

| Riesgo | Probabilidad | Impacto | Mitigacion |
|---|---|---|---|
| ⚠️ Step 1 (API add) tarda >15s por analisis SEO en Publisuites | Media | Medio — timeout PHP | El timeout del plugin es 180s. El timeout de step2 de la API es 60s. Dentro de limites. Si falla, el usuario ve error y puede reintentar. |
| ⚠️ Verificacion falla porque Publisuites no detecta el archivo inmediatamente | Media | Bajo — el archivo ya fue creado | El usuario queda en VerificationSection con boton "Verify" manual. Puede reintentar tras unos segundos. |
| WP_Filesystem no disponible (hosting restrictivo con FTP) | Baja | Medio — archivo no se crea | El usuario queda en VerificationSection con instrucciones manuales para subir el archivo |
| Credenciales de Publisuites no configuradas | Media | Bajo — error claro | La API retorna 400 con mensaje descriptivo. El error se muestra en flash message |

---

## 14. Fuera de Scope

- Cambios en la API (los endpoints ya existen con idempotencia)
- Refactor del `PublisuitesService` existente (ya funciona)
- AJAX con progress bar (el form POST es suficiente para ~10s de espera)
- Cambios en VerificationSection (ya sirve como fallback)
- Cambios en ConnectedSection (panel post-verificacion)
- ⚠️ Retry automatico si verify falla (el usuario tiene el boton manual como fallback)
- ⚠️ Delay entre crear archivo y verificar (Publisuites suele detectar inmediatamente)
