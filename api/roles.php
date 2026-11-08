<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Roles of the company and their permissions (admin API, owner only; anyone else gets
// 403 "permiso":"admin"). Permission codes come from the sap_pm00 catalog.
//
// GET                                    the company roles (permission codes and how many users
//                                        have each) and the catalog (permisos_disponibles).
// POST   {"nombre","permisos":[codes]}   creates a role. 201 {"ro_ide":N}.
// PUT    {"id","nombre","permisos"}      replaces the name and the permissions of a role.
// DELETE ?id=N                           deletes a role; 409 while users have it.
// 400 invalid name (1-50 characters) or unknown code, 404 role not in the company,
// 409 another role of the company has that name. Writes run in one transaction.

const ROL_NOMBRE_MAX = 50;

require_owner();
$companyId = current_company_id();

$method = $_SERVER['REQUEST_METHOD'];

// Rolls back the open transaction (if any) and sends the error
function rol_fallar($con, $status, $error) {
    mysqli_rollback($con);
    send_json(["success" => false, "error" => $error], $status);
}

// Trimmed role name of 1..ROL_NOMBRE_MAX characters, or null
function parse_rol_nombre($value) {
    if (!is_string($value)) return null;
    $value = trim($value);
    $largo = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    return ($largo >= 1 && $largo <= ROL_NOMBRE_MAX) ? $value : null;
}

// pm_cod => pm_ide of the whole catalog
function catalogo_permisos($con) {
    $res = mysqli_query($con, 'SELECT pm_ide, pm_cod FROM sap_pm00');
    if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);
    $catalogo = [];
    while ($r = mysqli_fetch_assoc($res)) $catalogo[$r['pm_cod']] = intval($r['pm_ide']);
    return $catalogo;
}

// Validates the name and the permission codes of a POST/PUT body. Returns [name, [pm_ide, ...]]
// (each permission once) or sends 400.
function parse_rol_body($con, $data) {
    $nombre = isset($data['nombre']) ? parse_rol_nombre($data['nombre']) : null;
    if ($nombre === null) send_json(["success" => false, "error" => "Nombre inválido: debe tener entre 1 y 50 caracteres"], 400);

    if (!isset($data['permisos']) || !is_array($data['permisos']) || array_values($data['permisos']) !== $data['permisos']) {
        send_json(["success" => false, "error" => "Permisos inválidos: debe ser una lista de códigos"], 400);
    }

    $catalogo = catalogo_permisos($con);
    $ids = [];
    foreach ($data['permisos'] as $code) {
        if (!is_string($code) || !isset($catalogo[$code])) {
            send_json(["success" => false, "error" => "Permiso desconocido: " . (is_string($code) ? $code : json_encode($code))], 400);
        }
        $ids[$catalogo[$code]] = true;
    }
    return [$nombre, array_keys($ids)];
}

