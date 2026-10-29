<?php
// Per-user data isolation tests for the REST API in api/ (A13).
//
// Usage: php tests/isolation_test.php
//
// Starts the PHP built-in server on 127.0.0.1:8765 (docroot api/) against the
// local database sodapp_test, logs in as Ariza (owner of the existing data) and
// Congre (owns nothing), and checks that each user only sees and modifies their
// own clientes, productos and ventas. Prints PASS/FAIL per case and exits with
// code 1 when any case fails.
//
// Every row created by the test is deleted at the end and every row of Ariza
// touched by the cross-user attempts is restored, so the script is re-runnable.
// Requires the curl and mysqli extensions. Never point it at production.

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
// ISOLATION_TEST_PORT overrides the port when 8765 is taken by another server
define('PORT', (int) (getenv('ISOLATION_TEST_PORT') ?: 8765));
const DB_HOST = 'localhost';
const DB_NAME = 'sodapp_test';
const DB_USER = 'root';
const DB_PASS = '';
const JWT_SECRET = 'local-test-secret-0123456789abcdef';
const PASSWORD = 'test1234';
const MARKER = 'zz_iso_test_';

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
        fwrite(STDERR, 'Port ' . PORT . " is already in use; stop that server or set ISOLATION_TEST_PORT\n");
        exit(1);
    }
    $env = array_merge(getenv(), [
        'DB_HOST' => DB_HOST,
        'DB_NAME' => DB_NAME,
        'DB_USER' => DB_USER,
        'DB_PASS' => DB_PASS,
        'JWT_SECRET' => JWT_SECRET,
    ]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapp_isolation_server.log';
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
function http($method, $path, $query = [], $body = null, $token = null) {
    $url = 'http://' . HOST . ':' . PORT . '/' . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $ch = curl_init($url);
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

function login($name) {
    list($status, $json, $raw) = http('POST', 'login.php', [], ['nombreUs' => $name, 'contrasenaUs' => PASSWORD]);
    if ($status !== 200 || empty($json['token'])) {
        fwrite(STDERR, "Login failed for $name ($status): $raw\n");
        return null;
    }
    return $json['token'];
}

function short($raw) {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    return strlen($raw) > 160 ? substr($raw, 0, 160) . '...' : $raw;
}

function expect_status($name, $expected, $response) {
    list($status, , $raw) = $response;
    check($name, $status === $expected, "expected $expected, got $status: " . short($raw));
}

function list_of($json, $key) {
    return (is_array($json) && isset($json[$key]) && is_array($json[$key])) ? $json[$key] : [];
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
        mysqli_set_charset($db, 'utf8');
    }
    return $db;
}

function db_rows($sql, $types = '', $params = []) {
    $stmt = mysqli_prepare(db(), $sql);
    if ($stmt === false) {
        fwrite(STDERR, 'SQL error: ' . mysqli_error(db()) . " in $sql\n");
        return [];
    }
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($stmt);
    return $rows;
}

function db_row($sql, $types = '', $params = []) {
    $rows = db_rows($sql, $types, $params);
    return $rows ? $rows[0] : null;
}

function db_exec($sql, $types = '', $params = []) {
    $stmt = mysqli_prepare(db(), $sql);
    if ($stmt === false) {
        fwrite(STDERR, 'SQL error: ' . mysqli_error(db()) . " in $sql\n");
        return;
    }
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function column_exists($table, $column) {
    return db_row('SELECT 1 AS x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        'sss', [DB_NAME, $table, $column]) !== null;
}

function max_id($table, $col) {
    $row = db_row("SELECT COALESCE(MAX($col), 0) AS m FROM $table");
    return (int) $row['m'];
}

function ids($rows, $col) {
    return array_map('intval', array_column($rows, $col));
}

// Restores a row to its snapshot (re-inserting it if it was hard-deleted).
function restore_row($table, $idCol, $snapshot) {
    $id = (int) $snapshot[$idCol];
    $current = db_row("SELECT * FROM $table WHERE $idCol = ?", 'i', [$id]);
    if ($current === $snapshot) return;
    $cols = array_keys($snapshot);
    $values = array_values($snapshot);
    $types = str_repeat('s', count($values));
    if ($current === null) {
        $sql = "INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        db_exec($sql, $types, $values);
        return;
    }
    $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols));
    db_exec("UPDATE $table SET $sets WHERE $idCol = ?", $types . 'i', array_merge($values, [$id]));
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

// productos.php filters by deleted_at, which the local schema lacks; add it
// for the duration of the run and drop it again at the end.
$addedDeletedAt = false;
if (!column_exists('sap_pr00', 'deleted_at')) {
    db_exec('ALTER TABLE sap_pr00 ADD COLUMN deleted_at DATE NULL DEFAULT NULL');
    $addedDeletedAt = true;
    echo "NOTE  sap_pr00.deleted_at missing in " . DB_NAME . "; added for this run\n";
}

$maxCl = max_id('sap_cl00', 'cl_ide');
$maxPr = max_id('sap_pr00', 'pr_ide');
$maxVt = max_id('sap_vt00', 'vt_ide');

$arizaClientes = db_rows('SELECT cl_ide, cl_nom FROM sap_cl00 WHERE cl_emp = ?', 'i', [$arizaId]);
$arizaClienteIds = ids($arizaClientes, 'cl_ide');
$arizaClienteNames = array_column($arizaClientes, 'cl_nom');
$arizaProductoIds = ids(db_rows('SELECT pr_ide FROM sap_pr00 WHERE pr_emp = ?', 'i', [$arizaId]), 'pr_ide');
$arizaVentaIds = ids(db_rows('SELECT vt_ide FROM sap_vt00 WHERE vt_emp = ?', 'i', [$arizaId]), 'vt_ide');

// Ariza's targets: an active cliente with debt and sales, two ASCII-named products
$targetCl = db_row('SELECT * FROM sap_cl00 c WHERE cl_emp = ? AND is_deleted = 0 AND cl_deb > 0
    AND EXISTS (SELECT 1 FROM sap_vt00 v WHERE v.vt_cli = c.cl_ide AND v.vt_emp = c.cl_emp) ORDER BY cl_ide LIMIT 1', 'i', [$arizaId]);
$arizaProducts = db_rows("SELECT * FROM sap_pr00 WHERE pr_emp = ? AND pr_nom REGEXP '^[a-z]+( [a-z]+)*$' ORDER BY pr_ide LIMIT 2", 'i', [$arizaId]);
if (!$targetCl || count($arizaProducts) < 2) {
    fwrite(STDERR, "Fixture data missing: Ariza needs an active cliente with debt and sales and two products\n");
    exit(1);
}
$targetVt = db_row('SELECT * FROM sap_vt00 WHERE vt_cli = ? AND vt_emp = ? ORDER BY vt_ide LIMIT 1', 'ii', [(int) $targetCl['cl_ide'], $arizaId]);
$dupProduct = $arizaProducts[0];      // Congre creates a product with this same name
$targetPr = $arizaProducts[1];        // Congre attacks this one
$targetClId = (int) $targetCl['cl_ide'];
$targetVtId = (int) $targetVt['vt_ide'];
$targetPrName = $targetPr['pr_nom'];
$dupName = $dupProduct['pr_nom'];
$targetMonth = (int) date('n', strtotime($targetVt['vt_fec']));
$targetYear = (int) date('Y', strtotime($targetVt['vt_fec']));

// Delivery day ids of a cliente in the sap_cd00 join table, ascending.
function cd_days($clienteId) {
    return ids(db_rows('SELECT cd_dia FROM sap_cd00 WHERE cd_cli = ? ORDER BY cd_dia', 'i', [$clienteId]), 'cd_dia');
}

// Replaces the sap_cd00 rows of a cliente with exactly these day ids.
function set_cd_days($clienteId, $dayIds) {
    db_exec('DELETE FROM sap_cd00 WHERE cd_cli = ?', 'i', [$clienteId]);
    foreach ($dayIds as $d) db_exec('INSERT INTO sap_cd00 (cd_cli, cd_dia) VALUES (?, ?)', 'ii', [$clienteId, $d]);
}

$targetCdDays = cd_days($targetClId);

function restore_targets() {
    global $targetCl, $targetPr, $targetVt, $dupProduct, $targetClId, $targetCdDays;
    if (cd_days($targetClId) !== $targetCdDays) set_cd_days($targetClId, $targetCdDays);
    restore_row('sap_cl00', 'cl_ide', $targetCl);
    restore_row('sap_pr00', 'pr_ide', $targetPr);
    restore_row('sap_pr00', 'pr_ide', $dupProduct);
    restore_row('sap_vt00', 'vt_ide', $targetVt);
}

function table_exists($table) {
    return db_row('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        'ss', [DB_NAME, $table]) !== null;
}

function cleanup() {
    global $maxCl, $maxPr, $maxVt, $addedDeletedAt;
    // container balances reference clientes and productos (FKs): delete them first
    if (table_exists('sap_ce00')) {
        db_exec('DELETE FROM sap_ce00 WHERE ce_cli > ? OR ce_pro > ?', 'ii', [$maxCl, $maxPr]);
    }
    db_exec('DELETE FROM sap_vt00 WHERE vt_ide > ?', 'i', [$maxVt]);
    db_exec('DELETE FROM sap_pr00 WHERE pr_ide > ?', 'i', [$maxPr]);
    db_exec('DELETE FROM sap_cd00 WHERE cd_cli > ?', 'i', [$maxCl]);
    db_exec('DELETE FROM sap_cl00 WHERE cl_ide > ?', 'i', [$maxCl]);
    restore_targets();
    if ($addedDeletedAt && column_exists('sap_pr00', 'deleted_at')) {
        db_exec('ALTER TABLE sap_pr00 DROP COLUMN deleted_at');
    }
}

$server = start_server();
register_shutdown_function(function () use ($server) {
    cleanup();
    stop_server($server);
});

$tokA = login('Ariza');
$tokC = login('Congre');
if (!$tokA || !$tokC) exit(1);

$suffix = substr(md5(uniqid('', true)), 0, 6);
$congreClName = MARKER . 'cliente_' . $suffix;

// ---------------------------------------------------------------- 1. authentication

echo "\n# Authentication: no token / invalid token -> 401\n";

$fakeToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.'
    . rtrim(strtr(base64_encode(json_encode(['us_ide' => $arizaId, 'exp' => time() + 3600])), '+/', '-_'), '=')
    . '.invalidsignature';

// Targets are nonexistent ids/names so an unprotected endpoint cannot damage data.
$authCases = [
    ['GET', 'clientes.php', [], null],
    ['GET', 'clientes.php', ['id' => $targetClId], null],
    ['POST', 'clientes.php', [], ['nombreCl' => MARKER . 'noauth', 'numTelCl' => '1', 'direccionCl' => 'x']],
    ['PUT', 'clientes.php', [], ['cl_ide' => 999999, 'cl_nom' => 'x', 'cl_tel' => '1', 'cl_dir' => 'x']],
    ['DELETE', 'clientes.php', ['id' => 999999], null],
    ['GET', 'productos.php', [], null],
    ['GET', 'productos.php', ['nombre' => $targetPrName], null],
    ['POST', 'productos.php', [], ['nombrePr' => MARKER . 'noauth', 'precioUni' => 1, 'stock' => 1]],
    ['PUT', 'productos.php', ['nombre' => MARKER . 'none', 'precio' => 1, 'cantidad' => 1], null],
    ['DELETE', 'productos.php', ['nombre' => MARKER . 'none'], null],
    ['GET', 'ventas.php', ['usuarioId' => $arizaId], null],
    ['GET', 'ventas.php', ['id' => $targetClId, 'usuarioId' => $arizaId], null],
    ['POST', 'ventas.php', [], ['clienteId' => 999999, 'productos' => [['nombre' => 'x', 'cantidad' => 1, 'precio' => 1]]]],
    ['DELETE', 'ventas.php', ['id' => 999999], null],
    ['GET', 'deudaCliente.php', [], null],
    ['GET', 'deudaCliente.php', ['id' => $targetClId], null],
    ['PUT', 'deudaCliente.php', [], ['id' => 999999, 'deuda' => 1]],
    ['GET', 'cajaMes.php', ['mes' => $targetMonth, 'ano' => $targetYear], null],
    ['GET', 'dias.php', [], null],
    ['GET', 'dias.php', ['id' => $targetClId], null],
    ['PUT', 'dias.php', [], ['cl_ide' => 999999]],
    ['GET', 'envases.php', ['clienteId' => 999999], null],
    ['POST', 'envases.php', [], ['accion' => 'llenar', 'producto' => MARKER . 'none', 'cantidad' => 1]],
    ['PUT', 'envases.php', [], ['clienteId' => 999999, 'productoId' => 999999, 'cantidad' => 1]],
];
foreach ($authCases as $c) {
    list($method, $path, $query, $body) = $c;
    $label = "$method $path" . ($query ? '?' . http_build_query($query) : '');
    expect_status("$label without token", 401, http($method, $path, $query, $body, null));
    expect_status("$label with invalid token", 401, http($method, $path, $query, $body, $fakeToken));
}
// remove anything an unprotected endpoint may have created before going on
db_exec('DELETE FROM sap_cl00 WHERE cl_ide > ?', 'i', [$maxCl]);
db_exec('DELETE FROM sap_pr00 WHERE pr_ide > ?', 'i', [$maxPr]);

// ---------------------------------------------------------------- 2. reads

echo "\n# Reads: Congre sees none of Ariza's records, Ariza still sees hers\n";

list(, $j) = http('GET', 'clientes.php', [], null, $tokC);
$leaked = array_intersect(ids(list_of($j, 'clientes'), 'cl_ide'), $arizaClienteIds);
check('Congre clientes list has none of Ariza', isset($j['clientes']) && !$leaked, count($leaked) . ' leaked');
list(, $j) = http('GET', 'clientes.php', [], null, $tokA);
check('Ariza clientes list has her cliente', in_array($targetClId, ids(list_of($j, 'clientes'), 'cl_ide'), true));

list(, $j) = http('GET', 'productos.php', [], null, $tokC);
$leaked = array_intersect(ids(list_of($j, 'productos'), 'pr_ide'), $arizaProductoIds);
check('Congre productos list has none of Ariza', isset($j['productos']) && !$leaked, count($leaked) . ' leaked');
list(, $j) = http('GET', 'productos.php', [], null, $tokA);
check('Ariza productos list has her producto', in_array((int) $targetPr['pr_ide'], ids(list_of($j, 'productos'), 'pr_ide'), true));

list(, $j) = http('GET', 'ventas.php', ['usuarioId' => $arizaId], null, $tokC);
$leaked = array_filter(list_of($j, 'ventas'), function ($v) use ($arizaClienteIds) {
    return isset($v['vt_cli']['cl_ide']) && in_array((int) $v['vt_cli']['cl_ide'], $arizaClienteIds, true);
});
check('Congre ventas list (usuarioId=Ariza) has none of Ariza', isset($j['ventas']) && !$leaked, count($leaked) . ' leaked');

list(, $j) = http('GET', 'ventas.php', ['id' => $targetClId, 'usuarioId' => $arizaId], null, $tokC);
$leaked = array_intersect(ids(list_of($j, 'ventas'), 'vt_ide'), $arizaVentaIds);
check("Congre ventas of Ariza's cliente is empty", isset($j['ventas']) && !$leaked, count($leaked) . ' leaked');
list(, $j) = http('GET', 'ventas.php', ['id' => $targetClId, 'usuarioId' => $arizaId], null, $tokA);
check("Ariza ventas of her cliente are listed", count(list_of($j, 'ventas')) > 0);

list(, $j) = http('GET', 'cajaMes.php', ['mes' => $targetMonth, 'ano' => $targetYear], null, $tokC);
$leaked = array_intersect(ids(list_of($j, 'caja'), 'vt_ide'), $arizaVentaIds);
check("Congre cajaMes $targetMonth/$targetYear has none of Ariza", isset($j['caja']) && !$leaked, count($leaked) . ' leaked');
list(, $j) = http('GET', 'cajaMes.php', ['mes' => $targetMonth, 'ano' => $targetYear], null, $tokA);
check("Ariza cajaMes $targetMonth/$targetYear has her venta", in_array($targetVtId, ids(list_of($j, 'caja'), 'vt_ide'), true));

list(, $j) = http('GET', 'dias.php', [], null, $tokC);
$leaked = array_intersect(array_column(list_of($j, 'days'), 'nombre'), $arizaClienteNames);
check('Congre dias list has none of Ariza', isset($j['days']) && !$leaked, count($leaked) . ' leaked');
list(, $j) = http('GET', 'dias.php', [], null, $tokA);
check('Ariza dias list has her cliente', in_array($targetCl['cl_nom'], array_column(list_of($j, 'days'), 'nombre'), true));

list(, $j) = http('GET', 'deudaCliente.php', [], null, $tokC);
$leaked = array_intersect(ids(list_of($j, 'deudores'), 'cl_ide'), $arizaClienteIds);
check('Congre deudores list has none of Ariza', isset($j['deudores']) && !$leaked, count($leaked) . ' leaked');
list(, $j) = http('GET', 'deudaCliente.php', [], null, $tokA);
check('Ariza deudores list has her cliente', in_array($targetClId, ids(list_of($j, 'deudores'), 'cl_ide'), true));

// ---------------------------------------------------------------- 3. creates

echo "\n# Creates: records are owned by the token user, not by usuarioId\n";

$r = http('POST', 'clientes.php', [], [
    'nombreCl' => $congreClName, 'numTelCl' => '351000000', 'direccionCl' => 'Calle test 1', 'usuarioId' => $arizaId,
], $tokC);
expect_status('Congre creates cliente', 201, $r);
$row = db_row('SELECT cl_ide, cl_emp FROM sap_cl00 WHERE cl_nom = ? AND cl_ide > ?', 'si', [$congreClName, $maxCl]);
$congreClId = $row ? (int) $row['cl_ide'] : 0;
check('Congre cliente owned by Congre in DB', $row && (int) $row['cl_emp'] === $congreId, 'cl_emp=' . ($row ? $row['cl_emp'] : 'missing'));

$r = http('POST', 'productos.php', [], [
    'nombrePr' => $dupName, 'precioUni' => 10, 'stock' => 5, 'usuarioId' => $arizaId,
], $tokC);
expect_status("Congre creates producto named like Ariza's ('$dupName')", 201, $r);
$row = db_row('SELECT pr_ide, pr_emp FROM sap_pr00 WHERE pr_nom = ? AND pr_ide > ?', 'si', [$dupName, $maxPr]);
$congrePrId = $row ? (int) $row['pr_ide'] : 0;
check('Congre producto owned by Congre in DB', $row && (int) $row['pr_emp'] === $congreId, 'pr_emp=' . ($row ? $row['pr_emp'] : 'missing'));

$r = http('POST', 'ventas.php', [], [
    'clienteId' => $congreClId, 'usuarioId' => $arizaId,
    'productos' => [['nombre' => $dupName, 'cantidad' => 1, 'precio' => 10]],
], $tokC);
expect_status('Congre creates venta for his cliente and producto', 201, $r);
$row = db_row('SELECT vt_ide, vt_emp FROM sap_vt00 WHERE vt_cli = ? AND vt_ide > ?', 'ii', [$congreClId, $maxVt]);
$congreVtId = $row ? (int) $row['vt_ide'] : 0;
check('Congre venta owned by Congre in DB', $row && (int) $row['vt_emp'] === $congreId, 'vt_emp=' . ($row ? $row['vt_emp'] : 'missing'));

$nowMonth = (int) date('n');
$nowYear = (int) date('Y');

list(, $j) = http('GET', 'clientes.php', [], null, $tokA);
check('Ariza does not see Congre cliente', isset($j['clientes']) && !in_array($congreClId, ids(list_of($j, 'clientes'), 'cl_ide'), true));
list(, $j) = http('GET', 'productos.php', [], null, $tokA);
check('Ariza does not see Congre producto', isset($j['productos']) && !in_array($congrePrId, ids(list_of($j, 'productos'), 'pr_ide'), true));
list(, $j) = http('GET', 'ventas.php', ['id' => $congreClId, 'usuarioId' => $arizaId], null, $tokA);
check('Ariza does not see Congre ventas by cliente', isset($j['ventas']) && count($j['ventas']) === 0);
list(, $j) = http('GET', 'ventas.php', ['usuarioId' => $arizaId], null, $tokA);
$seen = array_filter(list_of($j, 'ventas'), function ($v) use ($congreClId) {
    return isset($v['vt_cli']['cl_ide']) && (int) $v['vt_cli']['cl_ide'] === $congreClId;
});
check('Ariza ventas list does not include Congre venta', isset($j['ventas']) && !$seen);
list(, $j) = http('GET', 'cajaMes.php', ['mes' => $nowMonth, 'ano' => $nowYear], null, $tokA);
check('Ariza cajaMes does not include Congre venta', isset($j['caja']) && !in_array($congreVtId, ids(list_of($j, 'caja'), 'vt_ide'), true));
list(, $j) = http('GET', 'deudaCliente.php', [], null, $tokA);
check('Ariza deudores does not include Congre cliente', isset($j['deudores']) && !in_array($congreClId, ids(list_of($j, 'deudores'), 'cl_ide'), true));
list(, $j) = http('GET', 'dias.php', [], null, $tokA);
check('Ariza dias does not include Congre cliente', isset($j['days']) && !in_array($congreClName, array_column(list_of($j, 'days'), 'nombre'), true));

list(, $j) = http('GET', 'clientes.php', [], null, $tokC);
check('Congre sees his cliente', in_array($congreClId, ids(list_of($j, 'clientes'), 'cl_ide'), true));
list(, $j) = http('GET', 'productos.php', ['nombre' => $dupName], null, $tokC);
check('Congre reads his producto by name', isset($j['producto']['pr_ide']) && (int) $j['producto']['pr_ide'] === $congrePrId);
list(, $j) = http('GET', 'ventas.php', ['id' => $congreClId, 'usuarioId' => $congreId], null, $tokC);
check('Congre sees his venta by cliente', in_array($congreVtId, ids(list_of($j, 'ventas'), 'vt_ide'), true));
list(, $j) = http('GET', 'cajaMes.php', ['mes' => $nowMonth, 'ano' => $nowYear], null, $tokC);
check('Congre cajaMes includes his venta', in_array($congreVtId, ids(list_of($j, 'caja'), 'vt_ide'), true));

// ---------------------------------------------------------------- 4. cross-user access

echo "\n# Cross-user access: Congre on Ariza's records -> 404, DB unchanged\n";

function assert_unchanged($name, $table, $idCol, $snapshot) {
    $current = db_row("SELECT * FROM $table WHERE $idCol = ?", 'i', [(int) $snapshot[$idCol]]);
    check("$name: DB row unchanged", $current === $snapshot);
    restore_targets();
}

function count_ventas_after($clienteId) {
    global $maxVt;
    $row = db_row('SELECT COUNT(*) AS n FROM sap_vt00 WHERE vt_cli = ? AND vt_ide > ?', 'ii', [$clienteId, $maxVt]);
    return (int) $row['n'];
}

expect_status("Congre GET Ariza cliente by id", 404, http('GET', 'clientes.php', ['id' => $targetClId], null, $tokC));
expect_status("Congre GET Ariza producto by name", 404, http('GET', 'productos.php', ['nombre' => $targetPrName], null, $tokC));
expect_status("Congre GET Ariza deuda by id", 404, http('GET', 'deudaCliente.php', ['id' => $targetClId], null, $tokC));
expect_status("Congre GET Ariza dias by id", 404, http('GET', 'dias.php', ['id' => $targetClId], null, $tokC));

expect_status('Congre PUT Ariza cliente', 404, http('PUT', 'clientes.php', [], [
    'cl_ide' => $targetClId, 'cl_nom' => MARKER . 'hacked', 'cl_tel' => '1', 'cl_dir' => 'hacked',
], $tokC));
assert_unchanged('Congre PUT Ariza cliente', 'sap_cl00', 'cl_ide', $targetCl);

expect_status('Congre DELETE Ariza cliente', 404, http('DELETE', 'clientes.php', ['id' => $targetClId], null, $tokC));
assert_unchanged('Congre DELETE Ariza cliente', 'sap_cl00', 'cl_ide', $targetCl);

expect_status('Congre PUT Ariza producto', 404, http('PUT', 'productos.php', [
    'nombre' => $targetPrName, 'precio' => 1, 'cantidad' => 1,
], null, $tokC));
assert_unchanged('Congre PUT Ariza producto', 'sap_pr00', 'pr_ide', $targetPr);

expect_status('Congre DELETE Ariza producto', 404, http('DELETE', 'productos.php', ['nombre' => $targetPrName], null, $tokC));
assert_unchanged('Congre DELETE Ariza producto', 'sap_pr00', 'pr_ide', $targetPr);

expect_status('Congre DELETE Ariza venta', 404, http('DELETE', 'ventas.php', ['id' => $targetVtId], null, $tokC));
assert_unchanged('Congre DELETE Ariza venta', 'sap_vt00', 'vt_ide', $targetVt);

expect_status('Congre PUT deuda of Ariza cliente', 404, http('PUT', 'deudaCliente.php', [], [
    'id' => $targetClId, 'deuda' => 1,
], $tokC));
assert_unchanged('Congre PUT deuda of Ariza cliente', 'sap_cl00', 'cl_ide', $targetCl);

expect_status("Congre POST venta for Ariza's cliente", 404, http('POST', 'ventas.php', [], [
    'clienteId' => $targetClId, 'productos' => [['nombre' => $dupName, 'cantidad' => 1, 'precio' => 10]],
], $tokC));
check("Congre POST venta for Ariza's cliente: no venta inserted", count_ventas_after($targetClId) === 0);
assert_unchanged("Congre POST venta for Ariza's cliente", 'sap_cl00', 'cl_ide', $targetCl);

$before = count_ventas_after($congreClId);
expect_status("Congre POST venta with Ariza's producto", 404, http('POST', 'ventas.php', [], [
    'clienteId' => $congreClId, 'productos' => [['nombre' => $targetPrName, 'cantidad' => 1, 'precio' => 10]],
], $tokC));
check("Congre POST venta with Ariza's producto: no venta inserted", count_ventas_after($congreClId) === $before);

// ---------------------------------------------------------------- 5. own writes and uniqueness

echo "\n# Own writes and product name uniqueness\n";

expect_status('Congre duplicate producto name -> 409', 409, http('POST', 'productos.php', [], [
    'nombrePr' => $dupName, 'precioUni' => 10, 'stock' => 5,
], $tokC));
expect_status('Ariza duplicate producto name -> 409', 409, http('POST', 'productos.php', [], [
    'nombrePr' => $dupName, 'precioUni' => 10, 'stock' => 5,
], $tokA));
$row = db_row('SELECT COUNT(*) AS n FROM sap_pr00 WHERE pr_nom = ? AND pr_ide > ?', 'si', [$dupName, $maxPr]);
check('Duplicate attempts inserted nothing', (int) $row['n'] === 1, 'new rows=' . $row['n']);

expect_status('Congre PUT own cliente', 200, http('PUT', 'clientes.php', [], [
    'cl_ide' => $congreClId, 'cl_nom' => $congreClName, 'cl_tel' => '351000001', 'cl_dir' => 'Calle test 2',
], $tokC));
expect_status('Congre PUT own cliente with unchanged values', 200, http('PUT', 'clientes.php', [], [
    'cl_ide' => $congreClId, 'cl_nom' => $congreClName, 'cl_tel' => '351000001', 'cl_dir' => 'Calle test 2',
], $tokC));
expect_status('Congre PUT own producto', 200, http('PUT', 'productos.php', [
    'nombre' => $dupName, 'precio' => 12, 'cantidad' => 4,
], null, $tokC));
$row = db_row('SELECT pr_val FROM sap_pr00 WHERE pr_ide = ?', 'i', [(int) $dupProduct['pr_ide']]);
check("Congre PUT own producto left Ariza's same-name producto unchanged", $row && $row['pr_val'] === $dupProduct['pr_val']);
expect_status('Congre PUT deuda of own cliente', 200, http('PUT', 'deudaCliente.php', [], [
    'id' => $congreClId, 'deuda' => 5,
], $tokC));
// The Android app sends id and deuda as query params, not as a JSON body
$debtBefore = (float) db_row('SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?', 'i', [$congreClId])['cl_deb'];
expect_status('Congre PUT deuda of own cliente via query params', 200, http('PUT', 'deudaCliente.php', [
    'id' => $congreClId, 'deuda' => '3.0',
], null, $tokC));
$debtAfter = (float) db_row('SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?', 'i', [$congreClId])['cl_deb'];
check('Congre PUT deuda via query params subtracted the amount', $debtAfter === $debtBefore - 3, "before=$debtBefore after=$debtAfter");
expect_status('Congre PUT deuda larger than the debt -> 409', 409, http('PUT', 'deudaCliente.php', [
    'id' => $congreClId, 'deuda' => (string) ($debtAfter + 1000),
], null, $tokC));
$debtUnchanged = (float) db_row('SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?', 'i', [$congreClId])['cl_deb'];
check('Congre PUT deuda larger than the debt left it unchanged', $debtUnchanged === $debtAfter, "after=$debtUnchanged");
expect_status('Congre PUT deuda of Ariza cliente via query params', 404, http('PUT', 'deudaCliente.php', [
    'id' => $targetClId, 'deuda' => '1.0',
], null, $tokC));
assert_unchanged('Congre PUT deuda of Ariza cliente via query params', 'sap_cl00', 'cl_ide', $targetCl);
// Delivery days: the app sends PUT dias.php with a JSON body keyed by cl_ide
expect_status('Congre PUT dias of own cliente', 200, http('PUT', 'dias.php', [], [
    'cl_ide' => $congreClId, 'cl_lun' => 1, 'cl_mar' => 0, 'cl_mie' => 1, 'cl_jue' => 0,
    'cl_vie' => 0, 'cl_sab' => 0, 'cl_dom' => 1,
], $tokC));
$row = db_row('SELECT cl_lun, cl_mie, cl_dom, cl_mar FROM sap_cl00 WHERE cl_ide = ?', 'i', [$congreClId]);
check('Congre PUT dias saved all seven days', $row && (int) $row['cl_lun'] === 1 && (int) $row['cl_mie'] === 1
    && (int) $row['cl_dom'] === 1 && (int) $row['cl_mar'] === 0, json_encode($row));
check('Congre PUT dias wrote the days set to 1 into sap_cd00', cd_days($congreClId) === [1, 3, 7], json_encode(cd_days($congreClId)));
expect_status('Congre PUT dias of own cliente with unchanged values', 200, http('PUT', 'dias.php', [], [
    'cl_ide' => $congreClId, 'cl_lun' => 1, 'cl_mar' => 0, 'cl_mie' => 1, 'cl_jue' => 0,
    'cl_vie' => 0, 'cl_sab' => 0, 'cl_dom' => 1,
], $tokC));
expect_status('Congre PUT dias of Ariza cliente', 404, http('PUT', 'dias.php', [], [
    'cl_ide' => $targetClId, 'cl_lun' => 1, 'cl_dom' => 1,
], $tokC));
check('Congre PUT dias of Ariza cliente: sap_cd00 rows unchanged', cd_days($targetClId) === $targetCdDays, json_encode(cd_days($targetClId)));
assert_unchanged('Congre PUT dias of Ariza cliente', 'sap_cl00', 'cl_ide', $targetCl);

// Delivery days live in sap_cd00: a day sent as 0 is physically deleted
expect_status('Congre PUT dias unsetting Miércoles', 200, http('PUT', 'dias.php', [], [
    'cl_ide' => $congreClId, 'cl_lun' => 1, 'cl_dom' => 1,
], $tokC));
check('Congre PUT dias deleted the day set to 0 from sap_cd00', cd_days($congreClId) === [1, 7], json_encode(cd_days($congreClId)));

// Reads come from sap_cd00 joined to sap_di00, ordered Lunes..Domingo
set_cd_days($congreClId, [6, 2, 5]);
$expectedDays = ['Martes', 'Viernes', 'Sábado'];
$r = http('GET', 'dias.php', ['id' => $congreClId], null, $tokC);
expect_status('Congre GET dias of own cliente by id', 200, $r);
check('Congre GET dias by id reflects sap_cd00 rows with the same JSON',
    $r[2] === json_encode(['success' => true, 'diasEntrega' => $expectedDays]), short($r[2]));
list(, $j) = http('GET', 'dias.php', [], null, $tokC);
$entry = null;
foreach (list_of($j, 'days') as $d) if (isset($d['nombre']) && $d['nombre'] === $congreClName) $entry = $d;
check('Congre GET dias list reflects sap_cd00 rows with the same JSON', $entry !== null
    && array_keys($entry) === ['nombre', 'direccion', 'diasEntrega'] && $entry['diasEntrega'] === $expectedDays, json_encode($entry));

// Ownership: Ariza can neither read nor write Congre's delivery days
expect_status("Ariza GET Congre dias by id", 404, http('GET', 'dias.php', ['id' => $congreClId], null, $tokA));
expect_status("Ariza PUT Congre dias", 404, http('PUT', 'dias.php', [], [
    'cl_ide' => $congreClId, 'cl_lun' => 1, 'cl_mar' => 0, 'cl_vie' => 0, 'cl_sab' => 0,
], $tokA));
check('Ariza PUT Congre dias: sap_cd00 rows unchanged', cd_days($congreClId) === [2, 5, 6], json_encode(cd_days($congreClId)));

// Soft-deleted clientes are hidden from the calendar and not writable
db_exec('UPDATE sap_cl00 SET is_deleted = 1 WHERE cl_ide = ?', 'i', [$congreClId]);
$missing = http('GET', 'dias.php', ['id' => 999999], null, $tokC);
$r = http('GET', 'dias.php', ['id' => $congreClId], null, $tokC);
check('Congre GET dias of soft-deleted cliente -> same 404 as missing', $r[0] === 404 && $r[2] === $missing[2],
    "got {$r[0]}: " . short($r[2]));
list(, $j) = http('GET', 'dias.php', [], null, $tokC);
check('Congre GET dias list excludes soft-deleted cliente', isset($j['days'])
    && !in_array($congreClName, array_column(list_of($j, 'days'), 'nombre'), true));
expect_status('Congre PUT dias of soft-deleted cliente', 404, http('PUT', 'dias.php', [], [
    'cl_ide' => $congreClId, 'cl_lun' => 1,
], $tokC));
check('Congre PUT dias of soft-deleted cliente: sap_cd00 rows unchanged', cd_days($congreClId) === [2, 5, 6], json_encode(cd_days($congreClId)));
db_exec('UPDATE sap_cl00 SET is_deleted = 0 WHERE cl_ide = ?', 'i', [$congreClId]);
expect_status('Congre DELETE own venta', 200, http('DELETE', 'ventas.php', ['id' => $congreVtId], null, $tokC));
expect_status('Congre DELETE own producto', 200, http('DELETE', 'productos.php', ['nombre' => $dupName], null, $tokC));
$row = db_row('SELECT pr_ide FROM sap_pr00 WHERE pr_ide = ? AND deleted_at IS NULL', 'i', [(int) $dupProduct['pr_ide']]);
check("Congre DELETE own producto left Ariza's same-name producto active", $row !== null);
restore_row('sap_pr00', 'pr_ide', $dupProduct);
expect_status('Congre DELETE own cliente', 200, http('DELETE', 'clientes.php', ['id' => $congreClId], null, $tokC));

// ---------------------------------------------------------------- 6. returnable containers (envases)

echo "\n# Returnable containers: products, sales, refill and client balance\n";

function pr_row($id) {
    return db_row('SELECT pr_ret, pr_vac, pr_stk, pr_val FROM sap_pr00 WHERE pr_ide = ?', 'i', [$id]);
}

// Client container balance, or null when there is no sap_ce00 row.
function ce_can($clienteId, $productoId) {
    $row = db_row('SELECT ce_can FROM sap_ce00 WHERE ce_cli = ? AND ce_pro = ?', 'ii', [$clienteId, $productoId]);
    return $row === null ? null : (int) $row['ce_can'];
}

function cl_debt($clienteId) {
    return (float) db_row('SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?', 'i', [$clienteId])['cl_deb'];
}

function ventas_of($clienteId) {
    global $maxVt;
    return db_rows('SELECT vt_ide, vt_pro, vt_can, vt_dev FROM sap_vt00 WHERE vt_cli = ? AND vt_ide > ? ORDER BY vt_ide', 'ii', [$clienteId, $maxVt]);
}

function pr_id_by_name($name) {
    global $maxPr;
    $row = db_row('SELECT pr_ide FROM sap_pr00 WHERE pr_nom = ? AND pr_ide > ?', 'si', [$name, $maxPr]);
    return $row ? (int) $row['pr_ide'] : 0;
}

$retName = MARKER . 'ret_' . $suffix;
$ret2Name = MARKER . 'rettwo_' . $suffix;
$plainName = MARKER . 'plain_' . $suffix;
$envClName = MARKER . 'envcli_' . $suffix;

// S4 POST: retornable / vacios are optional; an old app POST creates a non-returnable product
$r = http('POST', 'productos.php', [], ['nombrePr' => $retName, 'precioUni' => 100, 'stock' => 10, 'retornable' => true, 'vacios' => 3], $tokA);
expect_status('Ariza POST returnable producto with vacios', 201, $r);
$retId = pr_id_by_name($retName);
$row = pr_row($retId);
check('POST returnable producto saved pr_ret=1, pr_vac=3, pr_stk=10', $row && (int) $row['pr_ret'] === 1
    && (int) $row['pr_vac'] === 3 && (int) $row['pr_stk'] === 10, json_encode($row));

expect_status('Ariza POST returnable producto without vacios', 201, http('POST', 'productos.php', [], [
    'nombrePr' => $ret2Name, 'precioUni' => 80, 'stock' => 4, 'retornable' => true,
], $tokA));
$ret2Id = pr_id_by_name($ret2Name);
$row = pr_row($ret2Id);
check('POST returnable producto without vacios defaults pr_vac=0', $row && (int) $row['pr_ret'] === 1 && (int) $row['pr_vac'] === 0, json_encode($row));

$r = http('POST', 'productos.php', [], ['nombrePr' => $plainName, 'precioUni' => 50, 'stock' => 5], $tokA);
expect_status('Ariza POST producto old shape (no retornable/vacios)', 201, $r);
check('POST producto old shape keeps the same response body', isset($r[1]['success'], $r[1]['message'], $r[1]['id'])
    && $r[1]['success'] === true && $r[1]['message'] === 'Producto creado', short($r[2]));
$plainId = pr_id_by_name($plainName);
$row = pr_row($plainId);
check('POST producto old shape saved pr_ret=0, pr_vac=0', $row && (int) $row['pr_ret'] === 0 && (int) $row['pr_vac'] === 0
    && (int) $row['pr_stk'] === 5, json_encode($row));

$badName = MARKER . 'bad_' . $suffix;
expect_status('POST producto with negative vacios -> 400', 400, http('POST', 'productos.php', [], [
    'nombrePr' => $badName, 'precioUni' => 1, 'stock' => 1, 'retornable' => true, 'vacios' => -1,
], $tokA));
expect_status('POST producto with non-integer vacios -> 400', 400, http('POST', 'productos.php', [], [
    'nombrePr' => $badName, 'precioUni' => 1, 'stock' => 1, 'retornable' => true, 'vacios' => 1.5,
], $tokA));
expect_status('POST producto with invalid retornable -> 400', 400, http('POST', 'productos.php', [], [
    'nombrePr' => $badName, 'precioUni' => 1, 'stock' => 1, 'retornable' => 'x',
], $tokA));
check('Invalid producto POSTs inserted nothing', pr_id_by_name($badName) === 0);

// S4 GET returns pr_ret and pr_vac
list(, $j) = http('GET', 'productos.php', [], null, $tokA);
$entry = null;
foreach (list_of($j, 'productos') as $p) if ((int) $p['pr_ide'] === $retId) $entry = $p;
check('GET productos list returns pr_ret and pr_vac', $entry !== null && array_key_exists('pr_ret', $entry)
    && array_key_exists('pr_vac', $entry) && (int) $entry['pr_ret'] === 1 && (int) $entry['pr_vac'] === 3, json_encode($entry));
list(, $j) = http('GET', 'productos.php', ['nombre' => $retName], null, $tokA);
check('GET producto by name returns pr_ret and pr_vac', isset($j['producto']['pr_ret'], $j['producto']['pr_vac'])
    && (int) $j['producto']['pr_ret'] === 1 && (int) $j['producto']['pr_vac'] === 3, json_encode($j));

// S4 PUT: an old app PUT (nombre, precio, cantidad) leaves pr_ret / pr_vac untouched
$r = http('PUT', 'productos.php', ['nombre' => $retName, 'precio' => 110, 'cantidad' => 20], null, $tokA);
expect_status('Ariza PUT producto old shape', 200, $r);
check('PUT producto old shape keeps the same response body', $r[2] === json_encode(['success' => true, 'message' => 'Producto actualizado']), short($r[2]));
$row = pr_row($retId);
check('PUT producto old shape updated price and stock, left pr_ret/pr_vac', $row && (float) $row['pr_val'] === 110.0
    && (int) $row['pr_stk'] === 20 && (int) $row['pr_ret'] === 1 && (int) $row['pr_vac'] === 3, json_encode($row));

expect_status('Ariza PUT producto with retornable=1 and vacios=2', 200, http('PUT', 'productos.php', [
    'nombre' => $plainName, 'precio' => 50, 'cantidad' => 5, 'retornable' => 1, 'vacios' => 2,
], null, $tokA));
$row = pr_row($plainId);
check('PUT producto set pr_ret=1 and absolute pr_vac=2', $row && (int) $row['pr_ret'] === 1 && (int) $row['pr_vac'] === 2, json_encode($row));
expect_status('Ariza PUT producto with retornable=0 and vacios=0', 200, http('PUT', 'productos.php', [
    'nombre' => $plainName, 'precio' => 50, 'cantidad' => 5, 'retornable' => 0, 'vacios' => 0,
], null, $tokA));
$row = pr_row($plainId);
check('PUT producto set pr_ret=0 and pr_vac=0', $row && (int) $row['pr_ret'] === 0 && (int) $row['pr_vac'] === 0, json_encode($row));
expect_status('PUT producto with negative vacios -> 400', 400, http('PUT', 'productos.php', [
    'nombre' => $retName, 'precio' => 1, 'cantidad' => 1, 'vacios' => -1,
], null, $tokA));
expect_status('PUT producto with retornable=2 -> 400', 400, http('PUT', 'productos.php', [
    'nombre' => $retName, 'precio' => 1, 'cantidad' => 1, 'retornable' => 2,
], null, $tokA));
$row = pr_row($retId);
check('Invalid producto PUTs changed nothing', $row && (float) $row['pr_val'] === 110.0 && (int) $row['pr_stk'] === 20
    && (int) $row['pr_ret'] === 1 && (int) $row['pr_vac'] === 3, json_encode($row));
expect_status('Congre PUT vacios of Ariza producto', 404, http('PUT', 'productos.php', [
    'nombre' => $retName, 'precio' => 1, 'cantidad' => 1, 'retornable' => 0, 'vacios' => 99,
], null, $tokC));
$row = pr_row($retId);
check('Congre PUT vacios of Ariza producto changed nothing', $row && (int) $row['pr_ret'] === 1 && (int) $row['pr_vac'] === 3, json_encode($row));

// A cliente of Ariza for the sales below
expect_status('Ariza creates cliente for envases', 201, http('POST', 'clientes.php', [], [
    'nombreCl' => $envClName, 'numTelCl' => '351000002', 'direccionCl' => 'Calle test 3',
], $tokA));
$row = db_row('SELECT cl_ide FROM sap_cl00 WHERE cl_nom = ? AND cl_ide > ?', 'si', [$envClName, $maxCl]);
$envClId = $row ? (int) $row['cl_ide'] : 0;

// S2 POST venta with devueltos: vt_dev, pr_vac and the client balance move in the same transaction
$debt0 = cl_debt($envClId);
$r = http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $retName, 'cantidad' => 5, 'precio' => 100, 'devueltos' => 2],
    ['nombre' => $plainName, 'cantidad' => 1, 'precio' => 50],
]], $tokA);
expect_status('POST venta with devueltos on a returnable producto', 201, $r);
check('POST venta with devueltos keeps the same response body',
    $r[2] === json_encode(['success' => true, 'message' => 'Ventas registradas correctamente']), short($r[2]));
