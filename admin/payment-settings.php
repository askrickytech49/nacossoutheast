<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/payment-method-settings.php';
admin_require_login();

$pdo = nacos_db();
$notice = '';
$error = '';
$settings = nacos_payment_method_settings($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf()) {
        $error = 'This session expired. Refresh the page and try again.';
    } else {
        $automatic = ($_POST['automatic_enabled'] ?? '') === '1';
        $manual = ($_POST['manual_enabled'] ?? '') === '1';
        $default = (string) ($_POST['default_method'] ?? '');
        if (!$automatic && !$manual) {
            $error = 'Keep at least one payment method enabled.';
        } else {
            if ($default === 'automatic' && !$automatic) $default = 'manual';
            if ($default === 'manual' && !$manual) $default = 'automatic';
            if (!in_array($default, ['automatic', 'manual'], true)) $default = $automatic ? 'automatic' : 'manual';
            try {
                $save = $pdo->prepare('INSERT INTO payment_method_settings (id, automatic_enabled, manual_enabled, default_method) VALUES (1, :automatic, :manual, :default_method) ON DUPLICATE KEY UPDATE automatic_enabled = VALUES(automatic_enabled), manual_enabled = VALUES(manual_enabled), default_method = VALUES(default_method)');
                $save->execute(['automatic' => (int) $automatic, 'manual' => (int) $manual, 'default_method' => $default]);
                $settings = ['automatic_enabled' => $automatic, 'manual_enabled' => $manual, 'default_method' => $default];
                $notice = 'Payment settings saved.';
            } catch (Throwable $exception) {
                $error = 'Could not save payment settings. Run api/migrate-payment-method-settings.sql on this database, then try again.';
            }
        }
    }
}

admin_page_start('Payment settings');
$csrf = admin_h(admin_csrf_token());
echo '<div class="subhead"><div><h1>Payment settings</h1><p class="muted">Choose which payment methods registrants can use and set the default.</p></div></div>';
if ($notice !== '') echo '<div class="notice">' . admin_h($notice) . '</div>';
if ($error !== '') echo '<div class="notice error">' . admin_h($error) . '</div>';
echo '<style>.payment-toggle{display:flex;align-items:center;gap:10px;padding:14px;border:1px solid #dfe7df;border-radius:10px;background:#fff}.payment-toggle input{width:18px;height:18px;margin:0;accent-color:#0b6623}.payment-settings-form select{width:100%;margin-top:6px}</style><section class="panel panel-pad"><form method="post" class="form-stack payment-settings-form"><input type="hidden" name="csrf_token" value="' . $csrf . '"><label class="payment-toggle"><input type="checkbox" name="automatic_enabled" value="1"' . ($settings['automatic_enabled'] ? ' checked' : '') . '> Automatic payment (Paystack)</label><label class="payment-toggle"><input type="checkbox" name="manual_enabled" value="1"' . ($settings['manual_enabled'] ? ' checked' : '') . '> Manual bank transfer with receipt review</label><label>Default payment method<select name="default_method"><option value="automatic"' . ($settings['default_method'] === 'automatic' ? ' selected' : '') . '>Automatic payment (Paystack)</option><option value="manual"' . ($settings['default_method'] === 'manual' ? ' selected' : '') . '>Manual bank transfer</option></select></label><p class="muted">At least one method must remain enabled. If you disable the current default, the other enabled method becomes the default automatically.</p><div class="actions"><button type="submit">Save payment settings</button></div></form></section>';
admin_page_end();
