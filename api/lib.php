<?php
// Helper functions for the new REST API
// Compatible with older PHP versions (5.6+)

// load DB connection from project
require_once __DIR__ . '/conexion.php';

// Endpoints check mysqli return values / errno (e.g. 1062 -> 409); PHP 8.1+
// throws exceptions by default instead, so keep the classic reporting mode.
mysqli_report(MYSQLI_REPORT_OFF);

$db = new Database();

$con = $db->connect();

// sap_fp00 row stored for payments sent without a method (old app versions)
const FORMA_PAGO_SIN_ESPECIFICAR = 1;

function allow_cors() {

    header('Access-Control-Allow-Origin: *');

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

        http_response_code(200);

        exit;
    }
}

function send_json($data, $status = 200) {

    header('Content-Type: application/json; charset=utf-8');

    http_response_code($status);

    echo json_encode($data);

    exit;
}

function get_json_input() {

    $raw = file_get_contents('php://input');

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {

        send_json(["success" => false, "error" => 'Error al decodificar JSON: ' . json_last_error_msg()], 400);

    }

    return $data;
}

// Small helper to prepare statements and return error on failure
function prepare_or_fail($con, $query) {

    $stmt = mysqli_prepare($con, $query);

    if ($stmt === false) {

        send_json(["success" => false, "error" => mysqli_error($con)], 500);

    }
    return $stmt;
}

// Integer >= 0 from JSON or a query param (int or digit string); null when invalid (negative, decimal, text)
function parse_non_negative_int($value) {
    if (is_int($value)) return $value >= 0 ? $value : null;

    if (is_string($value) && ctype_digit($value)) return intval($value);

    return null;
}

// Latitude/longitude pair in WGS84 degrees (numbers or numeric strings). Both null means no
// coordinates. Returns [lat, lng] (floats or both null), or false when only one is given,
// one is not a number or out of range (lat -90..90, lng -180..180).
function parse_coordenadas($lat, $lng) {
    if ($lat === null && $lng === null) return [null, null];

    $esNumero = function ($v) { return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)); };

    if (!$esNumero($lat) || !$esNumero($lng)) return false;

    $lat = (float) $lat;
    $lng = (float) $lng;

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;

    return [$lat, $lng];
}

// Optional trimmed text of at most $max characters: null when absent or blank, false when too long
function parse_texto_opcional($value, $max) {
    if ($value === null) return null;

    if (!is_string($value)) return false;

    $value = trim($value);

    if ($value === '') return null;

    $largo = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

    return $largo > $max ? false : $value;
}

