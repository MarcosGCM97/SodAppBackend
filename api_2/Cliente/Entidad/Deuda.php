<?php

class Deuda {
    private $id;
    private $monto;
	private $cliente;
	
    public function __construct(Int $monto) {
        $this->monto = $monto;
    }
    
    public function getId() {
		return $this->id;	
	}

    public function getMonto() {
        return $this->monto;
    }

    public function setMonto(Int $monto) {
        $this->monto = $monto;

        return $this;
    }
    
    public function getCliente() {
        return $this->cliente;
    }

    public function setCliente(Int $cliente) {
        $this->cliente = $cliente;

        return $this;
    }
}
