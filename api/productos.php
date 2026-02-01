<?php
require_once __DIR__ . '/lib.php';
allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['nombre']) && $_GET['nombre'] !== '') {

        $nombre = $_GET['nombre'];

        $stmt = prepare_or_fail($con, 'SELECT * FROM sap_pr00 WHERE updated_at IS NULL AND pr_nom = ?');
       
        mysqli_stmt_bind_param($stmt, 's', $nombre);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);

        $row = mysqli_fetch_assoc($res);

        if ($row) send_json(["success" => true, "producto" => $row]);

        send_json(["success" => false, "error" => "Producto no encontrado"], 404);
    } else {
        $res = mysqli_query($con, 'SELECT * FROM sap_pr00 WHERE updated_at IS NULL');

        if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
        
        send_json(["success" => true, "productos" => $arr]);
    }
}

if ($method === 'POST') {
    $data = get_json_input();

    $nombre = isset($data['nombrePr']) ? $data['nombrePr'] : (isset($data['name']) ? $data['name'] : null);
    
    $precio = isset($data['precioPr']) ? $data['precioPr'] : (isset($data['price']) ? $data['price'] : null);
    
    $cantidad = isset($data['cantidadPr']) ? $data['cantidadPr'] : (isset($data['stock']) ? $data['stock'] : null);
    
    if (!$nombre || $precio === null || $cantidad === null) send_json(["success" => false, "error" => "Datos incompletos"], 400);

    // check exists
    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_pr00 WHERE pr_nom = ?');

    mysqli_stmt_bind_param($check, 's', $nombre);

    mysqli_stmt_execute($check);

    $resCheck = mysqli_stmt_get_result($check);

    if ($resCheck && mysqli_num_rows($resCheck) > 0) send_json(["success"=>false,"error"=>"Ya existe producto"],400);

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_pr00 SET pr_nom = ?, pr_val = ?, pr_stk = ?, created_at = cur_date()');

    mysqli_stmt_bind_param($stmt, 'sdi', $nombre, $precio, $cantidad);

    if (mysqli_stmt_execute($stmt)) send_json(["success"=>true,"message"=>"Producto creado","id"=>mysqli_insert_id($con)],201);
    
    send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
}

if ($method === 'PUT') {
    //SE CREA UN NUEVO PRODUCTO, CON UN NUEVO PRECIO Y EL PRODUCTO ORIGINAL SE DESACTIVA
    $data = get_json_input();

    $nombre = isset($data['nombre']) ? $data['nombre'] : (isset($data['name']) ? $data['name'] : null);

    $precio = isset($data['precio']) ? $data['precio'] : (isset($data['price']) ? $data['price'] : null);

    $cantidad = isset($data['cantidad']) ? $data['cantidad'] : (isset($data['stock']) ? $data['stock'] : null);

    if (!$nombre || $precio === null || $cantidad === null) send_json(["success" => false, "error" => "Datos incompletos"], 400);
    
    // begin transaction (best effort)
    mysqli_autocommit($con, false);

    //update prod original
    $stmt = prepare_or_fail($con, 'UPDATE sap_pr00 SET updated_at = cur_date() WHERE pr_nom = ?');

    mysqli_stmt_bind_param($stmt, 's', $nombre);

    if (! mysqli_stmt_execute($stmt)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
    }

    $stmt2 = prepare_or_fail($con, 'INSERT INTO sap_pr00  (pr_nom, pr_val, pr_stk, created_at) VALUES (?, ?, ?, cur_date())');

    mysqli_stmt_bind_param($stmt2, 'sdi', $nombre, $precio, $cantidad);

    if (! mysqli_stmt_execute($stmt2)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
    }
    
    mysqli_commit($con);
    mysqli_autocommit($con, true);
    
    send_json(["success"=>true,"message"=>"Producto actualizado"]);
}

if ($method === 'DELETE') {
    $nombre = isset($_GET['nombre']) ? $_GET['nombre'] : '';

    if ($nombre === '') send_json(["success"=>false,"error"=>"Nombre requerido"],400);

    $stmt = prepare_or_fail($con, 'UPDATE FROM sap_pr00 SET deleted_at = cur_date() WHERE pr_nom = ?');

    mysqli_stmt_bind_param($stmt, 's', $nombre);

    if (mysqli_stmt_execute($stmt)) {
        if ($stmt->affected_rows > 0) send_json(["success"=>true,"message"=>"Producto eliminado"]);

        send_json(["success"=>false,"error"=>"Producto no encontrado"],404);
    }
    
    send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
}

send_json(["success"=>false,"error"=>"Método no soportado"],405);

?>
