# Tareas del backend (api / api_2)

Origen: auditoría del contrato entre la app Android (`SodAppComposse`, rama `main` @ `8a5ded3`) y `api/` (2026-10-12).
Objetivo: dejar `api_2/` en el servidor como entorno de pruebas compatible con la app.

Severidad: **[ROMPE]** la app falla · **[DATOS]** resultado incorrecto · **[SEG]** seguridad · **[MENOR]** cosmético.

## Decisiones

- [x] **D1 – Autenticación (decidido 2026-10-12): la app envía el token.** Todos los endpoints (excepto `login.php`) exigen `Authorization: Bearer <jwt>` vía `require_auth()`. Hoy la app no guarda ni envía el JWT (`NetworkModels.kt:19-20` mapea `"usuario"` a un campo `token`; no hay interceptor).
- [x] **D2 – Separación de datos por usuario (decidido 2026-10-12).** Cada usuario solo ve y modifica los productos, clientes y ventas que cargó. El dueño de cada registro es el usuario (`sap_us00.us_ide`), guardado en las columnas `cl_emp`, `pr_emp` y `vt_emp`. En esta lista, "`empresa_id`" se refiere a ese valor.

### Estado actual de las columnas de dueño (producción, confirmado por el usuario; local `sodapp_test` coincide)

| Tabla | Columna | Tipo | Qué guarda hoy | Quién la escribe |
|---|---|---|---|---|
| `sap_cl00` | `cl_emp` | `varchar(50)`, default `'Ariza'` | **nombre** del usuario | `clientes.php:53` **hardcodea `"Ariza"`** en el INSERT |
| `sap_pr00` | `pr_emp` | `varchar(50)` | **nombre** del usuario | `productos.php` POST **no la escribe** (queda `NULL`) |
| `sap_vt00` | `vt_emp` | `varchar(50)`, default `'Ariza'` | **id** del usuario (ventas nuevas); ventas viejas tienen el nombre | `ventas.php:162` con `usuarioId` que manda la app |

Local: 35 clientes con `'Ariza'` y 1 vacío; 5 productos con `'Ariza'`; 368 ventas con `'Ariza'` y 1 con `'1'`. Ningún índice sobre estas columnas. La base `sodapp` (esquema normalizado con `empresa_id`) **no la usa la API**.

## Autenticación y separación por usuario [ROMPE] [SEG]

Regla general: el id del usuario dueño **siempre sale del JWT validado en el servidor**, nunca de un parámetro que manda la app (`usuarioId`, `vt_emp`, etc.), porque cualquiera podría cambiarlo y ver datos ajenos.

- [x] **A1 – Normalizar las columnas de dueño a id de usuario.** En producción:
  1. Backup de `sap_cl00`, `sap_pr00`, `sap_vt00`.
  2. Convertir nombres a id: `UPDATE sap_cl00 c JOIN sap_us00 u ON u.us_nom = c.cl_emp SET c.cl_emp = u.us_ide;` (ídem `sap_pr00.pr_emp` y las ventas viejas de `sap_vt00.vt_emp`).
  3. Revisar lo que no matcheó (vacíos, `NULL`, nombres que no existen en `sap_us00`) y asignarlo a mano. En producción `'Ariza'` es el `us_nom` del usuario real (confirmado 2026-10-12), así que la mayoría convierte sola; los `NULL` de `pr_emp` y los vacíos de `cl_emp` probablemente también son de Ariza. Contar antes y después: `SELECT cl_emp, COUNT(*) FROM sap_cl00 GROUP BY cl_emp;` (ídem las otras dos).
  4. Cambiar el tipo: `ALTER TABLE ... MODIFY cl_emp INT NOT NULL` (sin default `'Ariza'`), ídem `pr_emp` y `vt_emp`. Opcional: renombrar las tres a `empresa_id` / `us_ide` para que el nombre diga lo que guarda.
  5. Índices: `(cl_emp)`, `(pr_emp, pr_nom)`, `(vt_emp, vt_fec)`.
  6. Actualizar los dumps de `tablasDB/` (están desactualizados: no tienen `vt_mon` ni `is_deleted`).
