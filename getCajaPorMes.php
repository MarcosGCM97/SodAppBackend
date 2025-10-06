<?php
include('conexion.php');
ini_set('display_errors', 1);
ini_set('display_startup_error',1);

header('Content-Type: application/json; charset=utf-8');

$ano = date('Y');
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : 0;

if($mes < 10){
    $mes = '0'.$mes;
}

$primerFecha = $ano.'/'.$mes.'/01';
$segundaFecha = $ano.'/'.$mes.'/31';
if($mes <= 0){
    echo json_encode([
        "success" => false,
        "error" => 'Me incorrecto'
    ]);
    exit;
}

//buscar ventas por mes
$query = 'SELECT cl_nom, cl_dir, cl_tel, pr_nom, pr_val, vt_can, vt_fec, vt_ide FROM sap_vt00 as vt
    JOIN sap_cl00 as cl on cl.cl_ide = vt.vt_cli
    JOIN sap_pr00 as pr on pr.pr_nom = vt.vt_pro
    WHERE vt.vt_fec BETWEEN ? AND ?';
$stmt = $con->prepare($query);
if(!$stmt){
    echo json_encode([
        "success" => false,
        "caja" => [ $con->error ]
    ]);
    exit;
}
$stmt->bind_param('ss',$primerFecha, $segundaFecha);
if($stmt->execute()){
    $result = $stmt->get_result();
    if($result->num_rows > 0){
        $caja = [];
        while($row = $result->fetch_assoc()){
            $caja[] = $row;
        }
        echo json_encode([
            "succes"=> true,
            "caja" => $caja
        ]);
    }else{
        echo json_encode([
            "success" => false,
            "caja" => [ "No se encontraron ventas entre las fechas: $primerFecha y $segundaFecha" ]
        ]);
    }
}else {
    echo json_encode([
        "success" => false,
        "caja" => [ $stmt->error ]
    ]);
}

mysqli_close($con);
?>