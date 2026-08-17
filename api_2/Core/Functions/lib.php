<?php
// Helper functions for the new REST API
// Compatible with older PHP versions (5.6+)

// load DB connection from project
require_once __DIR__ . '/conexion.php';

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

?>