- [x] **A2 – El JWT ya trae `us_ide`** (`login.php:38-43`, `:91-96`): usarlo como dueño. Si a futuro varios usuarios comparten una empresa, ahí recién agregar tabla `empresa` y `empresa_id` en `sap_us00`.
- [x] **A3 – Helper `current_user_id()`** en `auth.php`: llama a `require_auth()` y devuelve `us_ide` del payload; 401 si falta o el token es inválido/expirado.
- [x] **A4 – `require_auth()` en todos los endpoints** salvo `login.php`: `clientes.php`, `productos.php`, `ventas.php`, `deudaCliente.php`, `cajaMes.php`, `dias.php` (todos los métodos, incluidos GET). Revierte la quita de `require_auth()` de los cambios locales de clientes/productos (ver T7).
- [x] **A5 – Filtrar lecturas por dueño.** Agregar `WHERE cl_emp = ?` / `pr_emp = ?` / `vt_emp = ?` (con el id del token) a todos los SELECT: listados y búsquedas por id/nombre de clientes y productos, ventas (`ventas.php` GET), `cajaMes.php`, `dias.php`, `deudaCliente.php` GET.
- [x] **A6 – Asignar dueño en altas.** `INSERT` de clientes (`clientes.php:53`, quitar el `"Ariza"` hardcodeado), productos (`productos.php` POST, hoy no escribe `pr_emp`) y ventas (`ventas.php:162`, hoy usa `usuarioId` de la app) guardan el id del token.
- [x] **A7 – Restringir modificaciones y borrados.** Todo `UPDATE` / `DELETE` / borrado lógico incluye `AND empresa_id = ?`; si no afecta filas, responder 404 (no revelar que el registro existe para otro usuario).
- [x] **A8 – Validar pertenencia en ventas.** En `ventas.php` POST, el cliente (`vt_cli`) y cada producto deben pertenecer al mismo `empresa_id`; si no, 404. Lo mismo para el ajuste de deuda en `deudaCliente.php`.
- [x] **A9 – JOINs por nombre de producto.** `vt_pro` se une con `sap_pr00` por `pr_nom` (`cajaMes.php:60`, `ventas.php`). Con varios usuarios, dos pueden tener un producto con el mismo nombre: agregar `AND pr.pr_emp = vt.vt_emp` a cada JOIN (a futuro, guardar `pr_ide` en la venta).
- [x] **A10 – Unicidad por usuario.** Hoy no hay índice único sobre `pr_nom`. Agregar `UNIQUE (pr_emp, pr_nom)` (después de A1) para que cada usuario tenga sus propios nombres sin duplicados; `productos.php` POST/PUT debe responder 409 si choca.
- [x] **A11 – (Incluido en A1)** migración de datos existentes.
- [x] **A12 – Dejar de confiar en `usuarioId` / `vt_emp` del request.** `ventas.php` GET y POST reciben `usuarioId` desde la app; usar el del token y conservar el parámetro solo por compatibilidad (ignorado).
- [x] **A13 – Pruebas de aislamiento** en `api_2` con dos usuarios (`Ariza` y el usuario de pruebas `Congre`): cada uno no ve, no edita y no borra datos del otro (listados, por id, Caja, Agenda, ventas, deuda).

### Cambios necesarios en la app (repo `SodAppComposse`)

- [x] **APP1 – Guardar el token del login.** Corregir `NetworkModels.kt:19-20` para leer la clave `"token"` y persistirla (DataStore / SharedPreferences).
- [x] **APP2 – Interceptor OkHttp** en `RetrofitInstance.kt` que agregue `Authorization: Bearer <token>` a cada request.
- [x] **APP3 – Manejar 401**: token vencido/inválido → limpiar sesión y volver al login.
- [x] **APP4 – Al cerrar sesión o cambiar de usuario**, borrar el token y los datos cacheados en memoria del usuario anterior.

## Login [SEG]

