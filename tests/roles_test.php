<?php
// Roles and permissions tests for the REST API in api/ (roles-usuarios).
//
// Usage: php tests/roles_test.php
//
// Starts the PHP built-in server on 127.0.0.1:8766 (docroot api/) against the
// local database sodapp_test (with migration 011 applied), logs in as Ariza and
// Congre (owners of their companies), creates a role and a vendedor user of
// Ariza's company through the admin API, and checks company scope, permission
// enforcement (403 + permiso), deactivation, the login/session fields and the
// validation of usuarios.php and roles.php. Prints PASS/FAIL per case and exits
// with code 1 when any case fails.
//
// Every row created by the test is deleted at the end and Ariza's product touched
// by the vendedor sale is restored, so the script is re-runnable.
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
// ROLES_TEST_PORT overrides the port when 8766 is taken by another server
define('PORT', (int) (getenv('ROLES_TEST_PORT') ?: 8766));
const DB_HOST = 'localhost';
const DB_NAME = 'sodapp_test';
const DB_USER = 'root';
const DB_PASS = '';
const JWT_SECRET = 'local-test-secret-0123456789abcdef';
const PASSWORD = 'test1234';
const MARKER = 'zz_roles_test_';
const FORBIDDEN = 'Forbidden: missing permission';
const CATALOG = ['ventas.ver', 'ventas.cargar', 'ventas.eliminar', 'repartos.ver', 'repartos.gestionar', 'clientes.ver',
    'clientes.editar', 'cobros.ver', 'cobros.cargar', 'stock.ver', 'stock.editar', 'caja.ver'];
