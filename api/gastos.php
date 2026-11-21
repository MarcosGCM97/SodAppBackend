<?php
// Business expenses (gastos) of the company (migration 014, table sap_gs00).
// GET ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD[&categoria=]: gastos of the period (default: the
//   current month), newest first, with their total. Needs gastos.ver.
// POST {categoria, monto, fecha?, descripcion?, repartoId?, productoId?, cantidad?, estado?}:
//   creates one (fecha defaults to today). Needs gastos.cargar, plus stock.editar for the
//   categories that move stock.
// PUT {id, ...the same fields}: replaces one (fecha defaults to its previous date). The previous
//   stock effect is reverted before the new one is applied. Needs gastos.editar, plus
//   stock.editar when the previous or the new gasto moves stock.
// DELETE ?id=: deletes one and reverts its stock effect. Needs gastos.editar (+ stock.editar).
//
// Stock effects, in the same transaction as the gasto row:
// - compra_producto of a returnable product: estado 'lleno' -> pr_stk +cantidad, 'vacio' ->
//   pr_vac +cantidad. Of a non-returnable product (no estado) -> pr_stk +cantidad.
// - recarga_envases (returnable products only): pr_vac -cantidad, pr_stk +cantidad (the same
//   refill as envases.php, llenar_envases()).
// Every pr_stk change records a sap_ms00 movement (compra, llenado, compra_eliminada,
// llenado_eliminado). When the stock cannot absorb a change (not enough empties to refill, or
// less stock than a revert takes back) the answer is 409 and nothing is stored.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Every method requires a valid token; the company (owner id) always comes from it
$companyId = current_company_id();

$method = $_SERVER['REQUEST_METHOD'];

const GASTO_CATEGORIAS = ['compra_producto', 'recarga_envases', 'vehiculo', 'combustible', 'otro'];
const GASTO_CATEGORIAS_STOCK = ['compra_producto', 'recarga_envases'];
const GASTO_MONTO_MAX = 9999999999.99;
const GASTO_DESCRIPCION_MAX = 255;

function mueve_stock($categoria) {
    return in_array($categoria, GASTO_CATEGORIAS_STOCK, true);
}

// Rolls back the open transaction and answers the error
function fallar($con, $error, $status) {
    mysqli_rollback($con);

    send_json(["success"=>false,"error"=>$error], $status);
}

// YYYY-MM-DD of a real calendar day, or null
function parse_fecha_gasto($raw) {
    if (!is_string($raw)) return null;

    $raw = trim($raw);

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;

    return $raw;
}

// Amount > 0 with at most 2 decimals (number or numeric string), or null
function parse_monto($value) {
    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) return null;

    $monto = (float) $value;

    if ($monto <= 0 || $monto > GASTO_MONTO_MAX || abs(round($monto, 2) - $monto) > 1e-9) return null;

    return round($monto, 2);
}