- [ ] **L1 – Eliminar el login por GET** (`login.php:61-118`). Recibe la contraseña en la URL (`?nombre=..&contraseña=..`), que queda registrada en logs del servidor, proxies e historial. La app usa solo `POST login.php` (`ApiServices.kt:30`), así que se puede borrar sin romper nada; responder 405 a cualquier método distinto de POST.
- [ ] **L2 – No revelar si el usuario existe.** Hoy responde 404 "Usuario no encontrado" (`:58`) o 401 "Contraseña incorrecta" (`:55`). Unificar en 401 con un mensaje genérico ("Usuario o contraseña incorrectos").
- [ ] **L3 – Límite de intentos: máximo 5 fallidos.** Al 5.º intento fallido para el mismo usuario + IP, bloquear el login durante 15 minutos y responder 429. Un login exitoso reinicia el contador. Guardar los intentos en una tabla (p. ej. `sap_login_intentos`: `us_nom`, `ip`, `intentos`, `bloqueado_hasta`). La app debe mostrar el mensaje de bloqueo.
- [ ] **L4 – Rol explícito.** El rol se adivina recorriendo posibles nombres de columna (`:30-36`). Definir una columna concreta en `sap_us00` (o quitar `role` del JWT si no se usa).
- [ ] **L5 – Borrar código muerto** (`:119-157`): bloque comentado con un registro de usuarios que guardaba la contraseña sin hashear.

## Bloqueantes [ROMPE]

- [x] **T1 – `ventas.php` POST/DELETE devuelven 401.** `require_auth()` en `ventas.php:120` y `:250`. Se resuelve con APP1–APP2 (no quitar `require_auth()`).
- [x] **T2 – `deudaCliente.php` PUT devuelve 401.** `require_auth()` en `deudaCliente.php:36`. Se resuelve con APP1–APP2.
- [x] **T3 – `deudaCliente.php` PUT lee JSON pero la app envía query params.** La app llama `PUT deudaCliente.php?id=..&deuda=..` (`ApiServices.kt` `updateDeuda`); el PHP usa `get_json_input()` (`:37-43`) → 400. Leer `$_GET['id']` / `$_GET['deuda']` (o cambiar la app a body JSON).
- [x] **T4 – `deudaCliente.php` PUT responde 500 con error vacío** cuando no se afecta ninguna fila (`:68-71`). Devolver un código y mensaje claros (p. ej. 404/409).
- [ ] **T5 – `dias.php` PUT es inalcanzable.** El bloque `if ($method === 'PUT')` (`:70`) está anidado dentro de `if ($method === 'GET')` (`:9`). Sacarlo al nivel superior.
  - **Reproducido en producción (2026-10-12):** al guardar los días de entrega de un cliente (Agenda), la app muestra "Error de red: verifica tu conexión" aunque la conexión anda bien. La app envía `PUT api/dias.php` con body JSON `{"cl_ide":51,"cl_lun":1,"cl_mar":0,...,"cl_dom":0}`; el servidor responde **200 con body vacío** (`Content-Type: text/html`, `Content-Length: 0`) porque el bloque PUT nunca se ejecuta, y la app falla al leer el JSON (`AgendaRepository: java.io.EOFException: End of input at line 1 column 1`). Además, `GET dias.php?id=51` devolvió `"diasEntrega":[]`: verificar si ese cliente no tiene días cargados o si el GET por id también falla.
- [ ] **T6 – `dias.php` PUT: SQL mal armado.** El `UPDATE` tiene 7 placeholders y no incluye `cl_dom` (`:81`); el bind usa `'iiiiiii'` con 8 variables (`:93-102`). Incluir `cl_dom` y alinear tipos/variables. Mantener `AND cl_emp = ?` (separación por usuario) y responder 404 si el cliente no es del usuario.
- [ ] **APP5 – Mensaje engañoso ante respuestas inválidas.** La app muestra "Error de red: verifica tu conexión" cuando el servidor responde algo que no es JSON (p. ej. `EOFException` / `JsonSyntaxException`). Distinguir errores de conexión (`IOException` sin respuesta) de respuestas inválidas del servidor y mostrar un mensaje que lo diga.
- [ ] **T7 – Commitear los cambios locales de `clientes.php` y `productos.php`.** La versión del working tree es la que coincide con la app (PUT de clientes con `cl_ide/cl_nom/...`, PUT de productos por query string). La versión en HEAD rompe la app. Desplegar el working tree, no HEAD. **Excepción:** esos cambios quitan `require_auth()`; volver a agregarlo (A4).
- [ ] **T8 – Subir `ventas.php` actualizado a producción** (guarda `vt_mon`). Sin esto, Caja recalcula con el precio actual.

