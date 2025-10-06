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
$stmt = $con->prepare('SELECT * FROM sap_cl00 WHERE cl_ide = ?');
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}
$stmt->bind_param('i', $id);
if ($stmt->execute()) {
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        echo json_encode(
            $result->fetch_assoc()
        );
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Cliente no encontrado $id"
        ]);
    }

} else {
    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}
?>