<?php
require_once __DIR__ . '../../Core/lib.php';
require_once __DIR__ . '../../Core/jwt.php';
allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {    
    $data = get_json_input();

    if (!isset($data['nombreUs']) || !isset($data['contrasenaUs'])) {
        send_json(["success" => false, "error" => "Datos incompletos"], 400);
        return;
    }

    $nombre = $data['nombreUs'];
    $pass = $data['contrasenaUs'];

    $query = "SELECT * FROM sap_us00 WHERE us_nom = ?";
    $stmt = prepare_or_fail($con, $query);
    mysqli_stmt_bind_param($stmt, 's', $nombre);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && $usuarioDB = mysqli_fetch_assoc($result)) {
        if (password_verify($pass, $usuarioDB['us_pas'])) {
            // prepare token payload (do not include password)
            // detect role column if present, fall back to 'user'
            $role = 'user';
            foreach (['role','rol','us_rol','us_tipo','is_admin','admin'] as $rk) {
                if (isset($usuarioDB[$rk])) {
                    $role = $usuarioDB[$rk];
                    break;
                }
            }
            if ($role === '1' || $role === 1) $role = 'admin';

            $payload = [
                'us_ide' => intval($usuarioDB['us_ide']),
                'us_nom' => $usuarioDB['us_nom'],
                'role' => $role
            ];
            $token = generate_jwt($payload);

            // remove sensitive fields
            if (isset($usuarioDB['us_pas'])) unset($usuarioDB['us_pas']);

            send_json([
                "success" => true,
                "message" => "Usuario encontrado.",
                "usuario" => $usuarioDB,
                "token" => $token
            ], 200);
        } else {
            send_json(["success" => false, "error" => "Contraseña incorrecta."], 401);
        }
    } else {
        send_json(["success" => false, "error" => "Usuario no encontrado."], 404);
    }
}
if ($method === 'GET') {    
    //$data = get_json_input();

    //if (!$data['nombreUs'] || !$data['contrasenaUs']) send_json(["success" => false, "error" => "Datos incompletos"], 400);

    $nombre = isset($_GET['nombre']) ? $_GET['nombre'] : null;
    $pass = isset($_GET['contraseña']) ? $_GET['contraseña'] : null;

    $query = "SELECT * FROM sap_us00 WHERE us_nom = ?";

    $stmt = prepare_or_fail($con, $query);

    mysqli_stmt_bind_param($stmt, 's', $nombre);

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($result && $usuarioDB = mysqli_fetch_assoc($result)) {
        if (password_verify($pass, $usuarioDB['us_pas'])) {
            // detect role column if present
            $role = 'user';
            foreach (['role','rol','us_rol','us_tipo','is_admin','admin'] as $rk) {
                if (isset($usuarioDB[$rk])) {
                    $role = $usuarioDB[$rk];
                    break;
                }
            }
            if ($role === '1' || $role === 1) $role = 'admin';

            $payload = [
                'us_ide' => intval($usuarioDB['us_ide']),
                'us_nom' => $usuarioDB['us_nom'],
                'role' => $role
            ];
            $token = generate_jwt($payload);

            if (isset($usuarioDB['us_pas'])) unset($usuarioDB['us_pas']);

            send_json([
                "success" => true,
                "message" => "Usuario encontrado.",
                "usuario" => $usuarioDB,
                "token" => $token
            ], 200);
        } else {
            send_json([
                "success" => false,
                "error" => "Contraseña incorrecta."
            ], 401);
        }
    } else {
        send_json([
            "success" => false,
            "error" => "Usuario no encontrado."
        ], 404);
    }
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

