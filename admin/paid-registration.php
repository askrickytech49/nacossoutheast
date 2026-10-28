<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: (int) ($_GET['id'] ?? 0);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid paid form ID.');
}

$pdo = nacos_db();
$query = $pdo->prepare('SELECT * FROM paid_registrations WHERE id = :id LIMIT 1');
$query->execute(['id' => $id]);
$entry = $query->fetch(PDO::FETCH_ASSOC);
if (!$entry) {
    http_response_code(404);
    exit('Paid form not found.');
}

$data = json_decode((string) $entry['submission_data'], true);
$data = is_array($data) ? $data : [];
$saved = false;
$error = '';
$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!admin_verify_csrf()) {
        $error = 'Your session expired. Refresh and try again.';
    } else {
        $notes = trim((string) ($_POST['admin_notes'] ?? ''));
        if (strlen($notes) > 40000) {
            $error = 'Notes are too long.';
        } else {
            $update = $pdo->prepare('UPDATE paid_registrations SET admin_notes = :notes WHERE id = :id');
            $update->execute(['notes' => $notes === '' ? null : $notes, 'id' => $id]);
            $entry['admin_notes'] = $notes;
            $saved = true;
            $notice = 'Notes saved successfully.';
        }
    }
}

$certificateRows = [];
$certificateError = '';
$isBulk = !empty($data['attendees']) && is_array($data['attendees']) && count($data['attendees']) > 1;

$isRealEmail = static function(?string $e): bool {
    if ($e === null) return false;
    $e = trim($e);
    if ($e === '' || $e === '—' || $e === 'N/A' || $e === 'none') return false;
    if (preg_match('/^bulk-[a-z0-9-]+@nacosoutheast\.org\.ng$/i', $e)) return false;
    return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
};

$primaryEmailValue = $isRealEmail($entry['applicant_email'] ?? '') ? (string) $entry['applicant_email'] : '';

if ((string) $entry['form_type'] !== 'sponsorship') {
    try {
        $certificatePeople = [];
        if (isset($data['attendees']) && is_array($data['attendees']) && $data['attendees']) {
            foreach (array_values($data['attendees']) as $index => $attendee) {
                if (!is_array($attendee)) continue;
                $attendeeName = trim((string) ($attendee['name'] ?? ''));
                if ($attendeeName === '') continue;
                $attendeeName = function_exists('mb_strtoupper') ? mb_strtoupper($attendeeName, 'UTF-8') : strtoupper($attendeeName);
                $certificatePeople[] = [$index, $attendeeName];
            }
        } else {
            $applicantName = trim((string) $entry['applicant_name']);
            $applicantName = function_exists('mb_strtoupper') ? mb_strtoupper($applicantName, 'UTF-8') : strtoupper($applicantName);
            $certificatePeople[] = [0, $applicantName];
        }

        $addCertificate = $pdo->prepare(
            'INSERT IGNORE INTO paid_certificate_verifications
             (paid_registration_id, attendee_index, attendee_name, institution, token)
             VALUES (:registration_id, :attendee_index, :attendee_name, :institution, :token)'
        );
        foreach ($certificatePeople as [$attendeeIndex, $attendeeName]) {
            $addCertificate->execute([
                'registration_id' => $id,
                'attendee_index' => $attendeeIndex,
                'attendee_name' => $attendeeName,
                'institution' => trim((string) ($data['institution'] ?? '')) ?: null,
                'token' => bin2hex(random_bytes(32)),
            ]);
        }

        $certificateQuery = $pdo->prepare(
            'SELECT id, attendee_index, attendee_name, institution, token, email_sent_count, last_emailed_at FROM paid_certificate_verifications
             WHERE paid_registration_id = :id ORDER BY attendee_index ASC'
        );
        $certificateQuery->execute(['id' => $id]);
        $certificateRows = $certificateQuery->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $certificateException) {
        error_log('Certificate QR setup failed: ' . $certificateException->getMessage());
        $certificateError = 'Certificate QR codes are not available yet. Run api/migrate-certificate-verification.sql on this database, then reload this page.';
    }
}

admin_page_start('Paid form #' . $id);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if (!preg_match('/\A[a-z0-9.-]+(?::[0-9]{1,5})?\z/', $host)) $host = 'nacosoutheast.org.ng';
$isLocalHost = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
$origin = ($isLocalHost ? 'http://' : 'https://') . $host;
$sitePath = rtrim(dirname(rtrim(admin_base_path(), '/')), '/') . '/';
?>

