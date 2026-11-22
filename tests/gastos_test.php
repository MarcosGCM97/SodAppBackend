<?php
// Expenses (gastos) tests for the REST API in api/gastos.php and the refill in api/envases.php.
//
// Usage: php tests/gastos_test.php
//
// Starts the PHP built-in server on 127.0.0.1:8769 (docroot api/) against the local database
// sodapp_test (migrations 011 and 014 applied), logs in as Ariza and Congre (owners), creates
// test products and repartos directly in the database and a role + user of Ariza's company
// through the admin API. Checks the CRUD, filters and total, the stock effects of purchases and
// refills (same transaction, 409 without enough stock), company scope and permissions.
// Prints PASS/FAIL per case and exits with code 1 when any case fails.
//
// Every row created by the test is deleted at the end, so the script is re-runnable.
// Requires the curl and mysqli extensions. Never point it at production.

// "Today" must be the API's day (api/config.php), not the CLI default timezone (UTC),
// or the date checks fail every evening after 21:00 in Argentina.
date_default_timezone_set('America/Argentina/Buenos_Aires');

if (PHP_SAPI === 'cli-server') {
    putenv('DB_HOST=localhost');
    putenv('DB_NAME=sodapp_test');
    putenv('DB_USER=root');
    putenv('DB_PASS=');
    putenv('JWT_SECRET=local-test-secret-0123456789abcdef');
    return false;
}

error_reporting(E_ALL);

const HOST = '127.0.0.1';
define('PORT', (int) (getenv('GASTOS_TEST_PORT') ?: 8769));
const DB_HOST = 'localhost';
const DB_NAME = 'sodapp_test';
const DB_USER = 'root';
const DB_PASS = '';
const JWT_SECRET = 'local-test-secret-0123456789abcdef';
const PASSWORD = 'test1234';
const MARKER = 'zz_gastos_test_';
const FORBIDDEN = 'Forbidden: missing permission';

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
    $busy = @fsockopen(HOST, PORT, $errno, $errstr, 0.2);
    if ($busy) {
        fclose($busy);
        fwrite(STDERR, 'Port ' . PORT . " is already in use; stop that server or set GASTOS_TEST_PORT\n");
        exit(1);
    }
    $env = array_merge(getenv(), ['DB_HOST' => DB_HOST, 'DB_NAME' => DB_NAME, 'DB_USER' => DB_USER, 'DB_PASS' => DB_PASS, 'JWT_SECRET' => JWT_SECRET]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapp_gastos_server.log';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']];
    $proc = proc_open([PHP_BINARY, '-S', HOST . ':' . PORT, '-t', $apiDir, __FILE__], $descriptors, $pipes, $apiDir, $env);
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
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = $raw === false ? '' : $raw;
    return [$status, json_decode($raw, true), $raw];
}

function login($name, $password = PASSWORD) {
    list($status, $json, $raw) = http('POST', 'login.php', [], ['nombreUs' => $name, 'contrasenaUs' => $password]);
    if ($status !== 200 || empty($json['token'])) {
        fwrite(STDERR, "Login failed for $name ($status): " . short($raw) . "\n");
        return null;
    }
    return $json['token'];
}

function short($raw) {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    return strlen($raw) > 200 ? substr($raw, 0, 200) . '...' : $raw;
}

function expect_status($name, $expected, $response) {
    list($status, , $raw) = $response;
    check($name, $status === $expected, "expected $expected, got $status: " . short($raw));
}

function expect_forbidden($name, $code, $response) {
    list($status, $json, $raw) = $response;
    $ok = $status === 403 && $json === ['success' => false, 'error' => FORBIDDEN, 'permiso' => $code];
    check("$name -> 403 $code", $ok, "got $status: " . short($raw));
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
        return 0;
    }
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $id = mysqli_insert_id(db());
    mysqli_stmt_close($stmt);
    return $id;
}

