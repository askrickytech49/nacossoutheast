<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/manual-payment-mail.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function manual_reply(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function manual_text(array $input, string $key, int $max, bool $required = true): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) manual_reply(422, ['ok' => false, 'error' => 'Some form details are invalid.']);
    $value = trim((string) $value);
    if (($required && $value === '') || strlen($value) > $max * 4) manual_reply(422, ['ok' => false, 'error' => 'Some required form details are missing or too long.']);
    return $value;
}

function manual_upload(array $upload, array $mimeExtensions, string $label, int $maxMb = 5): array
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || (int) ($upload['size'] ?? 0) < 1 || (int) $upload['size'] > $maxMb * 1024 * 1024
        || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        manual_reply(422, ['ok' => false, 'error' => 'Upload a ' . $label . ' under ' . $maxMb . ' MB.']);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
    if (!isset($mimeExtensions[$mime])) manual_reply(422, ['ok' => false, 'error' => 'The ' . $label . ' file type is not supported.']);
    return [$mime, $mimeExtensions[$mime]];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') manual_reply(405, ['ok' => false, 'error' => 'Use POST to submit a payment receipt.']);
$registrationJson = $_POST['registration'] ?? '';
if (!is_string($registrationJson) || strlen($registrationJson) > 100000) manual_reply(422, ['ok' => false, 'error' => 'Registration details are invalid.']);
$input = json_decode($registrationJson, true);
$type = is_array($input) ? (string) ($input['type'] ?? '') : '';
if (!is_array($input) || !in_array($type, ['convention', 'sponsorship', 'pageant'], true)) manual_reply(422, ['ok' => false, 'error' => 'This manual payment form is invalid.']);

$name = '';
$email = '';
$phone = '';
$package = '';
$category = null;
$amountKobo = 0;
$data = [];
$applicationPhoto = null;
$applicationPhotoMime = null;

