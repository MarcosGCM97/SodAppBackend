ALTER TABLE cliente
ADD CONSTRAINT fk_cliente_pesona
FOREIGN KEY (persona_id) REFERENCES persona(id);

ALTER TABLE cliente
ADD CONSTRAINT fk_cliente_deuda
FOREIGN KEY (deuda_id) REFERENCES deuda(id);

ALTER TABLE persona
ADD CONSTRAINT fk_persona_contacto
FOREIGN KEY (contacto_id) REFERENCES contacto(id);

ALTER TABLE usuario
ADD CONSTRAINT fk_usuario_persona
FOREIGN KEY (persona_id) REFERENCES persona(id);

ALTER TABLE venta
ADD CONSTRAINT fk_venta_cliente
FOREIGN KEY (cliente_id) REFERENCES cliente(id);
 
ALTER TABLE venta
ADD CONSTRAINT fk_venta_producto
FOREIGN KEY (producto_id) REFERENCES producto(id);