<style>
button, .button { font-family: inherit; font-size: 13px; font-weight: 650; }
.detail-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,.8fr);gap:18px}
.bulk-banner{background:linear-gradient(135deg,#073b12 0%,#0b6623 100%);color:#fff;border-radius:14px;padding:22px 24px;margin-bottom:20px;box-shadow:0 8px 24px rgba(7,59,18,.15)}
.bulk-banner-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
.bulk-banner-title{font-size:20px;font-weight:700;margin:0 0 4px}
.bulk-banner-inst{color:#bdf0c6;font-size:14px;font-weight:500}
.bulk-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-top:18px;padding-top:16px;border-top:1px solid rgba(255,255,255,.18)}
.bulk-stat small{display:block;color:#a3e4af;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
.bulk-stat strong{display:block;font-size:18px;font-weight:700;color:#fff;margin-top:2px}
.attendees-panel{background:#fff;border:1px solid #e0e7df;border-radius:12px;margin:18px 0;overflow:hidden;box-shadow:0 3px 12px rgba(18,45,22,.035)}
.attendees-panel-header{padding:18px 20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;border-bottom:1px solid #edf0ec}
.panel-title{font-size:17px;font-weight:700;color:#073b12;margin:0}
.panel-subtitle{font-size:12.5px;color:#5c6e5e;margin-top:3px}
.panel-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.search-attendee-input{min-height:38px;padding:6px 12px;border:1px solid #cad5ca;border-radius:8px;font-size:12.5px;width:240px}
.view-tabs{display:inline-flex;background:#edf2ed;padding:3px;border-radius:8px}
.view-tab{padding:6px 12px;border-radius:6px;font-size:12px;font-weight:650;color:#3b503e;border:0;background:transparent;cursor:pointer}
.view-tab.active{background:#fff;color:#073b12;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.batch-btn{background:#e8f5ec;color:#0b6623;border:1px solid #bfe3c7;padding:7px 12px;border-radius:8px;font-size:12px;font-weight:650;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.batch-btn:hover{background:#0b6623;color:#fff}
.attendee-dir-table{width:100%;border-collapse:collapse}
.attendee-dir-table th{background:#f8faf8;color:#475b4b;font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;padding:12px 14px;border-bottom:1px solid #edf0ec;text-align:left}
.attendee-dir-table td{padding:12px 14px;border-bottom:1px solid #edf0ec;font-size:13px;vertical-align:middle}
.attendee-dir-table tbody tr:hover{background:#fbfdfb}
.att-idx{font-weight:700;color:#0b6623;width:34px;text-align:center}
.att-name{font-weight:700;color:#0f2413;font-size:13.5px}
.att-sub{font-size:11.5px;color:#617263;margin-top:2px}
.att-qr-thumb{width:46px;height:46px;border:1px solid #d8e5d9;border-radius:6px;padding:2px;background:#fff;cursor:pointer;display:block;transition:transform 150ms ease}
.att-qr-thumb:hover{transform:scale(1.15);box-shadow:0 4px 12px rgba(0,0,0,.12)}
.att-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.att-btn-pdf{background:#0b6623;color:#fff;border:0;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:650;text-decoration:none;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.att-btn-pdf:hover{background:#073b12}
.att-btn-qr{background:#fff;color:#184a21;border:1px solid #d7e0d6;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.att-btn-qr:hover{background:#eef6ed}
.qr-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px;padding:20px}
.qr-card{background:#fff;border:1px solid #e0e7df;border-radius:12px;padding:18px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:12px;box-shadow:0 2px 8px rgba(18,45,22,.035)}
.qr-card-name{font-weight:750;color:#073b12;font-size:14px;line-height:1.3}
.qr-card-inst{font-size:11.5px;color:#617263}
.qr-card canvas{width:130px;height:130px;border:1px solid #dce8dd;border-radius:8px;padding:4px;background:#fff}
.qr-card-actions{display:flex;flex-direction:column;gap:8px;width:100%;margin-top:auto}
.qr-card-actions a,.qr-card-actions button{width:100%;justify-content:center;min-height:36px}
@media(max-width:800px){.detail-grid{grid-template-columns:1fr}.panel-toolbar{width:100%;justify-content:space-between}.search-attendee-input{width:100%}}
</style>

<div class="subhead">
  <div>
    <a class="button button-light" href="payments.php" style="width:auto;min-height:36px;padding:8px 12px">Back to all paid forms</a>
    <h1 style="margin-top:10px"><?= admin_h((string) $entry['applicant_name']) ?></h1>
    <p class="muted"><?= admin_h(ucwords((string) $entry['form_type'])) ?> · submitted <?= admin_h(date('M j, Y · g:i a', strtotime((string) $entry['created_at']))) ?></p>
  </div>
  <span class="badge badge-accepted">Verified paid</span>
</div>

<?php if ($notice !== ''): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>
<?php if ($certificateError !== ''): ?><div class="notice error"><?= admin_h($certificateError) ?></div><?php endif; ?>

<?php if ($isBulk): ?>
<!-- Delegation / Bulk Summary Banner -->
<div class="bulk-banner">
  <div class="bulk-banner-top">
    <div>
      <span class="badge" style="background:rgba(255,255,255,.2);color:#fff;margin-bottom:8px">Convention Chapter Delegation</span>
      <h2 class="bulk-banner-title"><?= admin_h((string) ($data['institution'] ?? 'Institutional Group Registration')) ?></h2>
      <div class="bulk-banner-inst">Lead Representative: <strong><?= admin_h((string) $entry['applicant_name']) ?></strong> (<?= $primaryEmailValue !== '' ? admin_h($primaryEmailValue) : 'No primary email registered' ?><?= !empty($entry['phone']) && $entry['phone'] !== '—' ? ' · ' . admin_h((string) $entry['phone']) : '' ?>)</div>
    </div>
    <div style="text-align:right">
      <span class="badge" style="background:#fff;color:#073b12;font-size:12px">Reference: <?= admin_h((string) $entry['payment_reference']) ?></span>
    </div>
  </div>
  <div class="bulk-stats">
    <div class="bulk-stat">
      <small>Total Attendees</small>
      <strong><?= count($certificateRows) ?> Students</strong>
    </div>
    <div class="bulk-stat">
      <small>Ticket Package</small>
      <strong><?= admin_h((string) $entry['package_name']) ?></strong>
    </div>
    <div class="bulk-stat">
      <small>Total Amount Paid</small>
      <strong>₦<?= number_format(((int) $entry['amount_kobo']) / 100, 2) ?></strong>
    </div>
    <div class="bulk-stat">
      <small>Payment Channel</small>
      <strong><?= strtoupper(admin_h((string) ($entry['payment_channel'] ?: 'offline'))) ?></strong>
    </div>
    <div class="bulk-stat">
      <small>Certificates Emailed</small>
      <?php
      $totalSentAcross = array_sum(array_map(static fn($c) => (int) ($c['email_sent_count'] ?? 0), $certificateRows));
      ?>
      <strong id="bulk-total-emailed-count"><?= $totalSentAcross ?> Sent</strong>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($certificateRows): ?>
<!-- Attendees & Certificates Directory Section -->
<section class="attendees-panel">
  <div class="attendees-panel-header">
    <div>
      <h2 class="panel-title"><?= $isBulk ? 'Delegation Attendees & Certificates (' . count($certificateRows) . ')' : 'Attendee Certificate & QR Code' ?></h2>
      <div class="panel-subtitle">Instant certificate verification and downloadable QR codes for all attendees in this record.</div>
    </div>
    <?php if ($isBulk): ?>
      <div class="panel-toolbar">
        <input type="search" id="filter-attendees-input" class="search-attendee-input" placeholder="Filter student name, email...">
        <div class="view-tabs">
          <button type="button" class="view-tab" id="tab-table-view">Table View</button>
          <button type="button" class="view-tab active" id="tab-cards-view">QR Cards</button>
        </div>
        <button type="button" id="batch-download-qrs-btn" class="batch-btn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/></svg>
          Download All QRs
        </button>
      </div>
    <?php endif; ?>
  </div>

  <!-- Collapsible Section Email Box -->
  <div id="bulk-email-box" style="display:none;background:#f4f9f4;border-bottom:1px solid #c8dec8;padding:16px 20px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px;flex-wrap:wrap">
      <div>
        <strong style="color:#073b12;font-size:14px"><?= $isBulk ? 'Email All Bulk Certificates at Once' : 'Email Certificate' ?></strong>
        <div style="font-size:12.5px;color:#536357;margin-top:2px"><?= $isBulk ? 'Enter the recipient email below. All certificates for this bulk delegation will be included in one single email.' : 'Enter the recipient email below to send this certificate.' ?></div>
      </div>
      <button type="button" id="close-bulk-email-box-btn" style="background:transparent;border:0;color:#68766a;cursor:pointer;font-size:20px;line-height:1;padding:4px 8px" title="Close">&times;</button>
    </div>
    <form id="bulk-email-form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <input type="email" id="bulk-email-input" value="<?= admin_h($primaryEmailValue) ?>" placeholder="<?= $primaryEmailValue !== '' ? 'Recipient email address' : 'Enter recipient email address (none registered)' ?>" required style="flex:1;min-width:240px;padding:9px 12px;border:1px solid #cad5ca;border-radius:8px">
      <input type="hidden" id="bulk-email-cert-id" value="0">
      <button type="submit" id="bulk-email-send-btn" style="background:#0b6623;color:#fff;border:0;padding:9px 18px;border-radius:8px;font-weight:650;display:inline-flex;align-items:center;gap:6px;cursor:pointer">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        Send Certificate<?= $isBulk ? 's' : '' ?>
      </button>
    </form>
    <div id="bulk-email-status" style="margin-top:10px;font-size:13px;display:none"></div>
  </div>

  <!-- Table View Container -->
  <div id="attendees-table-view" class="table-scroll" style="display:none">
    <table class="attendee-dir-table" id="attendees-dir-table">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Attendee Details</th>
          <th>Email & Phone</th>
          <th style="width:130px;text-align:center">Email History</th>
          <th style="width:80px;text-align:center">QR Code</th>
          <th style="text-align:right">Certificate Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($certificateRows as $qrIndex => $cert): ?>
          <?php
          $verifyUrl = $origin . $sitePath . 'verify-certificate.php?token=' . rawurlencode((string) $cert['token']);
          $pdfUrl = $verifyUrl . '&download=pdf';
          $canvasId = 'cert-qr-tbl-' . $qrIndex;
          $filename = 'nacos-qr-' . (int) $id . '-' . ($qrIndex + 1) . '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower((string) $cert['attendee_name'])) . '.png';
          $rawAttEmail = trim((string) ($data['attendees'][$qrIndex]['email'] ?? ''));
          $hasRealEmail = $isRealEmail($rawAttEmail);
          $attEmail = $hasRealEmail ? $rawAttEmail : '';
          $rawPhone = trim((string) ($data['attendees'][$qrIndex]['phone'] ?? ''));
          $attPhone = ($rawPhone !== '' && $rawPhone !== '—' && $rawPhone !== 'N/A') ? $rawPhone : '—';
          $searchData = strtolower($cert['attendee_name'] . ' ' . $attEmail . ' ' . $attPhone . ' ' . ($cert['institution'] ?? ''));
          $sentCount = (int) ($cert['email_sent_count'] ?? 0);
          ?>
          <tr class="attendee-row" data-search="<?= admin_h($searchData) ?>">
            <td class="att-idx"><?= $qrIndex + 1 ?></td>
            <td>
              <div class="att-name"><?= admin_h((string) $cert['attendee_name']) ?></div>
              <?php if (!empty($cert['institution'])): ?>
                <div class="att-sub"><?= admin_h((string) $cert['institution']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($attEmail !== ''): ?>
                <div><a href="mailto:<?= admin_h($attEmail) ?>" style="color:#0b6623;text-decoration:none"><?= admin_h($attEmail) ?></a></div>
              <?php else: ?>
                <div><span class="badge" style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 7px;border-radius:6px;font-weight:600">No email registered</span></div>
              <?php endif; ?>
              <div class="att-sub"><?= admin_h((string) $attPhone) ?></div>
            </td>
            <td style="text-align:center">
              <div id="cert-email-badge-tbl-<?= (int) $cert['id'] ?>">
                <?php if ($sentCount > 0): ?>
                  <span class="badge" style="background:#e8f5ec;color:#0b6623;font-size:11px;padding:3px 8px;border-radius:10px;font-weight:650;display:inline-flex;align-items:center;gap:4px">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Sent <?= $sentCount ?> <?= $sentCount === 1 ? 'time' : 'times' ?>
                  </span>
                  <?php if (!empty($cert['last_emailed_at'])): ?>
                    <div style="font-size:10.5px;color:#617263;margin-top:3px"><?= admin_h(date('M j, Y · g:ia', strtotime((string) $cert['last_emailed_at']))) ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge" style="background:#f3f4f6;color:#6b7280;font-size:11px;padding:3px 8px;border-radius:10px;font-weight:500">
                    Not sent yet
                  </span>
                <?php endif; ?>
              </div>
            </td>
            <td style="text-align:center">
              <canvas id="<?= $canvasId ?>" class="att-qr-thumb qr-canvas-item" data-value="<?= admin_h($verifyUrl) ?>" width="120" height="120" title="Click to download QR code" data-filename="<?= admin_h($filename) ?>" style="margin:auto"></canvas>
            </td>
            <td style="text-align:right">
              <div class="att-actions" style="justify-content:flex-end">
                <a class="att-btn-pdf" href="<?= admin_h($pdfUrl) ?>" target="_blank" rel="noopener">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                  Certificate PDF
                </a>
                <button type="button" class="att-btn-qr certificate-qr-download" data-target="<?= $canvasId ?>" data-filename="<?= admin_h($filename) ?>" title="Download PNG QR code">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/></svg>
                  QR Code
                </button>
                <button type="button" class="att-btn-qr individual-email-cert-btn" data-cert-id="<?= (int) $cert['id'] ?>" data-name="<?= admin_h((string) $cert['attendee_name']) ?>" data-email="<?= admin_h($attEmail) ?>" title="Email certificate to this attendee">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                  Email
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Cards View Container (Hidden by default for bulk) -->
  <div id="attendees-cards-view" class="qr-cards-grid">
    <?php foreach ($certificateRows as $qrIndex => $cert): ?>
      <?php
      $verifyUrl = $origin . $sitePath . 'verify-certificate.php?token=' . rawurlencode((string) $cert['token']);
      $pdfUrl = $verifyUrl . '&download=pdf';
      $canvasCardId = 'cert-qr-card-' . $qrIndex;
      $filename = 'nacos-qr-' . (int) $id . '-' . ($qrIndex + 1) . '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower((string) $cert['attendee_name'])) . '.png';
      $searchData = strtolower($cert['attendee_name'] . ' ' . ($data['attendees'][$qrIndex]['email'] ?? '') . ' ' . ($cert['institution'] ?? ''));
      $sentCount = (int) ($cert['email_sent_count'] ?? 0);
      ?>
      <article class="qr-card" data-search="<?= admin_h($searchData) ?>">
        <div>
          <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:6px;flex-wrap:wrap">
            <span class="badge" style="background:#eef6ed;color:#0b6623;font-size:11px">Attendee #<?= $qrIndex + 1 ?></span>
            <div id="cert-email-badge-card-<?= (int) $cert['id'] ?>" style="display:inline-block">
              <?php if ($sentCount > 0): ?>
                <span class="badge" style="background:#e8f5ec;color:#0b6623;font-size:11px;padding:2px 7px;border-radius:10px;font-weight:650;display:inline-flex;align-items:center;gap:3px" title="Sent <?= $sentCount ?> <?= $sentCount === 1 ? 'time' : 'times' ?><?= !empty($cert['last_emailed_at']) ? ' (last: ' . date('M j, Y · g:ia', strtotime((string) $cert['last_emailed_at'])) . ')' : '' ?>">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Sent <?= $sentCount ?>x
                </span>
              <?php else: ?>
                <span class="badge" style="background:#f3f4f6;color:#6b7280;font-size:11px;padding:2px 7px;border-radius:10px;font-weight:500">
                  Not sent yet
                </span>
              <?php endif; ?>
            </div>
          </div>
          <div class="qr-card-name"><?= admin_h((string) $cert['attendee_name']) ?></div>
          <?php if (!empty($cert['institution'])): ?>
            <div class="qr-card-inst"><?= admin_h((string) $cert['institution']) ?></div>
          <?php endif; ?>
          <?php
          $cardRawEmail = trim((string) ($data['attendees'][$qrIndex]['email'] ?? ''));
          $cardEmail = $isRealEmail($cardRawEmail) ? $cardRawEmail : '';
          ?>
          <?php if ($cardEmail !== ''): ?>
            <div style="font-size:11.5px;color:#4b6350;margin-top:2px;word-break:break-all"><?= admin_h($cardEmail) ?></div>
          <?php else: ?>
            <div style="margin-top:3px"><span class="badge" style="background:#fef3c7;color:#92400e;font-size:10.5px;padding:1px 6px;border-radius:4px">No email registered</span></div>
          <?php endif; ?>
        </div>
        <canvas id="<?= $canvasCardId ?>" class="qr-canvas-item" data-value="<?= admin_h($verifyUrl) ?>" width="180" height="180"></canvas>
        <div class="qr-card-actions">
          <a class="att-btn-pdf" href="<?= admin_h($pdfUrl) ?>" target="_blank" rel="noopener">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Download Certificate PDF
          </a>
          <button type="button" class="att-btn-qr certificate-qr-download" data-target="<?= $canvasCardId ?>" data-filename="<?= admin_h($filename) ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/></svg>
            Download QR Code
          </button>
          <button type="button" class="att-btn-qr individual-email-cert-btn" data-cert-id="<?= (int) $cert['id'] ?>" data-name="<?= admin_h((string) $cert['attendee_name']) ?>" data-email="<?= admin_h($cardEmail) ?>" title="Email certificate to this attendee">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            Email Certificate
          </button>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Submission Details and Internal Notes -->
<div class="detail-grid">
  <section class="panel panel-pad">
    <h2 style="margin-top:0">Registration Details</h2>
    <dl class="detail-list">
      <?php
      $fields = [
          'Email' => $primaryEmailValue !== '' ? $primaryEmailValue : '— (No email registered)',
          'Phone' => $entry['phone'],
          'Category' => $entry['category'] ?: '—',
          'Package' => $entry['package_name'],
          'Amount paid' => '₦' . number_format(((int) $entry['amount_kobo']) / 100, 2) . ' ' . $entry['currency'],
          'Payment status' => ucfirst((string) $entry['payment_status']),
          'Payment reference' => $entry['payment_reference'],
          'Payment channel' => strtoupper((string) ($entry['payment_channel'] ?: '—')),
          'Paid at' => $entry['payment_paid_at'] ?: $entry['created_at'],
      ];
      if (!empty($certificateRows)) {
          $totalSentAcross = array_sum(array_map(static fn($c) => (int) ($c['email_sent_count'] ?? 0), $certificateRows));
          $fields['Certificate emails'] = $totalSentAcross > 0
              ? $totalSentAcross . ' sent'
              : 'Not sent yet';
      }
      if (!empty($data['institution'])) {
          $fields['Institution / School'] = $data['institution'];
      }
      foreach ($data as $key => $value) {
          if (in_array($key, ['photo_mime', 'photo_size', 'attendees', 'students', 'institution', 'bulk_registration', 'admin_recorded', 'total_attendees'], true)) continue;
          $label = ucwords(str_replace('_', ' ', (string) $key));
          if ($key === 'fee_per_student' || $key === 'total_amount') {
              $value = '₦' . number_format((float) $value);
          } elseif (is_array($value)) {
              $value = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          }
          $fields[$label] = is_scalar($value) ? (string) $value : '';
      }
      foreach ($fields as $label => $value) {
          echo '<dt>' . admin_h((string) $label) . '</dt><dd>' . nl2br(admin_h((string) $value)) . '</dd>';
      }
      ?>
    </dl>

    <?php if (!empty($entry['photo_path'])): ?>
      <h3>Profile photograph</h3>
      <a href="photo.php?id=<?= (int) $id ?>" target="_blank" rel="noopener">
        <img src="photo.php?id=<?= (int) $id ?>" alt="Applicant profile photo" style="display:block;max-width:240px;max-height:300px;object-fit:contain;border:1px solid #dce5dc;border-radius:10px">
      </a>
      <p class="muted">Click the photo to open full size.</p>
    <?php endif; ?>
  </section>

  <aside class="panel panel-pad">
    <h2 style="margin-top:0">Internal Admin Notes</h2>
    <form method="post" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?= admin_h(admin_csrf_token()) ?>">
      <label>Notes
        <textarea name="admin_notes" maxlength="10000" placeholder="Add a private follow-up note about this registration"><?= admin_h((string) ($entry['admin_notes'] ?? '')) ?></textarea>
      </label>
      <button type="submit">Save notes</button>
      <a class="button button-light" href="mailto:<?= rawurlencode((string) $entry['applicant_email']) ?>">Email applicant</a>
      <a class="button button-light" id="toggle-notes-email-cert-btn" href="javascript:void(0)">Email <?= $isBulk ? 'all certificates' : 'certificate' ?></a>
    </form>

    <!-- Inline email certificate card under Email applicant -->
    <div id="notes-email-cert-box" style="display:none;margin-top:14px;background:#f7faf7;border:1px solid #cfe2cf;border-radius:8px;padding:14px">
      <div style="font-size:12px;font-weight:700;color:#073b12;margin-bottom:4px"><?= $isBulk ? 'Email All Bulk Certificates at Once' : 'Email Certificate' ?></div>
      <div style="font-size:11.5px;color:#536357;margin-bottom:10px">Enter the email where <?= $isBulk ? 'all bulk certificates' : 'the certificate' ?> should be sent:</div>
      <form id="notes-email-form" style="display:grid;gap:8px">
        <input type="email" id="notes-email-input" value="<?= admin_h($primaryEmailValue) ?>" placeholder="<?= $primaryEmailValue !== '' ? 'Recipient email address' : 'Enter recipient email address' ?>" required style="padding:8px 10px;font-size:12px;border:1px solid #cad5ca;border-radius:6px">
        <button type="submit" id="notes-email-send-btn" style="background:#0b6623;color:#fff;border:0;padding:8px 12px;border-radius:6px;font-size:12px;font-weight:650;cursor:pointer">
          Send Certificate<?= $isBulk ? 's' : '' ?>
        </button>
      </form>
      <div id="notes-email-status" style="margin-top:8px;font-size:12px;display:none"></div>
    </div>
  </aside>
</div>

<script src="../assets/js/qrious.min.js" referrerpolicy="no-referrer"></script>
<script>
(function(){
  // Initialize all QRious canvases
  if (typeof QRious !== "undefined") {
    document.querySelectorAll(".qr-canvas-item").forEach(canvas => {
      new QRious({
        element: canvas,
        value: canvas.dataset.value,
        size: 360,
        level: "H",
        background: "#ffffff",
        foreground: "#073b12"
      });
    });
  }

  // Download individual QR
  document.querySelectorAll(".certificate-qr-download, .att-qr-thumb").forEach(btn => {
    btn.addEventListener("click", () => {
      const targetId = btn.dataset.target || btn.id;
      const filename = btn.dataset.filename || "nacos-qr.png";
      const canvas = document.getElementById(targetId);
      if (!canvas) return;
      const link = document.createElement("a");
      link.download = filename;
      link.href = canvas.toDataURL("image/png");
      link.click();
    });
  });

  // Switch between Table and Cards view
  const tabTable = document.getElementById("tab-table-view");
  const tabCards = document.getElementById("tab-cards-view");
  const viewTable = document.getElementById("attendees-table-view");
  const viewCards = document.getElementById("attendees-cards-view");

  if (tabTable && tabCards) {
    tabTable.addEventListener("click", () => {
      tabTable.classList.add("active");
      tabCards.classList.remove("active");
      viewTable.style.display = "";
      viewCards.style.display = "none";
    });
    tabCards.addEventListener("click", () => {
      tabCards.classList.add("active");
      tabTable.classList.remove("active");
      viewCards.style.display = "grid";
      viewTable.style.display = "none";
    });
  }

  // Filter attendees in real-time
  const filterInput = document.getElementById("filter-attendees-input");
  if (filterInput) {
    filterInput.addEventListener("input", () => {
      const query = filterInput.value.toLowerCase().trim();
      document.querySelectorAll(".attendee-row, .qr-card").forEach(el => {
        const search = el.dataset.search || "";
        el.style.display = search.includes(query) ? "" : "none";
      });
    });
  }

  // Batch download all QR codes with a slight delay
  const batchBtn = document.getElementById("batch-download-qrs-btn");
  if (batchBtn) {
    batchBtn.addEventListener("click", () => {
      const canvases = document.querySelectorAll("#attendees-dir-table .att-qr-thumb");
      if (!canvases.length) return;
      batchBtn.disabled = true;
      batchBtn.textContent = "Downloading " + canvases.length + " QRs...";
      let i = 0;
      function next(){
        if (i < canvases.length) {
          const c = canvases[i];
          const link = document.createElement("a");
          link.download = c.dataset.filename || ("nacos-qr-" + (i + 1) + ".png");
          link.href = c.toDataURL("image/png");
          link.click();
          i++;
          setTimeout(next, 300);
        } else {
          batchBtn.disabled = false;
          batchBtn.textContent = "✓ Downloaded All QRs";
          setTimeout(() => {
            batchBtn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/></svg> Download All QRs';
          }, 3000);
        }
      }
      next();
    });
  }

  // Certificate email handling
  const csrfToken = "<?= admin_h(admin_csrf_token()) ?>";
  const regId = <?= (int) $id ?>;

  const bulkEmailBox = document.getElementById("bulk-email-box");
  const closeBulkBtn = document.getElementById("close-bulk-email-box-btn");
  const bulkEmailInput = document.getElementById("bulk-email-input");
  const bulkCertIdInput = document.getElementById("bulk-email-cert-id");
  if (closeBulkBtn && bulkEmailBox) {
    closeBulkBtn.addEventListener("click", () => {
      bulkEmailBox.style.display = "none";
    });
  }

  const toggleNotesBtn = document.getElementById("toggle-notes-email-cert-btn");
  const notesEmailBox = document.getElementById("notes-email-cert-box");
  const notesEmailInput = document.getElementById("notes-email-input");

  if (toggleNotesBtn && notesEmailBox) {
    toggleNotesBtn.addEventListener("click", (e) => {
      e.preventDefault();
      const isVisible = notesEmailBox.style.display !== "none";
      notesEmailBox.style.display = isVisible ? "none" : "block";
      if (!isVisible) {
        notesEmailInput?.focus();
      }
    });
  }

  function isPlaceholderOrMissingEmail(email) {
    if (!email) return true;
    email = email.trim();
    if (email === "" || email === "—" || email.toLowerCase() === "n/a" || email.toLowerCase() === "none") return true;
    if (/^bulk-[a-z0-9-]+@nacosoutheast\.org\.ng$/i.test(email)) return true;
    return false;
  }

  // Individual attendee email button handler
  document.querySelectorAll(".individual-email-cert-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      const certId = btn.dataset.certId || "0";
      const rawEmail = (btn.dataset.email || "").trim();
      const hasEmail = !isPlaceholderOrMissingEmail(rawEmail);
      const name = btn.dataset.name || "Attendee";
      if (bulkEmailBox) {
        bulkEmailBox.style.display = "block";
        if (bulkCertIdInput) bulkCertIdInput.value = certId;
        if (bulkEmailInput) {
          // If no email registered, strictly keep input empty!
          bulkEmailInput.value = hasEmail ? rawEmail : "";
          bulkEmailInput.placeholder = hasEmail ? "Recipient email address" : "Enter email to receive this certificate (no email registered)";
        }
        const heading = bulkEmailBox.querySelector("strong");
        if (heading) heading.textContent = "Email Certificate for " + name;

        const subtext = bulkEmailBox.querySelector("div[style*='font-size:12.5px']");
        if (subtext) {
          subtext.textContent = hasEmail
            ? "Enter or confirm the recipient email below for " + name + "."
            : "This attendee was not registered with an email. Please enter a destination email below before sending.";
        }

        const sendBtn = document.getElementById("bulk-email-send-btn");
        if (sendBtn) {
          sendBtn.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Send Certificate
          `;
        }

        const statusEl = document.getElementById("bulk-email-status");
        if (statusEl) {
          statusEl.style.display = "none";
          statusEl.textContent = "";
        }

        bulkEmailBox.scrollIntoView({ behavior: "smooth", block: "nearest" });
        bulkEmailInput?.focus();
      }
    });
  });

  window.certificateList = <?= json_encode(array_values(array_map(function($c) {
      return [
          'id' => (int) $c['id'],
          'name' => (string) $c['attendee_name'],
          'token' => (string) $c['token']
      ];
  }, $certificateRows ?? [])), JSON_UNESCAPED_UNICODE) ?>;

  function generateCertPdf(token) {
    return new Promise((resolve, reject) => {
      const iframe = document.createElement("iframe");
      iframe.style.position = "fixed";
      iframe.style.width = "1200px";
      iframe.style.height = "850px";
      iframe.style.left = "-9999px";
      iframe.style.top = "-9999px";
      iframe.style.opacity = "0";
      iframe.style.pointerEvents = "none";
      iframe.setAttribute("aria-hidden", "true");

      let cleanedUp = false;
      function cleanup() {
        if (cleanedUp) return;
        cleanedUp = true;
        window.removeEventListener("message", onMsg);
        if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
      }

      const timer = setTimeout(() => {
        cleanup();
        reject(new Error("Timeout generating PDF"));
      }, 25000);

      function onMsg(ev) {
        if (!ev.data) return;
        if (ev.data.type === "NACOS_CERT_PDF" && ev.data.token === token) {
          clearTimeout(timer);
          cleanup();
          resolve({ name: ev.data.name, content: ev.data.base64 });
        } else if (ev.data.type === "NACOS_CERT_ERROR" && ev.data.token === token) {
          clearTimeout(timer);
          cleanup();
          reject(new Error(ev.data.error || "Failed to render certificate"));
        }
      }

      window.addEventListener("message", onMsg);
      iframe.src = "../verify-certificate.php?token=" + encodeURIComponent(token) + "&download=pdf&export=base64";
      document.body.appendChild(iframe);
    });
  }

  async function sendCertEmail(email, certId, statusEl, submitBtn, defaultBtnHtml) {
    if (!email) return;
    submitBtn.disabled = true;
    submitBtn.textContent = "Preparing PDF...";
    statusEl.style.display = "block";
    statusEl.style.color = "#175b26";
    statusEl.style.background = "#e5f5e7";
    statusEl.style.border = "1px solid #c3e6c8";
    statusEl.style.padding = "8px 12px";
    statusEl.style.borderRadius = "6px";
    statusEl.textContent = "Preparing certificate PDF...";

    // Determine target certificates to attach
    const certsToAttach = [];
    if (window.certificateList && Array.isArray(window.certificateList)) {
      if (certId && certId !== "0") {
        const found = window.certificateList.find(c => String(c.id) === String(certId));
        if (found) certsToAttach.push(found);
      } else {
        certsToAttach.push(...window.certificateList);
      }
    }

    const attachments = [];
    if (certsToAttach.length > 0) {
      for (let i = 0; i < certsToAttach.length; i++) {
        const item = certsToAttach[i];
        const label = certsToAttach.length === 1 
          ? "Generating certificate PDF..." 
          : "Generating certificate PDF (" + (i + 1) + " of " + certsToAttach.length + ")...";
        submitBtn.textContent = "Generating PDF...";
        statusEl.textContent = label;
        try {
          const pdfAttachment = await generateCertPdf(item.token);
          if (pdfAttachment && pdfAttachment.content) {
            attachments.push(pdfAttachment);
          }
        } catch (genErr) {
          console.warn("Could not generate PDF attachment for token " + item.token + ":", genErr);
        }
      }
    }

    submitBtn.textContent = "Sending...";
    statusEl.textContent = attachments.length > 2
      ? "Packaging " + attachments.length + " certificates into a ZIP archive and sending..."
      : (attachments.length > 0
        ? "Sending email with " + attachments.length + " attached certificate" + (attachments.length === 1 ? "" : "s") + "..."
        : "Sending certificate email...");

    const params = new URLSearchParams();
    params.append("csrf_token", csrfToken);
    params.append("registration_id", regId);
    params.append("recipient_email", email);
    if (certId && certId !== "0") {
      params.append("cert_id", certId);
    }
    if (attachments.length > 0) {
      params.append("attachments", JSON.stringify(attachments));
    }

    try {
      const res = await fetch("../api/send-certificate-email.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: params.toString()
      });
      const rawText = await res.text();
      let data = null;
      try {
        data = JSON.parse(rawText);
      } catch (jsonErr) {
        console.error("Non-JSON server response:", rawText);
        const jsonMatch = rawText.match(/\{[\s\S]*\}/);
        if (jsonMatch) {
          try { data = JSON.parse(jsonMatch[0]); } catch (e) {}
        }
      }
      submitBtn.disabled = false;
      submitBtn.innerHTML = defaultBtnHtml;
      if (res.ok && data && data.success) {
        statusEl.style.color = "#166534";
        statusEl.style.background = "#dcfce7";
        statusEl.style.border = "1px solid #bbf7d0";
        statusEl.textContent = "✓ " + (data.message || "Certificate sent successfully.");

        if (data.updated_certs && Array.isArray(data.updated_certs)) {
          data.updated_certs.forEach(item => {
            const count = parseInt(item.email_sent_count, 10) || 0;
            const certId = item.id;
            const timeStr = "Just now";

            const tblEl = document.getElementById("cert-email-badge-tbl-" + certId);
            if (tblEl) {
              tblEl.innerHTML = `
                <span class="badge" style="background:#e8f5ec;color:#0b6623;font-size:11px;padding:3px 8px;border-radius:10px;font-weight:650;display:inline-flex;align-items:center;gap:4px">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Sent ${count} ${count === 1 ? 'time' : 'times'}
                </span>
                <div style="font-size:10.5px;color:#617263;margin-top:3px">${timeStr}</div>
              `;
            }

            const cardEl = document.getElementById("cert-email-badge-card-" + certId);
            if (cardEl) {
              cardEl.innerHTML = `
                <span class="badge" style="background:#e8f5ec;color:#0b6623;font-size:11px;padding:2px 7px;border-radius:10px;font-weight:650;display:inline-flex;align-items:center;gap:3px" title="Sent ${count} times (${timeStr})">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  Sent ${count}x
                </span>
              `;
            }
          });

          // Update bulk total stat counter
          const bulkStat = document.getElementById("bulk-total-emailed-count");
          if (bulkStat) {
            let total = 0;
            document.querySelectorAll("[id^='cert-email-badge-card-'] .badge").forEach(b => {
              const m = (b.textContent || "").match(/Sent\s+(\d+)x/);
              if (m) total += parseInt(m[1], 10);
            });
            if (total > 0) {
              bulkStat.textContent = total + " Sent";
            }
          }
        }
      } else {
        statusEl.style.color = "#991b1b";
        statusEl.style.background = "#fee2e2";
        statusEl.style.border = "1px solid #fecaca";
        statusEl.textContent = "✕ " + ((data && data.error) ? data.error : "Failed to send email. Please check server logs.");
      }
    } catch (err) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = defaultBtnHtml;
      statusEl.style.color = "#991b1b";
      statusEl.style.background = "#fee2e2";
      statusEl.style.border = "1px solid #fecaca";
      statusEl.textContent = "✕ " + (err && err.message ? err.message : "Network or server error occurred.");
    }
  }

  const bulkEmailForm = document.getElementById("bulk-email-form");
  if (bulkEmailForm) {
    const origBulkHtml = document.getElementById("bulk-email-send-btn")?.innerHTML || "Send Certificate";
    bulkEmailForm.addEventListener("submit", (e) => {
      e.preventDefault();
      const email = bulkEmailInput?.value.trim() || "";
      const certId = bulkCertIdInput?.value || "0";
      const statusEl = document.getElementById("bulk-email-status");
      const submitBtn = document.getElementById("bulk-email-send-btn");
      sendCertEmail(email, certId, statusEl, submitBtn, origBulkHtml);
    });
  }

  const notesEmailForm = document.getElementById("notes-email-form");
  if (notesEmailForm) {
    const origNotesHtml = document.getElementById("notes-email-send-btn")?.innerHTML || "Send Certificate";
    notesEmailForm.addEventListener("submit", (e) => {
      e.preventDefault();
      const email = notesEmailInput?.value.trim() || "";
      const statusEl = document.getElementById("notes-email-status");
      const submitBtn = document.getElementById("notes-email-send-btn");
      sendCertEmail(email, "0", statusEl, submitBtn, origNotesHtml);
    });
  }
})();
</script>

<?php
admin_page_end();
