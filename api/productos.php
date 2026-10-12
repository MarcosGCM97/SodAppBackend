<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['nombre']) && $_GET['nombre'] !== '') {

        $nombre = $_GET['nombre'];

        $stmt = prepare_or_fail($con, 'SELECT * FROM sap_pr00 WHERE deleted_at IS NULL AND pr_nom = ? AND pr_emp = ?');

        mysqli_stmt_bind_param($stmt, 'si', $nombre, $userId);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);

        $row = mysqli_fetch_assoc($res);

        if ($row) send_json(["success" => true, "producto" => $row]);

        send_json(["success" => false, "error" => "Producto no encontrado"], 404);
    } else {
        // $userId is an int from the token; plain query keeps the string-typed JSON of this list
        $res = mysqli_query($con, 'SELECT * FROM sap_pr00 WHERE deleted_at IS NULL AND pr_emp = ' . intval($userId));

        if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
        
        send_json(["success" => true, "productos" => $arr]);
    }
}

if ($method === 'POST') {
    $data = get_json_input();

    $nombre = isset($data['nombrePr']) ? $data['nombrePr'] : (isset($data['name']) ? $data['name'] : null);
    
    $precio = isset($data['precioUni']) ? $data['precioUni'] : (isset($data['price']) ? $data['price'] : null);
    
    $cantidad = isset($data['stock']) ? $data['stock'] : (isset($data['stock']) ? $data['stock'] : null);
    
    if (!$nombre || $precio === null || $cantidad === null) send_json(["success" => false, "error" => "Datos incompletos"], 400);

    // check exists (names are unique per owner: uq_pr_emp_nom)
    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_pr00 WHERE pr_nom = ? AND pr_emp = ?');

    mysqli_stmt_bind_param($check, 'si', $nombre, $userId);

    mysqli_stmt_execute($check);

    $resCheck = mysqli_stmt_get_result($check);

    if ($resCheck && mysqli_num_rows($resCheck) > 0) send_json(["success"=>false,"error"=>"Ya existe producto"],409);

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_pr00 SET pr_nom = ?, pr_val = ?, pr_stk = ?, pr_emp = ?, created_at = CURDATE()');

    mysqli_stmt_bind_param($stmt, 'sdii', $nombre, $precio, $cantidad, $userId);

    if (mysqli_stmt_execute($stmt)) send_json(["success"=>true,"message"=>"Producto creado","id"=>mysqli_insert_id($con)],201);

    // concurrent insert of the same name hits the unique key
    if (mysqli_stmt_errno($stmt) === 1062) send_json(["success"=>false,"error"=>"Ya existe producto"],409);

    send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
}

if ($method === 'PUT') {
    $nombre = isset($_GET['nombre']) ? $_GET['nombre'] : null;

    $precio = isset($_GET['precio']) ? $_GET['precio'] : null;

    $cantidad = isset($_GET['cantidad']) ? $_GET['cantidad'] : null;

    if (!$nombre || $precio === null || $cantidad === null) send_json(["success" => false, "error" => "Datos incompletos"], 400);

    // Ownership check first: an UPDATE with unchanged values affects 0 rows
    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_pr00 WHERE pr_nom = ? AND pr_emp = ? AND updated_at IS NULL');

    mysqli_stmt_bind_param($check, 'si', $nombre, $userId);

    mysqli_stmt_execute($check);

    $resCheck = mysqli_stmt_get_result($check);

    if (!$resCheck || mysqli_num_rows($resCheck) === 0) send_json(["success"=>false,"error"=>"Producto no encontrado"],404);

    $stmt = prepare_or_fail($con, 'UPDATE sap_pr00 SET pr_val = ?, pr_stk = ? WHERE pr_nom = ? AND pr_emp = ? AND updated_at IS NULL');

    mysqli_stmt_bind_param($stmt, 'disi', $precio, $cantidad, $nombre, $userId);

    if (mysqli_stmt_execute($stmt)) {
        send_json(["success"=>true,"message"=>"Producto actualizado"]);
    } else if (mysqli_stmt_errno($stmt) === 1062) {
        send_json(["success"=>false,"error"=>"Ya existe producto"],409);
    } else {
        send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
    }
}

if ($method === 'DELETE') {
    $nombre = isset($_GET['nombre']) ? $_GET['nombre'] : '';

    if ($nombre === '') send_json(["success"=>false,"error"=>"Nombre requerido"],400);

    $stmt = prepare_or_fail($con, 'UPDATE sap_pr00 SET deleted_at = CURDATE() WHERE pr_nom = ? AND pr_emp = ?');

    mysqli_stmt_bind_param($stmt, 'si', $nombre, $userId);

    if (mysqli_stmt_execute($stmt)) {
        if (mysqli_stmt_affected_rows($stmt) > 0) {
            send_json(["success"=>true,"message"=>"Producto eliminado"]);
        } else {
            send_json(["success"=>false,"error"=>"Producto no encontrado"],404);
        }
    } else {
        send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
    }
}

send_json(["success"=>false,"error"=>"Método no soportado"],405);

?>
