<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/manual-payment-mail.php';
admin_require_login();

$pdo = nacos_db();

// Real-time duplicate check endpoint for AJAX bulk emails
if (isset($_GET['action']) && $_GET['action'] === 'check_bulk_duplicates') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $emailsRaw = (string) ($_POST['emails'] ?? $_GET['emails'] ?? '');
    $emails = array_unique(array_filter(array_map('trim', explode(',', strtolower($emailsRaw)))));
    $duplicates = [];
    if (!empty($emails)) {
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $stmt = $pdo->prepare("SELECT id, applicant_name, applicant_email, package_name, payment_reference FROM paid_registrations WHERE LOWER(applicant_email) IN ($placeholders) LIMIT 20");
        $stmt->execute(array_values($emails));
        $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode([
        'found' => !empty($duplicates),
        'duplicates' => $duplicates,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$error = '';
$notice = '';
$duplicateMatches = [];
$allowDuplicate = false;

$packages = [
    'VISITING NACOSITE' => 15000,
    'HOME NACOSITE' => 10000,
    'VISITING NON NACOSITE' => 20000,
    'HOME NON NACOSITE' => 15000,
    'VIRTUAL ATTENDEE' => 5000,
    'CUSTOM CONVENTION PACKAGE' => null,
];

$values = [
    'institution' => 'Ogbonnaya Onu Polytechnic',
    'other_inst' => '',
    'package_name' => 'VISITING NACOSITE',
    'custom_package' => '',
    'amount' => '',
    'paid_at' => date('Y-m-d\TH:i'),
    'admin_notes' => '',
];
$attendees = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($values as $key => $_) {
        $posted = $_POST[$key] ?? '';
        $values[$key] = is_string($posted) ? trim($posted) : '';
    }

    $allowDuplicate = !empty($_POST['allow_duplicate']);
    $packageName = $values['package_name'] === '__custom' ? $values['custom_package'] : $values['package_name'];

    // Resolve institution
    $institutionName = $values['institution'] === 'other'
        ? trim($values['other_inst'])
        : trim($values['institution']);

    // Collect attendee rows from form
    $submittedAttendees = $_POST['attendees'] ?? [];
    if (is_array($submittedAttendees)) {
        foreach ($submittedAttendees as $row) {
            if (!is_array($row)) continue;
            $name = trim((string) ($row['name'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $phone = trim((string) ($row['phone'] ?? ''));
            if ($name !== '' || $email !== '' || $phone !== '') {
                $attendees[] = [
                    'name' => function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name),
                    'email' => $email,
                    'phone' => $phone,
                ];
            }
        }
    }

    // Check duplicate attendee emails
    $allEmails = array_filter(array_unique(array_column($attendees, 'email')));

    if (!empty($allEmails)) {
        $placeholders = implode(',', array_fill(0, count($allEmails), '?'));
        $dupStmt = $pdo->prepare("SELECT id, applicant_name, applicant_email, package_name, payment_reference FROM paid_registrations WHERE LOWER(applicant_email) IN ($placeholders) LIMIT 20");
        $dupStmt->execute(array_values($allEmails));
        $duplicateMatches = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!admin_verify_csrf()) {
        $error = 'This session expired. Refresh the page and try again.';
    } elseif ($institutionName === '' || strlen($institutionName) > 200) {
        $error = 'Please select or specify the institution name.';
    } elseif ($values['package_name'] !== '__custom' && !array_key_exists($values['package_name'], $packages)) {
        $error = 'Choose a valid convention package.';
    } elseif ($values['package_name'] === '__custom' && ($packageName === '' || strlen($packageName) > 160)) {
        $error = 'Enter a custom package description (up to 160 characters).';
    } elseif (empty($attendees)) {
        $error = 'Add at least one attendee in the student list below.';
    } elseif (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $values['amount']) || (float) $values['amount'] <= 0) {
        $error = 'Enter a valid total amount in naira.';
    } elseif (!empty($duplicateMatches) && !$allowDuplicate) {
        $error = 'One or more attendees in this list already have an existing paid registration in the system. Check "I confirm this is an intentional additional bulk registration" to proceed.';
    } else {
        $paidAt = DateTime::createFromFormat('Y-m-d\TH:i', $values['paid_at']);
        $dateErrors = DateTime::getLastErrors();
        if (!$paidAt || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            $error = 'Enter a valid payment date and time.';
        } else {
            $amountParts = explode('.', $values['amount'], 2);
            $naira = (int) $amountParts[0];
            $kobo = isset($amountParts[1]) ? (int) str_pad($amountParts[1], 2, '0') : 0;
            $amountKobo = $naira * 100 + $kobo;

            $reference = 'NACOS-OFFLINE-' . strtoupper(bin2hex(random_bytes(6)));

            // Use first attendee as primary lead contact
            $leadAttendee = $attendees[0];
            $leadName = $leadAttendee['name'];
            $leadEmail = $leadAttendee['email'] !== '' ? $leadAttendee['email'] : ('bulk-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $institutionName)) . '@nacosoutheast.org.ng');
            $leadPhone = $leadAttendee['phone'] !== '' ? $leadAttendee['phone'] : '—';

            $data = [
                'admin_recorded' => true,
                'payment_channel' => 'offline',
                'bulk_registration' => true,
                'institution' => $institutionName,
                'total_attendees' => count($attendees),
                'attendees' => $attendees,
                'admin_notes' => $values['admin_notes'],
            ];

            $statement = $pdo->prepare(
                'INSERT INTO paid_registrations
                 (form_type, applicant_name, applicant_email, phone, category, package_name, amount_kobo, currency, payment_status, payment_reference, payment_channel, payment_paid_at, submission_data)
                 VALUES (\'convention\', :name, :email, :phone, NULL, :package, :amount, \'NGN\', \'paid\', :reference, \'offline\', :paid_at, :data)'
            );

            try {
                $statement->execute([
                    'name' => $leadName,
                    'email' => $leadEmail,
                    'phone' => $leadPhone,
                    'package' => $packageName,
                    'amount' => $amountKobo,
                    'reference' => $reference,
                    'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                    'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);

                $newId = (int) $pdo->lastInsertId();

                // Send confirmation emails
                $emailSent = nacos_manual_send_payer_confirmations([
                    'form_type' => 'convention',
                    'applicant_name' => $leadName,
                    'applicant_email' => $leadEmail,
                    'package_name' => $packageName,
                    'amount_kobo' => $amountKobo,
                    'currency' => 'NGN',
                    'payment_reference' => $reference,
                    'submission_data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);

                $notice = 'Bulk convention registration saved successfully for ' . count($attendees) . ' attendees! Reference: ' . $reference . ' · <a href="paid-registration.php?id=' . $newId . '" style="color:#073b12;font-weight:700;text-decoration:underline">View record and generate certificates →</a>';
                $values = [
                    'institution' => 'Ogbonnaya Onu Polytechnic', 'other_inst' => '',
                    'package_name' => 'VISITING NACOSITE', 'custom_package' => '', 'amount' => '',
                    'paid_at' => date('Y-m-d\TH:i'), 'admin_notes' => '',
                ];
                $attendees = [];
            } catch (Throwable $exception) {
                $error = 'Database error: ' . $exception->getMessage();
            }
        }
    }
}

admin_page_start('Record Bulk Student Registration');
$csrf = admin_h(admin_csrf_token());
?>

<style>
.step-card {
  background: #ffffff;
  border: 1.5px solid #dce5dc;
  border-radius: 14px;
  padding: 24px;
  margin-bottom: 22px;
  box-shadow: 0 4px 14px rgba(18, 45, 22, 0.04);
}
.step-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
  flex-wrap: wrap;
  gap: 12px;
}
.step-title-wrap {
  display: flex;
  align-items: center;
  gap: 12px;
}
.step-num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #073b12;
  color: #ffffff;
  font-weight: 800;
  font-size: 14px;
  flex-shrink: 0;
}
.step-title {
  margin: 0;
  font-size: 18px;
  font-weight: 750;
  color: #073b12;
  letter-spacing: -0.02em;
}
.step-sub {
  font-size: 12.5px;
  color: #5c6e5e;
  margin-top: 2px;
}
.type-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}
.type-choice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-radius: 12px;
  background: #ffffff;
  border: 1.5px solid #d4e0d6;
  text-decoration: none;
  color: #182e1c;
  transition: all 180ms ease;
  cursor: pointer;
}
.type-choice:hover {
  border-color: #0b6623;
  background: #f8faf8;
}
.type-choice.active {
  border: 2px solid #073b12;
  background: #f0fbf3;
  box-shadow: 0 4px 12px rgba(7, 59, 18, 0.08);
}
.type-content {
  display: flex;
  align-items: center;
  gap: 14px;
}
.type-icon {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.type-radio {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.type-radio.checked {
  border: 6px solid #073b12;
  background: #fff;
}
.type-radio.unchecked {
  border: 2px solid #cad5ca;
  background: #fff;
}
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px 22px;
  margin-bottom: 20px;
}
.form-grid label {
  display: grid;
  gap: 6px;
  font-size: 13px;
  font-weight: 650;
  color: #273b2c;
}
.form-grid input, .form-grid select, .form-grid textarea {
  display: block;
  width: 100%;
  min-height: 46px;
  margin: 0;
}
.info-note {
  background: #f4f9f4;
  border-left: 4px solid #0b6623;
  padding: 13px 18px;
  border-radius: 0 8px 8px 0;
  font-size: 13px;
  color: #234827;
  line-height: 1.5;
  margin: 18px 0;
}
.students-table {
  width: 100%;
  border-collapse: collapse;
  background: #ffffff;
  border: 1px solid #dce5dc;
  border-radius: 8px;
  overflow: hidden;
  margin: 14px 0;
}
.students-table th {
  background: #f0f7f1;
  color: #143e1d;
  font-size: 12.5px;
  font-weight: 700;
  padding: 12px 14px;
  text-align: left;
  border-bottom: 1px solid #dce5dc;
}
.students-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #edf2ed;
  vertical-align: middle;
}
.students-table input {
  min-height: 42px;
  padding: 8px 12px;
  font-size: 13px;
  border: 1px solid #cad5ca;
  border-radius: 6px;
  width: 100%;
  box-sizing: border-box;
}
.students-table .sn-cell {
  font-weight: 750;
  color: #0b6623;
  text-align: center;
  width: 44px;
}
.remove-btn {
  background: #fee2e2;
  color: #991b1b;
  border: 1px solid #fecaca;
  padding: 8px 14px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 650;
  cursor: pointer;
  white-space: nowrap;
  transition: all 150ms ease;
}
.remove-btn:hover {
  background: #fca5a5;
  color: #7f1d1d;
}
.add-student-btn {
  background: #073b12;
  color: #ffffff;
  border: 0;
  padding: 11px 20px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background 150ms ease;
}
.add-student-btn:hover {
  background: #05290c;
}
.duplicate-alert-box {
  padding: 18px 20px;
  border-radius: 12px;
  background: #f0fbf3;
  border: 1.5px solid #0b6623;
  margin: 18px 0;
  display: grid;
  gap: 12px;
}
.duplicate-alert-header {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #073b12;
  font-size: 14.5px;
  font-weight: 700;
}
.duplicate-match-item {
  padding: 10px 14px;
  background: #fff;
  border: 1px solid #cbe4d0;
  border-left: 4px solid #0b6623;
  border-radius: 8px;
  font-size: 12.5px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.duplicate-checkbox-label {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  font-weight: 600;
  color: #073b12;
  cursor: pointer;
  padding: 10px 14px;
  background: #e8f5ec;
  border: 1px solid #bfe3c7;
  border-radius: 8px;
}
.duplicate-checkbox-label input {
  width: 18px;
  height: 18px;
  margin: 0;
  accent-color: #0b6623;
}
@media(max-width: 720px) {
  .type-grid, .form-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 680px) {
  .step-card {
    padding: 16px 14px;
    border-radius: 12px;
  }
  .table-scroll {
    overflow: visible !important;
  }
  table.students-table {
    min-width: 0 !important;
    width: 100% !important;
    border: none !important;
    background: transparent !important;
  }
  .students-table thead {
    display: none !important;
  }
  .students-table, 
  .students-table tbody, 
  .students-table tr, 
  .students-table td {
    display: block !important;
    width: 100% !important;
    box-sizing: border-box !important;
  }
  .students-table tr {
    background: #ffffff;
    border: 1.5px solid #dce5dc;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 14px;
    box-shadow: 0 2px 8px rgba(18, 45, 22, 0.04);
  }
  .students-table td {
    padding: 5px 0;
    border: none;
  }
  .students-table td::before {
    content: attr(data-label);
    display: block;
    font-size: 11.5px;
    font-weight: 600;
    color: #556858;
    margin-bottom: 4px;
    margin-top: 4px;
  }
  .students-table td:first-child {
    font-weight: 750;
    color: #073b12;
    border-bottom: 1px solid #edf2ed;
    padding-bottom: 8px;
    margin-bottom: 8px;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    width: 100% !important;
    text-align: right;
  }
  .students-table td:first-child::before {
    content: "Student #" attr(data-index);
    display: inline-block;
    font-size: 13.5px;
    font-weight: 750;
    color: #073b12;
    margin: 0;
  }
  .students-table td[data-label="Action"] {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed #dce5dc;
  }
  .students-table td[data-label="Action"]::before {
    display: none !important;
  }
  .remove-btn {
    width: 100%;
    padding: 10px;
    text-align: center;
    display: block;
    border-radius: 8px;
  }
  .add-student-btn {
    width: 100%;
    justify-content: center;
  }
  #table-summary-text {
    width: 100%;
    text-align: center;
  }
  .actions {
    flex-direction: column;
  }
  .actions button, .actions .button {
    width: 100%;
    text-align: center;
  }
}
</style>

<div class="subhead">
  <div>
    <a class="button button-light" href="offline-payers.php" style="width:auto;min-height:36px;padding:8px 12px">Back to Offline Payers</a>
    <h1 style="margin-top:12px">Record Bulk Student Registration</h1>
    <p class="muted">Add multiple students from an institution for the NACOS South East Convention in one single entry.</p>
  </div>
</div>

<?php if ($notice !== ''): ?><div class="notice"><?= $notice ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>

<!-- STEP 1: REGISTRATION TYPE -->
<section class="step-card">
  <div class="step-header">
    <div class="step-title-wrap">
      <span class="step-num">1</span>
      <div>
        <h2 class="step-title">Registration Type</h2>
        <div class="step-sub">Choose how you are registering.</div>
      </div>
    </div>
  </div>
  <div class="type-grid">
    <a href="add-offline-payment.php" class="type-choice">
      <div class="type-content">
        <div class="type-icon" style="background:#eef6ed;color:#0b6623">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
        <div>
          <strong style="display:block;font-size:14.5px;color:#073b12">Single Registration</strong>
          <small style="color:#5c6e5e;font-size:12px">Register as an individual.</small>
        </div>
      </div>
      <div class="type-radio unchecked"></div>
    </a>
    <div class="type-choice active">
      <div class="type-content">
        <div class="type-icon" style="background:#e5f2e6;color:#073b12">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
        </div>
        <div>
          <strong style="display:block;font-size:14.5px;color:#073b12">Bulk Student Registration</strong>
          <small style="color:#5c6e5e;font-size:12px">Register multiple students.</small>
        </div>
      </div>
      <div class="type-radio checked"></div>
    </div>
  </div>
</section>

<!-- STEP 2: BULK STUDENT REGISTRATION FORM -->
<section class="step-card">
  <div class="step-header">
    <div class="step-title-wrap">
      <span class="step-num">2</span>
      <div>
        <h2 class="step-title">Bulk Student Registration</h2>
      </div>
    </div>
    <span class="badge" style="background:#eef6ed;color:#073b12;border:1px solid #cce5cf;padding:5px 12px;font-size:12px;font-weight:650;border-radius:999px">For multiple students</span>
  </div>

  <form method="post" id="bulk-form" class="form-stack">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

    <div class="form-grid">
      <!-- Institution -->
      <label>
        Institution *
        <select name="institution" id="bulk-institution" required>
          <option value="Ogbonnaya Onu Polytechnic"<?= $values['institution'] === 'Ogbonnaya Onu Polytechnic' ? ' selected' : '' ?>>Ogbonnaya Onu Polytechnic</option>
          <option value="other"<?= $values['institution'] === 'other' ? ' selected' : '' ?>>Other (Specify below)</option>
        </select>
      </label>

      <!-- Specify Institution (Conditional) -->
      <label id="other-inst-group" style="<?= $values['institution'] === 'other' ? '' : 'display:none' ?>">
        Specify Institution *
        <input name="other_inst" id="bulk-other-inst" maxlength="200" value="<?= admin_h($values['other_inst']) ?>" placeholder="Enter school name">
      </label>

      <!-- Package -->
      <label>
        Convention Package *
        <select name="package_name" id="bulk-package" required>
          <?php foreach ($packages as $pkg => $price): ?>
            <?php
            $selected = $values['package_name'] === $pkg ? ' selected' : '';
            $priceAttr = $price === null ? '' : ' data-price="' . (int) $price . '"';
            $label = $price === null ? $pkg . ' — custom pricing' : $pkg . ' — ₦' . number_format((int) $price) . ' / student';
            ?>
            <option value="<?= admin_h($pkg) ?>"<?= $priceAttr ?><?= $selected ?>><?= admin_h($label) ?></option>
          <?php endforeach; ?>
          <option value="__custom"<?= $values['package_name'] === '__custom' ? ' selected' : '' ?>>Custom Package</option>
        </select>
      </label>

      <!-- Custom Package Description -->
      <label id="custom-pkg-wrap" style="<?= $values['package_name'] === '__custom' ? 'grid-column: 1 / -1' : 'grid-column: 1 / -1; display:none' ?>">
        Custom Package Description
        <input name="custom_package" maxlength="160" value="<?= admin_h($values['custom_package']) ?>" placeholder="e.g. Subsidized Chapter Pass">
      </label>

      <!-- Total Amount -->
      <label>
        Total Amount Paid (₦) *
        <input id="bulk-amount" name="amount" inputmode="decimal" required value="<?= admin_h($values['amount']) ?>" placeholder="e.g. 30000.00">
        <span class="muted" id="amount-calc-hint" style="font-size:11.5px">Calculated automatically from student count.</span>
      </label>

      <!-- Date and Time -->
      <label>
        Date and Time Paid *
        <input type="datetime-local" name="paid_at" required value="<?= admin_h($values['paid_at']) ?>">
      </label>
    </div>

    <!-- Duplicate Alert for Bulk -->
    <?php if (!empty($duplicateMatches)): ?>
      <div class="duplicate-alert-box">
        <div class="duplicate-alert-header">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <span>Existing Paid Attendees Detected in List</span>
        </div>
        <p class="muted" style="margin:0;color:#27432d">The following attendees in this batch are already registered in the system:</p>
        <div style="display:grid;gap:6px">
          <?php foreach ($duplicateMatches as $dm): ?>
            <div class="duplicate-match-item">
              <div><strong><?= admin_h((string) $dm['applicant_name']) ?></strong> (<?= admin_h((string) $dm['applicant_email']) ?>) — <?= admin_h((string) $dm['package_name']) ?></div>
              <a href="paid-registration.php?id=<?= (int) $dm['id'] ?>" target="_blank" rel="noopener" style="color:#0b6623;font-weight:650">View record ↗</a>
            </div>
          <?php endforeach; ?>
        </div>
        <label class="duplicate-checkbox-label">
          <input type="checkbox" name="allow_duplicate" value="1"<?= $allowDuplicate ? ' checked' : '' ?>>
          <span>I confirm this is an intentional bulk registration despite the existing matches above.</span>
        </label>
      </div>
    <?php endif; ?>

    <div class="info-note">
      You can add multiple students below. Fill in each student's details.
    </div>

    <!-- Students Table Section -->
    <div style="margin: 18px 0">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <h3 style="margin:0;font-size:15px;color:#073b12">Students List</h3>
        <span id="student-count-badge" class="badge" style="background:#0b6623;color:#fff;font-size:11.5px;padding:4px 10px">0 Students</span>
      </div>

      <div class="table-scroll">
        <table class="students-table">
          <thead>
            <tr>
              <th class="sn-cell">S/N</th>
              <th>Full Name</th>
              <th>Email Address</th>
              <th>Phone Number</th>
              <th style="width:90px;text-align:center">Action</th>
            </tr>
          </thead>
          <tbody id="students-table-body">
            <!-- Dynamic student rows -->
          </tbody>
        </table>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;flex-wrap:wrap;gap:10px">
        <button type="button" id="add-student-btn" class="add-student-btn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Student
        </button>
        <span class="muted" id="table-summary-text" style="font-size:12.5px">0 attendees in list</span>
      </div>
    </div>

    <!-- Admin Notes -->
    <label style="display:grid;gap:6px;font-size:13px;font-weight:650;color:#273b2c;margin-top:12px">
      Admin Notes (Optional)
      <textarea name="admin_notes" rows="3" style="min-height:80px" placeholder="e.g. Bulk EBSU convention delegation of 15 students paid to Zonal bank account."><?= admin_h($values['admin_notes']) ?></textarea>
    </label>

    <div class="actions" style="margin-top:20px;display:flex;gap:12px">
      <button type="submit" style="padding:12px 24px;font-size:14px">Record Bulk Registration</button>
      <a class="button button-light" href="offline-payers.php" style="padding:12px 20px;font-size:14px">Cancel</a>
    </div>
  </form>
</section>

<script>
(function(){
  const instSelect = document.getElementById("bulk-institution");
  const otherInstGroup = document.getElementById("other-inst-group");
  const otherInstInput = document.getElementById("bulk-other-inst");

  if (instSelect && otherInstGroup) {
    instSelect.addEventListener("change", () => {
      if (instSelect.value === "other") {
        otherInstGroup.style.display = "grid";
        otherInstInput?.setAttribute("required", "required");
        otherInstInput?.focus();
      } else {
        otherInstGroup.style.display = "none";
        otherInstInput?.removeAttribute("required");
      }
    });
  }

  const packageSelect = document.getElementById("bulk-package");
  const customWrap = document.getElementById("custom-pkg-wrap");
  const amountInput = document.getElementById("bulk-amount");
  const tbody = document.getElementById("students-table-body");
  const addStudentBtn = document.getElementById("add-student-btn");
  const countBadge = document.getElementById("student-count-badge");
  const summaryText = document.getElementById("table-summary-text");
  const calcHint = document.getElementById("amount-calc-hint");

  let initialAttendees = <?= json_encode($attendees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  if (!initialAttendees || initialAttendees.length === 0) {
    initialAttendees = [
      { name: "", email: "", phone: "" },
      { name: "", email: "", phone: "" }
    ];
  }

  function getUnitPrice(){
    const opt = packageSelect.selectedOptions[0];
    if (opt && opt.dataset.price) {
      return parseInt(opt.dataset.price, 10);
    }
    return null;
  }

  function updateCountAndTotals(){
    const rows = tbody.querySelectorAll("tr");
    const count = rows.length;
    countBadge.textContent = count + (count === 1 ? " Student" : " Students");
    summaryText.textContent = count + (count === 1 ? " attendee in list" : " attendees in list");

    const unitPrice = getUnitPrice();
    if (unitPrice && count > 0) {
      const calculatedTotal = count * unitPrice;
      calcHint.textContent = `${count} students × ₦${unitPrice.toLocaleString()} = ₦${calculatedTotal.toLocaleString()}`;
      if (amountInput.value.trim() === "" || amountInput.dataset.autoCalc === "true") {
        amountInput.value = calculatedTotal.toFixed(2);
        amountInput.dataset.autoCalc = "true";
      }
    } else {
      calcHint.textContent = "Custom pricing mode.";
    }

    rows.forEach((row, idx) => {
      const num = idx + 1;
      const numCell = row.querySelector(".sn-cell");
      if (numCell) {
        numCell.textContent = "#" + num;
        numCell.dataset.index = num;
      }
    });
  }

  amountInput.addEventListener("input", () => {
    amountInput.dataset.autoCalc = "false";
  });

  packageSelect.addEventListener("change", () => {
    const isCustom = packageSelect.value === "__custom";
    customWrap.style.display = isCustom ? "grid" : "none";
    amountInput.dataset.autoCalc = "true";
    updateCountAndTotals();
  });

  function createRow(data, idx){
    const tr = document.createElement("tr");
    const index = idx;
    const num = index + 1;
    tr.innerHTML = `
      <td class="sn-cell" data-label="S/N" data-index="${num}">#${num}</td>
      <td data-label="Full Name"><input type="text" name="attendees[${index}][name]" value="${(data.name || '').replace(/"/g, '&quot;')}" required placeholder="Full Name" style="text-transform:uppercase"></td>
      <td data-label="Email Address"><input type="email" name="attendees[${index}][email]" value="${(data.email || '').replace(/"/g, '&quot;')}" placeholder="Email Address"></td>
      <td data-label="Phone Number"><input type="text" name="attendees[${index}][phone]" value="${(data.phone || '').replace(/"/g, '&quot;')}" placeholder="Phone Number"></td>
      <td data-label="Action" style="text-align:center"><button type="button" class="remove-btn">Remove</button></td>
    `;
    tr.querySelector(".remove-btn").addEventListener("click", () => {
      tr.remove();
      updateCountAndTotals();
    });
    return tr;
  }

  function addRow(data = {}){
    const rows = tbody.querySelectorAll("tr");
    const tr = createRow(data, rows.length);
    tbody.appendChild(tr);
    updateCountAndTotals();
  }

  initialAttendees.forEach(att => addRow(att));

  addStudentBtn.addEventListener("click", () => {
    addRow();
  });

  updateCountAndTotals();
})();
</script>

<?php
admin_page_end();
