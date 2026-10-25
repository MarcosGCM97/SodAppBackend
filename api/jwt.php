<?php
// Minimal JWT helper (HS256). Secret comes from config (see config.php).
require_once __DIR__ . '/config.php';

function jwt_secret() {
    $secret = config_value('JWT_SECRET');
    // An empty or short secret makes tokens forgeable
    if (strlen($secret) < 32) {
        error_log('JWT_SECRET is missing or shorter than 32 characters');
        http_response_code(500);
        die(json_encode(['error' => 'Server configuration error'], JSON_UNESCAPED_UNICODE));
    }
    return $secret;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($data, '-_', '+/'));
}

function generate_jwt($payload, $expirySeconds = 86400) {
    $secret = jwt_secret();
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];

    $now = time();
    $payload['iat'] = $now;
    $payload['exp'] = $now + intval($expirySeconds);

    $header_b64 = base64url_encode(json_encode($header));
    $payload_b64 = base64url_encode(json_encode($payload));

    $sig = hash_hmac('sha256', $header_b64 . '.' . $payload_b64, $secret, true);
    $sig_b64 = base64url_encode($sig);

    return $header_b64 . '.' . $payload_b64 . '.' . $sig_b64;
}

function verify_jwt($token) {
    $secret = jwt_secret();

    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;

    list($header_b64, $payload_b64, $sig_b64) = $parts;

    $sig = base64url_decode($sig_b64);

    $expected = hash_hmac('sha256', $header_b64 . '.' . $payload_b64, $secret, true);

    if (!hash_equals($expected, $sig)) return false;

    $payloadJson = base64url_decode($payload_b64);
    $payload = json_decode($payloadJson, true);
    if (!$payload) return false;

    if (isset($payload['exp']) && time() > intval($payload['exp'])) return false;

    return $payload;
}

function get_bearer_token() {
    $headers = null;
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } else if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        // Apache with PHP as CGI/FPM: the header arrives only through the .htaccess rewrite rule
        $headers = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } else if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $headers = trim($value);
                break;
            }
        }
    }

    if (!$headers) return null;

    if (preg_match('/Bearer\s+(.*)$/i', $headers, $matches)) {
        return $matches[1];
    }
    return null;
}

?>