if ($type === 'convention') {
    $prices = ['HOME NACOSITE' => 10000, 'VISITING NACOSITE' => 15000, 'HOME NON NACOSITE' => 15000, 'VISITING NON NACOSITE' => 20000, 'VIRTUAL ATTENDEE' => 5000];
    $package = manual_text($input, 'package_name', 160);
    $people = $input['attendees'] ?? null;
    if (!isset($prices[$package]) || !is_array($people) || count($people) < 1 || count($people) > 100) manual_reply(422, ['ok' => false, 'error' => 'The selected convention package or attendee list is invalid.']);
    $attendees = [];
    foreach ($people as $person) {
        if (!is_array($person)) manual_reply(422, ['ok' => false, 'error' => 'An attendee detail is invalid.']);
        $personName = manual_text($person, 'name', 180);
        $personEmail = manual_text($person, 'email', 254);
        $personPhone = manual_text($person, 'phone', 50);
        if (!filter_var($personEmail, FILTER_VALIDATE_EMAIL)) manual_reply(422, ['ok' => false, 'error' => 'An attendee email is invalid.']);
        $attendees[] = ['name' => $personName, 'email' => $personEmail, 'phone' => $personPhone];
    }
    $name = $attendees[0]['name']; $email = $attendees[0]['email']; $phone = $attendees[0]['phone'];
    $data = ['institution' => manual_text($input, 'institution', 200), 'state' => manual_text($input, 'state', 100, false), 'attendees' => $attendees, 'manual_payment' => true];
    $amountKobo = $prices[$package] * count($attendees) * 100;
} elseif ($type === 'sponsorship') {
    $prices = ['HEADLINE SPONSOR' => 10000000, 'DIAMOND SPONSOR' => 5000000, 'PLATINUM SPONSOR' => 3000000, 'GOLD SPONSOR' => 2000000, 'SILVER SPONSOR' => 1000000, 'BRONZE SPONSOR' => 500000];
    $package = manual_text($input, 'package_name', 160);
    if ($package === 'CUSTOM SPONSOR') {
        $customNaira = filter_var($input['custom_amount'] ?? null, FILTER_VALIDATE_INT);
        if ($customNaira === false || $customNaira < 1000 || $customNaira > 500000000) manual_reply(422, ['ok' => false, 'error' => 'The custom sponsorship amount is invalid.']);
        $amountKobo = $customNaira * 100;
    } elseif (isset($prices[$package])) $amountKobo = $prices[$package] * 100;
    else manual_reply(422, ['ok' => false, 'error' => 'The selected sponsorship package is invalid.']);
    $name = manual_text($input, 'full_name', 180); $email = manual_text($input, 'email', 254); $phone = manual_text($input, 'phone', 50);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) manual_reply(422, ['ok' => false, 'error' => 'The sponsor email is invalid.']);
    $category = manual_text($input, 'category', 100);
    if (!in_array($category, ['Individual', 'Corporate', 'NGO', 'Government', 'Alumni'], true)) manual_reply(422, ['ok' => false, 'error' => 'The sponsorship category is invalid.']);
    $data = ['organisation' => manual_text($input, 'organisation', 200, false), 'message' => manual_text($input, 'message', 3000, false), 'manual_payment' => true];
} else {
    $package = 'MR & MISS NACOS APPLICATION'; $amountKobo = 20000 * 100;
    $name = manual_text($input, 'full_name', 180); $email = manual_text($input, 'email', 254); $phone = manual_text($input, 'phone', 50);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) manual_reply(422, ['ok' => false, 'error' => 'The applicant email is invalid.']);
    $category = manual_text($input, 'category', 60);
    if (!in_array($category, ['Mr NACOS', 'Miss NACOS'], true)) manual_reply(422, ['ok' => false, 'error' => 'The pageant category is invalid.']);
    $gender = manual_text($input, 'gender', 10);
    $conventionRegistered = manual_text($input, 'convention_registered', 3);
    $accuracyRulesAgreement = manual_text($input, 'accuracy_rules_agreement', 3);
    $mediaConsent = manual_text($input, 'media_promotional_consent', 3);
    $paymentMade = manual_text($input, 'have_made_registration_payment', 3);
    if (!in_array($gender, ['Male', 'Female'], true)
        || !in_array($conventionRegistered, ['Yes', 'No'], true)
        || !in_array($accuracyRulesAgreement, ['Yes', 'No'], true)
        || !in_array($mediaConsent, ['Yes', 'No'], true)
        || !in_array($paymentMade, ['Yes', 'No'], true)) {
        manual_reply(422, ['ok' => false, 'error' => 'Choose a valid answer for each required question.']);
    }
    $reasonNotPaid = manual_text($input, 'reason_not_made_payment', 1500, false);
    if ($paymentMade === 'No' && $reasonNotPaid === '') manual_reply(422, ['ok' => false, 'error' => 'Please provide the reason you have not made the payment yet.']);
    $data = [
        'institution' => manual_text($input, 'institution', 200), 'department' => manual_text($input, 'department', 160),
        'level' => manual_text($input, 'level', 60), 'state' => manual_text($input, 'state', 100),
        'date_of_birth' => manual_text($input, 'date_of_birth', 20), 'bio' => manual_text($input, 'bio', 500),
        'social_handles' => manual_text($input, 'social_handles', 300, false),
        'gender' => $gender,
        'convention_registered' => $conventionRegistered,
        'why_participate' => manual_text($input, 'why_participate', 3000, false),
        'suitable_representative' => manual_text($input, 'suitable_representative', 3000, false),
        'nacos_meaning' => manual_text($input, 'nacos_meaning', 3000, false),
        'accuracy_rules_agreement' => $accuracyRulesAgreement,
        'media_promotional_consent' => $mediaConsent,
        'have_made_registration_payment' => $paymentMade,
        'reason_not_made_payment' => $reasonNotPaid,
        'manual_payment' => true,
    ];
    [$applicationPhotoMime, $photoExtension] = manual_upload($_FILES['photo'] ?? [], ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 'profile photo', 10);
    $applicationPhoto = ['tmp' => (string) $_FILES['photo']['tmp_name'], 'extension' => $photoExtension];
}

