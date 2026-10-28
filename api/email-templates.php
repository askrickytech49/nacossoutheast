<?php
declare(strict_types=1);

function nacos_add_email_banner(array $message): array
{
    if (!empty($message['skip_banner'])) return $message;
    $html = (string) ($message['htmlContent'] ?? '');
    $bannerUrl = 'https://www.nacosoutheast.org.ng/images/Banner.png';
    if ($html === '' || str_contains($html, $bannerUrl) || str_contains($html, 'Logo-removebg-preview.png')) return $message;

    $banner = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#ffffff"><tr><td align="center" style="padding:0 12px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px"><tr><td style="padding:0"><img src="' . $bannerUrl . '" alt="NACOS South East" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;outline:none;text-decoration:none"></td></tr></table></td></tr></table>';
    if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
        $bodyTag = $match[0][0];
        $position = $match[0][1] + strlen($bodyTag);
        $html = substr($html, 0, $position) . $banner . substr($html, $position);
    } else {
        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#ffffff">' . $banner . $html . '</body></html>';
    }
    $message['htmlContent'] = $html;
    return $message;
}

function nacos_admin_notification_template(string $kind, int|string $recordId, array $details, string $detailUrl, bool $sample = false): array
{
    $templates = [
        'hackathon' => ['title' => 'New Hackathon application', 'eyebrow' => 'HACKATHON APPLICATION', 'subject' => 'New Hackathon application'],
        'convention' => ['title' => 'New Convention registration', 'eyebrow' => 'CONVENTION REGISTRATION', 'subject' => 'New Convention registration'],
        'sponsorship' => ['title' => 'New Sponsorship submission', 'eyebrow' => 'SPONSORSHIP SUBMISSION', 'subject' => 'New Sponsorship submission'],
        'pageant' => ['title' => 'New Mr & Miss NACOS application', 'eyebrow' => 'MR & MISS NACOS', 'subject' => 'New Mr & Miss NACOS application'],
    ];
    $template = $templates[$kind] ?? ['title' => 'New NACOS form submission', 'eyebrow' => 'FORM SUBMISSION', 'subject' => 'New NACOS form submission'];
    $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows = '';
    $textRows = [];
    foreach ($details as $label => $value) {
        $label = (string) $label;
        $value = is_scalar($value) ? (string) $value : '';
        $rows .= '<tr><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#536357;font-size:13px;font-weight:600;width:34%;vertical-align:top">' . $safe($label) . '</td><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#18251b;font-size:13px;line-height:1.6;vertical-align:top">' . nl2br($safe($value)) . '</td></tr>';
        $textRows[] = $label . ': ' . $value;
    }
    $safeTitle = $safe($template['title']);
    $safeRecordId = $safe((string) $recordId);
    $safeUrl = $safe($detailUrl);
    $sampleNotice = $sample ? '<tr><td style="padding:12px 28px 0"><div style="padding:11px 13px;border:1px solid #f0d9a5;border-radius:7px;background:#fff8e8;color:#805a12;font-size:12px;font-weight:700">SAMPLE PREVIEW · This message contains fictional details and is not a real submission.</div></td></tr>' : '';
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#18251b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #dfe8df;border-radius:12px;overflow:hidden">'
        . '<tr><td style="padding:26px 28px 8px"><div style="color:#16803a;font-size:11px;font-weight:700;letter-spacing:1.5px">' . $safe($template['eyebrow']) . '</div><h1 style="margin:8px 0 8px;font-size:23px;line-height:1.3;color:#132219">' . $safeTitle . '</h1><p style="margin:0;color:#617064;font-size:14px;line-height:1.6">A new form submission has been received by NACOS South East.</p></td></tr>'
        . $sampleNotice
        . '<tr><td style="padding:16px 28px 8px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f8f2;border:1px solid #d8e9d9;border-radius:8px"><tr><td style="padding:13px 15px;color:#294530;font-size:13px"><strong>Admin record:</strong> #' . $safeRecordId . '</td><td align="right" style="padding:13px 15px;color:#08732d;font-size:12px;font-weight:700">' . strtoupper($safe($kind)) . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:10px 28px 22px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4ebe4;border-radius:8px;border-collapse:separate">' . $rows . '</table>'
        . '<p style="margin:20px 0 0"><a href="' . $safeUrl . '" style="display:inline-block;background:#0b6623;color:#fff;text-decoration:none;font-size:13px;font-weight:700;padding:12px 18px;border-radius:7px">Review in admin</a></p></td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8faf8;border-top:1px solid #e8eee8;color:#718074;font-size:11px;line-height:1.6">Automated admin notification · NACOS South East<br>Please reply directly to the applicant to follow up.</td></tr>'
        . '</table></td></tr></table></body></html>';
    return [
        'subject' => ($sample ? '[SAMPLE PREVIEW] ' : '') . $template['subject'] . ' #' . $recordId,
        'htmlContent' => $html,
        'textContent' => ($sample ? "SAMPLE PREVIEW - fictional details, not a real submission.\n\n" : '') . $template['title'] . "\nAdmin record: #" . $recordId . "\n\n" . implode("\n", $textRows) . "\n\nReview: " . $detailUrl,
    ];
}

