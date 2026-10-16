<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email-templates.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function paid_reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function paid_text(array $source, string $key, int $max, bool $required = true): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) {
        paid_reply(422, ['ok' => false, 'error' => 'Some form details are invalid.']);
    }
    $value = trim((string) $value);
    if (($required && $value === '') || strlen($value) > ($max * 4)) {
        paid_reply(422, ['ok' => false, 'error' => 'Some form details are missing or too long.']);
    }
    return $value;
}

function paid_send_brevo_email(array $message, string $apiKey): bool
{
    if (!function_exists('curl_init')) {
        error_log('Paid form email: PHP cURL extension is not enabled.');
        return false;
    }
    $curl = curl_init('https://api.brevo.com/v3/smtp/email');
    $message = nacos_add_email_banner($message);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'api-key: ' . $apiKey, 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($response === false) {
        error_log('Paid form email transport failed: ' . curl_error($curl));
        curl_close($curl);
        return false;
    }
    curl_close($curl);
    if ($status < 200 || $status >= 300) {
        error_log('Brevo rejected paid form email (HTTP ' . $status . ').');
        return false;
    }
    return true;
}

function paid_send_notifications(array $entry): string
{
    $config = nacos_config();
    $brevo = $config['brevo'] ?? [];
    $apiKey = trim((string) ($brevo['api_key'] ?? ''));
    $senderEmail = trim((string) ($brevo['sender_email'] ?? ''));
    $senderName = trim((string) ($brevo['sender_name'] ?? 'NACOS South East')) ?: 'NACOS South East';
    $adminEmail = trim((string) ($config['admin_email'] ?? 'nacosoutheast@gmail.com'));
    if ($apiKey === '' || str_contains($apiKey, 'ADD_YOUR_') || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        return 'failed';
    }

    $typeNames = ['convention' => 'Convention', 'sponsorship' => 'Sponsorship', 'pageant' => 'Mr & Miss NACOS'];
    $details = [
        'Form' => $typeNames[$entry['type']] ?? ucfirst((string) $entry['type']),
        'Applicant' => (string) $entry['name'],
        'Email' => (string) $entry['email'],
        'Phone' => (string) $entry['phone'],
        'Category' => (string) ($entry['category'] ?? '—'),
        'Package' => (string) $entry['package'],
        'Amount paid' => '₦' . number_format((int) $entry['amount'] / 100, 2) . ' ' . (string) $entry['currency'],
        'Payment status' => 'Verified',
        'Payment reference' => (string) $entry['reference'],
        'Admin record' => 'Paid form #' . (int) $entry['id'],
    ];
    foreach (($entry['data'] ?? []) as $key => $value) {
        if (in_array((string) $key, ['photo_mime', 'photo_size'], true)) continue;
        $label = ucwords(str_replace('_', ' ', (string) $key));
        if ($key === 'attendees' && is_array($value)) {
            $value = implode("\n", array_map(static function ($person, $index): string {
                if (!is_array($person)) return '';
                return ($index + 1) . '. ' . (string) ($person['name'] ?? '') . ' · ' . (string) ($person['email'] ?? '') . ' · ' . (string) ($person['phone'] ?? '');
            }, $value, array_keys($value)));
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        $details[$label] = is_scalar($value) ? (string) $value : '';
    }
    $adminTemplate = nacos_admin_notification_template(
        (string) $entry['type'],
        (int) $entry['id'],
        $details,
        'https://www.nacosoutheast.org.ng/admin/paid-registration.php?id=' . (int) $entry['id']
    );
    $adminMessage = [
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [['email' => $adminEmail, 'name' => 'NACOS South East']],
        'replyTo' => ['email' => (string) $entry['email'], 'name' => (string) $entry['name']],
        'subject' => $adminTemplate['subject'],
        'htmlContent' => $adminTemplate['htmlContent'],
        'textContent' => $adminTemplate['textContent'],
    ];
    $applicantTemplate = nacos_paid_applicant_confirmation_template($entry);
    $applicantMessage = [
        'sender' => ['name' => $senderName, 'email' => $senderEmail],
        'to' => [['email' => (string) $entry['email'], 'name' => (string) $entry['name']]],
        'subject' => $applicantTemplate['subject'],
        'htmlContent' => $applicantTemplate['htmlContent'],
        'textContent' => $applicantTemplate['textContent'],
    ];
    if ((string) $entry['type'] === 'convention' && !empty($entry['data']['attendees']) && is_array($entry['data']['attendees'])) {
        $bcc = [];
        foreach ($entry['data']['attendees'] as $attendee) {
            if (!is_array($attendee) || empty($attendee['email'])) continue;
            $attendeeEmail = trim((string) $attendee['email']);
            if (!filter_var($attendeeEmail, FILTER_VALIDATE_EMAIL) || strcasecmp($attendeeEmail, $adminEmail) === 0) continue;
            $bcc[] = ['email' => $attendeeEmail];
        }
        $groupCount = count($entry['data']['attendees']);
        $attendeeTemplate = nacos_convention_attendee_confirmation_template($groupCount, (string) $entry['reference']);
        $attendeeMessage = [
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to' => [['email' => $adminEmail, 'name' => 'NACOS South East']],
            'subject' => $attendeeTemplate['subject'],
            'htmlContent' => $attendeeTemplate['htmlContent'],
            'textContent' => $attendeeTemplate['textContent'],
        ];
        if ($bcc) $attendeeMessage['bcc'] = $bcc;
        $applicantMessage = $attendeeMessage;
    }
    $adminSent = paid_send_brevo_email($adminMessage, $apiKey);
    $applicantSent = paid_send_brevo_email($applicantMessage, $apiKey);
    return $adminSent && $applicantSent ? 'sent' : ($adminSent || $applicantSent ? 'partial' : 'failed');
}

function paid_verify_transaction(string $reference, int $expectedKobo, string $email): array
{
    $secret = (string) (nacos_config()['paystack']['secret_key'] ?? '');
    if ($secret === '' || str_contains($secret, 'ADD_YOUR_')) {
        paid_reply(503, ['ok' => false, 'error' => 'Payment verification is not configured yet. Please contact the event team with your payment reference.']);
    }
    if (!function_exists('curl_init')) {
        paid_reply(503, ['ok' => false, 'error' => 'Payment verification is unavailable on this server.']);
    }

    $curl = curl_init('https://api.paystack.co/transaction/verify/' . rawurlencode($reference));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);
    if ($raw === false || $curlError !== 0 || $httpStatus !== 200) {
        paid_reply(502, ['ok' => false, 'error' => 'We could not verify the payment right now. Keep your payment reference and try again.']);
    }

    $response = json_decode($raw, true);
    $payment = is_array($response) ? ($response['data'] ?? null) : null;
    $paidEmail = is_array($payment) ? (string) ($payment['customer']['email'] ?? '') : '';
    if (!is_array($response) || empty($response['status']) || !is_array($payment)
        || ($payment['status'] ?? '') !== 'success'
        || !hash_equals($reference, (string) ($payment['reference'] ?? ''))
        || (int) ($payment['amount'] ?? 0) !== $expectedKobo
        || strtoupper((string) ($payment['currency'] ?? '')) !== 'NGN'
        || strcasecmp($paidEmail, $email) !== 0) {
        paid_reply(422, ['ok' => false, 'error' => 'The payment could not be matched to these form details. Contact the event team with your payment reference.']);
    }
    return $payment;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paid_reply(405, ['ok' => false, 'error' => 'Use POST to submit a payment record.']);
}

