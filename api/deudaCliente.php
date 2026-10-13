<?php
require_once('lib.php');
require_once __DIR__ . '/auth.php';

allow_cors();

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

$method = $_SERVER['REQUEST_METHOD'];

//GET /api/deuda.php?id=1
if ($method === 'GET') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    // If id provided, return single debtor
    if ($id > 0) {
        $stmt = prepare_or_fail($con, 'SELECT cl_ide, cl_nom, cl_deb FROM sap_cl00 WHERE cl_ide = ? AND cl_emp = ? AND is_deleted = 0');
        mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        if ($row) send_json(["success" => true, "deudor" => $row]);
        send_json(["success" => false, "error" => "Cliente no encontrado"], 404);
    }

    // Otherwise list debtors
    // $userId is an int from the token; plain query keeps the string-typed JSON of this list
    $res = mysqli_query($con, 'SELECT cl_ide, cl_nom, cl_deb FROM sap_cl00 WHERE cl_emp = ' . intval($userId) . ' AND cl_deb > 0 AND is_deleted = 0');
    if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

    $arr = [];
    while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;

    send_json(["success" => true, "deudores" => $arr]);
}

if ($method === "PUT") {
    // The Android app sends ?id=..&deuda=.. as query params; other clients may send a JSON body
    $data = isset($_GET['id']) ? $_GET : get_json_input();

    $id = isset($data['id']) && !is_array($data['id']) ? intval($data['id']) : 0;

    if ($id <= 0) send_json(["success" => false, "error" => "ID inválido"], 400);

    $totalDeuda = isset($data['deuda']) && !is_array($data['deuda']) ? floatval($data['deuda']) : 0;

    if ($totalDeuda <= 0) {
        send_json(["success" => false, "error" => "La deuda debe ser mayor a 0"], 400);
    }

    // The cliente must belong to the token user
    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_cl00 WHERE cl_ide = ? AND cl_emp = ? AND is_deleted = 0');
    mysqli_stmt_bind_param($check, 'ii', $id, $userId);
    mysqli_stmt_execute($check);
    $resCheck = mysqli_stmt_get_result($check);
    if (!$resCheck || mysqli_num_rows($resCheck) === 0) {
        send_json(["success" => false, "error" => "Cliente no encontrado"], 404);
    }

    $queryUpdateDeuda = "
        UPDATE sap_cl00
        SET cl_deb = cl_deb - ?
        WHERE cl_ide = ?
          AND cl_emp = ?
          AND cl_deb - ? >= 0
          AND cl_deb > 0
    ";

    $stmt = prepare_or_fail($con, $queryUpdateDeuda);

    mysqli_stmt_bind_param($stmt, 'diid', $totalDeuda, $id, $userId, $totalDeuda);

    if (mysqli_stmt_execute($stmt)) {
        if ($stmt->affected_rows > 0) {
            send_json([
                "success" => true,
                "message" => "Deuda actualizada correctamente"
            ]);
        }
        // The cliente exists and is owned by the user, so no affected row means the amount exceeds the debt
        send_json([
            "success" => false,
            "error" => "El monto supera la deuda actual del cliente"
        ], 409);
    }

    send_json([
        "success" => false,
        "error" => "Metodo no soportado"
    ], 405);

}