function max_id($table, $col) {
    return (int) db_row("SELECT COALESCE(MAX($col), 0) AS m FROM $table")['m'];
}

// pr_stk / pr_vac of a product
function stock($id) {
    $r = db_row('SELECT pr_stk, pr_vac FROM sap_pr00 WHERE pr_ide = ?', 'i', [$id]);
    return [(int) $r['pr_stk'], (int) $r['pr_vac']];
}

// JSON numbers: 2000.0 is encoded as 2000; null stays null
function money($v) {
    return is_int($v) || is_float($v) ? (float) $v : null;
}

function gastos_count() {
    global $maxGs;
    return (int) db_row('SELECT COUNT(*) AS n FROM sap_gs00 WHERE gs_ide > ?', 'i', [$maxGs])['n'];
}

function movimientos($productoId) {
    global $msDesde;
    return array_map(function ($r) { return [$r['ms_tip'], (int) $r['ms_can'], (int) $r['ms_stk']]; },
        db_rows('SELECT ms_tip, ms_can, ms_stk FROM sap_ms00 WHERE ms_pro = ? AND ms_ide > ? ORDER BY ms_ide', 'ii', [$productoId, $msDesde]));
}

// ---------------------------------------------------------------- fixtures

$hasTable = db_row("SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'sap_gs00'", 's', [DB_NAME]);
if (!$hasTable) {
    fwrite(STDERR, "Migration 014_gastos.sql must be applied to " . DB_NAME . "\n");
    exit(1);
}
$ariza = db_row('SELECT us_ide FROM sap_us00 WHERE us_nom = ?', 's', ['Ariza']);
$congre = db_row('SELECT us_ide FROM sap_us00 WHERE us_nom = ?', 's', ['Congre']);
if (!$ariza || !$congre) {
    fwrite(STDERR, "Users Ariza and Congre must exist in " . DB_NAME . "\n");
    exit(1);
}
$arizaId = (int) $ariza['us_ide'];
$congreId = (int) $congre['us_ide'];

$maxGs = max_id('sap_gs00', 'gs_ide');
$maxMs = max_id('sap_ms00', 'ms_ide');
$maxPr = max_id('sap_pr00', 'pr_ide');
$maxRp = max_id('sap_rp00', 'rp_ide');
$maxUs = max_id('sap_us00', 'us_ide');
$maxRo = max_id('sap_ro00', 'ro_ide');
// movimientos() lists the movements after this id; cleanup() always uses $maxMs
$msDesde = $maxMs;

function cleanup() {
    global $maxGs, $maxMs, $maxPr, $maxRp, $maxUs, $maxRo;
    db_exec('DELETE FROM sap_gs00 WHERE gs_ide > ?', 'i', [$maxGs]);
    db_exec('DELETE FROM sap_ms00 WHERE ms_ide > ?', 'i', [$maxMs]);
    db_exec('DELETE FROM sap_pr00 WHERE pr_ide > ?', 'i', [$maxPr]);
    db_exec('DELETE FROM sap_rp00 WHERE rp_ide > ?', 'i', [$maxRp]);
    db_exec('DELETE FROM sap_us00 WHERE us_ide > ?', 'i', [$maxUs]);
    db_exec('DELETE FROM sap_ro00 WHERE ro_ide > ?', 'i', [$maxRo]);
}

$server = start_server();
register_shutdown_function(function () use ($server) {
    cleanup();
    stop_server($server);
});