$isMultipart = str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
if ($isMultipart) {
    $encoded = $_POST['submission'] ?? '';
    $input = is_string($encoded) ? json_decode($encoded, true) : null;
} else {
    $input = json_decode(file_get_contents('php://input') ?: '', true);
}
if (!is_array($input)) {
    paid_reply(400, ['ok' => false, 'error' => 'The submitted form data is invalid.']);
}

$type = $input['type'] ?? '';
$reference = paid_text($input, 'reference', 100);
if (!preg_match('/^[A-Za-z0-9_.=\-]{3,100}$/', $reference)) {
    paid_reply(422, ['ok' => false, 'error' => 'The payment reference is invalid.']);
}

$photoTemp = null;
$photoExtension = null;
$photoMime = null;
$photoSize = 0;
$base = [];
$category = null;
$expectedKobo = 0;
$packageName = '';

if ($type === 'convention') {
    $packageName = paid_text($input, 'package_name', 100);
    $prices = [
        'HOME NACOSITE' => 10000,
        'VISITING NACOSITE' => 15000,
        'HOME NON NACOSITE' => 15000,
        'VISITING NON NACOSITE' => 20000,
        'VIRTUAL ATTENDEE' => 5000,
    ];
    $people = $input['attendees'] ?? null;
    if (!isset($prices[$packageName]) || !is_array($people) || count($people) < 1 || count($people) > 100) {
        paid_reply(422, ['ok' => false, 'error' => 'The convention package or attendee list is invalid.']);
    }
    $attendees = [];
    foreach ($people as $person) {
        if (!is_array($person)) paid_reply(422, ['ok' => false, 'error' => 'An attendee detail is invalid.']);
        $attendees[] = [
            'name' => paid_text($person, 'name', 180),
            'email' => paid_text($person, 'email', 254),
            'phone' => paid_text($person, 'phone', 50),
        ];
        if (!filter_var($attendees[count($attendees) - 1]['email'], FILTER_VALIDATE_EMAIL)) {
            paid_reply(422, ['ok' => false, 'error' => 'An attendee email is invalid.']);
        }
    }
    $institution = paid_text($input, 'institution', 200);
    $state = paid_text($input, 'state', 100, false);
    $expectedKobo = $prices[$packageName] * count($attendees) * 100;
    $base = ['institution' => $institution, 'state' => $state, 'attendees' => $attendees];
    $name = $attendees[0]['name'];
    $email = $attendees[0]['email'];
    $phone = $attendees[0]['phone'];
} elseif ($type === 'sponsorship') {
    $packageName = paid_text($input, 'package_name', 100);
    $prices = [
        'HEADLINE SPONSOR' => 10000000,
        'DIAMOND SPONSOR' => 5000000,
        'PLATINUM SPONSOR' => 3000000,
        'GOLD SPONSOR' => 2000000,
        'SILVER SPONSOR' => 1000000,
        'BRONZE SPONSOR' => 500000,
    ];
    if ($packageName === 'CUSTOM SPONSOR') {
        $customNaira = filter_var($input['custom_amount'] ?? null, FILTER_VALIDATE_INT);
        if ($customNaira === false || $customNaira < 1000 || $customNaira > 500000000) {
            paid_reply(422, ['ok' => false, 'error' => 'The custom sponsorship amount is invalid.']);
        }
        $expectedKobo = $customNaira * 100;
    } elseif (isset($prices[$packageName])) {
        $expectedKobo = $prices[$packageName] * 100;
    } else {
        paid_reply(422, ['ok' => false, 'error' => 'The sponsorship package is invalid.']);
    }
    $name = paid_text($input, 'full_name', 180);
    $email = paid_text($input, 'email', 254);
    $phone = paid_text($input, 'phone', 50);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) paid_reply(422, ['ok' => false, 'error' => 'The sponsor email is invalid.']);
    $category = paid_text($input, 'category', 100);
    $base = [
        'organisation' => paid_text($input, 'organisation', 200, false),
        'message' => paid_text($input, 'message', 3000, false),
    ];
} elseif ($type === 'pageant') {
    $packageName = 'MR & MISS NACOS APPLICATION';
    $expectedKobo = 20000 * 100;
    $name = paid_text($input, 'full_name', 180);
    $email = paid_text($input, 'email', 254);
    $phone = paid_text($input, 'phone', 50);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) paid_reply(422, ['ok' => false, 'error' => 'The applicant email is invalid.']);
    $category = paid_text($input, 'category', 60);
    if (!in_array($category, ['Mr NACOS', 'Miss NACOS'], true)) paid_reply(422, ['ok' => false, 'error' => 'The pageant category is invalid.']);
    $base = [
        'institution' => paid_text($input, 'institution', 200),
        'department' => paid_text($input, 'department', 160),
        'level' => paid_text($input, 'level', 60),
        'state' => paid_text($input, 'state', 100),
        'date_of_birth' => paid_text($input, 'date_of_birth', 20),
        'bio' => paid_text($input, 'bio', 500),
        'social_handles' => paid_text($input, 'social_handles', 300, false),
    ];
    if (!isset($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || (int) ($_FILES['photo']['size'] ?? 0) > 5 * 1024 * 1024) {
        paid_reply(422, ['ok' => false, 'error' => 'Please include a profile photo smaller than 5 MB.']);
    }
    $photoTemp = (string) $_FILES['photo']['tmp_name'];
    $photoSize = (int) $_FILES['photo']['size'];
    $photoMime = (new finfo(FILEINFO_MIME_TYPE))->file($photoTemp);
    $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($mimeMap[$photoMime])) paid_reply(422, ['ok' => false, 'error' => 'The profile photo must be JPG, PNG or WEBP.']);
    $photoExtension = $mimeMap[$photoMime];
} else {
    paid_reply(422, ['ok' => false, 'error' => 'This paid form type is not supported.']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) paid_reply(422, ['ok' => false, 'error' => 'The email address is invalid.']);
$payment = paid_verify_transaction($reference, $expectedKobo, $email);
$photoPath = null;
if ($photoTemp !== null) {
    $uploadDirectory = dirname(__DIR__) . '/uploads/paid';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        paid_reply(500, ['ok' => false, 'error' => 'The application could not be saved. Contact the event team with your payment reference.']);
    }
    $fileName = bin2hex(random_bytes(20)) . '.' . $photoExtension;
    if (!move_uploaded_file($photoTemp, $uploadDirectory . '/' . $fileName)) {
        paid_reply(500, ['ok' => false, 'error' => 'The application could not be saved. Contact the event team with your payment reference.']);
    }
    $photoPath = $fileName;
    $base['photo_mime'] = $photoMime;
    $base['photo_size'] = $photoSize;
}

