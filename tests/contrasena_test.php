<?php
// Own password change tests for the REST API in api/ (contrasena.php).
//
// Usage: php tests/contrasena_test.php
//
// Starts the PHP built-in server on 127.0.0.1:8770 (docroot api/) against the
// local database sodapp_test (with migration 011 applied), logs in as Ariza
// and Congre (owners of their companies), creates a vendedor user of Ariza's
// company and checks contrasena.php: only the owner changes its own password,
// the current password is required, the new one follows the usuarios.php
// rule (at least 6 characters) and a rejected request changes nothing.
// Prints PASS/FAIL per case and exits with code 1 when any case fails.
//
// The passwords of Ariza and Congre are restored and the vendedor is deleted at
// the end, so the script is re-runnable. Requires the curl and mysqli
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
// CONTRASENA_TEST_PORT overrides the port when 8770 is taken by another server
define('PORT', (int) (getenv('CONTRASENA_TEST_PORT') ?: 8770));
const DB_HOST = 'localhost';
const DB_NAME = 'sodapp_test';
const DB_USER = 'root';
const DB_PASS = '';
const JWT_SECRET = 'local-test-secret-0123456789abcdef';
const PASSWORD = 'test1234';
const MARKER = 'zz_contrasena_test_';
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
        fwrite(STDERR, 'Port ' . PORT . " is already in use; stop that server or set CONTRASENA_TEST_PORT\n");
        exit(1);
    }
    $env = array_merge(getenv(), [
        'DB_HOST' => DB_HOST,
        'DB_NAME' => DB_NAME,
        'DB_USER' => DB_USER,
        'DB_PASS' => DB_PASS,
        'JWT_SECRET' => JWT_SECRET,
    ]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapp_contrasena_server.log';
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
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

function login_ok($name, $password) {
    $r = login_request($name, $password);
    return $r[0] === 200 && !empty($r[1]['success']) && !empty($r[1]['token']);
}

function short($raw) {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    return strlen($raw) > 160 ? substr($raw, 0, 160) . '...' : $raw;
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
        return;
    }
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function password_hash_of($userId) {
    $row = db_row('SELECT us_pas FROM sap_us00 WHERE us_ide = ?', 'i', [$userId]);
    return $row ? $row['us_pas'] : null;
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
$arizaRol = db_row('SELECT ro_ide FROM sap_ro00 WHERE ro_emp = ? ORDER BY ro_ide LIMIT 1', 'i', [$arizaId]);
if (!$arizaRol) {
    fwrite(STDERR, "Ariza needs a role (migration 011)\n");
    exit(1);
}

$hashA = password_hash_of($arizaId);
$hashC = password_hash_of($congreId);
$maxUs = (int) db_row('SELECT COALESCE(MAX(us_ide), 0) AS m FROM sap_us00')['m'];

function cleanup() {
    global $arizaId, $congreId, $hashA, $hashC, $maxUs;
    db_exec('DELETE FROM sap_us00 WHERE us_ide > ?', 'i', [$maxUs]);
    db_exec("DELETE FROM sap_us00 WHERE us_nom LIKE 'zz\\_contrasena\\_test\\_%'");
    db_exec('UPDATE sap_us00 SET us_pas = ? WHERE us_ide = ?', 'si', [$hashA, $arizaId]);
    db_exec('UPDATE sap_us00 SET us_pas = ? WHERE us_ide = ?', 'si', [$hashC, $congreId]);
}

$server = start_server();
register_shutdown_function(function () use ($server) {
    cleanup();
    stop_server($server);
});

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
http('POST', 'usuarios.php', ['nombre' => $vendName, 'contrasena' => 'secreto1', 'rol' => (int) $arizaRol['ro_ide']], $tokA);
$r = login_request($vendName, 'secreto1');
$tokV = $r[1]['token'] ?? null;
$vendId = (int) ($r[1]['usuario']['us_ide'] ?? 0);
if (!$tokV || $vendId <= 0) {
    fwrite(STDERR, "Could not create and log in the vendedor: " . short($r[2]) . "\n");
    exit(1);
}
$hashV = password_hash_of($vendId);

// ---------------------------------------------------------------- 1. access

echo "\n# Only the logged owner changes its own password\n";

$r = http('PUT', 'contrasena.php', ['actual' => PASSWORD, 'nueva' => 'nueva123']);
check('PUT contrasena without token -> 401', $r[0] === 401, "got {$r[0]}: " . short($r[2]));
check('Without token the owner password is unchanged', password_hash_of($arizaId) === $hashA);

$r = http('PUT', 'contrasena.php', ['actual' => 'secreto1', 'nueva' => 'nueva123'], $tokV);
check('Vendedor PUT contrasena -> 403 admin', $r[0] === 403 && $r[1] === ['success' => false, 'error' => FORBIDDEN, 'permiso' => 'admin'], "got {$r[0]}: " . short($r[2]));
check('Vendedor password unchanged after the 403', password_hash_of($vendId) === $hashV && login_ok($vendName, 'secreto1'));

// ---------------------------------------------------------------- 2. validation

echo "\n# Rejected requests -> 400 with the message, nothing changes\n";

$r = http('PUT', 'contrasena.php', ['actual' => 'incorrecta', 'nueva' => 'nueva123'], $tokA);
check('Wrong current password -> 400 "La contraseña actual es incorrecta"',
    $r[0] === 400 && $r[1] === ['success' => false, 'error' => 'La contraseña actual es incorrecta'], "got {$r[0]}: " . short($r[2]));

$r = http('PUT', 'contrasena.php', ['actual' => PASSWORD, 'nueva' => '12345'], $tokA);
check('5-character new password -> 400 "La contraseña nueva debe tener al menos 6 caracteres"',
    $r[0] === 400 && $r[1] === ['success' => false, 'error' => 'La contraseña nueva debe tener al menos 6 caracteres'], "got {$r[0]}: " . short($r[2]));

$incomplete = [
    'without actual' => ['nueva' => 'nueva123'],
    'without nueva' => ['actual' => PASSWORD],
    'actual not a string' => ['actual' => 1234, 'nueva' => 'nueva123'],
    'nueva not a string' => ['actual' => PASSWORD, 'nueva' => 123456],
    'empty object' => [],
];
foreach ($incomplete as $label => $body) {
    $r = http('PUT', 'contrasena.php', $body, $tokA);
    check("PUT contrasena $label -> 400 \"Datos incompletos: actual y nueva\"",
        $r[0] === 400 && $r[1] === ['success' => false, 'error' => 'Datos incompletos: actual y nueva'], "got {$r[0]}: " . short($r[2]));
}
$r = http('PUT', 'contrasena.php', 'x', $tokA);
check('PUT contrasena with a non-JSON body -> 400', $r[0] === 400, "got {$r[0]}: " . short($r[2]));

check('Rejected PUTs leave the owner password unchanged', password_hash_of($arizaId) === $hashA && login_ok('Ariza', PASSWORD));

$r = http('POST', 'contrasena.php', ['actual' => PASSWORD, 'nueva' => 'nueva123'], $tokA);
check('POST contrasena -> 405', $r[0] === 405, "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- 3. change

echo "\n# Valid change: the new password logs in, the old one no longer does\n";

$nueva = 'ñandú1';
$r = http('PUT', 'contrasena.php', ['actual' => PASSWORD, 'nueva' => $nueva], $tokA);
check('Owner PUT contrasena with 6 multibyte characters -> 200 success', $r[0] === 200 && $r[1] === ['success' => true], "got {$r[0]}: " . short($r[2]));
$hashNuevo = password_hash_of($arizaId);
check('Stored as a password hash, never in plain text', $hashNuevo !== $hashA && $hashNuevo !== $nueva && password_verify($nueva, $hashNuevo));
check('Login with the new password works', login_ok('Ariza', $nueva));
check('Login with the old password fails', !login_ok('Ariza', PASSWORD));
check('Other users keep their passwords', password_hash_of($congreId) === $hashC && password_hash_of($vendId) === $hashV);

$r = http('PUT', 'contrasena.php', ['actual' => PASSWORD, 'nueva' => 'otra1234'], $tokA);
check('The old password is no longer accepted as the current one -> 400',
    $r[0] === 400 && ($r[1]['error'] ?? null) === 'La contraseña actual es incorrecta' && password_hash_of($arizaId) === $hashNuevo, "got {$r[0]}: " . short($r[2]));

$r = http('PUT', 'contrasena.php', ['actual' => PASSWORD, 'nueva' => 'otra1234'], $tokC);
check('Congre changes only its own password', $r[0] === 200 && login_ok('Congre', 'otra1234') && password_hash_of($arizaId) === $hashNuevo, "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- 4. usuarios.php keeps its rules

echo "\n# usuarios.php still validates passwords with the shared rule\n";

$r = http('PUT', 'usuarios.php', ['id' => $vendId, 'contrasena' => '12345'], $tokA);
check('usuarios.php PUT with a 5-character password -> 400', $r[0] === 400 && password_hash_of($vendId) === $hashV, "got {$r[0]}: " . short($r[2]));
$r = http('PUT', 'usuarios.php', ['id' => $arizaId, 'contrasena' => 'nueva123'], $tokA);
check('usuarios.php still cannot change the owner row -> 400', $r[0] === 400 && password_hash_of($arizaId) === $hashNuevo, "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- summary

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