const VENDEDOR = ['ventas.ver', 'ventas.cargar', 'repartos.ver', 'repartos.gestionar', 'clientes.ver', 'clientes.editar'];

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
        fwrite(STDERR, 'Port ' . PORT . " is already in use; stop that server or set ROLES_TEST_PORT\n");
        exit(1);
    }
    $env = array_merge(getenv(), [
        'DB_HOST' => DB_HOST,
        'DB_NAME' => DB_NAME,
        'DB_USER' => DB_USER,
        'DB_PASS' => DB_PASS,
        'JWT_SECRET' => JWT_SECRET,
    ]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapp_roles_server.log';
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

function login_request($name, $password = PASSWORD) {
    return http('POST', 'login.php', [], ['nombreUs' => $name, 'contrasenaUs' => $password]);
}

function login($name, $password = PASSWORD) {
    list($status, $json, $raw) = login_request($name, $password);
    if ($status !== 200 || empty($json['token'])) {
        fwrite(STDERR, "Login failed for $name ($status): " . short($raw) . "\n");
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

// 403 with exactly {"success":false,"error":"Forbidden: missing permission","permiso":$code}
function expect_forbidden($name, $code, $response) {
    list($status, $json, $raw) = $response;
    $ok = $status === 403 && $json === ['success' => false, 'error' => FORBIDDEN, 'permiso' => $code];
    check("$name -> 403 $code", $ok, "got $status: " . short($raw));
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

function table_exists($table) {
    return db_row('SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        'ss', [DB_NAME, $table]) !== null;
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
    $ids = array_map('intval', array_column($rows, $col));
    sort($ids);
    return $ids;
}

// Restores a row to its snapshot.
function restore_row($table, $idCol, $snapshot) {
    $id = (int) $snapshot[$idCol];
    $current = db_row("SELECT * FROM $table WHERE $idCol = ?", 'i', [$id]);
    if ($current === $snapshot || $current === null) return;
    $cols = array_keys($snapshot);
    $values = array_values($snapshot);
    $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols));
    db_exec("UPDATE $table SET $sets WHERE $idCol = ?", str_repeat('s', count($values)) . 'i', array_merge($values, [$id]));
}

// ---------------------------------------------------------------- fixtures

if (!table_exists('sap_ro00') || !column_exists('sap_us00', 'us_emp')) {
    fwrite(STDERR, "Migration 011_roles_permisos.sql must be applied to " . DB_NAME . "\n");
    exit(1);
}
if (!column_exists('sap_pr00', 'deleted_at')) {
    fwrite(STDERR, "sap_pr00.deleted_at missing in " . DB_NAME . "; run tests/isolation_test.php setup first\n");
    exit(1);
}

$ariza = db_row('SELECT * FROM sap_us00 WHERE us_nom = ?', 's', ['Ariza']);
$congre = db_row('SELECT * FROM sap_us00 WHERE us_nom = ?', 's', ['Congre']);
if (!$ariza || !$congre) {
    fwrite(STDERR, "Users Ariza and Congre must exist in " . DB_NAME . " (tablasDB/migrations/000_local_seed_users.sql)\n");
    exit(1);
}
$arizaId = (int) $ariza['us_ide'];
$congreId = (int) $congre['us_ide'];
$congreRol = db_row('SELECT ro_ide FROM sap_ro00 WHERE ro_emp = ? ORDER BY ro_ide LIMIT 1', 'i', [$congreId]);
$congreRolId = $congreRol ? (int) $congreRol['ro_ide'] : 0;
$targetCl = db_row('SELECT * FROM sap_cl00 c WHERE cl_emp = ? AND is_deleted = 0
    AND EXISTS (SELECT 1 FROM sap_vt00 v WHERE v.vt_cli = c.cl_ide AND v.vt_emp = c.cl_emp) ORDER BY cl_ide LIMIT 1', 'i', [$arizaId]);
$targetPr = db_row("SELECT * FROM sap_pr00 WHERE pr_emp = ? AND deleted_at IS NULL AND pr_nom REGEXP '^[a-z]+( [a-z]+)*$' ORDER BY pr_ide LIMIT 1", 'i', [$arizaId]);
$targetVt = $targetCl ? db_row('SELECT * FROM sap_vt00 WHERE vt_cli = ? AND vt_emp = ? ORDER BY vt_ide LIMIT 1', 'ii', [(int) $targetCl['cl_ide'], $arizaId]) : null;
if (!$targetCl || !$targetPr || !$targetVt || !$congreRolId) {
    fwrite(STDERR, "Fixture data missing: Ariza needs a cliente with sales and a product, Congre a role (migration 011)\n");
    exit(1);
}
$targetClId = (int) $targetCl['cl_ide'];
$targetVtId = (int) $targetVt['vt_ide'];

$maxUs = max_id('sap_us00', 'us_ide');
$maxRo = max_id('sap_ro00', 'ro_ide');
$maxCl = max_id('sap_cl00', 'cl_ide');
$maxVt = max_id('sap_vt00', 'vt_ide');
$maxMs = max_id('sap_ms00', 'ms_ide');
$maxRp = max_id('sap_rp00', 'rp_ide');

function cleanup() {
    global $maxUs, $maxRo, $maxCl, $maxVt, $maxMs, $maxRp, $targetPr, $ariza, $congre;
    db_exec('DELETE FROM sap_rp00 WHERE rp_ide > ?', 'i', [$maxRp]);
    db_exec('DELETE FROM sap_ce00 WHERE ce_cli > ?', 'i', [$maxCl]);
    db_exec('DELETE FROM sap_ms00 WHERE ms_ide > ?', 'i', [$maxMs]);
    db_exec('DELETE FROM sap_vt00 WHERE vt_ide > ?', 'i', [$maxVt]);
    db_exec('DELETE FROM sap_cd00 WHERE cd_cli > ?', 'i', [$maxCl]);
    db_exec('DELETE FROM sap_cl00 WHERE cl_ide > ?', 'i', [$maxCl]);
    db_exec('DELETE FROM sap_us00 WHERE us_ide > ?', 'i', [$maxUs]);
    db_exec('DELETE FROM sap_ro00 WHERE ro_ide > ?', 'i', [$maxRo]);
    db_exec("DELETE FROM sap_us00 WHERE us_nom LIKE 'zz\\_roles\\_test\\_%'");
    restore_row('sap_pr00', 'pr_ide', $targetPr);
    restore_row('sap_us00', 'us_ide', $ariza);
    restore_row('sap_us00', 'us_ide', $congre);
}

$server = start_server();
register_shutdown_function(function () use ($server) {
    cleanup();
    stop_server($server);
});

$suffix = substr(md5(uniqid('', true)), 0, 6);

// ---------------------------------------------------------------- 1. owner login and session

echo "\n# Owner login and session: es_dueno, full catalog, company fields, no password\n";

$r = login_request('Ariza');
expect_status('Ariza login', 200, $r);
$tokA = $r[1]['token'] ?? null;
$u = $r[1]['usuario'] ?? [];
check('Owner login es_dueno true', ($r[1]['es_dueno'] ?? null) === true, short($r[2]));
check('Owner login permisos = full catalog', ($r[1]['permisos'] ?? null) === CATALOG, short($r[2]));
check('Owner login usuario has us_emp = us_ide, us_rol null, us_act 1',
    isset($u['us_ide'], $u['us_nom']) && (int) $u['us_ide'] === $arizaId && (int) ($u['us_emp'] ?? 0) === $arizaId
    && array_key_exists('us_rol', $u) && $u['us_rol'] === null && (int) ($u['us_act'] ?? 0) === 1, short($r[2]));
check('Owner login never returns us_pas', !array_key_exists('us_pas', $u));

$tokC = login('Congre');
if (!$tokA || !$tokC) exit(1);

$r = http('GET', 'sesion.php', [], null, $tokA);
expect_status('Owner GET sesion', 200, $r);
check('Owner sesion fields', $r[1] === ['success' => true, 'us_ide' => $arizaId, 'us_nom' => 'Ariza', 'es_dueno' => true, 'permisos' => CATALOG], short($r[2]));
expect_status('GET sesion without token -> 401', 401, http('GET', 'sesion.php'));

// ---------------------------------------------------------------- 2. owner data unchanged

echo "\n# Owner tokens see exactly what they saw before (company = owner)\n";

$dbClientes = ids(db_rows('SELECT cl_ide FROM sap_cl00 WHERE cl_emp = ? AND is_deleted = 0', 'i', [$arizaId]), 'cl_ide');
list(, $j) = http('GET', 'clientes.php', [], null, $tokA);
check('Owner clientes = her clientes in DB', ids(list_of($j, 'clientes'), 'cl_ide') === $dbClientes);
$dbProductos = ids(db_rows('SELECT pr_ide FROM sap_pr00 WHERE pr_emp = ? AND deleted_at IS NULL', 'i', [$arizaId]), 'pr_ide');
list(, $j) = http('GET', 'productos.php', [], null, $tokA);
check('Owner productos = her productos in DB', ids(list_of($j, 'productos'), 'pr_ide') === $dbProductos);
// the API returns the 15 newest sales of a cliente (joined with its product)
$dbVentas = ids(db_rows('SELECT vt.vt_ide FROM sap_vt00 vt JOIN sap_pr00 pr ON pr.pr_nom = vt.vt_pro AND pr.pr_emp = vt.vt_emp
    WHERE vt.vt_cli = ? AND vt.vt_emp = ? ORDER BY vt.vt_fec DESC LIMIT 15', 'ii', [$targetClId, $arizaId]), 'vt_ide');
list(, $j) = http('GET', 'ventas.php', ['id' => $targetClId, 'usuarioId' => $arizaId], null, $tokA);
check('Owner ventas of her cliente = DB rows', ids(list_of($j, 'ventas'), 'vt_ide') === $dbVentas);
list(, $j) = http('GET', 'clientes.php', [], null, $tokC);
check('Congre clientes has none of Ariza', !array_intersect(ids(list_of($j, 'clientes'), 'cl_ide'), $dbClientes));

// ---------------------------------------------------------------- 3. roles.php (owner)

echo "\n# roles.php: create, list, validation\n";

$rolName = MARKER . 'rol_' . $suffix;
$r = http('POST', 'roles.php', [], ['nombre' => '  ' . $rolName . ' ', 'permisos' => VENDEDOR], $tokA);
expect_status('Owner POST role', 201, $r);
$rolId = (int) ($r[1]['ro_ide'] ?? 0);
$rolRow = db_row('SELECT ro_emp, ro_nom FROM sap_ro00 WHERE ro_ide = ?', 'i', [$rolId]);
check('Role stored in Ariza company with trimmed name', $rolRow && (int) $rolRow['ro_emp'] === $arizaId && $rolRow['ro_nom'] === $rolName);
$rolPerms = array_column(db_rows('SELECT p.pm_cod FROM sap_rm00 m JOIN sap_pm00 p ON p.pm_ide = m.rm_per WHERE m.rm_rol = ? ORDER BY p.pm_ide', 'i', [$rolId]), 'pm_cod');
check('Role permissions stored', $rolPerms === VENDEDOR, implode(',', $rolPerms));
check('Owner POST role response', $r[1] === ['success' => true, 'ro_ide' => $rolId], short($r[2]));

expect_status('POST role with unknown code -> 400', 400, http('POST', 'roles.php', [], ['nombre' => MARKER . 'x' . $suffix, 'permisos' => ['ventas.ver', 'nope.nada']], $tokA));
expect_status('POST role with blank name -> 400', 400, http('POST', 'roles.php', [], ['nombre' => '   ', 'permisos' => []], $tokA));
expect_status('POST role with permisos not a list -> 400', 400, http('POST', 'roles.php', [], ['nombre' => MARKER . 'y' . $suffix, 'permisos' => 'ventas.ver'], $tokA));
expect_status('POST role with a duplicate name in the company -> 409', 409, http('POST', 'roles.php', [], ['nombre' => $rolName, 'permisos' => []], $tokA));
check('Rejected role posts stored nothing', (int) db_row('SELECT COUNT(*) AS n FROM sap_ro00 WHERE ro_ide > ?', 'i', [$maxRo])['n'] === 1);

$r = http('GET', 'roles.php', [], null, $tokA);
expect_status('Owner GET roles', 200, $r);
$mine = array_values(array_filter(list_of($r[1], 'roles'), function ($x) use ($rolId) { return (int) ($x['ro_ide'] ?? 0) === $rolId; }));
check('GET roles shows the role with its permisos and 0 users',
    $mine && $mine[0] === ['ro_ide' => $rolId, 'ro_nom' => $rolName, 'permisos' => VENDEDOR, 'usuarios' => 0], short(json_encode($mine)));
$disp = list_of($r[1], 'permisos_disponibles');
check('GET roles permisos_disponibles = catalog with descriptions',
    array_column($disp, 'pm_cod') === CATALOG && count(array_filter(array_column($disp, 'pm_des'))) === count(CATALOG));
$roleEmps = array_map(function ($x) { return (int) $x['ro_ide']; }, list_of($r[1], 'roles'));
$arizaRoleIds = ids(db_rows('SELECT ro_ide FROM sap_ro00 WHERE ro_emp = ?', 'i', [$arizaId]), 'ro_ide');
sort($roleEmps);
check('GET roles lists only the company roles', $roleEmps === $arizaRoleIds);
list(, $j) = http('GET', 'roles.php', [], null, $tokC);
check('Congre GET roles has none of Ariza', !array_intersect(ids(list_of($j, 'roles'), 'ro_ide'), $arizaRoleIds));

expect_status("Congre PUT Ariza's role -> 404", 404, http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => 'hack', 'permisos' => CATALOG], $tokC));
expect_status("Congre DELETE Ariza's role -> 404", 404, http('DELETE', 'roles.php', ['id' => $rolId], null, $tokC));
check('Congre attempts left the role unchanged', db_row('SELECT ro_nom FROM sap_ro00 WHERE ro_ide = ?', 'i', [$rolId])['ro_nom'] === $rolName
    && (int) db_row('SELECT COUNT(*) AS n FROM sap_rm00 WHERE rm_rol = ?', 'i', [$rolId])['n'] === count(VENDEDOR));

// ---------------------------------------------------------------- 4. usuarios.php (owner)

echo "\n# usuarios.php: create a vendedor, validation\n";

$vendName = MARKER . 'vend_' . $suffix;
$vendPass = 'secreto1';
$r = http('POST', 'usuarios.php', [], ['nombre' => ' ' . $vendName . ' ', 'contrasena' => $vendPass, 'rol' => $rolId], $tokA);
expect_status('Owner POST usuario', 201, $r);
$vendId = (int) ($r[1]['us_ide'] ?? 0);
check('POST usuario response', $r[1] === ['success' => true, 'us_ide' => $vendId], short($r[2]));
$vendRow = db_row('SELECT * FROM sap_us00 WHERE us_ide = ?', 'i', [$vendId]);
check('Vendedor stored in Ariza company with the role, active',
    $vendRow && $vendRow['us_nom'] === $vendName && (int) $vendRow['us_emp'] === $arizaId && (int) $vendRow['us_rol'] === $rolId && (int) $vendRow['us_act'] === 1);
check('Vendedor password stored hashed', $vendRow && $vendRow['us_pas'] !== $vendPass && password_verify($vendPass, $vendRow['us_pas']));

$countUs = function () use ($maxUs) { return (int) db_row('SELECT COUNT(*) AS n FROM sap_us00 WHERE us_ide > ?', 'i', [$maxUs])['n']; };
expect_status('POST usuario with a 2-char name -> 400', 400, http('POST', 'usuarios.php', [], ['nombre' => ' ab ', 'contrasena' => $vendPass, 'rol' => $rolId], $tokA));
expect_status('POST usuario with a 101-char name -> 400', 400, http('POST', 'usuarios.php', [], ['nombre' => str_repeat('a', 101), 'contrasena' => $vendPass, 'rol' => $rolId], $tokA));
expect_status('POST usuario with a 5-char password -> 400', 400, http('POST', 'usuarios.php', [], ['nombre' => MARKER . 'p' . $suffix, 'contrasena' => '12345', 'rol' => $rolId], $tokA));
expect_status('POST usuario without rol -> 400', 400, http('POST', 'usuarios.php', [], ['nombre' => MARKER . 'r' . $suffix, 'contrasena' => $vendPass], $tokA));
expect_status('POST usuario with a nonexistent rol -> 404', 404, http('POST', 'usuarios.php', [], ['nombre' => MARKER . 'n' . $suffix, 'contrasena' => $vendPass, 'rol' => 999999], $tokA));
expect_status("POST usuario with another company's rol -> 404", 404, http('POST', 'usuarios.php', [], ['nombre' => MARKER . 'o' . $suffix, 'contrasena' => $vendPass, 'rol' => $congreRolId], $tokA));
expect_status('POST usuario with an existing name (other company) -> 409', 409, http('POST', 'usuarios.php', [], ['nombre' => 'Congre', 'contrasena' => $vendPass, 'rol' => $rolId], $tokA));
expect_status('POST usuario with an existing name (case-insensitive) -> 409', 409, http('POST', 'usuarios.php', [], ['nombre' => strtoupper($vendName), 'contrasena' => $vendPass, 'rol' => $rolId], $tokA));
check('Rejected usuario posts stored nothing', $countUs() === 1);

$r = http('GET', 'usuarios.php', [], null, $tokA);
expect_status('Owner GET usuarios', 200, $r);
$byId = [];
foreach (list_of($r[1], 'usuarios') as $x) $byId[(int) $x['us_ide']] = $x;
check('GET usuarios lists the owner as es_dueno', isset($byId[$arizaId]) && $byId[$arizaId] === [
    'us_ide' => $arizaId, 'us_nom' => 'Ariza', 'us_rol' => null, 'ro_nom' => null, 'us_act' => 1, 'es_dueno' => true], short($r[2]));
check('GET usuarios lists the vendedor with its role', isset($byId[$vendId]) && $byId[$vendId] === [
    'us_ide' => $vendId, 'us_nom' => $vendName, 'us_rol' => $rolId, 'ro_nom' => $rolName, 'us_act' => 1, 'es_dueno' => false], short($r[2]));
$dbCompany = ids(db_rows('SELECT us_ide FROM sap_us00 WHERE us_emp = ?', 'i', [$arizaId]), 'us_ide');
$listed = array_keys($byId);
sort($listed);
check('GET usuarios lists exactly the company users', $listed === $dbCompany);
list(, $j) = http('GET', 'usuarios.php', [], null, $tokC);
$congreList = list_of($j, 'usuarios');
check('Congre GET usuarios has only Congre users (not the vendedor)',
    !in_array($vendId, ids($congreList, 'us_ide'), true) && in_array($congreId, ids($congreList, 'us_ide'), true));

$r = http('GET', 'roles.php', [], null, $tokA);
$mine = array_values(array_filter(list_of($r[1], 'roles'), function ($x) use ($rolId) { return (int) ($x['ro_ide'] ?? 0) === $rolId; }));
check('GET roles counts the assigned user', $mine && $mine[0]['usuarios'] === 1);

$ownerBefore = db_row('SELECT * FROM sap_us00 WHERE us_ide = ?', 'i', [$arizaId]);
expect_status('PUT usuario on the owner (rol) -> 400', 400, http('PUT', 'usuarios.php', [], ['id' => $arizaId, 'rol' => $rolId], $tokA));
expect_status('PUT usuario on the owner (activo 0) -> 400', 400, http('PUT', 'usuarios.php', [], ['id' => $arizaId, 'activo' => 0], $tokA));
check('Owner row unchanged', db_row('SELECT * FROM sap_us00 WHERE us_ide = ?', 'i', [$arizaId]) === $ownerBefore);
expect_status('PUT usuario of a nonexistent id -> 404', 404, http('PUT', 'usuarios.php', [], ['id' => 999999, 'activo' => 0], $tokA));
expect_status("PUT usuario of another company's user -> 404", 404, http('PUT', 'usuarios.php', [], ['id' => $congreId, 'activo' => 0], $tokA));
expect_status("Congre PUT Ariza's vendedor -> 404", 404, http('PUT', 'usuarios.php', [], ['id' => $vendId, 'activo' => 0], $tokC));
expect_status('PUT usuario with a short password -> 400', 400, http('PUT', 'usuarios.php', [], ['id' => $vendId, 'contrasena' => '123'], $tokA));
expect_status('PUT usuario with an invalid activo -> 400', 400, http('PUT', 'usuarios.php', [], ['id' => $vendId, 'activo' => 'si'], $tokA));
expect_status("PUT usuario with another company's rol -> 404", 404, http('PUT', 'usuarios.php', [], ['id' => $vendId, 'rol' => $congreRolId], $tokA));
expect_status('PUT usuario without id -> 400', 400, http('PUT', 'usuarios.php', [], ['activo' => 0], $tokA));
check('Rejected usuario puts left the vendedor unchanged', db_row('SELECT * FROM sap_us00 WHERE us_ide = ?', 'i', [$vendId]) === $vendRow);
check('Congre row unchanged', db_row('SELECT * FROM sap_us00 WHERE us_ide = ?', 'i', [$congreId]) === $congre);

// ---------------------------------------------------------------- 5. vendedor login and session

echo "\n# Vendedor login and session\n";

$r = login_request($vendName, $vendPass);
expect_status('Vendedor login', 200, $r);
$tokV = $r[1]['token'] ?? null;
$u = $r[1]['usuario'] ?? [];
check('Vendedor login es_dueno false', ($r[1]['es_dueno'] ?? null) === false, short($r[2]));
check('Vendedor login permisos = role permissions', ($r[1]['permisos'] ?? null) === VENDEDOR, short($r[2]));
check('Vendedor login usuario has company and role', (int) ($u['us_ide'] ?? 0) === $vendId && (int) ($u['us_emp'] ?? 0) === $arizaId
    && (int) ($u['us_rol'] ?? 0) === $rolId && (int) ($u['us_act'] ?? 0) === 1 && !array_key_exists('us_pas', $u), short($r[2]));
if (!$tokV) exit(1);
$r = http('GET', 'sesion.php', [], null, $tokV);
check('Vendedor sesion fields', $r[1] === ['success' => true, 'us_ide' => $vendId, 'us_nom' => $vendName, 'es_dueno' => false, 'permisos' => VENDEDOR], short($r[2]));

// ---------------------------------------------------------------- 6. vendedor sees and writes the company data

echo "\n# Vendedor sees Ariza's clientes, ventas and repartos and creates rows owned by Ariza's company\n";

list(, $j) = http('GET', 'clientes.php', [], null, $tokV);
check('Vendedor clientes = Ariza clientes', ids(list_of($j, 'clientes'), 'cl_ide') === $dbClientes);
list(, $j) = http('GET', 'ventas.php', ['id' => $targetClId, 'usuarioId' => $vendId], null, $tokV);
check('Vendedor ventas of an Ariza cliente = DB rows', ids(list_of($j, 'ventas'), 'vt_ide') === $dbVentas);
list($s, $j) = http('GET', 'productos.php', [], null, $tokV);
check('Vendedor can read productos (needed to load a sale)', $s === 200 && ids(list_of($j, 'productos'), 'pr_ide') === $dbProductos);
expect_status('Vendedor can read dias', 200, http('GET', 'dias.php', [], null, $tokV));

$vClName = MARKER . 'cl_' . $suffix;
expect_status('Vendedor POST cliente', 201, http('POST', 'clientes.php', [], ['nombreCl' => $vClName, 'numTelCl' => '351111', 'direccionCl' => 'Calle 1'], $tokV));
$row = db_row('SELECT cl_ide, cl_emp FROM sap_cl00 WHERE cl_nom = ? AND cl_ide > ?', 'si', [$vClName, $maxCl]);
$vClId = $row ? (int) $row['cl_ide'] : 0;
check('Vendedor cliente owned by Ariza company', $row && (int) $row['cl_emp'] === $arizaId);
list(, $j) = http('GET', 'clientes.php', [], null, $tokA);
check('Owner sees the vendedor cliente', in_array($vClId, ids(list_of($j, 'clientes'), 'cl_ide'), true));
list(, $j) = http('GET', 'clientes.php', [], null, $tokC);
check('Congre does not see the vendedor cliente', !in_array($vClId, ids(list_of($j, 'clientes'), 'cl_ide'), true));

$r = http('POST', 'ventas.php', [], ['clienteId' => $vClId, 'productos' => [['nombre' => $targetPr['pr_nom'], 'cantidad' => 1, 'precio' => 10]]], $tokV);
expect_status('Vendedor POST venta', 201, $r);
$row = db_row('SELECT vt_ide, vt_emp FROM sap_vt00 WHERE vt_cli = ? AND vt_ide > ?', 'ii', [$vClId, $maxVt]);
$vVtId = $row ? (int) $row['vt_ide'] : 0;
check('Vendedor venta owned by Ariza company', $row && (int) $row['vt_emp'] === $arizaId);
$ms = db_row('SELECT ms_emp FROM sap_ms00 WHERE ms_vta = ?', 'i', [$vVtId]);
check('Vendedor venta stock movement owned by Ariza company', $ms && (int) $ms['ms_emp'] === $arizaId);
list(, $j) = http('GET', 'ventas.php', ['id' => $vClId, 'usuarioId' => $arizaId], null, $tokA);
check('Owner sees the vendedor venta', count(list_of($j, 'ventas')) === 1);
list(, $j) = http('GET', 'ventas.php', ['id' => $vClId, 'usuarioId' => $congreId], null, $tokC);
check('Congre does not see the vendedor venta', count(list_of($j, 'ventas')) === 0);

$fecha = '2098-06-15';
$r = http('POST', 'repartos.php', [], ['accion' => 'crear', 'fecha' => $fecha, 'origen' => 'vacio'], $tokV);
expect_status('Vendedor creates a reparto', 201, $r);
$rp = db_row('SELECT rp_ide, rp_emp FROM sap_rp00 WHERE rp_fec = ? AND rp_ide > ?', 'si', [$fecha, $maxRp]);
check('Vendedor reparto owned by Ariza company', $rp && (int) $rp['rp_emp'] === $arizaId);
list($s, $j) = http('GET', 'repartos.php', ['fecha' => $fecha], null, $tokA);
check('Owner sees the vendedor reparto', $s === 200 && (int) ($j['reparto']['rp_ide'] ?? 0) === (int) ($rp['rp_ide'] ?? -1));
list($s, $j) = http('GET', 'repartos.php', ['fecha' => $fecha], null, $tokV);
check('Vendedor sees the reparto', $s === 200 && (int) ($j['reparto']['rp_ide'] ?? 0) === (int) ($rp['rp_ide'] ?? -1));
expect_status('Congre does not see the reparto', 404, http('GET', 'repartos.php', ['fecha' => $fecha], null, $tokC));
expect_status('Vendedor reads the route', 200, http('GET', 'ruta.php', ['repartoId' => (int) ($rp['rp_ide'] ?? 0)], null, $tokV));

// ---------------------------------------------------------------- 7. vendedor forbidden actions

echo "\n# Vendedor without the permission -> 403 with permiso, nothing changes\n";

$prBefore = db_row('SELECT * FROM sap_pr00 WHERE pr_ide = ?', 'i', [(int) $targetPr['pr_ide']]);
$clBefore = db_row('SELECT * FROM sap_cl00 WHERE cl_ide = ?', 'i', [$targetClId]);
$month = (int) date('n', strtotime($targetVt['vt_fec']));
$year = (int) date('Y', strtotime($targetVt['vt_fec']));
expect_forbidden('Vendedor PUT productos (stock)', 'stock.editar', http('PUT', 'productos.php', ['nombre' => $targetPr['pr_nom'], 'precio' => 1, 'cantidad' => 999], null, $tokV));
expect_forbidden('Vendedor POST productos', 'stock.editar', http('POST', 'productos.php', [], ['nombrePr' => MARKER . 'pr', 'precioUni' => 1, 'stock' => 1], $tokV));
expect_forbidden('Vendedor DELETE productos', 'stock.editar', http('DELETE', 'productos.php', ['nombre' => $targetPr['pr_nom']], null, $tokV));
expect_forbidden('Vendedor POST envases llenar', 'stock.editar', http('POST', 'envases.php', [], ['accion' => 'llenar', 'producto' => $targetPr['pr_nom'], 'cantidad' => 1], $tokV));
expect_forbidden('Vendedor GET movimientos', 'stock.ver', http('GET', 'movimientos.php', ['productoId' => (int) $targetPr['pr_ide']], null, $tokV));
expect_forbidden('Vendedor GET cajaMes', 'caja.ver', http('GET', 'cajaMes.php', ['mes' => $month, 'ano' => $year], null, $tokV));
expect_forbidden('Vendedor GET pagos of the month', 'caja.ver', http('GET', 'pagos.php', ['mes' => $month], null, $tokV));
$reporteRange = ['desde' => date('Y-m-01'), 'hasta' => date('Y-m-d')];
expect_forbidden('Vendedor GET reporte', 'caja.ver', http('GET', 'reporte.php', $reporteRange, null, $tokV));
expect_forbidden('Vendedor GET pagos of a cliente', 'cobros.ver', http('GET', 'pagos.php', ['clienteId' => $targetClId], null, $tokV));
expect_forbidden('Vendedor GET deudaCliente', 'cobros.ver', http('GET', 'deudaCliente.php', [], null, $tokV));
expect_forbidden('Vendedor PUT deudaCliente (cobro)', 'cobros.cargar', http('PUT', 'deudaCliente.php', ['id' => $targetClId, 'deuda' => 1], null, $tokV));
expect_forbidden('Vendedor DELETE ventas', 'ventas.eliminar', http('DELETE', 'ventas.php', ['id' => $targetVtId], null, $tokV));
expect_forbidden('Vendedor GET usuarios', 'admin', http('GET', 'usuarios.php', [], null, $tokV));
expect_forbidden('Vendedor POST usuarios', 'admin', http('POST', 'usuarios.php', [], ['nombre' => MARKER . 'h' . $suffix, 'contrasena' => 'secreto1', 'rol' => $rolId], $tokV));
expect_forbidden('Vendedor PUT usuarios (own role)', 'admin', http('PUT', 'usuarios.php', [], ['id' => $vendId, 'activo' => 1], $tokV));
expect_forbidden('Vendedor GET roles', 'admin', http('GET', 'roles.php', [], null, $tokV));
expect_forbidden('Vendedor PUT roles', 'admin', http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => $rolName, 'permisos' => CATALOG], $tokV));
check('Forbidden calls left the product unchanged', db_row('SELECT * FROM sap_pr00 WHERE pr_ide = ?', 'i', [(int) $targetPr['pr_ide']]) === $prBefore);
check('Forbidden calls left the cliente debt unchanged', db_row('SELECT * FROM sap_cl00 WHERE cl_ide = ?', 'i', [$targetClId]) === $clBefore);
check('Forbidden DELETE kept the venta', db_row('SELECT vt_ide FROM sap_vt00 WHERE vt_ide = ?', 'i', [$targetVtId]) !== null);
check('Forbidden calls created no user or role', $countUs() === 1 && (int) db_row('SELECT COUNT(*) AS n FROM sap_rm00 WHERE rm_rol = ?', 'i', [$rolId])['n'] === count(VENDEDOR));

// ---------------------------------------------------------------- 8. role change without re-login

echo "\n# Role permission change applies to the existing token\n";

$withCaja = array_merge(VENDEDOR, ['caja.ver']);
$r = http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => $rolName . 'b', 'permisos' => $withCaja], $tokA);
expect_status('Owner PUT role adds caja.ver and renames', 200, $r);
check('PUT role response', $r[1] === ['success' => true], short($r[2]));
check('PUT role stored the new name', db_row('SELECT ro_nom FROM sap_ro00 WHERE ro_ide = ?', 'i', [$rolId])['ro_nom'] === $rolName . 'b');
expect_status('Vendedor GET cajaMes with the same token -> 200', 200, http('GET', 'cajaMes.php', ['mes' => $month, 'ano' => $year], null, $tokV));
$r = http('GET', 'reporte.php', $reporteRange, null, $tokV);
expect_status('Vendedor GET reporte with caja.ver -> 200', 200, $r);
$ownerReporte = http('GET', 'reporte.php', $reporteRange, null, $tokA);
check('Vendedor reporte equals the owner reporte (company scope)', $r[1] !== null && $r[1] === $ownerReporte[1], short($r[2]));
check('Owner reporte of the month has sales', ($ownerReporte[1]['resumen']['ventas'] ?? 0) > 0, short($ownerReporte[2]));
$r = http('GET', 'sesion.php', [], null, $tokV);
check('Vendedor sesion shows caja.ver', ($r[1]['permisos'] ?? null) === array_merge(VENDEDOR, ['caja.ver']), short($r[2]));
expect_status('Owner PUT role removes clientes.ver', 200, http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => $rolName, 'permisos' => ['ventas.ver']], $tokA));
expect_forbidden('Vendedor GET cajaMes after removal', 'caja.ver', http('GET', 'cajaMes.php', ['mes' => $month, 'ano' => $year], null, $tokV));
expect_forbidden('Vendedor GET reporte after removal', 'caja.ver', http('GET', 'reporte.php', $reporteRange, null, $tokV));
list($s, $j) = http('GET', 'clientes.php', [], null, $tokV);
check('Vendedor still reads clientes with ventas.ver (reference read)', $s === 200);
expect_forbidden('Vendedor POST cliente after removal', 'clientes.editar', http('POST', 'clientes.php', [], ['nombreCl' => MARKER . 'no', 'numTelCl' => '1', 'direccionCl' => 'x'], $tokV));
expect_status('PUT role with an unknown code -> 400', 400, http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => $rolName, 'permisos' => ['x.y']], $tokA));
expect_status('PUT role with a nonexistent id -> 404', 404, http('PUT', 'roles.php', [], ['id' => 999999, 'nombre' => 'z', 'permisos' => []], $tokA));
$otherRol = db_row("SELECT ro_nom FROM sap_ro00 WHERE ro_emp = ? AND ro_ide <> ? ORDER BY ro_ide LIMIT 1", 'ii', [$arizaId, $rolId]);
if ($otherRol) {
    expect_status('PUT role renamed to another role of the company -> 409', 409, http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => $otherRol['ro_nom'], 'permisos' => []], $tokA));
}
check('Rejected role puts kept the last permissions', array_column(db_rows('SELECT p.pm_cod FROM sap_rm00 m JOIN sap_pm00 p ON p.pm_ide = m.rm_per WHERE m.rm_rol = ?', 'i', [$rolId]), 'pm_cod') === ['ventas.ver']);

