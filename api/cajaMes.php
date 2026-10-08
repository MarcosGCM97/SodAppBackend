<?php
require_once 'lib.php';

allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== "GET") {
    send_json([
        "success" => false,
        "error" => 'Metodo no permitido'
    ], 405);
}

// Validate month (1..12) before formatting it
$mesRaw = (isset($_GET['mes']) && is_string($_GET['mes'])) ? trim($_GET['mes']) : '';
if (!ctype_digit($mesRaw) || intval($mesRaw) < 1 || intval($mesRaw) > 12) {
    send_json([
        "success" => false,
        "error" => 'Mes incorrecto'
    ], 400);
}
$mes = intval($mesRaw);

// Optional year (4 digits), defaults to the current year
$ano = intval(date('Y'));
if (isset($_GET['ano']) && $_GET['ano'] !== '') {
    $anoRaw = is_string($_GET['ano']) ? trim($_GET['ano']) : '';
    if (!preg_match('/^\d{4}$/', $anoRaw)) {
        send_json([
            "success" => false,
            "error" => 'Año incorrecto'
        ], 400);
    }
    $ano = intval($anoRaw);
}

// Half-open range [first day of month, first day of next month)
$anoSiguiente = $mes === 12 ? $ano + 1 : $ano;
$mesSiguiente = $mes === 12 ? 1 : $mes + 1;
$desde = sprintf('%04d-%02d-01 00:00:00', $ano, $mes);
$hasta = sprintf('%04d-%02d-01 00:00:00', $anoSiguiente, $mesSiguiente);

// vt_mon is the line total stored at sale time; fall back to the current
// product price only for legacy rows without a stored amount.
// LEFT JOINs keep sales whose client or product was renamed or deleted.
$query = 'SELECT cl.cl_nom, cl.cl_dir, cl.cl_tel,
        COALESCE(pr.pr_nom, vt.vt_pro) AS pr_nom,
        pr.pr_val,
        vt.vt_pro,
        vt.vt_can,
        CASE WHEN vt.vt_mon IS NOT NULL AND vt.vt_mon > 0
            THEN vt.vt_mon
            ELSE COALESCE(pr.pr_val, 0) * vt.vt_can
        END AS vt_mon,
        vt.vt_fec,
        vt.vt_ide
    FROM sap_vt00 AS vt
    LEFT JOIN sap_cl00 AS cl ON cl.cl_ide = vt.vt_cli
    LEFT JOIN sap_pr00 AS pr ON pr.pr_nom = vt.vt_pro
    WHERE vt.vt_fec >= ? AND vt.vt_fec < ?
    ORDER BY vt.vt_fec';

$stmt = prepare_or_fail($con, $query);

mysqli_stmt_bind_param($stmt, 'ss', $desde, $hasta);

if (!mysqli_stmt_execute($stmt)) {
    send_json([
        "success" => false,
        "error" => mysqli_stmt_error($stmt)
    ], 500);
}

$result = mysqli_stmt_get_result($stmt);
$caja = [];
while ($row = mysqli_fetch_assoc($result)) {
    $caja[] = $row;
}

send_json([
    "success" => true,
    "caja" => $caja
]);
