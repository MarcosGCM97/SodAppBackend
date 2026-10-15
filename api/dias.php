<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';

allow_cors();

// Every method requires a valid token; the owner always comes from it
$userId = current_user_id();

$method = $_SERVER['REQUEST_METHOD'];

// PUT body flag key => sap_di00.di_ide (day names come only from sap_di00.di_nom)
const DAY_FLAG_IDS = [
    'cl_lun' => 1,
    'cl_mar' => 2,
    'cl_mie' => 3,
    'cl_jue' => 4,
    'cl_vie' => 5,
    'cl_sab' => 6,
    'cl_dom' => 7,
];

// Executes a prepared statement inside the PUT transaction; rolls back and answers 500 on failure
function execute_or_rollback($con, $stmt) {
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_rollback($con);
        mysqli_autocommit($con, true);
        send_json(["success" => false, "error" => $error], 500);
    }
}

// GET /api/days.php or /api/days.php?id=1
if ($method === 'GET') {

    if (isset($_GET['id']) && intval($_GET['id']) > 0) {

        $id = intval($_GET['id']);

        // LEFT JOIN keeps one row for an owned cliente without days, so 404 means not found
        $stmt = prepare_or_fail($con, 'SELECT d.di_nom FROM sap_cl00 cl
            LEFT JOIN sap_cd00 cd ON cd.cd_cli = cl.cl_ide
            LEFT JOIN sap_di00 d ON d.di_ide = cd.cd_dia
            WHERE cl.cl_ide = ? AND cl.cl_emp = ? AND cl.is_deleted = 0
            ORDER BY d.di_ide');

        mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);

        $found = false;
        $diasEntrega = array();

        while ($row = mysqli_fetch_assoc($res)) {
            $found = true;
            if ($row['di_nom'] !== null) $diasEntrega[] = $row['di_nom'];
        }

        if ($found) send_json(["success" => true, "diasEntrega" => $diasEntrega]);

        send_json(["success" => false, "error" => "Day not found"], 404);
    } else {
        $stmt = prepare_or_fail($con, 'SELECT cl.cl_ide, cl.cl_nom, cl.cl_dir, d.di_nom FROM sap_cl00 cl
            LEFT JOIN sap_cd00 cd ON cd.cd_cli = cl.cl_ide
            LEFT JOIN sap_di00 d ON d.di_ide = cd.cd_dia
            WHERE cl.cl_emp = ? AND cl.is_deleted = 0
            ORDER BY cl.cl_ide, d.di_ide');

        mysqli_stmt_bind_param($stmt, 'i', $userId);

        if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

        $res = mysqli_stmt_get_result($stmt);

        // One entry per cliente, keyed by cl_ide while the joined day rows are folded in
        $clientes = [];

        while ($r = mysqli_fetch_assoc($res)) {

            $clId = $r['cl_ide'];

            if (!isset($clientes[$clId])) {
                $clientes[$clId] = array(
                    "nombre" => $r['cl_nom'],
                    "direccion" => $r['cl_dir'],
                    "diasEntrega" => array()
                );
            }

            if ($r['di_nom'] !== null) $clientes[$clId]['diasEntrega'][] = $r['di_nom'];
        }

        send_json(["success" => true, "days" => array_values($clientes)]);
    }
}

if ($method === 'PUT') {
    $data = get_json_input();

    // Android sends the cliente id as 'cl_ide'
    $id = isset($data['cl_ide']) ? intval($data['cl_ide']) : 0;

    if ($id <= 0) {
        send_json(["success" => false, "error" => "ID inválido o faltante"], 400);
    }

    // The cliente must belong to the token user and not be soft-deleted
    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_cl00 WHERE cl_ide = ? AND cl_emp = ? AND is_deleted = 0');
    mysqli_stmt_bind_param($check, 'ii', $id, $userId);
    mysqli_stmt_execute($check);
    $resCheck = mysqli_stmt_get_result($check);
    if (!$resCheck || mysqli_num_rows($resCheck) === 0) {
        send_json(["success" => false, "error" => "Cliente no encontrado"], 404);
    }

    // A missing key means the day is not selected
    $flags = [];
    $daysOn = [];
    $daysOff = [];
    foreach (DAY_FLAG_IDS as $key => $dayId) {
        $flags[$key] = (isset($data[$key]) && (int)$data[$key] === 1) ? 1 : 0;
        if ($flags[$key] === 1) $daysOn[] = $dayId; else $daysOff[] = $dayId;
    }

    // Prepared before the transaction so a prepare error needs no rollback
    $delete = null;
    if ($daysOff) {
        $delete = prepare_or_fail($con, 'DELETE FROM sap_cd00 WHERE cd_cli = ? AND cd_dia IN (' . implode(', ', array_fill(0, count($daysOff), '?')) . ')');
        mysqli_stmt_bind_param($delete, str_repeat('i', count($daysOff) + 1), $id, ...$daysOff);
    }

    $insert = null;
    if ($daysOn) {
        $insert = prepare_or_fail($con, 'INSERT IGNORE INTO sap_cd00 (cd_cli, cd_dia) VALUES ' . implode(', ', array_fill(0, count($daysOn), '(?, ?)')));
        $pairs = [];
        foreach ($daysOn as $dayId) {
            $pairs[] = $id;
            $pairs[] = $dayId;
        }
        mysqli_stmt_bind_param($insert, str_repeat('i', count($pairs)), ...$pairs);
    }

    // Legacy flags are still dual-written so this release can be rolled back
    // until migration 004 drops cl_lun..cl_dom
    $legacy = prepare_or_fail($con, 'UPDATE sap_cl00 SET cl_lun = ?, cl_mar = ?, cl_mie = ?, cl_jue = ?, cl_vie = ?, cl_sab = ?, cl_dom = ? WHERE cl_ide = ? AND cl_emp = ?');
    mysqli_stmt_bind_param($legacy, 'iiiiiiiii',
        $flags['cl_lun'],
        $flags['cl_mar'],
        $flags['cl_mie'],
        $flags['cl_jue'],
        $flags['cl_vie'],
        $flags['cl_sab'],
        $flags['cl_dom'],
        $id,
        $userId
    );

    // All writes succeed together or the previous days stay intact
    mysqli_autocommit($con, false);

    if ($delete) execute_or_rollback($con, $delete);
    if ($insert) execute_or_rollback($con, $insert);
    execute_or_rollback($con, $legacy);

    if (!mysqli_commit($con)) {
        mysqli_rollback($con);
        mysqli_autocommit($con, true);
        send_json(["success" => false, "error" => "No se pudo confirmar la transacción"], 500);
    }

    mysqli_autocommit($con, true);

    // Ownership was checked above, so 0 affected rows only means the days did not change
    send_json(["success" => true, "message" => "Días actualizados correctamente"], 200);
}
