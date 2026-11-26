<?php
require_once __DIR__ . '/config.php';

class Database {
   private $host;
   private $db;
   private $user;
   private $pwd;

   public function __construct() {
       $this->host = config_value('DB_HOST');
       $this->db = config_value('DB_NAME');
       $this->user = config_value('DB_USER');
       $this->pwd = config_value('DB_PASS');
   }

   public function connect() {
      $con = mysqli_connect($this->host, $this->user, $this->pwd, $this->db);

      // VALIDAR PRIMERO si la conexión fue exitosa
      if (!$con || mysqli_connect_errno()) {
         error_log("Error al conectarse con MySQL: " . mysqli_connect_error());
         http_response_code(500);
         die(json_encode(['error' => 'Error al conectarse con la base de datos'], JSON_UNESCAPED_UNICODE));
      }

      // Ahora sí puedes usar $con
      // utf8mb4, like the tables: MySQL "utf8" only takes 3-byte characters and rejects emoji
      // (the default WhatsApp messages have them) with "Incorrect string value"
      if (!$con->set_charset("utf8mb4")) {
         printf("Error cargando charset utf8mb4: %s\n", $con->error);
      }

      $con->query('SET NAMES utf8mb4');

      // MySQL dates (NOW(), DEFAULT CURRENT_TIMESTAMP such as pg_fec) must use the same
      // clock as PHP dates (vt_fec), whatever timezone the MySQL server runs on.
      // date('P') is generated here ("-03:00"), never user input.
      $con->query("SET time_zone = '" . date('P') . "'");
      return $con;
   }
}

