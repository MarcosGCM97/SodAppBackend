<?php
// Company data tests for the REST API in api/ (datos-empresa).
//
// Usage: php tests/empresa_test.php
//
// Starts the PHP built-in server on 127.0.0.1:8767 (docroot api/) against the
// local database sodapp_test (with migrations 011 and 012 applied), logs in as
// Ariza and Congre (owners of their companies), creates a vendedor user of
// Ariza's company and checks empresa.php (GET for every user of the company,
// PUT only for the owner, validation, company scope) and the empresa_nombre
// field of login.php and sesion.php. Prints PASS/FAIL per case and exits with
// code 1 when any case fails.
//
// The sap_em00 rows of Ariza and Congre are restored and the vendedor is deleted
// at the end, so the script is re-runnable. Requires the curl and mysqli
// extensions. Never point it at production.

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
// EMPRESA_TEST_PORT overrides the port when 8767 is taken by another server
define('PORT', (int) (getenv('EMPRESA_TEST_PORT') ?: 8767));
const DB_HOST = 'localhost';
const DB_NAME = 'sodapp_test';
const DB_USER = 'root';
const DB_PASS = '';
const JWT_SECRET = 'local-test-secret-0123456789abcdef';
const PASSWORD = 'test1234';
const MARKER = 'zz_empresa_test_';
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
    // never run the cases against a server of another checkout
    $busy = @fsockopen(HOST, PORT, $errno, $errstr, 0.2);
    if ($busy) {
        fclose($busy);
        fwrite(STDERR, 'Port ' . PORT . " is already in use; stop that server or set EMPRESA_TEST_PORT\n");
        exit(1);
    }
    $env = array_merge(getenv(), [
        'DB_HOST' => DB_HOST,
        'DB_NAME' => DB_NAME,
        'DB_USER' => DB_USER,
        'DB_PASS' => DB_PASS,
        'JWT_SECRET' => JWT_SECRET,
    ]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapp_empresa_server.log';
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

function login_request($name, $password = PASSWORD) {
    return http('POST', 'login.php', ['nombreUs' => $name, 'contrasenaUs' => $password]);
}

function short($raw) {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    return strlen($raw) > 160 ? substr($raw, 0, 160) . '...' : $raw;
}

function expect_status($name, $expected, $response) {
    list($status, , $raw) = $response;
    check($name, $status === $expected, "expected $expected, got $status: " . short($raw));
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

function empresa_row($companyId) {
    return db_row('SELECT em_emp, em_nom, em_tel, em_dir FROM sap_em00 WHERE em_emp = ?', 'i', [$companyId]);
}

// Puts back the sap_em00 row of a company as it was before the test (or no row)
function restore_empresa($companyId, $snapshot) {
    db_exec('DELETE FROM sap_em00 WHERE em_emp = ?', 'i', [$companyId]);
    if ($snapshot) {
        db_exec('INSERT INTO sap_em00 (em_emp, em_nom, em_tel, em_dir) VALUES (?, ?, ?, ?)', 'isss',
            [$companyId, $snapshot['em_nom'], $snapshot['em_tel'], $snapshot['em_dir']]);
    }
}

// ---------------------------------------------------------------- fixtures

if (!db_row("SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'sap_em00'", 's', [DB_NAME])) {
    fwrite(STDERR, "Migration 012_datos_empresa.sql must be applied to " . DB_NAME . "\n");
    exit(1);
}

$ariza = db_row('SELECT us_ide FROM sap_us00 WHERE us_nom = ?', 's', ['Ariza']);
$congre = db_row('SELECT us_ide FROM sap_us00 WHERE us_nom = ?', 's', ['Congre']);
if (!$ariza || !$congre) {
    fwrite(STDERR, "Users Ariza and Congre must exist in " . DB_NAME . " (tablasDB/migrations/000_local_seed_users.sql)\n");
    exit(1);
}
$arizaId = (int) $ariza['us_ide'];
$congreId = (int) $congre['us_ide'];
$arizaRol = db_row('SELECT ro_ide FROM sap_ro00 WHERE ro_emp = ? ORDER BY ro_ide LIMIT 1', 'i', [$arizaId]);
if (!$arizaRol) {
    fwrite(STDERR, "Ariza needs a role (migration 011)\n");
    exit(1);
}

$snapA = empresa_row($arizaId);
$snapC = empresa_row($congreId);
$maxUs = (int) db_row('SELECT COALESCE(MAX(us_ide), 0) AS m FROM sap_us00')['m'];

function cleanup() {
    global $arizaId, $congreId, $snapA, $snapC, $maxUs;
    db_exec('DELETE FROM sap_us00 WHERE us_ide > ?', 'i', [$maxUs]);
    db_exec("DELETE FROM sap_us00 WHERE us_nom LIKE 'zz\\_empresa\\_test\\_%'");
    restore_empresa($arizaId, $snapA);
    restore_empresa($congreId, $snapC);
}

$server = start_server();
register_shutdown_function(function () use ($server) {
    cleanup();
    stop_server($server);
});

// Both companies start without company data
db_exec('DELETE FROM sap_em00 WHERE em_emp IN (?, ?)', 'ii', [$arizaId, $congreId]);

$r = login_request('Ariza');
$tokA = $r[1]['token'] ?? null;
$r = login_request('Congre');
$tokC = $r[1]['token'] ?? null;
if (!$tokA || !$tokC) {
    fwrite(STDERR, "Login failed for Ariza or Congre\n");
    exit(1);
}

$suffix = substr(md5(uniqid('', true)), 0, 6);
$vendName = MARKER . 'vend_' . $suffix;
$r = http('POST', 'usuarios.php', ['nombre' => $vendName, 'contrasena' => 'secreto1', 'rol' => (int) $arizaRol['ro_ide']], $tokA);
$r = login_request($vendName, 'secreto1');
$tokV = $r[1]['token'] ?? null;
if (!$tokV) {
    fwrite(STDERR, "Could not create and log in the vendedor: " . short($r[2]) . "\n");
    exit(1);
}

// ---------------------------------------------------------------- 1. no company data yet

echo "\n# No company data: GET returns null, login and sesion return empresa_nombre null\n";

expect_status('GET empresa without token -> 401', 401, http('GET', 'empresa.php'));
$r = http('GET', 'empresa.php', null, $tokA);
check('Owner GET empresa without data -> 200 empresa null', $r[0] === 200 && $r[1] === ['success' => true, 'empresa' => null], "got {$r[0]}: " . short($r[2]));
$r = login_request('Ariza');
check('Login empresa_nombre null without data', $r[0] === 200 && is_array($r[1]) && array_key_exists('empresa_nombre', $r[1]) && $r[1]['empresa_nombre'] === null, short($r[2]));
$r = http('GET', 'sesion.php', null, $tokA);
check('Sesion empresa_nombre null without data', $r[0] === 200 && is_array($r[1]) && array_key_exists('empresa_nombre', $r[1]) && $r[1]['empresa_nombre'] === null, short($r[2]));

// ---------------------------------------------------------------- 2. owner saves

echo "\n# Owner PUT: saves trimmed name, phone and address; GET returns them\n";

$saved = ['em_nom' => 'Soderia La Calera', 'em_tel' => '+54 351-555 1234', 'em_dir' => 'Av. San Martín 123'];
$r = http('PUT', 'empresa.php', ['nombre' => '  Soderia La Calera ', 'telefono' => ' +54 351-555 1234 ', 'direccion' => 'Av. San Martín 123'], $tokA);
check('Owner PUT empresa -> 200 with the stored data', $r[0] === 200 && $r[1] === ['success' => true, 'empresa' => $saved], "got {$r[0]}: " . short($r[2]));
check('Stored in sap_em00 for Ariza company', empresa_row($arizaId) === ['em_emp' => $arizaId] + $saved);
$r = http('GET', 'empresa.php', null, $tokA);
check('Owner GET empresa returns the saved data', $r[0] === 200 && $r[1] === ['success' => true, 'empresa' => $saved], short($r[2]));

$r = http('PUT', 'empresa.php', ['nombre' => 'Soderia La Calera', 'telefono' => '', 'direccion' => null], $tokA);
check('PUT with empty phone and null address clears them', $r[0] === 200 && empresa_row($arizaId) === ['em_emp' => $arizaId, 'em_nom' => 'Soderia La Calera', 'em_tel' => null, 'em_dir' => null], "got {$r[0]}: " . short($r[2]));
$r = http('PUT', 'empresa.php', ['nombre' => 'Soderia La Calera', 'telefono' => '+54 351-555 1234', 'direccion' => 'Av. San Martín 123'], $tokA);
check('PUT again updates the same row', $r[0] === 200 && (int) db_row('SELECT COUNT(*) AS n FROM sap_em00 WHERE em_emp = ?', 'i', [$arizaId])['n'] === 1 && empresa_row($arizaId) === ['em_emp' => $arizaId] + $saved);

$r = login_request('Ariza');
check('Login empresa_nombre = saved name', $r[0] === 200 && ($r[1]['empresa_nombre'] ?? null) === 'Soderia La Calera', short($r[2]));
check('Login keeps its other fields', is_array($r[1]) && array_keys($r[1]) === ['success', 'message', 'usuario', 'token', 'es_dueno', 'permisos', 'empresa_nombre'], short($r[2]));
$r = http('GET', 'sesion.php', null, $tokA);
check('Sesion empresa_nombre = saved name', $r[0] === 200 && ($r[1]['empresa_nombre'] ?? null) === 'Soderia La Calera', short($r[2]));

// ---------------------------------------------------------------- 3. employee and other company

echo "\n# Employee reads the owner's data and cannot edit; another company never sees it\n";

$r = http('GET', 'empresa.php', null, $tokV);
check('Vendedor GET empresa returns the owner company data', $r[0] === 200 && $r[1] === ['success' => true, 'empresa' => $saved], "got {$r[0]}: " . short($r[2]));
$r = login_request($vendName, 'secreto1');
check('Vendedor login empresa_nombre = company name', ($r[1]['empresa_nombre'] ?? null) === 'Soderia La Calera', short($r[2]));
$r = http('GET', 'sesion.php', null, $tokV);
check('Vendedor sesion empresa_nombre = company name', ($r[1]['empresa_nombre'] ?? null) === 'Soderia La Calera', short($r[2]));
$r = http('PUT', 'empresa.php', ['nombre' => 'Hack'], $tokV);
check('Vendedor PUT empresa -> 403 admin', $r[0] === 403 && $r[1] === ['success' => false, 'error' => FORBIDDEN, 'permiso' => 'admin'], "got {$r[0]}: " . short($r[2]));
check('Vendedor PUT leaves the data unchanged', empresa_row($arizaId) === ['em_emp' => $arizaId] + $saved);

$r = http('GET', 'empresa.php', null, $tokC);
check('Congre GET empresa -> null (never Ariza data)', $r[0] === 200 && $r[1] === ['success' => true, 'empresa' => null], short($r[2]));
$r = http('PUT', 'empresa.php', ['nombre' => 'Soda Congre'], $tokC);
check('Congre PUT saves only its own company', $r[0] === 200 && empresa_row($congreId)['em_nom'] === 'Soda Congre' && empresa_row($arizaId) === ['em_emp' => $arizaId] + $saved, short($r[2]));

// ---------------------------------------------------------------- 4. validation

echo "\n# Invalid input -> 400 and nothing changes\n";

$invalid = [
    'without nombre' => ['telefono' => '123'],
    'blank nombre' => ['nombre' => '   '],
    'nombre not a string' => ['nombre' => 123],
    '81-char nombre' => ['nombre' => str_repeat('a', 81)],
    'telefono with letters' => ['nombre' => 'X', 'telefono' => '351abc'],
    '31-char telefono' => ['nombre' => 'X', 'telefono' => str_repeat('1', 31)],
    '151-char direccion' => ['nombre' => 'X', 'direccion' => str_repeat('d', 151)],
];
foreach ($invalid as $label => $body) {
    expect_status("PUT empresa $label -> 400", 400, http('PUT', 'empresa.php', $body, $tokA));
}
expect_status('PUT empresa with a non-JSON body -> 400', 400, http('PUT', 'empresa.php', 'x', $tokA));
check('Rejected PUTs leave the data unchanged', empresa_row($arizaId) === ['em_emp' => $arizaId] + $saved);
$r = http('PUT', 'empresa.php', ['nombre' => str_repeat('ñ', 80), 'telefono' => str_repeat('1', 30), 'direccion' => str_repeat('d', 150)], $tokA);
check('PUT at the maximum lengths (80 multibyte, 30, 150) -> 200', $r[0] === 200 && empresa_row($arizaId)['em_nom'] === str_repeat('ñ', 80), "got {$r[0]}: " . short($r[2]));

expect_status('POST empresa -> 405', 405, http('POST', 'empresa.php', ['nombre' => 'X'], $tokA));

// ---------------------------------------------------------------- summary

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
