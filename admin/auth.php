<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/db.php';

$secureCookie = (bool) nacos_config()['session_secure'];
// Versioned session name resets old per-session login throttles after an admin credential reset.
session_name('nacos_admin_session_v2');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function admin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function admin_verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && isset($_SESSION['admin_csrf']) && hash_equals($_SESSION['admin_csrf'], $token);
}

function admin_require_login(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login');
        exit;
    }
}

function admin_page_start(string $title): void
{
    $safeTitle = admin_h($title);
    $csrf = admin_h(admin_csrf_token());
    $pendingManualCount = 0;
    try {
        $pendingManualCount = (int) nacos_db()->query("SELECT COUNT(*) FROM manual_payment_submissions WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $error) {
        $pendingManualCount = 0;
    }
    $pendingBadgeHtml = $pendingManualCount > 0
        ? '<span class="review-count">' . admin_h($pendingManualCount > 99 ? '99+' : (string) $pendingManualCount) . '</span>'
        : '';
    echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle} · NACOS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{font-family:'Poppins',Segoe UI,Arial,sans-serif;color:#17251a;background:#f2f5f2;font-synthesis:none}*{box-sizing:border-box}body{margin:0}.topbar{background:#073b12;color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px}.brand{display:flex;align-items:center;gap:12px;font-weight:750;letter-spacing:.02em}.brand-logo{display:block;width:118px;height:auto;object-fit:contain}.brand small{display:block;color:#b5d7b3;font-weight:500;margin-top:3px}.top-actions{display:flex;align-items:center;gap:14px;font-size:13px}.top-actions form{margin:0}.top-end{display:flex;align-items:center;gap:10px}.review-notification{position:relative;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;color:#fff;border-radius:50%;text-decoration:none}.review-notification:hover{background:rgba(255,255,255,.12)}.review-notification svg{width:21px;height:21px;display:block}.payment-settings-icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;color:#fff;border-radius:50%;text-decoration:none}.payment-settings-icon:hover{background:rgba(255,255,255,.12)}.payment-settings-icon svg{width:21px;height:21px;display:block}.review-count{position:absolute;top:-4px;right:-6px;min-width:17px;height:17px;padding:0 4px;display:flex;align-items:center;justify-content:center;border-radius:999px;background:#e53935;color:#fff;font-size:10px;font-weight:800;line-height:1}.wrap{max-width:1180px;padding:26px 18px 50px;margin:auto}h1{font-size:27px;margin:0 0 5px;letter-spacing:-.03em}.muted{color:#68766a;font-size:13px}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin:22px 0 16px;flex-wrap:wrap}.toolbar form{display:flex;gap:8px;flex-wrap:wrap}input,select,textarea{font:inherit;border:1px solid #cad5ca;border-radius:8px;background:#fff;padding:10px 12px;color:#1b2b1e}input:focus,select:focus,textarea:focus{outline:2px solid #b9dfbc;border-color:#16852a}button,.button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:8px;padding:10px 14px;background:#0b6623;color:#fff;font-weight:650;text-decoration:none;cursor:pointer}button:hover,.button:hover{background:#084c1a}.button-light{background:#fff;color:#174a21;border:1px solid #d7e0d6}.button-light:hover{background:#eef6ed}.cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:20px 0}.metric,.panel{background:#fff;border:1px solid #e0e7df;border-radius:12px;box-shadow:0 3px 12px rgba(18,45,22,.035)}.metric{padding:15px}.metric small{display:block;color:#68766a;font-size:11px}.metric strong{display:block;margin-top:7px;font-size:24px}.panel{overflow:hidden}.table-scroll{overflow:auto}table{width:100%;border-collapse:collapse;min-width:780px}th,td{text-align:left;padding:12px 14px;border-bottom:1px solid #edf0ec;font-size:13px;vertical-align:top}th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#59675a;background:#f8faf8}tbody tr:hover{background:#fbfdfb}td a{color:#0b6623;text-decoration:none;font-weight:650}.badge{display:inline-flex;border-radius:999px;padding:4px 8px;background:#eef3ee;color:#435444;font-size:11px;font-weight:700}.badge-new{background:#e8f4e8;color:#176128}.badge-reviewing{background:#fff4d8;color:#8a6000}.badge-shortlisted{background:#e8f0ff;color:#31598b}.badge-accepted{background:#dcfce7;color:#166534}.badge-rejected{background:#fee2e2;color:#991b1b}.empty{padding:28px;color:#68766a;text-align:center}.pagination{display:flex;justify-content:space-between;align-items:center;padding:13px 15px;color:#68766a;font-size:12px}.detail-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,.8fr);gap:18px}.panel-pad{padding:20px}.detail-list{display:grid;grid-template-columns:160px 1fr;gap:0}.detail-list dt,.detail-list dd{margin:0;padding:11px 0;border-bottom:1px solid #edf0ec;font-size:13px;overflow-wrap:anywhere}.detail-list dt{color:#69766b}.detail-list dd{font-weight:550;white-space:pre-wrap}.form-stack{display:grid;gap:12px}.form-stack label{font-size:12px;font-weight:700}.form-stack select,.form-stack textarea{width:100%;margin-top:5px}.form-stack textarea{min-height:150px;resize:vertical}.notice{padding:12px 14px;border-radius:8px;background:#e5f5e7;color:#175b26;margin:14px 0;font-size:13px}.error{background:#fee2e2;color:#991b1b}.login-shell{max-width:430px;margin:8vh auto;padding:24px}.login-shell .panel{padding:24px}.login-shell input{width:100%;margin-top:6px}.login-shell label{display:block;margin:15px 0 0;font-size:13px;font-weight:650}.login-shell button{width:100%;margin-top:20px}.subhead{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:24px 0 12px}.actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:760px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.detail-grid{grid-template-columns:1fr}.topbar{position:relative;padding:12px 15px;align-items:stretch;flex-direction:column;gap:10px}.review-notification{position:absolute;top:12px;right:58px;z-index:2}.payment-settings-icon{position:absolute;top:12px;right:18px;z-index:2}.brand{width:100%;line-height:1.25}.top-actions{width:100%;display:grid;grid-template-columns:auto auto minmax(0,1fr);gap:6px;font-size:10px}.top-actions .top-end{grid-column:3;justify-self:end}.top-actions form{justify-self:end}.top-actions button{white-space:nowrap;padding:9px 10px}.wrap{padding:20px 12px 40px}.detail-list{grid-template-columns:120px 1fr}.subhead{align-items:flex-start;flex-direction:column}.subhead .actions,.subhead .button{width:100%}.toolbar{align-items:stretch}.toolbar form{width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto}.toolbar input{grid-column:1/-1;width:100%;min-width:0}.toolbar select{min-width:0;width:100%}.toolbar form button{white-space:nowrap}.toolbar>.button{width:100%}.table-scroll{-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain}table:has(td.empty){min-width:0}}
</style></head><body>
<header class="topbar"><div class="brand"><img class="brand-logo" src="../images/nacos-logo.png" alt="NACOS South East · Event administration"></div><div class="top-actions"><a href="payments.php" style="color:#fff;text-decoration:none">Paid forms</a><a href="manual-paid.php" style="color:#fff;text-decoration:none">Manual paid</a><div class="top-end"><a class="review-notification" href="manual-payments.php" aria-label="Manual payment review, {$pendingManualCount} pending" title="Manual payment review"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>{$pendingBadgeHtml}</a><a class="payment-settings-icon" href="payment-settings.php" aria-label="Payment settings" title="Payment settings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="m19.4 15 .1.1a1.7 1.7 0 0 1-2.4 2.4l-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.2a1.7 1.7 0 0 1-3.4 0v-.2a1.7 1.7 0 0 0-2.9-1.2l-.1.1a1.7 1.7 0 0 1-2.4-2.4l.1-.1a1.7 1.7 0 0 0-1.2-2.9H4a1.7 1.7 0 0 1 0-3.4h.2a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a1.7 1.7 0 0 1 2.4-2.4l.1.1a1.7 1.7 0 0 0 2.9-1.2V2a1.7 1.7 0 0 1 3.4 0v.2a1.7 1.7 0 0 0 2.9 1.2l.1-.1a1.7 1.7 0 0 1 2.4 2.4l-.1.1a1.7 1.7 0 0 0 1.2 2.9h.2a1.7 1.7 0 0 1 0 3.4h-.2a1.7 1.7 0 0 0-1.2 2.9Z"></path></svg></a><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="{$csrf}"><button type="submit" class="button-light">Sign out</button></form></div></div></header>
<main class="wrap">
HTML;
}

function admin_page_end(): void
{
    echo '</main></body></html>';
}
