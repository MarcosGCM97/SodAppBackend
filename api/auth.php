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

// Owner id (sap_us00.us_ide) of the authenticated user, taken only from the
// validated JWT. Sends 401 when the token is missing, invalid or has no us_ide.
function current_user_id() {
    $payload = require_auth();
    $id = isset($payload['us_ide']) ? intval($payload['us_ide']) : 0;
    if ($id <= 0) {
        send_json(["success"=>false,"error"=>"Invalid or expired token"], 401);
    }
    return $id;
}

function current_user() {
    return isset($GLOBALS['current_user']) ? $GLOBALS['current_user'] : null;
}

function require_role($role) {
    $user = require_auth();
    $userRole = isset($user['role']) ? $user['role'] : (isset($user['rol']) ? $user['rol'] : (isset($user['is_admin']) ? ($user['is_admin'] ? 'admin' : 'user') : 'user'));
    if (is_numeric($userRole)) {
        // numeric flag treat >0 as admin
        $userRole = intval($userRole) > 0 ? 'admin' : 'user';
    }
    if ($userRole !== $role) {
        send_json(["success"=>false,"error"=>"Forbidden: insufficient role"], 403);
    }
    return true;
}

?>
