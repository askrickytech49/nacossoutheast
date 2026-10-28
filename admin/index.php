<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$statuses = ['new', 'reviewing', 'shortlisted', 'accepted', 'rejected'];
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, $statuses, true)) {
    $status = '';
}
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;
$pdo = nacos_db();
$counts = $pdo->query("SELECT COUNT(*) AS total,
  SUM(review_status = 'new') AS new_count,
  SUM(review_status = 'reviewing') AS reviewing_count,
  SUM(review_status = 'shortlisted') AS shortlisted_count,
  SUM(review_status = 'accepted') AS accepted_count,
  SUM(review_status = 'rejected') AS rejected_count
  FROM hackathon_registrations")->fetch(PDO::FETCH_ASSOC) ?: [];

$where = [];
$params = [];
if ($status !== '') {
    $where[] = 'review_status = :status';
    $params[':status'] = $status;
}
if ($search !== '') {
    $where[] = '(full_name LIKE :search_name OR email LIKE :search_email OR institution LIKE :search_institution OR team_name LIKE :search_team)';
    $needle = '%' . $search . '%';
    $params += [':search_name' => $needle, ':search_email' => $needle, ':search_institution' => $needle, ':search_team' => $needle];
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countQuery = $pdo->prepare('SELECT COUNT(*) FROM hackathon_registrations' . $whereSql);
$countQuery->execute($params);
$filteredTotal = (int) $countQuery->fetchColumn();
$list = $pdo->prepare('SELECT id, full_name, email, institution, participation, team_name, team_size, review_status, created_at FROM hackathon_registrations' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$list->execute($params);
$registrations = $list->fetchAll(PDO::FETCH_ASSOC);
$pages = max(1, (int) ceil($filteredTotal / $perPage));

admin_page_start('Hackathon registrations');
echo '<div class="subhead"><div><h1>Hackathon registrations</h1><p class="muted">Review applications, update their progress, and export the list.</p></div><div class="actions"><a class="button button-light" href="payments.php">View paid forms</a><a class="button button-light" href="export.php">Export CSV</a></div></div>';
echo '<section class="cards">';
foreach ([
    'All registrations' => $counts['total'] ?? 0,
    'New' => $counts['new_count'] ?? 0,
    'Reviewing' => $counts['reviewing_count'] ?? 0,
    'Shortlisted' => $counts['shortlisted_count'] ?? 0,
    'Accepted' => $counts['accepted_count'] ?? 0,
] as $label => $count) {
    echo '<article class="metric"><small>' . admin_h($label) . '</small><strong>' . (int) $count . '</strong></article>';
}
echo '</section>';
$exportQuery = http_build_query(array_filter(['status' => $status, 'q' => $search], static fn($value) => $value !== ''));
echo '<div class="toolbar"><form method="get"><input type="search" name="q" value="' . admin_h($search) . '" placeholder="Search name, email, institution or team"><select name="status"><option value="">All statuses</option>';
foreach ($statuses as $option) {
    echo '<option value="' . admin_h($option) . '"' . ($status === $option ? ' selected' : '') . '>' . admin_h(ucfirst($option)) . '</option>';
}
echo '</select><button type="submit">Filter</button></form><a class="button button-light" href="export.php' . ($exportQuery ? '?' . admin_h($exportQuery) : '') . '">Export filtered</a></div>';
echo '<section class="panel"><div class="table-scroll"><table><thead><tr><th>Applicant</th><th>Institution</th><th>Format</th><th>Submitted</th><th>Status</th><th></th></tr></thead><tbody>';
if (!$registrations) {
    echo '<tr><td colspan="6" class="empty">No registrations match this search.</td></tr>';
} else {
    foreach ($registrations as $row) {
        $rowStatus = (string) $row['review_status'];
        $format = $row['participation'] === 'Team' ? 'Team' . ($row['team_name'] ? ' · ' . $row['team_name'] : '') . ($row['team_size'] ? ' · ' . (int) $row['team_size'] . ' members' : '') : 'Individual';
        echo '<tr><td><a href="registration.php?id=' . (int) $row['id'] . '">' . admin_h($row['full_name']) . '</a><br><span class="muted">' . admin_h($row['email']) . '</span></td>';
        echo '<td>' . admin_h($row['institution']) . '</td><td>' . admin_h($format) . '</td><td>' . admin_h(date('M j, Y · g:i a', strtotime((string) $row['created_at']))) . '</td>';
        echo '<td><span class="badge badge-' . admin_h($rowStatus) . '">' . admin_h(ucfirst($rowStatus)) . '</span></td><td><a href="registration.php?id=' . (int) $row['id'] . '">Review</a></td></tr>';
    }
}
echo '</tbody></table></div><div class="pagination"><span>' . $filteredTotal . ' result' . ($filteredTotal === 1 ? '' : 's') . ' · page ' . min($page, $pages) . ' of ' . $pages . '</span><div class="actions">';
$baseParams = array_filter(['q' => $search, 'status' => $status], static fn($value) => $value !== '');
if ($page > 1) {
    echo '<a class="button button-light" href="?' . admin_h(http_build_query($baseParams + ['page' => $page - 1])) . '">Previous</a>';
}
if ($page < $pages) {
    echo '<a class="button button-light" href="?' . admin_h(http_build_query($baseParams + ['page' => $page + 1])) . '">Next</a>';
}
echo '</div></div></section>';
admin_page_end();
