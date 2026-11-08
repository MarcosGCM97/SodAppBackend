<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
allow_cors();

// GET: the logged user and its current permissions, re-read from the database. The app
// calls it when it starts with a saved session, so role changes reach it without a login.
// 401 when the token is invalid or the user was deactivated or deleted.

$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(["success" => false, "error" => 'Metodo no permitido'], 405);
}

send_json([
    "success" => true,
    "us_ide" => $user['us_ide'],
    "us_nom" => $user['us_nom'],
    "es_dueno" => $user['es_dueno'],
    "permisos" => $user['permisos'],
]);
