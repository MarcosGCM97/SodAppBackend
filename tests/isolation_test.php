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
$maxPg = table_exists('sap_pg00') ? max_id('sap_pg00', 'pg_ide') : 0;
$maxMs = table_exists('sap_ms00') ? max_id('sap_ms00', 'ms_ide') : 0;
$maxRp = table_exists('sap_rp00') ? max_id('sap_rp00', 'rp_ide') : 0;
// plants (ruta) of the two test users: restored at the end; other users' rows are never touched
$plSnapshot = table_exists('sap_pl00') ? db_rows('SELECT * FROM sap_pl00 WHERE pl_emp IN (?, ?)', 'ii', [$arizaId, $congreId]) : null;

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
    global $maxCl, $maxPr, $maxVt, $maxPg, $maxMs, $maxRp, $addedDeletedAt, $plSnapshot, $arizaId, $congreId;
    if ($plSnapshot !== null) {
        db_exec('DELETE FROM sap_pl00 WHERE pl_emp IN (?, ?)', 'ii', [$arizaId, $congreId]);
        foreach ($plSnapshot as $pl) restore_row('sap_pl00', 'pl_emp', $pl);
    }
    // repartos reference clientes and productos (stops and lines cascade): delete them first
    if (table_exists('sap_rp00')) {
        db_exec('DELETE FROM sap_rp00 WHERE rp_ide > ?', 'i', [$maxRp]);
    }
    // payments reference clientes (FK): delete them first
    if (table_exists('sap_pg00')) {
        db_exec('DELETE FROM sap_pg00 WHERE pg_ide > ? OR pg_cli > ?', 'ii', [$maxPg, $maxCl]);
    }
    // container balances and stock movements reference productos (FKs): delete them first
    if (table_exists('sap_ce00')) {
        db_exec('DELETE FROM sap_ce00 WHERE ce_cli > ? OR ce_pro > ?', 'ii', [$maxCl, $maxPr]);
    }
    if (table_exists('sap_ms00')) {
        db_exec('DELETE FROM sap_ms00 WHERE ms_ide > ? OR ms_pro > ?', 'ii', [$maxMs, $maxPr]);
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

// PUT filters soft-deleted productos, not updated_at: a row with updated_at set stays editable
db_exec('UPDATE sap_pr00 SET updated_at = CURDATE() WHERE pr_ide = ?', 'i', [$retId]);
expect_status('Ariza PUT producto with updated_at set', 200, http('PUT', 'productos.php', [
    'nombre' => $retName, 'precio' => 110, 'cantidad' => 20,
], null, $tokA));
check('PUT producto with updated_at set saved the values', (float) pr_row($retId)['pr_val'] === 110.0 && (int) pr_row($retId)['pr_stk'] === 20, json_encode(pr_row($retId)));
db_exec('UPDATE sap_pr00 SET updated_at = NULL, deleted_at = CURDATE() WHERE pr_ide = ?', 'i', [$retId]);
expect_status('PUT soft-deleted producto -> 404', 404, http('PUT', 'productos.php', [
    'nombre' => $retName, 'precio' => 999, 'cantidad' => 99,
], null, $tokA));
check('PUT soft-deleted producto changed nothing', (float) pr_row($retId)['pr_val'] === 110.0 && (int) pr_row($retId)['pr_stk'] === 20, json_encode(pr_row($retId)));
db_exec('UPDATE sap_pr00 SET deleted_at = NULL WHERE pr_ide = ?', 'i', [$retId]);

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
// The sale date is Argentina time whatever date.timezone the server's php.ini sets
$row = db_row('SELECT vt_fec FROM sap_vt00 WHERE vt_ide = ?', 'i', [(int) $vts[0]['vt_ide']]);
$tzAr = new DateTimeZone('America/Argentina/Buenos_Aires');
$skew = $row ? abs((new DateTime($row['vt_fec'], $tzAr))->getTimestamp() - (new DateTime('now', $tzAr))->getTimestamp()) : PHP_INT_MAX;
check('Venta vt_fec is the current Argentina time (within 2 minutes)', $skew <= 120, json_encode($row) . " skew={$skew}s");

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
check('DELETE venta reverted pr_vac (11-6=5) and restored the sold unit to stock', (int) pr_row($retId)['pr_vac'] === 5
    && (int) pr_row($retId)['pr_stk'] === $stockBefore + 1, json_encode(pr_row($retId)));
check('DELETE venta reverted the balance (0 - (1-6) = 5)', ce_can($envClId, $retId) === 5, var_export(ce_can($envClId, $retId), true));
check('DELETE venta removed the line', db_row('SELECT 1 AS x FROM sap_vt00 WHERE vt_ide = ?', 'i', [$returnLineId]) === null);
$vts = ventas_of($envClId);
$plainLineId = isset($vts[1]) ? (int) $vts[1]['vt_ide'] : 0;
$plainLineCan = isset($vts[1]) ? (int) $vts[1]['vt_can'] : 0;
$plainBefore = pr_row($plainId);
$plainBefore['pr_stk'] = (string) ((int) $plainBefore['pr_stk'] + $plainLineCan);
expect_status('Ariza DELETE venta line with vt_dev NULL', 200, http('DELETE', 'ventas.php', ['id' => $plainLineId], null, $tokA));
check('DELETE venta with vt_dev NULL: stock restored, containers and balance unchanged', $plainLineCan > 0
    && pr_row($plainId) == $plainBefore && ce_can($envClId, $plainId) === null && ce_can($envClId, $retId) === 5,
    json_encode([pr_row($plainId), $plainBefore]));
$stockAfterDelete = (int) pr_row($retId)['pr_stk'];
expect_status('DELETE venta already deleted -> 404', 404, http('DELETE', 'ventas.php', ['id' => $returnLineId], null, $tokA));
check('Second DELETE did not revert twice', (int) pr_row($retId)['pr_vac'] === 5 && ce_can($envClId, $retId) === 5
    && (int) pr_row($retId)['pr_stk'] === $stockAfterDelete);

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

// GET productos: pr_cli = containers held by the owner's non-deleted clientes (sum of ce_can)
function productos_by_id($json) {
    $byId = [];
    foreach (list_of($json, 'productos') as $p) $byId[(int) $p['pr_ide']] = $p;
    return $byId;
}
list(, $j) = http('GET', 'productos.php', [], null, $tokA);
$byId = productos_by_id($j);
check('GET productos returns pr_cli with the client balance (10)', isset($byId[$retId]['pr_cli'])
    && (int) $byId[$retId]['pr_cli'] === 10, json_encode($byId[$retId] ?? null));
check('GET productos returns pr_cli 0 for a returnable producto with balance 0', isset($byId[$ret2Id]['pr_cli'])
    && (int) $byId[$ret2Id]['pr_cli'] === 0, json_encode($byId[$ret2Id] ?? null));
check('GET productos returns pr_cli 0 for a non-returnable producto', isset($byId[$plainId]['pr_cli'])
    && (int) $byId[$plainId]['pr_cli'] === 0, json_encode($byId[$plainId] ?? null));
list(, $j) = http('GET', 'productos.php', ['nombre' => $retName], null, $tokA);
check('GET producto by nombre returns pr_cli (10)', isset($j['producto']['pr_cli'])
    && (int) $j['producto']['pr_cli'] === 10, json_encode($j));
db_exec('UPDATE sap_cl00 SET is_deleted = 1 WHERE cl_ide = ?', 'i', [$envClId]);
list(, $j) = http('GET', 'productos.php', [], null, $tokA);
$byId = productos_by_id($j);
check('GET productos pr_cli ignores soft-deleted clientes', isset($byId[$retId]['pr_cli'])
    && (int) $byId[$retId]['pr_cli'] === 0, json_encode($byId[$retId] ?? null));
db_exec('UPDATE sap_cl00 SET is_deleted = 0 WHERE cl_ide = ?', 'i', [$envClId]);

// ---------------------------------------------------------------- 7. stock movement history (historial de stock)

echo "\n# Stock movement history: every stock change is recorded and listed newest first\n";

// [tipo, cantidad, stock, venta] of a producto's movements as the API lists them
function movimientos_of($productoId, $token) {
    list($status, $json) = http('GET', 'movimientos.php', ['productoId' => $productoId], null, $token);
    if ($status !== 200) return null;
    return array_map(function ($m) {
        return [$m['ms_tip'], (int) $m['ms_can'], (int) $m['ms_stk'], $m['ms_vta'] === null ? null : (int) $m['ms_vta']];
    }, list_of($json, 'movimientos'));
}

function ms_count($productoId) {
    return (int) db_row('SELECT COUNT(*) AS n FROM sap_ms00 WHERE ms_pro = ?', 'i', [$productoId])['n'];
}

$histName = MARKER . 'hist_' . $suffix;

// S2 alta: initial stock
expect_status('Ariza POST producto for history', 201, http('POST', 'productos.php', [], [
    'nombrePr' => $histName, 'precioUni' => 100, 'stock' => 10, 'retornable' => true, 'vacios' => 4,
], $tokA));
$histId = pr_id_by_name($histName);
check('POST producto recorded alta +10 (stock 10)', movimientos_of($histId, $tokA) === [['alta', 10, 10, null]], json_encode(movimientos_of($histId, $tokA)));
$row = db_row('SELECT ms_emp, ms_fec FROM sap_ms00 WHERE ms_pro = ?', 'i', [$histId]);
check('Movement is owned by Ariza and dated', $row && (int) $row['ms_emp'] === $arizaId && $row['ms_fec'] !== null, json_encode($row));

// S2 ajuste: new - previous stock; no row when the stock does not change
expect_status('Ariza PUT producto stock 10 -> 12', 200, http('PUT', 'productos.php', [
    'nombre' => $histName, 'precio' => 100, 'cantidad' => 12,
], null, $tokA));
expect_status('Ariza PUT producto price only', 200, http('PUT', 'productos.php', [
    'nombre' => $histName, 'precio' => 120, 'cantidad' => 12,
], null, $tokA));
check('PUT recorded ajuste +2 and no row for an unchanged stock', movimientos_of($histId, $tokA) === [
    ['ajuste', 2, 12, null], ['alta', 10, 10, null],
], json_encode(movimientos_of($histId, $tokA)));
expect_status('Ariza PUT producto stock 12 -> 7', 200, http('PUT', 'productos.php', [
    'nombre' => $histName, 'precio' => 120, 'cantidad' => 7,
], null, $tokA));
check('PUT recorded ajuste -5 (stock 7)', movimientos_of($histId, $tokA)[0] === ['ajuste', -5, 7, null], json_encode(movimientos_of($histId, $tokA)));

// S2 venta: one row per sale line, linked to the sale
expect_status('Ariza POST venta of 3 for history', 201, http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $histName, 'cantidad' => 3, 'precio' => 120, 'devueltos' => 1],
]], $tokA));
$histVtId = (int) db_row('SELECT MAX(vt_ide) AS id FROM sap_vt00 WHERE vt_pro = ? AND vt_emp = ?', 'si', [$histName, $arizaId])['id'];
check('POST venta recorded venta -3 (stock 4) linked to the sale', movimientos_of($histId, $tokA)[0] === ['venta', -3, 4, $histVtId], json_encode(movimientos_of($histId, $tokA)));

