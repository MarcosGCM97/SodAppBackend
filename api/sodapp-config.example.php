<?php
// Template. Copy to the folder that contains public_html (one level above it),
// name it sodapp-config.php and fill in the real values. Never commit the real file.
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASS' => '',
    // Generate with: php -r "echo bin2hex(random_bytes(32));"
    'JWT_SECRET' => '',
];
