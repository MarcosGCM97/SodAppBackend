<?php 
include 'conexion.php';

$data = json_decode(file_get_contents('php://input'), true);
if(json_last_error() !== JSON_ERROR_NONE){
    die('Error al decodificar JSON: '. json_last_error_msg());
}
$id = $data['id'];
$dias = $data['dias'];

if(!isset($id) || $id <= 0){
    die('Id no reconocido');
}

$lunes = 0;
$martes = 0;
$miercoles = 0;
$jueves = 0;
$viernes = 0;
$sabado = 0;
$domingo = 0;

foreach($dias as $dia){
    switch (strtoLower($dia)){
        case 'lunes': 
            $lunes = 1;
            break;
        case 'martes':
            $martes = 1;
            break;
        case 'miércoles':
            $miercoles = 1;
            break;
        case 'jueves':
            $jueves = 1;
            break;
        case 'viernes':
            $viernes = 1;
            break;
        case 'sábado':
            $sabado = 1;
            break;
        case 'domingo':
            $domingo = 1;
            break;
    }
}

$qr = 'UPDATE sap_cl00 SET cl_lun = ?, cl_mar = ?, cl_mie = ?, cl_jue = ?, cl_vie = ?, cl_sab = ?, cl_dom = ? WHERE cl_ide = ?';

$stmt = $con->prepare($qr);
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}

$stmt->bind_param('iiiiiiii', $lunes, $martes, $miercoles, $jueves, $viernes, $sabado, $domingo, $id);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "Fecha de entrega actualizada correctamente"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Cliente no encontrado" . $stmt->error
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}


?>