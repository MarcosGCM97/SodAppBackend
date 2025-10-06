<?php 
include 'conexion.php';

header('Content-Type: application/json; charset=utf-8');
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if(!isset($id) || $id <= 0){
    echo json_encode([
        "success" => false,
        "error" => "ID inválido"
    ]);
    exit;
}
$qr = 'SELECT cl_lun, cl_mar, cl_mie, cl_jue, cl_vie, cl_sab, cl_dom FROM sap_cl00 WHERE cl_ide = ?';
$stmt = $con->prepare($qr);
if (!$stmt) {
    echo json_encode([
        "success" => false,
        "error" => $con->error
    ]);
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result) {
    die("Error en la consulta: " . mysqli_error($con));
}
$diasEntrega = array();
if ($row = mysqli_fetch_assoc($result)) {
    if ($row['cl_lun']) $diasEntrega[] = 'Lunes';
    if ($row['cl_mar']) $diasEntrega[] = 'Martes';
    if ($row['cl_mie']) $diasEntrega[] = 'Miércoles';
    if ($row['cl_jue']) $diasEntrega[] = 'Jueves';
    if ($row['cl_vie']) $diasEntrega[] = 'Viernes';
    if ($row['cl_sab']) $diasEntrega[] = 'Sábado';
    if ($row['cl_dom']) $diasEntrega[] = 'Domingo';
}
echo json_encode([
    "success" => true,
    "diasEntrega" => $diasEntrega
]);
$stmt->close();
mysqli_close($con);


?>