function nacos_convention_attendee_confirmation_template(int $groupCount, string $reference): array
{
    $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $countLabel = $groupCount . ' attendee' . ($groupCount === 1 ? '' : 's');
    $safeReference = $safe($reference);
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#18251b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #dfe8df;border-radius:12px;overflow:hidden">'
        . '<tr><td style="padding:26px 28px 8px"><div style="color:#16803a;font-size:11px;font-weight:700;letter-spacing:1.5px">CONVENTION REGISTRATION</div><h1 style="margin:8px 0;font-size:23px;line-height:1.3;color:#132219">Payment confirmed</h1><p style="margin:0;color:#617064;font-size:14px;line-height:1.6">Your NACOS South East Convention group registration payment has been verified.</p></td></tr>'
        . '<tr><td style="padding:16px 28px 8px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f8f2;border:1px solid #d8e9d9;border-radius:8px"><tr><td style="padding:15px;color:#294530;font-size:13px"><strong>Registration:</strong> ' . $safe($countLabel) . '<br><strong>Payment reference:</strong> ' . $safeReference . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:14px 28px 22px"><h2 style="margin:0 0 10px;color:#132219;font-size:16px">Event details</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4ebe4;border-collapse:separate;border-radius:8px"><tr><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#536357;font-size:13px;font-weight:600;width:28%">Date</td><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#18251b;font-size:13px">20th–24th October, 2026</td></tr><tr><td style="padding:11px 12px;color:#536357;font-size:13px;font-weight:600;vertical-align:top">Venue</td><td style="padding:11px 12px;color:#18251b;font-size:13px;line-height:1.6">Ogbonnaya Onu Polytechnic Aba, Abia State, Nigeria</td></tr></table><p style="margin:18px 0 0;color:#536357;font-size:13px;line-height:1.6">This confirmation was sent to every attendee email provided for the group registration. Please keep this email for your records.</p></td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8faf8;border-top:1px solid #e8eee8;color:#718074;font-size:11px;line-height:1.6">NACOS South East Convention 2026<br>We look forward to seeing you.</td></tr>'
        . '</table></td></tr></table></body></html>';

    $text = "Payment confirmed — NACOS South East Convention 2026\n\n"
        . "Your group registration payment has been verified. Registration: " . $countLabel . ".\n"
        . "Payment reference: " . $reference . "\n\n"
        . "Event date: 20th–24th October, 2026\n"
        . "Venue: Ogbonnaya Onu Polytechnic Aba, Abia State, Nigeria\n\n"
        . "This confirmation was sent to every attendee email provided for the group registration. Please keep this email for your records.\n\n"
        . "NACOS South East Convention 2026";

    return nacos_add_email_banner([
        'subject' => 'Payment confirmed — NACOS South East Convention 2026',
        'htmlContent' => $html,
        'textContent' => $text,
    ]);
}

