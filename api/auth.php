<?php
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/lib.php';

function require_auth() {
    $token = get_bearer_token();
    if (!$token) {
        send_json(["success"=>false,"error"=>"Missing Authorization token"], 401);
    }

    $payload = verify_jwt($token);
    if (!$payload) {
        send_json(["success"=>false,"error"=>"Invalid or expired token"], 401);
    }

    // attach to global for downstream use
    $GLOBALS['current_user'] = $payload;
    return $payload;
}

// Permission codes of a sap_us00 row: the whole catalog for the owner (us_emp = us_ide),
// otherwise the codes of its role within the same company. Ordered by pm_ide.
function user_permissions($con, $user) {
    if (intval($user['us_emp']) === intval($user['us_ide'])) {
        $res = mysqli_query($con, 'SELECT pm_cod FROM sap_pm00 ORDER BY pm_ide');
        if (!$res) send_json(["success"=>false,"error"=>mysqli_error($con)], 500);
        $codes = [];
        while ($r = mysqli_fetch_assoc($res)) $codes[] = $r['pm_cod'];
        return $codes;
    }

    if ($user['us_rol'] === null) return [];

    $stmt = prepare_or_fail($con, 'SELECT p.pm_cod FROM sap_rm00 m
        JOIN sap_ro00 r ON r.ro_ide = m.rm_rol
        JOIN sap_pm00 p ON p.pm_ide = m.rm_per
        WHERE m.rm_rol = ? AND r.ro_emp = ? ORDER BY p.pm_ide');
    $rol = intval($user['us_rol']);
    $emp = intval($user['us_emp']);
    mysqli_stmt_bind_param($stmt, 'ii', $rol, $emp);
    $res = execute_or_fail($con, $stmt);
    $codes = [];
    while ($r = mysqli_fetch_assoc($res)) $codes[] = $r['pm_cod'];
    return $codes;
}

// The authenticated user, re-read from sap_us00 on every request (cached for the rest of
// it), so a deactivation or a role change applies to tokens issued before it.
// Returns ['us_ide','us_nom','us_emp','us_rol','es_dueno','permisos']. Sends 401 when the
// token is missing or invalid, or the user no longer exists or is inactive.
function auth_user() {
    static $user = null;
    if ($user !== null) return $user;

    $payload = require_auth();
    $id = isset($payload['us_ide']) ? intval($payload['us_ide']) : 0;
    if ($id <= 0) {
        send_json(["success"=>false,"error"=>"Invalid or expired token"], 401);
    }

    $con = $GLOBALS['con'];
    $stmt = prepare_or_fail($con, 'SELECT us_ide, us_nom, us_emp, us_rol, us_act FROM sap_us00 WHERE us_ide = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    $row = mysqli_fetch_assoc(execute_or_fail($con, $stmt));
    if (!$row || intval($row['us_act']) !== 1) {
        send_json(["success"=>false,"error"=>"Invalid or expired token"], 401);
    }

    $user = [
        'us_ide' => intval($row['us_ide']),
        'us_nom' => $row['us_nom'],
        'us_emp' => intval($row['us_emp']),
        'us_rol' => $row['us_rol'] === null ? null : intval($row['us_rol']),
        'es_dueno' => intval($row['us_emp']) === intval($row['us_ide']),
        'permisos' => user_permissions($con, $row),
    ];
    return $user;
}

// Id (sap_us00.us_ide) of the logged user, taken from the validated JWT and checked
// against the database. Sends 401 when the token is invalid or the user is inactive.
// Data rows are scoped by current_company_id(), not by this id.
function current_user_id() {
    $user = auth_user();
    return $user['us_ide'];
}

// Company of the logged user (sap_us00.us_emp = the owner's us_ide): the owner id stored in
// every data row (cl_emp, pr_emp, vt_emp, rp_emp, ...) and the scope of every data query.
// This is the single place where a future per-vendedor client assignment would narrow
// what a non-owner user sees.
function current_company_id() {
    $user = auth_user();
    return $user['us_emp'];
}

// True when the logged user is the owner of its company (us_emp = us_ide)
function is_owner() {
    $user = auth_user();
    return $user['es_dueno'];
}

// Requires one permission code, or any of a list of codes. The owner has every permission.
// Otherwise sends 403 naming the (first) required code.
function require_permission($codes) {
    $codes = is_array($codes) ? $codes : [$codes];
    $user = auth_user();
    if ($user['es_dueno']) return true;
    foreach ($codes as $code) {
        if (in_array($code, $user['permisos'], true)) return true;
    }
    send_json(["success"=>false,"error"=>"Forbidden: missing permission","permiso"=>$codes[0]], 403);
}

// User and role administration: only the owner, never a grantable permission
function require_owner() {
    if (!is_owner()) {
        send_json(["success"=>false,"error"=>"Forbidden: missing permission","permiso"=>"admin"], 403);
    }
    return true;
}

function current_user() {
    return isset($GLOBALS['current_user']) ? $GLOBALS['current_user'] : null;
}

?>
