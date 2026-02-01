<?php 
require_once 'lib.php';

allow_cors();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === "GET") {
    $ano = date('Y');
    $mes = isset($_GET['mes']) ? intval($_GET['mes']) : 0;

    if($mes < 10){
        $mes = '0'.$mes;
    }

    $primerFecha = $ano.'/'.$mes.'/01';
    $segundaFecha = $ano.'/'.$mes.'/31';

    if($mes <= 0){
        send_json([
            "success" => false,
            "error" => 'Mes incorrecto'
        ]);
    }

    //buscar ventas por mes
    $query = 'SELECT cl_nom, cl_dir, cl_tel, pr_nom, pr_val, vt_can, vt_fec, vt_ide FROM sap_vt00 as vt
        JOIN sap_cl00 as cl on cl.cl_ide = vt.vt_cli
        JOIN sap_pr00 as pr on pr.pr_nom = vt.vt_pro
        WHERE vt.vt_fec BETWEEN ? AND ?';

    $stmt = prepare_or_fail($con, $query);

    mysqli_stmt_bind_param($stmt, 'ss',$primerFecha, $segundaFecha);

    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if ($result->num_rows > 0) {
            $caja = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $caja[] = $row;
            }
            send_json([
                "success"=> true,
                "caja" => $caja
            ]);
        } else {
            send_json([
                "success" => false,
                "error" => "No se encontraron ventas entre las fechas: $primerFecha y $segundaFecha"
            ]);
        }
    } else {
        send_json([
            "success" => false,
            "error" => mysqli_stmt_error($stmt)
        ]);
    }
}