// True when another role of the company (not $exceptId) already has that name
function rol_nombre_tomado($con, $companyId, $nombre, $exceptId) {
    $stmt = prepare_or_fail($con, 'SELECT 1 FROM sap_ro00 WHERE ro_emp = ? AND ro_nom = ? AND ro_ide <> ?');
    mysqli_stmt_bind_param($stmt, 'isi', $companyId, $nombre, $exceptId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    return $res && mysqli_num_rows($res) > 0;
}

// True when the role belongs to the company; with $lock the row stays locked until commit
function rol_de_empresa($con, $companyId, $roleId, $lock = false) {
    $stmt = prepare_or_fail($con, 'SELECT 1 FROM sap_ro00 WHERE ro_ide = ? AND ro_emp = ?' . ($lock ? ' FOR UPDATE' : ''));
    mysqli_stmt_bind_param($stmt, 'ii', $roleId, $companyId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    return $res && mysqli_num_rows($res) > 0;
}

// Inserts the role permissions; the caller owns the transaction
function insertar_permisos($con, $roleId, $permIds) {
    $stmt = prepare_or_fail($con, 'INSERT INTO sap_rm00 (rm_rol, rm_per) VALUES (?, ?)');
    foreach ($permIds as $permId) {
        mysqli_stmt_bind_param($stmt, 'ii', $roleId, $permId);
        if (!mysqli_stmt_execute($stmt)) rol_fallar($con, 500, mysqli_stmt_error($stmt));
    }
}

function rol_commit($con) {
    if (!mysqli_commit($con)) rol_fallar($con, 500, 'No se pudo confirmar la transacción');
    mysqli_autocommit($con, true);
}

if ($method === 'GET') {
    $stmt = prepare_or_fail($con, 'SELECT r.ro_ide, r.ro_nom,
            (SELECT COUNT(*) FROM sap_us00 u WHERE u.us_rol = r.ro_ide) AS usuarios
        FROM sap_ro00 r WHERE r.ro_emp = ? ORDER BY r.ro_nom, r.ro_ide');
    mysqli_stmt_bind_param($stmt, 'i', $companyId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

    $roles = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $roles[intval($r['ro_ide'])] = [
            'ro_ide' => intval($r['ro_ide']),
            'ro_nom' => $r['ro_nom'],
            'permisos' => [],
            'usuarios' => intval($r['usuarios']),
        ];
    }

    $stmt = prepare_or_fail($con, 'SELECT m.rm_rol, p.pm_cod FROM sap_rm00 m
        JOIN sap_ro00 r ON r.ro_ide = m.rm_rol
        JOIN sap_pm00 p ON p.pm_ide = m.rm_per
        WHERE r.ro_emp = ? ORDER BY p.pm_ide');
    mysqli_stmt_bind_param($stmt, 'i', $companyId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $roles[intval($r['rm_rol'])]['permisos'][] = $r['pm_cod'];
    }

    $res = mysqli_query($con, 'SELECT pm_cod, pm_des FROM sap_pm00 ORDER BY pm_ide');
    if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);
    $disponibles = [];
    while ($r = mysqli_fetch_assoc($res)) $disponibles[] = ['pm_cod' => $r['pm_cod'], 'pm_des' => $r['pm_des']];

    send_json(["success" => true, "roles" => array_values($roles), "permisos_disponibles" => $disponibles]);
}

if ($method === 'POST') {
    $data = get_json_input();
    list($nombre, $permIds) = parse_rol_body($con, $data);

    mysqli_autocommit($con, false);

    if (rol_nombre_tomado($con, $companyId, $nombre, 0)) rol_fallar($con, 409, "Ya existe un rol con ese nombre");

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_ro00 (ro_emp, ro_nom) VALUES (?, ?)');
    mysqli_stmt_bind_param($stmt, 'is', $companyId, $nombre);
    if (!mysqli_stmt_execute($stmt)) {
        // uq_ro_emp_nom: a concurrent request took the name
        if (mysqli_stmt_errno($stmt) === 1062) rol_fallar($con, 409, "Ya existe un rol con ese nombre");
        rol_fallar($con, 500, mysqli_stmt_error($stmt));
    }
    $roleId = mysqli_insert_id($con);

    insertar_permisos($con, $roleId, $permIds);
    rol_commit($con);

    send_json(["success" => true, "ro_ide" => $roleId], 201);
}

if ($method === 'PUT') {
    $data = get_json_input();

    $roleId = isset($data['id']) ? parse_non_negative_int($data['id']) : null;
    if (!$roleId) send_json(["success" => false, "error" => "Datos incompletos: id es requerido"], 400);

    list($nombre, $permIds) = parse_rol_body($con, $data);

    mysqli_autocommit($con, false);

    if (!rol_de_empresa($con, $companyId, $roleId, true)) rol_fallar($con, 404, "Rol no encontrado");
    if (rol_nombre_tomado($con, $companyId, $nombre, $roleId)) rol_fallar($con, 409, "Ya existe un rol con ese nombre");

    $stmt = prepare_or_fail($con, 'UPDATE sap_ro00 SET ro_nom = ? WHERE ro_ide = ? AND ro_emp = ?');
    mysqli_stmt_bind_param($stmt, 'sii', $nombre, $roleId, $companyId);
    if (!mysqli_stmt_execute($stmt)) {
        if (mysqli_stmt_errno($stmt) === 1062) rol_fallar($con, 409, "Ya existe un rol con ese nombre");
        rol_fallar($con, 500, mysqli_stmt_error($stmt));
    }

    $stmt = prepare_or_fail($con, 'DELETE FROM sap_rm00 WHERE rm_rol = ?');
    mysqli_stmt_bind_param($stmt, 'i', $roleId);
    if (!mysqli_stmt_execute($stmt)) rol_fallar($con, 500, mysqli_stmt_error($stmt));

    insertar_permisos($con, $roleId, $permIds);
    rol_commit($con);

    send_json(["success" => true]);
}

if ($method === 'DELETE') {
    $roleId = isset($_GET['id']) ? parse_non_negative_int($_GET['id']) : null;
    if (!$roleId) send_json(["success" => false, "error" => "Datos incompletos: id es requerido"], 400);

    mysqli_autocommit($con, false);

    if (!rol_de_empresa($con, $companyId, $roleId, true)) rol_fallar($con, 404, "Rol no encontrado");

    $stmt = prepare_or_fail($con, 'SELECT COUNT(*) AS n FROM sap_us00 WHERE us_rol = ?');
    mysqli_stmt_bind_param($stmt, 'i', $roleId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $usuarios = $res ? intval(mysqli_fetch_assoc($res)['n']) : 0;
    if ($usuarios > 0) rol_fallar($con, 409, "El rol tiene usuarios asignados");

    // its sap_rm00 rows go away with it (ON DELETE CASCADE)
    $stmt = prepare_or_fail($con, 'DELETE FROM sap_ro00 WHERE ro_ide = ? AND ro_emp = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $roleId, $companyId);
    if (!mysqli_stmt_execute($stmt)) {
        // fk_us_rol: a user got the role meanwhile
        if (mysqli_stmt_errno($stmt) === 1451) rol_fallar($con, 409, "El rol tiene usuarios asignados");
        rol_fallar($con, 500, mysqli_stmt_error($stmt));
    }
    rol_commit($con);

    send_json(["success" => true]);
}

send_json(["success" => false, "error" => 'Metodo no permitido'], 405);
