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
- El token incluye un campo `role` (si existe en la tabla de usuarios); los endpoints que requieren permisos pueden usar ese valor. Si no hay columna de rol, por defecto se asigna `user`.
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
