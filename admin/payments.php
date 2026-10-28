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
echo '<style>@media(max-width:620px){.table-scroll{overflow:visible}.paid-forms-table{display:block;min-width:0;width:100%}.paid-forms-table thead{display:none}.paid-forms-table tbody{display:grid;gap:10px;padding:10px}.paid-forms-table tbody tr{display:grid;grid-template-columns:minmax(0,1fr);gap:9px;padding:12px;border:1px solid #e0e7df;border-radius:10px;background:#fff}.paid-forms-table tbody td{display:block;min-width:0;padding:0;border:0;font-size:13px;overflow-wrap:anywhere}.paid-forms-table tbody td:nth-child(1){grid-column:1/-1}.paid-forms-table tbody td:nth-child(2){grid-column:1/-1;color:#465448}.paid-forms-table tbody td:nth-child(2)::before{content:"FORM";display:block;margin-bottom:3px;color:#68766a;font-size:9px;font-weight:750;letter-spacing:.06em}.paid-forms-table tbody td:nth-child(3),.paid-forms-table tbody td:nth-child(4),.paid-forms-table tbody td:nth-child(5),.paid-forms-table tbody td:nth-child(6){display:none}.paid-forms-table tbody td.mobile-row-actions{grid-column:1/-1;display:flex;align-items:center;gap:14px;padding-top:8px;border-top:1px solid #edf0ec}.mobile-payment-details{flex:1;min-width:0}.mobile-payment-details summary{color:#0b6623;font-size:12px;font-weight:700;cursor:pointer;list-style:none}.mobile-payment-details summary::-webkit-details-marker{display:none}.mobile-payment-details summary:after{content:"⌄";float:right;font-size:16px}.mobile-payment-details[open] summary:after{content:"⌃"}.mobile-payment-details dl{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:12px 0 2px}.mobile-payment-details dl div{min-width:0}.mobile-payment-details dt{margin-bottom:3px;color:#68766a;font-size:10px}.mobile-payment-details dd{margin:0;font-size:12px;font-weight:650;overflow-wrap:anywhere}.paid-forms-table td.empty{display:block!important;grid-column:1/-1;text-align:center}}.mobile-payment-details{display:none}@media(max-width:620px){.mobile-payment-details{display:block}}</style>';
echo '<div class="subhead"><div><h1>Paid forms</h1><p class="muted">Convention, sponsorship, and Mr &amp; Miss NACOS registrations with verified online or admin-approved bank payments.</p></div><div class="actions"><a class="button" href="add-offline-payment.php">Add offline payer</a><a class="button button-light" href="offline-payers.php">Offline payers</a><a class="button button-light" href="paid-export.php' . (($type || $search) ? '?' . admin_h(http_build_query(array_filter(['type' => $type, 'q' => $search]))) : '') . '">Export CSV</a></div></div>';
echo '<section class="cards"><article class="metric"><small>Verified paid entries</small><strong>' . number_format($total) . '</strong></article><article class="metric"><small>Recorded paid value</small><strong>₦' . number_format($paidTotal / 100, 2) . '</strong></article></section>';
echo '<div class="toolbar"><form method="get"><input type="search" name="q" value="' . admin_h($search) . '" placeholder="Search name, email or reference"><select name="type"><option value="">All paid forms</option>';
foreach ($types as $option) echo '<option value="' . admin_h($option) . '"' . ($type === $option ? ' selected' : '') . '>' . admin_h(ucwords(str_replace('_', ' ', $option))) . '</option>';
echo '</select><button type="submit">Filter</button></form><a class="button button-light" href="index.php">Hackathon applications</a></div>';
echo '<section class="panel"><div class="table-scroll"><table class="paid-forms-table"><thead><tr><th>Applicant / sponsor</th><th>Form</th><th>Package</th><th>Amount</th><th>Payment</th><th>Paid at</th><th></th></tr></thead><tbody>';
if (!$payments) {
    echo '<tr><td colspan="7" class="empty">No verified paid entries match this search.</td></tr>';
} else {
    foreach ($payments as $row) {
        $submissionData = json_decode((string) $row['submission_data'], true);
        $attendeeCount = is_array($submissionData['attendees'] ?? null) ? count($submissionData['attendees']) : 0;
        $formLabel = ucwords((string) $row['form_type']) . ($attendeeCount > 1 ? ' · ' . $attendeeCount . ' attendees' : '');
        $cleanEmail = trim((string) ($row['applicant_email'] ?? ''));
        $emailHtml = ($cleanEmail !== '' && !str_starts_with(strtolower($cleanEmail), 'bulk-'))
            ? '<span class="muted">' . admin_h($cleanEmail) . '</span>'
            : '<span class="badge" style="background:#fef3c7;color:#92400e;font-size:10.5px;padding:1px 6px;border-radius:4px">No email registered</span>';
        echo '<tr><td><a href="paid-registration.php?id=' . (int) $row['id'] . '">' . admin_h((string) $row['applicant_name']) . '</a><br>' . $emailHtml . '</td>';
        echo '<td>' . admin_h($formLabel) . ($row['category'] ? '<br><span class="muted">' . admin_h((string) $row['category']) . '</span>' : '') . '</td>';
        echo '<td>' . admin_h((string) $row['package_name']) . '</td><td>₦' . number_format(((int) $row['amount_kobo']) / 100, 2) . '</td><td><span class="badge badge-accepted">Paid</span><br><span class="muted">' . admin_h((string) $row['payment_reference']) . '</span></td>';
        $paidAt = $row['payment_paid_at'] ?: $row['created_at'];
        $paidAtLabel = date('M j, Y · g:i a', strtotime((string) $paidAt));
        $amountLabel = '₦' . number_format(((int) $row['amount_kobo']) / 100, 2);
        echo '<td>' . admin_h($paidAtLabel) . '</td><td class="mobile-row-actions"><a href="paid-registration.php?id=' . (int) $row['id'] . '">View</a><details class="mobile-payment-details"><summary aria-label="Show or hide payment details"></summary><dl><div><dt>Package</dt><dd>' . admin_h((string) $row['package_name']) . '</dd></div><div><dt>Amount</dt><dd>' . admin_h($amountLabel) . '</dd></div><div><dt>Status</dt><dd>Paid</dd></div><div><dt>Reference</dt><dd>' . admin_h((string) $row['payment_reference']) . '</dd></div><div><dt>Paid at</dt><dd>' . admin_h($paidAtLabel) . '</dd></div></dl></details></td></tr>';
    }
}
echo '</tbody></table></div><div class="pagination"><span>' . number_format($total) . ' entries · page ' . $page . ' of ' . $pages . '</span><div class="actions">';
$paramsBase = array_filter(['type' => $type, 'q' => $search], static fn($value) => $value !== '');
if ($page > 1) echo '<a class="button button-light" href="?' . admin_h(http_build_query($paramsBase + ['page' => $page - 1])) . '">Previous</a>';
if ($page < $pages) echo '<a class="button button-light" href="?' . admin_h(http_build_query($paramsBase + ['page' => $page + 1])) . '">Next</a>';
echo '</div></div></section>';
admin_page_end();
