<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$id = max(0, (int) ($_GET['id'] ?? 0));
if ($id < 1) { http_response_code(404); exit; }
$query = nacos_db()->prepare('SELECT proof_path FROM manual_payment_submissions WHERE id = :id LIMIT 1');
$query->execute(['id' => $id]);
$storedName = $query->fetchColumn();
if (!is_string($storedName) || !preg_match('/^[a-f0-9]{48}\.(jpg|png|webp|pdf)$/', $storedName)) { http_response_code(404); exit; }
$path = dirname(__DIR__) . '/uploads/manual-payments/' . $storedName;
if (!is_file($path)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="payment-receipt-' . $id . '.' . pathinfo($storedName, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
