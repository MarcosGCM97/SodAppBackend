<?php
include('conexion.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$deuda = isset($_GET['deuda']) ? doubleval($_GET['deuda']) : 0;

if ($id <= 0) {
    echo json_encode([
        "success" => false,
        "error" => "ID inválido"
    ]);
    exit;
}
//Buscar deuda existente
$queryDeuda = 'SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?';
$stmt = $con->prepare($queryDeuda);
if (!$stmt) {
    die(json_encode([
        "success" => false,
        "error" => $con->error
    ]));
}
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die(json_encode([
        "success" => false,
        "error" => "Cliente con deuda no encontrado"
    ]));
}
$row = $result->fetch_assoc();
$deudaActual = $row['cl_deb'];

// Validar que la deuda no sea negativa
$totalDeuda = $deudaActual - $deuda;
if ($deuda < 0 || $totalDeuda < 0) {
    echo json_encode([
        "success" => false,
        "error" => "La deuda no puede ser negativa"
    ]);
    exit;
}

//Actualizar deuda
$stmt = $con->prepare('UPDATE sap_cl00 SET cl_deb = ? WHERE cl_ide = ?');
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}
$stmt->bind_param('di', $totalDeuda, $id);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Deuda actualizada correctamente"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "No se encontró el cliente o no se modificó la deuda"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}
?>