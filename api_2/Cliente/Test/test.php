<?php
	require_once __DIR__ . "/../Entidad/Cliente.php";
	require_once __DIR__ .  "/../Controller/ClienteController.php";
	
	$cliente = new Cliente('','');

	$cliente->setNombre('Marcos');
	
	echo $cliente->getNombre();


	
