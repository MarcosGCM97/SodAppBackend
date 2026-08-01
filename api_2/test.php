<?php
include "conexion.php";

echo "Probando conexión...<br>";
$db = new Database();
$con = $db->connect();

if ($con) {
    echo "✓ Conexión exitosa<br>";
    $result = mysqli_query($con, "SELECT 1");
    if ($result) echo "✓ Query ejecutada<br>";
    mysqli_close($con);
} else {
    echo "✗ Conexión fallida<br>";
}
?>