<?php
// Minimal JWT helper (HS256). Uses getenv('JWT_SECRET') or fallback.
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($data, '-_', '+/'));
}

function generate_jwt($payload, $expirySeconds = 86400) {
    $secret = getenv('JWT_SECRET') ?: 'CHANGE_ME_PLEASE_OVERRIDE';
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
    $secret = getenv('JWT_SECRET') ?: 'CHANGE_ME_PLEASE_OVERRIDE';

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
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } else if (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }

    if (!$headers) return null;

    if (preg_match('/Bearer\s+(.*)$/i', $headers, $matches)) {
        return $matches[1];
    }
    return null;
}

?>