// Rejected requests record nothing
$before = ms_count($histId);
expect_status('POST venta with negative devueltos -> 400 (history)', 400, http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $histName, 'cantidad' => 1, 'precio' => 120, 'devueltos' => -1],
]], $tokA));
expect_status('llenar more than the empties -> 409 (history)', 409, http('POST', 'envases.php', [], [
    'accion' => 'llenar', 'producto' => $histName, 'cantidad' => 99,
], $tokA));
expect_status('PUT producto of Congre on Ariza producto -> 404 (history)', 404, http('PUT', 'productos.php', [
    'nombre' => $histName, 'precio' => 1, 'cantidad' => 99,
], null, $tokC));
check('Rejected requests recorded no movement', ms_count($histId) === $before, (string) ms_count($histId));

// S2 llenado and venta eliminada
expect_status('Ariza llenar 2 for history', 200, http('POST', 'envases.php', [], [
    'accion' => 'llenar', 'producto' => $histName, 'cantidad' => 2,
], $tokA));
expect_status('Ariza DELETE venta for history', 200, http('DELETE', 'ventas.php', ['id' => $histVtId], null, $tokA));
check('History lists every movement newest first', movimientos_of($histId, $tokA) === [
    ['venta_eliminada', 3, 9, $histVtId],
    ['llenado', 2, 6, null],
    ['venta', -3, 4, $histVtId],
    ['ajuste', -5, 7, null],
    ['ajuste', 2, 12, null],
    ['alta', 10, 10, null],
], json_encode(movimientos_of($histId, $tokA)));
check('Last movement stock matches pr_stk', (int) pr_row($histId)['pr_stk'] === 9, json_encode(pr_row($histId)));
list(, $j) = http('GET', 'movimientos.php', ['productoId' => $histId], null, $tokA);
$first = list_of($j, 'movimientos')[0] ?? [];
check('GET movimientos returns ms_ide, ms_tip, ms_can, ms_stk, ms_vta, ms_fec',
    array_keys($first) === ['ms_ide', 'ms_tip', 'ms_can', 'ms_stk', 'ms_vta', 'ms_fec'], json_encode($first));

// S3 access: owned, non-deleted producto only
expect_status('Congre GET movimientos of Ariza producto -> 404', 404, http('GET', 'movimientos.php', ['productoId' => $histId], null, $tokC));
expect_status('GET movimientos of an unknown producto -> 404', 404, http('GET', 'movimientos.php', ['productoId' => 999999], null, $tokA));
expect_status('GET movimientos without productoId -> 400', 400, http('GET', 'movimientos.php', [], null, $tokA));
expect_status('GET movimientos without token -> 401', 401, http('GET', 'movimientos.php', ['productoId' => $histId]));
db_exec('UPDATE sap_pr00 SET deleted_at = CURDATE() WHERE pr_ide = ?', 'i', [$histId]);
expect_status('GET movimientos of a soft-deleted producto -> 404', 404, http('GET', 'movimientos.php', ['productoId' => $histId], null, $tokA));
db_exec('UPDATE sap_pr00 SET deleted_at = NULL WHERE pr_ide = ?', 'i', [$histId]);

// Deleting a sale reverts its amount from the debt, even below zero (credit balance)
// The raw DECIMAL(12,2) column as MySQL returns it ("300.00"), so cents are compared exactly
function deuda_of($clienteId) {
    return db_row('SELECT cl_deb FROM sap_cl00 WHERE cl_ide = ?', 'i', [$clienteId])['cl_deb'];
}
function last_venta_of($name, $userId) {
    return (int) db_row('SELECT MAX(vt_ide) AS id FROM sap_vt00 WHERE vt_pro = ? AND vt_emp = ?', 'si', [$name, $userId])['id'];
}
$debtBefore = deuda_of($envClId);
db_exec('UPDATE sap_cl00 SET cl_deb = 0 WHERE cl_ide = ?', 'i', [$envClId]);
expect_status('Ariza POST venta of 1000 for debt revert', 201, http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $histName, 'cantidad' => 2, 'precio' => 500],
]], $tokA));
$debtVtId = last_venta_of($histName, $arizaId);
expect_status('Ariza pays 700 of the 1000', 200, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => 700], null, $tokA));
check('Debt after paying 700 of 1000 is 300', deuda_of($envClId) === '300.00', (string) deuda_of($envClId));

expect_status('Congre DELETE Ariza venta with revertir_deuda -> 404', 404, http('DELETE', 'ventas.php', ['id' => $debtVtId, 'revertir_deuda' => 1], null, $tokC));
check('Rejected DELETE left the debt unchanged', deuda_of($envClId) === '300.00', (string) deuda_of($envClId));

expect_status('Ariza DELETE paid venta with revertir_deuda', 200, http('DELETE', 'ventas.php', ['id' => $debtVtId, 'revertir_deuda' => 1], null, $tokA));
check('Deleting a partly paid venta leaves a credit balance of -700', deuda_of($envClId) === '-700.00', (string) deuda_of($envClId));
list(, $j) = http('GET', 'deudaCliente.php', ['id' => $envClId], null, $tokA);
check('GET deudaCliente returns the credit balance', ($j['deudor']['cl_deb'] ?? null) === '-700.00', json_encode($j));

expect_status('Ariza POST venta of 500 consumes the credit', 201, http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $histName, 'cantidad' => 1, 'precio' => 500],
]], $tokA));
check('Next venta consumes the credit balance (-200)', deuda_of($envClId) === '-200.00', (string) deuda_of($envClId));

// Older app versions revert the debt themselves after the DELETE
expect_status('Ariza DELETE venta without revertir_deuda', 200, http('DELETE', 'ventas.php', ['id' => last_venta_of($histName, $arizaId)], null, $tokA));
check('DELETE without revertir_deuda leaves the debt unchanged', deuda_of($envClId) === '-200.00', (string) deuda_of($envClId));

// Debts keep cents: sales, reverted sales and payments move the exact amount
db_exec('UPDATE sap_cl00 SET cl_deb = 0 WHERE cl_ide = ?', 'i', [$envClId]);
expect_status('Ariza POST venta of 1250.50', 201, http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $histName, 'cantidad' => 1, 'precio' => 1250.5],
]], $tokA));
check('A venta of 1250.50 adds exactly 1250.50 to the debt', deuda_of($envClId) === '1250.50', (string) deuda_of($envClId));
$centsVtId = last_venta_of($histName, $arizaId);
// 3 * 0.1 is 0.30000000000000004 in floating point
expect_status('Ariza POST venta of 3 x 0.10', 201, http('POST', 'ventas.php', [], ['clienteId' => $envClId, 'productos' => [
    ['nombre' => $histName, 'cantidad' => 3, 'precio' => 0.1],
]], $tokA));
check('A venta of 3 x 0.10 adds exactly 0.30 to the debt', deuda_of($envClId) === '1250.80', (string) deuda_of($envClId));
expect_status('Ariza DELETE the 1250.50 venta with revertir_deuda', 200, http('DELETE', 'ventas.php', ['id' => $centsVtId, 'revertir_deuda' => 1], null, $tokA));
check('Deleting the 1250.50 venta subtracts exactly 1250.50', deuda_of($envClId) === '0.30', (string) deuda_of($envClId));