$vts = ventas_of($envClId);
check('Returnable line saved vt_dev=2, non-returnable line vt_dev=NULL', count($vts) === 2
    && $vts[0]['vt_pro'] === $retName && $vts[0]['vt_dev'] !== null && (int) $vts[0]['vt_dev'] === 2
    && $vts[1]['vt_pro'] === $plainName && $vts[1]['vt_dev'] === null, json_encode($vts));
$row = pr_row($retId);
check('Venta added devueltos to pr_vac (3+2) and decremented pr_stk (20-5)', $row && (int) $row['pr_vac'] === 5 && (int) $row['pr_stk'] === 15, json_encode($row));
check('Venta set client balance to cantidad - devueltos (5-2=3)', ce_can($envClId, $retId) === 3, var_export(ce_can($envClId, $retId), true));
check('Venta created no balance row for the non-returnable producto', ce_can($envClId, $plainId) === null);
$row = pr_row($plainId);
check('Non-returnable producto: pr_vac unchanged, pr_stk decremented (5-1)', $row && (int) $row['pr_vac'] === 0 && (int) $row['pr_stk'] === 4, json_encode($row));
check('Venta with devueltos added the debt (5*100 + 1*50)', cl_debt($envClId) === $debt0 + 550, 'debt=' . cl_debt($envClId));
$row = db_row('SELECT ce_emp FROM sap_ce00 WHERE ce_cli = ? AND ce_pro = ?', 'ii', [$envClId, $retId]);
check('Balance row is owned by Ariza', $row && (int) $row['ce_emp'] === $arizaId, json_encode($row));