// Records a stock movement of the owner's product in sap_ms00 (historial de stock).
// Call it right after changing pr_stk, inside the same transaction: ms_stk takes the
// stock that the change left. $tipo: venta, venta_eliminada, llenado, ajuste, alta.
// Returns false when the insert fails, so the caller can roll back.
function record_stock_movement($con, $companyId, $nombre, $tipo, $cantidad, $ventaId = null) {
    $stmt = prepare_or_fail($con, 'INSERT INTO sap_ms00 (ms_pro, ms_emp, ms_tip, ms_can, ms_stk, ms_vta, ms_fec)
        SELECT pr_ide, pr_emp, ?, ?, COALESCE(pr_stk, 0), ?, NOW() FROM sap_pr00 WHERE pr_nom = ? AND pr_emp = ?');

    mysqli_stmt_bind_param($stmt, 'siisi', $tipo, $cantidad, $ventaId, $nombre, $companyId);

    return mysqli_stmt_execute($stmt);
}

// Registers the sale lines of a cliente: one sap_vt00 row per line, stock decrement with its
// movement, returned containers (pr_vac + sap_ce00) and the client debt. $lineas is the POST
// ventas payload shape: [['nombre' => , 'cantidad' => , 'precio' => , 'devueltos' => ], ...].
// The caller owns the transaction: call it after mysqli_autocommit($con, false) and commit after.
// On invalid input it rolls back and sends 404 (cliente/producto) or 400 (line) like POST ventas.
// Returns the created sale ids, in line order.
function registrar_venta_lineas($con, $companyId, $clienteId, $lineas, $fecha) {
    // Validar que el cliente existe, no está eliminado y pertenece al usuario
    $stmtValidateClient = prepare_or_fail($con, 'SELECT cl_ide FROM sap_cl00 WHERE cl_ide = ? AND cl_emp = ? AND is_deleted = 0');

    mysqli_stmt_bind_param($stmtValidateClient, 'ii', $clienteId, $companyId);

    mysqli_stmt_execute($stmtValidateClient);

    $resClient = mysqli_stmt_get_result($stmtValidateClient);

    if (!$resClient || mysqli_num_rows($resClient) === 0) {
        mysqli_rollback($con);

        send_json(["success"=>false,"error"=>"Cliente no encontrado o eliminado"],404);
    }

    // Validar que cada producto pertenece al usuario; keep its id and whether it is returnable.
    // Every 404 is checked before any line is written or rejected with 400.
    $stmtValidateProduct = prepare_or_fail($con, 'SELECT pr_ide, pr_ret FROM sap_pr00 WHERE pr_nom = ? AND pr_emp = ?');

    $productos = [];

    foreach ($lineas as $p) {
        $nombre = isset($p['nombre']) ? $p['nombre'] : null;

        if (!$nombre) continue; // reported as an invalid product below

        mysqli_stmt_bind_param($stmtValidateProduct, 'si', $nombre, $companyId);

        mysqli_stmt_execute($stmtValidateProduct);

        $resProduct = mysqli_stmt_get_result($stmtValidateProduct);

        $rowProduct = $resProduct ? mysqli_fetch_assoc($resProduct) : null;

        if (!$rowProduct) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>"Producto no encontrado: $nombre"],404);
        }
        $productos[$nombre] = $rowProduct;
    }

    // vt_dev: containers returned in the line; NULL for non-returnable products (no container effect)
    $stmtInsert = prepare_or_fail($con, 'INSERT INTO sap_vt00 SET vt_cli = ?, vt_pro = ?, vt_can = ?, vt_emp = ?, vt_fec = ?, vt_mon = ?, vt_dev = ?');

    // Atomic decrement that never blocks a sale: the delivery already happened physically,
    // so stock may go negative and signals that inventory needs adjusting. NULL counts as 0.
    $stmtDecrementStock = prepare_or_fail($con, 'UPDATE sap_pr00 SET pr_stk = COALESCE(pr_stk, 0) - ? WHERE pr_nom = ? AND pr_emp = ? AND deleted_at IS NULL');

    // Returnable products: returned containers go to the empties and the client keeps
    // cantidad - devueltos containers. Both are atomic increments; the balance may go negative.
    $stmtAddEmpties = prepare_or_fail($con, 'UPDATE sap_pr00 SET pr_vac = pr_vac + ? WHERE pr_nom = ? AND pr_emp = ? AND deleted_at IS NULL');

    $stmtAddBalance = prepare_or_fail($con, 'INSERT INTO sap_ce00 (ce_cli, ce_pro, ce_can, ce_emp) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE ce_can = ce_can + VALUES(ce_can)');

    // Atomic increment of the client debt; NULL counts as 0
    $stmtAddDebt = prepare_or_fail($con, 'UPDATE sap_cl00 SET cl_deb = COALESCE(cl_deb, 0) + ? WHERE cl_ide = ? AND cl_emp = ?');

    $ventaIds = [];

    foreach ($lineas as $p) {
        $nombre = isset($p['nombre']) ? $p['nombre'] : null;

        $cantidad = isset($p['cantidad']) ? intval($p['cantidad']) : 0;

        $precio = isset($p['precio']) ? floatval($p['precio']) : 0;

        $monto = $precio * $cantidad;

        if (!$nombre || $cantidad<=0 || $precio<=0) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>"Producto inválido: nombre, cantidad y precio deben estar presentes y ser mayores a 0"], 400);
        }

        // devueltos is optional (old app versions do not send it): absent counts as 0
        $devueltos = isset($p['devueltos']) ? parse_non_negative_int($p['devueltos']) : 0;

        if ($devueltos === null) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>"Envases devueltos inválidos: deben ser un número entero mayor o igual a 0"], 400);
        }

        $retornable = intval($productos[$nombre]['pr_ret']) === 1;

        if (!$retornable && $devueltos > 0) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>"El producto $nombre no es retornable"], 400);
        }

        $vtDev = $retornable ? $devueltos : null;

        mysqli_stmt_bind_param($stmtInsert, 'isiisdi', $clienteId, $nombre, $cantidad, $companyId, $fecha, $monto, $vtDev);

        if (!mysqli_stmt_execute($stmtInsert)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtInsert)],500);
        }

        $ventaId = mysqli_insert_id($con);

        $ventaIds[] = $ventaId;

        // descontar stock del producto
        mysqli_stmt_bind_param($stmtDecrementStock, 'isi', $cantidad, $nombre, $companyId);

        if (!mysqli_stmt_execute($stmtDecrementStock)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtDecrementStock)],500);
        }

        if (!record_stock_movement($con, $companyId, $nombre, 'venta', -$cantidad, $ventaId)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_error($con)],500);
        }

        if ($retornable) {
            mysqli_stmt_bind_param($stmtAddEmpties, 'isi', $devueltos, $nombre, $companyId);

            if (!mysqli_stmt_execute($stmtAddEmpties)) {
                mysqli_rollback($con);

                send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtAddEmpties)],500);
            }

            $productoId = intval($productos[$nombre]['pr_ide']);

            $saldo = $cantidad - $devueltos;

            mysqli_stmt_bind_param($stmtAddBalance, 'iiii', $clienteId, $productoId, $saldo, $companyId);

            if (!mysqli_stmt_execute($stmtAddBalance)) {
                mysqli_rollback($con);

                send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtAddBalance)],500);
            }
        }

        // actualizar deuda del cliente
        mysqli_stmt_bind_param($stmtAddDebt, 'dii', $monto, $clienteId, $companyId);

        if (!mysqli_stmt_execute($stmtAddDebt)) {
            mysqli_rollback($con);

            send_json(["success"=>false,"error"=>mysqli_stmt_error($stmtAddDebt)],500);
        }
    }

    return $ventaIds;
}

// Boolean flag as 0/1 from JSON (true/false, 0/1) or a query param ("0"/"1"); null when invalid
function parse_flag($value) {
    if (is_bool($value)) return $value ? 1 : 0;

    if ($value === 0 || $value === 1 || $value === '0' || $value === '1') return intval($value);

    return null;
}

?>
