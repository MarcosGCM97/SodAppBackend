<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Ruta (ruta-google-maps): the data the app needs to open Google Maps with the route of a
// reparto, the plant where every route starts and ends, and the trip start/end times.
//
// Self-contained module: it owns sap_pl00 and the rp_ini / rp_fin columns of sap_rp00, and
// only reads sap_rp00, sap_rs00 and sap_cl00 otherwise. It never includes repartos.php or
// clientes.php, so it can move to its own service later.
//
// GET                 the plant of the user: {"planta": {...} | null}
// GET  ?repartoId=N   the route: stops in rs_ord order with address, locality and coordinates,
//                     the plant and the trip times. 404 for templates and other users' repartos.
// POST {"accion": ...}:
//   guardar_planta {"direccion","localidad","lat"?,"lng"?}: creates or replaces the plant.
//   iniciar        {"repartoId"}: sets rp_ini to now when it is still empty; returns it.
//   finalizar      {"repartoId"}: sets rp_fin to now. 409 when the trip was not started, a stop
//                  is still pendiente, or it was already finished.

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

$method = $_SERVER['REQUEST_METHOD'];

function ruta_fetch_all($stmt) {
    if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    return $rows;
}

function ruta_numero_o_null($value) {
    return $value === null ? null : (float) $value;
}

