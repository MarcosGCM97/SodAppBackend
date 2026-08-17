<?php

class Contacto {
	private $id;
    private $calle;
    private $numero;
    private $telefono;
    private $mail;

    public function __construct(String $calle, String $numero, String $telefono, String $mail) {
        $this->calle = $calle;
        $this->numero = $numero;
        $this->telefono = $telefono;
        $this->mail = $mail;
    }
    
    public function getId() {
		return $this->id;	
	}

    public function getCalle() {
        return $this->calle;
    }

    public function setCalle(String $calle) {
        $this->calle = $calle;

        return $this;
    }

    public function getNumero($numero) {
        return $this->numero;
    }

    public function setNumero(String $numero) {
        $this->numero = $numero;

        return $this;
    }

    public function getTelefono() {
        return $this->telefono;
    }
    
    public function setTelefono(String $telefono) {
        $this->telefono = $telefono;
    
        return $this;
    }

    public function getMail() {
        return $this->mail;
    }

    public function setMail(String $mail) {
        $this->mail = $mail;

        return $this;
    }
}
