<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/auth.php';
allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

// Checks the credentials and sends the login response: the user row (us_ide, us_nom, us_emp,
// us_rol, us_act; never us_pas), the token, es_dueno and the permission codes (the whole
// catalog for the owner). Unknown name -> 404, wrong password or inactive user -> 401.
function login_response($con, $nombre, $pass) {
    $stmt = prepare_or_fail($con, 'SELECT us_ide, us_nom, us_pas, us_emp, us_rol, us_act FROM sap_us00 WHERE us_nom = ?');
    mysqli_stmt_bind_param($stmt, 's', $nombre);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $usuarioDB = $result ? mysqli_fetch_assoc($result) : null;

    if (!$usuarioDB) {
        send_json(["success" => false, "error" => "Usuario no encontrado."], 404);
    }
    if (!is_string($pass) || !password_verify($pass, $usuarioDB['us_pas'])) {
        send_json(["success" => false, "error" => "Contraseña incorrecta."], 401);
    }
    if (intval($usuarioDB['us_act']) !== 1) {
        send_json(["success" => false, "error" => "Usuario inactivo."], 401);
    }

    // The token carries only the id: company, role and permissions are re-read on every request
    $token = generate_jwt([
        'us_ide' => intval($usuarioDB['us_ide']),
        'us_nom' => $usuarioDB['us_nom'],
    ]);

    $usuario = [
        'us_ide' => intval($usuarioDB['us_ide']),
        'us_nom' => $usuarioDB['us_nom'],
        'us_emp' => intval($usuarioDB['us_emp']),
        'us_rol' => $usuarioDB['us_rol'] === null ? null : intval($usuarioDB['us_rol']),
        'us_act' => intval($usuarioDB['us_act']),
    ];

    send_json([
        "success" => true,
        "message" => "Usuario encontrado.",
        "usuario" => $usuario,
        "token" => $token,
        "es_dueno" => $usuario['us_emp'] === $usuario['us_ide'],
        "permisos" => user_permissions($con, $usuario),
    ], 200);
}

if ($method === 'POST') {
    $data = get_json_input();

    if (!isset($data['nombreUs']) || !isset($data['contrasenaUs'])) {
        send_json(["success" => false, "error" => "Datos incompletos"], 400);
    }

    login_response($con, $data['nombreUs'], $data['contrasenaUs']);
}

// Legacy login with the credentials in the query string (?nombre=&contraseña=): same rules
// and response as POST. Kept for old app versions; the current app uses POST.
if ($method === 'GET') {
    $nombre = isset($_GET['nombre']) ? $_GET['nombre'] : null;
    $pass = isset($_GET['contraseña']) ? $_GET['contraseña'] : null;

    login_response($con, $nombre, $pass);
}
/*if ($method === "GET") {
    $nombre = isset($_GET['nombre']) ? $_GET['nombre'] : null;
    $pass = isset($_GET['contraseña']) ? $_GET['contraseña'] : null;

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    //echo $hash;

    if (password_verify($pass, '$2y$10$o2rdKW.EIxXbHf05lsOU3.8BiFULCRKehkJQ.emHkW749ixf8DpmS')) {
        echo "Contraseña válida.";
    } else {
        echo "Contraseña inválida.";
    }
}
if($method === 'POST'){
    $data = get_json_input();

    if (!$nombre || !$pass) send_json(["success" => false, "error" => "Datos incompletos"], 400);

    $nombre = isset($data['nombreUs']) ? $data['nombreUs'] : null;

    $pass = isset($data['contraseñaUs']) ? $data['contraseñaUs'] : null;

    // check exists
    $check = prepare_or_fail($con, 'SELECT 1 FROM sap_us00 WHERE us_nom = ?');

    mysqli_stmt_bind_param($check, 's', $nombre);

    mysqli_stmt_execute($check);

    $resCheck = mysqli_stmt_get_result($check);

    if ($resCheck && mysqli_num_rows($resCheck) > 0) send_json(["success"=>false,"error"=>"Ya existe usuario"],400);

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_us00 SET us_nom = ?, us_pas = ?');
    mysqli_stmt_bind_param($stmt, 'ss', $nombre, $pass);
    if (mysqli_stmt_execute($stmt)) send_json(["success"=>true,"message"=>"Usuario creado","id"=>mysqli_insert_id($con)],201);
    send_json(["success"=>false,"error"=>mysqli_stmt_error($stmt)],500);
}*/

