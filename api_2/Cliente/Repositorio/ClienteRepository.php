<?php
require_once '../../Core/Functions/lib.php';
require_once '../../Core/Functions/auth.php';

class ClienteRepository {
	public function getCliente($id) {
		$stmt = prepare_or_fail(
			$con
			'SELECT cl_ide, cl_nom, cl_dir, cl_tel, cl_deb, cl_emp 
			FROM sap_cl00 
				WHERE cl_ide = ? 
				AND is_deleted = 0'
		);

        mysqli_stmt_bind_param($stmt, 'i', $id);

        mysqli_stmt_execute($stmt);

        $res = mysqli_stmt_get_result($stmt);

        $row = mysqli_fetch_assoc($res);

        if ($row) send_json([
			"success" => true
			"cliente" => $row
		]);

        send_json([
			"success" => false,
			"error" => "Cliente no encontrado"
		], 404);
	}
	
	public function getClientes() {
		$res = mysqli_query(
			$con,
			'SELECT cl_ide, cl_nom, cl_dir, cl_tel, cl_deb, cl_emp
			FROM sap_cl00 
				WHERE is_deleted = 0 
			ORDER BY cl_nom');

        if (!$res) send_json([
			"success" => false
			"error" => mysqli_error($con)
		], 500);

        $arr = [];

        while ($r = mysqli_fetch_assoc($res)) $arr[] = $r;
        
        send_json([
			"success" => true,
			"clientes" => $arr
		]);
	}
	
	public function postCliente($data) {
		$nombre = null;
		$apellido = null;
		$emp = null;
		
		$params = [];
		$types = '';
		
		if (isset($data['nombre']) {
			$params += $data['nombre'];		
		}

		if (isset($data['apellido']) {
			$params += $data['apellido'];		
		}
		
		if (isset($data['empresa']) {
			$params += $data['empresa'];		
		}

var_dump($params);
		
		if (!$nombre || !$emp) send_json([
			"success" => false,
			"error" => "Datos incompletos: nombre y/o empresa"
		], 400);

		$stmt = prepare_or_fail(
			$con,
			'INSERT INTO sap_cl00 
				SET nombre = ?, 
					apellido = ?, 
					fecha_desde = NOW (),
					contacto_id = ?, 
					empresa_id = ?'
			);

		mysqli_stmt_bind_param($stmt, 'sss', $nombre, $tel, $dir);

		if (mysqli_stmt_execute($stmt)) {
			send_json([
				"success" => true, 
				"message" => "Cliente creado", 
				"id" => mysqli_insert_id($con)
			], 201);
		}

		send_json([
			"success" => false,
			"error" => mysqli_stmt_error($stmt)
		], 500);
	}
	
	public function putCliente($data) {
		$nombre = isset($data['nombreCl']) 
			? $data['nombreCl'] 
			: (isset($data['name'])	? $data['name']	: null
			);

		$tel = isset($data['numTelCl']) 
			? $data['numTelCl'] 
			: (isset($data['tel']) ? $data['tel'] : null);

		$dir = isset($data['direccionCl']) 
			? $data['direccionCl'] 
			: (isset($data['address']) ? $data['address'] : null);

		if (!$nombre || !$tel || !$dir) send_json([
			"success" => false, 
			"error" => "Datos incompletos"
		], 400);

		$stmt = prepare_or_fail(
			$con, 
			'UPDATE sap_cl00 
				SET cl_nom = ?, cl_tel = ?, cl_dir = ? 
			WHERE cl_ide = ?'
		);

		mysqli_stmt_bind_param($stmt, 'sssi', $nombre, $tel, $dir, $id);

		if (mysqli_stmt_execute($stmt)) {
			send_json([
				"success" => true,
				"message" => "Cliente actualizado"
			]);
		}

		send_json([
			"success" => false,
			"error" => mysqli_stmt_error($stmt)
		], 500);
	}
}
