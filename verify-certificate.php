<?php
declare(strict_types=1);
require_once __DIR__ . '/api/db.php';

$token = strtolower(trim((string) ($_GET['token'] ?? '')));
$record = null;
if (preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    try {
        $query = nacos_db()->prepare(
            "SELECT v.attendee_name, v.created_at, p.form_type, p.payment_status
             FROM paid_certificate_verifications v
             INNER JOIN paid_registrations p ON p.id = v.paid_registration_id
             WHERE v.token = :token AND p.payment_status = 'paid' AND p.form_type <> 'sponsorship'
             LIMIT 1"
        );
        $query->execute(['token' => $token]);
        $record = $query->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $error) {
        error_log('Certificate verification lookup failed: ' . $error->getMessage());
        http_response_code(503);
    }
}
$verified = is_array($record);
$downloadPdf = $verified && (string) ($_GET['download'] ?? '') === 'pdf';
$verificationHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if (!preg_match('/\A[a-z0-9.-]+(?::[0-9]{1,5})?\z/', $verificationHost)) $verificationHost = 'nacosoutheast.org.ng';
$verificationScheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$verificationPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/verify-certificate.php'), PHP_URL_PATH) ?: '/verify-certificate.php');
$verificationUrl = $verificationScheme . '://' . $verificationHost . $verificationPath . '?token=' . rawurlencode($token);
if (!$verified && http_response_code() !== 503) http_response_code(404);
function certificate_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$rawName = $verified ? trim((string) $record['attendee_name']) : '';
$name = function_exists('mb_strtoupper') ? mb_strtoupper($rawName, 'UTF-8') : strtoupper($rawName);
$nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
$nameFontSize = $verified ? max(32, min(56, (int) floor(920 / max(20, $nameLength * 0.62)))) : 56;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Cormorant+Garamond:wght@600;700&family=Libre+Baskerville:wght@400;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <title><?= $verified ? 'Certificate verified' : 'Certificate not verified' ?> · NACOS South East</title>
  <style>
    *{box-sizing:border-box}
    :root{--green:#08783e;--deep-green:#064b2b;--gold:#d6a62e;--ink:#17241b}
    body{margin:0;min-height:100vh;padding:24px;background:#e8e9e5;color:var(--ink);font-family:Montserrat,Arial,sans-serif;display:flex;align-items:center;justify-content:center}
    .certificate{width:min(1123px,100%);aspect-ratio:297/210;min-height:0;position:relative;isolation:isolate;overflow:hidden;flex:0 1 auto;background:#fff url("images/certificate-background.png") center/100% 100% no-repeat;border:10px solid #fff;outline:none;box-shadow:0 12px 32px #132b1b25;padding:3.2% 5%;text-align:center;display:flex;flex-direction:column;justify-content:space-evenly;align-items:center;container-type:inline-size}
    .certificate-bold-border{position:absolute;inset:0;border:3px solid var(--green);pointer-events:none;z-index:4}.certificate-inner-border{position:absolute;inset:2%;border:1px solid var(--green);pointer-events:none;z-index:4}
    .certificate.pdf-mode{padding-bottom:18%}
    .pdf-footer{position:absolute;z-index:5;left:10%;right:10%;bottom:4%;height:13%;display:grid;grid-template-columns:minmax(0,1fr) 100px minmax(0,1fr);align-items:center;gap:7%;font-family:Montserrat,Arial,sans-serif;color:#17241b}
    .pdf-footer-side{display:flex;flex-direction:column;align-items:stretch;text-align:center;font-size:clamp(8px,.85vw,10px);line-height:1.35}
    .pdf-footer-rule{display:block;width:100%;height:0;border-top:2px solid #17241b;margin:5px 0 3px}.pdf-footer-side strong{font-weight:900}
    .pdf-footer-side strong,.pdf-footer-side span{display:block}
    .pdf-footer-side strong{font-weight:800}
    .pdf-footer-side span{font-size:clamp(7px,.7vw,8px);letter-spacing:.3px;margin-top:2px}
    .pdf-footer-seal{display:block;width:100px;height:100px;object-fit:contain}.pdf-footer-signature{display:block;width:168px;height:50px;object-fit:contain;margin:0 auto 4px}.pdf-footer-qr{position:absolute;right:12%;left:auto;top:65%;transform:translateY(-50%);display:block;width:108px;height:108px;background:#fff}
    .watermark{position:absolute;z-index:-1;left:50%;top:47%;transform:translate(-50%,-50%);font-family:Cinzel,serif;text-align:center;font-size:clamp(28px,6.2vw,76px);font-weight:800;color:var(--green);opacity:.035;letter-spacing:2px;white-space:nowrap;max-width:92%;pointer-events:none}
    .brand{position:relative;z-index:2;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:5px;height:auto;margin:0 auto}
    .logo{display:block;width:clamp(300px,36vw,400px);height:auto;max-width:88vw;object-fit:contain;object-position:center;margin:0 auto}
    .brand-copy{color:var(--green);font-family:Cinzel,Georgia,serif;font-weight:800;line-height:1.05;font-size:clamp(10px,1.25vw,14px);letter-spacing:.5px}
    h1{position:relative;z-index:2;margin:0;font-family:Cinzel,Georgia,serif;font-size:clamp(20px,3.15vw,36px);font-weight:800;letter-spacing:.6px;line-height:1.15;color:#101b14;text-transform:uppercase}
    .gold-rule{width:22%;height:3px;margin:0 auto 1.15%;background:linear-gradient(90deg,transparent,var(--gold),transparent)}
    .intro{position:relative;z-index:2;font-size:clamp(9px,.9vw,11px);margin:0;color:#57645b;letter-spacing:2px;font-weight:600}
    .recipient{position:relative;z-index:2;width:84%;margin:0 auto;font-family:'Cormorant Garamond',Georgia,serif;font-weight:700;font-size:clamp(18px,5.2cqw,<?= (int) $nameFontSize ?>px);color:var(--deep-green);line-height:1.18;text-transform:uppercase;letter-spacing:1px;white-space:nowrap;text-shadow:0 1px 0 #fff}
    .name-line{height:2px;width:78%;background:linear-gradient(90deg,transparent,var(--gold) 13%,var(--green) 35%,var(--green) 65%,var(--gold) 87%,transparent);margin:0 auto}
    .event-details{position:relative;z-index:2;margin:0;font-family:'Libre Baskerville',Georgia,serif;font-size:clamp(9px,1vw,12px);line-height:1.75;color:#26342a}
    .event-details p{margin:0}.event-details strong{color:var(--deep-green);font-family:Montserrat,Arial,sans-serif;font-weight:800}
    .ribbon{display:none;position:absolute;pointer-events:none;z-index:1}.ribbon.top-right{right:-1%;top:-2%;width:25%;height:49%}.ribbon.bottom-left{left:-1%;bottom:-2%;width:30%;height:48%}.ribbon.left-mid{left:-3%;top:43%;width:11%;height:29%}.ribbon svg{width:100%;height:100%}
    .error-card{width:min(100%,620px);padding:32px;border:1px solid #dce7dc;background:#fff;box-shadow:0 14px 40px rgba(23,53,26,.07);font-family:Montserrat,Arial,sans-serif}.error-card h1{margin:20px 0 8px;color:#a32020;font-size:24px}.error-text{margin:0;color:#647166;font-size:14px;line-height:1.6}
    @media(max-width:700px){body{padding:0;min-height:100vh;min-height:100svh;align-items:center;justify-content:center}.certificate{width:calc(100vw - 20px);max-width:calc(100vw - 20px);aspect-ratio:297/210;min-height:0;padding:4% 6%;display:flex;flex-direction:column;justify-content:space-evenly;gap:0}.certificate-inner-border{inset:2%}.brand{height:auto;margin:0 auto;gap:1cqw}.logo{width:min(36cqw,400px);height:auto;max-width:100%}.brand-copy{font-size:clamp(6px,1.25cqw,14px)}h1{font-size:clamp(12px,3.15cqw,36px);margin:0;line-height:1.1}.intro{font-size:clamp(6px,.9cqw,11px);letter-spacing:.15cqw}.recipient{width:94%;font-size:clamp(18px,5.2cqw,<?= (int) $nameFontSize ?>px);line-height:1.05;white-space:nowrap;margin:0 auto;text-transform:uppercase;letter-spacing:.5px}.name-line{width:90%;margin:0 auto;height:1px}.event-details{font-size:clamp(7px,1cqw,12px);line-height:1.35}.ribbon.top-right{width:29%;height:30%}.ribbon.bottom-left{width:34%;height:31%}.ribbon.left-mid{width:8%;height:19%}}}
    @media print{@page{size:A4 landscape;margin:0}body{width:297mm;height:210mm;min-height:0;margin:0;padding:0;background:#fff;display:block}.certificate{width:297mm;height:210mm;min-height:0;margin:0;box-shadow:none;outline:none;border:10px solid #fff}.certificate-bold-border{inset:0;border-width:2mm}.certificate-inner-border{inset:2%;border-width:.4mm}}
  </style>
</head>
<body>
<?php if ($verified): ?>
<section class="certificate<?= $downloadPdf ? ' pdf-mode' : '' ?>" aria-label="Verified NACOS certificate">
  <div class="certificate-bold-border" aria-hidden="true"></div>
  <div class="certificate-inner-border" aria-hidden="true"></div>
  <div class="watermark" aria-hidden="true">NACOS SOUTH EAST</div>
  <div class="ribbon top-right" aria-hidden="true"><svg viewBox="0 0 300 360" preserveAspectRatio="none"><path d="M35 -10 C125 8 215 42 310 125 L310 -10 Z" fill="#008000"/><path d="M0 -10 C95 16 195 62 310 175 L310 144 C202 54 113 12 25 -10 Z" fill="#D4AF37"/><path d="M20 -10 C105 18 205 70 310 190 L310 165 C208 65 116 20 40 -10 Z" fill="#006400"/><path d="M0 -10 C80 22 180 95 310 250 L310 225 C190 85 95 20 0 -10 Z" fill="#E6BE45"/></svg></div>
  <div class="ribbon bottom-left" aria-hidden="true"><svg viewBox="0 0 360 300" preserveAspectRatio="none"><path d="M-10 75 C70 135 135 235 280 310 L-10 310 Z" fill="#008000"/><path d="M-10 25 C55 115 140 220 340 310 L300 310 C145 235 70 130 -10 70 Z" fill="#D4AF37"/><path d="M-10 55 C60 130 150 220 330 300 L285 300 C145 225 70 125 -10 35 Z" fill="#006400"/><path d="M-10 95 C75 160 165 245 270 300 L235 300 C135 240 55 150 -10 75 Z" fill="#E6BE45"/></svg></div>
  <div class="ribbon left-mid" aria-hidden="true"><svg viewBox="0 0 120 220" preserveAspectRatio="none"><path d="M0 0 C45 55 35 145 120 220 L0 220 Z" fill="#008000"/><path d="M0 0 C35 65 45 140 120 220 L100 220 C40 145 30 60 0 20 Z" fill="#D4AF37"/><path d="M0 35 C30 85 45 155 115 220 L90 220 C40 165 25 90 0 55 Z" fill="#006400"/></svg></div>
  <div class="brand"><img class="logo" src="images/Logo-removebg-preview.png" alt="NACOS South East logo"><div class="brand-copy">NACOS SOUTH EAST</div></div>
  <h1>Certificate of Participation</h1><div class="gold-rule"></div>
  <p class="intro">THIS IS TO CERTIFY THAT:</p>
  <div class="recipient"><?= certificate_h($name) ?></div><div class="name-line"></div>
  <div class="event-details"><p>has participated in the</p><p><strong>NACOS SOUTH EAST CONVENTION 2026</strong></p><p><strong>Convention Date:</strong> 20th–24th October, 2026</p><p><strong>Venue:</strong> Ogbonnaya Onu Polytechnic, Aba, Abia State, Nigeria</p></div>
  <?php if ($downloadPdf): ?>
  <div class="pdf-footer" aria-label="Certificate date, seal, verification QR code, and coordinator details">
    <div class="pdf-footer-side"><strong>24/10/2026</strong><div class="pdf-footer-rule"></div><span>DATE</span></div>
    <img class="pdf-footer-seal" src="images/SEAL.png" alt="NACOS South East official seal">
    <div class="pdf-footer-side"><img class="pdf-footer-signature" src="images/Zonal%20signature.png" alt="Zonal coordinator signature"><div class="pdf-footer-rule"></div><strong>Comrd Okechukwu Chukwu CCS</strong><span>NACOS SOUTH EAST ZONAL COORDINATOR</span><span>2024 - 2026</span></div>
  </div>
  <canvas class="pdf-footer-qr" data-value="<?= certificate_h($verificationUrl) ?>" width="240" height="240" aria-label="Scan to verify this certificate"></canvas>
  <?php endif; ?>
  </section>
<?php else: ?>
<main class="error-card"><div class="brand-copy">NACOS SOUTH EAST</div><?php if (http_response_code() === 503): ?><h1>Verification temporarily unavailable</h1><p class="error-text">Please try again later or contact NACOS South East.</p><?php else: ?><h1>Certificate not verified</h1><p class="error-text">This QR code does not match a current verified attendee record. Please contact NACOS South East.</p><?php endif; ?></main>
<?php endif; ?>
<?php if ($downloadPdf): ?>
<script src="assets/js/qrious.min.js" referrerpolicy="no-referrer"></script>
<script src="assets/js/html2canvas.min.js" referrerpolicy="no-referrer"></script>
<script src="assets/js/jspdf.umd.min.js" referrerpolicy="no-referrer"></script>
<script>
(async function () {
  try {
    await document.fonts.ready;
    const certificate = document.querySelector('.certificate');
    if (!certificate || typeof html2canvas !== 'function' || !window.jspdf?.jsPDF || typeof QRious !== 'function') {
      throw new Error('The PDF tools could not load. Please refresh and try again.');
    }
    await Promise.all(Array.from(certificate.querySelectorAll('.pdf-footer-seal, .pdf-footer-signature'), image => image.decode()));
    const footerQr = certificate.querySelector('.pdf-footer-qr');
    if (footerQr) {
      new QRious({ element: footerQr, value: footerQr.dataset.value, size: 240, level: 'H', background: '#ffffff', foreground: '#073b12' });
      const qrImage = new Image();
      qrImage.className = 'pdf-footer-qr';
      qrImage.alt = 'Scan to verify this certificate';
      qrImage.src = footerQr.toDataURL('image/png');
      footerQr.replaceWith(qrImage);
      await qrImage.decode();
    }
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const canvas = await html2canvas(certificate, { scale: 2, backgroundColor: '#ffffff', useCORS: true, logging: false, windowWidth: 1200, windowHeight: 850 });
    const pdf = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4', compress: true });
    pdf.addImage(canvas.toDataURL('image/jpeg', 0.85), 'JPEG', 0, 0, 297, 210);
    const name = (certificate.querySelector('.recipient')?.textContent || 'Attendee').trim();
    const safeName = name.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'Attendee';
    const isExportMode = window.self !== window.top || new URLSearchParams(window.location.search).get('export') === 'base64';
    if (isExportMode) {
      const dataUri = pdf.output('datauristring');
      const base64 = dataUri.indexOf(',') !== -1 ? dataUri.split(',')[1] : dataUri;
      window.parent.postMessage({
        type: 'NACOS_CERT_PDF',
        token: '<?= certificate_h($token) ?>',
        name: 'NACOS-Certificate-' + safeName + '.pdf',
        base64: base64
      }, '*');
    } else {
      pdf.save('NACOS-Certificate-' + safeName + '.pdf');
    }
  } catch (error) {
    if (window.self !== window.top || new URLSearchParams(window.location.search).get('export') === 'base64') {
      window.parent.postMessage({
        type: 'NACOS_CERT_ERROR',
        token: '<?= certificate_h($token) ?>',
        error: error instanceof Error ? error.message : 'Could not create the certificate PDF.'
      }, '*');
    } else {
      const message = document.createElement('p');
      message.setAttribute('role', 'alert');
      message.textContent = error instanceof Error ? error.message : 'Could not create the certificate PDF.';
      message.style.cssText = 'position:fixed;z-index:9999;top:12px;left:12px;right:12px;padding:12px;background:#fee2e2;color:#991b1b;border-radius:8px;font:14px Arial,sans-serif';
      document.body.append(message);
    }
  }
})();
</script>
<?php endif; ?>
</body>
</html>