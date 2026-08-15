<?php

class Venta {
    private $id;
    private $monto;
    private $fecha;
    private $producto;
    private $cliente;
    private $empresa;

    public function __construct(Int $monto, String $fecha) {
        $this->monto = $monto;
        $this->fecha = $fecha;
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

    public function getFecha() {
        return $this->fecha;
    }
    
    public function setFecha(String $fecha) {
        $this->fecha = $fecha;

        return $this;
    }
    
    public function getProducto() {
        return $this->producto;
    }

    public function getCliente() {
        return $this->cliente;
    }

    public function getEmpresa() {
        return $this->empresa;
    }
}