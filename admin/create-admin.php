<?php
declare(strict_types=1);

// Run from a terminal: php admin/create-admin.php [email]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/db.php';

$email = $argv[1] ?? nacos_config()['admin_email'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php admin/create-admin.php [valid-email]\n");
    exit(1);
}

$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$db = nacos_db();
$statement = $db->prepare(
    'INSERT INTO admin_users (email, password_hash) VALUES (:email, :password_hash) '
    . 'ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
);
$statement->execute([
    'email' => strtolower($email),
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

fwrite(STDOUT, "Admin account created. Save this password now; it will not be shown again.\n");
fwrite(STDOUT, "Email: " . strtolower($email) . "\nPassword: {$password}\n");
