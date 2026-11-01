<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

$method = $_SERVER['REQUEST_METHOD'];

// GET: ?usuarioId= recent sales, ?id=&usuarioId= sales of a cliente. Always scoped to the
// token user: usuarioId is still accepted (it selects the response shape) but never used as owner.
if ($method === 'GET') {
    $usuarioId = isset($_GET['usuarioId']) ? $_GET['usuarioId'] : null;
    $clienteId = isset($_GET['id']) && intval($_GET['id'])>0 ? intval($_GET['id']) : null;

    // Case 1: Filter by cliente AND usuarioId
    if ($clienteId && $usuarioId) {
        $stmt = prepare_or_fail($con, 'SELECT cl.cl_nom, cl.cl_dir, cl.cl_tel, pr.pr_nom, pr.pr_val, vt.vt_can, vt.vt_fec, vt.vt_ide
        FROM sap_vt00 AS vt
        JOIN sap_cl00 AS cl ON cl.cl_ide = vt.vt_cli
        JOIN sap_pr00 AS pr ON pr.pr_nom = vt.vt_pro AND pr.pr_emp = vt.vt_emp
        WHERE vt.vt_cli = ? AND vt.vt_emp = ? ORDER BY vt.vt_fec DESC
        LIMIT 15');

        mysqli_stmt_bind_param($stmt, 'ii', $clienteId, $userId);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);
        
        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;

        send_json(["success"=>true,"ventas"=>$arr]);
    }
    // Case 2: Filter by usuarioId only
    else if ($usuarioId) {
        $query = "SELECT cl_ide, cl_nom, cl_tel, cl_dir, cl_deb, vt_pro, vt_can, vt_fec, vt_emp, pr_val, vt_mon
            FROM sap_vt00 vt 
            JOIN sap_cl00 cl ON vt.vt_cli = cl.cl_ide
            JOIN sap_pr00 pr ON vt.vt_pro = pr.pr_nom AND pr.pr_emp = vt.vt_emp
            WHERE vt.vt_emp = ? 
            AND vt_fec >= NOW() - INTERVAL 5 DAY 
            ORDER BY vt_fec DESC";

        $stmt = prepare_or_fail($con, $query);
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if (!$res) send_json(["success"=>false,"error"=>mysqli_error($con)],500);

        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) {
            $venta = [
                'vt_cli' => [
                    'cl_ide' => $r["cl_ide"],
                    'cl_nom' => $r["cl_nom"],
                    'cl_tel' => $r["cl_tel"],
                    'cl_dir' => $r["cl_dir"],
                    'cl_deb' => $r["cl_deb"]
                ],
                'vt_pro' => $r["vt_pro"],
                'pr_val' => $r["pr_val"],
                'vt_can' => $r["vt_can"],
                'vt_fec' => $r["vt_fec"],
                'vt_mon' => $r["vt_mon"],
                'vt_emp' => $r["vt_emp"]
            ];

            $arr[] = $venta;
        }
        send_json(["success"=>true,"ventas"=>$arr]);
    }
    // Case 3: No filters - show recent sales
    else {
        $query = "SELECT cl_ide, cl_nom, cl_tel, cl_dir, cl_deb, vt_pro, vt_can, vt_fec, vt_emp, pr_val, vt_mon
            FROM sap_vt00 vt 
            JOIN sap_cl00 cl ON vt.vt_cli = cl.cl_ide
            JOIN sap_pr00 pr ON vt.vt_pro = pr.pr_nom AND pr.pr_emp = vt.vt_emp
            WHERE vt.vt_emp = " . intval($userId) . "
            AND vt_fec >= NOW() - INTERVAL 5 DAY ORDER BY vt_fec DESC";

        $res = mysqli_query($con, $query);

        if (!$res) send_json(["success"=>false,"error"=>mysqli_error($con)],500);

        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) {
            $venta = [
                'vt_cli' => [
                    'cl_ide' => $r["cl_ide"],
                    'cl_nom' => $r["cl_nom"],
                    'cl_tel' => $r["cl_tel"],
                    'cl_dir' => $r["cl_dir"],
                    'cl_deb' => $r["cl_deb"]
                ],
                'vt_pro' => $r["vt_pro"],
                'pr_val' => $r["pr_val"],
                'vt_can' => $r["vt_can"],
                'vt_fec' => $r["vt_fec"],
                'vt_mon' => $r["vt_mon"],
                'vt_emp' => $r["vt_emp"]
            ];

            $arr[] = $venta;
        }
        send_json(["success"=>true,"ventas"=>$arr]);
    }
}

