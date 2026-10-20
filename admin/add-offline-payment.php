<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/manual-payment-mail.php';
admin_require_login();

$pdo = nacos_db();
$error = '';
$notice = '';
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
    'form_type' => 'convention', 'applicant_name' => '', 'applicant_email' => '', 'phone' => '',
    'category' => '', 'package_name' => '', 'custom_package' => '', 'amount' => '', 'paid_at' => date('Y-m-d\TH:i'),
    'details' => '', 'admin_notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $_) {
        $posted = $_POST[$key] ?? '';
        $values[$key] = is_string($posted) ? trim($posted) : '';
    }

    $packageName = $values['package_name'] === '__custom' ? $values['custom_package'] : $values['package_name'];

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
    } elseif (strlen($values['category']) > 120 || strlen($values['details']) > 20000 || strlen($values['admin_notes']) > 5000) {
        $error = 'One of the optional fields is too long.';
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
                        'form_type' => 'convention', 'applicant_name' => '', 'applicant_email' => '', 'phone' => '',
                        'category' => '', 'package_name' => '', 'custom_package' => '', 'amount' => '', 'paid_at' => date('Y-m-d\TH:i'),
                        'details' => '', 'admin_notes' => '',
                    ];
                } catch (Throwable $exception) {
                    error_log('Admin offline payment entry failed: ' . $exception->getMessage());
                    $error = 'The payment could not be saved. Check the database fields and try again.';
                }
            }
        }
    }
}

admin_page_start('Add offline payer');
echo '<style>.offline-payment-form{gap:20px}.offline-payment-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 20px}.offline-payment-form label{display:grid;gap:6px;color:#344638;font-size:13px;font-weight:600}.offline-payment-form input,.offline-payment-form select,.offline-payment-form textarea{display:block;width:100%;min-width:0;margin:0;min-height:46px}.offline-payment-form textarea{min-height:112px;resize:vertical}.offline-payment-form .actions{margin-top:4px}@media(max-width:640px){.offline-payment-grid{grid-template-columns:1fr}.offline-payment-form .actions>*{flex:1}}</style>';

