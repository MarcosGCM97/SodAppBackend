<?php
include('conexion.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
$nombre = isset($_GET['nombre']) ? intval($_GET['nombre']) : 0;

if ($nombre <= 0) {
    echo json_encode([
        "success" => false,
        "error" => "nombre inválido"
    ]);
    exit;
}
$stmt = $con->prepare('SELECT FROM sap_pr00 WHERE nom_pr = ?');
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
            "message" => "Producto encontrado correctamente"
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