<?php 
include 'conexion.php';

$qr = 'SELECT cl_nom, cl_dir, cl_lun, cl_mar, cl_mie, cl_jue, cl_vie, cl_sab, cl_dom FROM sap_cl00';
$result = mysqli_query($con, $qr);

if(!$result){
    die("Error en la consulta: " . mysqli_error($con));
}

while($row = mysqli_fetch_assoc($result)){
    $diasEntrega = array();
    
    if ($row['cl_lun'] == 1) $diasEntrega[] = 'Lunes';
    if ($row['cl_mar'] == 1) $diasEntrega[] = 'Martes';
    if ($row['cl_mie'] == 1) $diasEntrega[] = 'Miércoles';
    if ($row['cl_jue'] == 1) $diasEntrega[] = 'Jueves';
    if ($row['cl_vie'] == 1) $diasEntrega[] = 'Viernes';
    if ($row['cl_sab'] == 1) $diasEntrega[] = 'Sábado';
    if ($row['cl_dom'] == 1) $diasEntrega[] = 'Domingo';
    

    $cliente = array(
        "nombre" => $row['cl_nom'],
        "direccion" => $row['cl_dir'],
        "diasEntrega" => $diasEntrega
    );

    $clientes[] = $cliente;
}


$response = array(
    "success" => true,
    "clientes" => $clientes
);

header('Content-Type: application/json');
echo json_encode($response);

mysqli_free_result($result);
mysqli_close($con)
?>