$r = http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $retName, 'cantidad' => 1, 'precio' => 100, 'devueltos' => 6],
]], $tokA);
expect_status('POST venta returning more than delivered', 201, $r);
check('Balance accumulates atomically and may go negative (3 + 1 - 6 = -2)', ce_can($envClId, $retId) === -2, var_export(ce_can($envClId, $retId), true));
check('pr_vac accumulates (5+6=11)', (int) pr_row($retId)['pr_vac'] === 11);
$vts = ventas_of($envClId);
$returnLineId = isset($vts[2]) ? (int) $vts[2]['vt_ide'] : 0;

// Old app versions send no devueltos: treated as 0
$debt1 = cl_debt($envClId);
$r = http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $retName, 'cantidad' => 2, 'precio' => 100],
]], $tokA);
expect_status('POST venta old shape (no devueltos) on a returnable producto', 201, $r);
$vts = ventas_of($envClId);
$last = end($vts);
check('Old shape on returnable producto saved vt_dev=0', $last && $last['vt_dev'] !== null && (int) $last['vt_dev'] === 0, json_encode($last));
check('Old shape on returnable producto: balance += cantidad (-2+2=0), pr_vac unchanged', ce_can($envClId, $retId) === 0
    && (int) pr_row($retId)['pr_vac'] === 11, var_export(ce_can($envClId, $retId), true));