$submissionData = json_encode($base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($submissionData === false) paid_reply(422, ['ok' => false, 'error' => 'The form details could not be saved.']);
try {
    $pdo = nacos_db();
    $statement = $pdo->prepare(
        'INSERT INTO paid_registrations
        (form_type, applicant_name, applicant_email, phone, category, package_name, amount_kobo, currency, payment_status, payment_reference, payment_channel, payment_paid_at, submission_data, photo_path)
        VALUES (:form_type, :name, :email, :phone, :category, :package_name, :amount, :currency, \'paid\', :reference, :channel, :paid_at, :submission_data, :photo_path)'
    );
    $paidAt = isset($payment['paid_at']) ? date('Y-m-d H:i:s', strtotime((string) $payment['paid_at'])) : null;
    $statement->execute([
        'form_type' => $type,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'category' => $category,
        'package_name' => $packageName,
        'amount' => (int) $payment['amount'],
        'currency' => strtoupper((string) $payment['currency']),
        'reference' => $reference,
        'channel' => (string) ($payment['channel'] ?? ''),
        'paid_at' => $paidAt,
        'submission_data' => $submissionData,
        'photo_path' => $photoPath,
    ]);
    $registrationId = (int) $pdo->lastInsertId();
    $emailStatus = paid_send_notifications([
        'id' => $registrationId,
        'type' => $type,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'package' => $packageName,
        'amount' => (int) $payment['amount'],
        'currency' => strtoupper((string) $payment['currency']),
        'reference' => $reference,
        'data' => $base,
    ]);
    paid_reply(200, ['ok' => true, 'id' => $registrationId, 'email_status' => $emailStatus, 'message' => 'Payment verified and application saved.']);
} catch (PDOException $error) {
    if ($photoPath !== null) @unlink(dirname(__DIR__) . '/uploads/paid/' . $photoPath);
    if ((string) $error->getCode() === '23000') {
        $existing = $pdo->prepare('SELECT id, form_type, applicant_email FROM paid_registrations WHERE payment_reference = :reference LIMIT 1');
        $existing->execute(['reference' => $reference]);
        $saved = $existing->fetch(PDO::FETCH_ASSOC);
        if ($saved && $saved['form_type'] === $type && strcasecmp((string) $saved['applicant_email'], $email) === 0) {
            if ($photoPath !== null) @unlink(dirname(__DIR__) . '/uploads/paid/' . $photoPath);
            paid_reply(200, ['ok' => true, 'id' => (int) $saved['id'], 'message' => 'Payment already verified and application saved.']);
        }
        if ($photoPath !== null) @unlink(dirname(__DIR__) . '/uploads/paid/' . $photoPath);
        paid_reply(409, ['ok' => false, 'error' => 'This payment reference has already been recorded. Contact the event team if you need help.']);
    }
    error_log('Paid registration database save failed: ' . $error->getMessage());
    paid_reply(500, ['ok' => false, 'error' => 'The payment was verified but the form could not be saved. Contact the event team with your payment reference.']);
} catch (Throwable $error) {
    if ($photoPath !== null) @unlink(dirname(__DIR__) . '/uploads/paid/' . $photoPath);
    error_log('Paid registration save failed: ' . $error->getMessage());
    paid_reply(500, ['ok' => false, 'error' => 'The payment was verified but the form could not be saved. Contact the event team with your payment reference.']);
}
