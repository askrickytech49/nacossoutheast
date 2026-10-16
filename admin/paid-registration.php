<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(400); exit('Invalid paid form ID.'); }
$pdo = nacos_db();
$query = $pdo->prepare('SELECT * FROM paid_registrations WHERE id = :id LIMIT 1');
$query->execute(['id' => $id]);
$entry = $query->fetch(PDO::FETCH_ASSOC);
if (!$entry) { http_response_code(404); exit('Paid form not found.'); }
$data = json_decode((string) $entry['submission_data'], true);
$data = is_array($data) ? $data : [];
$saved = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf()) {
        $error = 'Your session expired. Refresh and try again.';
    } else {
        $notes = trim((string) ($_POST['admin_notes'] ?? ''));
        if (strlen($notes) > 40000) $error = 'Notes are too long.';
        else {
            $update = $pdo->prepare('UPDATE paid_registrations SET admin_notes = :notes WHERE id = :id');
            $update->execute(['notes' => $notes === '' ? null : $notes, 'id' => $id]);
            $entry['admin_notes'] = $notes;
            $saved = true;
        }
    }
}

admin_page_start('Paid form #' . $id);
echo '<div class="subhead"><div><a class="muted" href="payments.php">← All paid forms</a><h1 style="margin-top:10px">' . admin_h((string) $entry['applicant_name']) . '</h1><p class="muted">' . admin_h(ucwords((string) $entry['form_type'])) . ' · submitted ' . admin_h(date('M j, Y · g:i a', strtotime((string) $entry['created_at']))) . '</p></div><span class="badge badge-accepted">Verified paid</span></div>';
if ($saved) echo '<div class="notice">Notes saved.</div>';
if ($error !== '') echo '<div class="notice error">' . admin_h($error) . '</div>';
echo '<div class="detail-grid"><section class="panel panel-pad"><h2 style="margin-top:0">Submission details</h2><dl class="detail-list">';
$fields = [
    'Email' => $entry['applicant_email'], 'Phone' => $entry['phone'], 'Category' => $entry['category'] ?: '—',
    'Package' => $entry['package_name'], 'Amount paid' => '₦' . number_format(((int) $entry['amount_kobo']) / 100, 2) . ' ' . $entry['currency'],
    'Payment status' => ucfirst((string) $entry['payment_status']), 'Payment reference' => $entry['payment_reference'],
    'Payment channel' => $entry['payment_channel'] ?: '—', 'Paid at' => $entry['payment_paid_at'] ?: $entry['created_at'],
];
foreach ($data as $key => $value) {
    if ($key === 'photo_mime' || $key === 'photo_size' || $key === 'attendees') continue;
    $label = ucwords(str_replace('_', ' ', (string) $key));
    if (is_array($value)) $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fields[$label] = is_scalar($value) ? (string) $value : '';
}
foreach ($fields as $label => $value) echo '<dt>' . admin_h((string) $label) . '</dt><dd>' . nl2br(admin_h((string) $value)) . '</dd>';
echo '</dl>';
if (isset($data['attendees']) && is_array($data['attendees'])) {
    echo '<h3>Group attendees (' . count($data['attendees']) . ')</h3><div class="table-scroll"><table style="min-width:560px"><thead><tr><th>Name</th><th>Email</th><th>Phone</th></tr></thead><tbody>';
    foreach ($data['attendees'] as $attendee) {
        if (!is_array($attendee)) continue;
        echo '<tr><td>' . admin_h((string) ($attendee['name'] ?? '—')) . '</td><td>' . admin_h((string) ($attendee['email'] ?? '—')) . '</td><td>' . admin_h((string) ($attendee['phone'] ?? '—')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
if (!empty($entry['photo_path'])) echo '<h3>Profile photograph</h3><a href="photo.php?id=' . (int) $id . '" target="_blank" rel="noopener" aria-label="Open applicant profile photograph at full size"><img src="photo.php?id=' . (int) $id . '" alt="Applicant profile photograph; click to view full size" title="Click to view full size" style="display:block;max-width:240px;max-height:300px;object-fit:contain;border:1px solid #dce5dc;border-radius:10px"></a><p class="muted">Click the photo to open the full-size image.</p>';
echo '</section><aside class="panel panel-pad"><h2 style="margin-top:0">Internal notes</h2><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="' . admin_h(admin_csrf_token()) . '"><label>Notes<textarea name="admin_notes" maxlength="10000" placeholder="Add a private follow-up or review note">' . admin_h((string) ($entry['admin_notes'] ?? '')) . '</textarea></label><button type="submit">Save notes</button><a class="button button-light" href="mailto:' . rawurlencode((string) $entry['applicant_email']) . '">Email applicant</a></form></aside></div>';
admin_page_end();