$centsPg = (int) db_row('SELECT COALESCE(MAX(pg_ide), 0) AS id FROM sap_pg00')['id'];
list($st, , $raw) = http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => '0.004'], null, $tokA);
check('PUT deuda that rounds to 0.00 -> 400', $st === 400
    && (json_decode($raw, true)['error'] ?? null) === 'La deuda debe ser mayor a 0', "$st $raw");
check('PUT deuda that rounds to 0.00 left the debt unchanged', deuda_of($envClId) === '0.30', (string) deuda_of($envClId));
expect_status('Ariza pays exactly the whole 0.30 debt', 200, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => '0.3'], null, $tokA));
check('Paying exactly the whole debt with cents leaves 0.00', deuda_of($envClId) === '0.00', (string) deuda_of($envClId));
check('Only the accepted cents payment was stored, with its exact amount',
    array_column(db_rows('SELECT pg_mon FROM sap_pg00 WHERE pg_ide > ? ORDER BY pg_ide', 'i', [$centsPg]), 'pg_mon') === ['0.30'],
    json_encode(db_rows('SELECT pg_mon FROM sap_pg00 WHERE pg_ide > ?', 'i', [$centsPg])));
db_exec('UPDATE sap_cl00 SET cl_deb = ? WHERE cl_ide = ?', 'si', [$debtBefore, $envClId]);

// ---------------------------------------------------------------- 8. payments (formas de pago)

// Earlier sections pay this cliente's debt too (each PUT deuda stores a payment): start from none
db_exec('DELETE FROM sap_pg00 WHERE pg_cli = ? AND pg_ide > ?', 'ii', [$envClId, $maxPg]);

function payments_of($clienteId) {
    $rows = db_rows('SELECT pg_cli, pg_mon, pg_fp, pg_emp FROM sap_pg00 WHERE pg_cli = ? AND pg_ide > ? ORDER BY pg_ide',
        'ii', [$clienteId, $GLOBALS['maxPg']]);
    return array_map(function ($p) {
        return ['pg_cli' => (int) $p['pg_cli'], 'pg_mon' => $p['pg_mon'], 'pg_fp' => (int) $p['pg_fp'], 'pg_emp' => (int) $p['pg_emp']];
    }, $rows);
}
// Bound as a string so a decimal debt is restored exactly
function set_debt($clienteId, $deuda) {
    db_exec('UPDATE sap_cl00 SET cl_deb = ? WHERE cl_ide = ?', 'si', [$deuda, $clienteId]);
}
function debt_of($clienteId) {
    return deuda_of($clienteId);
}
// Congre's cliente of section 3 was deleted in section 5: create a fresh one
$pagoClName = MARKER . 'pagocli_' . substr(md5(uniqid('', true)), 0, 6);
expect_status('Congre creates cliente for payments', 201, http('POST', 'clientes.php', [], [
    'nombreCl' => $pagoClName, 'numTelCl' => '351000001', 'direccionCl' => 'Calle test 2',
], $tokC));
$pagoClC = (int) (db_row('SELECT cl_ide FROM sap_cl00 WHERE cl_nom = ?', 's', [$pagoClName])['cl_ide'] ?? 0);
$payDebtA = debt_of($envClId);
$payDebtC = debt_of($pagoClC);

// S2 selectable methods
list($st, $j) = http('GET', 'pagos.php', ['formas' => 1], null, $tokA);
check('GET formas lists Efectivo and Transferencia only', $st === 200
    && array_column(list_of($j, 'formas'), 'fp_nom') === ['Efectivo', 'Transferencia'], json_encode($j));
check('GET formas returns fp_ide and fp_nom', array_keys(list_of($j, 'formas')[0] ?? []) === ['fp_ide', 'fp_nom'], json_encode($j));
$efectivo = 2;
$transferencia = 3;

// S4 an accepted payment is stored with its method in the same transaction
set_debt($envClId, 1000);
list($st, , $raw) = http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => '300.5', 'formaPago' => $efectivo], null, $tokA);
check('PUT deuda with formaPago keeps the same response body',
    $st === 200 && json_decode($raw, true) === ['success' => true, 'message' => 'Deuda actualizada correctamente'], "$st $raw");
check('PUT deuda with formaPago stored the payment',
    payments_of($envClId) === [['pg_cli' => $envClId, 'pg_mon' => '300.50', 'pg_fp' => $efectivo, 'pg_emp' => $arizaId]],
    json_encode(payments_of($envClId)));
check('PUT deuda with formaPago lowered the balance by 300.50', debt_of($envClId) === '699.50', (string) debt_of($envClId));

// S4 old app versions send no method: stored as Sin especificar
expect_status('PUT deuda without formaPago (old app)', 200, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => 100], null, $tokA));
check('PUT deuda without formaPago stored Sin especificar', (payments_of($envClId)[1]['pg_fp'] ?? null) === 1, json_encode(payments_of($envClId)));

// S4 rejected payments store nothing and leave the balance unchanged
$before = count(payments_of($envClId));
expect_status('PUT deuda with an unknown formaPago -> 400', 400, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => 10, 'formaPago' => 999], null, $tokA));
expect_status('PUT deuda with the non-selectable Sin especificar -> 400', 400, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => 10, 'formaPago' => 1], null, $tokA));
expect_status('PUT deuda with a non-numeric formaPago -> 400', 400, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => 10, 'formaPago' => 'x'], null, $tokA));
expect_status('PUT deuda larger than the balance with formaPago -> 409', 409, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => 5000, 'formaPago' => $efectivo], null, $tokA));
expect_status('Congre PUT deuda of Ariza cliente with formaPago -> 404', 404, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => 10, 'formaPago' => $efectivo], null, $tokC));
check('Rejected payments stored nothing', count(payments_of($envClId)) === $before, json_encode(payments_of($envClId)));
check('Rejected payments left the balance unchanged', debt_of($envClId) === '599.50', (string) debt_of($envClId));

// S5 payment history per client, newest first
expect_status('PUT deuda with Transferencia', 200, http('PUT', 'deudaCliente.php', ['id' => $envClId, 'deuda' => 50, 'formaPago' => $transferencia], null, $tokA));
list($st, $j) = http('GET', 'pagos.php', ['clienteId' => $envClId], null, $tokA);
$hist = list_of($j, 'pagos');
check('GET pagos by cliente lists every payment newest first',
    $st === 200 && array_map(function ($p) { return [$p['pg_mon'], $p['fp_nom']]; }, $hist)
        === [['50.00', 'Transferencia'], ['100.00', 'Sin especificar'], ['300.50', 'Efectivo']], json_encode($j));
check('GET pagos by cliente returns pg_ide, pg_mon, fp_nom, pg_fec', array_keys($hist[0] ?? []) === ['pg_ide', 'pg_mon', 'fp_nom', 'pg_fec'], json_encode($hist[0] ?? []));

// S6 isolation
expect_status('Congre GET pagos of Ariza cliente -> 404', 404, http('GET', 'pagos.php', ['clienteId' => $envClId], null, $tokC));
expect_status('GET pagos of an unknown cliente -> 404', 404, http('GET', 'pagos.php', ['clienteId' => 999999], null, $tokA));
expect_status('GET pagos without token -> 401', 401, http('GET', 'pagos.php', ['clienteId' => $envClId]));
expect_status('GET pagos without parameters -> 400', 400, http('GET', 'pagos.php', [], null, $tokA));
expect_status('POST pagos -> 405', 405, http('POST', 'pagos.php', [], ['x' => 1], $tokA));

// S5 monthly totals per method, only the owner's payments
set_debt($pagoClC, 100);
expect_status('Congre PUT deuda with Efectivo', 200, http('PUT', 'deudaCliente.php', ['id' => $pagoClC, 'deuda' => 40, 'formaPago' => $efectivo], null, $tokC));
$mesActual = (int) date('n');
$anoActual = (int) date('Y');
$totales = function ($token) use ($mesActual, $anoActual) {
    list($st, $j) = http('GET', 'pagos.php', ['mes' => $mesActual, 'ano' => $anoActual], null, $token);
    $out = [];
    foreach (list_of($j, 'totales') as $t) $out[$t['fp_nom']] = $t['total'];
    return [$st, $out];
};
// Ariza may have earlier payments this month: compare against the DB, not fixed numbers
$expectedA = [];
foreach (db_rows('SELECT fp.fp_nom, SUM(pg.pg_mon) AS total FROM sap_pg00 pg JOIN sap_fp00 fp ON fp.fp_ide = pg.pg_fp
        WHERE pg.pg_emp = ? AND YEAR(pg.pg_fec) = ? AND MONTH(pg.pg_fec) = ? GROUP BY fp.fp_ide, fp.fp_nom ORDER BY fp.fp_ide',
        'iii', [$arizaId, $anoActual, $mesActual]) as $t) $expectedA[$t['fp_nom']] = $t['total'];
