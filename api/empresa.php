<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Empresa (datos-empresa): the real name, phone and address of the company (sap_em00, one
// row per company keyed by the owner id). The app signs WhatsApp messages with the name.
//
// GET  any user of the company: {"empresa": {"em_nom","em_tel","em_dir"} | null}
// PUT  only the owner, {"nombre","telefono"?,"direccion"?}: creates or replaces the data.
//      nombre 1..80 characters; telefono up to 30, only digits, spaces, "+" and "-";
//      direccion up to 150. A blank or missing telefono/direccion is stored as null.

// Every method requires a valid token; the company (owner id) always comes from it
$companyId = current_company_id();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    send_json(["success" => true, "empresa" => empresa_datos($con, $companyId)]);
}

if ($method === 'PUT') {
    require_owner();
    $data = get_json_input();
    if (!is_array($data)) send_json(["success" => false, "error" => "Datos incompletos: nombre"], 400);

    $nombre = parse_texto_opcional(isset($data['nombre']) ? $data['nombre'] : null, 80);
    if ($nombre === null) send_json(["success" => false, "error" => "Datos incompletos: nombre"], 400);
    if ($nombre === false) send_json(["success" => false, "error" => "Nombre inválido: hasta 80 caracteres"], 400);

    $telefono = parse_texto_opcional(isset($data['telefono']) ? $data['telefono'] : null, 30);
    if ($telefono === false || ($telefono !== null && !preg_match('/^[0-9+\- ]+$/', $telefono))) {
        send_json(["success" => false, "error" => "Teléfono inválido: hasta 30 caracteres, solo números, espacios, + y -"], 400);
    }

    $direccion = parse_texto_opcional(isset($data['direccion']) ? $data['direccion'] : null, 150);
    if ($direccion === false) send_json(["success" => false, "error" => "Dirección inválida: hasta 150 caracteres"], 400);

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_em00 (em_emp, em_nom, em_tel, em_dir) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE em_nom = VALUES(em_nom), em_tel = VALUES(em_tel), em_dir = VALUES(em_dir)');
    mysqli_stmt_bind_param($stmt, 'isss', $companyId, $nombre, $telefono, $direccion);
    if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

    send_json(["success" => true, "empresa" => empresa_datos($con, $companyId)]);
}

send_json(["success" => false, "error" => 'Metodo no permitido'], 405);
