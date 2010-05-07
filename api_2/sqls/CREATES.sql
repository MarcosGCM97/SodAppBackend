
CREATE TABLE cliente (
	id INT AUTO_INCREMENT PRIMARY KEY,
	persona_id INT,
	fecha_desde VARCHAR(50),
	fecha_hasta VARCHAR(50),
	deuda_id INT;
	empresa_id INT
);

CREATE TABLE deuda (
	id INT AUTO_INCREMENT PRIMARY KEY,
	monto DECIMAL (10,2)
);

CREATE TABLE contacto (
	id INT AUTO_INCREMENT PRIMARY KEY,
	calle VARCHAR(100),
	numero VARCHAR(50),
	telefono VARCHAR(50),
	mail VARCHAR(50)
);

CREATE TABLE persona (
	id INT AUTO_INCREMENT PRIMARY KEY,
	nombre VARCHAR(50),
	apellido VARCHAR(50),
	edad INT,
	contacto_id INT,
);

CREATE TABLE usuario (
	id INT AUTO_INCREMENT PRIMARY KEY,
	persona_id INT,
	nombre VARCHAR(100),
	pass VARCHAR(100)
);

CREATE TABLE producto (
	id INT AUTO_INCREMENT PRIMARY KEY,
	nombre VARCHAR(50),
	precio DECIMAL(20,2),
	cantidad INT(20),
	empresa_id INT(20)
);

CREATE TABLE venta (
	id INT AUTO_INCREMENT PRIMARY KEY,
	cliente_id INT,
	producto_id INT,
	cantidad INT,
	precio DECIMAL (10,2)
);
