<?php
// Secrets live outside the repo and outside public_html.
// Lookup order: environment variable, then the config file.
// Config file: SODAPP_CONFIG env var, or ../../sodapp-config.php (next to public_html).
// A missing value stops the request instead of falling back to a default.

function config_value($key) {
    $env = getenv($key);
    // A defined but empty variable is a valid value (e.g. local root without password)
    if ($env !== false) {
        return $env;
    }

    static $file = null;
    if ($file === null) {
        $path = getenv('SODAPP_CONFIG') ?: dirname(__DIR__, 2) . '/sodapp-config.php';
        $file = is_file($path) ? require $path : [];
        if (!is_array($file)) {
            $file = [];
        }
    }

    if (!array_key_exists($key, $file)) {
        error_log("Missing config value: $key");
        http_response_code(500);
        die(json_encode(['error' => 'Server configuration error'], JSON_UNESCAPED_UNICODE));
    }
    return $file[$key];
}
