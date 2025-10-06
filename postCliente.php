<?php 
include 'conexion.php';
//reportar errores
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
// Consulta para cargar los clientes
$query = "INSERT INTO sap_cl00 SET cl_nom = ?, cl_tel = ?, cl_dir = ?";
$stmt = mysqli_prepare($con, $query);
if ($stmt === false) {
    die("Error al preparar la consulta: " . mysqli_error($con));
}
// Obtener los datos del cliente desde el cuerpo de la solicitud
$data = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error al decodificar JSON: " . json_last_error_msg());
}
// Verificar que los datos necesarios estén presentes
if (!isset($data['nombreCl']) || !isset($data['numTelCl']) || !isset($data['direccionCl'])) {
    die("Datos incompletos: nombreCl, numTelCl y direccionCl son requeridos.");
}
// Asignar los valores a los parámetros de la consulta
$cl_nom = $data['nombreCl'];
$cl_tel = $data['numTelCl'];
$cl_dir = $data['direccionCl'];  

mysqli_stmt_bind_param($stmt, "sss", $cl_nom, $cl_tel, $cl_dir);
// Ejecutar la consulta
if (mysqli_stmt_execute($stmt)) {
    // Devolver una respuesta exitosa
    http_response_code(201);
    $response = [
        "success" => true,
        "message" => "Cliente creado exitosamente."
    ];
} else {
    // Devolver un error si la consulta falla
    http_response_code(500);
    $response = [
        "success" => false,
        "error" => $stmt->error
    ];
}
echo json_encode($response);
// Cerrar la declaración y la conexión
mysqli_stmt_close($stmt);
mysqli_close($con);
?>