$suffix = substr(md5(uniqid('', true)), 0, 6);
$ret = MARKER . 'bidon_' . $suffix;
$noRet = MARKER . 'vaso_' . $suffix;
$retId = db_exec('INSERT INTO sap_pr00 (pr_nom, pr_val, pr_stk, pr_emp, pr_ret, pr_vac) VALUES (?, 100, 10, ?, 1, 5)', 'si', [$ret, $arizaId]);
$noRetId = db_exec('INSERT INTO sap_pr00 (pr_nom, pr_val, pr_stk, pr_emp, pr_ret, pr_vac) VALUES (?, 50, 3, ?, 0, 0)', 'si', [$noRet, $arizaId]);
$congrePrId = db_exec('INSERT INTO sap_pr00 (pr_nom, pr_val, pr_stk, pr_emp, pr_ret, pr_vac) VALUES (?, 1, 1, ?, 1, 9)', 'si', [MARKER . 'c_' . $suffix, $congreId]);
$repId = db_exec("INSERT INTO sap_rp00 (rp_emp, rp_fec, rp_dia) VALUES (?, '2097-03-10', 1)", 'i', [$arizaId]);
$congreRepId = db_exec("INSERT INTO sap_rp00 (rp_emp, rp_fec, rp_dia) VALUES (?, '2097-03-10', 1)", 'i', [$congreId]);

$tokA = login('Ariza');
$tokC = login('Congre');
if (!$tokA || !$tokC) exit(1);

// ---------------------------------------------------------------- 1. envases llenar still works (refactor)

echo "\n# envases.php llenar keeps its behavior after the llenar_envases() extraction\n";

$r = http('POST', 'envases.php', [], ['accion' => 'llenar', 'producto' => $ret, 'cantidad' => 2], $tokA);
expect_status('llenar 2', 200, $r);
check('llenar moved 2 empties to stock', stock($retId) === [12, 3], json_encode(stock($retId)));
check('llenar response has the product row', (int) ($r[1]['producto']['pr_stk'] ?? -1) === 12 && (int) ($r[1]['producto']['pr_vac'] ?? -1) === 3, short($r[2]));
check('llenar recorded a llenado movement', movimientos($retId) === [['llenado', 2, 12]], json_encode(movimientos($retId)));
$r = http('POST', 'envases.php', [], ['accion' => 'llenar', 'producto' => $ret, 'cantidad' => 4], $tokA);
check('llenar more than the empties -> 409 and nothing changes', $r[0] === 409 && ($r[1]['error'] ?? '') === 'No hay suficientes envases vacíos'
    && stock($retId) === [12, 3] && count(movimientos($retId)) === 1, short($r[2]));
expect_status('llenar a non-returnable product -> 400', 400, http('POST', 'envases.php', [], ['accion' => 'llenar', 'producto' => $noRet, 'cantidad' => 1], $tokA));
db_exec('UPDATE sap_pr00 SET pr_stk = 10, pr_vac = 5 WHERE pr_ide = ?', 'i', [$retId]);
$msDesde = max_id('sap_ms00', 'ms_ide');

// ---------------------------------------------------------------- 2. gastos without stock

echo "\n# POST gastos without stock effect: stored, scoped, validated\n";

$r = http('POST', 'gastos.php', [], ['categoria' => 'vehiculo', 'monto' => 15000.5, 'fecha' => '2097-03-10',
    'descripcion' => '  Pinchadura  ', 'repartoId' => $repId], $tokA);
expect_status('POST vehiculo gasto', 201, $r);
$g1 = $r[1]['gasto'] ?? [];
$g1Id = (int) ($g1['gs_ide'] ?? 0);
check('POST response gasto fields', $g1 === ['gs_ide' => $g1Id, 'gs_cat' => 'vehiculo', 'gs_mon' => 15000.5, 'gs_fec' => '2097-03-10',
    'gs_des' => 'Pinchadura', 'gs_rep' => $repId, 'gs_pro' => null, 'pr_nom' => null, 'gs_can' => null, 'gs_est' => null,
    'gs_us' => $arizaId, 'us_nom' => 'Ariza'], short($r[2]));
$row = db_row('SELECT * FROM sap_gs00 WHERE gs_ide = ?', 'i', [$g1Id]);
check('Gasto stored in Ariza company by Ariza', $row && (int) $row['gs_emp'] === $arizaId && (int) $row['gs_us'] === $arizaId
    && $row['gs_mon'] === '15000.50' && $row['gs_des'] === 'Pinchadura');

