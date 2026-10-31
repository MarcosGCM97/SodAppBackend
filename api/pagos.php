<?php
require_once 'lib.php';
require_once __DIR__ . '/auth.php';

allow_cors();

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    send_json(["success" => false, "error" => 'Metodo no permitido'], 405);
}

// GET /pagos.php?formas=1 -> payment methods the app can choose from
if (isset($_GET['formas'])) {
    $res = mysqli_query($con, 'SELECT fp_ide, fp_nom FROM sap_fp00 WHERE fp_sel = 1 ORDER BY fp_ide');
    if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

    $formas = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $formas[] = ['fp_ide' => intval($r['fp_ide']), 'fp_nom' => $r['fp_nom']];
    }
    send_json(["success" => true, "formas" => $formas]);
}

// GET /pagos.php?clienteId=1 -> payment history of one cliente, newest first
if (isset($_GET['clienteId'])) {
    $clienteId = is_array($_GET['clienteId']) ? 0 : intval($_GET['clienteId']);

    // The cliente must belong to the token user
    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_cl00 WHERE cl_ide = ? AND cl_emp = ? AND is_deleted = 0');
    mysqli_stmt_bind_param($check, 'ii', $clienteId, $userId);
    mysqli_stmt_execute($check);
    $resCheck = mysqli_stmt_get_result($check);
    if (!$resCheck || mysqli_num_rows($resCheck) === 0) {
        send_json(["success" => false, "error" => "Cliente no encontrado"], 404);
    }

    $stmt = prepare_or_fail($con, 'SELECT pg.pg_ide, pg.pg_mon, fp.fp_nom, pg.pg_fec
        FROM sap_pg00 AS pg
        JOIN sap_fp00 AS fp ON fp.fp_ide = pg.pg_fp
        WHERE pg.pg_cli = ? AND pg.pg_emp = ?
        ORDER BY pg.pg_fec DESC, pg.pg_ide DESC');
    mysqli_stmt_bind_param($stmt, 'ii', $clienteId, $userId);
    if (!mysqli_stmt_execute($stmt)) {
        send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
    }

    $res = mysqli_stmt_get_result($stmt);
    $pagos = [];
    while ($r = mysqli_fetch_assoc($res)) $pagos[] = $r;

    send_json(["success" => true, "pagos" => $pagos]);
}

// GET /pagos.php?mes=1&ano=2026 -> amount collected in the month per payment method
if (isset($_GET['mes'])) {
    $mesRaw = is_string($_GET['mes']) ? trim($_GET['mes']) : '';
    if (!ctype_digit($mesRaw) || intval($mesRaw) < 1 || intval($mesRaw) > 12) {
        send_json(["success" => false, "error" => 'Mes incorrecto'], 400);
    }
    $mes = intval($mesRaw);

    // Optional year (4 digits), defaults to the current year
    $ano = intval(date('Y'));
    if (isset($_GET['ano']) && $_GET['ano'] !== '') {
        $anoRaw = is_string($_GET['ano']) ? trim($_GET['ano']) : '';
        if (!preg_match('/^\d{4}$/', $anoRaw)) {
            send_json(["success" => false, "error" => 'Año incorrecto'], 400);
        }
        $ano = intval($anoRaw);
    }

    // Half-open range [first day of month, first day of next month)
    $anoSiguiente = $mes === 12 ? $ano + 1 : $ano;
    $mesSiguiente = $mes === 12 ? 1 : $mes + 1;
    $desde = sprintf('%04d-%02d-01 00:00:00', $ano, $mes);
    $hasta = sprintf('%04d-%02d-01 00:00:00', $anoSiguiente, $mesSiguiente);

    $stmt = prepare_or_fail($con, 'SELECT fp.fp_nom, SUM(pg.pg_mon) AS total
        FROM sap_pg00 AS pg
        JOIN sap_fp00 AS fp ON fp.fp_ide = pg.pg_fp
        WHERE pg.pg_emp = ? AND pg.pg_fec >= ? AND pg.pg_fec < ?
        GROUP BY fp.fp_ide, fp.fp_nom
        ORDER BY fp.fp_ide');
    mysqli_stmt_bind_param($stmt, 'iss', $userId, $desde, $hasta);
    if (!mysqli_stmt_execute($stmt)) {
        send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
    }

    $res = mysqli_stmt_get_result($stmt);
    $totales = [];
    while ($r = mysqli_fetch_assoc($res)) $totales[] = $r;

    send_json(["success" => true, "totales" => $totales]);
}

send_json(["success" => false, "error" => 'Indique formas, clienteId o mes'], 400);
