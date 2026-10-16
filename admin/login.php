<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: payments.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf()) {
        $error = 'This login session expired. Refresh the page and try again.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        try {
            $query = nacos_db()->prepare('SELECT id, email, password_hash FROM admin_users WHERE email = :email LIMIT 1');
            $query->execute([':email' => $email]);
            $admin = $query->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            error_log('Admin login database error: ' . $exception->getMessage());
            $admin = false;
        }

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            $update = nacos_db()->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id');
            $update->execute([':id' => $admin['id']]);
            header('Location: payments.php');
            exit;
        }

        $error = 'Email or password is incorrect.';
    }
}

$csrf = admin_h(admin_csrf_token());
$safeError = $error === '' ? '' : '<div class="notice error">' . admin_h($error) . '</div>';
echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin sign in · NACOS</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;min-height:100svh;display:grid;place-items:center;padding:24px;background:#f2f5f2;color:#10251a;font-family:Arial,Helvetica,sans-serif}
.login-shell{width:min(100%,430px);margin:0;display:flex;flex-direction:column;align-items:center;gap:22px}.brand{display:flex;flex-direction:column;align-items:center;gap:12px;margin:0;color:#08732d;text-align:center;font-size:12px;font-weight:800;letter-spacing:.13em}.brand img{display:block;width:min(220px,70vw);height:auto;object-fit:contain}.panel{width:100%;background:#fff;border:1px solid #dce6dc;border-radius:16px;padding:26px;box-shadow:0 16px 42px rgba(18,45,22,.09)}h1{margin:0 0 6px;font-size:25px;letter-spacing:-.03em}.muted{color:#68766a;font-size:13px;line-height:1.6;margin:0}label{display:block;margin-top:16px;font-size:13px;font-weight:650}input{width:100%;margin-top:6px;padding:12px;border:1px solid #cad5ca;border-radius:8px;font:inherit}input:focus{outline:2px solid #b9dfbc;border-color:#16852a}.password-field{position:relative;margin-top:6px}.password-field input{margin-top:0;padding-right:48px}.password-toggle{position:absolute;right:5px;top:50%;width:38px;height:38px;margin:0;padding:8px;transform:translateY(-50%);display:grid;place-items:center;border:0;border-radius:7px;background:transparent;color:#526554;cursor:pointer}.password-toggle:hover{background:#edf5ed;color:#0b6623}.password-toggle:focus-visible{outline:2px solid #16852a;outline-offset:1px}.password-toggle svg{width:20px;height:20px}button[type=submit]{width:100%;margin-top:20px;padding:12px;border:0;border-radius:8px;background:#0b6623;color:#fff;font:inherit;font-weight:700;cursor:pointer}button[type=submit]:hover{background:#084c1a}.notice{margin-top:14px;padding:12px;border-radius:8px;background:#fee2e2;color:#991b1b;font-size:13px}
@media(max-width:480px){body{padding:20px}.login-shell{gap:18px}.brand img{width:min(190px,68vw)}.panel{padding:22px;border-radius:14px}}
</style></head><body><main class="login-shell"><div class="brand"><img src="../images/nacos-logo.png" alt="NACOS South East logo"><span>NACOS SOUTH EAST · ADMIN</span></div><section class="panel"><h1>Sign in</h1><p class="muted">Use your administrator email and password to manage event registrations and verified payments.</p>{$safeError}<form method="post" action="login" autocomplete="on"><input type="hidden" name="csrf_token" value="{$csrf}"><label>Email<input type="email" name="email" autocomplete="username" required maxlength="254"></label><label for="admin-password">Password</label><div class="password-field"><input id="admin-password" type="password" name="password" autocomplete="current-password" required maxlength="200"><button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" aria-controls="admin-password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button></div><button type="submit">Sign in</button></form></section></main><script>const passwordInput=document.getElementById('admin-password');const passwordToggle=document.querySelector('.password-toggle');passwordToggle.addEventListener('click',()=>{const showing=passwordInput.type==='text';passwordInput.type=showing?'password':'text';passwordToggle.setAttribute('aria-label',showing?'Show password':'Hide password');passwordToggle.setAttribute('aria-pressed',String(!showing));});</script></body></html>
HTML;