$r = http('POST', 'gastos.php', [], ['categoria' => 'combustible', 'monto' => '2000'], $tokA);
expect_status('POST combustible without fecha (defaults to today)', 201, $r);
check('Fecha defaults to today', ($r[1]['gasto']['gs_fec'] ?? '') === date('Y-m-d') && array_key_exists('gs_rep', $r[1]['gasto'] ?? []) && $r[1]['gasto']['gs_rep'] === null, short($r[2]));
$g2Id = (int) ($r[1]['gasto']['gs_ide'] ?? 0);

$n = gastos_count();
$bad = [
    'unknown categoria' => ['categoria' => 'viajes', 'monto' => 1],
    'monto 0' => ['categoria' => 'otro', 'monto' => 0],
    'negative monto' => ['categoria' => 'otro', 'monto' => -5],
    'monto text' => ['categoria' => 'otro', 'monto' => 'mucho'],
    'monto with 3 decimals' => ['categoria' => 'otro', 'monto' => 1.234],
    'invalid fecha' => ['categoria' => 'otro', 'monto' => 1, 'fecha' => '2097-02-30'],
    'descripcion over 255' => ['categoria' => 'otro', 'monto' => 1, 'descripcion' => str_repeat('a', 256)],
    'producto on a non-stock categoria' => ['categoria' => 'otro', 'monto' => 1, 'productoId' => $retId, 'cantidad' => 1],
    'compra without producto' => ['categoria' => 'compra_producto', 'monto' => 1, 'cantidad' => 1],
    'compra with cantidad 0' => ['categoria' => 'compra_producto', 'monto' => 1, 'productoId' => $noRetId, 'cantidad' => 0],
    'compra of a returnable without estado' => ['categoria' => 'compra_producto', 'monto' => 1, 'productoId' => $retId, 'cantidad' => 1],
    'compra with an unknown estado' => ['categoria' => 'compra_producto', 'monto' => 1, 'productoId' => $retId, 'cantidad' => 1, 'estado' => 'roto'],
    'compra vacio of a non-returnable' => ['categoria' => 'compra_producto', 'monto' => 1, 'productoId' => $noRetId, 'cantidad' => 1, 'estado' => 'vacio'],
    'recarga of a non-returnable' => ['categoria' => 'recarga_envases', 'monto' => 1, 'productoId' => $noRetId, 'cantidad' => 1],
];
foreach ($bad as $label => $body) {
    expect_status("POST $label -> 400", 400, http('POST', 'gastos.php', [], $body, $tokA));
}
expect_status('POST with another company reparto -> 404', 404, http('POST', 'gastos.php', [], ['categoria' => 'otro', 'monto' => 1, 'repartoId' => $congreRepId], $tokA));
expect_status('POST compra of another company product -> 404', 404, http('POST', 'gastos.php', [], ['categoria' => 'compra_producto', 'monto' => 1, 'productoId' => $congrePrId, 'cantidad' => 1, 'estado' => 'lleno'], $tokA));
check('Rejected posts stored nothing and moved no stock', gastos_count() === $n && stock($retId) === [10, 5] && stock($noRetId) === [3, 0]
    && stock($congrePrId) === [1, 9]);
expect_status('POST without token -> 401', 401, http('POST', 'gastos.php', [], ['categoria' => 'otro', 'monto' => 1]));

// ---------------------------------------------------------------- 3. stock effects

echo "\n# Purchases and refills move the stock with the gasto\n";