list($st, $gotA) = $totales($tokA);
check('GET pagos monthly totals per method for Ariza', $st === 200 && $gotA && $gotA === $expectedA, json_encode($gotA) . ' vs ' . json_encode($expectedA));
list($st, $gotC) = $totales($tokC);
check('Congre monthly totals include only his payments', $st === 200 && ($gotC['Efectivo'] ?? null) === '40.00'
    && !isset($gotC['Transferencia']), json_encode($gotC));
expect_status('GET pagos with an invalid mes -> 400', 400, http('GET', 'pagos.php', ['mes' => 13], null, $tokA));
expect_status('GET pagos with an invalid ano -> 400', 400, http('GET', 'pagos.php', ['mes' => 1, 'ano' => '20x6'], null, $tokA));
set_debt($envClId, $payDebtA);
set_debt($pagoClC, $payDebtC);

// ---------------------------------------------------------------- 9. repartos (armado de repartos)

echo "\n# Repartos: build, edit and order the deliveries of a date\n";

// Congre owns the repartos here (Ariza attacks), so a template Ariza saves on stage is never touched.
// Dates are far in the future: 2099-03-02 is a Monday.
const RP_LUNES_1 = '2099-03-02';
const RP_LUNES_2 = '2099-03-09';
const RP_LUNES_3 = '2099-03-16';
const RP_LUNES_4 = '2099-03-23';
const RP_MARTES = '2099-03-03';

function rp_post($body, $token) {
    return http('POST', 'repartos.php', [], $body, $token);
}

// [cliente id, estado, [[producto id, planned, price], ...]] per stop, in list order
function rp_shape($reparto) {
    return array_map(function ($s) {
        return [(int) $s['cl_ide'], $s['rs_est'], array_map(function ($l) {
            return [(int) $l['pr_ide'], (int) $l['rl_cpl'], $l['rl_pre']];
        }, $s['lineas'])];
    }, $reparto['paradas'] ?? []);
}

function rp_count() {
    return (int) db_row('SELECT COUNT(*) AS n FROM sap_rp00')['n'];
}

check('2099-03-02 is a Monday', date('N', strtotime(RP_LUNES_1)) === '1');

// Congre's fixtures: two Monday clientes, one without delivery days, one deleted Monday cliente,
// a returnable and a plain producto
$rpCl = [];
foreach (['a', 'b', 'c', 'd'] as $k) {
    expect_status("Congre creates cliente $k for repartos", 201, http('POST', 'clientes.php', [], [
        'nombreCl' => MARKER . "rp_{$k}_$suffix", 'numTelCl' => '351000009', 'direccionCl' => "Calle reparto $k",
    ], $tokC));
    $row = db_row('SELECT cl_ide FROM sap_cl00 WHERE cl_nom = ? AND cl_ide > ?', 'si', [MARKER . "rp_{$k}_$suffix", $maxCl]);
    $rpCl[$k] = $row ? (int) $row['cl_ide'] : 0;
}
set_cd_days($rpCl['a'], [1, 4]);
set_cd_days($rpCl['b'], [1]);
set_cd_days($rpCl['c'], []);
set_cd_days($rpCl['d'], [1]);
db_exec('UPDATE sap_cl00 SET is_deleted = 1 WHERE cl_ide = ?', 'i', [$rpCl['d']]);

$rpRetName = MARKER . 'rpret_' . $suffix;
$rpPlainName = MARKER . 'rpplain_' . $suffix;
expect_status('Congre creates returnable producto for repartos', 201, http('POST', 'productos.php', [], [
    'nombrePr' => $rpRetName, 'precioUni' => 1000, 'stock' => 20, 'retornable' => true, 'vacios' => 0,
], $tokC));
expect_status('Congre creates plain producto for repartos', 201, http('POST', 'productos.php', [], [
    'nombrePr' => $rpPlainName, 'precioUni' => 300, 'stock' => 20,
], $tokC));
$rpRet = pr_id_by_name($rpRetName);
$rpPlain = pr_id_by_name($rpPlainName);

// A1 create for a concrete date; the weekday comes from the date; ownership
$r = rp_post(['accion' => 'crear', 'fecha' => RP_LUNES_1, 'origen' => 'vacio'], $tokC);
expect_status('Congre creates an empty reparto for 2099-03-02', 201, $r);
$rp1 = $r[1]['reparto'] ?? [];
$rp1Id = (int) ($rp1['rp_ide'] ?? 0);
check('Created reparto has rp_fec 2099-03-02, rp_dia 1 (Lunes) and no stops', ($rp1['rp_fec'] ?? null) === RP_LUNES_1
    && (int) ($rp1['rp_dia'] ?? 0) === 1 && ($rp1['paradas'] ?? null) === [], short($r[2]));
expect_status('Creating a second reparto for the same date -> 409', 409, rp_post(['accion' => 'crear', 'fecha' => RP_LUNES_1, 'origen' => 'vacio'], $tokC));
expect_status('Create with an invalid fecha -> 400', 400, rp_post(['accion' => 'crear', 'fecha' => '2099-02-30', 'origen' => 'vacio'], $tokC));
expect_status('Create with an unknown origen -> 400', 400, rp_post(['accion' => 'crear', 'fecha' => RP_MARTES, 'origen' => 'otro'], $tokC));
expect_status('Unknown accion -> 400', 400, rp_post(['accion' => 'borrar_todo'], $tokC));
expect_status('POST repartos without token -> 401', 401, rp_post(['accion' => 'crear', 'fecha' => RP_MARTES, 'origen' => 'vacio'], null));
$r = http('GET', 'repartos.php', ['fecha' => RP_LUNES_1], null, $tokC);
check('GET by fecha returns the reparto', $r[0] === 200 && (int) ($r[1]['reparto']['rp_ide'] ?? 0) === $rp1Id, short($r[2]));
expect_status('Ariza GET Congre reparto by id -> 404', 404, http('GET', 'repartos.php', ['id' => $rp1Id], null, $tokA));
expect_status('Ariza GET by the same fecha -> 404 (her own, none)', 404, http('GET', 'repartos.php', ['fecha' => RP_LUNES_1], null, $tokA));
expect_status('GET without id or fecha -> 400', 400, http('GET', 'repartos.php', [], null, $tokC));

// A5 add stops by hand, any cliente of the owner, once per reparto
expect_status('Add cliente a (Monday) to reparto 1', 201, rp_post(['accion' => 'agregar_parada', 'repartoId' => $rp1Id, 'clienteId' => $rpCl['a']], $tokC));
expect_status('Add cliente c (no delivery day) to reparto 1', 201, rp_post(['accion' => 'agregar_parada', 'repartoId' => $rp1Id, 'clienteId' => $rpCl['c']], $tokC));
expect_status('Add cliente b to reparto 1', 201, rp_post(['accion' => 'agregar_parada', 'repartoId' => $rp1Id, 'clienteId' => $rpCl['b']], $tokC));
expect_status('Adding the same cliente twice -> 409', 409, rp_post(['accion' => 'agregar_parada', 'repartoId' => $rp1Id, 'clienteId' => $rpCl['a']], $tokC));
expect_status('Adding a deleted cliente -> 404', 404, rp_post(['accion' => 'agregar_parada', 'repartoId' => $rp1Id, 'clienteId' => $rpCl['d']], $tokC));
expect_status('Adding an Ariza cliente to a Congre reparto -> 404', 404, rp_post(['accion' => 'agregar_parada', 'repartoId' => $rp1Id, 'clienteId' => $envClId], $tokC));
expect_status('Ariza adding to a Congre reparto -> 404', 404, rp_post(['accion' => 'agregar_parada', 'repartoId' => $rp1Id, 'clienteId' => $envClId], $tokA));
$rp1 = http('GET', 'repartos.php', ['id' => $rp1Id], null, $tokC)[1]['reparto'] ?? [];
check('Stops are listed in the order they were added', rp_shape($rp1) === [
    [$rpCl['a'], 'pendiente', []], [$rpCl['c'], 'pendiente', []], [$rpCl['b'], 'pendiente', []],
], json_encode(rp_shape($rp1)));
$first = $rp1['paradas'][0] ?? [];
check('Each stop returns rs_ide, rs_ord, rs_est, cl_ide, cl_nom, cl_dir, cl_tel, lineas',
    array_keys($first) === ['rs_ide', 'rs_ord', 'rs_est', 'cl_ide', 'cl_nom', 'cl_dir', 'cl_tel', 'lineas']
    && $first['cl_dir'] === 'Calle reparto a', json_encode($first));
$stopId = [];
foreach ($rp1['paradas'] ?? [] as $s) $stopId[(int) $s['cl_ide']] = (int) $s['rs_ide'];

