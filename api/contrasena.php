<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Own password change of the logged owner (the app offers it from the company data screen).
// The owner row cannot be changed from usuarios.php, so this is the only way to change it.
//
// PUT only the owner (anyone else gets 403 "permiso":"admin"), {"actual","nueva"}:
//     replaces the password of the logged user after checking the current one.
//     400 "Datos incompletos: actual y nueva" when either is missing or not a string,
//     400 "La contraseña actual es incorrecta" (never 401: the app ends the session on 401),
//     400 when nueva has fewer than USUARIO_CONTRASENA_MIN characters (same rule as usuarios.php).
//     Tokens issued before the change stay valid until they expire.

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'PUT') {
    require_owner();
    $userId = current_user_id();

    $data = get_json_input();
    $actual = is_array($data) && isset($data['actual']) ? $data['actual'] : null;
    $nueva = is_array($data) && isset($data['nueva']) ? $data['nueva'] : null;
    if (!is_string($actual) || !is_string($nueva)) {
        send_json(["success" => false, "error" => "Datos incompletos: actual y nueva"], 400);
    }

    $stmt = prepare_or_fail($con, 'SELECT us_pas FROM sap_us00 WHERE us_ide = ?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    $row = mysqli_fetch_assoc(execute_or_fail($con, $stmt));
    if (!$row || !password_verify($actual, $row['us_pas'])) {
        send_json(["success" => false, "error" => "La contraseña actual es incorrecta"], 400);
    }

    if (!valid_password($nueva)) {
        send_json(["success" => false, "error" => "La contraseña nueva debe tener al menos " . USUARIO_CONTRASENA_MIN . " caracteres"], 400);
    }

    $hash = password_hash($nueva, PASSWORD_DEFAULT);
    $stmt = prepare_or_fail($con, 'UPDATE sap_us00 SET us_pas = ? WHERE us_ide = ?');
    mysqli_stmt_bind_param($stmt, 'si', $hash, $userId);
    if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

    send_json(["success" => true]);
}

send_json(["success" => false, "error" => 'Metodo no permitido'], 405);
