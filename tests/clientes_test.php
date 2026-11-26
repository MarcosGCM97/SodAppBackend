<?php
// Unique client name per company tests for the REST API in api/ (clientes.php).
//
// Usage: php tests/clientes_test.php
//
// Starts the PHP built-in server on 127.0.0.1:8771 (docroot api/) against the
// local database sodapp_test, logs in as Ariza and Congre (owners of their
// companies) and checks that a company cannot create or rename a client to the
// name of another of its active clients (ignoring case, accents and the spaces
// at the edges), while another company, a deleted client and a client saved
// with its own name are never blocked. Prints PASS/FAIL per case and exits with
// code 1 when any case fails.
//
// Every client it creates has a name starting with "Zz Cli " plus a random
// suffix and is deleted at the end, so the script is re-runnable. Requires the
// curl and mysqli extensions. Never point it at production.

// Router mode: the built-in server runs this same file before every request.
// The DB/JWT variables are set here with putenv() because Windows drops
// empty-valued variables (DB_PASS=) from a child process environment.
if (PHP_SAPI === 'cli-server') {
    putenv('DB_HOST=localhost');
    putenv('DB_NAME=sodapp_test');
    putenv('DB_USER=root');
    putenv('DB_PASS=');
    putenv('JWT_SECRET=local-test-secret-0123456789abcdef');
    return false; // let the server handle the requested file from api/
}

error_reporting(E_ALL);

const HOST = '127.0.0.1';
// CLIENTES_TEST_PORT overrides the port when 8771 is taken by another server
define('PORT', (int) (getenv('CLIENTES_TEST_PORT') ?: 8771));
const DB_HOST = 'localhost';
const DB_NAME = 'sodapp_test';
const DB_USER = 'root';
const DB_PASS = '';
const JWT_SECRET = 'local-test-secret-0123456789abcdef';
const PASSWORD = 'test1234';
const DUPLICADO = 'Ya existe un cliente con ese nombre';

$failures = 0;
$passes = 0;

function check($name, $ok, $detail = '') {
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name" . ($detail !== '' ? "  -- $detail" : '') . "\n";
    }
}

// ---------------------------------------------------------------- server

function start_server() {
    $apiDir = realpath(__DIR__ . '/../api');
    // never run the cases against a server of another checkout
    $busy = @fsockopen(HOST, PORT, $errno, $errstr, 0.2);
    if ($busy) {
        fclose($busy);
        fwrite(STDERR, 'Port ' . PORT . " is already in use; stop that server or set CLIENTES_TEST_PORT\n");
        exit(1);
    }
    $env = array_merge(getenv(), [
        'DB_HOST' => DB_HOST,
        'DB_NAME' => DB_NAME,
        'DB_USER' => DB_USER,
        'DB_PASS' => DB_PASS,
        'JWT_SECRET' => JWT_SECRET,
    ]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapp_clientes_server.log';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'w'],
        2 => ['file', $log, 'a'],
    ];
    $cmd = [PHP_BINARY, '-S', HOST . ':' . PORT, '-t', $apiDir, __FILE__];
    $proc = proc_open($cmd, $descriptors, $pipes, $apiDir, $env);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start the PHP built-in server\n");
        exit(1);
    }
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen(HOST, PORT, $errno, $errstr, 0.2);
        if ($sock) {
            fclose($sock);
            return $proc;
        }
        usleep(100000);
    }
    proc_terminate($proc);
    fwrite(STDERR, "The PHP built-in server did not start (see $log)\n");
    exit(1);
}

function stop_server($proc) {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
}

// ---------------------------------------------------------------- http

/**
 * Sends a request and returns [status, decoded JSON or null, raw body].
 * $token: null = no Authorization header, string = "Bearer <token>".
 */
function http($method, $path, $body = null, $token = null) {
    $ch = curl_init('http://' . HOST . ':' . PORT . '/' . $path);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = $raw === false ? '' : $raw;
    return [$status, json_decode($raw, true), $raw];
}

function short($raw) {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    return strlen($raw) > 160 ? substr($raw, 0, 160) . '...' : $raw;
}

function crear($nombre, $token) {
    return http('POST', 'clientes.php', ['nombreCl' => $nombre, 'numTelCl' => '351555', 'direccionCl' => 'Calle 1'], $token);
}

