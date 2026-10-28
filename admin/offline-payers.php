<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$search = trim((string) ($_GET['q'] ?? ''));
$pdo = nacos_db();
$where = ["payment_channel = 'offline'"];
$params = [];
if ($search !== '') {
    $where[] = '(applicant_name LIKE :name OR applicant_email LIKE :email OR payment_reference LIKE :reference OR package_name LIKE :package)';
    $needle = '%' . $search . '%';
    $params = [':name' => $needle, ':email' => $needle, ':reference' => $needle, ':package' => $needle];
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$count = $pdo->prepare('SELECT COUNT(*) FROM paid_registrations' . $whereSql);
$count->execute($params);
$total = (int) $count->fetchColumn();
$query = $pdo->prepare('SELECT id, form_type, category, applicant_name, applicant_email, package_name, amount_kobo, payment_reference, payment_paid_at, created_at FROM paid_registrations' . $whereSql . ' ORDER BY payment_paid_at DESC, id DESC LIMIT 300');
$query->execute($params);
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
$paidTotal = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM paid_registrations WHERE payment_channel = 'offline'")->fetchColumn();

admin_page_start('Offline payers');
echo '<div class="subhead"><div><h1>Offline payers</h1><p class="muted">Payers entered directly by an administrator. These are kept separate from bank-transfer receipts submitted through the portal.</p></div><div class="actions"><a class="button" href="choose-offline-payment.php">Add offline payer</a><a class="button button-light" href="manual-paid.php">Portal bank transfers</a></div></div>';
echo '<section class="cards" style="grid-template-columns:repeat(2,minmax(0,1fr))"><article class="metric"><small>Offline payers added by admin</small><strong>' . number_format($total) . '</strong></article><article class="metric"><small>Recorded offline payment value</small><strong>&#8358;' . number_format($paidTotal / 100, 2) . '</strong></article></section>';
echo '<div class="toolbar"><form method="get"><input type="search" name="q" value="' . admin_h($search) . '" placeholder="Search name, email or reference"><button type="submit">Search</button></form></div>';
echo '<section class="panel"><div class="table-scroll"><table><thead><tr><th>Payer</th><th>Form</th><th>Package</th><th>Amount</th><th>Payment reference</th><th>Date paid</th><th></th></tr></thead><tbody>';
if (!$rows) {
    echo '<tr><td class="empty" colspan="7">No admin-entered offline payers' . ($search !== '' ? ' match this search.' : ' yet.') . '</td></tr>';
} else {
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $paidAt = $row['payment_paid_at'] ?: $row['created_at'];
        $cleanEmail = trim((string) ($row['applicant_email'] ?? ''));
        $emailHtml = ($cleanEmail !== '' && !str_starts_with(strtolower($cleanEmail), 'bulk-'))
            ? '<span class="muted">' . admin_h($cleanEmail) . '</span>'
            : '<span class="badge" style="background:#fef3c7;color:#92400e;font-size:10.5px;padding:1px 6px;border-radius:4px">No email registered</span>';
        echo '<tr><td><a href="paid-registration.php?id=' . $id . '">' . admin_h((string) $row['applicant_name']) . '</a><br>' . $emailHtml . '<br><span class="badge badge-reviewing">Admin entry</span></td>';
        echo '<td>' . admin_h(ucwords((string) $row['form_type'])) . ($row['category'] ? '<br><span class="muted">' . admin_h((string) $row['category']) . '</span>' : '') . '</td>';
        echo '<td>' . admin_h((string) $row['package_name']) . '</td><td>&#8358;' . number_format((int) $row['amount_kobo'] / 100, 2) . '</td>';
        echo '<td>' . admin_h((string) $row['payment_reference']) . '</td><td>' . admin_h(date('M j, Y, g:i a', strtotime((string) $paidAt))) . '</td><td><a href="paid-registration.php?id=' . $id . '">View record</a></td></tr>';
    }
}
echo '</tbody></table></div><div class="pagination"><span>' . number_format($total) . ' offline payers</span><a href="payments.php">Back to all paid forms</a></div></section>';
admin_page_end();
