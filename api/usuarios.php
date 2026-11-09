<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Users of the company (admin API, owner only; anyone else gets 403 "permiso":"admin").
//
// GET                                      every user of the company, the owner included.
// POST {"nombre","contrasena","rol"}       creates an active user of the company with that role.
//                                          400 invalid data, 404 role not in the company,
//                                          409 name already taken (names are global: login is by name).
// PUT  {"id","rol"?,"contrasena"?,"activo"?} changes a user of the company. 404 user not in the
//                                          company, 400 when the target is the owner (its row
//                                          cannot be changed here) or the data is invalid.

const USUARIO_NOMBRE_MIN = 3;
const USUARIO_NOMBRE_MAX = 100;
const USUARIO_CONTRASENA_MIN = 6;

require_owner();
$companyId = current_company_id();

$method = $_SERVER['REQUEST_METHOD'];

function text_length($value) {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function valid_password($value) {
    return is_string($value) && text_length($value) >= USUARIO_CONTRASENA_MIN;
}

// Positive integer from JSON (int or digit string), or null
function parse_id($value) {
    $id = parse_non_negative_int($value);
    return $id ? $id : null;
}

// True when the role belongs to the company
function role_in_company($con, $companyId, $roleId) {
    $stmt = prepare_or_fail($con, 'SELECT 1 FROM sap_ro00 WHERE ro_ide = ? AND ro_emp = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $roleId, $companyId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    return $res && mysqli_num_rows($res) > 0;
}

if ($method === 'GET') {
    $stmt = prepare_or_fail($con, 'SELECT u.us_ide, u.us_nom, u.us_rol, r.ro_nom, u.us_act, u.us_emp
        FROM sap_us00 u LEFT JOIN sap_ro00 r ON r.ro_ide = u.us_rol
        WHERE u.us_emp = ? ORDER BY (u.us_ide = u.us_emp) DESC, u.us_nom');
    mysqli_stmt_bind_param($stmt, 'i', $companyId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

    $usuarios = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $usuarios[] = [
            'us_ide' => intval($r['us_ide']),
            'us_nom' => $r['us_nom'],
            'us_rol' => $r['us_rol'] === null ? null : intval($r['us_rol']),
            'ro_nom' => $r['ro_nom'],
            'us_act' => intval($r['us_act']),
            'es_dueno' => intval($r['us_ide']) === intval($r['us_emp']),
        ];
    }
    send_json(["success" => true, "usuarios" => $usuarios]);
}

if ($method === 'POST') {
    $data = get_json_input();

    $nombre = isset($data['nombre']) && is_string($data['nombre']) ? trim($data['nombre']) : null;
    if ($nombre === null || text_length($nombre) < USUARIO_NOMBRE_MIN || text_length($nombre) > USUARIO_NOMBRE_MAX) {
        send_json(["success" => false, "error" => "Nombre inválido: debe tener entre 3 y 100 caracteres"], 400);
    }

    $contrasena = isset($data['contrasena']) ? $data['contrasena'] : null;
    if (!valid_password($contrasena)) {
        send_json(["success" => false, "error" => "Contraseña inválida: debe tener al menos 6 caracteres"], 400);
    }

    $rol = isset($data['rol']) ? parse_id($data['rol']) : null;
    if ($rol === null) send_json(["success" => false, "error" => "Rol inválido"], 400);

    if (!role_in_company($con, $companyId, $rol)) send_json(["success" => false, "error" => "Rol no encontrado"], 404);

    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_us00 WHERE us_nom = ?');
    mysqli_stmt_bind_param($check, 's', $nombre);
    mysqli_stmt_execute($check);
    $resCheck = mysqli_stmt_get_result($check);
    if ($resCheck && mysqli_num_rows($resCheck) > 0) {
        send_json(["success" => false, "error" => "Ya existe un usuario con ese nombre"], 409);
    }

    $hash = password_hash($contrasena, PASSWORD_DEFAULT);
    $stmt = prepare_or_fail($con, 'INSERT INTO sap_us00 (us_nom, us_pas, us_emp, us_rol, us_act) VALUES (?, ?, ?, ?, 1)');
    mysqli_stmt_bind_param($stmt, 'ssii', $nombre, $hash, $companyId, $rol);
    if (!mysqli_stmt_execute($stmt)) {
        // uq_us_nom: a concurrent request took the name
        if (mysqli_stmt_errno($stmt) === 1062) {
            send_json(["success" => false, "error" => "Ya existe un usuario con ese nombre"], 409);
        }
        send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
    }

    send_json(["success" => true, "us_ide" => mysqli_insert_id($con)], 201);
}

if ($method === 'PUT') {
    $data = get_json_input();

    $id = isset($data['id']) ? parse_id($data['id']) : null;
    if ($id === null) send_json(["success" => false, "error" => "Datos incompletos: id es requerido"], 400);

    $stmt = prepare_or_fail($con, 'SELECT us_ide, us_emp FROM sap_us00 WHERE us_ide = ? AND us_emp = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $companyId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $target = $res ? mysqli_fetch_assoc($res) : null;
    if (!$target) send_json(["success" => false, "error" => "Usuario no encontrado"], 404);

    if (intval($target['us_ide']) === intval($target['us_emp'])) {
        send_json(["success" => false, "error" => "El usuario dueño no se puede modificar"], 400);
    }

    $sets = [];
    $types = '';
    $params = [];

    if (array_key_exists('rol', $data)) {
        $rol = parse_id($data['rol']);
        if ($rol === null) send_json(["success" => false, "error" => "Rol inválido"], 400);
        $sets[] = 'us_rol = ?';
        $types .= 'i';
        $params[] = $rol;
    }

    if (array_key_exists('contrasena', $data)) {
        if (!valid_password($data['contrasena'])) {
            send_json(["success" => false, "error" => "Contraseña inválida: debe tener al menos 6 caracteres"], 400);
        }
        $sets[] = 'us_pas = ?';
        $types .= 's';
        $params[] = password_hash($data['contrasena'], PASSWORD_DEFAULT);
    }

    if (array_key_exists('activo', $data)) {
        $activo = parse_flag($data['activo']);
        if ($activo === null) send_json(["success" => false, "error" => "Activo inválido: debe ser 0 o 1"], 400);
        $sets[] = 'us_act = ?';
        $types .= 'i';
        $params[] = $activo;
    }

    if (!$sets) send_json(["success" => false, "error" => "Datos incompletos: rol, contrasena o activo"], 400);

    if (isset($rol) && !role_in_company($con, $companyId, $rol)) {
        send_json(["success" => false, "error" => "Rol no encontrado"], 404);
    }

    $types .= 'ii';
    $params[] = $id;
    $params[] = $companyId;
    $stmt = prepare_or_fail($con, 'UPDATE sap_us00 SET ' . implode(', ', $sets) . ' WHERE us_ide = ? AND us_emp = ?');
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

    send_json(["success" => true]);
}

send_json(["success" => false, "error" => 'Metodo no permitido'], 405);
