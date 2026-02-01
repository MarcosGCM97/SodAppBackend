<?php

class Database {
   private $host ="127.0.0.1";
   private $db ="sodapp";
   private $user ="root";
   private $pwd ="";

   public function connect() {
       $con = mysqli_connect($this->host, $this->user, $this->pwd, $this->db);

       if (!$con->set_charset("utf8")) {

          printf("Error cargando el conjunto de caracteres utf8: %s\n", $con->error);
       } else {
          // Para diagnosticar si algo anda mal con la codificación
          //printf("Conjunto de caracteres actual: %s\n", $con->character_set_name());
          //echo "<br>";
       }
       $con->query('SET NAMES utf8');

       // verifica conexion
       if(mysqli_connect_errno()) {

          die("Error al conectarse con MySQL: " . mysqli_connect_error());

       }
       return $con;
   }
}

