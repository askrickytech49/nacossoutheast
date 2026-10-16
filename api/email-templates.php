<?php
declare(strict_types=1);

function nacos_add_email_banner(array $message): array
{
    $html = (string) ($message['htmlContent'] ?? '');
    $bannerUrl = 'https://www.nacosoutheast.org.ng/images/Banner.png';
    if ($html === '' || str_contains($html, $bannerUrl)) return $message;

    $banner = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#f1f5f1"><tr><td align="center" style="padding:0 12px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px"><tr><td style="padding:0"><img src="' . $bannerUrl . '" alt="NACOS South East" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;outline:none;text-decoration:none"></td></tr></table></td></tr></table>';
    if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
        $bodyTag = $match[0][0];
        $position = $match[0][1] + strlen($bodyTag);
        $html = substr($html, 0, $position) . $banner . substr($html, $position);
    } else {
        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f1">' . $banner . $html . '</body></html>';
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
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f1;font-family:Arial,Helvetica,sans-serif;color:#18251b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f1;padding:28px 12px"><tr><td align="center">'
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
    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f1;font-family:Arial,Helvetica,sans-serif;color:#18251b">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f1;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #dfe8df;border-radius:12px;overflow:hidden">'
        . '<tr><td style="padding:26px 28px 8px"><div style="color:#16803a;font-size:11px;font-weight:700;letter-spacing:1.5px">CONVENTION REGISTRATION</div><h1 style="margin:8px 0;font-size:23px;line-height:1.3;color:#132219">Payment confirmed</h1><p style="margin:0;color:#617064;font-size:14px;line-height:1.6">Your NACOS South East Convention group registration payment has been verified.</p></td></tr>'
        . '<tr><td style="padding:16px 28px 8px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f8f2;border:1px solid #d8e9d9;border-radius:8px"><tr><td style="padding:15px;color:#294530;font-size:13px"><strong>Registration:</strong> ' . $safe($countLabel) . '<br><strong>Payment reference:</strong> ' . $safeReference . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:14px 28px 22px"><h2 style="margin:0 0 10px;color:#132219;font-size:16px">Event details</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4ebe4;border-collapse:separate;border-radius:8px"><tr><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#536357;font-size:13px;font-weight:600;width:28%">Date</td><td style="padding:11px 12px;border-bottom:1px solid #e8eee8;color:#18251b;font-size:13px">20–24 October, 2026</td></tr><tr><td style="padding:11px 12px;color:#536357;font-size:13px;font-weight:600;vertical-align:top">Venue</td><td style="padding:11px 12px;color:#18251b;font-size:13px;line-height:1.6">Ogbonnaya Onu Polytechnic Aba, Abia State, Nigeria</td></tr></table><p style="margin:18px 0 0;color:#536357;font-size:13px;line-height:1.6">This confirmation was sent to every attendee email provided for the group registration. Please keep this email for your records.</p></td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8faf8;border-top:1px solid #e8eee8;color:#718074;font-size:11px;line-height:1.6">NACOS South East Convention 2026<br>We look forward to seeing you.</td></tr>'
        . '</table></td></tr></table></body></html>';

    $text = "Payment confirmed — NACOS South East Convention 2026\n\n"
        . "Your group registration payment has been verified. Registration: " . $countLabel . ".\n"
        . "Payment reference: " . $reference . "\n\n"
        . "Event date: 20–24 October, 2026\n"
        . "Venue: Ogbonnaya Onu Polytechnic Aba, Abia State, Nigeria\n\n"
        . "This confirmation was sent to every attendee email provided for the group registration. Please keep this email for your records.\n\n"
        . "NACOS South East Convention 2026";

    return [
        'subject' => 'Payment confirmed — NACOS South East Convention 2026',
        'htmlContent' => $html,
        'textContent' => $text,
    ];
}