echo "\n# Reduced roles: every guarded method rejects a missing permission\n";

// Role with ventas.ver only: no sale loading, no repartos, no ruta
$vtCount = (int) db_row('SELECT COUNT(*) AS n FROM sap_vt00 WHERE vt_emp = ?', 'i', [$arizaId])['n'];
$rpCount = (int) db_row('SELECT COUNT(*) AS n FROM sap_rp00 WHERE rp_emp = ?', 'i', [$arizaId])['n'];
expect_forbidden('ventas.ver only: POST ventas', 'ventas.cargar', http('POST', 'ventas.php', [], ['clienteId' => $targetClId, 'lineas' => []], $tokV));
expect_forbidden('ventas.ver only: GET repartos', 'repartos.ver', http('GET', 'repartos.php', [], null, $tokV));
expect_forbidden('ventas.ver only: POST repartos', 'repartos.gestionar', http('POST', 'repartos.php', [], ['accion' => 'crear'], $tokV));
expect_forbidden('ventas.ver only: GET ruta', 'repartos.ver', http('GET', 'ruta.php', [], null, $tokV));
expect_forbidden('ventas.ver only: POST ruta', 'repartos.gestionar', http('POST', 'ruta.php', [], ['accion' => 'iniciar'], $tokV));
check('Forbidden sale and reparto writes created no rows',
    (int) db_row('SELECT COUNT(*) AS n FROM sap_vt00 WHERE vt_emp = ?', 'i', [$arizaId])['n'] === $vtCount
    && (int) db_row('SELECT COUNT(*) AS n FROM sap_rp00 WHERE rp_emp = ?', 'i', [$arizaId])['n'] === $rpCount);