## Datos incorrectos [DATOS]

- [ ] **T9 – `ventas.php` GET `?id&usuarioId` no devuelve `vt_mon`** (`:20-25`). La app reintegra la deuda al borrar una venta con `precio * cantidad` del precio actual (`DeudaScreen.kt:325`). Devolver `vt_mon` y que la app lo use.
- [ ] **T10 – `ventas.php` GET `?id&usuarioId` usa INNER JOIN por `pr_nom`**: se pierden ventas de productos renombrados/eliminados. Usar LEFT JOIN como en `cajaMes.php`.
- [ ] **T11 – Borrar venta + ajustar deuda no es atómico** (dos llamadas separadas desde la app). Evaluar que `ventas.php` DELETE ajuste la deuda en la misma transacción.
- [ ] **T12 – `productos.php` PUT no actualiza productos con `updated_at` cargado** (`WHERE ... AND updated_at IS NULL`, `:78`) → 404. Revisar la condición (¿debería ser una columna de borrado lógico?).
- [ ] **T13 – `dias.php` GET lista clientes eliminados** (sin filtro `is_deleted`, `:39`).
- [ ] **T14 – `ventas.php` DELETE sin respuesta si falla el execute** (cae al 405, `:261-273`). Devolver error explícito.

## Seguridad [SEG]

- [ ] **T15 – Credenciales de BD hardcodeadas** como fallback en `conexion.php`. Moverlas a variables de entorno / archivo fuera del web root.
- [ ] **T16 – `api_2` debe usar una base de pruebas.** `conexion.php` cae a `c2761775_sodapp` si no hay `DB_NAME`. Configurar una base distinta para `api_2`.
- [ ] **T17 – Secreto JWT por defecto** `'CHANGE_ME_PLEASE_OVERRIDE'` (`jwt.php:14,31`). Definir `JWT_SECRET` en el servidor.
- [ ] **T18 – `ventas.php` tiene `display_errors=1`** (`:2-5`): puede romper el JSON y exponer rutas. Desactivar y no escribir `error.log` dentro del web root.
- [ ] **T19 – No publicar archivos auxiliares** en `api_2/`: `test.php`, `error.log`, `README.md`, `actualizar_en_app.txt`, `.github/`.

## Menores [MENOR]

- [ ] **T20 – `conexion.php` responde 200 con `{"error":...}`** y sin `success` cuando falla la conexión. Devolver 500 y `success:false`.
- [ ] **T21 – `cajaMes.php` no devuelve `vt_emp`** y la app no envía `ano` (no se pueden ver meses de años anteriores).
- [ ] **T22 – `ventas.php` GET `?usuarioId` no devuelve `vt_ide`** (la app queda con `idVenta = 0`).
- [ ] **T23 – Campos que pueden venir `null`** (`created_at`, `updated_at`, `cl_dir`) y la app los declara no-nulos: devolver string vacío o ajustar la app.

## Orden sugerido

1. T16, T19 antes de copiar a `api_2`; A1 primero en la base de pruebas, después en producción (con backup).
2. A2–A4 + APP1–APP3 (token de punta a punta) → resuelve T1, T2. Junto con L1–L5 (endurecer el login).
3. A5–A12 (separación por usuario) y A13 (pruebas con dos usuarios).
4. T3–T7 (bloqueantes restantes).
5. T8 para producción (solo después de que la app nueva envíe el token).
6. Resto por severidad.