$stockBefore = (int) pr_row($plainId)['pr_stk'];
$r = http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $plainName, 'cantidad' => 2, 'precio' => 50],
]], $tokA);
expect_status('POST venta old shape on a non-returnable producto', 201, $r);
check('POST venta old shape keeps the same response body',
    $r[2] === json_encode(['success' => true, 'message' => 'Ventas registradas correctamente']), short($r[2]));
$vts = ventas_of($envClId);
$last = end($vts);
check('Old shape on non-returnable producto: vt_dev NULL, stock decremented, no balance row', $last && $last['vt_dev'] === null
    && (int) pr_row($plainId)['pr_stk'] === $stockBefore - 2 && ce_can($envClId, $plainId) === null, json_encode($last));
check('Old shape ventas added the debt (2*100 + 2*50)', cl_debt($envClId) === $debt1 + 300, 'debt=' . cl_debt($envClId));

// Rejected ventas persist nothing
function envases_state() {
    global $envClId, $retId, $plainId;
    return json_encode([count(ventas_of($envClId)), pr_row($retId), pr_row($plainId), ce_can($envClId, $retId), ce_can($envClId, $plainId), cl_debt($envClId)]);
}
$state = envases_state();
$r = http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $retName, 'cantidad' => 1, 'precio' => 100, 'devueltos' => 1],
    ['nombre' => $plainName, 'cantidad' => 1, 'precio' => 50, 'devueltos' => 1],
]], $tokA);
expect_status('POST venta with devueltos on a non-returnable producto -> 400', 400, $r);
check('Non-returnable devueltos error message', $r[2] === json_encode(['success' => false, 'error' => "El producto $plainName no es retornable"]), short($r[2]));
check('Non-returnable devueltos: nothing persisted (rollback)', envases_state() === $state, envases_state());
foreach ([[-1, 'negative'], ['abc', 'non-numeric'], [1.5, 'non-integer']] as $c) {
    $r = http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
        ['nombre' => $retName, 'cantidad' => 2, 'precio' => 100, 'devueltos' => $c[0]],
    ]], $tokA);
    expect_status("POST venta with {$c[1]} devueltos -> 400", 400, $r);
    check("POST venta with {$c[1]} devueltos: nothing persisted", envases_state() === $state, envases_state());
}