$r = http('POST', 'gastos.php', [], ['categoria' => 'compra_producto', 'monto' => 900, 'productoId' => $retId, 'cantidad' => 4, 'estado' => 'lleno'], $tokA);
expect_status('POST compra lleno', 201, $r);
$gLleno = (int) ($r[1]['gasto']['gs_ide'] ?? 0);
check('compra lleno: pr_stk +4', stock($retId) === [14, 5], json_encode(stock($retId)));
check('compra lleno response names the product and estado', ($r[1]['gasto']['pr_nom'] ?? '') === $ret && ($r[1]['gasto']['gs_est'] ?? '') === 'lleno'
    && ($r[1]['gasto']['gs_can'] ?? 0) === 4 && ($r[1]['gasto']['gs_pro'] ?? 0) === $retId, short($r[2]));
check('compra lleno recorded a compra movement', movimientos($retId) === [['compra', 4, 14]], json_encode(movimientos($retId)));

$r = http('POST', 'gastos.php', [], ['categoria' => 'compra_producto', 'monto' => 300, 'productoId' => $retId, 'cantidad' => 3, 'estado' => 'vacio'], $tokA);
expect_status('POST compra vacio', 201, $r);
$gVacio = (int) ($r[1]['gasto']['gs_ide'] ?? 0);
check('compra vacio: pr_vac +3, pr_stk unchanged, no stock movement', stock($retId) === [14, 8] && count(movimientos($retId)) === 1, json_encode(stock($retId)));

$r = http('POST', 'gastos.php', [], ['categoria' => 'compra_producto', 'monto' => 120, 'productoId' => $noRetId, 'cantidad' => 6], $tokA);
expect_status('POST compra of a non-returnable', 201, $r);
$gNoRet = (int) ($r[1]['gasto']['gs_ide'] ?? 0);
check('compra non-returnable: pr_stk +6, estado null', stock($noRetId) === [9, 0] && array_key_exists('gs_est', $r[1]['gasto'] ?? []) && $r[1]['gasto']['gs_est'] === null, short($r[2]));
check('compra non-returnable recorded a compra movement', movimientos($noRetId) === [['compra', 6, 9]], json_encode(movimientos($noRetId)));

$r = http('POST', 'gastos.php', [], ['categoria' => 'recarga_envases', 'monto' => 500, 'productoId' => $retId, 'cantidad' => 5], $tokA);
expect_status('POST recarga', 201, $r);
$gRecarga = (int) ($r[1]['gasto']['gs_ide'] ?? 0);
check('recarga: pr_vac -5, pr_stk +5', stock($retId) === [19, 3], json_encode(stock($retId)));
check('recarga recorded a llenado movement', array_slice(movimientos($retId), -1) === [['llenado', 5, 19]], json_encode(movimientos($retId)));

$n = gastos_count();
$r = http('POST', 'gastos.php', [], ['categoria' => 'recarga_envases', 'monto' => 500, 'productoId' => $retId, 'cantidad' => 4], $tokA);
check('recarga with fewer empties -> 409 with the message', $r[0] === 409 && ($r[1]['error'] ?? '') === 'No hay suficientes envases vacíos', short($r[2]));
check('Rejected recarga stored no gasto and moved no stock', gastos_count() === $n && stock($retId) === [19, 3] && count(movimientos($retId)) === 2);

// ---------------------------------------------------------------- 4. GET list, filters, total

echo "\n# GET gastos: period and category filters, total, company scope\n";

