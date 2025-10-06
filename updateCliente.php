<?php
include('conexion.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$nombre = isset($_GET['nombre']) ? $_GET['nombre'] : '';
$direccion = isset($_GET['direccion']) ? $_GET['direccion'] : '';
$telefono = isset($_GET['telefono']) ? $_GET['telefono'] : '';

if ($id <= 0 || empty($nombre) || empty($direccion) || empty($telefono)) {
    echo json_encode([
        "success" => false,
        "error" => "Datos inválidos"
    ]);
    exit;
}
$stmt = $con->prepare('UPDATE sap_cl00 SET cl_nom = ?, cl_dir = ?, cl_tel = ? WHERE cl_ide = ?');
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}
$stmt->bind_param('sssi', $nombre, $direccion, $telefono, $id);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Cliente actualizado correctamente"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Cliente no encontrado o ya eliminado"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}
?>