function nacos_hackathon_participant_confirmation_template(string $name, int $registrationId): array
{
    $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeName = $safe($name);
    $safeId = $safe((string) $registrationId);
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#18251b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #dfe8df;border-radius:12px;overflow:hidden">'
        . '<tr><td style="padding:26px 28px 10px"><div style="color:#16803a;font-size:11px;font-weight:700;letter-spacing:1.5px">HACKATHON REGISTRATION</div><h1 style="margin:8px 0;color:#132219;font-size:23px;line-height:1.3">Your registration is confirmed</h1><p style="margin:0;color:#536357;font-size:14px;line-height:1.7">Hello ' . $safeName . ', your NACOS South East Hackathon registration has been received. Our team will contact you with the next steps.</p></td></tr>'
        . '<tr><td style="padding:14px 28px 18px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f8f2;border:1px solid #d8e9d9;border-collapse:separate;border-radius:8px"><tr><td style="padding:12px 14px;border-bottom:1px solid #d8e9d9;color:#536357;font-size:13px;font-weight:600">Registration number</td><td align="right" style="padding:12px 14px;border-bottom:1px solid #d8e9d9;color:#18251b;font-size:13px;font-weight:700">#' . $safeId . '</td></tr><tr><td style="padding:12px 14px;border-bottom:1px solid #d8e9d9;color:#536357;font-size:13px;font-weight:600">Date</td><td align="right" style="padding:12px 14px;border-bottom:1px solid #d8e9d9;color:#18251b;font-size:13px">20th–24th October, 2026</td></tr><tr><td style="padding:12px 14px;color:#536357;font-size:13px;font-weight:600">Venue</td><td align="right" style="padding:12px 14px;color:#18251b;font-size:13px">Ogbonnaya Onu Polytechnic, Aba</td></tr></table></td></tr>'
        . '<tr><td style="padding:0 28px 22px"><p style="margin:0;color:#536357;font-size:13px;line-height:1.7">Please keep this email for your records. We look forward to seeing what you build.</p></td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8faf8;border-top:1px solid #e8eee8;color:#718074;font-size:11px;line-height:1.6">NACOS South East Hackathon 2026<br>Thank you for registering with us.</td></tr>'
        . '</table></td></tr></table></body></html>';
    $text = 'Hello ' . $name . ",\n\nYour NACOS South East Hackathon registration has been received. Our team will contact you with the next steps.\n\n"
        . 'Registration number: #' . $registrationId . "\nDate: 20th–24th October, 2026\nVenue: Ogbonnaya Onu Polytechnic, Aba\n\n"
        . "Please keep this email for your records. We look forward to seeing what you build.\n\nNACOS South East";
    return nacos_add_email_banner([
        'subject' => 'Your NACOS South East Hackathon registration is confirmed',
        'htmlContent' => $html,
        'textContent' => $text,
    ]);
}

