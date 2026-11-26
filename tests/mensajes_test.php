<?php
// WhatsApp message template tests for the REST API in api/ (plantillas-mensajes).
//
// Usage: php tests/mensajes_test.php
//
// Starts the PHP built-in server on 127.0.0.1:8768 (docroot api/) against the
// local database sodapp_test (with migrations 011 and 013 applied), logs in as
// Ariza and Congre (owners of their companies), creates a vendedor user of
// Ariza's company and checks mensajes.php (GET for every user of the company,
// PUT/DELETE only for the owner, allowed variables per type, company scope).
// Prints PASS/FAIL per case and exits with code 1 when any case fails.
//
// The sap_msj00 rows of Ariza and Congre are restored and the vendedor is deleted
// at the end, so the script is re-runnable. Requires the curl and mysqli
// extensions. Never point it at production.

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
// MENSAJES_TEST_PORT overrides the port when 8768 is taken by another server
define('PORT', (int) (getenv('MENSAJES_TEST_PORT') ?: 8768));
const DB_HOST = 'localhost';
const DB_NAME = 'sodapp_test';
const DB_USER = 'root';
const DB_PASS = '';
const JWT_SECRET = 'local-test-secret-0123456789abcdef';
const PASSWORD = 'test1234';
const MARKER = 'zz_mensajes_test_';
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
        fwrite(STDERR, 'Port ' . PORT . " is already in use; stop that server or set MENSAJES_TEST_PORT\n");
        exit(1);
    }
    $env = array_merge(getenv(), [
        'DB_HOST' => DB_HOST,
        'DB_NAME' => DB_NAME,
        'DB_USER' => DB_USER,
        'DB_PASS' => DB_PASS,
        'JWT_SECRET' => JWT_SECRET,
    ]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapp_mensajes_server.log';
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

function plantillas_rows($companyId) {
    return db_rows('SELECT ms_tip, ms_txt FROM sap_msj00 WHERE ms_emp = ? ORDER BY ms_tip', 'i', [$companyId]);
}

// Puts back the sap_msj00 rows of a company as they were before the test
function restore_plantillas($companyId, $snapshot) {
    db_exec('DELETE FROM sap_msj00 WHERE ms_emp = ?', 'i', [$companyId]);
    foreach ($snapshot as $row) {
        db_exec('INSERT INTO sap_msj00 (ms_emp, ms_tip, ms_txt) VALUES (?, ?, ?)', 'iss',
            [$companyId, $row['ms_tip'], $row['ms_txt']]);
    }
}

// ---------------------------------------------------------------- fixtures

if (!db_row("SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'sap_msj00'", 's', [DB_NAME])) {
    fwrite(STDERR, "Migration 013_plantillas_mensajes.sql must be applied to " . DB_NAME . "\n");
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

$snapA = plantillas_rows($arizaId);
$snapC = plantillas_rows($congreId);
$maxUs = (int) db_row('SELECT COALESCE(MAX(us_ide), 0) AS m FROM sap_us00')['m'];

function cleanup() {
    global $arizaId, $congreId, $snapA, $snapC, $maxUs;
    db_exec('DELETE FROM sap_us00 WHERE us_ide > ?', 'i', [$maxUs]);
    db_exec("DELETE FROM sap_us00 WHERE us_nom LIKE 'zz\\_mensajes\\_test\\_%'");
    restore_plantillas($arizaId, $snapA);
    restore_plantillas($congreId, $snapC);
}

$server = start_server();
register_shutdown_function(function () use ($server) {
    cleanup();
    stop_server($server);
});

// Both companies start without templates
db_exec('DELETE FROM sap_msj00 WHERE ms_emp IN (?, ?)', 'ii', [$arizaId, $congreId]);

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

$deuda = "Hola {cliente}\n{saldo}\n{empresa}";
$envases = "{cliente}, tenés estos envases:\n{envases}\n{empresa}";
$venta = "{cliente}: {productos} Total {total}. {saldo} {empresa}";

// ---------------------------------------------------------------- 1. no templates yet

echo "\n# No templates: GET returns an empty object\n";

expect_status('GET mensajes without token -> 401', 401, http('GET', 'mensajes.php'));
$r = http('GET', 'mensajes.php', null, $tokA);
check('Owner GET without templates -> 200 plantillas {}', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => []] && strpos($r[2], '"plantillas":{}') !== false, "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- 2. owner saves

echo "\n# Owner PUT: saves one template per type; GET returns them\n";

$r = http('PUT', 'mensajes.php', ['tipo' => 'deuda', 'texto' => "  $deuda \n"], $tokA);
check('Owner PUT deuda -> 200 with the trimmed text', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => ['deuda' => $deuda]], "got {$r[0]}: " . short($r[2]));
$r = http('PUT', 'mensajes.php', ['tipo' => 'envases', 'texto' => $envases], $tokA);
check('Owner PUT envases -> 200', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => ['deuda' => $deuda, 'envases' => $envases]], "got {$r[0]}: " . short($r[2]));
$r = http('PUT', 'mensajes.php', ['tipo' => 'venta', 'texto' => $venta], $tokA);
$all = ['deuda' => $deuda, 'envases' => $envases, 'venta' => $venta];
check('Owner PUT venta -> 200 with the three templates', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => $all], "got {$r[0]}: " . short($r[2]));
check('Stored in sap_msj00 for Ariza company', plantillas_rows($arizaId) === [
    ['ms_tip' => 'deuda', 'ms_txt' => $deuda], ['ms_tip' => 'envases', 'ms_txt' => $envases], ['ms_tip' => 'venta', 'ms_txt' => $venta]]);