// POST: crear ventas. JSON: {"clienteId":1,"productos":[{"nombre":"X","cantidad":2}, ...]}
if ($method === 'POST') {
    $data = get_json_input();

    if (!isset($data['clienteId']) || !isset($data['productos']) || !is_array($data['productos']) || count($data['productos'])===0) {
        send_json(["success"=>false,"error"=>"Datos incompletos: clienteId y productos son requeridos"],400);
    }

    $clienteId = intval($data['clienteId']);

    // usuarioId from the request is ignored: the owner is the token user
    $fecha = date('Y-m-d H:i:s');

    // begin transaction (best effort)
    mysqli_autocommit($con, false);

    registrar_venta_lineas($con, $userId, $clienteId, $data['productos'], $fecha);

    // commit
    if (!mysqli_commit($con)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>"No se pudo confirmar la transacción"],500);
    }
    mysqli_autocommit($con, true);

    send_json(["success"=>true,"message"=>"Ventas registradas correctamente"],201);
}
if ($method==="DELETE") {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    // Newer app versions ask for the debt to be reverted here; older ones revert it
    // through deudaCliente.php after this call, so reverting it for them would subtract it twice
    $revertirDeuda = isset($_GET['revertir_deuda']) && $_GET['revertir_deuda'] === '1';

    if ($id <= 0) {
        send_json(["success"=>false,"error"=>"ID inválido"],400);
    }

    mysqli_autocommit($con, false);

    // Lock the line so two concurrent deletes cannot revert its containers twice
    $stmtFind = prepare_or_fail($con, 'SELECT vt_cli, vt_pro, vt_can, vt_dev, vt_mon FROM sap_vt00 WHERE vt_ide = ? AND vt_emp = ? FOR UPDATE');

    mysqli_stmt_bind_param($stmtFind, 'ii', $id, $userId);

    mysqli_stmt_execute($stmtFind);

    $resFind = mysqli_stmt_get_result($stmtFind);

    $venta = $resFind ? mysqli_fetch_assoc($resFind) : null;

    if (!$venta) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>"Venta no encontrada"],404);
    }

    // The sold units go back to stock
    $stmtRestoreStock = prepare_or_fail($con, 'UPDATE sap_pr00 SET pr_stk = COALESCE(pr_stk, 0) + ? WHERE pr_nom = ? AND pr_emp = ?');

    $cantidad = intval($venta['vt_can']);

    mysqli_stmt_bind_param($stmtRestoreStock, 'isi', $cantidad, $venta['vt_pro'], $userId);

    if (!mysqli_stmt_execute($stmtRestoreStock)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtRestoreStock)],500);
    }

    if (!record_stock_movement($con, $userId, $venta['vt_pro'], 'venta_eliminada', $cantidad, $id)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>mysqli_error($con)],500);
    }

    // Lines with vt_dev also revert their container effect
    if ($venta['vt_dev'] !== null) {
        $devueltos = intval($venta['vt_dev']);

        $saldo = $devueltos - intval($venta['vt_can']); // -(cantidad - devueltos)

        $clienteId = intval($venta['vt_cli']);

        $stmtEmpties = prepare_or_fail($con, 'UPDATE sap_pr00 SET pr_vac = pr_vac - ? WHERE pr_nom = ? AND pr_emp = ?');

        mysqli_stmt_bind_param($stmtEmpties, 'isi', $devueltos, $venta['vt_pro'], $userId);

        if (!mysqli_stmt_execute($stmtEmpties)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtEmpties)],500);
        }

        $stmtBalance = prepare_or_fail($con, 'INSERT INTO sap_ce00 (ce_cli, ce_pro, ce_can, ce_emp)
            SELECT ?, pr_ide, ?, pr_emp FROM sap_pr00 WHERE pr_nom = ? AND pr_emp = ?
            ON DUPLICATE KEY UPDATE ce_can = ce_can + VALUES(ce_can)');

        mysqli_stmt_bind_param($stmtBalance, 'iisi', $clienteId, $saldo, $venta['vt_pro'], $userId);

        if (!mysqli_stmt_execute($stmtBalance)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtBalance)],500);
        }
    }

    // The sale amount leaves the debt even below zero: whatever the client already paid
    // for this sale stays as a credit balance that the next sale consumes
    if ($revertirDeuda) {
        $stmtRevertDebt = prepare_or_fail($con, 'UPDATE sap_cl00 SET cl_deb = COALESCE(cl_deb, 0) - ? WHERE cl_ide = ? AND cl_emp = ?');

        $monto = floatval($venta['vt_mon']);

        $clienteVenta = intval($venta['vt_cli']);

        mysqli_stmt_bind_param($stmtRevertDebt, 'dii', $monto, $clienteVenta, $userId);

        if (!mysqli_stmt_execute($stmtRevertDebt)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtRevertDebt)],500);
        }
    }

    $stmtDelete = prepare_or_fail($con, 'DELETE FROM sap_vt00 WHERE vt_ide = ? AND vt_emp = ?');

    mysqli_stmt_bind_param($stmtDelete, 'ii', $id, $userId);

    if (!mysqli_stmt_execute($stmtDelete)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtDelete)],500);
    }

    if (!mysqli_commit($con)) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>"No se pudo confirmar la transacción"],500);
    }
    mysqli_autocommit($con, true);

    send_json(["success"=>true,"message"=>"Venta eliminada"]);
}

// other methods not supported
send_json(["success"=>false,"error"=>"Método no soportado"],405);

?>
