<?php
include 'conexion.php';

//reportar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Consulta para obtener las ventas
$query = "SELECT * FROM sap_vt00 WHERE vt_fec >= NOW() - INTERVAL 2 DAY ORDER BY vt_fec DESC";
$result = mysqli_query($con, $query);
// Verificar si la consulta se ejecutó correctamente
if (!$result) {
    die("Error en la consulta: " . mysqli_error($con));
}
// Crear un array para almacenar los datos de las ventas
$ventas = array();
while ($row = mysqli_fetch_assoc($result)) {
    $clienteId = $row['vt_cli'];
    $queryCliente = "SELECT * FROM sap_cl00 WHERE cl_ide = ?";
    $stmtCliente = mysqli_prepare($con, $queryCliente);
    if ($stmtCliente === false) {
        die("Error al preparar la consulta del cliente: " . mysqli_error($con));
    }
    mysqli_stmt_bind_param($stmtCliente, "i", $clienteId);
    mysqli_stmt_execute($stmtCliente);
    $resultCliente = mysqli_stmt_get_result($stmtCliente);
    if (!$resultCliente) {
        die("Error al ejecutar la consulta del cliente: " . mysqli_error($con));
    }
    $clienteData = mysqli_fetch_assoc($resultCliente);


    $venta['vt_ide'] = $row['vt_ide'];
    $venta['vt_cli'] = $clienteData;
    $venta['vt_pro'] = $row['vt_pro'];
    $venta['vt_can'] = $row['vt_can'];
    $venta['vt_fec'] = $row['vt_fec'];
    $ventas[] = $venta; // Agregar cada fila al array de ventas
}
// Devolver los datos en formato JSON
header('Content-Type: application/json');
echo json_encode($ventas);
// Cerrar la conexión
mysqli_free_result($result);
mysqli_close($con);
?>