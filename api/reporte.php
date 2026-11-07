<?php
require_once 'lib.php';
require_once __DIR__ . '/auth.php';

allow_cors();

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(["success" => false, "error" => 'Metodo no permitido'], 405);
}

// GET /reporte.php?desde=YYYY-MM-DD&hasta=YYYY-MM-DD[&compDesde=..&compHasta=..]
// Sales and payments report of an inclusive date range. Every figure is
// aggregated here from the stored amounts so the app never recomputes money.
// The comparison period defaults to the same number of days right before.

const REPORTE_MAX_DIAS = 366;

function parse_fecha_reporte($key) {
    $raw = isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return new DateTimeImmutable($raw);
}

function rango_invalido($desde, $hasta) {
    return !$desde || !$hasta || $desde > $hasta || $desde->diff($hasta)->days + 1 > REPORTE_MAX_DIAS;
}

$desde = parse_fecha_reporte('desde');
$hasta = parse_fecha_reporte('hasta');
if (rango_invalido($desde, $hasta)) {
    send_json(["success" => false, "error" => 'Rango de fechas inválido (desde y hasta YYYY-MM-DD, hasta 366 días)'], 400);
}

$tieneComp = isset($_GET['compDesde']) || isset($_GET['compHasta']);
if ($tieneComp) {
    $compDesde = parse_fecha_reporte('compDesde');
    $compHasta = parse_fecha_reporte('compHasta');
    if (rango_invalido($compDesde, $compHasta)) {
        send_json(["success" => false, "error" => 'Período de comparación inválido'], 400);
    }
} else {
    $dias = $desde->diff($hasta)->days + 1;
    $compHasta = $desde->modify('-1 day');
    $compDesde = $compHasta->modify('-' . ($dias - 1) . ' days');
}

// Half-open DATETIME bounds [desde 00:00, hasta + 1 day 00:00)
function limites($desde, $hasta) {
    return [$desde->format('Y-m-d 00:00:00'), $hasta->modify('+1 day')->format('Y-m-d 00:00:00')];
}

// Stored line amount; legacy rows without one are valued at the current price and flagged.
// The price is a scalar subquery, not a JOIN, so a line can never be counted twice.
const MONTO_LINEA = 'CASE WHEN vt.vt_mon IS NOT NULL AND vt.vt_mon > 0 THEN vt.vt_mon
    ELSE COALESCE((SELECT MAX(pr.pr_val) FROM sap_pr00 AS pr WHERE pr.pr_nom = vt.vt_pro AND pr.pr_emp = vt.vt_emp), 0) * vt.vt_can END';
const ESTIMADA = '(vt.vt_mon IS NULL OR vt.vt_mon <= 0)';
const FROM_VENTAS = 'FROM sap_vt00 AS vt
    WHERE vt.vt_emp = ? AND vt.vt_fec >= ? AND vt.vt_fec < ?';
// One sale = one registration: every line of a sale shares cliente and timestamp
const CLAVE_VENTA = 'COALESCE(vt.vt_cli, 0), vt.vt_fec';

function filas($con, $sql, $userId, $rango) {
    $stmt = prepare_or_fail($con, $sql);
    mysqli_stmt_bind_param($stmt, 'iss', $userId, $rango[0], $rango[1]);
    if (!mysqli_stmt_execute($stmt)) {
        send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
    }
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
    return $out;
}

function dinero($v) {
    return round((float) $v, 2);
}