$r = http('GET', 'gastos.php', ['desde' => '2097-03-01', 'hasta' => '2097-03-31'], null, $tokA);
expect_status('GET march 2097', 200, $r);
check('GET period lists only the gasto of that period', array_column($r[1]['gastos'] ?? [], 'gs_ide') === [$g1Id] && money($r[1]['total'] ?? null) === 15000.5, short($r[2]));
$hoy = date('Y-m-d');
$r = http('GET', 'gastos.php', ['desde' => $hoy, 'hasta' => $hoy], null, $tokA);
$ids = array_column($r[1]['gastos'] ?? [], 'gs_ide');
sort($ids);
$esperados = [$g2Id, $gLleno, $gVacio, $gNoRet, $gRecarga];
sort($esperados);
check('GET today lists the gastos of today', $ids === $esperados, short($r[2]));
check('GET total sums the listed amounts', money($r[1]['total'] ?? null) === 3820.0, short($r[2]));
$r = http('GET', 'gastos.php', ['desde' => $hoy, 'hasta' => $hoy, 'categoria' => 'compra_producto'], null, $tokA);
$ids = array_column($r[1]['gastos'] ?? [], 'gs_ide');
sort($ids);
$compras = [$gLleno, $gVacio, $gNoRet];
sort($compras);
check('GET categoria filter', $ids === $compras && money($r[1]['total'] ?? null) === 1320.0, short($r[2]));
$r = http('GET', 'gastos.php', [], null, $tokA);
check('GET without period defaults to the current month', $r[0] === 200 && ($r[1]['desde'] ?? '') === date('Y-m-01') && ($r[1]['hasta'] ?? '') === date('Y-m-t')
    && in_array($g2Id, array_column($r[1]['gastos'] ?? [], 'gs_ide'), true), short($r[2]));
expect_status('GET with an invalid desde -> 400', 400, http('GET', 'gastos.php', ['desde' => '2097-13-01', 'hasta' => '2097-12-31'], null, $tokA));
expect_status('GET with desde after hasta -> 400', 400, http('GET', 'gastos.php', ['desde' => '2097-03-31', 'hasta' => '2097-03-01'], null, $tokA));
expect_status('GET with an unknown categoria -> 400', 400, http('GET', 'gastos.php', ['categoria' => 'viajes'], null, $tokA));
$r = http('GET', 'gastos.php', ['desde' => $hoy, 'hasta' => '2097-12-31'], null, $tokC);
check('Congre sees none of Ariza gastos', $r[0] === 200 && ($r[1]['gastos'] ?? null) === [] && money($r[1]['total'] ?? null) === 0.0, short($r[2]));

// ---------------------------------------------------------------- 5. PUT and DELETE revert the stock

echo "\n# PUT and DELETE revert the previous stock effect (409 when it cannot)\n";

$r = http('PUT', 'gastos.php', [], ['id' => $gLleno, 'categoria' => 'compra_producto', 'monto' => 950, 'productoId' => $retId, 'cantidad' => 2, 'estado' => 'lleno'], $tokA);
expect_status('PUT compra lleno 4 -> 2', 200, $r);
check('PUT applied the difference: pr_stk 19 -> 17', stock($retId) === [17, 3], json_encode(stock($retId)));
check('PUT recorded the revert and the new compra', array_slice(movimientos($retId), -2) === [['compra_eliminada', -4, 15], ['compra', 2, 17]], json_encode(movimientos($retId)));
check('PUT response has the new fields', money($r[1]['gasto']['gs_mon'] ?? null) === 950.0 && ($r[1]['gasto']['gs_can'] ?? 0) === 2, short($r[2]));

// Only the amount, date or description change: the stock is left alone, so a purchase whose
// units were already sold can still be corrected
db_exec('UPDATE sap_pr00 SET pr_stk = 0 WHERE pr_ide = ?', 'i', [$retId]);
$movs = count(movimientos($retId));
$r = http('PUT', 'gastos.php', [], ['id' => $gLleno, 'categoria' => 'compra_producto', 'monto' => 990, 'productoId' => $retId,
    'cantidad' => 2, 'estado' => 'lleno', 'descripcion' => 'Factura 12'], $tokA);
expect_status('PUT that keeps producto, cantidad and estado with sold stock', 200, $r);
check('PUT without a stock change leaves stock and movements alone', stock($retId) === [0, 3] && count(movimientos($retId)) === $movs
    && money($r[1]['gasto']['gs_mon'] ?? null) === 990.0 && ($r[1]['gasto']['gs_des'] ?? '') === 'Factura 12', short($r[2]));
