API REST mínima para SodApp

Archivos creados:
- api/lib.php         -> helpers (CORS, JSON, DB prepare)
- api/clients.php     -> endpoints para clientes (GET/POST/PUT/DELETE)
- api/products.php    -> endpoints para productos (GET/POST/PUT/DELETE)
- api/sales.php       -> endpoints para ventas (GET con filtro, POST para crear ventas)

Uso rápido (sin rewrite):
- GET all clients: /sodapp/api/clients.php
- GET client by id: /sodapp/api/clients.php?id=1
- POST create client: POST /sodapp/api/clients.php  (Content-Type: application/json)
  Body example: {"nombreCl":"Juan","numTelCl":"123","direccionCl":"Calle 1"}

- GET products: /sodapp/api/products.php
- POST product: /sodapp/api/products.php  Body: {"nombrePr":"Sifon","precioPr":10.5,"cantidadPr":5}

- POST sales: /sodapp/api/sales.php  Body: {"clienteId":3,"productos":[{"nombre":"Sifon de soda","cantidad":2}]}

Autenticación (JWT)
-------------------
1) Obtener token de acceso (login):

```bash
curl -X POST "http://<host>/api/login.php" \
  -H "Content-Type: application/json" \
  -d '{"nombreUs":"tu_usuario","contrasenaUs":"tu_pass"}'
```

Respuesta esperada (ejemplo):

```json
{
  "success": true,
  "message": "Usuario encontrado.",
  "usuario": { "us_ide": 1, "us_nom": "admin" },
  "token": "eyJhbGciOi..."
}
```

2) Usar token en llamadas protegidas:

```bash
curl -X POST "http://<host>/api/clientes.php" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"nombreCl":"Juan","numTelCl":"123","direccionCl":"Calle 1"}'
```

Notas:
- El token solo lleva `us_ide` y `us_nom`; la empresa, el rol y los permisos se leen de la base en cada request (ver "Roles and permissions" abajo).
- Recomendado: configurar la variable de entorno `JWT_SECRET` en el servidor para asegurar los tokens.

Notas:
- Estos archivos usan la conexión existente `conexion.php` (se incluye desde api/lib.php).
- El código intenta ser compatible con PHP 5.6+ usando mysqli procedural API.
- Recomiendo probar con Postman/curl y ajustar nombres de campos si la app móvil usa otras claves.

Repartos (api/repartos.php)
---------------------------
Requiere token (`Authorization: Bearer <token>`); cada usuario ve solo sus repartos. Armar o editar un reparto no mueve stock ni deuda.

- `GET ?id=N` o `GET ?fecha=AAAA-MM-DD`: el reparto con sus paradas en orden (`rs_ord`), cada una con `cl_nom`, `cl_dir`, `cl_tel` y sus líneas (`rl_cpl` planificada, `rl_cen` entregada, `rl_pre`). 404 si no existe.
- `POST` con `{"accion": ...}`:
  - `crear` `{"fecha":"2026-11-02","origen":"vacio|dias|plantilla|anterior"}` → 201 con el reparto. `dias`: clientes con ese día de entrega; `plantilla`: la plantilla guardada de ese día de la semana; `anterior`: el último reparto anterior del mismo día. Las líneas copiadas toman el precio actual del producto. 409 si ya hay un reparto para esa fecha; 404 si la plantilla o el reparto anterior no existen.
  - `guardar_plantilla` `{"repartoId":N}`: reemplaza la plantilla de ese día de la semana.
  - `agregar_parada` `{"repartoId":N,"clienteId":N}` → 201 `{"paradaId":N}`; 409 si el cliente ya está.
  - `quitar_parada` `{"paradaId":N}` y `guardar_lineas` `{"paradaId":N,"lineas":[{"productoId":N,"cantidad":N,"precio":X}]}` (precio opcional, por defecto el del producto): solo paradas `pendiente`, si no 409.
  - `ordenar` `{"repartoId":N,"paradas":[rs_ide,...]}`: todas las paradas del reparto en el orden nuevo; 400 si falta o sobra alguna.
  - `entregar` `{"paradaId":N,"lineas":[{"productoId":N,"cantidad":N,"devueltos":N,"precio":X}]}`: registra lo entregado como ventas (igual que `POST ventas.php`: stock, historial, envases y deuda) en una sola transacción y deja la parada `entregada`. `cantidad` es lo realmente entregado (0 = no se entregó ese producto); un producto planificado que no se envía cuenta como 0 y uno no planificado se agrega con planificada 0. Sin `lineas` se entrega lo planificado. Responde `{"monto":X,"ventas":[ids]}`. 409 si la parada no está `pendiente`, también ante dos entregas simultáneas.
  - `no_entregar` `{"paradaId":N}`: la parada queda `no_entregada`; no toca stock, deuda ni ventas. `reabrir` `{"paradaId":N}` la vuelve a `pendiente`. Una parada `entregada` es final (se corrige borrando la venta).