function totales_ventas($con, $userId, $rango) {
    $r = filas($con, 'SELECT COALESCE(SUM(' . MONTO_LINEA . '), 0) AS vendido,
            COUNT(DISTINCT ' . CLAVE_VENTA . ') AS ventas,
            COUNT(DISTINCT vt.vt_cli) AS clientes,
            COALESCE(SUM(' . ESTIMADA . '), 0) AS estimadas ' . FROM_VENTAS, $userId, $rango)[0];
    return [dinero($r['vendido']), (int) $r['ventas'], (int) $r['clientes'], (int) $r['estimadas']];
}

function total_cobrado($con, $userId, $rango) {
    $r = filas($con, 'SELECT COALESCE(SUM(pg_mon), 0) AS cobrado FROM sap_pg00
        WHERE pg_emp = ? AND pg_fec >= ? AND pg_fec < ?', $userId, $rango)[0];
    return dinero($r['cobrado']);
}

$rango = limites($desde, $hasta);
list($vendido, $ventas, $clientes, $estimadas) = totales_ventas($con, $userId, $rango);
$cobrado = total_cobrado($con, $userId, $rango);

$cobros = array_map(function ($r) {
    return ['forma' => $r['fp_nom'], 'total' => dinero($r['total'])];
}, filas($con, 'SELECT fp.fp_nom, SUM(pg.pg_mon) AS total
    FROM sap_pg00 AS pg
    JOIN sap_fp00 AS fp ON fp.fp_ide = pg.pg_fp
    WHERE pg.pg_emp = ? AND pg.pg_fec >= ? AND pg.pg_fec < ?
    GROUP BY fp.fp_ide, fp.fp_nom
    ORDER BY fp.fp_ide', $userId, $rango));

$productos = array_map(function ($r) {
    return ['producto' => $r['producto'], 'cantidad' => (int) $r['cantidad'], 'monto' => dinero($r['monto'])];
}, filas($con, 'SELECT vt.vt_pro AS producto, SUM(vt.vt_can) AS cantidad, SUM(' . MONTO_LINEA . ') AS monto '
    . FROM_VENTAS . ' GROUP BY vt.vt_pro ORDER BY monto DESC, producto', $userId, $rango));

$porCliente = array_map(function ($r) {
    return [
        'clienteId' => $r['clienteId'] === null ? null : (int) $r['clienteId'],
        'cliente' => $r['cliente'],
        'ventas' => (int) $r['ventas'],
        'monto' => dinero($r['monto']),
    ];
}, filas($con, 'SELECT vt.vt_cli AS clienteId, MAX(cl.cl_nom) AS cliente,
        COUNT(DISTINCT vt.vt_fec) AS ventas, SUM(' . MONTO_LINEA . ') AS monto
    FROM sap_vt00 AS vt
    LEFT JOIN sap_cl00 AS cl ON cl.cl_ide = vt.vt_cli AND cl.cl_emp = vt.vt_emp
    WHERE vt.vt_emp = ? AND vt.vt_fec >= ? AND vt.vt_fec < ?
    GROUP BY vt.vt_cli ORDER BY monto DESC, cliente', $userId, $rango));

$porDia = array_map(function ($r) {
    return ['fecha' => $r['fecha'], 'monto' => dinero($r['monto'])];
}, filas($con, 'SELECT DATE_FORMAT(vt.vt_fec, \'%Y-%m-%d\') AS fecha, SUM(' . MONTO_LINEA . ') AS monto '
    . FROM_VENTAS . ' GROUP BY fecha ORDER BY fecha', $userId, $rango));

$detalle = array_map(function ($r) {
    return [
        'fecha' => $r['fecha'],
        'clienteId' => $r['clienteId'] === null ? null : (int) $r['clienteId'],
        'cliente' => $r['cliente'],
        'producto' => $r['producto'],
        'cantidad' => (int) $r['cantidad'],
        'monto' => dinero($r['monto']),
        'estimado' => (int) $r['estimado'] === 1,
    ];
}, filas($con, 'SELECT vt.vt_fec AS fecha, vt.vt_cli AS clienteId, cl.cl_nom AS cliente, vt.vt_pro AS producto,
        vt.vt_can AS cantidad, ' . MONTO_LINEA . ' AS monto, ' . ESTIMADA . ' AS estimado
    FROM sap_vt00 AS vt
    LEFT JOIN sap_cl00 AS cl ON cl.cl_ide = vt.vt_cli AND cl.cl_emp = vt.vt_emp
    WHERE vt.vt_emp = ? AND vt.vt_fec >= ? AND vt.vt_fec < ?
    ORDER BY vt.vt_fec, vt.vt_ide', $userId, $rango));

$rangoComp = limites($compDesde, $compHasta);
list($compVendido, $compVentas) = totales_ventas($con, $userId, $rangoComp);

// Payments were not recorded before migration 007: periods before this date have no cobros
$stmt = prepare_or_fail($con, 'SELECT MIN(pg_fec) AS primero FROM sap_pg00 WHERE pg_emp = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
if (!mysqli_stmt_execute($stmt)) {
    send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
}
$primerCobro = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['primero'];

send_json([
    "success" => true,
    "desde" => $desde->format('Y-m-d'),
    "hasta" => $hasta->format('Y-m-d'),
    "resumen" => [
        "vendido" => $vendido,
        "cobrado" => $cobrado,
        "diferencia" => dinero($vendido - $cobrado),
        "ventas" => $ventas,
        "clientes" => $clientes,
        "lineasEstimadas" => $estimadas,
    ],
    "anterior" => [
        "desde" => $compDesde->format('Y-m-d'),
        "hasta" => $compHasta->format('Y-m-d'),
        "vendido" => $compVendido,
        "cobrado" => total_cobrado($con, $userId, $rangoComp),
        "ventas" => $compVentas,
    ],
    "cobros" => $cobros,
    "productos" => $productos,
    "clientes" => $porCliente,
    "dias" => $porDia,
    "detalle" => $detalle,
    "primerCobro" => $primerCobro,
]);
