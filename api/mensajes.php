<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// Plantillas de mensajes (plantillas-mensajes): the owner's own text for the WhatsApp
// messages the app sends to clients (sap_msj00, one row per company and type). The app
// replaces the variables when it sends a message and uses its default text for every
// type without a template.
//
// GET    any user of the company: {"plantillas": {"<tipo>": "<texto>", ...}} (only saved types)
// PUT    only the owner, {"tipo","texto"}: creates or replaces the template of that type.
//        texto 1..1000 characters (trimmed); every {variable} must be allowed for the type.
// DELETE only the owner, ?tipo=<tipo>: removes the template, so the app goes back to its
//        default text. Removing a missing template is not an error.
// PUT and DELETE answer with every template of the company, like GET.

// Allowed variables per message type, in the order the error message lists them
const MENSAJES_VARIABLES = [
    'deuda' => ['cliente', 'saldo', 'empresa'],
    'envases' => ['cliente', 'envases', 'empresa'],
    'venta' => ['cliente', 'productos', 'total', 'saldo', 'empresa'],
];

// Every method requires a valid token; the company (owner id) always comes from it
$companyId = current_company_id();

$method = $_SERVER['REQUEST_METHOD'];

function plantillas_empresa($con, $companyId) {
    $stmt = prepare_or_fail($con, 'SELECT ms_tip, ms_txt FROM sap_msj00 WHERE ms_emp = ? ORDER BY ms_tip');
    mysqli_stmt_bind_param($stmt, 'i', $companyId);
    if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
    $res = mysqli_stmt_get_result($stmt);
    $plantillas = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) $plantillas[$row['ms_tip']] = $row['ms_txt'];
    // An object even when empty, so the app always reads a map
    return (object) $plantillas;
}

function tipo_valido($tipo) {
    if (!is_string($tipo) || !array_key_exists($tipo, MENSAJES_VARIABLES)) {
        send_json(["success" => false, "error" => "Tipo inválido: deuda, envases o venta"], 400);
    }
    return $tipo;
}

if ($method === 'GET') {
    send_json(["success" => true, "plantillas" => plantillas_empresa($con, $companyId)]);
}

if ($method === 'PUT') {
    require_owner();
    $data = get_json_input();
    if (!is_array($data)) send_json(["success" => false, "error" => "Datos incompletos: tipo y texto"], 400);

    $tipo = tipo_valido(isset($data['tipo']) ? $data['tipo'] : null);

    $texto = parse_texto_opcional(isset($data['texto']) ? $data['texto'] : null, 1000);
    if ($texto === null) send_json(["success" => false, "error" => "Datos incompletos: texto"], 400);
    if ($texto === false) send_json(["success" => false, "error" => "Texto inválido: hasta 1000 caracteres"], 400);

    // A {word} is a variable; braces around anything else are plain text
    $permitidas = MENSAJES_VARIABLES[$tipo];
    preg_match_all('/\{([A-Za-z_]+)\}/', $texto, $variables);
    foreach ($variables[1] as $variable) {
        if (!in_array($variable, $permitidas, true)) {
            $lista = implode(', ', array_map(function ($v) { return '{' . $v . '}'; }, $permitidas));
            send_json(["success" => false, "error" => "Variable no permitida en $tipo: {" . $variable . "}. Permitidas: $lista"], 400);
        }
    }

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_msj00 (ms_emp, ms_tip, ms_txt) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE ms_txt = VALUES(ms_txt)');
    mysqli_stmt_bind_param($stmt, 'iss', $companyId, $tipo, $texto);
    if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

    send_json(["success" => true, "plantillas" => plantillas_empresa($con, $companyId)]);
}

if ($method === 'DELETE') {
    require_owner();
    $tipo = tipo_valido(isset($_GET['tipo']) ? $_GET['tipo'] : null);

    $stmt = prepare_or_fail($con, 'DELETE FROM sap_msj00 WHERE ms_emp = ? AND ms_tip = ?');
    mysqli_stmt_bind_param($stmt, 'is', $companyId, $tipo);
    if (!mysqli_stmt_execute($stmt)) send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);

    send_json(["success" => true, "plantillas" => plantillas_empresa($con, $companyId)]);
}

send_json(["success" => false, "error" => 'Metodo no permitido'], 405);