function nacos_paid_applicant_confirmation_template(array $entry): array
{
    $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $types = [
        'convention' => ['name' => 'Convention registration', 'subject' => 'Your NACOS South East Convention registration is confirmed'],
        'sponsorship' => ['name' => 'Sponsorship contribution', 'subject' => 'Your NACOS South East sponsorship is confirmed'],
        'pageant' => ['name' => 'Mr & Miss NACOS application', 'subject' => 'Your Mr & Miss NACOS application is confirmed'],
    ];
    $type = (string) ($entry['type'] ?? 'convention');
    $typeInfo = $types[$type] ?? $types['convention'];
    $name = (string) ($entry['name'] ?? 'there');
    $reference = (string) ($entry['reference'] ?? '');
    $package = (string) ($entry['package'] ?? '');
    $amount = '₦' . number_format((int) ($entry['amount'] ?? 0) / 100, 2) . ' ' . (string) ($entry['currency'] ?? 'NGN');

    $details = '<tr><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#536357;font-size:13px;font-weight:600">Form</td><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#18251b;font-size:13px">' . $safe($typeInfo['name']) . '</td></tr>'
        . '<tr><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#536357;font-size:13px;font-weight:600">Package</td><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#18251b;font-size:13px">' . $safe($package) . '</td></tr>'
        . '<tr><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#536357;font-size:13px;font-weight:600">Amount paid</td><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#08732d;font-size:13px;font-weight:700">' . $safe($amount) . '</td></tr>'
        . '<tr><td style="padding:11px 12px;color:#536357;font-size:13px;font-weight:600">Payment reference</td><td style="padding:11px 12px;color:#18251b;font-size:13px;font-weight:700">' . $safe($reference) . '</td></tr>';

    $eventDetails = $type === 'convention'
        ? '<tr><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#536357;font-size:13px;font-weight:600;width:28%">Date</td><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#18251b;font-size:13px">20th–24th October, 2026</td></tr><tr><td style="padding:11px 12px;color:#536357;font-size:13px;font-weight:600;vertical-align:top">Venue</td><td style="padding:11px 12px;color:#18251b;font-size:13px;line-height:1.6">Ogbonnaya Onu Polytechnic Aba, Abia State, Nigeria</td></tr>'
        : '<tr><td style="padding:11px 12px;color:#18251b;font-size:13px;line-height:1.6">The NACOS South East team will contact you with the next steps.</td></tr>';

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#18251b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #dfe8df;border-radius:12px;overflow:hidden">'
        . '<tr><td style="padding:26px 28px 10px"><div style="color:#16803a;font-size:11px;font-weight:700;letter-spacing:1.5px">PAYMENT CONFIRMATION</div><h1 style="margin:8px 0;color:#132219;font-size:23px;line-height:1.3">Registration confirmed</h1><p style="margin:0;color:#536357;font-size:14px;line-height:1.7">Hello ' . $safe($name) . ', your payment was verified and your NACOS South East registration has been received.</p></td></tr>'
        . '<tr><td style="padding:14px 28px 18px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4ebe4;border-collapse:separate;border-radius:8px">' . $details . '</table></td></tr>'
        . '<tr><td style="padding:0 28px 22px"><h2 style="margin:0 0 10px;color:#132219;font-size:16px">' . ($type === 'convention' ? 'Event details' : 'What happens next') . '</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f8f2;border:1px solid #d8e9d9;border-collapse:separate;border-radius:8px">' . $eventDetails . '</table><p style="margin:18px 0 0;color:#536357;font-size:13px;line-height:1.6">Please keep this email and your payment reference for your records.</p></td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8faf8;border-top:1px solid #e8eee8;color:#718074;font-size:11px;line-height:1.6">NACOS South East<br>Thank you for registering with us.</td></tr>'
        . '</table></td></tr></table></body></html>';
    $text = 'Hello ' . $name . ",\n\nYour payment was verified and your NACOS South East registration has been received.\n\nForm: " . $typeInfo['name'] . "\nPackage: " . $package . "\nAmount paid: " . $amount . "\nPayment reference: " . $reference . "\n\n";
    $text .= $type === 'convention'
        ? "Event date: 20th–24th October, 2026\nVenue: Ogbonnaya Onu Polytechnic Aba, Abia State, Nigeria\n\n"
        : "The NACOS South East team will contact you with the next steps.\n\n";
    $text .= "Please keep this email and your payment reference for your records.\n\nNACOS South East";

    return nacos_add_email_banner([
        'subject' => $typeInfo['subject'],
        'htmlContent' => $html,
        'textContent' => $text,
    ]);
}