$r = http('GET', 'mensajes.php', null, $tokA);
check('Owner GET returns the saved templates', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => $all], short($r[2]));

$deuda2 = 'Saldo: {saldo}';
$r = http('PUT', 'mensajes.php', ['tipo' => 'deuda', 'texto' => $deuda2], $tokA);
check('PUT again replaces the same row', $r[0] === 200 && (int) db_row("SELECT COUNT(*) AS n FROM sap_msj00 WHERE ms_emp = ? AND ms_tip = 'deuda'", 'i', [$arizaId])['n'] === 1 && ($r[1]['plantillas']['deuda'] ?? null) === $deuda2, "got {$r[0]}: " . short($r[2]));
$r = http('PUT', 'mensajes.php', ['tipo' => 'deuda', 'texto' => 'Sin variables, solo texto'], $tokA);
check('PUT a text without variables -> 200', $r[0] === 200, "got {$r[0]}: " . short($r[2]));
http('PUT', 'mensajes.php', ['tipo' => 'deuda', 'texto' => $deuda], $tokA);

// ---------------------------------------------------------------- 3. employee and other company

echo "\n# Employee reads the owner's templates and cannot edit; another company never sees them\n";

$r = http('GET', 'mensajes.php', null, $tokV);
check('Vendedor GET returns the owner company templates', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => $all], "got {$r[0]}: " . short($r[2]));
$r = http('PUT', 'mensajes.php', ['tipo' => 'deuda', 'texto' => 'Hack'], $tokV);
check('Vendedor PUT -> 403 admin', $r[0] === 403 && $r[1] === ['success' => false, 'error' => FORBIDDEN, 'permiso' => 'admin'], "got {$r[0]}: " . short($r[2]));
$r = http('DELETE', 'mensajes.php?tipo=deuda', null, $tokV);
check('Vendedor DELETE -> 403 admin', $r[0] === 403 && $r[1] === ['success' => false, 'error' => FORBIDDEN, 'permiso' => 'admin'], "got {$r[0]}: " . short($r[2]));
check('Vendedor PUT/DELETE leave the templates unchanged', count(plantillas_rows($arizaId)) === 3 && plantillas_rows($arizaId)[0]['ms_txt'] === $deuda);

$r = http('GET', 'mensajes.php', null, $tokC);
check('Congre GET -> {} (never Ariza templates)', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => []], short($r[2]));
$r = http('PUT', 'mensajes.php', ['tipo' => 'venta', 'texto' => 'Gracias {cliente}'], $tokC);
check('Congre PUT saves only its own company', $r[0] === 200 && plantillas_rows($congreId) === [['ms_tip' => 'venta', 'ms_txt' => 'Gracias {cliente}']] && plantillas_rows($arizaId)[2]['ms_txt'] === $venta, short($r[2]));
$r = http('DELETE', 'mensajes.php?tipo=venta', null, $tokC);
check('Congre DELETE never touches Ariza templates', $r[0] === 200 && plantillas_rows($congreId) === [] && count(plantillas_rows($arizaId)) === 3, short($r[2]));

// ---------------------------------------------------------------- 4. delete restores the default

echo "\n# Owner DELETE: removes one template (the app goes back to its default text)\n";

