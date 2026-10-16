<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email-templates.php';

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function env_value(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

function field(array $input, string $key, int $maxLength = 5000): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    $value = trim((string) $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function send_brevo_email(array $message, string $apiKey): bool
{
    if (!function_exists('curl_init')) {
        error_log('Hackathon email: PHP cURL extension is not enabled.');
        return false;
    }

    $curl = curl_init('https://api.brevo.com/v3/smtp/email');
    $message = nacos_add_email_banner($message);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($response === false) {
        error_log('Hackathon email transport error: ' . curl_error($curl));
        curl_close($curl);
        return false;
    }
    curl_close($curl);

    if ($status < 200 || $status >= 300) {
        error_log('Brevo rejected Hackathon email (HTTP ' . $status . '): ' . substr((string) $response, 0, 1000));
        return false;
    }
    return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'Use POST to submit this form.']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 32768) {
    respond(413, ['ok' => false, 'error' => 'The submission is too large.']);
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody ?: '', true);
if (!is_array($input)) {
    respond(400, ['ok' => false, 'error' => 'Please submit the form again.']);
}

// Quietly discard automated submissions caught by the hidden form field.
if (field($input, 'company_website', 200) !== '') {
    respond(202, ['ok' => true, 'email_status' => 'sent']);
}

$submission = [
    'full_name' => field($input, 'full_name', 160),
    'email' => field($input, 'email', 254),
    'phone' => field($input, 'phone', 40),
    'institution' => field($input, 'institution', 200),
    'department' => field($input, 'department', 160),
    'study_level' => field($input, 'study_level', 40),
    'state' => field($input, 'state', 120),
    'participation' => field($input, 'participation', 20),
    'team_name' => field($input, 'team_name', 160),
    'team_size' => field($input, 'team_size', 20),
    'project_idea' => field($input, 'project_idea', 5000),
    'portfolio_url' => field($input, 'portfolio_url', 2048),
    'other_information' => field($input, 'other_information', 1000),
];

$requiredFields = ['full_name', 'email', 'phone', 'institution', 'department', 'study_level', 'state', 'participation', 'project_idea'];
foreach ($requiredFields as $requiredField) {
    if ($submission[$requiredField] === '') {
        respond(422, ['ok' => false, 'error' => 'Please complete all required fields.']);
    }
}
if (!filter_var($submission['email'], FILTER_VALIDATE_EMAIL)) {
    respond(422, ['ok' => false, 'error' => 'Enter a valid email address.']);
}
if (!in_array($submission['participation'], ['Individual', 'Team'], true)) {
    respond(422, ['ok' => false, 'error' => 'Choose Individual or Team participation.']);
}
if ($submission['participation'] === 'Team') {
    if (!preg_match('/^[2-5]/', $submission['team_size'], $sizeMatch)) {
        respond(422, ['ok' => false, 'error' => 'Select a team size from 2 to 5.']);
    }
    $submission['team_size'] = (int) $sizeMatch[0];
} else {
    $submission['team_name'] = '';
    $submission['team_size'] = null;
}
if ($submission['portfolio_url'] !== '' && !filter_var($submission['portfolio_url'], FILTER_VALIDATE_URL)) {
    respond(422, ['ok' => false, 'error' => 'Enter a valid GitHub or portfolio URL.']);
}

$config = nacos_config();
$brevoApiKey = (string) ($config['brevo']['api_key'] ?? '');
$senderEmail = (string) ($config['brevo']['sender_email'] ?? '');
$senderName = (string) ($config['brevo']['sender_name'] ?? 'NACOS South East');
$adminEmail = (string) ($config['admin_email'] ?? 'nacosoutheast@gmail.com');
$brevoReady = $brevoApiKey !== '' && filter_var($senderEmail, FILTER_VALIDATE_EMAIL);

try {
    $pdo = nacos_db();

    $insert = $pdo->prepare(
        'INSERT INTO hackathon_registrations
         (full_name, email, phone, institution, department, study_level, state, participation, team_name, team_size, project_idea, portfolio_url, other_information)
         VALUES
         (:full_name, :email, :phone, :institution, :department, :study_level, :state, :participation, :team_name, :team_size, :project_idea, :portfolio_url, :other_information)'
    );
    $insert->execute([
        ':full_name' => $submission['full_name'],
        ':email' => $submission['email'],
        ':phone' => $submission['phone'],
        ':institution' => $submission['institution'],
        ':department' => $submission['department'],
        ':study_level' => $submission['study_level'],
        ':state' => $submission['state'],
        ':participation' => $submission['participation'],
        ':team_name' => $submission['team_name'] ?: null,
        ':team_size' => $submission['team_size'],
        ':project_idea' => $submission['project_idea'],
        ':portfolio_url' => $submission['portfolio_url'] ?: null,
        ':other_information' => $submission['other_information'] ?: null,
    ]);
    $registrationId = (int) $pdo->lastInsertId();
} catch (Throwable $error) {
    error_log('Hackathon database save failed: ' . $error->getMessage());
    respond(503, ['ok' => false, 'error' => 'We could not save your registration right now. Please try again later.']);
}

$labels = [
    'Registration number' => (string) $registrationId,
    'Full name' => $submission['full_name'],
    'Email' => $submission['email'],
    'Phone' => $submission['phone'],
    'Institution' => $submission['institution'],
    'Department' => $submission['department'],
    'Level' => $submission['study_level'],
    'State' => $submission['state'],
    'Participation' => $submission['participation'],
    'Team name' => $submission['team_name'] ?: 'Not provided',
    'Team size' => $submission['team_size'] ? (string) $submission['team_size'] : 'Not applicable',
    'Project idea' => $submission['project_idea'],
    'GitHub / Portfolio' => $submission['portfolio_url'] ?: 'Not provided',
    'Other information' => $submission['other_information'] ?: 'Not provided',
];
$adminTemplate = nacos_admin_notification_template('hackathon', $registrationId, $labels, 'https://www.nacosoutheast.org.ng/admin/registration.php?id=' . $registrationId);
$adminMessage = [
    'sender' => ['name' => $senderName, 'email' => $senderEmail],
    'to' => [['email' => $adminEmail, 'name' => 'NACOS South East Hackathon Team']],
    'replyTo' => ['email' => $submission['email'], 'name' => $submission['full_name']],
    'subject' => $adminTemplate['subject'],
    'htmlContent' => $adminTemplate['htmlContent'],
    'textContent' => $adminTemplate['textContent'],
];
$participantMessage = [
    'sender' => ['name' => $senderName, 'email' => $senderEmail],
    'to' => [['email' => $submission['email'], 'name' => $submission['full_name']]],
    'subject' => 'Hackathon registration received',
    'htmlContent' => '<p>Hello ' . htmlspecialchars($submission['full_name'], ENT_QUOTES, 'UTF-8') . ',</p><p>Your NACOS South East Hackathon registration has been received. The team will contact you with more information.</p><p>Your registration number is <strong>#' . $registrationId . '</strong>.</p><p>Thank you,<br>NACOS South East</p>',
    'textContent' => 'Hello ' . $submission['full_name'] . ",\n\nYour NACOS South East Hackathon registration has been received. The team will contact you with more information.\n\nRegistration number: #" . $registrationId . "\n\nThank you,\nNACOS South East",
];

$teamEmailSent = $brevoReady ? send_brevo_email($adminMessage, $brevoApiKey) : false;
$participantEmailSent = $brevoReady ? send_brevo_email($participantMessage, $brevoApiKey) : false;
$emailStatus = $teamEmailSent && $participantEmailSent ? 'sent' : ($teamEmailSent || $participantEmailSent ? 'partial' : 'failed');

respond(201, [
    'ok' => true,
    'registration_id' => $registrationId,
    'email_status' => $emailStatus,
]);
