<?php
    include 'conexion.php';
    //reportar errores
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    // Consulta para cargar los productos
    $query = "INSERT INTO sap_pr00 SET pr_nom = ?, pr_val = ?, pr_stk = ?";
    $stmt = mysqli_prepare($con, $query);
    if ($stmt === false) {
        die("Error al preparar la consulta: " . mysqli_error($con));
    }
    // Obtener los datos del producto desde el cuerpo de la solicitud
    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("Error al decodificar JSON: " . json_last_error_msg());
    }
    // Verificar que los datos necesarios estén presentes
    if (!isset($data['nombrePr']) || !isset($data['precioPr']) || !isset($data['cantidadPr'])) {
        die("Datos incompletos: nombrePr, precioPr y cantidadPr son requeridos.");
    }
    // Asignar los precios a los parámetros de la consulta
    $pr_nom = $data['nombrePr'];
    $pr_val = $data['precioPr'];
    $pr_stk = $data['cantidadPr'];

    //verificar que no exista un producto con el mismo nombre
    $checkQuery = "SELECT * FROM sap_pr00 WHERE pr_nom = ?";
    $checkStmt = mysqli_prepare($con, $checkQuery);
    if ($checkStmt === false) {
        die("Error al preparar la consulta de verificación: " . mysqli_error($con));
    }
    mysqli_stmt_bind_param($checkStmt, "s", $pr_nom);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    if (mysqli_num_rows($checkResult) > 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error" => "Ya existe un producto con el nombre '$pr_nom'."
        ]);
        mysqli_stmt_close($checkStmt);
        mysqli_close($con);
        exit;
    }

    mysqli_stmt_bind_param($stmt, "sdi", $pr_nom, $pr_val, $pr_stk);
    // Ejecutar la consulta
    if (mysqli_stmt_execute($stmt)) {
        // Devolver una respuesta exitosa
        http_response_code(201);
        $response = [
            "success" => true,
            "message" => "Producto creado exitosamente."
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