$r = http('DELETE', 'mensajes.php?tipo=envases', null, $tokA);
check('Owner DELETE envases -> 200 without envases', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => ['deuda' => $deuda, 'venta' => $venta]], "got {$r[0]}: " . short($r[2]));
$r = http('DELETE', 'mensajes.php?tipo=envases', null, $tokA);
check('DELETE a missing template -> 200 unchanged', $r[0] === 200 && $r[1] === ['success' => true, 'plantillas' => ['deuda' => $deuda, 'venta' => $venta]], "got {$r[0]}: " . short($r[2]));
$r = http('DELETE', 'mensajes.php?tipo=otro', null, $tokA);
check('DELETE with an invalid tipo -> 400', $r[0] === 400 && $r[1] === ['success' => false, 'error' => 'Tipo inválido: deuda, envases o venta'], "got {$r[0]}: " . short($r[2]));
expect_status('DELETE without tipo -> 400', 400, http('DELETE', 'mensajes.php', null, $tokA));

// ---------------------------------------------------------------- 5. validation

echo "\n# Invalid input -> 400 and nothing changes\n";

$before = plantillas_rows($arizaId);
$invalid = [
    'without tipo' => [['texto' => 'Hola'], 'Tipo inválido: deuda, envases o venta'],
    'unknown tipo' => [['tipo' => 'resumen', 'texto' => 'Hola'], 'Tipo inválido: deuda, envases o venta'],
    'without texto' => [['tipo' => 'deuda'], 'Datos incompletos: texto'],
    'blank texto' => [['tipo' => 'deuda', 'texto' => "  \n "], 'Datos incompletos: texto'],
    'texto not a string' => [['tipo' => 'deuda', 'texto' => 12], 'Texto inválido: hasta 1000 caracteres'],
    '1001-char texto' => [['tipo' => 'deuda', 'texto' => str_repeat('a', 1001)], 'Texto inválido: hasta 1000 caracteres'],
    'deuda with {productos}' => [['tipo' => 'deuda', 'texto' => 'Hola {productos}'], 'Variable no permitida en deuda: {productos}. Permitidas: {cliente}, {saldo}, {empresa}'],
    'envases with {saldo}' => [['tipo' => 'envases', 'texto' => '{saldo}'], 'Variable no permitida en envases: {saldo}. Permitidas: {cliente}, {envases}, {empresa}'],
    'venta with {envases}' => [['tipo' => 'venta', 'texto' => '{cliente} {envases}'], 'Variable no permitida en venta: {envases}. Permitidas: {cliente}, {productos}, {total}, {saldo}, {empresa}'],
    'unknown variable' => [['tipo' => 'deuda', 'texto' => 'Hola {nombre}'], 'Variable no permitida en deuda: {nombre}. Permitidas: {cliente}, {saldo}, {empresa}'],
    'variable in uppercase' => [['tipo' => 'deuda', 'texto' => 'Hola {Cliente}'], 'Variable no permitida en deuda: {Cliente}. Permitidas: {cliente}, {saldo}, {empresa}'],
];
foreach ($invalid as $label => list($body, $error)) {
    $r = http('PUT', 'mensajes.php', $body, $tokA);
    check("PUT $label -> 400 \"$error\"", $r[0] === 400 && $r[1] === ['success' => false, 'error' => $error], "got {$r[0]}: " . short($r[2]));
}
expect_status('PUT with a non-JSON body -> 400', 400, http('PUT', 'mensajes.php', 'x', $tokA));
check('Rejected PUTs leave the templates unchanged', plantillas_rows($arizaId) === $before);
$max = str_repeat('ñ', 990) . '{cliente}';
$r = http('PUT', 'mensajes.php', ['tipo' => 'venta', 'texto' => $max], $tokA);
check('PUT at the maximum length (999 multibyte + variable = 999) -> 200', $r[0] === 200 && plantillas_rows($arizaId)[1]['ms_txt'] === $max, "got {$r[0]}: " . short($r[2]));
$max = str_repeat('ñ', 1000);
$r = http('PUT', 'mensajes.php', ['tipo' => 'venta', 'texto' => $max], $tokA);
check('PUT 1000 multibyte characters -> 200', $r[0] === 200 && plantillas_rows($arizaId)[1]['ms_txt'] === $max, "got {$r[0]}: " . short($r[2]));
$r = http('PUT', 'mensajes.php', ['tipo' => 'deuda', 'texto' => 'Llaves sueltas { y } no son variables'], $tokA);
check('PUT with braces that are not a variable -> 200', $r[0] === 200, "got {$r[0]}: " . short($r[2]));

expect_status('POST mensajes -> 405', 405, http('POST', 'mensajes.php', ['tipo' => 'deuda', 'texto' => 'X'], $tokA));

// ---------------------------------------------------------------- summary

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