// S4 planned lines: price defaults to pr_val, replaced as a whole
$r = rp_post(['accion' => 'guardar_lineas', 'paradaId' => $stopId[$rpCl['a']], 'lineas' => [
    ['productoId' => $rpRet, 'cantidad' => 3], ['productoId' => $rpPlain, 'cantidad' => 2, 'precio' => 250],
]], $tokC);
expect_status('Save the planned lines of stop a', 200, $r);
expect_status('Save the planned lines of stop b', 200, rp_post(['accion' => 'guardar_lineas', 'paradaId' => $stopId[$rpCl['b']], 'lineas' => [
    ['productoId' => $rpRet, 'cantidad' => 1],
]], $tokC));
$before = rp_shape(http('GET', 'repartos.php', ['id' => $rp1Id], null, $tokC)[1]['reparto'] ?? []);
check('Lines keep planned quantity; price defaults to pr_val or takes the sent one', $before === [
    [$rpCl['a'], 'pendiente', [[$rpRet, 3, '1000.00'], [$rpPlain, 2, '250.00']]],
    [$rpCl['c'], 'pendiente', []],
    [$rpCl['b'], 'pendiente', [[$rpRet, 1, '1000.00']]],
], json_encode($before));
$l = http('GET', 'repartos.php', ['id' => $rp1Id], null, $tokC)[1]['reparto']['paradas'][0]['lineas'][0] ?? [];
check('Each line returns rl_ide, pr_ide, pr_nom, pr_ret, rl_cpl, rl_cen, rl_pre, rl_dev, rl_vt; undelivered rl_cen null',
    array_keys($l) === ['rl_ide', 'pr_ide', 'pr_nom', 'pr_ret', 'rl_cpl', 'rl_cen', 'rl_pre', 'rl_dev', 'rl_vt']
    && $l['rl_cen'] === null && $l['rl_vt'] === null, json_encode($l));
foreach ([
    'zero quantity' => [['productoId' => $rpRet, 'cantidad' => 0]],
    'negative price' => [['productoId' => $rpRet, 'cantidad' => 1, 'precio' => -5]],
    'repeated producto' => [['productoId' => $rpRet, 'cantidad' => 1], ['productoId' => $rpRet, 'cantidad' => 2]],
] as $case => $lineas) {
    expect_status("Save lines with $case -> 400", 400, rp_post(['accion' => 'guardar_lineas', 'paradaId' => $stopId[$rpCl['a']], 'lineas' => $lineas], $tokC));
}
expect_status('Save lines with an Ariza producto -> 404', 404, rp_post(['accion' => 'guardar_lineas', 'paradaId' => $stopId[$rpCl['a']], 'lineas' => [
    ['productoId' => $retId, 'cantidad' => 1],
]], $tokC));
expect_status('Ariza saving lines of a Congre stop -> 404', 404, rp_post(['accion' => 'guardar_lineas', 'paradaId' => $stopId[$rpCl['a']], 'lineas' => [
    ['productoId' => $retId, 'cantidad' => 1],
]], $tokA));
check('Rejected line saves changed nothing', rp_shape(http('GET', 'repartos.php', ['id' => $rp1Id], null, $tokC)[1]['reparto'] ?? []) === $before);

// A6 reorder persists
expect_status('Reorder stops b, a, c', 200, rp_post(['accion' => 'ordenar', 'repartoId' => $rp1Id,
    'paradas' => [$stopId[$rpCl['b']], $stopId[$rpCl['a']], $stopId[$rpCl['c']]]], $tokC));
$rp1 = http('GET', 'repartos.php', ['id' => $rp1Id], null, $tokC)[1]['reparto'] ?? [];
check('GET after reorder lists b, a, c with rs_ord 1..3', array_map(function ($s) { return [(int) $s['cl_ide'], (int) $s['rs_ord']]; }, $rp1['paradas'] ?? [])
    === [[$rpCl['b'], 1], [$rpCl['a'], 2], [$rpCl['c'], 3]], json_encode($rp1['paradas'] ?? []));
expect_status('Reorder missing a stop -> 400', 400, rp_post(['accion' => 'ordenar', 'repartoId' => $rp1Id,
    'paradas' => [$stopId[$rpCl['a']], $stopId[$rpCl['b']]]], $tokC));
expect_status('Ariza reordering a Congre reparto -> 404', 404, rp_post(['accion' => 'ordenar', 'repartoId' => $rp1Id,
    'paradas' => [$stopId[$rpCl['a']], $stopId[$rpCl['b']], $stopId[$rpCl['c']]]], $tokA));

// A5 only pendiente stops can be edited or removed
db_exec("UPDATE sap_rs00 SET rs_est = 'entregada' WHERE rs_ide = ?", 'i', [$stopId[$rpCl['b']]]);
expect_status('Saving lines of an entregada stop -> 409', 409, rp_post(['accion' => 'guardar_lineas', 'paradaId' => $stopId[$rpCl['b']], 'lineas' => [
    ['productoId' => $rpRet, 'cantidad' => 9],
]], $tokC));
expect_status('Removing an entregada stop -> 409', 409, rp_post(['accion' => 'quitar_parada', 'paradaId' => $stopId[$rpCl['b']]], $tokC));
db_exec("UPDATE sap_rs00 SET rs_est = 'pendiente' WHERE rs_ide = ?", 'i', [$stopId[$rpCl['b']]]);
expect_status('Ariza removing a Congre stop -> 404', 404, rp_post(['accion' => 'quitar_parada', 'paradaId' => $stopId[$rpCl['c']]], $tokA));
expect_status('Remove pendiente stop c', 200, rp_post(['accion' => 'quitar_parada', 'paradaId' => $stopId[$rpCl['c']]], $tokC));
$rp1Shape = rp_shape(http('GET', 'repartos.php', ['id' => $rp1Id], null, $tokC)[1]['reparto'] ?? []);
check('Removed stop is gone, the others keep their lines', $rp1Shape === [
    [$rpCl['b'], 'pendiente', [[$rpRet, 1, '1000.00']]],
    [$rpCl['a'], 'pendiente', [[$rpRet, 3, '1000.00'], [$rpPlain, 2, '250.00']]],
], json_encode($rp1Shape));

// A4 from delivery days: Monday clientes, deleted ones skipped, no lines
$r = rp_post(['accion' => 'crear', 'fecha' => RP_LUNES_2, 'origen' => 'dias'], $tokC);
expect_status('Create 2099-03-09 from delivery days', 201, $r);
check('From delivery days: clientes a and b, no lines, deleted d skipped', rp_shape($r[1]['reparto'] ?? []) === [
    [$rpCl['a'], 'pendiente', []], [$rpCl['b'], 'pendiente', []],
], short($r[2]));

// A2 template: save reparto 1 as the Monday template, create from it with current prices
expect_status('Ariza saving a Congre reparto as template -> 404', 404, rp_post(['accion' => 'guardar_plantilla', 'repartoId' => $rp1Id], $tokA));
expect_status('Save reparto 1 as the Monday template', 200, rp_post(['accion' => 'guardar_plantilla', 'repartoId' => $rp1Id], $tokC));
expect_status('Save it again (replaces the template)', 200, rp_post(['accion' => 'guardar_plantilla', 'repartoId' => $rp1Id], $tokC));
check('One Monday template per user', (int) db_row('SELECT COUNT(*) AS n FROM sap_rp00 WHERE rp_emp = ? AND rp_fec IS NULL AND rp_dia = 1', 'i', [$congreId])['n'] === 1);
db_exec('UPDATE sap_pr00 SET pr_val = 1100 WHERE pr_ide = ?', 'i', [$rpRet]);
db_exec("UPDATE sap_rs00 SET rs_est = 'entregada' WHERE rs_rp = ?", 'i', [$rp1Id]);
db_exec('UPDATE sap_rl00 l JOIN sap_rs00 s ON s.rs_ide = l.rl_rs SET l.rl_cen = l.rl_cpl WHERE s.rs_rp = ?', 'i', [$rp1Id]);
$r = rp_post(['accion' => 'crear', 'fecha' => RP_LUNES_3, 'origen' => 'plantilla'], $tokC);
expect_status('Create 2099-03-16 from the Monday template', 201, $r);
$rp3Shape = rp_shape($r[1]['reparto'] ?? []);
check('From template: same stops, order, products and planned quantities, all pendiente, current prices', $rp3Shape === [
    [$rpCl['b'], 'pendiente', [[$rpRet, 1, '1100.00']]],
    [$rpCl['a'], 'pendiente', [[$rpRet, 3, '1100.00'], [$rpPlain, 2, '300.00']]],
], json_encode($rp3Shape));
$cen = array_column(array_merge(...array_column($r[1]['reparto']['paradas'] ?? [['lineas' => []]], 'lineas')), 'rl_cen');
check('From template: delivered quantities empty', $cen !== [] && $cen === array_fill(0, count($cen), null), json_encode($cen));
$n = rp_count();
expect_status('Create a Tuesday from a template that does not exist -> 404', 404, rp_post(['accion' => 'crear', 'fecha' => RP_MARTES, 'origen' => 'plantilla'], $tokC));

// A3 copy the previous reparto of the same weekday
$r = rp_post(['accion' => 'crear', 'fecha' => RP_LUNES_4, 'origen' => 'anterior'], $tokC);
expect_status('Create 2099-03-23 copying the previous Monday', 201, $r);
check('Copy-previous takes the latest earlier Monday (2099-03-16)', rp_shape($r[1]['reparto'] ?? []) === $rp3Shape, short($r[2]));
expect_status('Copy-previous for a Tuesday with no earlier Tuesday -> 404', 404, rp_post(['accion' => 'crear', 'fecha' => RP_MARTES, 'origen' => 'anterior'], $tokC));
check('Failed creations created nothing', rp_count() === $n + 1, (string) rp_count());
// Ariza never gets Congre's template: 404 without a Monday template of her own, or her own
// template (stage data may hold one) with none of Congre's clients
$arizaTpl = (int) db_row('SELECT COUNT(*) AS n FROM sap_rp00 WHERE rp_emp = ? AND rp_fec IS NULL AND rp_dia = 1', 'i', [$arizaId])['n'];
$r = rp_post(['accion' => 'crear', 'fecha' => RP_LUNES_4, 'origen' => 'plantilla'], $tokA);
if ($arizaTpl === 0) {
    expect_status('Ariza has no Monday template of her own -> 404', 404, $r);
} else {
    expect_status('Ariza creates from her own Monday template', 201, $r);
    check("Ariza's reparto has none of Congre's clients",
        array_intersect(array_column(rp_shape($r[1]['reparto'] ?? []), 0), array_values($rpCl)) === [], short($r[2]));
}

