<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$statuses = ['pending', 'approved', 'rejected'];
$status = (string) ($_GET['status'] ?? 'pending');
if (!in_array($status, $statuses, true)) $status = 'pending';
$pdo = nacos_db();
$count = $pdo->prepare('SELECT COUNT(*) FROM manual_payment_submissions WHERE status = :status');
$count->execute(['status' => $status]);
$total = (int) $count->fetchColumn();
$query = $pdo->prepare('SELECT id, form_type, category, applicant_name, applicant_email, package_name, amount_kobo, currency, status, admin_email_status, submitted_at FROM manual_payment_submissions WHERE status = :status ORDER BY submitted_at DESC, id DESC LIMIT 200');
$query->execute(['status' => $status]);
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM manual_payment_submissions WHERE status = 'pending'")->fetchColumn();

admin_page_start('Manual payment review');
echo '<div class="subhead"><div><h1>Manual payment review</h1><p class="muted">Review bank-transfer receipts. Approve only after confirming the payment in the NACOS bank account.</p></div><div class="actions"><a class="button" href="add-offline-payment.php">Add offline payer</a><span class="badge badge-reviewing">' . $pendingCount . ' pending</span></div></div>';
echo '<div class="toolbar"><div class="actions">';
foreach ($statuses as $filter) {
    echo '<a class="button ' . ($status === $filter ? '' : 'button-light') . '" href="manual-payments.php?status=' . rawurlencode($filter) . '">' . ucfirst($filter) . '</a>';
}
echo '</div></div><section class="panel"><div class="table-scroll"><table><thead><tr><th>Registrant</th><th>Form</th><th>Package</th><th>Amount</th><th>Submitted</th><th>Email notice</th><th>Status</th><th></th></tr></thead><tbody>';
if (!$rows) {
    echo '<tr><td class="empty" colspan="8">No ' . admin_h($status) . ' manual payment submissions.</td></tr>';
} else {
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $reference = 'NACOS-MP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        echo '<tr><td><a href="manual-payment.php?id=' . $id . '">' . admin_h((string) $row['applicant_name']) . '</a><br><span class="muted">' . admin_h((string) $row['applicant_email']) . '</span><br><small class="muted">' . admin_h($reference) . '</small></td>';
        echo '<td>' . admin_h(ucwords((string) $row['form_type'])) . ($row['category'] ? '<br><span class="muted">' . admin_h((string) $row['category']) . '</span>' : '') . '</td>';
        echo '<td>' . admin_h((string) $row['package_name']) . '</td><td>₦' . number_format((int) $row['amount_kobo'] / 100, 2) . '</td>';
        echo '<td>' . admin_h(date('M j, Y · g:i a', strtotime((string) $row['submitted_at']))) . '</td>';
        echo '<td>' . admin_h(ucfirst((string) $row['admin_email_status'])) . '</td><td><span class="badge badge-' . ($row['status'] === 'pending' ? 'reviewing' : ($row['status'] === 'approved' ? 'accepted' : 'rejected')) . '">' . admin_h(ucfirst((string) $row['status'])) . '</span></td>';
        echo '<td><a href="manual-payment.php?id=' . $id . '">Review</a></td></tr>';
    }
}
echo '</tbody></table></div><div class="pagination"><span>' . $total . ' submissions</span><a href="payments.php">Back to paid forms</a></div></section>';
admin_page_end();
