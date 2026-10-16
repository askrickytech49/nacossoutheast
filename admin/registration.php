<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid registration id.');
}
$pdo = nacos_db();
$query = $pdo->prepare('SELECT * FROM hackathon_registrations WHERE id = :id LIMIT 1');
$query->execute([':id' => $id]);
$registration = $query->fetch(PDO::FETCH_ASSOC);
if (!$registration) {
    http_response_code(404);
    exit('Registration not found.');
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf()) {
        $error = 'This page expired. Refresh and try again.';
    } else {
        $status = (string) ($_POST['review_status'] ?? '');
        $notes = trim((string) ($_POST['admin_notes'] ?? ''));
        $validStatuses = ['new', 'reviewing', 'shortlisted', 'accepted', 'rejected'];
        if (!in_array($status, $validStatuses, true)) {
            $error = 'Choose a valid review status.';
        } elseif (strlen($notes) > 10000) {
            $error = 'Notes must be 10,000 characters or fewer.';
        } else {
            $update = $pdo->prepare('UPDATE hackathon_registrations SET review_status = :status, admin_notes = :notes WHERE id = :id');
            $update->execute([':status' => $status, ':notes' => $notes !== '' ? $notes : null, ':id' => $id]);
            header('Location: registration.php?id=' . $id . '&saved=1');
            exit;
        }
    }
}
if (isset($_GET['saved'])) {
    $message = 'Review notes and status saved.';
}
$registration['review_status'] = (string) $registration['review_status'];
$statusOptions = ['new', 'reviewing', 'shortlisted', 'accepted', 'rejected'];
$csrf = admin_h(admin_csrf_token());

admin_page_start('Review registration #' . $id);
echo '<div class="subhead"><div><a href="index.php" class="muted">← All registrations</a><h1 style="margin-top:12px">' . admin_h($registration['full_name']) . '</h1><p class="muted">Registration #' . (int) $id . ' · Submitted ' . admin_h(date('M j, Y · g:i a', strtotime((string) $registration['created_at']))) . '</p></div><div class="actions"><a class="button button-light" href="mailto:' . rawurlencode((string) $registration['email']) . '">Email applicant</a></div></div>';
if ($message !== '') echo '<div class="notice">' . admin_h($message) . '</div>';
if ($error !== '') echo '<div class="notice error">' . admin_h($error) . '</div>';
echo '<div class="detail-grid"><section class="panel panel-pad"><h2 style="font-size:17px;margin-bottom:10px">Application details</h2><dl class="detail-list">';
$fields = [
    'Email' => $registration['email'],
    'Phone' => $registration['phone'],
    'Institution' => $registration['institution'],
    'Department' => $registration['department'],
    'Level' => $registration['study_level'],
    'State' => $registration['state'],
    'Participation' => $registration['participation'],
    'Team name' => $registration['team_name'] ?: 'Not provided',
    'Team size' => $registration['team_size'] ? (string) $registration['team_size'] . ' members' : 'Not applicable',
    'Project idea' => $registration['project_idea'],
    'GitHub / Portfolio' => $registration['portfolio_url'] ?: 'Not provided',
    'Other information' => $registration['other_information'] ?: 'Not provided',
];
foreach ($fields as $label => $value) {
    echo '<dt>' . admin_h($label) . '</dt><dd>' . admin_h((string) $value) . '</dd>';
}
echo '</dl>';
if ($registration['participation'] === 'Team') {
    echo '<div class="notice"><strong>Team application.</strong> This submission includes the team name and team size. The current form records the submitter as the contact person; it does not collect each teammate’s name or contact details.</div>';
}
echo '</section><aside class="panel panel-pad"><h2 style="font-size:17px;margin-bottom:16px">Review application</h2><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="' . $csrf . '"><label>Status<select name="review_status">';
foreach ($statusOptions as $option) {
    echo '<option value="' . admin_h($option) . '"' . ($registration['review_status'] === $option ? ' selected' : '') . '>' . admin_h(ucfirst($option)) . '</option>';
}
echo '</select></label><label>Private admin notes<textarea name="admin_notes" maxlength="10000" placeholder="Add follow-up notes for the review team">' . admin_h((string) ($registration['admin_notes'] ?? '')) . '</textarea></label><button type="submit">Save review</button></form></aside></div>';
admin_page_end();