function nacos_base_url(): string
{
    $config = function_exists('nacos_config') ? nacos_config() : [];
    if (!empty($config['app_url'])) {
        return rtrim((string) $config['app_url'], '/');
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && (str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1'))) {
        return 'http://' . $host . '/nacossoutheast-main';
    }
    return 'https://nacosoutheast.org.ng';
}

function nacos_certificate_email_template(
    string $recipientName,
    array $certificates,
    ?string $customSubject = null,
    ?string $customBody = null,
    bool $hasAttachments = false,
    bool $isZipped = false,
    ?string $zipFilename = null
): array
{
    $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    
    $subject = trim((string) $customSubject) ?: 'Certificate of Participation - NACOS South East Convention 2026';
    $rawBody = $customBody;
    if ($rawBody === null || trim((string) $rawBody) === '') {
        $rawBody = "Dear [Name],\n\nWe extend our heartfelt appreciation to you for being a part of the NACOS South East Convention 2026, held from 20th - 24th October 2026 at Ogbonnaya Onu Polytechnic Aba, Abia State. Your active participation contributed immensely to the success of this landmark event.\n\nPlease find attached your Certificate of Participation, issued in recognition of your participation in the Convention. We encourage you to proudly display and share this certificate as a testament to your commitment to advancing the prospects of computing around the student community in Nigeria and world at large.\n\nThank you once again for your presence, enthusiasm, and support. We look forward to seeing you at future NACOS programs and initiatives.\n\nAccept the assurances of our regards.";
    }

    $processedBody = str_ireplace('[Name]', $recipientName, (string) $rawBody);
    $paragraphs = preg_split('/\r?\n\r?\n+/', trim($processedBody));
    $bodyHtml = '';
    foreach ($paragraphs as $para) {
        $para = trim((string) $para);
        if ($para === '') continue;
        $bodyHtml .= '<p style="margin:0 0 16px;line-height:1.7;color:#18251b;font-size:14px">' . nl2br($safe($para)) . '</p>';
    }

    $baseUrl = nacos_base_url();
    $logoUrl = 'https://www.nacosoutheast.org.ng/images/Logo-removebg-preview.png';
    $count = count($certificates);
    $certHtml = '';
    $certText = '';

    if ($count === 1) {
        $c = $certificates[0];
        $cName = $safe((string) ($c['name'] ?? $recipientName));
        $downloadUrl = $baseUrl . '/verify-certificate.php?token=' . rawurlencode((string) $c['token']) . '&download=pdf';
        $viewUrl = $baseUrl . '/verify-certificate.php?token=' . rawurlencode((string) $c['token']);
        
        $certHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0">'
            . '<tr><td align="center">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto"><tr>'
            . '<td align="center" style="background:#0b6623;border-radius:8px">'
            . '<a href="' . $safe($downloadUrl) . '" target="_blank" style="display:inline-block;padding:14px 30px;background:#0b6623;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;border-radius:8px;font-family:Arial,Helvetica,sans-serif">'
            . 'Download Certificate (PDF)'
            . '</a>'
            . '</td></tr></table>'
            . '</td></tr></table>';

        $certText = "\n\nDownload Certificate (PDF):\n" . $downloadUrl . "\nVerify certificate online:\n" . $viewUrl . "\n";
    } else {
        // Build attendee table rows for all bulk situations
        $certRows = '';
        $certTextList = [];
        foreach ($certificates as $idx => $c) {
            $num = $idx + 1;
            $cName = $safe((string) ($c['name'] ?? 'Attendee #' . $num));
            $cInst = !empty($c['institution']) ? '<div style="font-size:11px;color:#68766a">' . $safe((string) $c['institution']) . '</div>' : '';
            $dUrl = $baseUrl . '/verify-certificate.php?token=' . rawurlencode((string) $c['token']) . '&download=pdf';
            
            $certRows .= '<tr><td style="padding:10px 14px;border-bottom:1px solid #e7eee7;font-size:13px;color:#18251b;vertical-align:middle">'
                . '<strong>' . $num . '. ' . $cName . '</strong>' . $cInst
                . '</td><td align="right" style="padding:10px 14px;border-bottom:1px solid #e7eee7;vertical-align:middle;white-space:nowrap">'
                . '<a href="' . $safe($dUrl) . '" target="_blank" style="display:inline-block;padding:7px 14px;background:#0b6623;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700;border-radius:6px;font-family:Arial,Helvetica,sans-serif">Download PDF</a>'
                . '</td></tr>';
            $certTextList[] = $num . '. ' . ($c['name'] ?? 'Attendee') . ': ' . $dUrl;
        }

        if ($isZipped) {
            $zipLabel = $zipFilename ? $safe($zipFilename) : 'NACOS-Convention-Certificates.zip';
            $certHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0;border:1px solid #d5e4d5;border-radius:10px;background:#fafcfa;overflow:hidden">'
                . '<tr><td colspan="2" style="padding:12px 16px;background:#073b12;color:#ffffff;font-weight:700;font-size:13px;letter-spacing:0.5px">'
                . 'CONVENTION CERTIFICATES (' . $count . ' ATTENDEES - ZIPPED ATTACHMENT)'
                . '</td></tr>'
                . '<tr><td colspan="2" style="padding:16px;font-size:13px;line-height:1.7;color:#18251b">'
                . 'All <strong>' . $count . ' certificates</strong> have been packaged into the attached ZIP archive:<br>'
                . '<strong style="color:#0b6623">📦 ' . $zipLabel . '</strong><br><br>'
                . 'Please download and unzip the attached file to access all individual PDF certificates. You can also download any certificate directly using the links below:'
                . '</td></tr>'
                . $certRows
                . '</table>';

            $certText = "\n\nAll " . $count . " convention certificates are bundled in the attached ZIP archive (" . $zipLabel . ").\n\nIndividual Download Links:\n" . implode("\n", $certTextList) . "\n";
        } elseif ($hasAttachments) {
            $certHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0;border:1px solid #d5e4d5;border-radius:10px;background:#fafcfa;overflow:hidden">'
                . '<tr><td colspan="2" style="padding:12px 16px;background:#073b12;color:#ffffff;font-weight:700;font-size:13px;letter-spacing:0.5px">'
                . 'CONVENTION CERTIFICATES (' . $count . ' ATTENDEES)'
                . '</td></tr>'
                . '<tr><td colspan="2" style="padding:16px;font-size:13px;line-height:1.7;color:#18251b">'
                . 'The <strong>' . $count . ' certificates</strong> are attached to this email as individual PDF files. You can also download them directly using the links below:'
                . '</td></tr>'
                . $certRows
                . '</table>';

            $certText = "\n\n" . $count . " convention certificates are attached to this email as PDF files.\n\nIndividual Download Links:\n" . implode("\n", $certTextList) . "\n";
        } else {
            $certHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:24px 0;border:1px solid #d5e4d5;border-radius:10px;background:#fafcfa;overflow:hidden">'
                . '<tr><td colspan="2" style="padding:12px 16px;background:#073b12;color:#ffffff;font-weight:700;font-size:13px;letter-spacing:0.5px">'
                . 'CONVENTION CERTIFICATES (' . $count . ' ATTENDEES)'
                . '</td></tr>'
                . $certRows
                . '</table>';

            $certText = "\n\nConvention Certificates (" . $count . " Attendees):\n" . implode("\n", $certTextList) . "\n";
        }
    }

    $disclaimer = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:32px;border-top:1px dashed #d5ded5;padding-top:16px">'
        . '<tr><td align="center" style="font-family:Arial,Helvetica,sans-serif;font-size:9.5px;line-height:1.45;color:#c02b2b;text-align:center">'
        . '****************************************************************<br>'
        . 'This communication, including attachments, is for the exclusive use of addressee and MAY contain proprietary, confidential and/or privileged information. If you are not the intended recipient, any use, copying, disclosure, dissemination or distribution is strictly prohibited.<br>'
        . 'If you are not the intended recipient, please notify the sender immediately by return e-mail, delete this communication and destroy all copies.<br>'
        . '****************************************************************'
        . '</td></tr></table>';

    $html = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#18251b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;padding:10px 16px">'
        . '<tr><td align="center" style="padding:10px 0 24px">'
        . '<img src="' . $logoUrl . '" alt="NACOS South East" width="568" style="display:block;width:100%;max-width:568px;height:auto;border:0;outline:none;margin:0 auto">'
        . '</td></tr>'
        . '<tr><td style="padding:0">'
        . $bodyHtml
        . $certHtml
        . '</td></tr>'
        . '<tr><td style="padding:0">'
        . $disclaimer
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    $textContent = $processedBody . $certText . "\n\n---\nNACOS South East Zonal Secretariat\nNigeria Association of Computing Students (NACOS)\nhttps://www.nacosoutheast.org.ng";

    return [
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $textContent,
        'skip_banner' => true,
    ];
}

/**
 * Creates a valid ZIP archive binary string from an array of files.
 * Uses ZipArchive if available, or falls back to pure-PHP standard PKZIP format.
 *
 * @param array<array{name: string, content: string}> $files Array of files with name and raw binary content
 * @return string|null Raw binary content of the ZIP archive or null on failure
 */
function nacos_create_zip(array $files): ?string
{
    if (empty($files)) {
        return null;
    }

    if (class_exists('ZipArchive')) {
        $temp = tempnam(sys_get_temp_dir(), 'nacos_zip_');
        $zip = new ZipArchive();
        if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $file) {
                $name = $file['name'] ?? 'document.pdf';
                $content = $file['content'] ?? '';
                $zip->addFromString($name, $content);
            }
            $zip->close();
            $data = file_get_contents($temp);
            @unlink($temp);
            if ($data !== false && strlen($data) > 0) {
                return $data;
            }
        }
    }

    // Pure PHP standard PKZIP format builder fallback (requires only core zlib)
    $zipData = '';
    $centralDir = '';
    $offset = 0;
    $entryCount = 0;

    foreach ($files as $file) {
        $name = trim((string) ($file['name'] ?? 'document.pdf'));
        $content = (string) ($file['content'] ?? '');
        $time = time();
        $dosTime = (($time >> 1) & 0x1f) | ((($time >> 5) & 0x3f) << 5) | ((($time >> 11) & 0x1f) << 11);
        $dosDate = (($time >> 16) & 0x1f) | ((($time >> 21) & 0x0f) << 5) | (((($time >> 25) & 0x7f) - 1980) << 9);

        $uncompressedSize = strlen($content);
        $crc = crc32($content);
        $compressed = function_exists('gzdeflate') ? gzdeflate($content) : false;
        if ($compressed !== false && strlen($compressed) < $uncompressedSize) {
            $method = 8;
            $data = $compressed;
            $compressedSize = strlen($compressed);
        } else {
            $method = 0;
            $data = $content;
            $compressedSize = $uncompressedSize;
        }

        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dosTime, $dosDate, $crc, $compressedSize, $uncompressedSize, strlen($name), 0);
        $localEntry = $localHeader . $name . $data;
        $zipData .= $localEntry;

        $cdHeader = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dosTime, $dosDate, $crc, $compressedSize, $uncompressedSize, strlen($name), 0, 0, 0, 0, 32, $offset);
        $centralDir .= $cdHeader . $name;

        $offset += strlen($localEntry);
        $entryCount++;
    }

    $cdLen = strlen($centralDir);
    $endRecord = pack('VvvvvVVv', 0x06054b50, 0, 0, $entryCount, $entryCount, $cdLen, $offset, 0);

    return $zipData . $centralDir . $endRecord;
}

