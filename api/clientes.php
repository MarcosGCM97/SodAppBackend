<?php
require_once __DIR__ . '/lib.php';

allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

// GET /api/clientes.php or /api/clientes.php?id=1
if ($method === 'GET') {

    if (isset($_GET['id']) && intval($_GET['id']) > 0) {

        $id = intval($_GET['id']);

        $stmt = prepare_or_fail($con, 'SELECT cl_ide, cl_nom, cl_dir, cl_tel, cl_deb, cl_emp FROM sap_cl00 WHERE cl_ide = ?');

        mysqli_stmt_bind_param($stmt, 'i', $id);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);

        $row = mysqli_fetch_assoc($res);

        if ($row) send_json(["success" => true, "cliente" => $row]);

        send_json(["success" => false, "error" => "Cliente no encontrado"], 404);
    } else {
        $res = mysqli_query($con, 'SELECT cl_ide, cl_nom, cl_dir, cl_tel, cl_deb, cl_emp FROM sap_cl00 ORDER BY cl_nom');

        if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
        
        send_json(["success" => true, "clientes" => $arr]);
    }
}

// POST: crear cliente. JSON: {"nombreCl":"...","numTelCl":"...","direccionCl":"..."}
if ($method === 'POST') {
    $data = get_json_input();

    $nombre = isset($data['nombreCl']) ? $data['nombreCl'] : (isset($data['name']) ? $data['name'] : null);

    $tel = isset($data['numTelCl']) ? $data['numTelCl'] : (isset($data['tel']) ? $data['tel'] : null);

    $dir = isset($data['direccionCl']) ? $data['direccionCl'] : (isset($data['address']) ? $data['address'] : null);

    if (!$nombre || !$tel || !$dir) send_json(["success" => false, "error" => "Datos incompletos: nombre, telefono y direccion"], 400);

    $stmt = prepare_or_fail($con, 'INSERT INTO sap_cl00 SET cl_nom = ?, cl_tel = ?, cl_dir = ?, cl_emp = "Ariza"');

    mysqli_stmt_bind_param($stmt, 'sss', $nombre, $tel, $dir);

    if (mysqli_stmt_execute($stmt)) {
        send_json(["success" => true, "message" => "Cliente creado", "id" => mysqli_insert_id($con)], 201);
    }

    send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
}

// PUT: actualizar cliente (body JSON with id and fields)
if ($method === 'PUT') {
    $data = get_json_input();

    $id = isset($data['id']) ? intval($data['id']) : 0;

    if ($id <= 0) send_json(["success" => false, "error" => "ID inválido"], 400);

    $nombre = isset($data['nombreCl']) ? $data['nombreCl'] : (isset($data['name']) ? $data['name'] : null);

    $tel = isset($data['numTelCl']) ? $data['numTelCl'] : (isset($data['tel']) ? $data['tel'] : null);

    $dir = isset($data['direccionCl']) ? $data['direccionCl'] : (isset($data['address']) ? $data['address'] : null);

    if (!$nombre || !$tel || !$dir) send_json(["success" => false, "error" => "Datos incompletos"], 400);

    $stmt = prepare_or_fail($con, 'UPDATE sap_cl00 SET cl_nom = ?, cl_tel = ?, cl_dir = ? WHERE cl_ide = ?');

    mysqli_stmt_bind_param($stmt, 'sssi', $nombre, $tel, $dir, $id);

    if (mysqli_stmt_execute($stmt)) {
        send_json(["success" => true, "message" => "Cliente actualizado"]);
    }

    send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
}

// DELETE: /api/clients.php?id=1
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($id <= 0) send_json(["success" => false, "error" => "ID inválido"], 400);

    $stmt = prepare_or_fail($con, 'DELETE FROM sap_cl00 WHERE cl_ide = ?');

    mysqli_stmt_bind_param($stmt, 'i', $id);

    if (mysqli_stmt_execute($stmt)) {
        if ($stmt->affected_rows > 0) send_json(["success" => true, "message" => "Cliente eliminado"]);

        send_json(["success" => false, "error" => "No se encontró el cliente"], 404);
    }
    send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
}

// If method not matched
send_json(["success" => false, "error" => "Método no soportado"], 405);

?>
