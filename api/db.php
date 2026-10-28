<?php
declare(strict_types=1);

function nacos_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $localFile = __DIR__ . '/config.local.php';
    $local = is_file($localFile) ? require $localFile : [];
    $local = is_array($local) ? $local : [];
    $config = [
        'database' => array_merge([
            'host' => getenv('NACOS_DB_HOST') ?: '127.0.0.1',
            'name' => getenv('NACOS_DB_NAME') ?: 'nacos_portal',
            'user' => getenv('NACOS_DB_USER') ?: '',
            'password' => getenv('NACOS_DB_PASSWORD') ?: '',
        ], $local['database'] ?? []),
        'brevo' => array_merge([
            'api_key' => getenv('BREVO_API_KEY') ?: '',
            'sender_email' => getenv('BREVO_SENDER_EMAIL') ?: '',
            'sender_name' => getenv('BREVO_SENDER_NAME') ?: 'NACOS South East',
        ], $local['brevo'] ?? []),
        'paystack' => array_merge([
            'secret_key' => getenv('PAYSTACK_SECRET_KEY') ?: '',
            'public_key' => getenv('PAYSTACK_PUBLIC_KEY') ?: '',
        ], $local['paystack'] ?? []),
        'manual_payment' => array_merge([
            'bank_name' => 'Signature Bank',
            'account_name' => 'NACOS SOUTH EAST',
            'account_number' => '2000557961',
            'instructions' => '',
        ], $local['manual_payment'] ?? []),
        'admin_email' => $local['admin_email'] ?? (getenv('HACKATHON_ADMIN_EMAIL') ?: 'nacosoutheast@gmail.com'),
        'session_secure' => $local['session_secure'] ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ];
    return $config;
}

function nacos_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = nacos_config()['database'];
    foreach (['host', 'name', 'user'] as $required) {
        if (empty($database[$required])) {
            throw new RuntimeException('Database configuration is incomplete.');
        }
    }
    $pdo = new PDO(
        'mysql:host=' . $database['host'] . ';dbname=' . $database['name'] . ';charset=utf8mb4',
        $database['user'],
        $database['password'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    return $pdo;
}
