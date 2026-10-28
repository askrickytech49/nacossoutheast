<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) {
    fwrite(STDERR, "Could not read api/schema.sql.\n");
    exit(1);
}
try {
    $pdo = nacos_db();
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
    fwrite(STDOUT, "Database schema is up to date.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Database migration failed: " . $error->getMessage() . "\n");
    exit(1);
}