function editar($id, $nombre, $tel, $token) {
    return http('PUT', 'clientes.php', ['cl_ide' => $id, 'cl_nom' => $nombre, 'cl_tel' => $tel, 'cl_dir' => 'Calle 1'], $token);
}

function is_duplicado($r) {
    return $r[0] === 409 && $r[1] === ['success' => false, 'error' => DUPLICADO];
}

// ---------------------------------------------------------------- db

function db() {
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$db) {
            fwrite(STDERR, 'DB connection failed: ' . mysqli_connect_error() . "\n");
            exit(1);
        }
        mysqli_set_charset($db, 'utf8mb4');
    }
    return $db;
}

function db_row($sql, $types = '', $params = []) {
    $stmt = mysqli_prepare(db(), $sql);
    if ($stmt === false) {
        fwrite(STDERR, 'SQL error: ' . mysqli_error(db()) . " in $sql\n");
        return null;
    }
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function db_exec($sql, $types = '', $params = []) {
    $stmt = mysqli_prepare(db(), $sql);
    if ($stmt === false) {
        fwrite(STDERR, 'SQL error: ' . mysqli_error(db()) . " in $sql\n");
        return 0;
    }
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id(db());
    mysqli_stmt_close($stmt);
    return $id;
}

// Active clients of a company whose stored name is exactly $nombre (byte-exact, no collation)
function contar_exacto($companyId, $nombre) {
    return (int) db_row('SELECT COUNT(*) AS n FROM sap_cl00 WHERE cl_emp = ? AND is_deleted = 0 AND BINARY cl_nom = ?', 'is', [$companyId, $nombre])['n'];
}

function cliente($id) {
    return db_row('SELECT cl_nom, cl_tel, is_deleted FROM sap_cl00 WHERE cl_ide = ?', 'i', [$id]);
}

// ---------------------------------------------------------------- fixtures

$ariza = db_row('SELECT us_ide FROM sap_us00 WHERE us_nom = ?', 's', ['Ariza']);
$congre = db_row('SELECT us_ide FROM sap_us00 WHERE us_nom = ?', 's', ['Congre']);
if (!$ariza || !$congre) {
    fwrite(STDERR, "Users Ariza and Congre must exist in " . DB_NAME . " (tablasDB/migrations/000_local_seed_users.sql)\n");
    exit(1);
}
$arizaId = (int) $ariza['us_ide'];
$congreId = (int) $congre['us_ide'];

function cleanup() {
    db_exec("DELETE FROM sap_cl00 WHERE cl_nom LIKE 'Zz Cli %' OR cl_nom LIKE '%zz cli %'");
}

$server = start_server();
register_shutdown_function(function () use ($server) {
    cleanup();
    stop_server($server);
});

$r = http('POST', 'login.php', ['nombreUs' => 'Ariza', 'contrasenaUs' => PASSWORD]);
$tokA = $r[1]['token'] ?? null;
$r = http('POST', 'login.php', ['nombreUs' => 'Congre', 'contrasenaUs' => PASSWORD]);
$tokC = $r[1]['token'] ?? null;
if (!$tokA || !$tokC) {
    fwrite(STDERR, "Login failed for Ariza or Congre\n");
    exit(1);
}

$suffix = substr(md5(uniqid('', true)), 0, 6);
$nombre = "Zz Cli Pérez $suffix";
$otro = "Zz Cli Gómez $suffix";

// ---------------------------------------------------------------- 1. create

echo "\n# POST: a company cannot create a second client with the same name\n";

$r = crear($nombre, $tokA);
$idA = (int) ($r[1]['id'] ?? 0);
check('First client with the name -> 201', $r[0] === 201 && $idA > 0, "got {$r[0]}: " . short($r[2]));

$r = crear($nombre, $tokA);
check('Same name in the same company -> 409 "' . DUPLICADO . '"', is_duplicado($r), "got {$r[0]}: " . short($r[2]));
check('The 409 creates nothing', contar_exacto($arizaId, $nombre) === 1);

$variantes = [
    'lower case' => mb_strtolower($nombre, 'UTF-8'),
    'upper case' => mb_strtoupper($nombre, 'UTF-8'),
    'without accents' => "Zz Cli Perez $suffix",
    'spaces at the edges' => "  $nombre  ",
];
foreach ($variantes as $label => $variante) {
    $r = crear($variante, $tokA);
    check("Same name with $label -> 409", is_duplicado($r), "got {$r[0]}: " . short($r[2]));
}
check('No variant was created', (int) db_row("SELECT COUNT(*) AS n FROM sap_cl00 WHERE cl_emp = ? AND cl_nom LIKE ?", 'is', [$arizaId, "%$suffix%"])['n'] === 1);

$r = crear($otro, $tokA);
$idB = (int) ($r[1]['id'] ?? 0);
check('A different name in the same company -> 201', $r[0] === 201 && $idB > 0, "got {$r[0]}: " . short($r[2]));

$r = crear($nombre, $tokC);
$idC = (int) ($r[1]['id'] ?? 0);
check('Another company may use the same name -> 201', $r[0] === 201 && $idC > 0 && contar_exacto($congreId, $nombre) === 1, "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- 2. rename

echo "\n# PUT: renaming to another client's name is rejected; saving its own name is not\n";

$r = editar($idB, $nombre, '351999', $tokA);
check('Renaming to the name of another client of the company -> 409', is_duplicado($r), "got {$r[0]}: " . short($r[2]));
check('The 409 changes nothing', cliente($idB) === ['cl_nom' => $otro, 'cl_tel' => '351555', 'is_deleted' => 0]);

$r = editar($idB, mb_strtoupper("Zz Cli Perez $suffix", 'UTF-8'), '351999', $tokA);
check('Renaming to a case and accent variant of that name -> 409', is_duplicado($r), "got {$r[0]}: " . short($r[2]));

$r = editar($idA, $nombre, '351777', $tokA);
check('Saving a client with its own name -> 200', $r[0] === 200 && cliente($idA)['cl_tel'] === '351777', "got {$r[0]}: " . short($r[2]));

$r = editar($idA, mb_strtolower($nombre, 'UTF-8'), '351777', $tokA);
check('Changing only the case of its own name -> 200', $r[0] === 200 && cliente($idA)['cl_nom'] === mb_strtolower($nombre, 'UTF-8'), "got {$r[0]}: " . short($r[2]));
editar($idA, $nombre, '351777', $tokA);

$r = editar($idB, "Zz Cli Gómez Nuevo $suffix", '351555', $tokA);
check('Renaming to a free name -> 200', $r[0] === 200 && cliente($idB)['cl_nom'] === "Zz Cli Gómez Nuevo $suffix", "got {$r[0]}: " . short($r[2]));

$r = editar($idC, "Zz Cli Gómez Nuevo $suffix", '351555', $tokC);
check('Another company may rename to a name used by Ariza -> 200', $r[0] === 200, "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- 3. existing duplicates and deleted clients

echo "\n# Duplicates created before the rule can still be saved; a deleted client frees its name\n";

$dup = "Zz Cli Duplicado $suffix";
$dup1 = db_exec('INSERT INTO sap_cl00 SET cl_nom = ?, cl_tel = ?, cl_dir = ?, cl_emp = ?', 'sssi', [$dup, '1', 'x', $arizaId]);
$dup2 = db_exec('INSERT INTO sap_cl00 SET cl_nom = ?, cl_tel = ?, cl_dir = ?, cl_emp = ?', 'sssi', [$dup, '2', 'x', $arizaId]);
$r = editar($dup1, $dup, '111', $tokA);
check('An old duplicate saved with its own name -> 200', $r[0] === 200 && cliente($dup1)['cl_tel'] === '111', "got {$r[0]}: " . short($r[2]));

$r = http('DELETE', "clientes.php?id=$idA", null, $tokA);
check('Deleting the first client -> 200', $r[0] === 200 && (int) cliente($idA)['is_deleted'] === 1, "got {$r[0]}: " . short($r[2]));
$r = crear($nombre, $tokA);
check('The name of a deleted client can be created again -> 201', $r[0] === 201 && contar_exacto($arizaId, $nombre) === 1, "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- 4. previous rules still hold

echo "\n# Previous validation and scope still apply\n";

$r = http('POST', 'clientes.php', ['nombreCl' => "Zz Cli Incompleto $suffix"], $tokA);
check('POST without phone and address -> 400 (unchanged)', $r[0] === 400, "got {$r[0]}: " . short($r[2]));
$r = editar($idC, $otro, '1', $tokA);
check('PUT of another company client -> 404 (unchanged, before the name check)', $r[0] === 404, "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- summary

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