$email = trim($email);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) manual_reply(422, ['ok' => false, 'error' => 'The email address is invalid.']);
[$proofMime, $proofExtension] = manual_upload($_FILES['proof'] ?? [], ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'], 'transfer receipt', $type === 'pageant' ? 10 : 5);
$data['category'] = $category;
$data['proof_mime'] = $proofMime;
if ($applicationPhoto !== null) $data['photo_mime'] = $applicationPhotoMime;
$jsonData = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($jsonData === false) manual_reply(422, ['ok' => false, 'error' => 'The registration details could not be read.']);

$directory = dirname(__DIR__) . '/uploads/manual-payments';
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) manual_reply(500, ['ok' => false, 'error' => 'Receipt storage is unavailable. Please contact NACOS.']);
$photoDirectory = dirname(__DIR__) . '/uploads/paid';
if ($applicationPhoto !== null && !is_dir($photoDirectory) && !mkdir($photoDirectory, 0750, true) && !is_dir($photoDirectory)) manual_reply(500, ['ok' => false, 'error' => 'Application photo storage is unavailable. Please contact NACOS.']);
$proofName = bin2hex(random_bytes(24)) . '.' . $proofExtension;
$photoName = $applicationPhoto !== null ? bin2hex(random_bytes(20)) . '.' . $applicationPhoto['extension'] : null;
if (!move_uploaded_file((string) $_FILES['proof']['tmp_name'], $directory . '/' . $proofName)) manual_reply(500, ['ok' => false, 'error' => 'The receipt could not be saved. Please try again.']);
if ($photoName !== null && !move_uploaded_file($applicationPhoto['tmp'], $photoDirectory . '/' . $photoName)) {
    @unlink($directory . '/' . $proofName);
    manual_reply(500, ['ok' => false, 'error' => 'The application photo could not be saved. Please try again.']);
}

try {
    $pdo = nacos_db();
    $statement = $pdo->prepare('INSERT INTO manual_payment_submissions (form_type, applicant_name, applicant_email, phone, category, package_name, amount_kobo, currency, submission_data, proof_path, application_photo_path) VALUES (:type, :name, :email, :phone, :category, :package, :amount, \'NGN\', :data, :proof, :photo)');
    $statement->execute(['type' => $type, 'name' => $name, 'email' => $email, 'phone' => $phone, 'category' => $category, 'package' => $package, 'amount' => $amountKobo, 'data' => $jsonData, 'proof' => $proofName, 'photo' => $photoName]);
    $id = (int) $pdo->lastInsertId();
    $pendingReference = 'NACOS-MP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    $emailSent = nacos_manual_send_pending_notice([
        'id' => $id, 'form_type' => $type, 'applicant_name' => $name,
        'applicant_email' => $email, 'phone' => $phone, 'category' => $category,
        'package_name' => $package, 'amount_kobo' => $amountKobo,
        'reference' => $pendingReference, 'submission_data' => $jsonData,
    ]);
    $pdo->prepare('UPDATE manual_payment_submissions SET admin_email_status = :status WHERE id = :id')->execute(['status' => $emailSent ? 'sent' : 'failed', 'id' => $id]);
    manual_reply(201, ['ok' => true, 'reference' => $pendingReference, 'email_status' => $emailSent ? 'sent' : 'failed', 'message' => 'Receipt received and waiting for admin review. It is not a confirmed payment yet.']);
} catch (Throwable $error) {
    @unlink($directory . '/' . $proofName);
    if ($photoName !== null) @unlink($photoDirectory . '/' . $photoName);
    error_log('Manual payment submission failed: ' . $error->getMessage());
    manual_reply(500, ['ok' => false, 'error' => 'The receipt could not be saved. Keep your transfer receipt and contact NACOS.']);
}
