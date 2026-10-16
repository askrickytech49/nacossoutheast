<?php
declare(strict_types=1);

// CLI only. Set NACOS_ADMIN_NEW_PASSWORD in the environment and run this script.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/db.php';
$email = strtolower($argv[1] ?? nacos_config()['admin_email']);
$password = getenv('NACOS_ADMIN_NEW_PASSWORD') ?: '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    fwrite(STDERR, "Set NACOS_ADMIN_NEW_PASSWORD (at least 8 characters) and run: php admin/set-password.php [email]\n");
    exit(1);
}

$statement = nacos_db()->prepare('UPDATE admin_users SET password_hash = :hash WHERE email = :email');
$statement->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'email' => $email]);
if ($statement->rowCount() !== 1) {
    fwrite(STDERR, "No admin account was updated for {$email}.\n");
    exit(1);
}
fwrite(STDOUT, "Password updated for {$email}.\n");
