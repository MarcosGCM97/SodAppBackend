<?php

class Database {
   private $host;
   private $db;
   private $user;
   private $pwd;

   public function __construct() {
       // Cargar configuración desde variable de entorno o archivo
       $this->host = getenv('DB_HOST') ?: 'localhost';
       $this->db = getenv('DB_NAME') ?: 'c2761775_sodapp';
       $this->user = getenv('DB_USER') ?: 'c2761775_sodapp';
       $this->pwd = getenv('DB_PASS') ?: 'TUfiresi31';
   }

   public function connect() {
      $con = mysqli_connect($this->host, $this->user, $this->pwd, $this->db);

      // VALIDAR PRIMERO si la conexión fue exitosa
      if (!$con || mysqli_connect_errno()) {
         $error = "Error al conectarse con MySQL: " . mysqli_connect_error();
         error_log($error);
         die(json_encode(['error' => $error], JSON_UNESCAPED_UNICODE));
      }

      // Ahora sí puedes usar $con
      if (!$con->set_charset("utf8")) {
         printf("Error cargando charset utf8: %s\n", $con->error);
      }
      
      $con->query('SET NAMES utf8');
      return $con;
   }
}

