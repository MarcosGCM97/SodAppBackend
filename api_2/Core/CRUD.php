<?php
require_once __DIR__ . '../../Core/lib.php';
require_once __DIR__ . '../../Core/auth.php';


public function startPetition(
	String $metodo, 
	String $funtion, 
	String $entidad, 
	Array $body 
) {
	allow_cors();

	//$method = $_SERVER['REQUEST_METHOD'];

	Switch ($metodo):
		Case "GET":
			if (isset($_GET['id']) && intval($_GET['id']) > 0) {

				$id = intval($_GET['id']);

				$stmt = prepare_or_fail($con, $query);

				mysqli_stmt_bind_param($stmt, 'i', $id);

				mysqli_stmt_execute($stmt);

				$res = mysqli_stmt_get_result($stmt);

				$row = mysqli_fetch_assoc($res);

				if ($row) send_json(["success" => true, $entidad => $row]);

				send_json([
					"success" => false, 
					"error" => $entidad. " no encontrado"
				], 404);
			} else {
				$res = mysqli_query($con, $query);

				if (!$res) send_json([
					"success" => false, 
					"error" => mysqli_error($con)
				], 500);

				$arr = [];

				while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
				
				send_json(["success" => true, $entidad => $arr]);
			}
		break;
		
		Case "POST":		
			// require auth for creating clients
			require_auth();
			
			$data = get_json_input();

			$nombre = isset($data['nombreCl']) ? $data['nombreCl'] : (isset($data['name']) ? $data['name'] : null);

			$tel = isset($data['numTelCl']) ? $data['numTelCl'] : (isset($data['tel']) ? $data['tel'] : null);

			$dir = isset($data['direccionCl']) ? $data['direccionCl'] : (isset($data['address']) ? $data['address'] : null);

			if (!$nombre || !$tel || !$dir) send_json([
				"success" => false, 
				"error" => "Datos incompletos: nombre, telefono y direccion"
			], 400);

			$stmt = prepare_or_fail($con, $query);

			mysqli_stmt_bind_param($stmt, 'sss', $nombre, $tel, $dir);

			if (mysqli_stmt_execute($stmt)) {
				send_json([
					"success" => true, 
					"message" => $entidad . " creado", 
					"id" => mysqli_insert_id($con)
				], 201);
			}

			send_json(["success" => false, "error" => mysqli_stmt_error($stmt)], 500);
		
		break
		Case "PUT":
		break;
		Case "DELETE":
		break;
		default:
			return "Method Unknowed";		
}
