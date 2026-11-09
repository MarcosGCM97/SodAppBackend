<?php
// Stock movement history (historial de stock).
// GET ?productoId=: movements of an owned, non-deleted product, newest first.
// Rows are written by ventas.php, productos.php and envases.php (record_stock_movement).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Every method requires a valid token; the company (owner id) always comes from it
$companyId = current_company_id();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    require_permission('stock.ver');
    $productoId = isset($_GET['productoId']) ? parse_non_negative_int($_GET['productoId']) : null;

    if ($productoId === null || $productoId === 0) send_json(["success"=>false,"error"=>"productoId inválido"],400);

    $stmt = prepare_or_fail($con, 'SELECT pr_ide FROM sap_pr00 WHERE pr_ide = ? AND pr_emp = ? AND deleted_at IS NULL');

    mysqli_stmt_bind_param($stmt, 'ii', $productoId, $companyId);

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    if (!$res || mysqli_num_rows($res) === 0) send_json(["success"=>false,"error"=>"Producto no encontrado"],404);

    // Newest first; the latest 200 movements are enough for the app screen
    $stmt = prepare_or_fail($con, 'SELECT ms_ide, ms_tip, ms_can, ms_stk, ms_vta, ms_fec FROM sap_ms00
        WHERE ms_pro = ? AND ms_emp = ? ORDER BY ms_ide DESC LIMIT 200');

    mysqli_stmt_bind_param($stmt, 'ii', $productoId, $companyId);

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    if (!$res) send_json(["success"=>false,"error"=>mysqli_error($con)],500);

    $arr = [];

    while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;

    send_json(["success"=>true,"movimientos"=>$arr]);
}

// other methods not supported
send_json(["success"=>false,"error"=>"Método no soportado"],405);

?>
