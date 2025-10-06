<?php
//{"clienteId":3,"productos":[{"cantidad":4,"nombre":"Vidón de agua"}]}
    include 'conexion.php';
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    $query = "INSERT INTO sap_vt00 SET vt_cli = ?, vt_pro = ?, vt_can = ?, vt_fec = ?";

    $stmt = mysqli_prepare($con, $query);
    if ($stmt === false) {
        die("Error al preparar la consulta: " . mysqli_error($con));
    }
    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die("Error al decodificar JSON: " . json_last_error_msg());
    }
    if (!isset($data['clienteId'])) {
        die("Datos incompletos: clienteId es requerido.");
    }
    if (!is_array($data['productos']) || count($data['productos']) === 0) {
        die("Datos incompletos: productos debe ser un array no vacío.");
    }
   

    $clienteId = $data['clienteId'];
    $fechaVenta = date('Y-m-d'); // Fecha actual

    foreach ($data['productos'] as $producto) {
        $nombreProducto = $producto['nombre'];
        $cantProductos = $producto['cantidad'];

        //carga la venta en tabla sap_vt00
        mysqli_stmt_bind_param($stmt, "isis", $clienteId, $nombreProducto, $cantProductos, $fechaVenta);
        if (!mysqli_stmt_execute($stmt)) {
            http_response_code(500);
            $response = [
                "success" => false,
                "error" => $stmt->error
            ];
            echo json_encode($response);
            mysqli_stmt_close($stmt);
            mysqli_close($con);
            exit;
        }

        //cargar deuda del cliente
        // Primero, obtenemos el precio del producto
        $queryProductoPrecio = "SELECT pr_val FROM sap_pr00 WHERE pr_nom = ?";
        $stmtProductoPrecio = mysqli_prepare($con, $queryProductoPrecio);
        if ($stmtProductoPrecio === false) {
            die("Error al preparar la consulta del producto: " . mysqli_error($con));
        }
        mysqli_stmt_bind_param($stmtProductoPrecio, "s", $nombreProducto);

        if (!mysqli_stmt_execute($stmtProductoPrecio)) {
            die("Error al ejecutar la consulta del producto: " . mysqli_stmt_error($stmtProductoPrecio));
        }
        $resultProductoPrecio = mysqli_stmt_get_result($stmtProductoPrecio);

        //Obtener la deuda del cliente
        $queryDeudaCliente = "SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?";
        $stmtDeudaCliente = mysqli_prepare($con, $queryDeudaCliente);
        if ($stmtDeudaCliente === false) {
            die("Error al preparar la consulta de deuda del cliente: " . mysqli_error($con));
        }
        mysqli_stmt_bind_param($stmtDeudaCliente, "i", $clienteId);
        if (!mysqli_stmt_execute($stmtDeudaCliente)) {
            die("Error al ejecutar la consulta de deuda del cliente: " . mysqli_stmt_error($stmtDeudaCliente));
        }
        $resultDeudaCliente = mysqli_stmt_get_result($stmtDeudaCliente);
        $deudaCliente = 0;
        if ($resultDeudaCliente && mysqli_num_rows($resultDeudaCliente) > 0) {
            $rowDeuda = mysqli_fetch_assoc($resultDeudaCliente);
            $deudaCliente = $rowDeuda['cl_deb'];
        }


        // Verificamos si el producto existe y obtenemos su precio
        if ($resultProductoPrecio && mysqli_num_rows($resultProductoPrecio) > 0) {
            $productoPrecio = mysqli_fetch_assoc($resultProductoPrecio);
            $valorProducto = $productoPrecio['pr_val'];

            $deudaCliente = ($cantProductos * $valorProducto) + $deudaCliente;

            // Actualizamos la deuda del cliente en tabla sap_cl00
            $queryDeuda = "UPDATE sap_cl00 SET cl_deb = ? WHERE cl_ide = ?";
            $stmtDeuda = mysqli_prepare($con, $queryDeuda);
            if ($stmtDeuda === false) {
                die("Error al preparar la consulta de deuda: " . mysqli_error($con));
            }
            mysqli_stmt_bind_param($stmtDeuda, "ii", $deudaCliente, $clienteId);
            if (!mysqli_stmt_execute($stmtDeuda)) {
                die("Error al actualizar la deuda del cliente: " . mysqli_stmt_error($stmtDeuda));
            }

        } else {
            http_response_code(404);
            $response = [
                "success" => false,
                "error" => "Producto no encontrado: $nombreProducto"
            ];
            echo json_encode($response);
            mysqli_stmt_close($stmtProducto);
            mysqli_stmt_close($stmt);
            mysqli_close($con);
            exit;
        }

    }
    http_response_code(201);
    $response = [
        "success" => true,
        "message" => "Ventas creadas exitosamente."
    ];
    echo json_encode($response);
    mysqli_stmt_close($stmt);
    mysqli_close($con);

?>