// Role with cobros.ver only: the sales of one cliente (debt screen), never the company list
expect_status('Owner PUT role to cobros.ver only', 200, http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => $rolName, 'permisos' => ['cobros.ver']], $tokA));
expect_status('cobros.ver only: GET ventas of a cliente -> 200', 200, http('GET', 'ventas.php', ['id' => $targetClId, 'usuarioId' => $arizaId], null, $tokV));
expect_forbidden('cobros.ver only: GET recent ventas', 'ventas.ver', http('GET', 'ventas.php', ['usuarioId' => $arizaId], null, $tokV));
expect_forbidden('cobros.ver only: GET ventas with id=0', 'ventas.ver', http('GET', 'ventas.php', ['id' => 0, 'usuarioId' => $arizaId], null, $tokV));
expect_forbidden('cobros.ver only: GET ventas with a non-numeric id', 'ventas.ver', http('GET', 'ventas.php', ['id' => 'abc', 'usuarioId' => $arizaId], null, $tokV));

http('PUT', 'roles.php', [], ['id' => $rolId, 'nombre' => $rolName, 'permisos' => VENDEDOR], $tokA);

// ---------------------------------------------------------------- 9. password change, deactivation, deletion

echo "\n# Password change, deactivation and a missing user\n";

$r = http('PUT', 'usuarios.php', [], ['id' => $vendId, 'contrasena' => 'nuevo123'], $tokA);
expect_status('Owner PUT usuario password', 200, $r);
check('PUT usuario response', $r[1] === ['success' => true], short($r[2]));
check('New password stored hashed', password_verify('nuevo123', db_row('SELECT us_pas FROM sap_us00 WHERE us_ide = ?', 'i', [$vendId])['us_pas']));
expect_status('Login with the old password -> 401', 401, login_request($vendName, $vendPass));
expect_status('Login with the new password', 200, login_request($vendName, 'nuevo123'));

