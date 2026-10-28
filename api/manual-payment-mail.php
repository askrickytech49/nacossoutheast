<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email-templates.php';

function nacos_manual_send_email(array $message): bool
{
    $brevo = nacos_config()['brevo'] ?? [];
    $apiKey = trim((string) ($brevo['api_key'] ?? ''));
    $senderEmail = trim((string) ($brevo['sender_email'] ?? ''));
    $senderName = trim((string) ($brevo['sender_name'] ?? 'NACOS South East')) ?: 'NACOS South East';
    if ($apiKey === '' || str_contains($apiKey, 'ADD_YOUR_') || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || !function_exists('curl_init')) {
        error_log('Manual payment email is not configured or cURL is unavailable.');
        return false;
    }

    $message['sender'] = ['name' => $senderName, 'email' => $senderEmail];
    $message = nacos_add_email_banner($message);
    foreach (['subject', 'htmlContent', 'textContent'] as $field) {
        if (isset($message[$field]) && is_string($message[$field])) {
            $message[$field] = preg_replace('/\[\s*SAMPLE(?:\s+PREVIEW)?\s*\]\s*/i', '', $message[$field]);
        }
    }

    $curl = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'api-key: ' . $apiKey, 'content-type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($response === false) {
        $err = curl_error($curl);
        error_log('Manual payment email transport failed: ' . $err);
        curl_close($curl);
        $GLOBALS['last_email_error'] = 'Email transport timeout or connection error: ' . $err;
        return false;
    }
    curl_close($curl);
    if ($status < 200 || $status >= 300) {
        error_log('Brevo rejected manual payment email (HTTP ' . $status . '): ' . $response);
        $decoded = json_decode((string)$response, true);
        $msg = $decoded['message'] ?? ('HTTP error ' . $status);
        $GLOBALS['last_email_error'] = 'Brevo API error: ' . $msg;
        return false;
    }
    return true;
}

