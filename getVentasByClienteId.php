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

//buscar ventas por cliente
$stmt = $con->prepare('SELECT cl.cl_nom, cl.cl_tel, pr.pr_nom, pr.pr_val, vt.vt_pro, vt.vt_can, vt.vt_fec, vt.vt_ide
FROM sap_vt00 AS vt
JOIN sap_cl00 AS cl ON cl.cl_ide = vt.vt_cli
JOIN sap_pr00 AS pr ON pr.pr_nom = vt.vt_pro
WHERE vt.vt_cli = ? ORDER BY vt.vt_fec DESC
');

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
    if ($result->num_rows > 0) {
        $ventas = [];
        while ($row = $result->fetch_assoc()) {
            $ventas[] = $row;
        }
        echo json_encode([
            "success" => true,
            "ventas" => $ventas
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "No se encontraron ventas para el cliente con ID: $id"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}
?>