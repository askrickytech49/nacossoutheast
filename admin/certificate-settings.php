<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/email-templates.php';
admin_require_login();

$pdo = nacos_db();
$notice = '';
$error = '';

// Load current settings
$subject = 'Certificate of Participation - NACOS South East Convention 2026';
$body = "Dear [Name],\n\nWe extend our heartfelt appreciation to you for being a part of the NACOS South East Convention 2026, held from 20th - 24th October 2026 at Ogbonnaya Onu Polytechnic Aba, Abia State. Your active participation contributed immensely to the success of this landmark event.\n\nPlease find attached your Certificate of Participation, issued in recognition of your participation in the Convention. We encourage you to proudly display and share this certificate as a testament to your commitment to advancing the prospects of computing around the student community in Nigeria and world at large.\n\nThank you once again for your presence, enthusiasm, and support. We look forward to seeing you at future NACOS programs and initiatives.\n\nAccept the assurances of our regards.";

try {
    $stmt = $pdo->query("SELECT email_subject, email_body FROM certificate_email_settings WHERE id = 1 LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $subject = (string) $row['email_subject'];
        $body = (string) $row['email_body'];
    }
} catch (Throwable $e) {
    // If table doesn't exist yet, we can create or notice
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!admin_verify_csrf()) {
        $error = 'This session expired. Refresh the page and try again.';
    } else {
        $newSubject = trim((string) ($_POST['email_subject'] ?? ''));
        $newBody = trim((string) ($_POST['email_body'] ?? ''));

        if ($newSubject === '') {
            $error = 'Please enter an email subject.';
        } elseif ($newBody === '') {
            $error = 'Please enter the email message body.';
        } else {
            try {
                $save = $pdo->prepare('INSERT INTO certificate_email_settings (id, email_subject, email_body) VALUES (1, :sub, :body) ON DUPLICATE KEY UPDATE email_subject = VALUES(email_subject), email_body = VALUES(email_body)');
                $save->execute(['sub' => $newSubject, 'body' => $newBody]);
                $subject = $newSubject;
                $body = $newBody;
                $notice = 'Certificate email template settings saved successfully.';
            } catch (Throwable $exception) {
                $error = 'Could not save settings: ' . $exception->getMessage();
            }
        }
    }
}

admin_page_start('Certificate email settings');
$csrf = admin_h(admin_csrf_token());

// Generate sample preview
$previewTemplate = nacos_certificate_email_template('John Doe', [
    ['name' => 'JOHN DOE', 'token' => 'sample-preview-token-12345', 'institution' => 'University of Nigeria, Nsukka']
], $subject, $body);

