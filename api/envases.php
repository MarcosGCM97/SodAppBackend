<?php
// Returnable containers (envases).
// POST {"accion":"llenar","producto":"<nombre>","cantidad":N}: refill N empty containers (pr_vac -> pr_stk).
// GET ?clienteId=: container balance of a cliente for every returnable product.
// PUT {"clienteId","productoId","cantidad"}: set the absolute balance (manual initial load / correction).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

$method = $_SERVER['REQUEST_METHOD'];

// Owned, non-deleted cliente or a 404
function require_cliente($con, $clienteId, $userId) {
    $stmt = prepare_or_fail($con, 'SELECT cl_ide FROM sap_cl00 WHERE cl_ide = ? AND cl_emp = ? AND is_deleted = 0');

    mysqli_stmt_bind_param($stmt, 'ii', $clienteId, $userId);

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    if (!$res || mysqli_num_rows($res) === 0) send_json(["success"=>false,"error"=>"Cliente no encontrado o eliminado"],404);
}

if ($method === 'GET') {
    $clienteId = isset($_GET['clienteId']) ? parse_non_negative_int($_GET['clienteId']) : null;

    if (!$clienteId) send_json(["success"=>false,"error"=>"clienteId requerido"],400);

    require_cliente($con, $clienteId, $userId);

    // Every owned, non-deleted returnable product; 0 when the cliente has no balance row yet
    $stmt = prepare_or_fail($con, 'SELECT pr.pr_ide, pr.pr_nom, COALESCE(ce.ce_can, 0) AS ce_can
        FROM sap_pr00 pr
        LEFT JOIN sap_ce00 ce ON ce.ce_pro = pr.pr_ide AND ce.ce_cli = ? AND ce.ce_emp = pr.pr_emp
        WHERE pr.pr_emp = ? AND pr.deleted_at IS NULL AND pr.pr_ret = 1
        ORDER BY pr.pr_nom');

    mysqli_stmt_bind_param($stmt, 'ii', $clienteId, $userId);

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    if (!$res) send_json(["success"=>false,"error"=>mysqli_error($con)],500);

    $arr = [];

    while ($r = mysqli_fetch_assoc($res)) {
        $arr[] = ['pr_ide' => intval($r['pr_ide']), 'pr_nom' => $r['pr_nom'], 'ce_can' => intval($r['ce_can'])];
    }

    send_json(["success"=>true,"envases"=>$arr]);
}

if ($method === 'POST') {
    $data = get_json_input();

    $accion = isset($data['accion']) ? $data['accion'] : null;

    if ($accion !== 'llenar') send_json(["success"=>false,"error"=>"Acción no soportada"],400);

    $nombre = isset($data['producto']) && is_string($data['producto']) ? $data['producto'] : '';

    $cantidad = isset($data['cantidad']) ? parse_non_negative_int($data['cantidad']) : null;

    if ($nombre === '' || !$cantidad) {
        send_json(["success"=>false,"error"=>"Datos inválidos: producto y cantidad (entero mayor a 0) son requeridos"],400);
    }

    $stmt = prepare_or_fail($con, 'SELECT pr_ide, pr_ret FROM sap_pr00 WHERE pr_nom = ? AND pr_emp = ? AND deleted_at IS NULL');

    mysqli_stmt_bind_param($stmt, 'si', $nombre, $userId);

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    $producto = $res ? mysqli_fetch_assoc($res) : null;

    if (!$producto) send_json(["success"=>false,"error"=>"Producto no encontrado"],404);

    if (intval($producto['pr_ret']) !== 1) send_json(["success"=>false,"error"=>"El producto $nombre no es retornable"],400);

    $productoId = intval($producto['pr_ide']);

    // Single atomic statement: only applies while enough empties remain
    $stmt = prepare_or_fail($con, 'UPDATE sap_pr00 SET pr_vac = pr_vac - ?, pr_stk = COALESCE(pr_stk, 0) + ?
        WHERE pr_ide = ? AND pr_emp = ? AND deleted_at IS NULL AND pr_ret = 1 AND pr_vac >= ?');

    mysqli_stmt_bind_param($stmt, 'iiiii', $cantidad, $cantidad, $productoId, $userId, $cantidad);

    if (!mysqli_stmt_execute($stmt)) send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);

    if (mysqli_stmt_affected_rows($stmt) === 0) send_json(["success"=>false,"error"=>"No hay suficientes envases vacíos"],409);

    $stmt = prepare_or_fail($con, 'SELECT * FROM sap_pr00 WHERE pr_ide = ? AND pr_emp = ?');

    mysqli_stmt_bind_param($stmt, 'ii', $productoId, $userId);

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    send_json(["success"=>true,"producto"=>mysqli_fetch_assoc($res)]);
}

if ($method === 'PUT') {
    $data = get_json_input();

    $clienteId = isset($data['clienteId']) ? parse_non_negative_int($data['clienteId']) : null;

    $productoId = isset($data['productoId']) ? parse_non_negative_int($data['productoId']) : null;

    if (!$clienteId || !$productoId || !isset($data['cantidad'])) {
        send_json(["success"=>false,"error"=>"Datos incompletos: clienteId, productoId y cantidad son requeridos"],400);
    }

    $cantidad = parse_non_negative_int($data['cantidad']);

    if ($cantidad === null) send_json(["success"=>false,"error"=>"Dato inválido: cantidad debe ser un número entero mayor o igual a 0"],400);

    require_cliente($con, $clienteId, $userId);

    $stmt = prepare_or_fail($con, 'SELECT pr_nom, pr_ret FROM sap_pr00 WHERE pr_ide = ? AND pr_emp = ? AND deleted_at IS NULL');

    mysqli_stmt_bind_param($stmt, 'ii', $productoId, $userId);

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    $producto = $res ? mysqli_fetch_assoc($res) : null;

    if (!$producto) send_json(["success"=>false,"error"=>"Producto no encontrado"],404);

    if (intval($producto['pr_ret']) !== 1) send_json(["success"=>false,"error"=>"El producto " . $producto['pr_nom'] . " no es retornable"],400);

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_ce00 (ce_cli, ce_pro, ce_can, ce_emp) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ce_can = VALUES(ce_can)');

    mysqli_stmt_bind_param($stmt, 'iiii', $clienteId, $productoId, $cantidad, $userId);

    if (!mysqli_stmt_execute($stmt)) send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);

    send_json(["success"=>true,"envase"=>["pr_ide"=>$productoId,"pr_nom"=>$producto['pr_nom'],"ce_can"=>$cantidad]]);
}

send_json(["success"=>false,"error"=>"Método no soportado"],405);

?>