echo '<div class="subhead"><div><a class="muted" href="manual-payments.php">← Manual payment review</a><h1 style="margin-top:10px">Add offline payer</h1><p class="muted">Record a payment you have already confirmed in the bank, in cash, or through another offline channel. It will appear in Paid forms immediately.</p></div></div>';
if ($notice !== '') echo '<div class="notice">' . admin_h($notice) . '</div>';
if ($error !== '') echo '<div class="notice error">' . admin_h($error) . '</div>';
$csrf = admin_h(admin_csrf_token());
echo '<section class="panel panel-pad"><form method="post" class="form-stack offline-payment-form"><input type="hidden" name="csrf_token" value="' . $csrf . '"><div class="offline-payment-grid">';
echo '<label>Form type<select name="form_type" required>';
foreach (['convention' => 'Convention', 'sponsorship' => 'Sponsorship', 'pageant' => 'Mr & Miss NACOS'] as $key => $label) echo '<option value="' . $key . '"' . ($values['form_type'] === $key ? ' selected' : '') . '>' . $label . '</option>';
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
$customVisible = $values['package_name'] === '__custom' ? '' : ' hidden';
$customDisabled = $values['package_name'] === '__custom' ? '' : ' disabled';
echo '<label id="custom-package-wrap"' . $customVisible . '>Custom package description<input name="custom_package" maxlength="160" value="' . admin_h($values['custom_package']) . '" placeholder="Enter the package description"' . $customDisabled . '></label>';
echo '<label>Payer full name<input name="applicant_name" maxlength="180" required value="' . admin_h($values['applicant_name']) . '"></label><label>Payer email<input type="email" name="applicant_email" maxlength="254" required value="' . admin_h($values['applicant_email']) . '"></label>';
echo '<label>Phone number<input name="phone" maxlength="50" required value="' . admin_h($values['phone']) . '"></label><label>Category (optional)<input name="category" maxlength="120" value="' . admin_h($values['category']) . '" placeholder="e.g. Mr NACOS"></label>';
echo '<label>Amount paid (₦)<input id="offline-amount" name="amount" inputmode="decimal" required value="' . admin_h($values['amount']) . '" placeholder="10000.00"></label><label>Date and time paid<input type="datetime-local" name="paid_at" required value="' . admin_h($values['paid_at']) . '"></label>';
echo '</div><label>Registration details (optional)<textarea name="details" maxlength="20000" placeholder="Institution, attendee names, or other details to keep with this record">' . admin_h($values['details']) . '</textarea></label><label>Admin notes (optional)<textarea name="admin_notes" maxlength="5000" placeholder="Internal notes about how the payment was received">' . admin_h($values['admin_notes']) . '</textarea></label><p class="muted">This action immediately adds a paid record. Only use it after confirming the payment. A payment confirmation email will be attempted after saving.</p><div class="actions"><button type="button" id="record-payment-trigger">Record payment</button><a class="button button-light" href="manual-payments.php">Cancel</a></div></form></section>';
echo '<style>.offline-confirm-dialog{width:min(460px,calc(100vw - 32px));border:0;border-radius:16px;padding:26px;color:#173322;box-shadow:0 24px 80px rgba(0,0,0,.28)}.offline-confirm-dialog::backdrop{background:rgba(5,20,11,.62)}.offline-confirm-dialog h2{margin:0 0 10px;font-size:22px}.offline-confirm-dialog p{margin:0;color:#5e7064;line-height:1.55}.offline-confirm-dialog .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px}.offline-confirm-dialog button{min-height:44px;padding:0 18px;border-radius:9px;border:1px solid #d4e0d6;background:#fff;color:#264632;font:inherit;font-weight:700;cursor:pointer}.offline-confirm-dialog button.confirm{background:#096b28;border-color:#096b28;color:#fff}@media(max-width:480px){.offline-confirm-dialog{padding:22px}.offline-confirm-dialog .actions{flex-direction:column-reverse}.offline-confirm-dialog .actions button{width:100%}}</style><dialog class="offline-confirm-dialog" id="offline-confirm-dialog" aria-labelledby="offline-confirm-title"><h2 id="offline-confirm-title">Confirm offline payment</h2><p>Have you verified that this payment was received? Confirming will immediately add it to Paid forms.</p><div class="actions"><button type="button" id="offline-confirm-cancel">Cancel</button><button type="button" class="confirm" id="offline-confirm-submit">Confirm and record payment</button></div></dialog><script>(function(){const form=document.querySelector(".offline-payment-form");const trigger=document.getElementById("record-payment-trigger");const dialog=document.getElementById("offline-confirm-dialog");document.getElementById("offline-confirm-cancel").addEventListener("click",()=>dialog.close());trigger.addEventListener("click",()=>{if(form.reportValidity())dialog.showModal();});document.getElementById("offline-confirm-submit").addEventListener("click",()=>{dialog.close();form.requestSubmit();});})();</script>'; echo '<script>(function(){const type=document.querySelector("select[name=form_type]");const packages=document.getElementById("offline-package");const amount=document.getElementById("offline-amount");const customWrap=document.getElementById("custom-package-wrap");const customInput=customWrap.querySelector("input");function sync(){packages.querySelectorAll("optgroup[data-form-type]").forEach(group=>{const active=group.dataset.formType===type.value;group.hidden=!active;group.disabled=!active;});const selected=packages.selectedOptions[0];if(selected&&selected.parentElement.tagName==="OPTGROUP"&&selected.parentElement.dataset.formType!==type.value){packages.value="";}const isCustom=packages.value==="__custom";customWrap.hidden=!isCustom;customInput.disabled=!isCustom;customInput.required=isCustom;const option=packages.selectedOptions[0];if(option&&option.dataset.price&&amount.value.trim()===""){amount.value=option.dataset.price;}}type.addEventListener("change",function(){packages.value="";amount.value="";sync();});packages.addEventListener("change",function(){const isCustom=packages.value==="__custom";customWrap.hidden=!isCustom;customInput.disabled=!isCustom;customInput.required=isCustom;const option=packages.selectedOptions[0];if(option&&option.dataset.price){amount.value=option.dataset.price;}else if(isCustom){amount.value="";}});sync();})();</script>';
admin_page_end();
