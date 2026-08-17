<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
require_once __DIR__ . '../../Core/lib.php';
require_once __DIR__ . '../../Core/auth.php';
allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

// GET: optionally ?usuarioId= to filter by user, ?id=&usuarioId= to filter by cliente and user
if ($method === 'GET') {
    $usuarioId = isset($_GET['usuarioId']) ? $_GET['usuarioId'] : null;
    $clienteId = isset($_GET['id']) && intval($_GET['id'])>0 ? intval($_GET['id']) : null;

    // Case 1: Filter by cliente AND usuarioId
    if ($clienteId && $usuarioId) {
        $usuarioIdInt = intval($usuarioId); // Convert to int to match DB type
        $stmt = prepare_or_fail($con, '
			SELECT cl.cl_nom, cl.cl_dir, cl.cl_tel,
					pr.pr_nom, pr.pr_val,
					vt.vt_can, vt.vt_fec, vt.vt_ide
				FROM sap_vt00 AS vt
			JOIN sap_cl00 AS cl 
				ON cl.cl_ide = vt.vt_cli
			JOIN sap_pr00 AS pr 
				ON pr.pr_nom = vt.vt_pro
			WHERE vt.vt_cli = ? 
				AND vt.vt_emp = ? 
			ORDER BY vt.vt_fec DESC
			LIMIT 15'
        );

        mysqli_stmt_bind_param($stmt, 'ii', $clienteId, $usuarioIdInt);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);
        
        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;

        send_json(["success"=>true,"ventas"=>$arr]);
    }
    // Case 2: Filter by usuarioId only
    else if ($usuarioId) {
        $usuarioIdInt = intval($usuarioId); // Convert to int to match DB type
        $query = "SELECT cl_ide, cl_nom, cl_tel, cl_dir, cl_deb, vt_pro, vt_can, vt_fec, vt_emp, pr_val, vt_mon
            FROM sap_vt00 vt 
            JOIN sap_cl00 cl ON vt.vt_cli = cl.cl_ide
            JOIN sap_pr00 pr ON vt.vt_pro = pr.pr_nom
            WHERE vt.vt_emp = ? 
            AND vt_fec >= NOW() - INTERVAL 5 DAY 
            ORDER BY vt_fec DESC";

        $stmt = prepare_or_fail($con, $query);
        mysqli_stmt_bind_param($stmt, 'i', $usuarioIdInt);
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
            JOIN sap_pr00 pr ON vt.vt_pro = pr.pr_nom
            WHERE vt_fec >= NOW() - INTERVAL 5 DAY ORDER BY vt_fec DESC";

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
    // require auth for creating sales
    require_auth();
    $data = get_json_input();

    if (!isset($data['clienteId']) || !isset($data['productos']) || !is_array($data['productos']) || count($data['productos'])===0) {
        send_json(["success"=>false,"error"=>"Datos incompletos: clienteId y productos son requeridos"],400);
    }

    $clienteId = intval($data['clienteId']);

    $usuarioId = isset($data['usuarioId']) ? intval($data['usuarioId']) : 1;

    $fecha = date('Y-m-d H:i:s');

    // Validar que el cliente existe y no está eliminado
    $stmtValidateClient = prepare_or_fail($con, 'SELECT cl_ide FROM sap_cl00 WHERE cl_ide = ? AND is_deleted = 0');

    mysqli_stmt_bind_param($stmtValidateClient, 'i', $clienteId);

    mysqli_stmt_execute($stmtValidateClient);

    $resClient = mysqli_stmt_get_result($stmtValidateClient);

    if (!$resClient || mysqli_num_rows($resClient) === 0) {
        send_json(["success"=>false,"error"=>"Cliente no encontrado o eliminado"],404);
    }

    // Validar que el usuario existe
    $stmtValidateUser = prepare_or_fail($con, 'SELECT 1 FROM sap_us00 WHERE us_ide = ?');

    mysqli_stmt_bind_param($stmtValidateUser, 'i', $usuarioId);

    mysqli_stmt_execute($stmtValidateUser);

    $resUser = mysqli_stmt_get_result($stmtValidateUser);
    
    if (!$resUser || mysqli_num_rows($resUser) === 0) {
        send_json(["success"=>false,"error"=>"Usuario no válido"],404);
    }

    // begin transaction (best effort)
    mysqli_autocommit($con, false);

    $insertQuery = 'INSERT INTO sap_vt00 SET vt_cli = ?, vt_pro = ?, vt_can = ?, vt_emp = ?, vt_fec = ?, vt_mon = ?';

    $stmtInsert = prepare_or_fail($con, $insertQuery);

    foreach ($data['productos'] as $p) {
        $nombre = isset($p['nombre']) ? $p['nombre'] : null;

        $cantidad = isset($p['cantidad']) ? intval($p['cantidad']) : 0;

        $precio = isset($p['precio']) ? floatval($p['precio']) : 0;

        $monto = $precio * $cantidad;

        if (!$nombre || $cantidad<=0 || $precio<=0) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>"Producto inválido: nombre, cantidad y precio deben estar presentes y ser mayores a 0"], 400);
        }

        mysqli_stmt_bind_param($stmtInsert, 'isiisd', $clienteId, $nombre, $cantidad, $usuarioId, $fecha, $monto);

        if (!mysqli_stmt_execute($stmtInsert)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtInsert)],500);
        }

        // obtener precio
        /*$stmtPrice = prepare_or_fail($con, 'SELECT pr_val FROM sap_pr00 WHERE pr_nom = ?');

        mysqli_stmt_bind_param($stmtPrice, 's', $nombre);

        mysqli_stmt_execute($stmtPrice);

        $resPrice = mysqli_stmt_get_result($stmtPrice);

        if (!$resPrice || mysqli_num_rows($resPrice)===0) {

            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>"Producto no encontrado: $nombre"],404);
        }
        $rowP = mysqli_fetch_assoc($resPrice);

        $valor = floatval($rowP['pr_val']);*/

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
        $deudaNueva = $deudaActual + $monto;

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
    // require auth for deleting sales
    require_auth();
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
