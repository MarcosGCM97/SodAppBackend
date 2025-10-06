<?php
    include 'conexion.php';

    //reportar errores
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    // Consulta para cargar los usuarios
    $query = "SELECT * FROM sap_us00";
    $result = mysqli_query($con, $query);
    // Verificar si la consulta se ejecutó correctamente
    if (!$result) {
        die("Error en la consulta: " . mysqli_error($con));
    }
    // Crear un array para almacenar los datos de los usuarios
    $usuarios = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $usuarios[] = $row;
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
    $us_nom = strtolower(trim($data['nombreUs']));
    $us_pas = trim($data['contrasenaUs']);

    $usuarioDB = null;

    foreach ($usuarios as $usuario) {
        if (strtolower(trim($usuario['us_nom'])) === $us_nom && trim($usuario['us_pas']) === $us_pas) {
            $usuarioDB = $usuario;
            break;
        }
    }

    if ($usuarioDB) {
        // Devolver una respuesta exitosa
        http_response_code(200);
        $response = [
            "success" => true,
            "message" => "Usuario encontrado.",
            "usuario" => $usuarioDB
        ];
    }
    else {
        // Devolver un error si el usuario no se encuentra
        http_response_code(404);
        $response = [
            "success" => false,
            "error" => "Usuario no encontrado."
        ];
    }

    // Devolver los datos en formato JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    // Cerrar la conexión
    mysqli_free_result($result);
    mysqli_close($con);
?>