Clientes: localidad y coordenadas (migración 009)
-------------------------------------------------
- `GET clientes.php` y `GET ?id=N` devuelven además `cl_loc`, `cl_lat`, `cl_lng` (pueden ser `null`).
- `POST` acepta opcionales `localidadCl`, `latitudCl`, `longitudCl`. `PUT` acepta opcionales `cl_loc`, `cl_lat`, `cl_lng`: una clave que no se envía deja el valor como está (versiones viejas de la app); `null` lo borra.
- Latitud y longitud van siempre juntas, en grados (-90..90, -180..180); si no, 400. Localidad: hasta 100 caracteres.

Ruta (api/ruta.php)
-------------------
Requiere token. Módulo aislado: solo lee repartos, paradas y clientes; es dueño de la planta (`sap_pl00`) y de los horarios del recorrido (`rp_ini`, `rp_fin`).

- `GET`: la planta del usuario `{"planta": {"direccion","localidad","lat","lng"} | null}`.
- `GET ?repartoId=N`: `{"ruta": {"repartoId","fecha","inicio","fin","planta","paradas":[{"paradaId","orden","estado","clienteId","nombre","direccion","localidad","lat","lng"}]}}` con las paradas en el orden del reparto. 404 si no es un reparto con fecha del usuario.
- `POST` con `{"accion": ...}`:
  - `guardar_planta` `{"direccion","localidad","lat"?,"lng"?}`: crea o reemplaza la planta. 400 si falta dirección o localidad, o las coordenadas son inválidas.
  - `iniciar` `{"repartoId":N}`: guarda la hora de inicio solo la primera vez; responde `{"inicio"}`.
  - `finalizar` `{"repartoId":N}`: guarda la hora de llegada a planta; responde `{"inicio","fin"}`. 409 si no se inició, si queda alguna parada `pendiente` o si ya se finalizó.

Roles and permissions (migration 011)
-------------------------------------
Every user belongs to a company: `sap_us00.us_emp` is the owner's `us_ide` (the owner points to itself). All data rows keep the company in their owner column (`cl_emp`, `pr_emp`, `vt_emp`, `rp_emp`, ...) and every endpoint filters by the company of the token user (`current_company_id()` in `auth.php`), so every user of a company sees the same clientes, ventas and repartos and the rows a vendedor creates belong to the company.

- Owner (`us_emp = us_ide`): every permission plus user and role administration (not grantable). Its own row cannot be changed through the admin API.
- Other users: the permissions of their role (`us_rol` -> `sap_ro00`, `sap_rm00`, catalog `sap_pm00`).
- Every request re-reads the user from the database: old tokens keep working, a role change applies at once, and a deactivated (`us_act = 0`) or deleted user gets 401 `Invalid or expired token`.
- Missing permission: 403 `{"success":false,"error":"Forbidden: missing permission","permiso":"<code>"}` (`"permiso":"admin"` for the admin API). With "any of", the first code is reported.

Permission catalog: `ventas.ver`, `ventas.cargar`, `ventas.eliminar`, `repartos.ver`, `repartos.gestionar`, `clientes.ver`, `clientes.editar`, `cobros.ver`, `cobros.cargar`, `stock.ver`, `stock.editar`, `caja.ver`. Migration 011 seeds one `Vendedor` role per owner with `ventas.ver`, `ventas.cargar`, `repartos.ver`, `repartos.gestionar`, `clientes.ver`, `clientes.editar`.

Endpoint -> permission ("any of" for reference reads that several screens need):

