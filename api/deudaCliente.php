<?php
require_once('lib.php');

allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

//GET /api/deuda.php?id=1
if ($method === 'GET') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    $deuda = isset($_GET['deuda']) ? doubleval($_GET['deuda']) : 0;
    
    if ($id <= 0) send_json(["success" => false, "error" => mysqli_error($con)], 500);

    //Buscar deuda existente
    $stmtDeudaActual = prepare_or_fail($con, 'SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?');

    mysqli_stmt_bind_param($stmtDeudaActual, 'i', $id);

    mysqli_stmt_execute($stmtDeudaActual);

    $deudaActual = mysqli_stmt_get_result($stmtDeudaActual);
    
    $arr = [];
    
    while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
    
    send_json(["success" => true, "deudores" => $arr]);
}

if ($method === "PUT") {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id <= 0) send_json(["success" => false, "error" => "ID inválido"], 400);

    $totalDeuda = isset($_GET['deuda']) ? doubleval($_GET['deuda']) : 0;

    if (!$totalDeuda || $totalDeuda < 0) {
        send_json(["success" => false, "error" => "La deuda no puede ser negativa"], 400);
    }

    $queryUpdateDeuda = "
        UPDATE sap_cl00
        SET cl_deb = cl_deb - ?
        WHERE cl_ide = ?
          AND cl_deb - ? >= 0
          AND cl_deb > 0
    ";

    $stmt = prepare_or_fail($con, $queryUpdateDeuda);

    mysqli_stmt_bind_param($stmt, 'did', $totalDeuda, $id, $totalDeuda);

    if (mysqli_stmt_execute($stmt)) {
        if ($stmt->affected_rows > 0) {
            send_json([
                "success" => true,
                "message" => "Deuda actualizada correctamente"
            ]);
        }
        send_json([
            "success" => false,
            "error" => mysqli_stmt_error($stmt)
        ], 500);
    }

    send_json([
        "success" => false,
        "error" => "Metodo no soportado"
    ], 405);

}
