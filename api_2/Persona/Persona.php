<?php
require_once 'Contacto.php';

class Persona {
    protected $nombre;
    protected $apellido;
    private $edad;
    private $contacto;

    public function __construct(String $nombre, String $apellido, Int $edad) {
        $this->nombre = $nombre;
        $this->apellido = $apellido;
        $this->edad = $edad;
    }

    public function getNombre() {
        return $this->nombre;
    }

    public function setNombre(String $nombre) {
        $this->nombre = $nombre;

        return $this;
    }

    public function getApellido() {
        return $this->apellido;
    }

    public function setApellido(String $apellido) {
        $this->apellido = $apellido;
        
        return $this;
    }

    public function getEdad() {
        return $this->edad;
    }

    public function setEdad(Int $edad) {
        $this->edad = $edad;
        
        return $this;
    }

    public function getFullName() {
        return $this->nombre . ' ' . $this->apellido;
    }

    public function getContacto() {
        return $this->contacto;
    }
}