function ruta_planta($con, $userId) {
    $stmt = prepare_or_fail($con, 'SELECT pl_dir, pl_loc, pl_lat, pl_lng FROM sap_pl00 WHERE pl_emp = ?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    $rows = ruta_fetch_all($stmt);
    if (!$rows) return null;
    return [
        'direccion' => $rows[0]['pl_dir'],
        'localidad' => $rows[0]['pl_loc'],
        'lat' => ruta_numero_o_null($rows[0]['pl_lat']),
        'lng' => ruta_numero_o_null($rows[0]['pl_lng']),
    ];
}

// Dated reparto of the owner (templates have rp_fec NULL), or null
function ruta_reparto($con, $userId, $repartoId) {
    $stmt = prepare_or_fail($con, 'SELECT rp_ide, rp_fec, rp_ini, rp_fin FROM sap_rp00
        WHERE rp_ide = ? AND rp_emp = ? AND rp_fec IS NOT NULL');
    mysqli_stmt_bind_param($stmt, 'ii', $repartoId, $userId);
    $rows = ruta_fetch_all($stmt);
    return $rows ? $rows[0] : null;
}

function ruta_reparto_o_404($con, $userId, $value) {
    $repartoId = parse_non_negative_int($value);
    $reparto = $repartoId ? ruta_reparto($con, $userId, $repartoId) : null;
    if (!$reparto) send_json(["success" => false, "error" => "Reparto no encontrado"], 404);
    return $reparto;
}

if ($method === 'GET') {
    if (!isset($_GET['repartoId'])) {
        send_json(["success" => true, "planta" => ruta_planta($con, $userId)]);
    }

    $reparto = ruta_reparto_o_404($con, $userId, $_GET['repartoId']);
    $repartoId = (int) $reparto['rp_ide'];

    $stmt = prepare_or_fail($con, 'SELECT s.rs_ide, s.rs_est, c.cl_ide, c.cl_nom, c.cl_dir, c.cl_loc, c.cl_lat, c.cl_lng
        FROM sap_rs00 s JOIN sap_cl00 c ON c.cl_ide = s.rs_cli WHERE s.rs_rp = ? ORDER BY s.rs_ord, s.rs_ide');
    mysqli_stmt_bind_param($stmt, 'i', $repartoId);
    $paradas = [];
    foreach (ruta_fetch_all($stmt) as $i => $s) {
        $paradas[] = [
            'paradaId' => (int) $s['rs_ide'],
            'orden' => $i + 1,
            'estado' => $s['rs_est'],
            'clienteId' => (int) $s['cl_ide'],
            'nombre' => $s['cl_nom'],
            'direccion' => $s['cl_dir'],
            'localidad' => $s['cl_loc'],
            'lat' => ruta_numero_o_null($s['cl_lat']),
            'lng' => ruta_numero_o_null($s['cl_lng']),
        ];
    }

    send_json(["success" => true, "ruta" => [
        'repartoId' => $repartoId,
        'fecha' => $reparto['rp_fec'],
        'inicio' => $reparto['rp_ini'],
        'fin' => $reparto['rp_fin'],
        'planta' => ruta_planta($con, $userId),
        'paradas' => $paradas,
    ]]);
}

if ($method === 'POST') {
    $data = get_json_input();
    if (!is_array($data)) $data = [];
    $accion = isset($data['accion']) ? $data['accion'] : null;

    if ($accion === 'guardar_planta') {
        $dir = parse_texto_opcional(isset($data['direccion']) ? $data['direccion'] : null, 150);
        $loc = parse_texto_opcional(isset($data['localidad']) ? $data['localidad'] : null, 100);
        if (!$dir || !$loc) send_json(["success" => false, "error" => "Datos incompletos: direccion y localidad"], 400);

        $coords = parse_coordenadas(isset($data['lat']) ? $data['lat'] : null, isset($data['lng']) ? $data['lng'] : null);
        if ($coords === false) send_json(["success" => false, "error" => "Coordenadas inválidas: latitud y longitud van juntas y en grados"], 400);
        list($lat, $lng) = $coords;

        $stmt = prepare_or_fail($con, 'INSERT INTO sap_pl00 (pl_emp, pl_dir, pl_loc, pl_lat, pl_lng) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE pl_dir = VALUES(pl_dir), pl_loc = VALUES(pl_loc), pl_lat = VALUES(pl_lat), pl_lng = VALUES(pl_lng)');
        mysqli_stmt_bind_param($stmt, 'issdd', $userId, $dir, $loc, $lat, $lng);
        if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

        send_json(["success" => true, "planta" => ruta_planta($con, $userId)]);
    }

    if ($accion === 'iniciar') {
        $reparto = ruta_reparto_o_404($con, $userId, isset($data['repartoId']) ? $data['repartoId'] : null);
        $repartoId = (int) $reparto['rp_ide'];
        $ahora = date('Y-m-d H:i:s');

        // Only the first call sets the start; concurrent calls keep whichever landed first
        $stmt = prepare_or_fail($con, 'UPDATE sap_rp00 SET rp_ini = ? WHERE rp_ide = ? AND rp_ini IS NULL');
        mysqli_stmt_bind_param($stmt, 'si', $ahora, $repartoId);
        if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

        $reparto = ruta_reparto($con, $userId, $repartoId);
        send_json(["success" => true, "inicio" => $reparto['rp_ini']]);
    }

    if ($accion === 'finalizar') {
        $reparto = ruta_reparto_o_404($con, $userId, isset($data['repartoId']) ? $data['repartoId'] : null);
        $repartoId = (int) $reparto['rp_ide'];

        if ($reparto['rp_ini'] === null) send_json(["success" => false, "error" => "El recorrido no fue iniciado"], 409);
        if ($reparto['rp_fin'] !== null) send_json(["success" => false, "error" => "El recorrido ya fue finalizado"], 409);

        $ahora = date('Y-m-d H:i:s');

        // One statement checks and writes, so a stop reopened meanwhile cannot slip through
        $stmt = prepare_or_fail($con, "UPDATE sap_rp00 SET rp_fin = ? WHERE rp_ide = ? AND rp_fin IS NULL
            AND NOT EXISTS (SELECT 1 FROM sap_rs00 WHERE rs_rp = ? AND rs_est = 'pendiente')");
        mysqli_stmt_bind_param($stmt, 'sii', $ahora, $repartoId, $repartoId);
        if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

        if (mysqli_stmt_affected_rows($stmt) === 0) {
            $reparto = ruta_reparto($con, $userId, $repartoId);
            $error = $reparto['rp_fin'] !== null ? 'El recorrido ya fue finalizado' : 'Quedan paradas pendientes';
            send_json(["success" => false, "error" => $error], 409);
        }

        $reparto = ruta_reparto($con, $userId, $repartoId);
        send_json(["success" => true, "inicio" => $reparto['rp_ini'], "fin" => $reparto['rp_fin']]);
    }

    send_json(["success" => false, "error" => "Acción inválida"], 400);
}

send_json(["success" => false, "error" => "Método no soportado"], 405);
