<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Every method requires a valid token; the company (owner id) always comes from it
$companyId = current_company_id();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // also read while loading a sale, a reparto or a client's debt
    require_permission(['stock.ver', 'ventas.ver', 'ventas.cargar', 'repartos.ver', 'repartos.gestionar', 'cobros.ver']);
    // pr_cli: containers held by the owner's non-deleted clientes (sum of their balances)
    $selectProducto = 'SELECT p.*, (SELECT COALESCE(SUM(ce.ce_can), 0) FROM sap_ce00 ce
            JOIN sap_cl00 c ON c.cl_ide = ce.ce_cli AND c.is_deleted = 0
            WHERE ce.ce_pro = p.pr_ide AND ce.ce_emp = p.pr_emp) AS pr_cli
        FROM sap_pr00 p WHERE p.deleted_at IS NULL';

    if (isset($_GET['nombre']) && $_GET['nombre'] !== '') {

        $nombre = $_GET['nombre'];

        $stmt = prepare_or_fail($con, $selectProducto . ' AND p.pr_nom = ? AND p.pr_emp = ?');

        mysqli_stmt_bind_param($stmt, 'si', $nombre, $companyId);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);

        $row = mysqli_fetch_assoc($res);

        if ($row) send_json(["success" => true, "producto" => $row]);

        send_json(["success" => false, "error" => "Producto no encontrado"], 404);
    } else {
        // $companyId is an int from the token; plain query keeps the string-typed JSON of this list
        $res = mysqli_query($con, $selectProducto . ' AND p.pr_emp = ' . intval($companyId));

        if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
        
        send_json(["success" => true, "productos" => $arr]);
    }
}

if ($method === 'POST') {
    require_permission('stock.editar');
    $data = get_json_input();

    $nombre = isset($data['nombrePr']) ? $data['nombrePr'] : (isset($data['name']) ? $data['name'] : null);
    
    $precio = isset($data['precioUni']) ? $data['precioUni'] : (isset($data['price']) ? $data['price'] : null);
    
    $cantidad = isset($data['stock']) ? $data['stock'] : (isset($data['stock']) ? $data['stock'] : null);
    
    if (!$nombre || $precio === null || $cantidad === null) send_json(["success" => false, "error" => "Datos incompletos"], 400);

    // Returnable containers (optional, old app versions omit them): retornable defaults to false, vacios to 0
    $retornable = isset($data['retornable']) ? parse_flag($data['retornable']) : 0;

    if ($retornable === null) send_json(["success" => false, "error" => "Dato inválido: retornable debe ser verdadero o falso"], 400);

    $vacios = isset($data['vacios']) ? parse_non_negative_int($data['vacios']) : 0;

    if ($vacios === null) send_json(["success" => false, "error" => "Dato inválido: vacios debe ser un número entero mayor o igual a 0"], 400);

    // check exists (names are unique per owner: uq_pr_emp_nom)
    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_pr00 WHERE pr_nom = ? AND pr_emp = ?');

    mysqli_stmt_bind_param($check, 'si', $nombre, $companyId);

    mysqli_stmt_execute($check);

    $resCheck = mysqli_stmt_get_result($check);

    if ($resCheck && mysqli_num_rows($resCheck) > 0) send_json(["success"=>false,"error"=>"Ya existe producto"],409);

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_pr00 SET pr_nom = ?, pr_val = ?, pr_stk = ?, pr_emp = ?, pr_ret = ?, pr_vac = ?, created_at = CURDATE()');

    mysqli_stmt_bind_param($stmt, 'sdiiii', $nombre, $precio, $cantidad, $companyId, $retornable, $vacios);

    // The product and its "alta" movement are saved together
    mysqli_autocommit($con, false);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_rollback($con);

        // concurrent insert of the same name hits the unique key
        if (mysqli_stmt_errno($stmt) === 1062) send_json(["success"=>false,"error"=>"Ya existe producto"],409);

        send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
    }

    $productoId = mysqli_insert_id($con);

    if (!record_stock_movement($con, $companyId, $nombre, 'alta', intval($cantidad)) || !mysqli_commit($con)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>mysqli_error($con)],500);
    }
    mysqli_autocommit($con, true);

    send_json(["success"=>true,"message"=>"Producto creado","id"=>$productoId],201);
}

