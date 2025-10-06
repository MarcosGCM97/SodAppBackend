<?php
include('conexion.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode([
        "success" => false,
        "error" => "ID inválido"
    ]);
    exit;
}
$query='DELETE FROM sap_us00 WHERE id_us00 = ?';
$stmt = $con->prepare($query);
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}
$stmt->bind_param('i', $id);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Cliente eliminado correctamente"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "No se encontró el cliente con el ID proporcionado"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => "Error al eliminar el cliente: " . $stmt->error
    ]);
}
?>