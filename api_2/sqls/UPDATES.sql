ALTER TABLE cliente
ADD CONTRAINT fk_cliente_pesona
FOREIGN KEY (persona_id) REFERENCES persona(id);

ALTER TABLE cliente
ADD CONTRAINT fk_cliente_deuda
FOREIGN KEY (deuda_id) REFERENCES deuda(id);


CREATE TABLE cliente (
	id INT AUTO_INCREMENT PRIMARY KEY,
	persona_id INT,
	fecha_desde VARCHAR(50),
	fecha_hasta VARCHAR(50),
	deuda_id INT
	empresa_id INT
);