// S3 DELETE venta reverts pr_vac and the balance of lines with vt_dev
$stockBefore = (int) pr_row($retId)['pr_stk'];
expect_status('Congre DELETE Ariza venta with devueltos', 404, http('DELETE', 'ventas.php', ['id' => $returnLineId], null, $tokC));
check('Congre DELETE Ariza venta with devueltos: nothing changed', envases_state() === $state, envases_state());
$r = http('DELETE', 'ventas.php', ['id' => $returnLineId], null, $tokA);
expect_status('Ariza DELETE venta line with vt_dev=6', 200, $r);
check('DELETE venta keeps the same response body', $r[2] === json_encode(['success' => true, 'message' => 'Venta eliminada']), short($r[2]));
check('DELETE venta reverted pr_vac (11-6=5), stock untouched', (int) pr_row($retId)['pr_vac'] === 5
    && (int) pr_row($retId)['pr_stk'] === $stockBefore, json_encode(pr_row($retId)));
check('DELETE venta reverted the balance (0 - (1-6) = 5)', ce_can($envClId, $retId) === 5, var_export(ce_can($envClId, $retId), true));
check('DELETE venta removed the line', db_row('SELECT 1 AS x FROM sap_vt00 WHERE vt_ide = ?', 'i', [$returnLineId]) === null);
$vts = ventas_of($envClId);
$plainLineId = isset($vts[1]) ? (int) $vts[1]['vt_ide'] : 0;
$plainBefore = pr_row($plainId);
expect_status('Ariza DELETE venta line with vt_dev NULL', 200, http('DELETE', 'ventas.php', ['id' => $plainLineId], null, $tokA));
check('DELETE venta with vt_dev NULL: producto and balance unchanged', pr_row($plainId) === $plainBefore
    && ce_can($envClId, $plainId) === null && ce_can($envClId, $retId) === 5, json_encode(pr_row($plainId)));
