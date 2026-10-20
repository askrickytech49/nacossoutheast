<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/manual-payment-mail.php';
admin_require_login();

$id = max(0, (int) ($_GET['id'] ?? $_POST['id'] ?? 0));
if ($id < 1) { http_response_code(404); exit('Manual payment not found.'); }
$pdo = nacos_db();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf()) {
        $error = 'This review session expired. Refresh and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $notes = trim((string) ($_POST['admin_notes'] ?? ''));
        try {
            if ($action === 'approve') {
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT * FROM manual_payment_submissions WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $id]);
                $submission = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$submission || $submission['status'] !== 'pending') {
                    throw new RuntimeException('This payment is no longer pending review.');
                }
                $paymentReference = 'NACOS-MANUAL-' . $id . '-' . strtoupper(bin2hex(random_bytes(3)));
                $insert = $pdo->prepare(
                    'INSERT INTO paid_registrations
                     (form_type, applicant_name, applicant_email, phone, category, package_name, amount_kobo, currency, payment_status, payment_reference, payment_channel, payment_paid_at, submission_data, photo_path)
                     VALUES (:form_type, :name, :email, :phone, :category, :package, :amount, :currency, \'paid\', :reference, \'bank_transfer\', NOW(), :data, :photo)'
                );
                $insert->execute([
                    'form_type' => $submission['form_type'],
                    'name' => $submission['applicant_name'],
                    'email' => $submission['applicant_email'],
                    'phone' => $submission['phone'],
                    'category' => $submission['category'],
                    'package' => $submission['package_name'],
                    'amount' => $submission['amount_kobo'],
                    'currency' => $submission['currency'],
                    'reference' => $paymentReference,
                    'data' => $submission['submission_data'],
                    'photo' => $submission['application_photo_path'],
                ]);
                $paidId = (int) $pdo->lastInsertId();
                $update = $pdo->prepare('UPDATE manual_payment_submissions SET status = \'approved\', paid_registration_id = :paid_id, admin_notes = :notes, reviewed_at = NOW(), reviewed_by = :admin_id WHERE id = :id AND status = \'pending\'');
                $update->execute([
                    'paid_id' => $paidId,
                    'notes' => $notes !== '' ? $notes : null,
                    'admin_id' => (int) $_SESSION['admin_id'],
                    'id' => $id,
                ]);
                $pdo->commit();
                $submission['payment_reference'] = $paymentReference;
                $mailSent = nacos_manual_send_payer_confirmations($submission);
                $mailStatus = $pdo->prepare('UPDATE manual_payment_submissions SET payer_email_status = :status WHERE id = :id');
                $mailStatus->execute(['status' => $mailSent ? 'sent' : 'failed', 'id' => $id]);
                header('Location: manual-payment.php?id=' . $id . '&approved=1&email=' . ($mailSent ? 'sent' : 'failed'));
                exit;
            }
            if ($action === 'reject') {
                $reject = $pdo->prepare('UPDATE manual_payment_submissions SET status = \'rejected\', admin_notes = :notes, reviewed_at = NOW(), reviewed_by = :admin_id WHERE id = :id AND status = \'pending\'');
                $reject->execute(['notes' => $notes !== '' ? $notes : null, 'admin_id' => (int) $_SESSION['admin_id'], 'id' => $id]);
                if ($reject->rowCount() !== 1) throw new RuntimeException('This payment is no longer pending review.');
                header('Location: manual-payment.php?id=' . $id . '&rejected=1');
                exit;
            }
            if ($action === 'resend') {
                $queryForMail = $pdo->prepare('SELECT m.*, p.payment_reference FROM manual_payment_submissions m LEFT JOIN paid_registrations p ON p.id = m.paid_registration_id WHERE m.id = :id LIMIT 1');
                $queryForMail->execute(['id' => $id]);
                $submission = $queryForMail->fetch(PDO::FETCH_ASSOC);
                if (!$submission || $submission['status'] !== 'approved' || $submission['payer_email_status'] === 'sent') {
                    throw new RuntimeException('A confirmation can only be retried for an approved payment that has not already been emailed.');
                }
                $mailSent = nacos_manual_send_payer_confirmations($submission);
                $mailStatus = $pdo->prepare('UPDATE manual_payment_submissions SET payer_email_status = :status WHERE id = :id');
                $mailStatus->execute(['status' => $mailSent ? 'sent' : 'failed', 'id' => $id]);
                header('Location: manual-payment.php?id=' . $id . '&email=' . ($mailSent ? 'sent' : 'failed'));
                exit;
            }
            $error = 'Choose a valid review action.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Manual payment review failed: ' . $exception->getMessage());
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'The review action failed. Please try again.';
        }
    }
}

$query = $pdo->prepare('SELECT m.*, p.payment_reference FROM manual_payment_submissions m LEFT JOIN paid_registrations p ON p.id = m.paid_registration_id WHERE m.id = :id LIMIT 1');
$query->execute(['id' => $id]);
$entry = $query->fetch(PDO::FETCH_ASSOC);
if (!$entry) { http_response_code(404); exit('Manual payment not found.'); }
$data = json_decode((string) $entry['submission_data'], true);
$attendees = is_array($data) && isset($data['attendees']) && is_array($data['attendees']) ? $data['attendees'] : [];
$csrf = admin_h(admin_csrf_token());
if (isset($_GET['approved'])) $notice = ($_GET['email'] ?? '') === 'sent' ? 'Payment approved and confirmation sent to registrants.' : 'Payment approved, but the confirmation email could not be sent. Retry it after checking email configuration.';
elseif (isset($_GET['rejected'])) $notice = 'Manual payment submission rejected. It was not added to paid forms.';
elseif (($_GET['email'] ?? '') === 'sent') $notice = 'Confirmation email sent to registrants.';
elseif (($_GET['email'] ?? '') === 'failed') $error = 'Email could not be sent. Check Brevo configuration and retry.';

