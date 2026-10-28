<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(404); exit; }
$query = nacos_db()->prepare('SELECT photo_path FROM paid_registrations WHERE id = :id AND form_type = \'pageant\' LIMIT 1');
$query->execute(['id' => $id]);
$photo = $query->fetchColumn();
if (!is_string($photo) || !preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/', $photo)) { http_response_code(404); exit; }
$path = dirname(__DIR__) . '/uploads/paid/' . $photo;
if (!is_file($path)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