// ---------------------------------------------------------------- 10. repartos delivery (entregar / no entregar)

echo "\n# Repartos delivery: stock and debt move only when a stop is delivered, once\n";

$rpExtraName = MARKER . 'rpextra_' . $suffix;
expect_status('Congre creates a plain producto added at delivery', 201, http('POST', 'productos.php', [], [
    'nombrePr' => $rpExtraName, 'precioUni' => 500, 'stock' => 20,
], $tokC));
$rpExtra = pr_id_by_name($rpExtraName);

$r = rp_post(['accion' => 'crear', 'fecha' => '2099-03-30', 'origen' => 'vacio'], $tokC);
$rp5Id = (int) ($r[1]['reparto']['rp_ide'] ?? 0);
$stop5 = [];
foreach (['a', 'b'] as $k) {
    $stop5[$k] = (int) (rp_post(['accion' => 'agregar_parada', 'repartoId' => $rp5Id, 'clienteId' => $rpCl[$k]], $tokC)[1]['paradaId'] ?? 0);
}
expect_status('Plan stop a: 3 returnable at 1000, 2 plain', 200, rp_post(['accion' => 'guardar_lineas', 'paradaId' => $stop5['a'], 'lineas' => [
    ['productoId' => $rpRet, 'cantidad' => 3, 'precio' => 1000], ['productoId' => $rpPlain, 'cantidad' => 2],
]], $tokC));
expect_status('Plan stop b: 1 returnable', 200, rp_post(['accion' => 'guardar_lineas', 'paradaId' => $stop5['b'], 'lineas' => [
    ['productoId' => $rpRet, 'cantidad' => 1],
]], $tokC));