expect_status('DELETE venta already deleted -> 404', 404, http('DELETE', 'ventas.php', ['id' => $returnLineId], null, $tokA));
check('Second DELETE did not revert twice', (int) pr_row($retId)['pr_vac'] === 5 && ce_can($envClId, $retId) === 5);

// S5 refill: empties become full units, atomically
$before = pr_row($retId);
$r = http('POST', 'envases.php', [], ['accion' => 'llenar', 'producto' => $retName, 'cantidad' => 4], $tokA);
expect_status('Ariza llenar 4 envases', 200, $r);
check('llenar returns the updated producto', isset($r[1]['success'], $r[1]['producto']) && $r[1]['success'] === true
    && (int) $r[1]['producto']['pr_ide'] === $retId && (int) $r[1]['producto']['pr_vac'] === 1
    && (int) $r[1]['producto']['pr_stk'] === (int) $before['pr_stk'] + 4, short($r[2]));
$row = pr_row($retId);
check('llenar moved 4 from pr_vac to pr_stk in DB', (int) $row['pr_vac'] === 1 && (int) $row['pr_stk'] === (int) $before['pr_stk'] + 4, json_encode($row));
$before = pr_row($retId);
$r = http('POST', 'envases.php', [], ['accion' => 'llenar', 'producto' => $retName, 'cantidad' => 2], $tokA);
expect_status('llenar more than the empties -> 409', 409, $r);
check('llenar 409 error message', $r[2] === json_encode(['success' => false, 'error' => 'No hay suficientes envases vacíos']), short($r[2]));
check('llenar 409 changed nothing', pr_row($retId) === $before, json_encode(pr_row($retId)));
expect_status('llenar a non-returnable producto -> 400', 400, http('POST', 'envases.php', [], [
    'accion' => 'llenar', 'producto' => $plainName, 'cantidad' => 1,
], $tokA));
expect_status('llenar an unknown producto -> 404', 404, http('POST', 'envases.php', [], [
    'accion' => 'llenar', 'producto' => MARKER . 'none', 'cantidad' => 1,
], $tokA));
expect_status('Congre llenar Ariza producto -> 404', 404, http('POST', 'envases.php', [], [
    'accion' => 'llenar', 'producto' => $retName, 'cantidad' => 1,
], $tokC));
foreach ([[0, 'zero'], [-1, 'negative'], ['x', 'non-numeric'], [1.5, 'non-integer']] as $c) {
    expect_status("llenar with {$c[1]} cantidad -> 400", 400, http('POST', 'envases.php', [], [
        'accion' => 'llenar', 'producto' => $retName, 'cantidad' => $c[0],
    ], $tokA));
}
expect_status('envases POST with unknown accion -> 400', 400, http('POST', 'envases.php', [], [
    'accion' => 'vaciar', 'producto' => $retName, 'cantidad' => 1,
], $tokA));
check('Rejected llenar requests changed nothing', pr_row($retId) === $before, json_encode(pr_row($retId)));