admin_page_start('Review manual payment');
echo '<div class="subhead"><div><a class="muted" href="manual-payments.php">← Manual payment queue</a><h1 style="margin-top:10px">' . admin_h((string) $entry['applicant_name']) . '</h1><p class="muted">' . admin_h(ucwords((string) $entry['form_type'])) . ' · ' . admin_h((string) $entry['applicant_email']) . ' · NACOS-MP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) . '</p></div><span class="badge badge-' . ($entry['status'] === 'pending' ? 'reviewing' : ($entry['status'] === 'approved' ? 'accepted' : 'rejected')) . '">' . admin_h(ucfirst((string) $entry['status'])) . '</span></div>';
if ($notice !== '') echo '<div class="notice">' . admin_h($notice) . '</div>';
if ($error !== '') echo '<div class="notice error">' . admin_h($error) . '</div>';
echo '<div class="detail-grid"><section class="panel panel-pad"><h2>Transfer submission</h2><dl class="detail-list">';
$rows = [
    'Applicant' => $entry['applicant_name'], 'Email' => $entry['applicant_email'], 'Phone' => $entry['phone'],
    'Category' => $entry['category'] ?? '—', 'Package' => $entry['package_name'], 'Amount claimed' => '₦' . number_format((int) $entry['amount_kobo'] / 100, 2),
    'Currency' => $entry['currency'], 'Submitted' => date('M j, Y · g:i a', strtotime((string) $entry['submitted_at'])),
    'Admin notice email' => ucfirst((string) $entry['admin_email_status']),
];
if (!empty($entry['payment_reference'])) $rows['Recorded payment reference'] = $entry['payment_reference'];
if ($entry['status'] === 'approved') $rows['Registrant email status'] = ucfirst((string) $entry['payer_email_status']);
foreach ($rows as $label => $value) echo '<dt>' . admin_h($label) . '</dt><dd>' . admin_h((string) $value) . '</dd>';
echo '</dl>';
if ($attendees) {
    echo '<h3>Attendees</h3><div class="table-scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Phone</th></tr></thead><tbody>';
    foreach ($attendees as $person) {
        if (!is_array($person)) continue;
        echo '<tr><td>' . admin_h((string) ($person['name'] ?? '')) . '</td><td>' . admin_h((string) ($person['email'] ?? '')) . '</td><td>' . admin_h((string) ($person['phone'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
if (is_array($data)) {
    echo '<h3>Application details</h3><dl class="detail-list">';
    foreach ($data as $key => $value) {
        if (in_array((string) $key, ['attendees', 'manual_payment', 'proof_mime', 'photo_mime', 'category'], true) || is_array($value)) continue;
        $label = ucwords(str_replace('_', ' ', (string) $key));
        echo '<dt>' . admin_h($label) . '</dt><dd>' . nl2br(admin_h((string) $value)) . '</dd>';
    }
    echo '</dl>';
}
echo '<h3>Transfer receipt</h3><p><a class="button button-light" href="manual-payment-proof.php?id=' . $id . '" target="_blank" rel="noopener">Open receipt</a></p>';
if ($entry['form_type'] === 'pageant' && !empty($entry['application_photo_path'])) echo '<h3>Applicant photo</h3><p><a class="button button-light" href="manual-payment-photo.php?id=' . $id . '" target="_blank" rel="noopener">View applicant photo</a></p>';
echo '</section><section class="panel panel-pad"><h2>Review decision</h2><p class="muted">Verify the transfer in the NACOS bank account before approving. Approval adds the submission to Paid forms and sends confirmation to the payer.</p>';
if ($entry['status'] === 'pending') {
    echo '<form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="id" value="' . $id . '"><label>Admin notes<textarea name="admin_notes" placeholder="Optional review notes"></textarea></label><button type="submit" name="action" value="approve" onclick="return confirm(\'Confirm the transfer in the bank account before approving. Continue?\')">Approve payment</button><button type="submit" class="button-light" name="action" value="reject" onclick="return confirm(\'Reject this manual payment submission?\')">Reject submission</button></form>';
} elseif ($entry['status'] === 'approved' && $entry['payer_email_status'] !== 'sent') {
    echo '<form method="post"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="id" value="' . $id . '"><button type="submit" name="action" value="resend">Retry confirmation email</button></form>';
} elseif ($entry['status'] === 'rejected') {
    echo '<p class="muted">This submission was rejected and was not entered as paid.</p>';
} else {
    echo '<p class="notice">Payment is approved and confirmation email was accepted by Brevo.</p>';
}
if (!empty($entry['admin_notes'])) echo '<h3>Saved notes</h3><p>' . nl2br(admin_h((string) $entry['admin_notes'])) . '</p>';
echo '</section></div>';
admin_page_end();