if ($method === 'PUT') {
    require_permission('stock.editar');
    $nombre = isset($_GET['nombre']) ? $_GET['nombre'] : null;

    $precio = isset($_GET['precio']) ? $_GET['precio'] : null;

    $cantidad = isset($_GET['cantidad']) ? $_GET['cantidad'] : null;

    if (!$nombre || $precio === null || $cantidad === null) send_json(["success" => false, "error" => "Datos incompletos"], 400);

    // Optional retornable (0/1) and vacios (absolute); when omitted those columns are not touched
    $retornable = isset($_GET['retornable']) ? parse_flag($_GET['retornable']) : false;

    if ($retornable === null) send_json(["success" => false, "error" => "Dato inválido: retornable debe ser 0 o 1"], 400);

    $vacios = isset($_GET['vacios']) ? parse_non_negative_int($_GET['vacios']) : false;

    if ($vacios === null) send_json(["success" => false, "error" => "Dato inválido: vacios debe ser un número entero mayor o igual a 0"], 400);

    // The edit and its "ajuste" movement are saved together
    mysqli_autocommit($con, false);

    // Ownership check first (an UPDATE with unchanged values affects 0 rows); the lock
    // keeps the previous stock valid until the movement is recorded
    $check = prepare_or_fail($con, 'SELECT COALESCE(pr_stk, 0) AS pr_stk FROM sap_pr00 WHERE pr_nom = ? AND pr_emp = ? AND deleted_at IS NULL FOR UPDATE');

    mysqli_stmt_bind_param($check, 'si', $nombre, $companyId);

    mysqli_stmt_execute($check);

    $resCheck = mysqli_stmt_get_result($check);

    $actual = $resCheck ? mysqli_fetch_assoc($resCheck) : null;

    if (!$actual) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>"Producto no encontrado"],404);
    }

    $sets = 'pr_val = ?, pr_stk = ?';

    $types = 'di';

    $params = [$precio, $cantidad];

    if ($retornable !== false) {
        $sets .= ', pr_ret = ?';
        $types .= 'i';
        $params[] = $retornable;
    }

    if ($vacios !== false) {
        $sets .= ', pr_vac = ?';
        $types .= 'i';
        $params[] = $vacios;
    }

    $stmt = prepare_or_fail($con, "UPDATE sap_pr00 SET $sets WHERE pr_nom = ? AND pr_emp = ? AND deleted_at IS NULL");

    $types .= 'si';

    $params[] = $nombre;

    $params[] = $companyId;

    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_rollback($con);

        if (mysqli_stmt_errno($stmt) === 1062) send_json(["success"=>false,"error"=>"Ya existe producto"],409);

        send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
    }

    // Only a stock change is a movement: price or container edits record nothing
    $diferencia = intval($cantidad) - intval($actual['pr_stk']);

    if ($diferencia !== 0 && !record_stock_movement($con, $companyId, $nombre, 'ajuste', $diferencia)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>mysqli_error($con)],500);
    }

    if (!mysqli_commit($con)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>"No se pudo confirmar la transacción"],500);
    }
    mysqli_autocommit($con, true);

    send_json(["success"=>true,"message"=>"Producto actualizado"]);
}

if ($method === 'DELETE') {
    require_permission('stock.editar');
    $nombre = isset($_GET['nombre']) ? $_GET['nombre'] : '';

    if ($nombre === '') send_json(["success"=>false,"error"=>"Nombre requerido"],400);

    $stmt = prepare_or_fail($con, 'UPDATE sap_pr00 SET deleted_at = CURDATE() WHERE pr_nom = ? AND pr_emp = ?');

    mysqli_stmt_bind_param($stmt, 'si', $nombre, $companyId);

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
