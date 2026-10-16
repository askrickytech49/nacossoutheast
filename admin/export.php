<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$statuses = ['new', 'reviewing', 'shortlisted', 'accepted', 'rejected'];
$status = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if (in_array($status, $statuses, true)) {
    $where[] = 'review_status = :status';
    $params[':status'] = $status;
}
if ($search !== '') {
    $where[] = '(full_name LIKE :name OR email LIKE :email OR institution LIKE :institution OR team_name LIKE :team)';
    $needle = '%' . $search . '%';
    $params += [':name' => $needle, ':email' => $needle, ':institution' => $needle, ':team' => $needle];
}
$sql = 'SELECT id, full_name, email, phone, institution, department, study_level, state, participation, team_name, team_size, project_idea, portfolio_url, other_information, review_status, admin_notes, created_at FROM hackathon_registrations';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC, id DESC LIMIT 10000';
$query = nacos_db()->prepare($sql);
$query->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="nacos-hackathon-registrations-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Registration ID', 'Full Name', 'Email', 'Phone', 'Institution', 'Department', 'Level', 'State', 'Participation', 'Team Name', 'Team Size', 'Project Idea', 'GitHub / Portfolio', 'Other Information', 'Review Status', 'Admin Notes', 'Submitted At']);
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    foreach ($row as &$value) {
        $value = (string) ($value ?? '');
        if (preg_match('/^[\s]*[=+@-]/', $value)) $value = "'" . $value;
    }
    unset($value);
    fputcsv($out, $row);
}
fclose($out);
exit;
