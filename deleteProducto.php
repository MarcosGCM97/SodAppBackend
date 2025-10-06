<?php
include('conexion.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
$nombre = isset($_GET['nombre']) ? strval($_GET['nombre']) : "";
if (empty($nombre)) {
    echo json_encode([
        "success" => false,
        "error" => "Nombre de producto no proporcionado"
    ]);
    exit;
}
$stmt = $con->prepare('DELETE FROM sap_pr00 WHERE pr_nom = ?');
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}
$stmt->bind_param('s', $nombre);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Producto eliminada correctamente"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "No se encontró la producto con el nombre proporcionado"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => "Error al eliminar el producto: " . $stmt->error
    ]);
}
?>