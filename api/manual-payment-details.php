<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$details = nacos_config()['manual_payment'] ?? [];
$bankName = trim((string) ($details['bank_name'] ?? ''));
$accountName = trim((string) ($details['account_name'] ?? ''));
$accountNumber = trim((string) ($details['account_number'] ?? ''));

echo json_encode([
    'enabled' => $bankName !== '' && $accountName !== '' && preg_match('/^[0-9]{10}$/', $accountNumber) === 1,
    'bank_name' => $bankName,
    'account_name' => $accountName,
    'account_number' => $accountNumber,
    'instructions' => trim((string) ($details['instructions'] ?? '')),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
