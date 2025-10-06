<?php
include('conexion.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
$nombre = isset($_GET['nombre']) ? $_GET['nombre'] : '';
$precio = isset($_GET['precio']) ? doubleval($_GET['precio']) : 0.0;
$cantidad = isset($_GET['cantidad']) ? intval($_GET['cantidad']) : 0;


if (empty($nombre) || $precio <= 0 || $cantidad <= 0) {
    echo json_encode([
        "success" => false,
        "error" => "Datos inválidos"
    ]);
    exit;
}
$stmt = $con->prepare('UPDATE sap_pr00 SET pr_val = ?, pr_stk = ? WHERE pr_nom = ?');
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}
$stmt->bind_param('dis', $precio, $cantidad, $nombre);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Producto actualizado correctamente"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Producto no encontrado o ya eliminado"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}
?>