<?php 
include('conexion.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
// Obtener el ID del producto desde el cuerpo de la solicitud
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode([
        "success" => false,
        "error" => "ID inválido"
    ]);
    exit;
}

//buscar ventas por ide venta

$qrUpdate = 'DELETE FROM sap_vt00 WHERE vt_ide = ?';
$stmtUpdate = $con->prepare($qrUpdate);
if (!$stmtUpdate) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}
$stmtUpdate->bind_param('i', $id);
if ($stmtUpdate->execute()) {
    if ($stmtUpdate->affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Venta borrada correctamente"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "No se pudo borrar la venta"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => $stmtUpdate->error
    ]);
}
    


?>