// S6 GET client balance: every owned, non-deleted returnable producto, 0 when no row
$r = http('GET', 'envases.php', ['clienteId' => $envClId], null, $tokA);
expect_status('Ariza GET envases of her cliente', 200, $r);
$byId = [];
foreach (list_of($r[1], 'envases') as $e) $byId[(int) $e['pr_ide']] = $e;
check('GET envases lists the returnable producto with its balance', isset($byId[$retId])
    && array_keys($byId[$retId]) === ['pr_ide', 'pr_nom', 'ce_can'] && $byId[$retId]['pr_nom'] === $retName
    && (int) $byId[$retId]['ce_can'] === 5, short($r[2]));
check('GET envases lists a returnable producto without balance row as 0', isset($byId[$ret2Id]) && (int) $byId[$ret2Id]['ce_can'] === 0, short($r[2]));
check('GET envases omits non-returnable productos', !isset($byId[$plainId]));
db_exec('UPDATE sap_pr00 SET deleted_at = CURDATE() WHERE pr_ide = ?', 'i', [$ret2Id]);
list(, $j) = http('GET', 'envases.php', ['clienteId' => $envClId], null, $tokA);
check('GET envases omits deleted productos', isset($j['envases']) && !in_array($ret2Id, ids(list_of($j, 'envases'), 'pr_ide'), true));
db_exec('UPDATE sap_pr00 SET deleted_at = NULL WHERE pr_ide = ?', 'i', [$ret2Id]);
expect_status('Congre GET envases of Ariza cliente -> 404', 404, http('GET', 'envases.php', ['clienteId' => $envClId], null, $tokC));
expect_status('GET envases of an unknown cliente -> 404', 404, http('GET', 'envases.php', ['clienteId' => 999999], null, $tokA));

