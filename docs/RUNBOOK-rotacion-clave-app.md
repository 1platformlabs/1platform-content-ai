# Runbook — rotar la clave de aplicación del plugin

Refs `1platformlabs/1platform-content-ai#205`.

## Antes de empezar: qué hace y qué NO hace una rotación

La clave de aplicación viaja **dentro del `.zip`** que WordPress.org sirve
(`deploy.sh` publica por SVN y `.distignore` no excluye `includes/`), así que
todo el que instale el plugin la tiene. Es un **identificador público**, no un
secreto, y la decisión del dueño es tratarla como tal.

Consecuencia que manda sobre todo lo demás:

> **Rotar el valor embebido NO revoca el viejo.** Cada instalación que todavía
> no actualizó sigue presentando la clave anterior. Si se revoca la vieja el día
> del release, esas instalaciones empiezan a recibir `401` en `/auth/token` y
> dejan de funcionar. Rotar sirve para **mover tráfico**, nunca para **contener
> una filtración**.

Por eso el orden es: emitir la nueva → publicarla → **esperar** a que la base
instalada migre → recién ahí revocar la vieja. Y por eso el endurecimiento del
servidor (límite por clave en `/auth/token`) es la mitigación real, no la
rotación.

## Estado medido (2026-09-07)

| Entorno | Dónde | Estado |
|---|---|---|
| `development` | `Config.php` (línea del `app_key` de `development`) | ya **no** es una credencial: es el marcador `set-CONTAI_APP_KEY_DEVELOPMENT-in-wp-config` |
| `staging` | `Config.php` (línea del `app_key` de `staging`) | **viva contra QA** (`api-qa` → 200; control negativo de 64 caracteres inventados → 401; contra PROD → 401) |
| `production` | `Config.php` (línea del `app_key` de `production`) | **viva contra PROD** (`api` → 200; control negativo → 401; contra QA → 401) |

Hasta este PR, `development` y `staging` compartían el mismo valor: la clave de
QA se publicaba **dos veces** en el `.zip`, y rotar "la de development" era
imposible de acotar.

Barrido histórico (`gitleaks detect` sobre los 288 commits, 2026-09-07): **9
hallazgos, todos `generic-api-key`, todos en `includes/services/config/Config.php`
(líneas 19/55/56/92/93 según el commit), desde `f71dd379` del 2026-03-14**. No
hay ninguna otra credencial en la historia del repositorio.

## Paso 1 — emitir las claves nuevas (acción del dueño)

Una clave por entorno, **las tres distintas** (`ConfigEmbeddedAppKeyTest` falla
si dos coinciden).

- **Panel:** `console.1platform.pro` → la app del plugin → credenciales.
- **API:** `POST /api/v1/platform/apps/{app_id}/credentials` con token de
  plataforma (`is_superadmin`). El valor se revela **una sola vez**
  (`API_CRED_REVEAL_LIMIT = 10/hour`).
- Emitir contra **PROD** (`api.1platform.pro`) la de producción y contra **QA**
  (`api-qa.1platform.pro`) la de staging. La de development no hace falta: su
  `base_url` es `http://127.0.0.1:8000` y cada quien usa la suya.

No revoques nada todavía.

## Paso 2 — verificar que la nueva funciona ANTES de publicarla

```bash
# 200 con la nueva
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://api.1platform.pro/api/v1/auth/token \
  -H 'Content-Type: application/json' -A 'Mozilla/5.0' --data '{"apiKey":"<NUEVA-PROD>"}'
# control negativo: 64 caracteres inventados → 401 (si esto no da 401, la sonda no discrimina)
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://api.1platform.pro/api/v1/auth/token \
  -H 'Content-Type: application/json' -A 'Mozilla/5.0' \
  --data "{\"apiKey\":\"$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 64)\"}"
```

⚠️ Sin `-A '<user agent de navegador>'` el borde devuelve **403 `error code 1010`
a todo** y la medición no discrimina: la clave buena y la inventada dan lo mismo.

## Paso 3 — poner el valor nuevo

Dos caminos, y el orden entre ellos importa:

1. **Sin release, para los sitios propios:** definir en `wp-config.php` (o como
   variable de entorno) `CONTAI_APP_KEY_PRODUCTION` / `CONTAI_APP_KEY_STAGING`.
   `Config::resolveAppKey()` la prefiere sobre el valor embebido. Esto surte
   efecto **sin publicar nada** y es la forma de probar la clave nueva en
   producción real antes de moverla a la base instalada.
2. **Con release, para la base instalada:** cambiar el literal en
   `includes/services/config/Config.php`, subir la versión en los **tres**
   lugares que el CI compara (`1platform-content-ai.php` `Version:`,
   `readme.txt` `Stable tag:` — la constante `CONTAI_VERSION` no la compara el
   CI pero conviene alinearla), agregar entrada en `CHANGELOG.md` y en
   `== Changelog ==` de `readme.txt`, y publicar con `./deploy.sh`.

## Paso 4 — esperar, y medir la migración

La clave vieja **sigue viva** mientras haya instalaciones sin actualizar. Lo que
dice cuándo se puede revocar es el tráfico, no el calendario:

- `last_used_at` de la credencial vieja (si el registro está poblado), o
- el volumen de `/auth/token` atribuible a esa clave. Con el cambio de
  `1platform-api` de este mismo issue, `/auth/token` tiene un bucket por clave,
  así que el `429` del bucket público es además la señal de que algo abusa.

Regla práctica: no revocar hasta que el uso de la vieja sea ~0 durante varios
días seguidos, y avisar antes en el changelog de WordPress.org.

## Paso 5 — revocar la vieja (acción del dueño, y sólo después del paso 4)

Panel o `DELETE /api/v1/platform/apps/{app_id}/credentials/{credential_id}`. El
endpoint responde el mismo `401` para "no existe", "revocada" e "inactiva" (a
propósito: distinguirlas le confirmaría a quien tenga la clave robada que era
real y cuándo dejó de servir).

Después de revocar, repetir la sonda del paso 2 con la clave **vieja**: tiene
que dar **401**. Si da 200, no se revocó.

## Lo que NO hay que hacer

- **No dejar el valor de producción en blanco.** Una instalación limpia de
  WordPress.org tiene que andar sin configuración; con la cadena vacía
  `validate()` falla y el plugin no arranca.
- **No revocar la vieja el mismo día del release.** Rompe a todo el que no
  actualizó, que es la mayoría durante semanas.
- **No tratar la rotación como contención.** Si lo que se busca es poder
  revocar **un sitio** sin tocar el resto, eso es otra cosa: clave por
  instalación (registro en la activación), que es la alternativa "A" del
  comentario del issue #205 y no está implementada.
