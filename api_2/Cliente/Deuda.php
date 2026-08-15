<?php

class Deuda {
    private $monto;

    public function __construct(Int $monto) {
        $this->monto = $monto;
    }

    public function getMonto() {
        return $this->monto;
    }

    public function setMonto(Int $monto) {
        $this->monto = $monto;

        return $this;
    }
}