?>
<style>
.settings-tabs{display:flex;gap:8px;margin-bottom:20px;border-bottom:2px solid #dbe5db;padding-bottom:12px}
.settings-tab{display:inline-flex;align-items:center;justify-content:center;gap:8px;flex:1;padding:12px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:#536357;background:#fff;border:1px solid #dce5dc;transition:all .15s;text-align:center;white-space:nowrap}
.settings-tab:hover{color:#073b12;border-color:#b5d7b3;background:#f8faf8}
.settings-tab.active{background:#0b6623;color:#fff;border-color:#0b6623}
.settings-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(360px,.85fr);gap:20px;min-width:0}
.settings-grid>*{min-width:0}
.preview-card{background:#fff;border:1px solid #dfe7df;border-radius:12px;padding:24px;box-shadow:0 3px 12px rgba(18,45,22,.035);min-width:0}
.preview-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #eef2ee;gap:8px;flex-wrap:wrap}
.preview-badge{font-size:11px;font-weight:700;color:#0b6623;background:#eef6ed;padding:4px 9px;border-radius:999px;letter-spacing:.5px}
.preview-shell{border:1px solid #e0e7df;border-radius:8px;background:#ffffff;padding:20px;font-family:Arial,Helvetica,sans-serif;overflow-wrap:anywhere;word-break:break-word}
@media(max-width:960px){.settings-grid{grid-template-columns:1fr}}
@media(max-width:680px){
  .settings-tabs{flex-direction:column;gap:8px}
  .settings-tab{width:100%;white-space:normal;padding:11px 14px}
  .preview-card{padding:16px}
  .preview-shell{padding:14px}
  .form-stack .actions button{width:100%}
}
</style>

<div class="subhead">
  <div>
    <h1>System Settings</h1>
    <p class="muted">Manage certificate email templates and payment gateways.</p>
  </div>
</div>

<nav class="settings-tabs">
  <a class="settings-tab" href="payment-settings.php">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    Payment Methods
  </a>
  <a class="settings-tab active" href="certificate-settings.php">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    Certificate Email Template
  </a>
</nav>

<?php if ($notice !== ''): ?><div class="notice"><?= admin_h($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= admin_h($error) ?></div><?php endif; ?>

<div class="settings-grid">
  <!-- Form Column -->
  <section class="panel panel-pad">
    <h2 style="margin-top:0">Certificate Email Message</h2>
    <p class="muted" style="margin-bottom:18px">Customize the message that will accompany certificate delivery emails sent to attendees or institutional bulk representatives.</p>

    <form method="post" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      
      <label>Email Subject
        <input type="text" name="email_subject" value="<?= admin_h($subject) ?>" required style="width:100%;font-weight:600">
      </label>

      <label>Email Message Body
        <textarea name="email_body" rows="12" required style="width:100%;font-family:inherit;line-height:1.6;padding:12px;min-height:260px"><?= admin_h($body) ?></textarea>
      </label>

      <div style="background:#f4f8f4;border:1px solid #d5e5d5;padding:12px 16px;border-radius:8px;font-size:12px;color:#28482d;line-height:1.6">
        <strong>Dynamic Placeholder:</strong>
        <p style="margin:4px 0 0">Use <code>[Name]</code> anywhere in your message (e.g. <code>Dear [Name],</code>). When sending a certificate to an individual, it will be automatically replaced by the attendee's name. For bulk delegations, it will use the lead representative's name.</p>
      </div>

      <div class="actions" style="margin-top:8px">
        <button type="submit">Save email settings</button>
      </div>
    </form>
  </section>

  <!-- Live Preview Column -->
  <aside class="preview-card">
    <div class="preview-header">
      <div>
        <h3 style="margin:0;font-size:15px;color:#17251a">Recipient Email Preview</h3>
        <small class="muted">Live preview of how recipients receive the email</small>
      </div>
      <span class="preview-badge">LIVE PREVIEW</span>
    </div>

    <div style="margin-bottom:12px;font-size:12px;color:#536357;background:#f8faf8;padding:8px 12px;border:1px solid #eef2ee;border-radius:6px">
      <strong>Subject:</strong> <?= admin_h($subject) ?>
    </div>

    <div class="preview-shell">
      <div style="text-align:center;margin-bottom:20px">
        <img src="../images/Logo-removebg-preview.png" alt="NACOS Banner" width="420" style="display:block;width:100%;max-width:420px;height:auto;margin:0 auto">
      </div>

      <div style="font-size:13px;line-height:1.65;color:#18251b">
        <?= nl2br(admin_h(str_ireplace('[Name]', 'John Doe', $body))) ?>
      </div>

      <!-- Sample certificate button -->
      <div style="margin:22px 0;text-align:center">
        <div style="display:inline-block;padding:12px 24px;background:#0b6623;color:#ffffff;font-size:13px;font-weight:700;border-radius:7px;pointer-events:none">
          Download Certificate (PDF)
        </div>
      </div>

      <!-- Disclaimer preview -->
      <div style="margin-top:24px;border-top:1px dashed #d5ded5;padding-top:12px;font-size:8.5px;line-height:1.4;color:#c02b2b;text-align:center;font-family:Courier New,monospace;overflow-wrap:anywhere;word-break:break-all">
        ****************************************************************<br>
        This communication, including attachments, is for the exclusive use of addressee and MAY contain proprietary, confidential and/or privileged information...<br>
        ****************************************************************
      </div>
    </div>
  </aside>
</div>

<?php
admin_page_end();
