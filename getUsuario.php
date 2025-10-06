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

    // Devolver los datos en formato JSON
    header('Content-Type: application/json');
    echo json_encode($usuarios);
    // Cerrar la conexión
    mysqli_free_result($result);
    mysqli_close($con);
?>