expect_status('Owner PUT usuario activo 0', 200, http('PUT', 'usuarios.php', [], ['id' => $vendId, 'activo' => 0], $tokA));
check('Vendedor stored inactive', (int) db_row('SELECT us_act FROM sap_us00 WHERE us_ide = ?', 'i', [$vendId])['us_act'] === 0);
$r = login_request($vendName, 'nuevo123');
check('Inactive vendedor login -> 401 without token', $r[0] === 401 && empty($r[1]['token']), "got {$r[0]}: " . short($r[2]));
$r = http('GET', 'clientes.php', [], null, $tokV);
check('Inactive vendedor existing token -> 401 Invalid or expired token',
    $r[0] === 401 && $r[1] === ['success' => false, 'error' => 'Invalid or expired token'], "got {$r[0]}: " . short($r[2]));
expect_status('Inactive vendedor sesion -> 401', 401, http('GET', 'sesion.php', [], null, $tokV));
expect_status('Owner PUT usuario activo 1 and rol', 200, http('PUT', 'usuarios.php', [], ['id' => $vendId, 'activo' => 1, 'rol' => $rolId], $tokA));
$tokV2 = login($vendName, 'nuevo123');
check('Reactivated vendedor can log in', $tokV2 !== null);
expect_status('Reactivated vendedor old token works again', 200, http('GET', 'clientes.php', [], null, $tokV));

