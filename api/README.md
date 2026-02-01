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

Notas:
- Estos archivos usan la conexión existente `conexion.php` (se incluye desde api/lib.php).
- El código intenta ser compatible con PHP 5.6+ usando mysqli procedural API.
- Recomiendo probar con Postman/curl y ajustar nombres de campos si la app móvil usa otras claves.
