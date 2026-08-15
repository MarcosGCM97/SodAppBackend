<?php
require_once __DIR__ . '/Persona.php';

class Usuario extends Persona{
    private $usuario;
    private $contrasena;

    public function __construct(String $usuario, String $contrasena) {
        $this->usuario = $usuario;
        $this->contrasena = $contrasena;
    }

    public function getUsuario() {
        return $this->usuario;
    }

    public function setUsuario(String $usuario) {
        $this->usuario = $usuario;

        return $this;
    }

    public function getContrasena() {
        return $this->contrasena;
    }

    public function setContrasena(String $contrasena) {
        $this->contrasena = $contrasena;
    }
}