// S6 PUT sets the absolute balance (upsert)
$r = http('PUT', 'envases.php', [], ['clienteId' => $envClId, 'productoId' => $retId, 'cantidad' => 10], $tokA);
expect_status('Ariza PUT envases balance of existing row', 200, $r);
check('PUT envases set the absolute balance (10)', ce_can($envClId, $retId) === 10, var_export(ce_can($envClId, $retId), true));
expect_status('Ariza PUT envases balance without row (upsert)', 200, http('PUT', 'envases.php', [], [
    'clienteId' => $envClId, 'productoId' => $ret2Id, 'cantidad' => 7,
], $tokA));
$row = db_row('SELECT ce_can, ce_emp FROM sap_ce00 WHERE ce_cli = ? AND ce_pro = ?', 'ii', [$envClId, $ret2Id]);
check('PUT envases inserted the row owned by Ariza', $row && (int) $row['ce_can'] === 7 && (int) $row['ce_emp'] === $arizaId, json_encode($row));
expect_status('Ariza PUT envases balance to 0', 200, http('PUT', 'envases.php', [], [
    'clienteId' => $envClId, 'productoId' => $ret2Id, 'cantidad' => 0,
], $tokA));
check('PUT envases accepts 0', ce_can($envClId, $ret2Id) === 0);
$ceState = json_encode([ce_can($envClId, $retId), ce_can($envClId, $ret2Id), ce_can($envClId, $plainId)]);
foreach ([[-1, 'negative'], ['x', 'non-numeric'], [1.5, 'non-integer']] as $c) {
    expect_status("PUT envases with {$c[1]} cantidad -> 400", 400, http('PUT', 'envases.php', [], [
        'clienteId' => $envClId, 'productoId' => $retId, 'cantidad' => $c[0],
    ], $tokA));
}
expect_status('PUT envases on a non-returnable producto -> 400', 400, http('PUT', 'envases.php', [], [
    'clienteId' => $envClId, 'productoId' => $plainId, 'cantidad' => 1,
], $tokA));
expect_status('PUT envases on an unknown producto -> 404', 404, http('PUT', 'envases.php', [], [
    'clienteId' => $envClId, 'productoId' => 999999, 'cantidad' => 1,
], $tokA));
expect_status('Congre PUT envases on Ariza cliente -> 404', 404, http('PUT', 'envases.php', [], [
    'clienteId' => $envClId, 'productoId' => $retId, 'cantidad' => 1,
], $tokC));
check('Rejected envases PUTs changed nothing', json_encode([ce_can($envClId, $retId), ce_can($envClId, $ret2Id), ce_can($envClId, $plainId)]) === $ceState);

// Soft-deleted cliente: balance neither readable nor writable
db_exec('UPDATE sap_cl00 SET is_deleted = 1 WHERE cl_ide = ?', 'i', [$envClId]);
expect_status('GET envases of a soft-deleted cliente -> 404', 404, http('GET', 'envases.php', ['clienteId' => $envClId], null, $tokA));
expect_status('PUT envases of a soft-deleted cliente -> 404', 404, http('PUT', 'envases.php', [], [
    'clienteId' => $envClId, 'productoId' => $retId, 'cantidad' => 1,
], $tokA));
check('PUT envases of a soft-deleted cliente changed nothing', ce_can($envClId, $retId) === 10);
db_exec('UPDATE sap_cl00 SET is_deleted = 0 WHERE cl_ide = ?', 'i', [$envClId]);

// ---------------------------------------------------------------- summary

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
