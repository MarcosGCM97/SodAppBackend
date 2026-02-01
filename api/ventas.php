<?php
require_once __DIR__ . '/lib.php';
allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

// GET: optionally ?clienteId= to filter
if ($method === 'GET') {
    if (isset($_GET['id']) && intval($_GET['id'])>0) {
        $id = intval($_GET['id']);

        $stmt = prepare_or_fail($con, 'SELECT cl.cl_nom, cl.cl_dir, cl.cl_tel, pr.pr_nom, pr.pr_val, vt.vt_can, vt.vt_fec, vt.vt_ide
        FROM sap_vt00 AS vt
        JOIN sap_cl00 AS cl ON cl.cl_ide = vt.vt_cli
        JOIN sap_pr00 AS pr ON pr.pr_nom = vt.vt_pro
        WHERE vt.vt_cli = ? ORDER BY vt.vt_fec DESC
        LIMIT 15');

        mysqli_stmt_bind_param($stmt, 'i', $id);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);
        
        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;

        send_json(["success"=>true,"ventas"=>$arr]);
    } else {
        $query = "SELECT cl_ide, cl_nom, cl_tel, cl_dir, cl_deb, vt_pro, vt_can, vt_fec, vt_emp FROM sap_vt00 vt JOIN sap_cl00 cl ON vt.vt_cli = cl.cl_ide WHERE vt_fec >= NOW() - INTERVAL 5 DAY ORDER BY vt_fec DESC";

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
                'vt_can' => $r["vt_can"],
                'vt_fec' => $r["vt_fec"],
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

    $fecha = date('Y-m-d');

    // begin transaction (best effort)
    mysqli_autocommit($con, false);

    $insertQuery = 'INSERT INTO sap_vt00 SET vt_cli = ?, vt_pro = ?, vt_can = ?, vt_fec = ?';

    $stmtInsert = prepare_or_fail($con, $insertQuery);

    foreach ($data['productos'] as $p) {
        $nombre = isset($p['nombre']) ? $p['nombre'] : null;

        $cantidad = isset($p['cantidad']) ? intval($p['cantidad']) : 0;

        if (!$nombre || $cantidad<=0) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>"Producto inválido en lista"],400);
        }

        mysqli_stmt_bind_param($stmtInsert, 'isis', $clienteId, $nombre, $cantidad, $fecha);

        if (!mysqli_stmt_execute($stmtInsert)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtInsert)],500);
        }

        // obtener precio
        $stmtPrice = prepare_or_fail($con, 'SELECT pr_val FROM sap_pr00 WHERE pr_nom = ?');

        mysqli_stmt_bind_param($stmtPrice, 's', $nombre);

        mysqli_stmt_execute($stmtPrice);

        $resPrice = mysqli_stmt_get_result($stmtPrice);

        if (!$resPrice || mysqli_num_rows($resPrice)===0) {

            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>"Producto no encontrado: $nombre"],404);
        }
        $rowP = mysqli_fetch_assoc($resPrice);

        $valor = floatval($rowP['pr_val']);

        // actualizar deuda del cliente
        $stmtDebt = prepare_or_fail($con, 'SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?');
        
        mysqli_stmt_bind_param($stmtDebt, 'i', $clienteId);

        mysqli_stmt_execute($stmtDebt);

        $resDebt = mysqli_stmt_get_result($stmtDebt);

        $deudaActual = 0;

        if ($resDebt && mysqli_num_rows($resDebt)>0) {
            $rowD = mysqli_fetch_assoc($resDebt);

            $deudaActual = $rowD['cl_deb'];
        }
        $deudaNueva = $deudaActual + ($cantidad * $valor);

        $stmtUpdateDebt = prepare_or_fail($con, 'UPDATE sap_cl00 SET cl_deb = ? WHERE cl_ide = ?');

        mysqli_stmt_bind_param($stmtUpdateDebt, 'di', $deudaNueva, $clienteId);

        if (!mysqli_stmt_execute($stmtUpdateDebt)) {

            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtUpdateDebt)],500);
        }
    }

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

    if ($id <= 0) {
        send_json(["success"=>false,"error"=>"ID inválido"],400);
    }

    $stmtDelete = prepare_or_fail($con, 'DELETE FROM sap_vt00 WHERE vt_ide = ?');

    mysqli_stmt_bind_param($stmtDelete, 'i', $id);

    if (mysqli_stmt_execute($stmtDelete)) {

        if (mysqli_stmt_affected_rows($stmtDelete) > 0) {
            send_json(["success"=>true,"message"=>"Venta eliminada"]);

        } else {
            send_json(["success"=>false,"error"=>"Venta no encontrada"],404);
        }
    }
}

// other methods not supported
send_json(["success"=>false,"error"=>"Método no soportado"],405);

?>