db_exec('UPDATE sap_pr00 SET pr_stk = 17 WHERE pr_ide = ?', 'i', [$retId]);
$n = gastos_count();
expect_status('POST compra with cantidad over 100000 -> 400', 400, http('POST', 'gastos.php', [], ['categoria' => 'compra_producto',
    'monto' => 1, 'productoId' => $noRetId, 'cantidad' => 100001], $tokA));
check('Over-limit cantidad stored nothing', gastos_count() === $n && stock($noRetId) === [9, 0]);

$r = http('PUT', 'gastos.php', [], ['id' => $gVacio, 'categoria' => 'otro', 'monto' => 300], $tokA);
expect_status('PUT compra vacio -> otro', 200, $r);
check('PUT to a non-stock categoria reverted the empties: pr_vac 3 -> 0', stock($retId) === [17, 0], json_encode(stock($retId)));
check('PUT cleared the product fields', db_row('SELECT gs_cat, gs_pro, gs_can, gs_est FROM sap_gs00 WHERE gs_ide = ?', 'i', [$gVacio])
    === ['gs_cat' => 'otro', 'gs_pro' => null, 'gs_can' => null, 'gs_est' => null]);

$before = db_row('SELECT * FROM sap_gs00 WHERE gs_ide = ?', 'i', [$gRecarga]);
db_exec('UPDATE sap_pr00 SET pr_stk = 1 WHERE pr_ide = ?', 'i', [$retId]);
$r = http('DELETE', 'gastos.php', ['id' => $gRecarga], null, $tokA);
check('DELETE recarga with less stock than refilled -> 409', $r[0] === 409, short($r[2]));
check('Rejected DELETE kept the gasto and the stock', db_row('SELECT * FROM sap_gs00 WHERE gs_ide = ?', 'i', [$gRecarga]) === $before && stock($retId) === [1, 0]);
$r = http('PUT', 'gastos.php', [], ['id' => $gRecarga, 'categoria' => 'otro', 'monto' => 1], $tokA);
check('PUT that cannot revert -> 409, gasto unchanged', $r[0] === 409 && db_row('SELECT * FROM sap_gs00 WHERE gs_ide = ?', 'i', [$gRecarga]) === $before, short($r[2]));
db_exec('UPDATE sap_pr00 SET pr_stk = 17 WHERE pr_ide = ?', 'i', [$retId]);

$r = http('DELETE', 'gastos.php', ['id' => $gRecarga], null, $tokA);
expect_status('DELETE recarga', 200, $r);
check('DELETE recarga reverted the refill: pr_stk -5, pr_vac +5', stock($retId) === [12, 5], json_encode(stock($retId)));
check('DELETE recorded a llenado_eliminado movement', array_slice(movimientos($retId), -1) === [['llenado_eliminado', -5, 12]], json_encode(movimientos($retId)));
check('DELETE removed the row', db_row('SELECT gs_ide FROM sap_gs00 WHERE gs_ide = ?', 'i', [$gRecarga]) === null);

$r = http('DELETE', 'gastos.php', ['id' => $gNoRet], null, $tokA);
expect_status('DELETE compra non-returnable', 200, $r);
check('DELETE compra reverted pr_stk 9 -> 3', stock($noRetId) === [3, 0], json_encode(stock($noRetId)));

expect_status('DELETE a missing id -> 404', 404, http('DELETE', 'gastos.php', ['id' => 999999999], null, $tokA));
expect_status("Congre PUT Ariza's gasto -> 404", 404, http('PUT', 'gastos.php', [], ['id' => $g1Id, 'categoria' => 'otro', 'monto' => 1], $tokC));
expect_status("Congre DELETE Ariza's gasto -> 404", 404, http('DELETE', 'gastos.php', ['id' => $g1Id], null, $tokC));
expect_status('PUT without id -> 400', 400, http('PUT', 'gastos.php', [], ['categoria' => 'otro', 'monto' => 1], $tokA));
check('Congre attempts left the gasto', db_row('SELECT gs_cat FROM sap_gs00 WHERE gs_ide = ?', 'i', [$g1Id])['gs_cat'] === 'vehiculo');

