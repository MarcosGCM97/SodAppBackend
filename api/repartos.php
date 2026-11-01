<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Repartos (armado de repartos): the deliveries of a concrete date as ordered stops with
// planned lines. Building and editing a reparto never touches stock or client debt.
//
// GET  ?id=N | ?fecha=YYYY-MM-DD: the reparto with its stops in rs_ord order and their lines.
// POST {"accion": ...}:
//   crear             {"fecha","origen"}: origen vacio | dias (clientes with that delivery day)
//                     | plantilla (the weekday template) | anterior (latest earlier reparto of
//                     that weekday). One reparto per date. Copied lines take the current pr_val.
//   guardar_plantilla {"repartoId"}: replaces the weekday template with this reparto.
//   agregar_parada    {"repartoId","clienteId"}: appends a stop; any cliente, once per reparto.
//   quitar_parada     {"paradaId"}: only pendiente stops.
//   guardar_lineas    {"paradaId","lineas":[{"productoId","cantidad","precio"?}]}: replaces the
//                     planned lines of a pendiente stop; precio defaults to pr_val.
//   ordenar           {"repartoId","paradas":[rs_ide, ...]}: every stop, in the new order.
//   entregar          {"paradaId","lineas"?:[{"productoId","cantidad","devueltos"?,"precio"?}]}: registers the
//                     delivered quantities as sales (registrar_venta_lineas) and marks it entregada.
//   no_entregar       {"paradaId"}: pendiente -> no_entregada; reabrir: no_entregada -> pendiente.

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

$method = $_SERVER['REQUEST_METHOD'];

const ORIGENES_REPARTO = ['vacio', 'dias', 'plantilla', 'anterior'];

// Rolls back the open transaction (if any) and sends the error
function fallar($con, $status, $error) {
    mysqli_rollback($con);
    send_json(["success" => false, "error" => $error], $status);
}

// YYYY-MM-DD of a real calendar date, or null
function parse_fecha($value) {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return null;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
}

function fetch_all_assoc($stmt) {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) $rows[] = $row;
    return $rows;
}

// Dated reparto of the owner (templates are not reachable by id), or null
function reparto_del_usuario($con, $userId, $repartoId) {
    $stmt = prepare_or_fail($con, 'SELECT rp_ide, rp_fec, rp_dia FROM sap_rp00 WHERE rp_ide = ? AND rp_emp = ? AND rp_fec IS NOT NULL');
    mysqli_stmt_bind_param($stmt, 'ii', $repartoId, $userId);
    $rows = fetch_all_assoc($stmt);
    return $rows ? $rows[0] : null;
}

