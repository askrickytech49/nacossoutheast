<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$types = ['convention', 'sponsorship', 'pageant'];
$type = (string) ($_GET['type'] ?? '');
if (!in_array($type, $types, true)) $type = '';
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$where = [];
$params = [];
if ($type !== '') {
    $where[] = 'form_type = :type';
    $params[':type'] = $type;
}
if ($search !== '') {
    $where[] = '(applicant_name LIKE :name OR applicant_email LIKE :email OR payment_reference LIKE :reference OR package_name LIKE :package)';
    $needle = '%' . $search . '%';
    $params += [':name' => $needle, ':email' => $needle, ':reference' => $needle, ':package' => $needle];
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$pdo = nacos_db();
$count = $pdo->prepare('SELECT COUNT(*) FROM paid_registrations' . $whereSql);
$count->execute($params);
$total = (int) $count->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$query = $pdo->prepare('SELECT id, form_type, applicant_name, applicant_email, category, package_name, amount_kobo, currency, payment_reference, payment_paid_at, created_at, submission_data FROM paid_registrations' . $whereSql . ' ORDER BY payment_paid_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$query->execute($params);
$payments = $query->fetchAll(PDO::FETCH_ASSOC);
$paidTotal = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM paid_registrations WHERE payment_status = 'paid'")->fetchColumn();

admin_page_start('Paid forms');
echo '<div class="subhead"><div><h1>Paid forms</h1><p class="muted">Convention, sponsorship, and Mr &amp; Miss NACOS entries saved after Paystack payment verification.</p></div><div class="actions"><a class="button button-light" href="paid-export.php' . (($type || $search) ? '?' . admin_h(http_build_query(array_filter(['type' => $type, 'q' => $search]))) : '') . '">Export CSV</a></div></div>';
echo '<section class="cards"><article class="metric"><small>Verified paid entries</small><strong>' . number_format($total) . '</strong></article><article class="metric"><small>Recorded paid value</small><strong>₦' . number_format($paidTotal / 100, 2) . '</strong></article></section>';
echo '<div class="toolbar"><form method="get"><input type="search" name="q" value="' . admin_h($search) . '" placeholder="Search name, email or reference"><select name="type"><option value="">All paid forms</option>';
foreach ($types as $option) echo '<option value="' . admin_h($option) . '"' . ($type === $option ? ' selected' : '') . '>' . admin_h(ucwords(str_replace('_', ' ', $option))) . '</option>';
echo '</select><button type="submit">Filter</button></form><a class="button button-light" href="index.php">Hackathon applications</a></div>';
echo '<section class="panel"><div class="table-scroll"><table><thead><tr><th>Applicant / sponsor</th><th>Form</th><th>Package</th><th>Amount</th><th>Payment</th><th>Paid at</th><th></th></tr></thead><tbody>';
if (!$payments) {
    echo '<tr><td colspan="7" class="empty">No verified paid entries match this search.</td></tr>';
} else {
    foreach ($payments as $row) {
        $submissionData = json_decode((string) $row['submission_data'], true);
        $attendeeCount = is_array($submissionData['attendees'] ?? null) ? count($submissionData['attendees']) : 0;
        $formLabel = ucwords((string) $row['form_type']) . ($attendeeCount > 1 ? ' · ' . $attendeeCount . ' attendees' : '');
        echo '<tr><td><a href="paid-registration.php?id=' . (int) $row['id'] . '">' . admin_h((string) $row['applicant_name']) . '</a><br><span class="muted">' . admin_h((string) $row['applicant_email']) . '</span></td>';
        echo '<td>' . admin_h($formLabel) . ($row['category'] ? '<br><span class="muted">' . admin_h((string) $row['category']) . '</span>' : '') . '</td>';
        echo '<td>' . admin_h((string) $row['package_name']) . '</td><td>₦' . number_format(((int) $row['amount_kobo']) / 100, 2) . '</td><td><span class="badge badge-accepted">Paid</span><br><span class="muted">' . admin_h((string) $row['payment_reference']) . '</span></td>';
        $paidAt = $row['payment_paid_at'] ?: $row['created_at'];
        echo '<td>' . admin_h(date('M j, Y · g:i a', strtotime((string) $paidAt))) . '</td><td><a href="paid-registration.php?id=' . (int) $row['id'] . '">View</a></td></tr>';
    }
}
echo '</tbody></table></div><div class="pagination"><span>' . number_format($total) . ' entries · page ' . $page . ' of ' . $pages . '</span><div class="actions">';
$paramsBase = array_filter(['type' => $type, 'q' => $search], static fn($value) => $value !== '');
if ($page > 1) echo '<a class="button button-light" href="?' . admin_h(http_build_query($paramsBase + ['page' => $page - 1])) . '">Previous</a>';
if ($page < $pages) echo '<a class="button button-light" href="?' . admin_h(http_build_query($paramsBase + ['page' => $page + 1])) . '">Next</a>';
echo '</div></div></section>';
admin_page_end();
