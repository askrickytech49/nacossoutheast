<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/manual-payment-mail.php';
admin_require_login();

$pdo = nacos_db();

// Real-time duplicate check endpoint for AJAX
if (isset($_GET['action']) && $_GET['action'] === 'check_duplicate') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $email = strtolower(trim((string) ($_GET['email'] ?? '')));
    $phone = trim((string) ($_GET['phone'] ?? ''));
    $matches = [];
    if ($email !== '' || $phone !== '') {
        $clauses = [];
        $params = [];
        if ($email !== '') {
            $clauses[] = 'LOWER(applicant_email) = :dup_email';
            $params[':dup_email'] = $email;
            $clauses[] = 'submission_data LIKE :dup_email_json';
            $params[':dup_email_json'] = '%"' . $email . '"%';
        }
        if ($phone !== '') {
            $clauses[] = 'phone = :dup_phone';
            $params[':dup_phone'] = $phone;
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($cleanPhone) >= 9) {
                $clauses[] = 'phone LIKE :dup_phone_like';
                $params[':dup_phone_like'] = '%' . substr($cleanPhone, -9);
            }
        }
        $sql = 'SELECT id, form_type, applicant_name, applicant_email, phone, package_name, amount_kobo, payment_reference, payment_channel, payment_paid_at, created_at FROM paid_registrations WHERE ' . implode(' OR ', $clauses) . ' ORDER BY id DESC LIMIT 5';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode([
        'found' => !empty($matches),
        'matches' => array_map(static function(array $m): array {
            return [
                'id' => (int) $m['id'],
                'name' => (string) $m['applicant_name'],
                'email' => (string) $m['applicant_email'],
                'phone' => (string) $m['phone'],
                'package' => (string) $m['package_name'],
                'form_type' => (string) $m['form_type'],
                'channel' => (string) ($m['payment_channel'] ?: 'online'),
                'reference' => (string) $m['payment_reference'],
                'amount' => number_format(((int) $m['amount_kobo']) / 100, 2),
                'date' => date('M j, Y, g:i a', strtotime((string) ($m['payment_paid_at'] ?: $m['created_at']))),
            ];
        }, $matches),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$error = '';
$notice = '';
$duplicateMatches = [];
$allowDuplicate = false;

$packageOptions = [
    'convention' => [
        'HOME NACOSITE' => 10000, 'VISITING NACOSITE' => 15000, 'HOME NON NACOSITE' => 15000,
        'VISITING NON NACOSITE' => 20000, 'VIRTUAL ATTENDEE' => 5000,
    ],
    'sponsorship' => [
        'HEADLINE SPONSOR' => 10000000, 'DIAMOND SPONSOR' => 5000000, 'PLATINUM SPONSOR' => 3000000,
        'GOLD SPONSOR' => 2000000, 'SILVER SPONSOR' => 1000000, 'BRONZE SPONSOR' => 500000,
        'CUSTOM SPONSOR' => null,
    ],
    'pageant' => ['MR & MISS NACOS APPLICATION' => 20000],
];
$values = [
    'form_type' => 'convention', 'applicant_name' => '', 'institution' => '', 'applicant_email' => '', 'phone' => '',
    'category' => '', 'package_name' => '', 'custom_package' => '', 'amount' => '', 'paid_at' => date('Y-m-d\TH:i'),
    'details' => '', 'admin_notes' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($values as $key => $_) {
        $posted = $_POST[$key] ?? '';
        $values[$key] = is_string($posted) ? trim($posted) : '';
    }

    $allowDuplicate = !empty($_POST['allow_duplicate']);
    $packageName = $values['package_name'] === '__custom' ? $values['custom_package'] : $values['package_name'];

    // Check for duplicates in database
    if ($values['applicant_email'] !== '' || $values['phone'] !== '') {
        $clauses = [];
        $params = [];
        if ($values['applicant_email'] !== '') {
            $clauses[] = 'LOWER(applicant_email) = :dup_email';
            $params[':dup_email'] = strtolower($values['applicant_email']);
            $clauses[] = 'submission_data LIKE :dup_email_json';
            $params[':dup_email_json'] = '%"' . strtolower($values['applicant_email']) . '"%';
        }
        if ($values['phone'] !== '') {
            $clauses[] = 'phone = :dup_phone';
            $params[':dup_phone'] = $values['phone'];
            $cleanPhone = preg_replace('/[^0-9]/', '', $values['phone']);
            if (strlen($cleanPhone) >= 9) {
                $clauses[] = 'phone LIKE :dup_phone_like';
                $params[':dup_phone_like'] = '%' . substr($cleanPhone, -9);
            }
        }
        if ($clauses) {
            $dupStmt = $pdo->prepare('SELECT id, form_type, applicant_name, applicant_email, phone, package_name, amount_kobo, payment_reference, payment_channel, payment_paid_at, created_at FROM paid_registrations WHERE ' . implode(' OR ', $clauses) . ' ORDER BY id DESC LIMIT 5');
            $dupStmt->execute($params);
            $duplicateMatches = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (!admin_verify_csrf()) {
        $error = 'This session expired. Refresh the page and try again.';
    } elseif (!in_array($values['form_type'], ['convention', 'sponsorship', 'pageant'], true)) {
        $error = 'Choose a valid form type.';
    } elseif ($values['applicant_name'] === '' || strlen($values['applicant_name']) > 180) {
        $error = 'Enter the payer’s name (up to 180 characters).';
    } elseif (!filter_var($values['applicant_email'], FILTER_VALIDATE_EMAIL) || strlen($values['applicant_email']) > 254) {
        $error = 'Enter a valid payer email address.';
    } elseif ($values['phone'] === '' || strlen($values['phone']) > 50) {
        $error = 'Enter a phone number (up to 50 characters).';
    } elseif ($values['package_name'] !== '__custom' && !array_key_exists($values['package_name'], $packageOptions[$values['form_type']])) {
        $error = 'Choose a valid package for the selected form type.';
    } elseif ($values['package_name'] === '__custom' && ($packageName === '' || strlen($packageName) > 160)) {
        $error = 'Enter a custom package description (up to 160 characters).';
    } elseif (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $values['amount']) || (float) $values['amount'] <= 0) {
        $error = 'Enter a valid amount in naira, for example 10000 or 10500.50.';
    } elseif (strlen($values['institution']) > 200 || strlen($values['category']) > 120 || strlen($values['details']) > 20000 || strlen($values['admin_notes']) > 5000) {
        $error = 'One of the optional fields is too long.';
    } elseif (!empty($duplicateMatches) && !$allowDuplicate) {
        $firstMatch = $duplicateMatches[0];
        $error = 'Duplicate Warning: A paid registration already exists for ' . admin_h((string) $firstMatch['applicant_name']) . ' (' . admin_h((string) $firstMatch['applicant_email']) . ', ' . admin_h((string) $firstMatch['package_name']) . ', Ref: ' . admin_h((string) $firstMatch['payment_reference']) . '). If this attendee is purchasing an additional ticket, check "Confirm additional ticket" below and submit again.';
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
            if ($amountKobo < 1 || $amountKobo > 9000000000000000) {
                $error = 'The amount is outside the supported range.';
            } else {
                $reference = 'NACOS-OFFLINE-' . strtoupper(bin2hex(random_bytes(6)));
                $data = [
                    'admin_recorded' => true,
                    'payment_channel' => 'offline',
                    'institution' => $values['institution'] !== '' ? $values['institution'] : null,
                    'attendees' => [
                        [
                            'name' => $values['applicant_name'],
                            'email' => $values['applicant_email'],
                            'phone' => $values['phone'],
                        ]
                    ],
                    'details' => $values['details'],
                    'admin_notes' => $values['admin_notes'],
                ];
                $statement = $pdo->prepare(
                    'INSERT INTO paid_registrations
                     (form_type, applicant_name, applicant_email, phone, category, package_name, amount_kobo, currency, payment_status, payment_reference, payment_channel, payment_paid_at, submission_data)
                     VALUES (:form_type, :name, :email, :phone, :category, :package, :amount, \'NGN\', \'paid\', :reference, \'offline\', :paid_at, :data)'
                );
                try {
                    $statement->execute([
                        'form_type' => $values['form_type'], 'name' => $values['applicant_name'],
                        'email' => $values['applicant_email'], 'phone' => $values['phone'],
                        'category' => $values['category'] !== '' ? $values['category'] : null,
                        'package' => $packageName, 'amount' => $amountKobo,
                        'reference' => $reference, 'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                        'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                    $emailSent = nacos_manual_send_payer_confirmations([
                        'form_type' => $values['form_type'], 'applicant_name' => $values['applicant_name'],
                        'applicant_email' => $values['applicant_email'], 'package_name' => $packageName,
                        'amount_kobo' => $amountKobo, 'currency' => 'NGN', 'payment_reference' => $reference,
                        'submission_data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                    $notice = 'Offline payment recorded as paid. Reference: ' . $reference . ($emailSent ? ' Confirmation email sent.' : ' The confirmation email could not be sent; the payment is still recorded.');
                    $values = [
                        'form_type' => 'convention', 'applicant_name' => '', 'institution' => '', 'applicant_email' => '', 'phone' => '',
                        'category' => '', 'package_name' => '', 'custom_package' => '', 'amount' => '', 'paid_at' => date('Y-m-d\TH:i'),
                        'details' => '', 'admin_notes' => '',
                    ];
                    $duplicateMatches = [];
                    $allowDuplicate = false;
                } catch (Throwable $exception) {
                    error_log('Admin offline payment entry failed: ' . $exception->getMessage());
                    $error = 'The payment could not be saved. Check the database fields and try again.';
                }
            }
        }
    }
}

admin_page_start('Add offline payer');
echo '<style>
.offline-payment-form{gap:20px}
.offline-payment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 20px}
.offline-payment-form label{display:grid;gap:6px;color:#344638;font-size:13px;font-weight:600}
[hidden],#custom-package-wrap[hidden]{display:none !important}
#custom-package-wrap{grid-column:1 / -1}
.offline-payment-form input,.offline-payment-form select,.offline-payment-form textarea{display:block;width:100%;min-width:0;margin:0;min-height:46px}
.offline-payment-form textarea{min-height:112px;resize:vertical}
.offline-payment-form .actions{margin-top:4px}
.duplicate-alert-box{padding:18px 20px;border-radius:12px;background:#f0fbf3;border:1.5px solid #0b6623;box-shadow:0 4px 18px rgba(7,59,18,.06);margin:18px 0;display:grid;gap:12px;animation:fadeIn .2s ease-in-out}
.duplicate-alert-header{display:flex;align-items:center;gap:10px;color:#073b12;font-size:14.5px;font-weight:700;letter-spacing:-.01em}
.duplicate-alert-header svg{width:22px;height:22px;flex-shrink:0;color:#0b6623}
.duplicate-alert-text{margin:0;color:#27432d;font-size:13px;line-height:1.5}
.duplicate-matches-list{display:grid;gap:8px}
.duplicate-match-item{padding:12px 16px;background:#ffffff;border:1px solid #cbe4d0;border-left:4px solid #0b6623;border-radius:8px;font-size:12.5px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;box-shadow:0 2px 6px rgba(11,102,35,.04)}
.duplicate-match-item strong{color:#073b12;font-size:13.5px;font-weight:700}
.duplicate-match-item .muted{color:#556858;font-size:11.5px}
.duplicate-match-item a{color:#0b6623;background:#e8f5ec;border:1px solid #bfe3c7;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:650;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:all 180ms ease}
.duplicate-match-item a:hover{background:#0b6623;color:#ffffff;border-color:#0b6623;text-decoration:none}
.duplicate-checkbox-label{display:flex !important;align-items:center;gap:10px;font-size:13px;font-weight:600;color:#073b12;margin-top:4px;cursor:pointer;padding:10px 14px;background:#e8f5ec;border:1px solid #bfe3c7;border-radius:8px;transition:background 180ms ease}
.duplicate-checkbox-label:hover{background:#dff0e4}
.duplicate-checkbox-label input{width:18px !important;height:18px !important;min-height:18px !important;margin:0;cursor:pointer;accent-color:#0b6623}
.mode-switch{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:18px 0 24px}
.mode-card{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:12px;background:#fff;border:1.5px solid #d4e0d6;color:#182e1c;text-decoration:none;transition:all 200ms ease}
.mode-card:hover{border-color:#0b6623;background:#f8fcf9}
.mode-card.active{border-color:#0b6623;background:#f0fbf3;box-shadow:0 3px 12px rgba(11,102,35,.08)}
.mode-icon{font-size:24px;line-height:1}
.mode-title{display:block;font-size:14px;font-weight:700;color:#073b12}
.mode-desc{display:block;font-size:11.5px;color:#5c6e5e;margin-top:2px}
@keyframes fadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:700px){.mode-switch,.offline-payment-grid{grid-template-columns:1fr}}
@media(max-width:640px){.offline-payment-form .actions>*{flex:1}}
</style>';

echo '<div class="subhead"><div><a class="button button-light" href="offline-payers.php" style="width:auto;min-height:36px;padding:8px 12px">Back to Offline Payers</a><h1 style="margin-top:10px">Add offline payer</h1><p class="muted">Record a payment you have already confirmed in the bank, in cash, or through another offline channel. It will appear in Paid forms immediately.</p></div></div>';

echo '<section class="step-card" style="background:#fff;border:1.5px solid #dce5dc;border-radius:14px;padding:24px;margin-bottom:22px;box-shadow:0 4px 14px rgba(18,45,22,.04)">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">
    <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#073b12;color:#fff;font-weight:800;font-size:14px">1</span>
    <div>
      <h2 style="font-size:18px;font-weight:750;color:#073b12;margin:0">Registration Type</h2>
      <div style="font-size:12.5px;color:#5c6e5e;margin-top:2px">Choose how you are registering.</div>
    </div>
  </div>
  <div class="mode-switch" style="margin:0">
    <div class="mode-card active" style="display:flex;align-items:center;justify-content:space-between;border:2px solid #073b12;background:#f0fbf3">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="mode-icon" style="background:#e5f2e6;color:#073b12;width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
        <div>
          <span class="mode-title" style="font-size:14.5px;font-weight:750;color:#073b12">Single Registration</span>
          <span class="mode-desc" style="font-size:12px;color:#5c6e5e">Register as an individual.</span>
        </div>
      </div>
      <div style="width:18px;height:18px;border-radius:50%;border:5px solid #073b12;background:#fff"></div>
    </div>
    <a class="mode-card" href="add-bulk-offline-payment.php" style="display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="mode-icon" style="background:#eef6ed;color:#0b6623;width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
        <div>
          <span class="mode-title" style="font-size:14.5px;font-weight:750;color:#073b12">Bulk Student Registration</span>
          <span class="mode-desc" style="font-size:12px;color:#5c6e5e">Register multiple students.</span>
        </div>
      </div>
      <div style="width:18px;height:18px;border-radius:50%;border:2px solid #cad5ca;background:#fff"></div>
    </a>
  </div>
</section>';

if ($notice !== '') echo '<div class="notice">' . admin_h($notice) . '</div>';
if ($error !== '') echo '<div class="notice error">' . admin_h($error) . '</div>';
$csrf = admin_h(admin_csrf_token());

echo '<section class="panel panel-pad"><form method="post" class="form-stack offline-payment-form"><input type="hidden" name="csrf_token" value="' . $csrf . '"><div class="offline-payment-grid">';
echo '<label>Form type<select name="form_type" required>';
foreach (['convention' => 'Convention', 'sponsorship' => 'Sponsorship', 'pageant' => 'Mr & Miss NACOS'] as $key => $label) {
    echo '<option value="' . $key . '"' . ($values['form_type'] === $key ? ' selected' : '') . '>' . $label . '</option>';
}
echo '</select></label><label>Package<select name="package_name" id="offline-package" required><option value="">Select a package</option>';
foreach ($packageOptions as $formType => $options) {
    echo '<optgroup label="' . admin_h(ucwords($formType)) . ' packages" data-form-type="' . admin_h($formType) . '">';
    foreach ($options as $package => $price) {
        $selected = $values['package_name'] === $package ? ' selected' : '';
        $priceAttribute = $price === null ? '' : ' data-price="' . (int) $price . '"';
        $label = $price === null ? $package . ' — enter amount' : $package . ' — ₦' . number_format((int) $price);
        echo '<option value="' . admin_h($package) . '"' . $priceAttribute . $selected . '>' . admin_h($label) . '</option>';
    }
    echo '</optgroup>';
}
$customSelected = $values['package_name'] === '__custom' ? ' selected' : '';
echo '<option value="__custom"' . $customSelected . '>Other / custom package</option></select></label>';
$isCustom = $values['package_name'] === '__custom';
$customDisplay = $isCustom ? 'style="grid-column: 1 / -1"' : 'style="grid-column: 1 / -1; display: none"';
$customDisabled = $isCustom ? '' : ' disabled';
$customRequired = $isCustom ? ' required' : '';
echo '<label id="custom-package-wrap" ' . $customDisplay . '>Custom package description<input name="custom_package" id="custom-package-input" maxlength="160" value="' . admin_h($values['custom_package']) . '" placeholder="Enter the package description"' . $customDisabled . $customRequired . '></label>';
echo '<label>Payer full name<input name="applicant_name" id="offline-name" maxlength="180" required value="' . admin_h($values['applicant_name']) . '" placeholder="e.g. Chukwudi Emeka"></label>';
echo '<label>School / Institution<input name="institution" id="offline-institution" maxlength="200" value="' . admin_h($values['institution']) . '" placeholder="e.g. UNN, ESUT, IMSU, AEFUNAI"></label>';
echo '<label>Payer email<input type="email" name="applicant_email" id="offline-email" maxlength="254" required value="' . admin_h($values['applicant_email']) . '" placeholder="e.g. chukwudi@example.com"></label>';
echo '<label>Phone number<input name="phone" id="offline-phone" maxlength="50" required value="' . admin_h($values['phone']) . '" placeholder="e.g. 08012345678"></label>';
echo '<label>Category (optional)<input name="category" maxlength="120" value="' . admin_h($values['category']) . '" placeholder="e.g. Mr NACOS"></label>';
echo '<label>Amount paid (₦)<input id="offline-amount" name="amount" inputmode="decimal" required value="' . admin_h($values['amount']) . '" placeholder="10000.00"></label>';
echo '<label>Date and time paid<input type="datetime-local" name="paid_at" required value="' . admin_h($values['paid_at']) . '"></label>';
echo '</div>';

// Server-side or initial duplicate alert container
$hasServerDup = !empty($duplicateMatches);
$boxStyle = $hasServerDup ? '' : 'display:none;';
echo '<div id="duplicate-alert-box" class="duplicate-alert-box" style="' . $boxStyle . '">';
echo '<div class="duplicate-alert-header"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><span>Possible Duplicate Found</span></div>';
echo '<p class="duplicate-alert-text">A paid registration already exists with this email or phone number in the database:</p>';
echo '<div id="duplicate-matches-list" class="duplicate-matches-list">';
if ($hasServerDup) {
    foreach ($duplicateMatches as $match) {
        $dateStr = date('M j, Y, g:i a', strtotime((string) ($match['payment_paid_at'] ?: $match['created_at'])));
        $amtStr = number_format(((int) $match['amount_kobo']) / 100, 2);
        echo '<div class="duplicate-match-item"><div><strong>' . admin_h((string) $match['applicant_name']) . '</strong> · <span class="muted">' . admin_h((string) $match['applicant_email']) . ' · ' . admin_h((string) $match['phone']) . '</span><br><span class="muted">' . admin_h((string) $match['package_name']) . ' (₦' . $amtStr . ') · Ref: ' . admin_h((string) $match['payment_reference']) . ' · ' . $dateStr . '</span></div><a href="paid-registration.php?id=' . (int) $match['id'] . '" target="_blank" rel="noopener">View record ↗</a></div>';
    }
}
echo '</div>';
$checkedAttr = $allowDuplicate ? ' checked' : '';
echo '<label class="duplicate-checkbox-label"><input type="checkbox" name="allow_duplicate" id="allow-duplicate" value="1"' . $checkedAttr . '><span>I confirm this is an intentional additional ticket / payment for this attendee.</span></label>';
echo '</div>';

echo '<label>Registration details (optional)<textarea name="details" maxlength="20000" placeholder="Institution, attendee names, or other details to keep with this record">' . admin_h($values['details']) . '</textarea></label>';
echo '<label>Admin notes (optional)<textarea name="admin_notes" maxlength="5000" placeholder="Internal notes about how the payment was received">' . admin_h($values['admin_notes']) . '</textarea></label>';
echo '<p class="muted">This action immediately adds a paid record. Only use it after confirming the payment. A payment confirmation email will be attempted after saving.</p>';
echo '<div class="actions"><button type="button" id="record-payment-trigger">Record payment</button><a class="button button-light" href="manual-payments.php">Cancel</a></div>';
echo '</form></section>';

echo '<style>
.offline-confirm-dialog{width:min(480px,calc(100vw - 32px));border:0;border-radius:16px;padding:26px;color:#173322;box-shadow:0 24px 80px rgba(0,0,0,.28)}
.offline-confirm-dialog::backdrop{background:rgba(5,20,11,.62)}
.offline-confirm-dialog h2{margin:0 0 10px;font-size:22px}
.offline-confirm-dialog p{margin:0;color:#5e7064;line-height:1.55}
.offline-confirm-dialog .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px}
.offline-confirm-dialog button{min-height:44px;padding:0 18px;border-radius:9px;border:1px solid #d4e0d6;background:#fff;color:#264632;font:inherit;font-weight:700;cursor:pointer}
.offline-confirm-dialog .dialog-dup-warning{margin-top:14px;padding:12px 14px;border-radius:8px;background:#eef8f1;border:1px solid #bfe3c7;color:#073b12;font-size:12.5px;font-weight:600}
@media(max-width:480px){.offline-confirm-dialog{padding:22px}.offline-confirm-dialog .actions{flex-direction:column-reverse}.offline-confirm-dialog .actions button{width:100%}}
</style>
<dialog class="offline-confirm-dialog" id="offline-confirm-dialog" aria-labelledby="offline-confirm-title">
  <h2 id="offline-confirm-title">Confirm offline payment</h2>
  <p id="offline-confirm-message">Have you verified that this payment was received? Confirming will immediately add it to Paid forms.</p>
  <div id="offline-confirm-dup-note" class="dialog-dup-warning" style="display:none">Notice: An existing registration was found for this person. You are recording an additional ticket.</div>
  <div class="actions">
    <button type="button" id="offline-confirm-cancel">Cancel</button>
    <button type="button" class="confirm" id="offline-confirm-submit">Confirm and record payment</button>
  </div>
</dialog>';

echo '<script>
(function(){
  const form = document.querySelector(".offline-payment-form");
  const trigger = document.getElementById("record-payment-trigger");
  const dialog = document.getElementById("offline-confirm-dialog");
  const cancelBtn = document.getElementById("offline-confirm-cancel");
  const submitBtn = document.getElementById("offline-confirm-submit");
  const dupBox = document.getElementById("duplicate-alert-box");
  const dupList = document.getElementById("duplicate-matches-list");
  const allowDupCheck = document.getElementById("allow-duplicate");
  const dupNote = document.getElementById("offline-confirm-dup-note");
  const emailInput = document.getElementById("offline-email");
  const phoneInput = document.getElementById("offline-phone");

  let hasDuplicateMatches = ' . ($hasServerDup ? 'true' : 'false') . ';
  let checkDebounceTimer = null;

  function escapeHtml(str){
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  function checkDuplicates(){
    const email = (emailInput.value || "").trim();
    const phone = (phoneInput.value || "").trim();
    if (!email && !phone) {
      dupBox.style.display = "none";
      hasDuplicateMatches = false;
      return;
    }
    const params = new URLSearchParams({ action: "check_duplicate", email: email, phone: phone });
    fetch("add-offline-payment.php?" + params.toString())
      .then(res => res.json())
      .then(data => {
        if (data.found && data.matches.length > 0) {
          hasDuplicateMatches = true;
          let html = "";
          data.matches.forEach(m => {
            html += `<div class="duplicate-match-item">
              <div>
                <strong>${escapeHtml(m.name)}</strong> · <span class="muted">${escapeHtml(m.email)} · ${escapeHtml(m.phone)}</span><br>
                <span class="muted">${escapeHtml(m.package)} (₦${escapeHtml(m.amount)}) · Ref: ${escapeHtml(m.reference)} · ${escapeHtml(m.date)}</span>
              </div>
              <a href="paid-registration.php?id=${encodeURIComponent(m.id)}" target="_blank" rel="noopener">View record ↗</a>
            </div>`;
          });
          dupList.innerHTML = html;
          dupBox.style.display = "";
        } else {
          hasDuplicateMatches = false;
          dupBox.style.display = "none";
          allowDupCheck.checked = false;
        }
      })
      .catch(err => console.error("Duplicate check error:", err));
  }

  function scheduleDuplicateCheck(){
    clearTimeout(checkDebounceTimer);
    checkDebounceTimer = setTimeout(checkDuplicates, 350);
  }

  emailInput.addEventListener("input", scheduleDuplicateCheck);
  phoneInput.addEventListener("input", scheduleDuplicateCheck);
  emailInput.addEventListener("blur", checkDuplicates);
  phoneInput.addEventListener("blur", checkDuplicates);

  cancelBtn.addEventListener("click", () => dialog.close());

  trigger.addEventListener("click", () => {
    if (!form.reportValidity()) return;
    if (hasDuplicateMatches && !allowDupCheck.checked) {
      dupBox.scrollIntoView({ behavior: "smooth", block: "center" });
      dupBox.style.outline = "3px solid #dc2626";
      setTimeout(() => { dupBox.style.outline = ""; }, 2500);
      alert("A paid record with this email or phone number already exists! Please review the duplicate warning and check \'I confirm this is an intentional additional ticket\' if you want to proceed.");
      return;
    }
    if (hasDuplicateMatches && allowDupCheck.checked) {
      dupNote.style.display = "";
    } else {
      dupNote.style.display = "none";
    }
    dialog.showModal();
  });

  submitBtn.addEventListener("click", () => {
    dialog.close();
    form.requestSubmit();
  });
})();
</script>';

echo '<script>
(function(){
  const type = document.querySelector("select[name=form_type]");
  const packages = document.getElementById("offline-package");
  const amount = document.getElementById("offline-amount");
  const customWrap = document.getElementById("custom-package-wrap");
  const customInput = customWrap.querySelector("input");

  function sync(){
    packages.querySelectorAll("optgroup[data-form-type]").forEach(group => {
      const active = group.dataset.formType === type.value;
      group.hidden = !active;
      group.disabled = !active;
    });
    const selected = packages.selectedOptions[0];
    if (selected && selected.parentElement.tagName === "OPTGROUP" && selected.parentElement.dataset.formType !== type.value) {
      packages.value = "";
    }
    const isCustom = packages.value === "__custom";
    customWrap.style.display = isCustom ? "grid" : "none";
    customWrap.hidden = !isCustom;
    customInput.disabled = !isCustom;
    customInput.required = isCustom;
    const option = packages.selectedOptions[0];
    if (option && option.dataset.price && amount.value.trim() === "") {
      amount.value = option.dataset.price;
    }
  }

  type.addEventListener("change", function(){
    packages.value = "";
    amount.value = "";
    sync();
  });

  packages.addEventListener("change", function(){
    const isCustom = packages.value === "__custom";
    customWrap.style.display = isCustom ? "grid" : "none";
    customWrap.hidden = !isCustom;
    customInput.disabled = !isCustom;
    customInput.required = isCustom;
    const option = packages.selectedOptions[0];
    if (option && option.dataset.price) {
      amount.value = option.dataset.price;
    } else if (isCustom) {
      amount.value = "";
      customInput.focus();
    }
  });

  sync();
})();
</script>';

admin_page_end();