| Endpoint | Method / case | Permission |
|----------|---------------|------------|
| `clientes.php` | GET (list, `?id=`) | any of `clientes.ver`, `ventas.ver`, `ventas.cargar`, `repartos.ver`, `repartos.gestionar`, `cobros.ver` |
| `clientes.php` | POST, PUT, DELETE | `clientes.editar` |
| `dias.php` | GET (all, `?id=`) | any of `clientes.ver`, `ventas.ver`, `ventas.cargar`, `repartos.ver`, `repartos.gestionar` |
| `dias.php` | PUT | `clientes.editar` |
| `envases.php` | GET `?clienteId=` (client balance) | `clientes.ver` |
| `envases.php` | PUT (set a client balance) | `clientes.editar` |
| `envases.php` | POST `llenar` (refill empties, moves stock) | `stock.editar` |
| `productos.php` | GET (list, `?nombre=`) | any of `stock.ver`, `ventas.ver`, `ventas.cargar`, `repartos.ver`, `repartos.gestionar`, `cobros.ver` |
| `productos.php` | POST, PUT, DELETE | `stock.editar` |
| `movimientos.php` | GET | `stock.ver` |
| `ventas.php` | GET `?id=` (sales of a cliente) | any of `ventas.ver`, `cobros.ver` |
| `ventas.php` | GET (recent sales) | `ventas.ver` |
| `ventas.php` | POST | `ventas.cargar` |
| `ventas.php` | DELETE | `ventas.eliminar` |
| `repartos.php` | GET | `repartos.ver` |
| `repartos.php` | POST (every `accion`, `entregar` included: it registers the delivered sales) | `repartos.gestionar` |
| `ruta.php` | GET | `repartos.ver` |
| `ruta.php` | POST (`guardar_planta`, `iniciar`, `finalizar`) | `repartos.gestionar` |
| `deudaCliente.php` | GET | `cobros.ver` |
| `deudaCliente.php` | PUT (register a payment) | `cobros.cargar` |
| `pagos.php` | GET `?formas=1` | any of `cobros.ver`, `cobros.cargar` |
| `pagos.php` | GET `?clienteId=` | `cobros.ver` |
| `pagos.php` | GET `?mes=` (caja) | `caja.ver` |
| `cajaMes.php` | GET | `caja.ver` |
| `sesion.php` | GET | any valid token of an active user |
| `usuarios.php`, `roles.php` | every method | owner only (`admin`) |

Login and session:
- `POST login.php` (and the legacy `GET login.php?nombre=&contraseña=`) answers `{"success":true,"message","usuario":{"us_ide","us_nom","us_emp","us_rol","us_act"},"token","es_dueno":bool,"permisos":[codes]}`; the owner gets the whole catalog. Never returns `us_pas`. Unknown name 404, wrong password 401, inactive user 401 `Usuario inactivo.`.
- `GET sesion.php` -> `{"success":true,"us_ide":N,"us_nom":"..","es_dueno":bool,"permisos":[..]}`: the current permissions, for an app that starts with a saved session.

Admin API (owner only):
- `GET usuarios.php` -> `{"success":true,"usuarios":[{"us_ide","us_nom","us_rol","ro_nom","us_act","es_dueno"}]}` (company users, owner first).
- `POST usuarios.php` `{"nombre","contrasena","rol"}` -> 201 `{"success":true,"us_ide":N}`: active user of the company, password stored with `password_hash`. 400 name not 3-100 characters (trimmed), password under 6 characters or missing role; 404 role not in the company; 409 name taken (names are global: login is by name).
- `PUT usuarios.php` `{"id","rol"?,"contrasena"?,"activo"?}` -> `{"success":true}`. 404 user not in the company; 400 when the target is the owner, nothing to change or invalid data.
- `GET roles.php` -> `{"success":true,"roles":[{"ro_ide","ro_nom","permisos":[codes],"usuarios":N}],"permisos_disponibles":[{"pm_cod","pm_des"}]}`.
- `POST roles.php` `{"nombre","permisos":[codes]}` -> 201 `{"success":true,"ro_ide":N}`; `PUT roles.php` `{"id","nombre","permisos"}` replaces name and permissions; `DELETE roles.php?id=N`. 400 name not 1-50 characters or unknown code; 404 role not in the company; 409 duplicate name in the company, or (DELETE) users still have the role. Writes run in one transaction.
