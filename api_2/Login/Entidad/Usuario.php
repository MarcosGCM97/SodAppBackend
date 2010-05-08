<?php
require_once __DIR__ . '/Persona.php';

class Usuario extends Persona{
	private $id;
    private $usuario;
    private $pass;

    public function __construct(String $usuario, String $contrasena) {
        $this->usuario = $usuario;
        $this->pass = $pass;
    }
    
    public function getId() {
		return $this->id;	
	}

    public function getUsuario() {
        return $this->usuario;
    }

    public function setUsuario(String $usuario) {
        $this->usuario = $usuario;

        return $this;
    }

    public function getPass() {
        return $this->pass;
    }

    public function setPass(String $pass) {
        $this->pass = $pass;
    }
}