function nacos_manual_send_pending_notice(array $entry): bool
{
    $adminEmail = trim((string) (nacos_config()['admin_email'] ?? ''));
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) return false;
    $id = (int) $entry['id'];
    $name = htmlspecialchars((string) $entry['applicant_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $email = htmlspecialchars((string) $entry['applicant_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $package = htmlspecialchars((string) $entry['package_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $formType = (string) ($entry['form_type'] ?? 'convention');
    $formLabels = ['convention' => 'Convention', 'sponsorship' => 'Sponsorship', 'pageant' => 'Mr & Miss NACOS'];
    $formName = htmlspecialchars($formLabels[$formType] ?? 'Paid form', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $amount = '₦' . number_format((int) $entry['amount_kobo'] / 100, 2);
    $reference = htmlspecialchars((string) $entry['reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $url = 'https://www.nacosoutheast.org.ng/admin/manual-payment.php?id=' . $id;
    $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($formType === 'pageant') {
        $submission = json_decode((string) ($entry['submission_data'] ?? ''), true);
        $submission = is_array($submission) ? $submission : [];
        $details = [
            'Form' => 'Mr & Miss NACOS',
            'Applicant' => (string) $entry['applicant_name'],
            'Email' => (string) $entry['applicant_email'],
            'Phone' => (string) ($entry['phone'] ?? ''),
            'Category' => (string) ($entry['category'] ?? ''),
            'Registration fee' => $amount,
            'Payment status' => 'Pending manual review',
            'Payment reference' => (string) $entry['reference'],
            'Payment receipt' => 'Uploaded; open the admin review page to view it',
            'Applicant picture' => 'Uploaded; open the admin review page to view it',
            'Admin record' => 'Manual payment #' . $id,
        ];
        $labels = [
            'institution' => 'Institution / School',
            'department' => 'Department',
            'level' => 'Level',
            'state' => 'State',
            'date_of_birth' => 'Date of Birth',
            'bio' => 'Short Bio / About Yourself',
            'social_handles' => 'Social Media Handles',
            'gender' => 'Gender / Sex',
            'convention_registered' => 'Registered for NACOS Southeast Convention',
            'why_participate' => 'Why participate in Mr. & Miss NACOS',
            'suitable_representative' => 'Suitable NACOS representative',
            'nacos_meaning' => 'What NACOS means to the applicant',
            'accuracy_rules_agreement' => 'Accuracy confirmation and rules agreement',
            'media_promotional_consent' => 'Media and promotional usage consent',
            'have_made_registration_payment' => 'Have made registration payment',
            'reason_not_made_payment' => 'Reason for not making payment yet',
        ];
        foreach ($labels as $key => $label) {
            $value = $submission[$key] ?? '';
            $details[$label] = is_scalar($value) ? (string) $value : '';
        }
        $template = nacos_admin_notification_template('pageant', 'NACOS-MP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT), $details, $url);
        return nacos_manual_send_email([
            'to' => [['email' => $adminEmail, 'name' => 'NACOS South East Admin']],
            'subject' => 'Mr & Miss NACOS application and payment proof awaiting review · ' . (string) $entry['applicant_name'],
            'htmlContent' => $template['htmlContent'],
            'textContent' => $template['textContent'],
        ]);
    }
    $message = [
        'to' => [['email' => $adminEmail, 'name' => 'NACOS South East Admin']],
        'subject' => $formName . ' manual payment proof awaiting review · ' . $name,
        'htmlContent' => '<!doctype html><html><body style="margin:0;background:#fff;font-family:Arial,Helvetica,sans-serif;color:#18251b"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fff;padding:24px 12px"><tr><td align="center"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;border:1px solid #dfe8df;border-radius:12px"><tr><td style="padding:26px"><div style="color:#b36b00;font-size:11px;font-weight:700;letter-spacing:1.5px">MANUAL PAYMENT REVIEW · ' . $formName . '</div><h1 style="font-size:23px;margin:8px 0">Payment proof received</h1><p style="line-height:1.6;color:#536357">A registrant submitted a bank-transfer receipt. This submission is pending review and has not been marked paid.</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f8f2;border:1px solid #d8e9d9"><tr><td style="padding:14px;line-height:1.8"><strong>Form:</strong> ' . $formName . '<br><strong>Registrant:</strong> ' . $name . '<br><strong>Email:</strong> ' . $email . '<br><strong>Package:</strong> ' . $package . '<br><strong>Amount:</strong> ' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '<br><strong>Payment reference:</strong> ' . $reference . '</td></tr></table><p style="margin-top:22px"><a href="' . $safeUrl . '" style="display:inline-block;background:#0b6623;color:#fff;text-decoration:none;padding:12px 18px;border-radius:7px;font-weight:700">Review payment proof</a></p></td></tr></table></td></tr></table></body></html>',
        'textContent' => $formLabels[$formType] . " manual payment proof received; pending review. This submission is not marked paid yet.\n\nRegistrant: " . $entry['applicant_name'] . "\nEmail: " . $entry['applicant_email'] . "\nPackage: " . $entry['package_name'] . "\nAmount: " . $amount . "\nReference: " . $entry['reference'] . "\n\nReview: " . $url,
    ];
    return nacos_manual_send_email($message);
}

function nacos_manual_send_payer_confirmations(array $entry): bool
{
    $adminEmail = trim((string) (nacos_config()['admin_email'] ?? ''));
    $data = json_decode((string) ($entry['submission_data'] ?? ''), true);
    $attendees = is_array($data) && isset($data['attendees']) && is_array($data['attendees']) ? $data['attendees'] : [];
    if (!$attendees) {
        $attendees = [[
            'name' => (string) $entry['applicant_name'],
            'email' => (string) $entry['applicant_email'],
        ]];
    }

    $recipients = [];
    foreach ($attendees as $attendee) {
        if (!is_array($attendee)) continue;
        $email = trim((string) ($attendee['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || ($adminEmail !== '' && strcasecmp($email, $adminEmail) === 0)) continue;
        $key = strtolower($email);
        $recipients[$key] = [
            'email' => $email,
            'name' => trim((string) ($attendee['name'] ?? $entry['applicant_name'])),
        ];
    }
    if (!$recipients) return false;

    $allSent = true;
    $type = (string) ($entry['form_type'] ?? 'convention');
    $groupTemplate = $type === 'convention' && count($attendees) > 1
        ? nacos_convention_attendee_confirmation_template(count($attendees), (string) $entry['payment_reference'])
        : null;
    foreach ($recipients as $recipient) {
        $template = $groupTemplate ?? nacos_paid_applicant_confirmation_template([
                'type' => $type, 'name' => $recipient['name'], 'email' => $recipient['email'],
                'package' => (string) $entry['package_name'], 'amount' => (int) $entry['amount_kobo'],
                'currency' => (string) $entry['currency'], 'reference' => (string) $entry['payment_reference'],
            ]);
        $sent = nacos_manual_send_email([
            'to' => [['email' => $recipient['email'], 'name' => $recipient['name']]],
            'subject' => $template['subject'],
            'htmlContent' => $template['htmlContent'],
            'textContent' => $template['textContent'],
        ]);
        if (!$sent) $allSent = false;
    }
    return $allSent;
}
