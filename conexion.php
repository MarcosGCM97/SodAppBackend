<?php

$host ="www.unont.com.ar";
$db ="laMutua";
$user ="x077vm05_unont";
$pwd ="Altos1948";

$con = mysqli_connect($host, $user, $pwd, $db);

if (!$con->set_charset("utf8")) {
   printf("Error cargando el conjunto de caracteres utf8: %s\n", $con->error);
} else {
   // Para diagnosticar si algo anda mal con la codificación
   //printf("Conjunto de caracteres actual: %s\n", $con->character_set_name());
   //echo "<br>";
}
$con->query('SET NAMES utf8');

// verifica conexion
if(mysqli_connect_errno($con)) {
   die("Error al conectarse con MySQL: " . mysqli_connect_error());
   }
//echo "Conexión exitosa a la base de datos: " . $db;
?>