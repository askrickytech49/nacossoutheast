<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();
$types = ['convention', 'sponsorship', 'pageant'];
$type = (string) ($_GET['type'] ?? '');
if (!in_array($type, $types, true)) $type = '';
$search = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($type !== '') { $where[] = 'form_type = :type'; $params[':type'] = $type; }
if ($search !== '') {
    $where[] = '(applicant_name LIKE :name OR applicant_email LIKE :email OR payment_reference LIKE :reference OR package_name LIKE :package)';
    $needle = '%' . $search . '%';
    $params += [':name' => $needle, ':email' => $needle, ':reference' => $needle, ':package' => $needle];
}
$query = nacos_db()->prepare('SELECT id, form_type, applicant_name, applicant_email, phone, category, package_name, amount_kobo, currency, payment_status, payment_reference, payment_channel, payment_paid_at, submission_data, created_at FROM paid_registrations' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC, id DESC');
$query->execute($params);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="nacos-paid-forms-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'wb');
fputcsv($out, ['ID', 'Form', 'Name', 'Email', 'Phone', 'Category', 'Package', 'Amount NGN', 'Currency', 'Payment Status', 'Payment Reference', 'Channel', 'Paid At', 'Submission Details', 'Saved At']);
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $row['amount_kobo'] = number_format(((int) $row['amount_kobo']) / 100, 2, '.', '');
    $row['submission_data'] = str_replace(["\r", "\n"], [' ', ' '], (string) $row['submission_data']);
    $safe = array_map(static function ($value) {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }, array_values($row));
    fputcsv($out, $safe);
}
fclose($out);
