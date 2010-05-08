<?php

require_once __DIR__ .  '/../../Persona/Entidad/Persona.php';
require_once __DIR__ .  '/../../Persona/Entidad/Contacto.php';
require_once  __DIR__ . '/../../Cliente/Entidad/Deuda.php';

class Cliente extends Persona {

	private $id;
    private $fechaDesde;
    private $fechaHasta;
    private $deuda;
    private $ventas;
    private $productos;

    public function __construct(String $fechaDesde, String $fechaHasta) {
        $this->fechaDesde = $fechaDesde;
        $this->fechaHasta = $fechaHasta;
    }
    
    public function getId() {
		return $this->id;	
	}
    
    public function getFechaDesde() {
        return $this->fechaDesde;
    }

    public function setFechaDesde(String $fechaDesde) {
        $this->fechaDesde = $fechaDesde;

        return $this;
    }

    public function getFechaHasta() {
        return $this->fechaHasta;
    }

    public function setFechaHasta(String $fechaHasta) {
        $this->fechaHasta = $fechaHasta;

        return $this;
    }

    public function getDeuda() {
        return $this->deuda;
    }

    public function getVentas() {
        return $this->ventas;
    }

    public function getProductos() {
        return $this->productos;
    }
}
