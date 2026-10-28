<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../admin/auth.php';
require_once __DIR__ . '/manual-payment-mail.php';
require_once __DIR__ . '/email-templates.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please sign in as admin.']);
    exit;
}

if (!admin_verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token expired. Please refresh the page.']);
    exit;
}

$registrationId = (int) ($_POST['registration_id'] ?? 0);
$recipientEmail = trim((string) ($_POST['recipient_email'] ?? ''));
$certId = (int) ($_POST['cert_id'] ?? 0);

if ($registrationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid registration ID.']);
    exit;
}

if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please provide a valid recipient email address.']);
    exit;
}

if (preg_match('/^bulk-[a-z0-9-]+@nacosoutheast\.org\.ng$/i', $recipientEmail)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Cannot send to placeholder address. Please enter a real recipient email address.']);
    exit;
}


$pdo = nacos_db();

try {
    $regStmt = $pdo->prepare("SELECT * FROM paid_registrations WHERE id = :id AND payment_status = 'paid' LIMIT 1");
    $regStmt->execute(['id' => $registrationId]);
    $reg = $regStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Paid registration record not found.']);
        exit;
    }

    if ($certId > 0) {
        $certStmt = $pdo->prepare("SELECT * FROM paid_certificate_verifications WHERE paid_registration_id = :reg_id AND id = :cert_id LIMIT 1");
        $certStmt->execute(['reg_id' => $registrationId, 'cert_id' => $certId]);
    } else {
        $certStmt = $pdo->prepare("SELECT * FROM paid_certificate_verifications WHERE paid_registration_id = :reg_id ORDER BY attendee_index ASC, id ASC");
        $certStmt->execute(['reg_id' => $registrationId]);
    }
    $certs = $certStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($certs)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No certificates found for this registration.']);
        exit;
    }

    $customSubject = null;
    $customBody = null;
    try {
        $setStmt = $pdo->query("SELECT email_subject, email_body FROM certificate_email_settings WHERE id = 1 LIMIT 1");
        if ($settingsRow = $setStmt->fetch(PDO::FETCH_ASSOC)) {
            $customSubject = (string) $settingsRow['email_subject'];
            $customBody = (string) $settingsRow['email_body'];
        }
    } catch (Throwable $ignore) {
        // Fallback to template defaults
    }

    $certList = [];
    foreach ($certs as $c) {
        $cName = function_exists('mb_strtoupper') ? mb_strtoupper((string) $c['attendee_name'], 'UTF-8') : strtoupper((string) $c['attendee_name']);
        $certList[] = [
            'name' => $cName,
            'institution' => (string) ($c['institution'] ?? ''),
            'token' => (string) $c['token'],
        ];
    }

    // Determine greeting name
    $greetingName = count($certList) === 1
        ? $certList[0]['name']
        : trim((string) $reg['applicant_name']);

    // Process optional PDF attachments BEFORE building the template
    $rawAttachments = $_POST['attachments'] ?? null;
    $attachments = [];
    if (!empty($rawAttachments)) {
        if (is_string($rawAttachments)) {
            $decoded = json_decode($rawAttachments, true);
            if (is_array($decoded)) {
                $rawAttachments = $decoded;
            }
        }
        if (is_array($rawAttachments)) {
            $totalSize = 0;
            foreach ($rawAttachments as $att) {
                if (!is_array($att)) continue;
                $name = trim((string) ($att['name'] ?? ''));
                $content = trim((string) ($att['content'] ?? ''));
                if ($name === '' || $content === '') continue;
                if (preg_match('/^data:[^;]+;base64,(.+)$/', $content, $matches)) {
                    $content = $matches[1];
                }
                if (!str_ends_with(strtolower($name), '.pdf')) {
                    $name .= '.pdf';
                }
                $size = strlen($content);
                $totalSize += $size;
                // Allow up to 35 MB total raw PDFs across the cohort for zipping
                if ($totalSize > 35 * 1024 * 1024) break;

                $attachments[] = [
                    'name' => $name,
                    'content' => $content,
                ];
            }
        }
    }

    $isZipped = false;
    $zipFilename = null;
    $finalAttachments = [];

    // If more than 2 certificates, zip all certificates into a single archive
    if (count($attachments) > 2) {
        $filesToZip = [];
        foreach ($attachments as $att) {
            $decodedBin = base64_decode((string) $att['content'], true);
            if ($decodedBin !== false && strlen($decodedBin) > 0) {
                $filesToZip[] = [
                    'name' => $att['name'],
                    'content' => $decodedBin,
                ];
            }
        }

        if (!empty($filesToZip)) {
            $zipBinary = nacos_create_zip($filesToZip);
            if ($zipBinary !== null && strlen($zipBinary) > 0) {
                $cleanRegName = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($reg['applicant_name'] ?? 'Convention'));
                $cleanRegName = trim((string) $cleanRegName, '-_') ?: 'Convention';
                $zipFilename = 'NACOS-Certificates-' . $cleanRegName . '.zip';
                $finalAttachments = [
                    [
                        'name' => $zipFilename,
                        'content' => base64_encode($zipBinary),
                    ]
                ];
                $isZipped = true;
            } else {
                $finalAttachments = $attachments;
            }
        } else {
            $finalAttachments = $attachments;
        }
    } else {
        $finalAttachments = $attachments;
    }

    $hasAttachments = !empty($finalAttachments);
    $template = nacos_certificate_email_template($greetingName, $certList, $customSubject, $customBody, $hasAttachments, $isZipped, $zipFilename);

    $emailPayload = [
        'to' => [['email' => $recipientEmail, 'name' => $greetingName]],
        'subject' => $template['subject'],
        'htmlContent' => $template['htmlContent'],
        'textContent' => $template['textContent'],
        'skip_banner' => true,
    ];
    if (!empty($finalAttachments)) {
        $emailPayload['attachment'] = $finalAttachments;
    }

    $sent = nacos_manual_send_email($emailPayload);

    if (!$sent) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'Email delivery failed. Please check Brevo API credentials in configuration.']);
        exit;
    }

    // Increment email_sent_count and record last_emailed_at timestamp
    $certIds = array_values(array_filter(array_map(static fn($c) => (int) ($c['id'] ?? 0), $certs)));
    $updatedCerts = [];
    if (!empty($certIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($certIds), '?'));
        $updateStmt = $pdo->prepare("
            UPDATE paid_certificate_verifications
            SET email_sent_count = email_sent_count + 1,
                last_emailed_at = NOW()
            WHERE id IN ($inPlaceholders)
        ");
        $updateStmt->execute($certIds);

        $fetchStmt = $pdo->prepare("SELECT id, email_sent_count, last_emailed_at FROM paid_certificate_verifications WHERE id IN ($inPlaceholders)");
        $fetchStmt->execute($certIds);
        $updatedCerts = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $countSent = count($certList);
    $attachCount = count($attachments);
    if ($countSent === 1) {
        $msg = $hasAttachments
            ? 'Certificate successfully emailed with PDF attached to ' . $recipientEmail . '.'
            : 'Certificate successfully emailed to ' . $recipientEmail . '.';
    } elseif ($isZipped) {
        $msg = 'All ' . $attachCount . ' certificates successfully bundled into a single ZIP file (' . $zipFilename . ') and emailed to ' . $recipientEmail . '.';
    } else {
        $msg = $hasAttachments
            ? 'All ' . $attachCount . ' certificates successfully emailed with PDFs attached to ' . $recipientEmail . '.'
            : 'All ' . $countSent . ' certificates successfully emailed to ' . $recipientEmail . '.';
    }

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'attached' => $hasAttachments,
        'updated_certs' => $updatedCerts,
    ]);
} catch (Throwable $e) {
    error_log('Error sending certificate email: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred while processing the certificate email request.']);
}
