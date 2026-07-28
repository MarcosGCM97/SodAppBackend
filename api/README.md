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
