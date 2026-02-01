<?php
require_once __DIR__ . '/lib.php';

allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

// GET /api/days.php or /api/days.php?id=1
if ($method === 'GET') {

    if (isset($_GET['id']) && intval($_GET['id']) > 0) {

        $id = intval($_GET['id']);

        $stmt = prepare_or_fail($con, 'SELECT cl_nom, cl_dir, cl_lun, cl_mar, cl_mie, cl_jue, cl_vie, cl_sab, cl_dom FROM sap_cl00 WHERE cl_ide = ?');

        mysqli_stmt_bind_param($stmt, 'i', $id);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);

        $diasEntrega = array();

        if ($row = mysqli_fetch_assoc($res)) {
            if ($row['cl_lun']) $diasEntrega[] = 'Lunes';
            if ($row['cl_mar']) $diasEntrega[] = 'Martes';
            if ($row['cl_mie']) $diasEntrega[] = 'Miércoles';
            if ($row['cl_jue']) $diasEntrega[] = 'Jueves';
            if ($row['cl_vie']) $diasEntrega[] = 'Viernes';
            if ($row['cl_sab']) $diasEntrega[] = 'Sábado';
            if ($row['cl_dom']) $diasEntrega[] = 'Domingo';
        }

        if ($row) send_json(["success" => true, "diasEntrega" => $diasEntrega]);

        send_json(["success" => false, "error" => "Day not found"], 404);
    } else {
        $res = mysqli_query($con, 'SELECT cl_ide, cl_nom, cl_dir, cl_lun, cl_mar, cl_mie, cl_jue, cl_vie, cl_sab, cl_dom FROM sap_cl00');

        if (!$res) send_json(["success" => false, "error" => mysqli_error($con)], 500);

        $clientes = [];

        while ($r = mysqli_fetch_assoc($res)) {

            $diasEntrega = array();
    
            if ($r['cl_lun'] == 1) $diasEntrega[] = 'Lunes';
            if ($r['cl_mar'] == 1) $diasEntrega[] = 'Martes';
            if ($r['cl_mie'] == 1) $diasEntrega[] = 'Miércoles';
            if ($r['cl_jue'] == 1) $diasEntrega[] = 'Jueves';
            if ($r['cl_vie'] == 1) $diasEntrega[] = 'Viernes';
            if ($r['cl_sab'] == 1) $diasEntrega[] = 'Sábado';
            if ($r['cl_dom'] == 1) $diasEntrega[] = 'Domingo';
            
            $cliente = array(
                "nombre" => $r['cl_nom'],
                "direccion" => $r['cl_dir'],
                "diasEntrega" => $diasEntrega
            );

            
            $clientes[] = $cliente;
        }

        send_json(["success" => true, "days" => $clientes]);
    }

    if($method === 'PUT') {
        $data = get_json_input();
        
        $id = isset($data['cl_ide']) ? intval($data['cl_ide']) : 0;

        if($id <= 0) {
            send_json(["success" => false, "error" => "Invalid or missing cl_ide"], 400);
        }

        $fields = ['cl_lun', 'cl_mar', 'cl_mie', 'cl_jue', 'cl_vie', 'cl_sab'];

        $stmt = prepare_or_fail($con, 'UPDATE sap_cl00 SET cl_lun = ?, cl_mar = ?, cl_mie = ?, cl_jue = ?, cl_vie = ?, cl_sab = ? WHERE cl_ide = ?');

        // PHP (pre-7.x) compatible checks. Use isset to provide defaults (0) when fields are missing.
        $lunes = isset($data['cl_lun']) ? (int)$data['cl_lun'] : 0;
        $martes = isset($data['cl_mar']) ? (int)$data['cl_mar'] : 0;
        $miercoles = isset($data['cl_mie']) ? (int)$data['cl_mie'] : 0;
        $jueves = isset($data['cl_jue']) ? (int)$data['cl_jue'] : 0;
        $viernes = isset($data['cl_vie']) ? (int)$data['cl_vie'] : 0;
        $sabado = isset($data['cl_sab']) ? (int)$data['cl_sab'] : 0;
        $domingo = isset($data['cl_dom']) ? (int)$data['cl_dom'] : 0;

        // Bind the computed variables (6 day flags + id)
        mysqli_stmt_bind_param($stmt, 'iiiiiii', 
            $lunes, 
            $martes, 
            $miercoles, 
            $jueves, 
            $viernes, 
            $sabado, 
            $domingo,
            $id
        );

        if (mysqli_stmt_execute($stmt)) {
            send_json(["success" => true, "message" => "Day updated"], 200);
        }

        send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
    }
}