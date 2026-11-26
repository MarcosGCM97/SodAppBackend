<?php
// Database connection charset tests for the REST API in api/ (conexion.php).
//
// Usage: php tests/conexion_test.php
//
// Starts the PHP built-in server on 127.0.0.1:8772 (docroot api/) against the
// local database sodapp_test, logs in as Congre and checks that text with
// 4-byte characters (emoji, as in the default WhatsApp messages) is stored and
// read back unchanged, while accents and 3-byte characters keep working.
// Prints PASS/FAIL per case and exits with code 1 when any case fails.
//
// Every client it creates has a name starting with "Zz Utf8 " and is deleted at
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
// CONEXION_TEST_PORT overrides the port when 8772 is taken by another server
define('PORT', (int) (getenv('CONEXION_TEST_PORT') ?: 8772));
const DB_HOST = 'localhost';
const DB_NAME = 'sodapp_test';
const DB_USER = 'root';
const DB_PASS = '';
const JWT_SECRET = 'local-test-secret-0123456789abcdef';
const PASSWORD = 'test1234';

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

function start_server() {
    $apiDir = realpath(__DIR__ . '/../api');
    // never run the cases against a server of another checkout
    $busy = @fsockopen(HOST, PORT, $errno, $errstr, 0.2);
    if ($busy) {
        fclose($busy);
        fwrite(STDERR, 'Port ' . PORT . " is already in use; stop that server or set CONEXION_TEST_PORT\n");
        exit(1);
    }
    $env = array_merge(getenv(), [
        'DB_HOST' => DB_HOST,
        'DB_NAME' => DB_NAME,
        'DB_USER' => DB_USER,
        'DB_PASS' => DB_PASS,
        'JWT_SECRET' => JWT_SECRET,
    ]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapp_conexion_server.log';
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

/** Sends a request and returns [status, decoded JSON or null, raw body]. */
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

// Stored name of a client, read with a utf8mb4 connection (the bytes the API wrote)
function nombre_guardado($id) {
    $stmt = mysqli_prepare(db(), 'SELECT cl_nom FROM sap_cl00 WHERE cl_ide = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? $row['cl_nom'] : null;
}

$server = start_server();
register_shutdown_function(function () use ($server) {
    mysqli_query(db(), "DELETE FROM sap_cl00 WHERE cl_nom LIKE 'Zz Utf8 %'");
    stop_server($server);
});

$r = http('POST', 'login.php', ['nombreUs' => 'Congre', 'contrasenaUs' => PASSWORD]);
$tok = $r[1]['token'] ?? null;
if (!$tok) {
    fwrite(STDERR, "Login failed for Congre\n");
    exit(1);
}

$suffix = substr(md5(uniqid('', true)), 0, 6);

echo "\n# Text is stored and read back unchanged, 4-byte characters included\n";

$casos = [
    'emoji (4 bytes)' => "Zz Utf8 Juan 💧👋 $suffix",
    'accents and ñ (2 bytes)' => "Zz Utf8 Peña Ávila $suffix",
    'em dash and euro (3 bytes)' => "Zz Utf8 Sur — 5€ $suffix",
];
foreach ($casos as $label => $nombre) {
    $r = http('POST', 'clientes.php', ['nombreCl' => $nombre, 'numTelCl' => '351555', 'direccionCl' => 'Calle 1'], $tok);
    $id = (int) ($r[1]['id'] ?? 0);
    check("POST client with $label -> 201", $r[0] === 201 && $id > 0, "got {$r[0]}: " . short($r[2]));
    if ($id <= 0) continue;
    check("Stored name with $label is byte-identical", nombre_guardado($id) === $nombre, bin2hex((string) nombre_guardado($id)));
    $r = http('GET', "clientes.php?id=$id", null, $tok);
    $leido = $r[0] === 200 && is_array($r[1]) && strpos(json_encode($r[1], JSON_UNESCAPED_UNICODE), $nombre) !== false;
    check("GET returns the name with $label unchanged", $leido, short($r[2]));
}

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