expect_status('DELETE role with users -> 409', 409, http('DELETE', 'roles.php', ['id' => $rolId], null, $tokA));
check('Role with users still exists', db_row('SELECT ro_ide FROM sap_ro00 WHERE ro_ide = ?', 'i', [$rolId]) !== null);
expect_status('DELETE role of a nonexistent id -> 404', 404, http('DELETE', 'roles.php', ['id' => 999999], null, $tokA));
$r = http('POST', 'roles.php', [], ['nombre' => MARKER . 'del_' . $suffix, 'permisos' => ['caja.ver', 'caja.ver']], $tokA);
expect_status('POST role with a repeated code', 201, $r);
$delId = (int) ($r[1]['ro_ide'] ?? 0);
check('Repeated code stored once', (int) db_row('SELECT COUNT(*) AS n FROM sap_rm00 WHERE rm_rol = ?', 'i', [$delId])['n'] === 1);
$r = http('DELETE', 'roles.php', ['id' => $delId], null, $tokA);
expect_status('DELETE unused role', 200, $r);
check('DELETE role response', $r[1] === ['success' => true], short($r[2]));
check('Deleted role and its permissions are gone', db_row('SELECT ro_ide FROM sap_ro00 WHERE ro_ide = ?', 'i', [$delId]) === null
    && (int) db_row('SELECT COUNT(*) AS n FROM sap_rm00 WHERE rm_rol = ?', 'i', [$delId])['n'] === 0);

db_exec('DELETE FROM sap_us00 WHERE us_ide = ?', 'i', [$vendId]);
$r = http('GET', 'clientes.php', [], null, $tokV);
check('Token of a deleted user -> 401 Invalid or expired token',
    $r[0] === 401 && $r[1] === ['success' => false, 'error' => 'Invalid or expired token'], "got {$r[0]}: " . short($r[2]));

// ---------------------------------------------------------------- summary

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
