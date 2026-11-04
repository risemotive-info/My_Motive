<?php
require_once __DIR__ . '/../includes/public_layout.php';

platform_session_start('MM_PLATFORM');
if (!empty($_SESSION['pa_id']) && platform_admin_by_id((int) $_SESSION['pa_id'])) {
    header('Location: ' . BASE_URL . '/platform/index.php');
    exit;
}

$error = '';
if (($_GET['reason'] ?? '') === 'timeout') {
    $error = 'You were signed out because you were inactive. Please sign in again.';
}
$emailTyped = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $emailTyped = $email;
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = platform_client_ip();

    if (platform_rate_count('pa_login_ip', $ip, 900) >= 10 || ($email !== '' && platform_rate_count('pa_login_email', $email, 900) >= 5)) {
        $error = 'Too many attempts. Please wait 15 minutes and try again.';
    } else {
        $admin = null;
        if ($email !== '') {
            $st = mysqli_prepare(platform_conn(), 'SELECT * FROM platform_admins WHERE email = ? AND is_active = 1 LIMIT 1');
            mysqli_stmt_bind_param($st, 's', $email);
            mysqli_stmt_execute($st);
            $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
        }
        // an unknown email takes as long as a wrong password
        $hash = $admin ? $admin['password_hash'] : '$2y$10$abcdefghijklmnopqrstuuK7bD5kX0Qm0vC1n3Rk0m3b1d9Yw3y2e';
        if (password_verify($password, $hash) && $admin) {
            session_regenerate_id(true);
            $_SESSION['pa_id'] = (int) $admin['id'];
            $_SESSION['pa_last'] = time();
            $st = mysqli_prepare(platform_conn(), 'UPDATE platform_admins SET last_login_at = NOW() WHERE id = ?');
            mysqli_stmt_bind_param($st, 'i', $admin['id']);
            mysqli_stmt_execute($st);
            platform_audit(null, 'admin:' . $admin['email'], 'admin_login', $ip);
            header('Location: ' . BASE_URL . '/platform/' . ((int) $admin['must_change_password'] === 1 ? 'password.php' : 'index.php'));
            exit;
        }
        platform_rate_hit('pa_login_ip', $ip);
        if ($email !== '') {
            platform_rate_hit('pa_login_email', $email);
        }
        $error = 'Wrong email or password.';
    }
}

mm_public_start('Platform sign in', [
    'head' => '<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="' . h(mm_asset('css/mm-admin-theme.css')) . '">',
    'body_class' => 'mm-admin',
    'width' => 'slim',
    'shell' => 'narrow',
    'panel' => [
        'headline' => 'The control room.',
        'lead'     => 'Approve new businesses, confirm payments, keep every account healthy, and see the whole platform at a glance.',
        'points'   => [
            ['shield', 'Only authorised staff can come in'],
            ['clock', 'You are signed out after 30 minutes without activity'],
            ['key', 'Every action you take is recorded'],
        ],
        'note'     => 'Not staff? This page is not for you: use the normal sign-in.',
    ],
]);
?>
    <div class="mm-badge" style="margin-bottom:14px"><?= mm_icon('lock', 14); ?> Staff area</div>
    <h1 class="mm-title">Platform sign in</h1>
    <p class="mm-sub">Use your platform admin email and password.</p>
<?php if ($error !== '') { ?><div class="mm-alert bad"><?= mm_icon('alert', 18); ?><div><?= h($error); ?></div></div><?php } ?>
    <form method="POST" autocomplete="off">
        <?= platform_csrf_field(); ?>
        <div class="mm-field"><label class="mm-label" for="email">Email address</label>
            <input class="mm-input" type="email" id="email" name="email" value="<?= h($emailTyped); ?>" required<?= $emailTyped === '' ? ' autofocus' : ''; ?> autocomplete="username" placeholder="you@business.com"></div>
        <div class="mm-field" style="margin-bottom:22px"><label class="mm-label" for="pw">Password</label>
            <div class="mm-pwwrap"><input class="mm-input" type="password" id="pw" name="password" required<?= $emailTyped !== '' ? ' autofocus' : ''; ?> autocomplete="current-password" placeholder="Your password">
                <button type="button" class="mm-eye" id="eye" aria-label="Show password"><?= mm_icon('eye', 18); ?></button></div></div>
        <button class="mm-btn" type="submit"><?= mm_icon('lock', 18); ?> Sign in</button>
    </form>
<?php
mm_public_end(<<<'JS'
(function(){var b=document.getElementById('eye'),i=document.getElementById('pw');if(!b)return;b.addEventListener('click',function(){var s=i.type==='password';i.type=s?'text':'password';b.setAttribute('aria-label',s?'Hide password':'Show password');});})();
JS);