// Stop of a dated reparto of the owner, locked for the rest of the transaction, or null
function parada_del_usuario($con, $userId, $paradaId) {
    $stmt = prepare_or_fail($con, 'SELECT s.rs_ide, s.rs_rp, s.rs_cli, s.rs_est FROM sap_rs00 s
        JOIN sap_rp00 r ON r.rp_ide = s.rs_rp
        WHERE s.rs_ide = ? AND r.rp_emp = ? AND r.rp_fec IS NOT NULL FOR UPDATE');
    mysqli_stmt_bind_param($stmt, 'ii', $paradaId, $userId);
    $rows = fetch_all_assoc($stmt);
    return $rows ? $rows[0] : null;
}

function reparto_detalle($con, $repartoId) {
    $stmt = prepare_or_fail($con, 'SELECT rp_ide, rp_fec, rp_dia, rp_ini, rp_fin FROM sap_rp00 WHERE rp_ide = ?');
    mysqli_stmt_bind_param($stmt, 'i', $repartoId);
    $reparto = fetch_all_assoc($stmt)[0];

    $stmt = prepare_or_fail($con, 'SELECT s.rs_ide, s.rs_ord, s.rs_est, c.cl_ide, c.cl_nom, c.cl_dir, c.cl_tel
        FROM sap_rs00 s JOIN sap_cl00 c ON c.cl_ide = s.rs_cli WHERE s.rs_rp = ? ORDER BY s.rs_ord, s.rs_ide');
    mysqli_stmt_bind_param($stmt, 'i', $repartoId);
    $paradas = [];
    foreach (fetch_all_assoc($stmt) as $s) {
        $s['lineas'] = [];
        $paradas[$s['rs_ide']] = $s;
    }

    $stmt = prepare_or_fail($con, 'SELECT l.rl_rs, l.rl_ide, p.pr_ide, p.pr_nom, p.pr_ret, l.rl_cpl, l.rl_cen, l.rl_pre, l.rl_dev, l.rl_vt
        FROM sap_rl00 l JOIN sap_rs00 s ON s.rs_ide = l.rl_rs JOIN sap_pr00 p ON p.pr_ide = l.rl_pro
        WHERE s.rs_rp = ? ORDER BY l.rl_ide');
    mysqli_stmt_bind_param($stmt, 'i', $repartoId);
    foreach (fetch_all_assoc($stmt) as $l) {
        $rs = $l['rl_rs'];
        unset($l['rl_rs']);
        $paradas[$rs]['lineas'][] = $l;
    }

    $reparto['paradas'] = array_values($paradas);
    return $reparto;
}

// Copies the stops (non-deleted clientes, same order) and planned lines (non-deleted productos,
// current pr_val) of reparto $origenId into the empty reparto $destinoId; all stops pendiente
function copiar_paradas($con, $origenId, $destinoId) {
    $stmt = prepare_or_fail($con, 'SELECT s.rs_ide, s.rs_cli FROM sap_rs00 s JOIN sap_cl00 c ON c.cl_ide = s.rs_cli
        WHERE s.rs_rp = ? AND c.is_deleted = 0 ORDER BY s.rs_ord, s.rs_ide');
    mysqli_stmt_bind_param($stmt, 'i', $origenId);
    $paradas = fetch_all_assoc($stmt);

    $stmtStop = prepare_or_fail($con, "INSERT INTO sap_rs00 (rs_rp, rs_cli, rs_ord, rs_est) VALUES (?, ?, ?, 'pendiente')");
    $stmtLines = prepare_or_fail($con, 'INSERT INTO sap_rl00 (rl_rs, rl_pro, rl_cpl, rl_pre)
        SELECT ?, l.rl_pro, l.rl_cpl, COALESCE(p.pr_val, 0) FROM sap_rl00 l JOIN sap_pr00 p ON p.pr_ide = l.rl_pro
        WHERE l.rl_rs = ? AND p.deleted_at IS NULL ORDER BY l.rl_ide');

    foreach ($paradas as $i => $s) {
        $orden = $i + 1;
        $clienteId = (int) $s['rs_cli'];
        mysqli_stmt_bind_param($stmtStop, 'iii', $destinoId, $clienteId, $orden);
        if (!mysqli_stmt_execute($stmtStop)) fallar($con, 500, mysqli_stmt_error($stmtStop));

        $nuevaId = mysqli_insert_id($con);
        $viejaId = (int) $s['rs_ide'];
        mysqli_stmt_bind_param($stmtLines, 'ii', $nuevaId, $viejaId);
        if (!mysqli_stmt_execute($stmtLines)) fallar($con, 500, mysqli_stmt_error($stmtLines));
    }
}

function insertar_reparto($con, $userId, $fecha, $dia) {
    $stmt = prepare_or_fail($con, 'INSERT INTO sap_rp00 (rp_emp, rp_fec, rp_dia) VALUES (?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'isi', $userId, $fecha, $dia);
    if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));
    return mysqli_insert_id($con);
}

function commit_or_fail($con) {
    if (!mysqli_commit($con)) fallar($con, 500, 'No se pudo confirmar la transacción');
    mysqli_autocommit($con, true);
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $repartoId = parse_non_negative_int($_GET['id']);
        $reparto = $repartoId ? reparto_del_usuario($con, $userId, $repartoId) : null;
    } else if (isset($_GET['fecha'])) {
        $fecha = parse_fecha($_GET['fecha']);
        if ($fecha === null) send_json(["success" => false, "error" => "Fecha inválida: debe ser AAAA-MM-DD"], 400);
        $stmt = prepare_or_fail($con, 'SELECT rp_ide FROM sap_rp00 WHERE rp_emp = ? AND rp_fec = ? ORDER BY rp_ide LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'is', $userId, $fecha);
        $rows = fetch_all_assoc($stmt);
        $reparto = $rows ? ['rp_ide' => $rows[0]['rp_ide']] : null;
    } else {
        send_json(["success" => false, "error" => "Datos incompletos: id o fecha son requeridos"], 400);
    }

    if (!$reparto) send_json(["success" => false, "error" => "Reparto no encontrado"], 404);

    send_json(["success" => true, "reparto" => reparto_detalle($con, (int) $reparto['rp_ide'])]);
}

if ($method === 'POST') {
    $data = get_json_input();

    $accion = isset($data['accion']) ? $data['accion'] : null;

    mysqli_autocommit($con, false);

    if ($accion === 'crear') {
        $fecha = isset($data['fecha']) ? parse_fecha($data['fecha']) : null;
        if ($fecha === null) fallar($con, 400, "Fecha inválida: debe ser AAAA-MM-DD");

        $origen = isset($data['origen']) ? $data['origen'] : null;
        if (!in_array($origen, ORIGENES_REPARTO, true)) fallar($con, 400, "Origen inválido: debe ser vacio, dias, plantilla o anterior");

        // 1 Lunes ... 7 Domingo, like sap_di00
        $dia = (int) date('N', strtotime($fecha));

        // One reparto per date for now; rp_rep can widen this later (per repartidor)
        $stmt = prepare_or_fail($con, 'SELECT rp_ide FROM sap_rp00 WHERE rp_emp = ? AND rp_fec = ? FOR UPDATE');
        mysqli_stmt_bind_param($stmt, 'is', $userId, $fecha);
        if (fetch_all_assoc($stmt)) fallar($con, 409, "Ya existe un reparto para esa fecha");

        $origenId = null;
        if ($origen === 'plantilla') {
            $stmt = prepare_or_fail($con, 'SELECT rp_ide FROM sap_rp00 WHERE rp_emp = ? AND rp_fec IS NULL AND rp_dia = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $userId, $dia);
            $rows = fetch_all_assoc($stmt);
            if (!$rows) fallar($con, 404, "No hay una plantilla guardada para ese día");
            $origenId = (int) $rows[0]['rp_ide'];
        } else if ($origen === 'anterior') {
            $stmt = prepare_or_fail($con, 'SELECT rp_ide FROM sap_rp00 WHERE rp_emp = ? AND rp_dia = ? AND rp_fec < ?
                ORDER BY rp_fec DESC, rp_ide DESC LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'iis', $userId, $dia, $fecha);
            $rows = fetch_all_assoc($stmt);
            if (!$rows) fallar($con, 404, "No hay un reparto anterior de ese día");
            $origenId = (int) $rows[0]['rp_ide'];
        }

        $repartoId = insertar_reparto($con, $userId, $fecha, $dia);

        if ($origenId !== null) {
            copiar_paradas($con, $origenId, $repartoId);
        } else if ($origen === 'dias') {
            $stmt = prepare_or_fail($con, "INSERT INTO sap_rs00 (rs_rp, rs_cli, rs_ord, rs_est) VALUES (?, ?, ?, 'pendiente')");
            $stmtClientes = prepare_or_fail($con, 'SELECT c.cl_ide FROM sap_cl00 c JOIN sap_cd00 d ON d.cd_cli = c.cl_ide
                WHERE c.cl_emp = ? AND c.is_deleted = 0 AND d.cd_dia = ? ORDER BY c.cl_nom, c.cl_ide');
            mysqli_stmt_bind_param($stmtClientes, 'ii', $userId, $dia);
            foreach (fetch_all_assoc($stmtClientes) as $i => $c) {
                $orden = $i + 1;
                $clienteId = (int) $c['cl_ide'];
                mysqli_stmt_bind_param($stmt, 'iii', $repartoId, $clienteId, $orden);
                if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));
            }
        }

        commit_or_fail($con);

        send_json(["success" => true, "reparto" => reparto_detalle($con, $repartoId)], 201);
    }

    if ($accion === 'guardar_plantilla') {
        $repartoId = isset($data['repartoId']) ? parse_non_negative_int($data['repartoId']) : null;
        $reparto = $repartoId ? reparto_del_usuario($con, $userId, $repartoId) : null;
        if (!$reparto) fallar($con, 404, "Reparto no encontrado");

        $dia = (int) $reparto['rp_dia'];

        // The previous template of that weekday goes away with its stops and lines (cascade)
        $stmt = prepare_or_fail($con, 'DELETE FROM sap_rp00 WHERE rp_emp = ? AND rp_fec IS NULL AND rp_dia = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $userId, $dia);
        if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));

        $plantillaId = insertar_reparto($con, $userId, null, $dia);
        copiar_paradas($con, $repartoId, $plantillaId);

        commit_or_fail($con);

        send_json(["success" => true, "message" => "Plantilla guardada"]);
    }

    if ($accion === 'agregar_parada') {
        $repartoId = isset($data['repartoId']) ? parse_non_negative_int($data['repartoId']) : null;
        if (!$repartoId || !reparto_del_usuario($con, $userId, $repartoId)) fallar($con, 404, "Reparto no encontrado");

        $clienteId = isset($data['clienteId']) ? parse_non_negative_int($data['clienteId']) : null;
        $stmt = prepare_or_fail($con, 'SELECT cl_ide FROM sap_cl00 WHERE cl_ide = ? AND cl_emp = ? AND is_deleted = 0');
        mysqli_stmt_bind_param($stmt, 'ii', $clienteId, $userId);
        if (!$clienteId || !fetch_all_assoc($stmt)) fallar($con, 404, "Cliente no encontrado o eliminado");

        // Appended last; the unique (rs_rp, rs_cli) key rejects a cliente already in the reparto
        $stmt = prepare_or_fail($con, "INSERT INTO sap_rs00 (rs_rp, rs_cli, rs_ord, rs_est)
            SELECT ?, ?, COALESCE(MAX(rs_ord), 0) + 1, 'pendiente' FROM sap_rs00 WHERE rs_rp = ?");
        mysqli_stmt_bind_param($stmt, 'iii', $repartoId, $clienteId, $repartoId);
        if (!mysqli_stmt_execute($stmt)) {
            if (mysqli_stmt_errno($stmt) === 1062) fallar($con, 409, "El cliente ya está en el reparto");
            fallar($con, 500, mysqli_stmt_error($stmt));
        }
        $paradaId = mysqli_insert_id($con);

        commit_or_fail($con);

        send_json(["success" => true, "paradaId" => $paradaId], 201);
    }

    if (in_array($accion, ['quitar_parada', 'guardar_lineas', 'entregar', 'no_entregar', 'reabrir'], true)) {
        // The stop row stays locked until commit, so two deliveries of one stop run one after the other
        $paradaId = isset($data['paradaId']) ? parse_non_negative_int($data['paradaId']) : null;
        $parada = $paradaId ? parada_del_usuario($con, $userId, $paradaId) : null;
        if (!$parada) fallar($con, 404, "Parada no encontrada");

        // entregada is final; only no_entregada goes back to pendiente
        if ($accion === 'reabrir') {
            if ($parada['rs_est'] !== 'no_entregada') fallar($con, 409, "Solo se pueden reabrir paradas no entregadas");
        } else if ($parada['rs_est'] !== 'pendiente') {
            fallar($con, 409, "Solo se pueden modificar paradas pendientes");
        }
    }

    if ($accion === 'no_entregar' || $accion === 'reabrir') {
        // Only the status changes: stock, debt and sales stay as they are
        $estado = $accion === 'no_entregar' ? 'no_entregada' : 'pendiente';
        $stmt = prepare_or_fail($con, 'UPDATE sap_rs00 SET rs_est = ? WHERE rs_ide = ?');
        mysqli_stmt_bind_param($stmt, 'si', $estado, $paradaId);
        if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));

        commit_or_fail($con);

        send_json(["success" => true, "message" => "Parada $estado"]);
    }

    if ($accion === 'entregar') {
        // Planned lines: producto id => [planned, price]
        $stmt = prepare_or_fail($con, 'SELECT rl_pro, rl_cpl, rl_pre FROM sap_rl00 WHERE rl_rs = ? ORDER BY rl_ide');
        mysqli_stmt_bind_param($stmt, 'i', $paradaId);
        $plan = [];
        foreach (fetch_all_assoc($stmt) as $l) $plan[(int) $l['rl_pro']] = [(int) $l['rl_cpl'], (float) $l['rl_pre']];

        $stmtProducto = prepare_or_fail($con, 'SELECT pr_nom, pr_val, pr_ret FROM sap_pr00 WHERE pr_ide = ? AND pr_emp = ? AND deleted_at IS NULL');
        $productos = [];
        $producto = function ($productoId) use ($con, $userId, $stmtProducto, &$productos) {
            if (!isset($productos[$productoId])) {
                mysqli_stmt_bind_param($stmtProducto, 'ii', $productoId, $userId);
                $rows = fetch_all_assoc($stmtProducto);
                if (!$rows) fallar($con, 404, "Producto no encontrado: $productoId");
                $productos[$productoId] = $rows[0];
            }
            return $productos[$productoId];
        };

        // Delivered: producto id => [quantity, returned containers, price]. Without lineas the plan
        // is delivered as is; with lineas, a planned producto that is not sent was not delivered.
        $entregado = [];
        if (!isset($data['lineas'])) {
            foreach ($plan as $productoId => list($cantidad, $precio)) $entregado[$productoId] = [$cantidad, 0, $precio];
        } else {
            if (!is_array($data['lineas'])) fallar($con, 400, "Datos inválidos: lineas debe ser una lista");

            // Same order as POST ventas: every 404 before any 400
            foreach ($data['lineas'] as $l) {
                $productoId = isset($l['productoId']) ? parse_non_negative_int($l['productoId']) : null;
                if ($productoId) $producto($productoId);
            }

            foreach ($data['lineas'] as $l) {
                $productoId = isset($l['productoId']) ? parse_non_negative_int($l['productoId']) : null;
                $cantidad = isset($l['cantidad']) ? parse_non_negative_int($l['cantidad']) : null;
                $devueltos = isset($l['devueltos']) ? parse_non_negative_int($l['devueltos']) : 0;
                $precio = isset($l['precio']) ? $l['precio'] : (isset($plan[$productoId]) ? $plan[$productoId][1] : ($productoId ? $producto($productoId)['pr_val'] : null));

                if (!$productoId || $cantidad === null || !is_numeric($precio) || (float) $precio <= 0) {
                    fallar($con, 400, "Línea inválida: producto, cantidad (0 o más) y precio (mayor a 0) son requeridos");
                }
                if ($devueltos === null) fallar($con, 400, "Envases devueltos inválidos: deben ser un número entero mayor o igual a 0");
                if (isset($entregado[$productoId])) fallar($con, 400, "Producto repetido en la entrega: $productoId");

                $entregado[$productoId] = [$cantidad, $devueltos, (float) $precio];
            }
            foreach ($plan as $productoId => list(, $precio)) {
                if (!isset($entregado[$productoId])) $entregado[$productoId] = [0, 0, $precio];
            }
        }

        // The sale lines, built from the delivered quantities only
        $lineasVenta = [];
        $monto = 0;
        foreach ($entregado as $productoId => list($cantidad, $devueltos, $precio)) {
            if ($cantidad === 0) continue;
            $lineasVenta[$productoId] = ['nombre' => $producto($productoId)['pr_nom'], 'cantidad' => $cantidad, 'precio' => $precio, 'devueltos' => $devueltos];
            $monto += $cantidad * $precio;
        }
        if (!$lineasVenta) fallar($con, 400, "No hay productos entregados: para registrar que no se entregó use no_entregar");

        // Same sale registration as POST ventas (stock, movements, containers, debt), in this transaction
        $ventaIds = registrar_venta_lineas($con, $userId, (int) $parada['rs_cli'], array_values($lineasVenta), date('Y-m-d H:i:s'));
        $ventaPorProducto = array_combine(array_keys($lineasVenta), $ventaIds);

        $stmtUpdate = prepare_or_fail($con, 'UPDATE sap_rl00 SET rl_cen = ?, rl_pre = ?, rl_dev = ?, rl_vt = ? WHERE rl_rs = ? AND rl_pro = ?');
        $stmtInsert = prepare_or_fail($con, 'INSERT INTO sap_rl00 (rl_rs, rl_pro, rl_cpl, rl_cen, rl_pre, rl_dev, rl_vt) VALUES (?, ?, 0, ?, ?, ?, ?)');
        foreach ($entregado as $productoId => list($cantidad, $devueltos, $precio)) {
            $ventaId = isset($ventaPorProducto[$productoId]) ? $ventaPorProducto[$productoId] : null;
            // Returned containers only make sense for a delivered returnable producto
            $dev = $ventaId !== null && intval($producto($productoId)['pr_ret']) === 1 ? $devueltos : null;
            if (isset($plan[$productoId])) {
                mysqli_stmt_bind_param($stmtUpdate, 'idiiii', $cantidad, $precio, $dev, $ventaId, $paradaId, $productoId);
                $ok = mysqli_stmt_execute($stmtUpdate);
            } else {
                mysqli_stmt_bind_param($stmtInsert, 'iiidii', $paradaId, $productoId, $cantidad, $precio, $dev, $ventaId);
                $ok = mysqli_stmt_execute($stmtInsert);
            }
            if (!$ok) fallar($con, 500, mysqli_error($con));
        }

        $stmt = prepare_or_fail($con, "UPDATE sap_rs00 SET rs_est = 'entregada' WHERE rs_ide = ?");
        mysqli_stmt_bind_param($stmt, 'i', $paradaId);
        if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));

        commit_or_fail($con);

        send_json(["success" => true, "monto" => round($monto, 2), "ventas" => $ventaIds]);
    }

    if ($accion === 'quitar_parada') {
        $stmt = prepare_or_fail($con, 'DELETE FROM sap_rs00 WHERE rs_ide = ?');
        mysqli_stmt_bind_param($stmt, 'i', $paradaId);
        if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));

        commit_or_fail($con);

        send_json(["success" => true, "message" => "Parada quitada"]);
    }

    if ($accion === 'guardar_lineas') {
        $lineas = isset($data['lineas']) && is_array($data['lineas']) ? $data['lineas'] : null;
        if ($lineas === null) fallar($con, 400, "Datos incompletos: lineas es requerido");

        // Same order as POST ventas: every 404 before any 400
        $stmtProducto = prepare_or_fail($con, 'SELECT pr_val FROM sap_pr00 WHERE pr_ide = ? AND pr_emp = ? AND deleted_at IS NULL');
        $precios = [];
        foreach ($lineas as $l) {
            $productoId = isset($l['productoId']) ? parse_non_negative_int($l['productoId']) : null;
            if (!$productoId) continue; // reported as an invalid line below
            mysqli_stmt_bind_param($stmtProducto, 'ii', $productoId, $userId);
            $rows = fetch_all_assoc($stmtProducto);
            if (!$rows) fallar($con, 404, "Producto no encontrado: $productoId");
            $precios[$productoId] = $rows[0]['pr_val'];
        }

        $validas = [];
        foreach ($lineas as $l) {
            $productoId = isset($l['productoId']) ? parse_non_negative_int($l['productoId']) : null;
            $cantidad = isset($l['cantidad']) ? parse_non_negative_int($l['cantidad']) : null;
            $precio = isset($l['precio']) ? $l['precio'] : $precios[$productoId] ?? null;

            if (!$productoId || !$cantidad || !is_numeric($precio) || (float) $precio <= 0) {
                fallar($con, 400, "Línea inválida: producto, cantidad y precio deben ser mayores a 0");
            }
            if (isset($validas[$productoId])) fallar($con, 400, "Producto repetido en la parada: $productoId");

            $validas[$productoId] = [$cantidad, (float) $precio];
        }

        $stmt = prepare_or_fail($con, 'DELETE FROM sap_rl00 WHERE rl_rs = ?');
        mysqli_stmt_bind_param($stmt, 'i', $paradaId);
        if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));

        $stmt = prepare_or_fail($con, 'INSERT INTO sap_rl00 (rl_rs, rl_pro, rl_cpl, rl_pre) VALUES (?, ?, ?, ?)');
        foreach ($validas as $productoId => list($cantidad, $precio)) {
            mysqli_stmt_bind_param($stmt, 'iiid', $paradaId, $productoId, $cantidad, $precio);
            if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));
        }

        commit_or_fail($con);

        send_json(["success" => true, "message" => "Líneas guardadas"]);
    }

    if ($accion === 'ordenar') {
        $repartoId = isset($data['repartoId']) ? parse_non_negative_int($data['repartoId']) : null;
        if (!$repartoId || !reparto_del_usuario($con, $userId, $repartoId)) fallar($con, 404, "Reparto no encontrado");

        $orden = isset($data['paradas']) && is_array($data['paradas']) ? array_map('parse_non_negative_int', $data['paradas']) : [];

        $stmt = prepare_or_fail($con, 'SELECT rs_ide FROM sap_rs00 WHERE rs_rp = ? FOR UPDATE');
        mysqli_stmt_bind_param($stmt, 'i', $repartoId);
        $actuales = array_map('intval', array_column(fetch_all_assoc($stmt), 'rs_ide'));

        $enviadas = $orden;
        sort($enviadas);
        sort($actuales);
        if ($enviadas !== $actuales) fallar($con, 400, "La lista de paradas debe tener cada parada del reparto una sola vez");

        $stmt = prepare_or_fail($con, 'UPDATE sap_rs00 SET rs_ord = ? WHERE rs_ide = ?');
        foreach ($orden as $i => $paradaId) {
            $posicion = $i + 1;
            mysqli_stmt_bind_param($stmt, 'ii', $posicion, $paradaId);
            if (!mysqli_stmt_execute($stmt)) fallar($con, 500, mysqli_stmt_error($stmt));
        }

        commit_or_fail($con);

        send_json(["success" => true, "message" => "Paradas ordenadas"]);
    }

    fallar($con, 400, "Acción no soportada");
}

send_json(["success" => false, "error" => "Método no permitido"], 405);
?>
