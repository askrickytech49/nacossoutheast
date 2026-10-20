<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment-method-settings.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['enabled' => false]);
    exit;
}
$paystack = nacos_config()['paystack'] ?? [];
$secret = trim((string) ($paystack['secret_key'] ?? ''));
$publicKey = trim((string) ($paystack['public_key'] ?? ''));
$testModePair = str_starts_with($secret, 'sk_test_') && str_starts_with($publicKey, 'pk_test_');
$liveModePair = str_starts_with($secret, 'sk_live_') && str_starts_with($publicKey, 'pk_live_');
$paystackConfigured = $secret !== ''
    && !str_contains($secret, 'ADD_YOUR_')
    && ($testModePair || $liveModePair)
    && function_exists('curl_init');
$settings = nacos_payment_method_settings();
$automaticEnabled = $settings['automatic_enabled'] && $paystackConfigured;
$manualEnabled = $settings['manual_enabled'];
$defaultMethod = $settings['default_method'];
if ($defaultMethod === 'automatic' && !$automaticEnabled) $defaultMethod = $manualEnabled ? 'manual' : 'automatic';
if ($defaultMethod === 'manual' && !$manualEnabled) $defaultMethod = $automaticEnabled ? 'automatic' : 'manual';
echo json_encode([
    'enabled' => $automaticEnabled,
    'automatic_enabled' => $automaticEnabled,
    'manual_enabled' => $manualEnabled,
    'default_method' => $defaultMethod,
    'public_key' => $automaticEnabled ? $publicKey : '',
]);
