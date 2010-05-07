-- persona
INSERT INTO contacto (
	calle,
	numero,
	telefono,
	mail
) VALUES (
	'Jose Hernandez',
	'318',
	'3543650722',
	'marcoscongregado@gmail.com'
);

INSERT INTO persona (
	nombre,
	apellido,
	edad,
	contacto_id
) VALUES 
	('Marcos','Congregado','29',1),
	('Lobo','LC','29',1);

-- cliente
INSERT INTO deuda (
	monto
) VALUES (
	20000.00
);

INSERT INTO cliente (
	persona_id,
	fecha_desde,
	deuda_id,
	empresa_id
) VALUES (
	1,
	NOW(),
	1,
	2
);

-- usuario
INSERT INTO usuario (
	nombre,
	pass,
	persona_id
) VALUES (
	'marcos',
	'1234',
	2
);

INSERT INTO producto (
	nombre,
	precio,
	cantidad,
	empresa_id
) VALUES 
	('Vidon', 5000.00, 20, 2),
	('Sifon', 2000.00, 20, 2);

INSERT INTO venta (
	cliente_id,
	producto_id,
	cantidad,
	precio
) VALUE 
	(1,1,2,10000.00),
	(1,2,2,4000.00);
