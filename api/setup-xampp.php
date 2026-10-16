<?php
declare(strict_types=1);

// Run once from a terminal inside the project: php api/setup-xampp.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = __DIR__ . '/config.local.php';
if (is_file($configPath)) {
    fwrite(STDERR, "api/config.local.php already exists; refusing to overwrite it.\n");
    exit(1);
}

$rootPassword = getenv('XAMPP_MYSQL_ROOT_PASSWORD') ?: '';
try {
    $root = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $root->exec('CREATE DATABASE IF NOT EXISTS nacos_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $dbPassword = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
    $user = 'nacos_app';
    $root->exec("CREATE USER IF NOT EXISTS '{$user}'@'127.0.0.1' IDENTIFIED BY " . $root->quote($dbPassword));
    $root->exec("ALTER USER '{$user}'@'127.0.0.1' IDENTIFIED BY " . $root->quote($dbPassword));
    $root->exec("GRANT ALL PRIVILEGES ON nacos_portal.* TO '{$user}'@'127.0.0.1'");
    $root->exec('FLUSH PRIVILEGES');
    $app = new PDO('mysql:host=127.0.0.1;dbname=nacos_portal;charset=utf8mb4', $user, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
        $app->exec($statement);
    }

    $localConfig = [
        'database' => ['host' => '127.0.0.1', 'name' => 'nacos_portal', 'user' => $user, 'password' => $dbPassword],
        'brevo' => ['api_key' => '', 'sender_email' => 'hello@diamondnestbk.com', 'sender_name' => 'NACOS South East'],
        'paystack' => ['public_key' => '', 'secret_key' => ''],
        'admin_email' => 'nacosoutheast@gmail.com',
        'session_secure' => false,
    ];
    $written = file_put_contents($configPath, "<?php\nreturn " . var_export($localConfig, true) . ";\n", LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Could not write api/config.local.php.');
    }

    fwrite(STDOUT, "XAMPP database and tables are ready. Local configuration saved to api/config.local.php.\n");
    fwrite(STDOUT, "Add the Brevo API key and verified sender to that file to enable emails.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Setup failed: " . $error->getMessage() . "\n");
    exit(1);
}
