<?php
/**
 * Bulk Certificate Email Sender - Admin Tool
 * Open this page in the browser to send bulk certificate emails with PDF attachments
 */
declare(strict_types=1);

require_once __DIR__ . '/admin/auth.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: admin/login.php');
    exit;
}

$registrationId = 42; // Clifford University
$recipientEmail = 'rickyessential49@gmail.com';

require_once __DIR__ . '/api/db.php';
$pdo = nacos_db();

$regStmt = $pdo->prepare("SELECT * FROM paid_registrations WHERE id = :id AND payment_status = 'paid' LIMIT 1");
$regStmt->execute(['id' => $registrationId]);
$reg = $regStmt->fetch(PDO::FETCH_ASSOC);

$certStmt = $pdo->prepare("SELECT * FROM paid_certificate_verifications WHERE paid_registration_id = :reg_id ORDER BY attendee_index ASC, id ASC");
$certStmt->execute(['reg_id' => $registrationId]);
$certs = $certStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = admin_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Send Bulk Certificate Email</title>
<style>
body { font-family: Arial, sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; background: #f4f9f4; }
.card { background: #fff; border: 1px solid #d5e4d5; border-radius: 12px; padding: 24px; margin-bottom: 20px; }
h1 { color: #073b12; font-size: 22px; }
.info { color: #536357; font-size: 14px; margin: 8px 0; }
#status { margin-top: 16px; padding: 12px; border-radius: 8px; font-size: 14px; display: none; }
.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.progress { background: #e5f5e7; color: #175b26; border: 1px solid #c3e6c8; }
button { background: #0b6623; color: #fff; border: 0; padding: 12px 24px; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
.cert-count { font-size: 28px; font-weight: 800; color: #0b6623; }
</style>
</head>
<body>
<div class="card">
  <h1>📧 Send Bulk Certificate Email</h1>
  <p class="info"><strong>Registration:</strong> <?= htmlspecialchars($reg['applicant_name']) ?> (ID #<?= $registrationId ?>)</p>
  <p class="info"><strong>Certificates:</strong> <span class="cert-count"><?= count($certs) ?></span> attendees</p>
  <p class="info"><strong>Sending to:</strong> <?= htmlspecialchars($recipientEmail) ?></p>
  <hr style="margin:20px 0;border:none;border-top:1px solid #e5e5e5">
  <button id="sendBtn" onclick="startSend()">Send All <?= count($certs) ?> Certificates with PDF Attachments</button>
  <div id="status"></div>
</div>

<script src="assets/js/qrious.min.js"></script>
<script src="assets/js/html2canvas.min.js"></script>
<script src="assets/js/jspdf.umd.min.js"></script>
<script>
const certs = <?= json_encode(array_values(array_map(function($c) {
    return ['id' => (int)$c['id'], 'name' => (string)$c['attendee_name'], 'token' => (string)$c['token']];
}, $certs)), JSON_UNESCAPED_UNICODE) ?>;

const csrfToken = "<?= htmlspecialchars($csrfToken) ?>";
const regId = <?= $registrationId ?>;
const email = "<?= htmlspecialchars($recipientEmail) ?>";

function setStatus(msg, type) {
  const el = document.getElementById("status");
  el.style.display = "block";
  el.className = type;
  el.textContent = msg;
}

function generateCertPdf(token) {
  return new Promise((resolve, reject) => {
    const iframe = document.createElement("iframe");
    iframe.style.cssText = "position:fixed;width:1200px;height:850px;left:-9999px;top:-9999px;opacity:0;pointer-events:none";
    iframe.setAttribute("aria-hidden", "true");

    let done = false;
    function cleanup() {
      if (done) return;
      done = true;
      window.removeEventListener("message", onMsg);
      if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
    }

    const timer = setTimeout(() => { cleanup(); reject(new Error("Timeout")); }, 30000);

    function onMsg(ev) {
      if (!ev.data) return;
      if (ev.data.type === "NACOS_CERT_PDF" && ev.data.token === token) {
        clearTimeout(timer); cleanup();
        resolve({ name: ev.data.name, content: ev.data.base64 });
      } else if (ev.data.type === "NACOS_CERT_ERROR" && ev.data.token === token) {
        clearTimeout(timer); cleanup();
        reject(new Error(ev.data.error || "Failed"));
      }
    }

    window.addEventListener("message", onMsg);
    iframe.src = "verify-certificate.php?token=" + encodeURIComponent(token) + "&download=pdf&export=base64";
    document.body.appendChild(iframe);
  });
}

async function startSend() {
  const btn = document.getElementById("sendBtn");
  btn.disabled = true;

  const attachments = [];
  for (let i = 0; i < certs.length; i++) {
    setStatus("Generating PDF " + (i + 1) + " of " + certs.length + " (" + certs[i].name + ")...", "progress");
    try {
      const pdf = await generateCertPdf(certs[i].token);
      if (pdf && pdf.content) attachments.push(pdf);
    } catch (err) {
      console.warn("Failed for " + certs[i].name, err);
    }
    // Small delay between renders
    await new Promise(r => setTimeout(r, 300));
  }

  setStatus("Generated " + attachments.length + " PDFs. Sending email to " + email + "...", "progress");

  const params = new URLSearchParams();
  params.append("csrf_token", csrfToken);
  params.append("registration_id", String(regId));
  params.append("recipient_email", email);
  if (attachments.length > 0) {
    params.append("attachments", JSON.stringify(attachments));
  }

  try {
    const res = await fetch("api/send-certificate-email.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString()
    });
    const data = await res.json();
    if (res.ok && data.success) {
      setStatus("✓ " + (data.message || "Email sent successfully!"), "success");
    } else {
      setStatus("✕ " + (data.error || "Failed to send."), "error");
    }
  } catch (err) {
    setStatus("✕ Network error: " + err.message, "error");
  }

  btn.disabled = false;
}
</script>
</body>
</html>
