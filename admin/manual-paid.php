<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$search = trim((string) ($_GET['q'] ?? ''));
$pdo = nacos_db();
$recordsSql = "(SELECT m.id, m.form_type, m.category, m.applicant_name, m.applicant_email, m.package_name, m.amount_kobo, m.currency, m.reviewed_at AS paid_at, m.payer_email_status AS email_status, p.payment_reference FROM manual_payment_submissions m LEFT JOIN paid_registrations p ON p.id = m.paid_registration_id WHERE m.status = 'approved') AS entries";
$searchSql = $search !== '' ? ' WHERE (applicant_name LIKE :name OR applicant_email LIKE :email OR payment_reference LIKE :reference OR package_name LIKE :package)' : '';
$params = [];
if ($search !== '') {
    $needle = '%' . $search . '%';
    $params = [':name' => $needle, ':email' => $needle, ':reference' => $needle, ':package' => $needle];
}
$count = $pdo->prepare('SELECT COUNT(*) FROM ' . $recordsSql . $searchSql);
$count->execute($params);
$total = (int) $count->fetchColumn();
$query = $pdo->prepare('SELECT * FROM ' . $recordsSql . $searchSql . ' ORDER BY paid_at DESC, id DESC LIMIT 300');
$query->execute($params);
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
$paidTotal = (int) $pdo->query("SELECT COALESCE(SUM(amount_kobo), 0) FROM manual_payment_submissions WHERE status = 'approved'")->fetchColumn();

admin_page_start('Portal bank transfer payments');
echo '<div class="subhead"><div><h1>Portal bank transfer payments</h1><p class="muted">Receipts submitted through the registration portal and approved by an administrator. Directly added offline payers are listed on their own page.</p></div><div class="actions"><a class="button" href="offline-payers.php">Offline payers</a><a class="button button-light" href="manual-payments.php">Review pending submissions</a></div></div>';
echo '<section class="cards" style="grid-template-columns:repeat(2,minmax(0,1fr))"><article class="metric"><small>Approved portal transfers</small><strong>' . number_format($total) . '</strong></article><article class="metric"><small>Approved transfer value</small><strong>&#8358;' . number_format($paidTotal / 100, 2) . '</strong></article></section>';
echo '<div class="toolbar"><form method="get"><input type="search" name="q" value="' . admin_h($search) . '" placeholder="Search name, email or reference"><button type="submit">Search</button></form></div>';
echo '<section class="panel"><div class="table-scroll"><table><thead><tr><th>Registrant</th><th>Form</th><th>Package</th><th>Amount</th><th>Payment reference</th><th>Approved</th><th>Email</th><th></th></tr></thead><tbody>';
if (!$rows) {
    echo '<tr><td class="empty" colspan="8">No approved portal transfers' . ($search !== '' ? ' match this search.' : ' yet.') . '</td></tr>';
} else {
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        echo '<tr><td><a href="manual-payment.php?id=' . $id . '">' . admin_h((string) $row['applicant_name']) . '</a><br><span class="muted">' . admin_h((string) $row['applicant_email']) . '</span></td>';
        echo '<td>' . admin_h(ucwords((string) $row['form_type'])) . ($row['category'] ? '<br><span class="muted">' . admin_h((string) $row['category']) . '</span>' : '') . '</td>';
        echo '<td>' . admin_h((string) $row['package_name']) . '</td><td>&#8358;' . number_format((int) $row['amount_kobo'] / 100, 2) . '</td>';
        echo '<td>' . admin_h((string) ($row['payment_reference'] ?: '-' )) . '</td><td>' . admin_h($row['paid_at'] ? date('M j, Y, g:i a', strtotime((string) $row['paid_at'])) : '-') . '</td>';
        echo '<td><span class="badge ' . ($row['email_status'] === 'sent' ? 'badge-accepted' : 'badge-reviewing') . '">' . admin_h(ucfirst((string) $row['email_status'])) . '</span></td>';
        echo '<td><a href="manual-payment.php?id=' . $id . '">View receipt</a></td></tr>';
    }
}
echo '</tbody></table></div><div class="pagination"><span>' . number_format($total) . ' approved portal transfers</span><a href="payments.php">Back to all paid forms</a></div></section>';
admin_page_end();
