<?php
// Helper functions for the new REST API
// Compatible with older PHP versions (5.6+)

// load DB connection from project
require_once __DIR__ . '/conexion.php';

// Endpoints check mysqli return values / errno (e.g. 1062 -> 409); PHP 8.1+
// throws exceptions by default instead, so keep the classic reporting mode.
mysqli_report(MYSQLI_REPORT_OFF);

$db = new Database();

$con = $db->connect();

function allow_cors() {

    header('Access-Control-Allow-Origin: *');

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

        http_response_code(200);

        exit;
    }
}

function send_json($data, $status = 200) {

    header('Content-Type: application/json; charset=utf-8');

    http_response_code($status);

    echo json_encode($data);

    exit;
}

function get_json_input() {

    $raw = file_get_contents('php://input');

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {

        send_json(["success" => false, "error" => 'Error al decodificar JSON: ' . json_last_error_msg()], 400);

    }

    return $data;
}

// Small helper to prepare statements and return error on failure
function prepare_or_fail($con, $query) {

    $stmt = mysqli_prepare($con, $query);

    if ($stmt === false) {

        send_json(["success" => false, "error" => mysqli_error($con)], 500);

    }
    return $stmt;
}

// Integer >= 0 from JSON or a query param (int or digit string); null when invalid (negative, decimal, text)
function parse_non_negative_int($value) {
    if (is_int($value)) return $value >= 0 ? $value : null;

    if (is_string($value) && ctype_digit($value)) return intval($value);

    return null;
}

// Boolean flag as 0/1 from JSON (true/false, 0/1) or a query param ("0"/"1"); null when invalid
function parse_flag($value) {
    if (is_bool($value)) return $value ? 1 : 0;

    if ($value === 0 || $value === 1 || $value === '0' || $value === '1') return intval($value);

    return null;
}

?>