// ---------------------------------------------------------------- 6. permissions

echo "\n# Permissions: gastos.ver / gastos.cargar / gastos.editar, stock.editar for stock effects\n";

$r = http('POST', 'roles.php', [], ['nombre' => MARKER . 'rol_' . $suffix, 'permisos' => ['gastos.ver', 'gastos.cargar']], $tokA);
expect_status('Owner creates a role with gastos.ver + gastos.cargar', 201, $r);
$rolId = (int) ($r[1]['ro_ide'] ?? 0);
$vendName = MARKER . 'vend_' . $suffix;
expect_status('Owner creates the user', 201, http('POST', 'usuarios.php', [], ['nombre' => $vendName, 'contrasena' => 'secreto1', 'rol' => $rolId], $tokA));
$tokV = login($vendName, 'secreto1');
if (!$tokV) exit(1);

$r = http('POST', 'gastos.php', [], ['categoria' => 'combustible', 'monto' => 10], $tokV);
expect_status('User with gastos.cargar POSTs a combustible gasto', 201, $r);
check('Gasto of the user is owned by the company and names the user', ($r[1]['gasto']['gs_us'] ?? 0) === (int) db_row('SELECT us_ide FROM sap_us00 WHERE us_nom = ?', 's', [$vendName])['us_ide']
    && (int) db_row('SELECT gs_emp FROM sap_gs00 WHERE gs_ide = ?', 'i', [(int) ($r[1]['gasto']['gs_ide'] ?? 0)])['gs_emp'] === $arizaId, short($r[2]));
expect_status('User with gastos.ver GETs the company gastos', 200, http('GET', 'gastos.php', [], null, $tokV));
$n = gastos_count();
expect_forbidden('User POSTs a compra without stock.editar', 'stock.editar', http('POST', 'gastos.php', [], ['categoria' => 'compra_producto', 'monto' => 1, 'productoId' => $noRetId, 'cantidad' => 1], $tokV));
expect_forbidden('User POSTs a recarga without stock.editar', 'stock.editar', http('POST', 'gastos.php', [], ['categoria' => 'recarga_envases', 'monto' => 1, 'productoId' => $retId, 'cantidad' => 1], $tokV));
expect_forbidden('User PUTs without gastos.editar', 'gastos.editar', http('PUT', 'gastos.php', [], ['id' => $g1Id, 'categoria' => 'otro', 'monto' => 1], $tokV));
expect_forbidden('User DELETEs without gastos.editar', 'gastos.editar', http('DELETE', 'gastos.php', ['id' => $g1Id], null, $tokV));
check('Forbidden calls stored nothing and moved no stock', gastos_count() === $n && stock($noRetId) === [3, 0] && stock($retId) === [12, 5]);

expect_status('Owner removes gastos.ver from the role', 200, http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => MARKER . 'rol_' . $suffix, 'permisos' => ['gastos.editar']], $tokA));
expect_forbidden('User GETs without gastos.ver', 'gastos.ver', http('GET', 'gastos.php', [], null, $tokV));
expect_forbidden('User POSTs without gastos.cargar', 'gastos.cargar', http('POST', 'gastos.php', [], ['categoria' => 'otro', 'monto' => 1], $tokV));
expect_forbidden('User with gastos.editar PUTs a stock gasto without stock.editar', 'stock.editar',
    http('PUT', 'gastos.php', [], ['id' => $gLleno, 'categoria' => 'otro', 'monto' => 1], $tokV));
expect_status('User with gastos.editar PUTs a non-stock gasto', 200, http('PUT', 'gastos.php', [], ['id' => $g1Id, 'categoria' => 'otro', 'monto' => 1], $tokV));

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
