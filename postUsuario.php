<?php
include 'conexion.php';

//reportar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Consulta para cargar los usuarios
$query = "INSERT INTO sap_us00 SET us_nom = ?, us_pas = ?";
$stmt = mysqli_prepare($con, $query);

if ($stmt === false) {
    die("Error al preparar la consulta: " . mysqli_error($con));
}
// Obtener los datos del usuario desde el cuerpo de la solicitud
$data = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error al decodificar JSON: " . json_last_error_msg());
}
// Verificar que los datos necesarios estén presentes
if (!isset($data['nombreUs']) || !isset($data['contrasenaUs'])) {
    die("Datos incompletos: nombreUs y contrasenaUs son requeridos.");
}
// Asignar los valores a los parámetros de la consulta
$us_nom = $data['nombreUs'];
$us_pas = $data['contrasenaUs'];
mysqli_stmt_bind_param($stmt, "ss", $us_nom, $us_pas);
// Ejecutar la consulta
if (mysqli_stmt_execute($stmt)) {
    // Devolver una respuesta exitosa
    http_response_code(201);
    $response = [
        "success" => true,
        "message" => "Usuario creado exitosamente."
    ];
}
else {
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