// The owned gasto row with its product name, or null. $bloquear locks it for the transaction.
function buscar_gasto($con, $companyId, $id, $bloquear) {
    $stmt = prepare_or_fail($con, 'SELECT g.gs_ide, g.gs_cat, g.gs_fec, g.gs_pro, g.gs_can, g.gs_est, pr.pr_nom
        FROM sap_gs00 g LEFT JOIN sap_pr00 pr ON pr.pr_ide = g.gs_pro AND pr.pr_emp = g.gs_emp
        WHERE g.gs_ide = ? AND g.gs_emp = ?' . ($bloquear ? ' FOR UPDATE' : ''));

    mysqli_stmt_bind_param($stmt, 'ii', $id, $companyId);

    $res = execute_or_fail($con, $stmt);

    $row = mysqli_fetch_assoc($res);

    if (!$row) return null;

    return ['cat' => $row['gs_cat'], 'fec' => $row['gs_fec'], 'pro' => $row['gs_pro'] === null ? null : intval($row['gs_pro']),
        'can' => $row['gs_can'] === null ? null : intval($row['gs_can']), 'est' => $row['gs_est'], 'pr_nom' => $row['pr_nom']];
}

// Validates the gasto fields of a POST/PUT body. Sends 400 (invalid field), 403 (stock.editar
// for a stock category) or 404 (reparto/producto of another company or missing).
function leer_gasto($con, $companyId, $data, $fechaPorDefecto) {
    if (!is_array($data)) send_json(["success"=>false,"error"=>"Datos inválidos"],400);

    $categoria = isset($data['categoria']) ? $data['categoria'] : null;

    if (!in_array($categoria, GASTO_CATEGORIAS, true)) {
        send_json(["success"=>false,"error"=>"Categoría inválida: debe ser una de " . implode(', ', GASTO_CATEGORIAS)],400);
    }

    if (mueve_stock($categoria)) require_permission('stock.editar');

    $monto = isset($data['monto']) ? parse_monto($data['monto']) : null;

    if ($monto === null) send_json(["success"=>false,"error"=>"Monto inválido: debe ser mayor a 0 y con hasta 2 decimales"],400);

    $fecha = $fechaPorDefecto;

    if (isset($data['fecha'])) {
        $fecha = parse_fecha_gasto($data['fecha']);

        if ($fecha === null) send_json(["success"=>false,"error"=>"Fecha inválida: formato YYYY-MM-DD"],400);
    }

    $descripcion = parse_texto_opcional(isset($data['descripcion']) ? $data['descripcion'] : null, GASTO_DESCRIPCION_MAX);

    if ($descripcion === false) send_json(["success"=>false,"error"=>"Descripción inválida: hasta " . GASTO_DESCRIPCION_MAX . " caracteres"],400);

    $repartoId = null;

    if (isset($data['repartoId'])) {
        $repartoId = parse_non_negative_int($data['repartoId']);

        if (!$repartoId) send_json(["success"=>false,"error"=>"repartoId inválido"],400);

        $stmt = prepare_or_fail($con, 'SELECT rp_ide FROM sap_rp00 WHERE rp_ide = ? AND rp_emp = ? AND rp_fec IS NOT NULL');

        mysqli_stmt_bind_param($stmt, 'ii', $repartoId, $companyId);

        if (!mysqli_fetch_assoc(execute_or_fail($con, $stmt))) send_json(["success"=>false,"error"=>"Reparto no encontrado"],404);
    }

    $gasto = ['cat' => $categoria, 'mon' => $monto, 'fec' => $fecha, 'des' => $descripcion, 'rep' => $repartoId,
        'pro' => null, 'can' => null, 'est' => null, 'pr_nom' => null];

    $tieneProducto = isset($data['productoId']) || isset($data['cantidad']) || isset($data['estado']);

    if (!mueve_stock($categoria)) {
        if ($tieneProducto) send_json(["success"=>false,"error"=>"La categoría $categoria no lleva producto, cantidad ni estado"],400);

        return $gasto;
    }

    $productoId = isset($data['productoId']) ? parse_non_negative_int($data['productoId']) : null;

    $cantidad = isset($data['cantidad']) ? parse_non_negative_int($data['cantidad']) : null;

    if (!$productoId || !$cantidad) {
        send_json(["success"=>false,"error"=>"Datos inválidos: productoId y cantidad (entero mayor a 0) son requeridos"],400);
    }

    $stmt = prepare_or_fail($con, 'SELECT pr_ide, pr_nom, pr_ret FROM sap_pr00 WHERE pr_ide = ? AND pr_emp = ? AND deleted_at IS NULL');

    mysqli_stmt_bind_param($stmt, 'ii', $productoId, $companyId);

    $producto = mysqli_fetch_assoc(execute_or_fail($con, $stmt));

    if (!$producto) send_json(["success"=>false,"error"=>"Producto no encontrado"],404);

    $retornable = intval($producto['pr_ret']) === 1;

    $nombre = $producto['pr_nom'];

    $estado = isset($data['estado']) ? $data['estado'] : null;

    if ($categoria === 'recarga_envases') {
        if (!$retornable) send_json(["success"=>false,"error"=>"El producto $nombre no es retornable"],400);

        if ($estado !== null) send_json(["success"=>false,"error"=>"La recarga de envases no lleva estado"],400);
    } elseif ($retornable) {
        if ($estado !== 'lleno' && $estado !== 'vacio') {
            send_json(["success"=>false,"error"=>"Estado inválido: la compra de $nombre (retornable) debe ser lleno o vacio"],400);
        }
    } elseif ($estado !== null && $estado !== 'lleno') {
        send_json(["success"=>false,"error"=>"El producto $nombre no es retornable: no se compra vacío"],400);
    } else {
        $estado = null;
    }

    $gasto['pro'] = $productoId;
    $gasto['can'] = $cantidad;
    $gasto['est'] = $estado;
    $gasto['pr_nom'] = $nombre;

    return $gasto;
}

// Applies ($signo 1) or reverts ($signo -1) the stock effect of a gasto inside the open
// transaction. On failure it rolls back and sends 409 or 500.
function efecto_stock($con, $companyId, $gasto, $signo) {
    if (!mueve_stock($gasto['cat'])) return;

    $cantidad = $gasto['can'];

    $nombre = $gasto['pr_nom'];

    if ($gasto['cat'] === 'recarga_envases' && $signo > 0) {
        $llenado = llenar_envases($con, $companyId, $gasto['pro'], $nombre, $cantidad);

        if ($llenado === 'sin_vacios') fallar($con, "No hay suficientes envases vacíos", 409);

        if ($llenado !== 'ok') fallar($con, mysqli_error($con), 500);

        return;
    }

    if ($gasto['cat'] === 'recarga_envases') {
        // undo the refill: the containers go back to empty
        $deltaStock = -$cantidad;
        $deltaVacios = $cantidad;
        $tipo = 'llenado_eliminado';
    } elseif ($gasto['est'] === 'vacio') {
        $deltaStock = 0;
        $deltaVacios = $signo * $cantidad;
        $tipo = null;
    } else {
        $deltaStock = $signo * $cantidad;
        $deltaVacios = 0;
        $tipo = $signo > 0 ? 'compra' : 'compra_eliminada';
    }

    // Single atomic statement: a decrement only applies while the stock covers it
    $stmt = prepare_or_fail($con, 'UPDATE sap_pr00
        SET pr_stk = CASE WHEN ? = 0 THEN pr_stk ELSE COALESCE(pr_stk, 0) + ? END, pr_vac = pr_vac + ?
        WHERE pr_ide = ? AND pr_emp = ? AND deleted_at IS NULL
          AND (? >= 0 OR COALESCE(pr_stk, 0) + ? >= 0) AND (? >= 0 OR pr_vac + ? >= 0)');

    $producto = $gasto['pro'];

    mysqli_stmt_bind_param($stmt, 'iiiiiiiii', $deltaStock, $deltaStock, $deltaVacios, $producto, $companyId,
        $deltaStock, $deltaStock, $deltaVacios, $deltaVacios);

    if (!mysqli_stmt_execute($stmt)) fallar($con, mysqli_stmt_error($stmt), 500);

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        fallar($con, $signo > 0
            ? "El producto $nombre ya no está disponible"
            : "No se puede deshacer el gasto: $nombre no tiene stock suficiente o fue eliminado", 409);
    }

    if ($tipo !== null && !record_stock_movement($con, $companyId, $nombre, $tipo, $deltaStock)) fallar($con, mysqli_error($con), 500);
}

function gasto_json($r) {
    $entero = function ($v) { return $v === null ? null : intval($v); };

    return ['gs_ide' => intval($r['gs_ide']), 'gs_cat' => $r['gs_cat'], 'gs_mon' => round((float) $r['gs_mon'], 2),
        'gs_fec' => $r['gs_fec'], 'gs_des' => $r['gs_des'], 'gs_rep' => $entero($r['gs_rep']), 'gs_pro' => $entero($r['gs_pro']),
        'pr_nom' => $r['pr_nom'], 'gs_can' => $entero($r['gs_can']), 'gs_est' => $r['gs_est'], 'gs_us' => intval($r['gs_us']),
        'us_nom' => $r['us_nom']];
}

const GASTO_SELECT = 'SELECT g.*, pr.pr_nom, us.us_nom FROM sap_gs00 g
    LEFT JOIN sap_pr00 pr ON pr.pr_ide = g.gs_pro AND pr.pr_emp = g.gs_emp
    LEFT JOIN sap_us00 us ON us.us_ide = g.gs_us';

function gasto_por_id($con, $companyId, $id) {
    $stmt = prepare_or_fail($con, GASTO_SELECT . ' WHERE g.gs_ide = ? AND g.gs_emp = ?');

    mysqli_stmt_bind_param($stmt, 'ii', $id, $companyId);

    return gasto_json(mysqli_fetch_assoc(execute_or_fail($con, $stmt)));
}

function id_gasto($value) {
    $id = $value === null ? null : parse_non_negative_int($value);

    if (!$id) send_json(["success"=>false,"error"=>"id de gasto requerido"],400);

    return $id;
}

if ($method === 'GET') {
    require_permission('gastos.ver');

    $desde = isset($_GET['desde']) ? parse_fecha_gasto($_GET['desde']) : date('Y-m-01');

    $hasta = isset($_GET['hasta']) ? parse_fecha_gasto($_GET['hasta']) : date('Y-m-t');

    if ($desde === null || $hasta === null || $desde > $hasta) {
        send_json(["success"=>false,"error"=>"Rango de fechas inválido (desde y hasta YYYY-MM-DD)"],400);
    }

    $categoria = isset($_GET['categoria']) ? $_GET['categoria'] : null;

    if ($categoria !== null && !in_array($categoria, GASTO_CATEGORIAS, true)) send_json(["success"=>false,"error"=>"Categoría inválida"],400);

    $sql = GASTO_SELECT . ' WHERE g.gs_emp = ? AND g.gs_fec BETWEEN ? AND ?' . ($categoria !== null ? ' AND g.gs_cat = ?' : '')
        . ' ORDER BY g.gs_fec DESC, g.gs_ide DESC';

    $stmt = prepare_or_fail($con, $sql);

    if ($categoria !== null) {
        mysqli_stmt_bind_param($stmt, 'isss', $companyId, $desde, $hasta, $categoria);
    } else {
        mysqli_stmt_bind_param($stmt, 'iss', $companyId, $desde, $hasta);
    }

    $res = execute_or_fail($con, $stmt);

    $gastos = [];

    while ($r = mysqli_fetch_assoc($res)) $gastos[] = gasto_json($r);

    $total = round(array_sum(array_column($gastos, 'gs_mon')), 2);

    send_json(["success"=>true,"desde"=>$desde,"hasta"=>$hasta,"gastos"=>$gastos,"total"=>(float) $total]);
}

if ($method === 'POST') {
    require_permission('gastos.cargar');

    $gasto = leer_gasto($con, $companyId, get_json_input(), date('Y-m-d'));

    $usuarioId = current_user_id();

    // The gasto, its stock change and its movement are saved together
    mysqli_autocommit($con, false);

    efecto_stock($con, $companyId, $gasto, 1);

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_gs00 (gs_emp, gs_us, gs_cat, gs_mon, gs_fec, gs_des, gs_rep, gs_pro, gs_can, gs_est)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    mysqli_stmt_bind_param($stmt, 'iisdssiiis', $companyId, $usuarioId, $gasto['cat'], $gasto['mon'], $gasto['fec'], $gasto['des'],
        $gasto['rep'], $gasto['pro'], $gasto['can'], $gasto['est']);

    if (!mysqli_stmt_execute($stmt)) fallar($con, mysqli_stmt_error($stmt), 500);

    $id = mysqli_insert_id($con);

    if (!mysqli_commit($con)) fallar($con, mysqli_error($con), 500);

    mysqli_autocommit($con, true);

    send_json(["success"=>true,"gasto"=>gasto_por_id($con, $companyId, $id)],201);
}

if ($method === 'PUT') {
    require_permission('gastos.editar');

    $data = get_json_input();

    $id = id_gasto(is_array($data) && isset($data['id']) ? $data['id'] : null);

    $anterior = buscar_gasto($con, $companyId, $id, false);

    if (!$anterior) send_json(["success"=>false,"error"=>"Gasto no encontrado"],404);

    if (mueve_stock($anterior['cat'])) require_permission('stock.editar');

    $gasto = leer_gasto($con, $companyId, $data, $anterior['fec']);

    mysqli_autocommit($con, false);

    // locked re-read: the stock reverted is the one stored when the transaction runs
    $anterior = buscar_gasto($con, $companyId, $id, true);

    if (!$anterior) fallar($con, "Gasto no encontrado", 404);

    efecto_stock($con, $companyId, $anterior, -1);

    efecto_stock($con, $companyId, $gasto, 1);

    $stmt = prepare_or_fail($con, 'UPDATE sap_gs00 SET gs_cat = ?, gs_mon = ?, gs_fec = ?, gs_des = ?, gs_rep = ?, gs_pro = ?, gs_can = ?, gs_est = ?
        WHERE gs_ide = ? AND gs_emp = ?');

    mysqli_stmt_bind_param($stmt, 'sdssiiisii', $gasto['cat'], $gasto['mon'], $gasto['fec'], $gasto['des'], $gasto['rep'],
        $gasto['pro'], $gasto['can'], $gasto['est'], $id, $companyId);

    if (!mysqli_stmt_execute($stmt) || !mysqli_commit($con)) fallar($con, mysqli_stmt_error($stmt) ?: mysqli_error($con), 500);

    mysqli_autocommit($con, true);

    send_json(["success"=>true,"gasto"=>gasto_por_id($con, $companyId, $id)]);
}

if ($method === 'DELETE') {
    require_permission('gastos.editar');

    $id = id_gasto(isset($_GET['id']) ? $_GET['id'] : null);

    $anterior = buscar_gasto($con, $companyId, $id, false);

    if (!$anterior) send_json(["success"=>false,"error"=>"Gasto no encontrado"],404);

    if (mueve_stock($anterior['cat'])) require_permission('stock.editar');

    mysqli_autocommit($con, false);

    $anterior = buscar_gasto($con, $companyId, $id, true);

    if (!$anterior) fallar($con, "Gasto no encontrado", 404);

    efecto_stock($con, $companyId, $anterior, -1);

    $stmt = prepare_or_fail($con, 'DELETE FROM sap_gs00 WHERE gs_ide = ? AND gs_emp = ?');

    mysqli_stmt_bind_param($stmt, 'ii', $id, $companyId);

    if (!mysqli_stmt_execute($stmt) || !mysqli_commit($con)) fallar($con, mysqli_stmt_error($stmt) ?: mysqli_error($con), 500);

    mysqli_autocommit($con, true);

    send_json(["success"=>true]);
}

send_json(["success"=>false,"error"=>"Método no soportado"],405);

?>