// Everything a delivery may touch, to prove that rejected or repeated requests change nothing
function rp_state($clienteIds, $productoIds, $repartoId) {
    global $maxVt;
    $in = function ($ids) { return implode(',', array_map('intval', $ids)); };
    return json_encode([
        db_rows('SELECT pr_ide, pr_stk, pr_vac FROM sap_pr00 WHERE pr_ide IN (' . $in($productoIds) . ') ORDER BY pr_ide'),
        db_rows('SELECT cl_ide, cl_deb FROM sap_cl00 WHERE cl_ide IN (' . $in($clienteIds) . ') ORDER BY cl_ide'),
        db_rows('SELECT ce_cli, ce_pro, ce_can FROM sap_ce00 WHERE ce_cli IN (' . $in($clienteIds) . ') ORDER BY ce_cli, ce_pro'),
        db_rows('SELECT COUNT(*) AS n FROM sap_vt00 WHERE vt_ide > ?', 'i', [$maxVt]),
        db_rows('SELECT COUNT(*) AS n FROM sap_ms00 WHERE ms_pro IN (' . $in($productoIds) . ')'),
        db_rows('SELECT s.rs_ide, s.rs_est, l.rl_pro, l.rl_cpl, l.rl_cen, l.rl_pre, l.rl_dev, l.rl_vt FROM sap_rs00 s
            LEFT JOIN sap_rl00 l ON l.rl_rs = s.rs_ide WHERE s.rs_rp = ? ORDER BY s.rs_ide, l.rl_pro', 'i', [$repartoId]),
    ]);
}
$rpClIds = [$rpCl['a'], $rpCl['b']];
$rpPrIds = [$rpRet, $rpPlain, $rpExtra];
$s0 = rp_state($rpClIds, $rpPrIds, $rp5Id);

// Rejected deliveries change nothing
foreach ([
    'a negative quantity' => [400, [['productoId' => $rpRet, 'cantidad' => -1]]],
    'devueltos on a non-returnable producto' => [400, [['productoId' => $rpPlain, 'cantidad' => 1, 'devueltos' => 1]]],
    'negative devueltos' => [400, [['productoId' => $rpRet, 'cantidad' => 1, 'devueltos' => -1]]],
    'nothing delivered' => [400, [['productoId' => $rpRet, 'cantidad' => 0]]],
    'devueltos on a producto delivered 0' => [400, [['productoId' => $rpPlain, 'cantidad' => 1], ['productoId' => $rpRet, 'cantidad' => 0, 'devueltos' => 3]]],
    'a repeated producto' => [400, [['productoId' => $rpRet, 'cantidad' => 1], ['productoId' => $rpRet, 'cantidad' => 1]]],
    'an Ariza producto' => [404, [['productoId' => $retId, 'cantidad' => 1]]],
] as $case => list($status, $lineas)) {
    expect_status("Deliver with $case -> $status", $status, rp_post(['accion' => 'entregar', 'paradaId' => $stop5['a'], 'lineas' => $lineas], $tokC));
}
expect_status('Ariza delivering a Congre stop -> 404', 404, rp_post(['accion' => 'entregar', 'paradaId' => $stop5['a'], 'lineas' => [
    ['productoId' => $rpRet, 'cantidad' => 1],
]], $tokA));
check('Rejected deliveries changed nothing (stock, empties, debt, balances, sales, history, stops)', rp_state($rpClIds, $rpPrIds, $rp5Id) === $s0);

// A7 deliver less than planned, skip a planned producto, add one that was not planned
$stk0 = [$rpRet => (int) pr_row($rpRet)['pr_stk'], $rpPlain => (int) pr_row($rpPlain)['pr_stk'], $rpExtra => (int) pr_row($rpExtra)['pr_stk']];
$vac0 = (int) pr_row($rpRet)['pr_vac'];
$debt0 = cl_debt($rpCl['a']);
$ce0 = ce_can($rpCl['a'], $rpRet);
$r = rp_post(['accion' => 'entregar', 'paradaId' => $stop5['a'], 'lineas' => [
    ['productoId' => $rpRet, 'cantidad' => 2, 'devueltos' => 1], ['productoId' => $rpExtra, 'cantidad' => 1],
]], $tokC);
expect_status('Deliver stop a: 2 of 3 returnable (1 returned), 0 plain, 1 extra', 200, $r);
check('Delivery response returns the delivered amount (2*1000 + 1*500)', ($r[1]['monto'] ?? null) == 2500, short($r[2]));
$ventas = db_rows('SELECT vt_ide, vt_pro, vt_can, vt_mon, vt_dev FROM sap_vt00 WHERE vt_cli = ? AND vt_ide > ? ORDER BY vt_ide', 'ii', [$rpCl['a'], $maxVt]);
check('Sales hold the delivered quantities: returnable 2 at 2000 with 1 returned, extra 1 at 500, no plain sale',
    array_map(function ($v) { return [$v['vt_pro'], (int) $v['vt_can'], (float) $v['vt_mon'], $v['vt_dev'] === null ? null : (int) $v['vt_dev']]; }, $ventas)
    === [[$rpRetName, 2, 2000.0, 1], [$rpExtraName, 1, 500.0, null]], json_encode($ventas));
check('Stock moved by the delivered quantities (-2, 0, -1)', (int) pr_row($rpRet)['pr_stk'] === $stk0[$rpRet] - 2
    && (int) pr_row($rpPlain)['pr_stk'] === $stk0[$rpPlain] && (int) pr_row($rpExtra)['pr_stk'] === $stk0[$rpExtra] - 1);
check('Debt grew by the delivered amount (+2500)', cl_debt($rpCl['a']) === $debt0 + 2500, (string) cl_debt($rpCl['a']));
check('Returnable moved like a sale: empties +1, client balance +1', (int) pr_row($rpRet)['pr_vac'] === $vac0 + 1
    && ce_can($rpCl['a'], $rpRet) === $ce0 + 1);
$vtByPro = array_column($ventas, 'vt_ide', 'vt_pro');
$lineas = db_rows('SELECT rl_pro, rl_cpl, rl_cen, rl_pre, rl_dev, rl_vt FROM sap_rl00 WHERE rl_rs = ? ORDER BY rl_pro', 'i', [$stop5['a']]);
check('Lines keep planned and delivered quantities and link their sale; the added producto has planned 0',
    array_map(function ($l) { return [(int) $l['rl_pro'], (int) $l['rl_cpl'], (int) $l['rl_cen'], $l['rl_pre'], $l['rl_dev'] === null ? null : (int) $l['rl_dev'], $l['rl_vt'] === null ? null : (int) $l['rl_vt']]; }, $lineas)
    === [[$rpRet, 3, 2, '1000.00', 1, (int) $vtByPro[$rpRetName]], [$rpPlain, 2, 0, '300.00', null, null], [$rpExtra, 0, 1, '500.00', null, (int) $vtByPro[$rpExtraName]]],
    json_encode($lineas));
check('Stop a is entregada', db_row('SELECT rs_est FROM sap_rs00 WHERE rs_ide = ?', 'i', [$stop5['a']])['rs_est'] === 'entregada');
check('Each delivered line recorded a venta stock movement', db_row("SELECT COUNT(*) AS n FROM sap_ms00 WHERE ms_tip = 'venta' AND ms_vta IN (?, ?)", 'ii',
    [(int) $vtByPro[$rpRetName], (int) $vtByPro[$rpExtraName]])['n'] == 2);

// A8 a delivered stop is final: delivering again changes nothing
$s1 = rp_state($rpClIds, $rpPrIds, $rp5Id);
expect_status('Delivering stop a again -> 409', 409, rp_post(['accion' => 'entregar', 'paradaId' => $stop5['a'], 'lineas' => [
    ['productoId' => $rpRet, 'cantidad' => 2],
]], $tokC));
expect_status('Marking an entregada stop no entregada -> 409', 409, rp_post(['accion' => 'no_entregar', 'paradaId' => $stop5['a']], $tokC));
expect_status('Reopening an entregada stop -> 409', 409, rp_post(['accion' => 'reabrir', 'paradaId' => $stop5['a']], $tokC));
check('Repeated delivery changed nothing', rp_state($rpClIds, $rpPrIds, $rp5Id) === $s1);

// A8 no entregada touches nothing; it can be reopened (Q7) and then delivered as planned
expect_status('Ariza marking a Congre stop no entregada -> 404', 404, rp_post(['accion' => 'no_entregar', 'paradaId' => $stop5['b']], $tokA));
expect_status('Mark stop b no entregada', 200, rp_post(['accion' => 'no_entregar', 'paradaId' => $stop5['b']], $tokC));
$s2 = rp_state($rpClIds, $rpPrIds, $rp5Id);
check('No entregada changed only the stop status', str_replace('"no_entregada"', '"pendiente"', $s2) === $s1, $s2);
expect_status('Delivering a no entregada stop -> 409', 409, rp_post(['accion' => 'entregar', 'paradaId' => $stop5['b']], $tokC));
expect_status('Reopen stop b', 200, rp_post(['accion' => 'reabrir', 'paradaId' => $stop5['b']], $tokC));
check('Reopened stop b is pendiente', db_row('SELECT rs_est FROM sap_rs00 WHERE rs_ide = ?', 'i', [$stop5['b']])['rs_est'] === 'pendiente');
expect_status('Reopening a pendiente stop -> 409', 409, rp_post(['accion' => 'reabrir', 'paradaId' => $stop5['b']], $tokC));
$debtB = cl_debt($rpCl['b']);
$r = rp_post(['accion' => 'entregar', 'paradaId' => $stop5['b']], $tokC);
expect_status('Deliver stop b without lineas delivers the plan', 200, $r);
check('Delivering the plan of stop b: 1 returnable at 1100, debt +1100', ($r[1]['monto'] ?? null) == 1100
    && cl_debt($rpCl['b']) === $debtB + 1100, short($r[2]));
$lineaB = db_row('SELECT rl_cpl, rl_cen, rl_dev FROM sap_rl00 WHERE rl_rs = ?', 'i', [$stop5['b']]);
check('Plan delivery stores rl_cen = rl_cpl and 0 returned', (int) $lineaB['rl_cen'] === 1 && (int) $lineaB['rl_dev'] === 0, json_encode($lineaB));
expect_status('Removing an entregada stop is still rejected -> 409', 409, rp_post(['accion' => 'quitar_parada', 'paradaId' => $stop5['b']], $tokC));

// Copies keep the plan only: a producto added at delivery (planned 0) is not carried over
$r = rp_post(['accion' => 'crear', 'fecha' => '2099-04-06', 'origen' => 'anterior'], $tokC);
expect_status('Create 2099-04-06 copying the delivered 2099-03-30', 201, $r);
check('Copy carries planned lines only, not the producto added at delivery', rp_shape($r[1]['reparto'] ?? []) === [
    [$rpCl['a'], 'pendiente', [[$rpRet, 3, '1100.00'], [$rpPlain, 2, '300.00']]],
    [$rpCl['b'], 'pendiente', [[$rpRet, 1, '1100.00']]],
], short($r[2]));

// ---------------------------------------------------------------- 11. ruta google maps

echo "\n# Ruta: client locality and coordinates, plant, trip times\n";

function cl_geo($clienteId) {
    return db_row('SELECT cl_loc, cl_lat, cl_lng FROM sap_cl00 WHERE cl_ide = ?', 'i', [$clienteId]);
}

function ruta_post($body, $token) {
    return http('POST', 'ruta.php', [], $body, $token);
}

// T2 POST: localidadCl, latitudCl and longitudCl are stored
$geoName = MARKER . 'geo_' . $suffix;
expect_status('Congre creates a cliente with locality and coordinates', 201, http('POST', 'clientes.php', [], [
    'nombreCl' => $geoName, 'numTelCl' => '351000011', 'direccionCl' => 'San Martin 120',
    'localidadCl' => 'La Calera', 'latitudCl' => -31.343512, 'longitudCl' => -64.335871,
], $tokC));
$geoId = (int) (db_row('SELECT cl_ide FROM sap_cl00 WHERE cl_nom = ? AND cl_ide > ?', 'si', [$geoName, $maxCl])['cl_ide'] ?? 0);
check('POST stores locality and coordinates', cl_geo($geoId) == ['cl_loc' => 'La Calera', 'cl_lat' => '-31.343512', 'cl_lng' => '-64.335871'],
    json_encode(cl_geo($geoId)));

// T2 GET by id and list return cl_loc, cl_lat and cl_lng
$r = http('GET', 'clientes.php', ['id' => $geoId], null, $tokC);
check('GET by id returns locality and coordinates', ($r[1]['cliente']['cl_loc'] ?? null) === 'La Calera'
    && ($r[1]['cliente']['cl_lat'] ?? null) == -31.343512 && ($r[1]['cliente']['cl_lng'] ?? null) == -64.335871, short($r[2]));
$r = http('GET', 'clientes.php', [], null, $tokC);
$listed = array_values(array_filter(list_of($r[1], 'clientes'), function ($c) use ($geoId) { return (int) $c['cl_ide'] === $geoId; }));
check('GET list returns locality and coordinates', $listed && $listed[0]['cl_loc'] === 'La Calera' && array_key_exists('cl_lat', $listed[0]), short($r[2]));

// T2 old app POST (no new keys): created with locality and coordinates empty
$oldName = MARKER . 'geo_old_' . $suffix;
expect_status('Old app POST without locality still creates the cliente', 201, http('POST', 'clientes.php', [], [
    'nombreCl' => $oldName, 'numTelCl' => '351000012', 'direccionCl' => 'Belgrano 5',
], $tokC));
$oldId = (int) (db_row('SELECT cl_ide FROM sap_cl00 WHERE cl_nom = ? AND cl_ide > ?', 'si', [$oldName, $maxCl])['cl_ide'] ?? 0);
check('Old app POST leaves locality and coordinates NULL', cl_geo($oldId) === ['cl_loc' => null, 'cl_lat' => null, 'cl_lng' => null], json_encode(cl_geo($oldId)));

// T2 PUT: the new keys update; absent keys leave them unchanged (old app PUT)
$put = function ($extra) use ($oldId, $oldName, $tokC) {
    return http('PUT', 'clientes.php', [], array_merge([
        'cl_ide' => $oldId, 'cl_nom' => $oldName, 'cl_tel' => '351000012', 'cl_dir' => 'Belgrano 5',
    ], $extra), $tokC);
};
expect_status('PUT with locality and coordinates', 200, $put(['cl_loc' => 'Villa Allende', 'cl_lat' => -31.29, 'cl_lng' => -64.29]));
check('PUT stores locality and coordinates', cl_geo($oldId) == ['cl_loc' => 'Villa Allende', 'cl_lat' => '-31.290000', 'cl_lng' => '-64.290000'], json_encode(cl_geo($oldId)));
expect_status('Old app PUT without the new keys', 200, $put([]));
check('Old app PUT leaves locality and coordinates unchanged', cl_geo($oldId) == ['cl_loc' => 'Villa Allende', 'cl_lat' => '-31.290000', 'cl_lng' => '-64.290000'], json_encode(cl_geo($oldId)));
expect_status('PUT clearing the coordinates with null', 200, $put(['cl_lat' => null, 'cl_lng' => null]));
check('PUT null clears the coordinates and keeps the locality', cl_geo($oldId) == ['cl_loc' => 'Villa Allende', 'cl_lat' => null, 'cl_lng' => null], json_encode(cl_geo($oldId)));

// T2 invalid coordinates: 400 and nothing changes
$before = cl_geo($oldId);
expect_status('PUT latitude without longitude -> 400', 400, $put(['cl_lat' => -31.2]));
expect_status('PUT latitude out of range -> 400', 400, $put(['cl_lat' => 95, 'cl_lng' => -64.2]));
expect_status('PUT longitude not a number -> 400', 400, $put(['cl_lat' => -31.2, 'cl_lng' => 'abc']));
check('Rejected PUTs changed nothing', cl_geo($oldId) == $before, json_encode(cl_geo($oldId)));
expect_status('POST latitude without longitude -> 400', 400, http('POST', 'clientes.php', [], [
    'nombreCl' => MARKER . 'geo_bad_' . $suffix, 'numTelCl' => '1', 'direccionCl' => 'x', 'latitudCl' => -31.2,
], $tokC));
check('Rejected POST created nothing', db_row('SELECT 1 AS x FROM sap_cl00 WHERE cl_nom = ?', 's', [MARKER . 'geo_bad_' . $suffix]) === null);

// T2b plant: GET, guardar_planta, validation, per user (Congre starts without a plant; restored at the end)
db_exec('DELETE FROM sap_pl00 WHERE pl_emp = ?', 'i', [$congreId]);
expect_status('GET ruta.php without token -> 401', 401, http('GET', 'ruta.php'));
$r = http('GET', 'ruta.php', [], null, $tokC);
expect_status('GET plant of Congre', 200, $r);
check('Congre has no plant yet', array_key_exists('planta', $r[1] ?? []) && $r[1]['planta'] === null, short($r[2]));
expect_status('guardar_planta without localidad -> 400', 400, ruta_post(['accion' => 'guardar_planta', 'direccion' => 'Ruta 20 km 5'], $tokC));
expect_status('guardar_planta with latitude only -> 400', 400, ruta_post(['accion' => 'guardar_planta',
    'direccion' => 'Ruta 20 km 5', 'localidad' => 'La Calera', 'lat' => -31.3], $tokC));
expect_status('Unknown accion -> 400', 400, ruta_post(['accion' => 'otra'], $tokC));
expect_status('guardar_planta without coordinates', 200, ruta_post(['accion' => 'guardar_planta',
    'direccion' => 'Ruta 20 km 5', 'localidad' => 'La Calera'], $tokC));
expect_status('guardar_planta again with coordinates updates it', 200, ruta_post(['accion' => 'guardar_planta',
    'direccion' => 'Ruta 20 km 6', 'localidad' => 'La Calera', 'lat' => -31.35, 'lng' => -64.33], $tokC));
$r = http('GET', 'ruta.php', [], null, $tokC);
check('GET returns the saved plant', ($r[1]['planta'] ?? null) == ['direccion' => 'Ruta 20 km 6', 'localidad' => 'La Calera', 'lat' => -31.35, 'lng' => -64.33], short($r[2]));
check('One plant row per user', (int) db_row('SELECT COUNT(*) AS n FROM sap_pl00 WHERE pl_emp = ?', 'i', [$congreId])['n'] === 1);
$r = http('GET', 'ruta.php', [], null, $tokA);
check('Ariza does not see Congre plant', ($r[1]['planta']['direccion'] ?? null) !== 'Ruta 20 km 6', short($r[2]));

// T4b route of a reparto: stops in order with locality and coordinates, the plant and the times
$r = rp_post(['accion' => 'crear', 'fecha' => '2099-05-04', 'origen' => 'vacio'], $tokC);
expect_status('Congre creates an empty reparto for the route', 201, $r);
$rtId = (int) ($r[1]['reparto']['rp_ide'] ?? 0);
$rtStops = [];
foreach ([$geoId, $oldId, $rpCl['c']] as $cl) {
    $r = rp_post(['accion' => 'agregar_parada', 'repartoId' => $rtId, 'clienteId' => $cl], $tokC);
    $rtStops[] = (int) ($r[1]['paradaId'] ?? 0);
}
expect_status('Reorder the route stops', 200, rp_post(['accion' => 'ordenar', 'repartoId' => $rtId,
    'paradas' => [$rtStops[1], $rtStops[0], $rtStops[2]]], $tokC));
$r = http('GET', 'ruta.php', ['repartoId' => $rtId], null, $tokC);
expect_status('GET the route of the reparto', 200, $r);
$ruta = $r[1]['ruta'] ?? [];
check('Route stops follow the reparto order', array_map(function ($p) { return (int) $p['clienteId']; }, $ruta['paradas'] ?? [])
    === [$oldId, $geoId, $rpCl['c']], short($r[2]));
check('Route stop carries address, locality, coordinates and estado', ($ruta['paradas'][1] ?? null) == [
    'paradaId' => $rtStops[0], 'orden' => 2, 'estado' => 'pendiente', 'clienteId' => $geoId, 'nombre' => $geoName,
    'direccion' => 'San Martin 120', 'localidad' => 'La Calera', 'lat' => -31.343512, 'lng' => -64.335871,
], json_encode($ruta['paradas'][1] ?? null));
check('Route stop without coordinates has null lat/lng', array_key_exists('lat', $ruta['paradas'][0] ?? [])
    && $ruta['paradas'][0]['lat'] === null && $ruta['paradas'][0]['lng'] === null);
check('Route carries the plant and no times yet', ($ruta['planta']['direccion'] ?? null) === 'Ruta 20 km 6'
    && array_key_exists('inicio', $ruta) && $ruta['inicio'] === null && $ruta['fin'] === null, short($r[2]));
expect_status('Ariza reading Congre route -> 404', 404, http('GET', 'ruta.php', ['repartoId' => $rtId], null, $tokA));
expect_status('Route of a nonexistent reparto -> 404', 404, http('GET', 'ruta.php', ['repartoId' => 999999999], null, $tokC));

// T4b finalizar before iniciar, iniciar once, finalizar only without pendiente stops
expect_status('finalizar before iniciar -> 409', 409, ruta_post(['accion' => 'finalizar', 'repartoId' => $rtId], $tokC));
expect_status('Ariza starting Congre route -> 404', 404, ruta_post(['accion' => 'iniciar', 'repartoId' => $rtId], $tokA));
check('Rejected calls left the times empty', db_row('SELECT rp_ini, rp_fin FROM sap_rp00 WHERE rp_ide = ?', 'i', [$rtId]) === ['rp_ini' => null, 'rp_fin' => null]);
$r = ruta_post(['accion' => 'iniciar', 'repartoId' => $rtId], $tokC);
expect_status('iniciar the route', 200, $r);
$ini = db_row('SELECT rp_ini FROM sap_rp00 WHERE rp_ide = ?', 'i', [$rtId])['rp_ini'];
check('iniciar stores rp_ini and returns it', $ini !== null && ($r[1]['inicio'] ?? null) === $ini, short($r[2]));
db_exec('UPDATE sap_rp00 SET rp_ini = ? WHERE rp_ide = ?', 'si', ['2099-05-04 08:00:00', $rtId]);
$r = ruta_post(['accion' => 'iniciar', 'repartoId' => $rtId], $tokC);
check('iniciar again keeps the first start', ($r[1]['inicio'] ?? null) === '2099-05-04 08:00:00'
    && db_row('SELECT rp_ini FROM sap_rp00 WHERE rp_ide = ?', 'i', [$rtId])['rp_ini'] === '2099-05-04 08:00:00', short($r[2]));
expect_status('finalizar with pendiente stops -> 409', 409, ruta_post(['accion' => 'finalizar', 'repartoId' => $rtId], $tokC));
check('Rejected finalizar left rp_fin empty', db_row('SELECT rp_fin FROM sap_rp00 WHERE rp_ide = ?', 'i', [$rtId])['rp_fin'] === null);
foreach ($rtStops as $s) rp_post(['accion' => 'no_entregar', 'paradaId' => $s], $tokC);
$r = ruta_post(['accion' => 'finalizar', 'repartoId' => $rtId], $tokC);
expect_status('finalizar after every stop is resolved', 200, $r);
$fin = db_row('SELECT rp_fin FROM sap_rp00 WHERE rp_ide = ?', 'i', [$rtId])['rp_fin'];
check('finalizar stores rp_fin and returns both times', $fin !== null && ($r[1]['fin'] ?? null) === $fin
    && ($r[1]['inicio'] ?? null) === '2099-05-04 08:00:00', short($r[2]));
expect_status('finalizar twice -> 409', 409, ruta_post(['accion' => 'finalizar', 'repartoId' => $rtId], $tokC));
check('Second finalizar kept the first end', db_row('SELECT rp_fin FROM sap_rp00 WHERE rp_ide = ?', 'i', [$rtId])['rp_fin'] === $fin);
rp_post(['accion' => 'guardar_plantilla', 'repartoId' => $rtId], $tokC);
$tplId = (int) (db_row('SELECT rp_ide FROM sap_rp00 WHERE rp_emp = ? AND rp_fec IS NULL AND rp_ide > ? ORDER BY rp_ide DESC LIMIT 1', 'ii', [$congreId, $maxRp])['rp_ide'] ?? 0);
expect_status('Route of a weekday template -> 404', 404, http('GET', 'ruta.php', ['repartoId' => $tplId], null, $tokC));
$r = http('GET', 'repartos.php', ['id' => $rtId], null, $tokC);
check('GET repartos returns the trip times', ($r[1]['reparto']['rp_ini'] ?? null) === '2099-05-04 08:00:00' && ($r[1]['reparto']['rp_fin'] ?? null) === $fin, short($r[2]));

// ---------------------------------------------------------------- summary

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
