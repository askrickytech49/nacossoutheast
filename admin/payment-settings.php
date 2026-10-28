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
?>
<style>
.settings-tabs{display:flex;gap:8px;margin-bottom:20px;border-bottom:2px solid #dbe5db;padding-bottom:12px}
.settings-tab{display:inline-flex;align-items:center;justify-content:center;gap:8px;flex:1;padding:12px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:#536357;background:#fff;border:1px solid #dce5dc;transition:all .15s;text-align:center;white-space:nowrap}
.settings-tab:hover{color:#073b12;border-color:#b5d7b3;background:#f8faf8}
.settings-tab.active{background:#0b6623;color:#fff;border-color:#0b6623}
.payment-toggle{display:flex;align-items:center;gap:10px;padding:14px;border:1px solid #dfe7df;border-radius:10px;background:#fff}
.payment-toggle input{width:18px;height:18px;margin:0;accent-color:#0b6623}
.payment-settings-form select{width:100%;margin-top:6px}
@media(max-width:680px){
  .settings-tabs{flex-direction:column;gap:8px}
  .settings-tab{width:100%;white-space:normal;padding:11px 14px}
}
</style>

<div class="subhead">
  <div>
    <h1>System Settings</h1>
    <p class="muted">Manage certificate email templates and payment gateways.</p>
  </div>
</div>

<nav class="settings-tabs">
  <a class="settings-tab active" href="payment-settings.php">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    Payment Methods
  </a>
  <a class="settings-tab" href="certificate-settings.php">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    Certificate Email Template
  </a>
</nav>

<?php if ($notice !== ''): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>

<section class="panel panel-pad" style="max-width:720px">
  <h2 style="margin-top:0">Payment Gateways & Methods</h2>
  <form method="post" class="form-stack payment-settings-form">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <label class="payment-toggle">
      <input type="checkbox" name="automatic_enabled" value="1"<?= $settings['automatic_enabled'] ? ' checked' : '' ?>>
      Automatic payment (Paystack)
    </label>
    <label class="payment-toggle">
      <input type="checkbox" name="manual_enabled" value="1"<?= $settings['manual_enabled'] ? ' checked' : '' ?>>
      Manual bank transfer with receipt review
    </label>
    <label>Default payment method
      <select name="default_method">
        <option value="automatic"<?= $settings['default_method'] === 'automatic' ? ' selected' : '' ?>>Automatic payment (Paystack)</option>
        <option value="manual"<?= $settings['default_method'] === 'manual' ? ' selected' : '' ?>>Manual bank transfer</option>
      </select>
    </label>
    <p class="muted">At least one method must remain enabled. If you disable the current default, the other enabled method becomes the default automatically.</p>
    <div class="actions">
      <button type="submit">Save payment settings</button>
    </div>
  </form>
</section>
<?php
admin_page_end();

