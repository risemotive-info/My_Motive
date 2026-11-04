<?php
/**
 * The frame shared by every customer-facing page (sign-up, payment, set / reset password).
 * One look, one stylesheet (assets/css/mm-public.css), no external framework.
 *
 *   mm_public_start('Open your business', ['panel' => [...], 'width' => 'wide']);
 *   ... the content of the card ...
 *   mm_public_end();
 */

require_once __DIR__ . '/platform_web.php';

/** Small inline icons (stroke style). Returns ready-to-print SVG. */
function mm_icon(string $name, int $size = 20, string $class = ''): string
{
    static $paths = [
        'check'    => '<path d="M5 13l4 4L19 7"/>',
        'x'        => '<path d="M6 6l12 12M18 6L6 18"/>',
        'phone'    => '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
        'shield'   => '<path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
        'lock'     => '<rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 018 0v2.5"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 7l8.5 6 8.5-6"/>',
        'building' => '<path d="M4 21V5.5A1.5 1.5 0 015.5 4h8A1.5 1.5 0 0115 5.5V21M15 10h3.5A1.5 1.5 0 0120 11.5V21M2.5 21h19M8 8h3M8 12h3M8 16h3"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0115 0"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0113 0M16 4.8a3.5 3.5 0 010 6.4M18 14.2a6.5 6.5 0 013.5 5.8"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'eye'      => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eyeoff'   => '<path d="M3 3l18 18M10.6 5.1A9.7 9.7 0 0112 5c6.4 0 10 7 10 7a17 17 0 01-3.2 4.1M6.3 6.4C3.7 8.2 2 12 2 12s3.6 7 10 7c1.8 0 3.4-.5 4.8-1.2M9.9 9.9a3 3 0 004.2 4.2"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5h.01"/>',
        'card'     => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'box'      => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3.3 7.6L12 12.5l8.7-4.9M12 12.5V21"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3M14 9l2 2"/>',
        'send'     => '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4 18-7z"/>',
        'spark'    => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/>',
    ];
    $inner = $paths[$name] ?? $paths['info'];
    return '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

/** The address of a file under /assets, with a version stamp so a changed stylesheet is never served from the cache. */
function mm_asset(string $path): string
{
    $file = dirname(__DIR__) . '/assets/' . $path;
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return BASE_URL . '/assets/' . $path . '?v=' . $v;
}

/**
 * Starts a page.
 *
 * options:
 *   panel  => ['headline' => '', 'lead' => '', 'points' => [['icon','text'], ...], 'note' => '']   the blue side panel
 *   width  => 'normal' | 'wide' | 'slim'        the width of the white card
 *   shell  => 'narrow'                         a smaller brand panel
 */
function mm_public_start(string $title, array $opt = []): void
{
    $panel = $opt['panel'] ?? [];
    $headline = $panel['headline'] ?? 'Run your whole business from one place.';
    $lead = $panel['lead'] ?? 'People, sales, money and reports: simple, fast, and built for teams like yours.';
    $points = $panel['points'] ?? [
        ['users', 'Employees, attendance and leave'],
        ['box', 'Your offerings, customers and sales'],
        ['chart', 'Money, loans, assets and payroll with Hasty'],
    ];
    $note = $panel['note'] ?? 'Secure and private: every business has its own separate database.';
    $card = 'mm-card' . (($opt['width'] ?? '') === 'wide' ? ' mm-wide' : (($opt['width'] ?? '') === 'slim' ? ' mm-slim' : ''));
    $logo = mm_asset('images/logo-badge.png');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' | MY MOTIVE</title>'
        . '<meta name="robots" content="noindex">'
        . '<link rel="icon" href="' . h($logo) . '">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
 . '<link rel="stylesheet" href="' . h(mm_asset('css/mm-public.css')) . '">'
        . ($opt['head'] ?? '')   // extra tags for the head, written by our own code (the admin sign-in page adds its theme here)
        . '</head><body class="mm' . (isset($opt['body_class']) ? ' ' . h($opt['body_class']) : '') . '">';


    echo '<div class="mm-topbar"><img src="' . h($logo) . '" alt="">MY MOTIVE</div>';
    echo '<div class="mm-shell' . (($opt['shell'] ?? '') === 'narrow' ? ' mm-narrow' : '') . '">';
    echo '<aside class="mm-brand"><div class="mm-logo"><img src="' . h($logo) . '" alt="">MY MOTIVE</div>'
        . '<div><h1>' . h($headline) . '</h1><p class="lead">' . h($lead) . '</p><ul class="mm-points">';
    foreach ($points as [$icon, $text]) {
        echo '<li><span class="mm-dot">' . mm_icon($icon, 15) . '</span><span>' . h($text) . '</span></li>';
    }
    echo '</ul></div><small>' . mm_icon('shield', 14) . ' ' . h($note) . '</small></aside>';
    echo '<main class="mm-main"><div class="' . $card . '">';
}

/** Ends a page. $scripts is raw JavaScript for the page (written by us, never by a visitor). */
function mm_public_end(string $scripts = ''): void
{
    echo '</div></main></div>';
    if ($scripts !== '') {
        echo '<script>' . $scripts . '</script>';
    }
    echo '</body></html>';
}

/** A short password-strength helper used by the set / reset password pages (shared script). */
function mm_password_script(): string
{
    return <<<'JS'
(function(){
  var pw=document.getElementById('pw'), pw2=document.getElementById('pw2');
  if(!pw) return;
  var bars=document.querySelectorAll('.mm-meter i'), rules={len:document.getElementById('r-len'),let:document.getElementById('r-let'),dig:document.getElementById('r-dig'),same:document.getElementById('r-same')};
  function mark(el,ok){ if(el) el.classList.toggle('ok',ok); }
  function check(){
    var v=pw.value, len=v.length>=10, let=/[A-Za-z]/.test(v), dig=/[0-9]/.test(v), same=v.length>0&&v===(pw2?pw2.value:v);
    mark(rules.len,len); mark(rules.let,let); mark(rules.dig,dig); mark(rules.same,same);
    var score=(len?1:0)+(let&&dig?1:0)+(v.length>=14?1:0)+(/[^A-Za-z0-9]/.test(v)?1:0);
    var colors=['#E4E7F0','#D64545','#F59E0B','#3B4FE0','#12A06B'];
    for(var i=0;i<bars.length;i++){ bars[i].style.background = (v.length&&i<score)?colors[Math.max(1,score)]:'#EDEFF5'; }
  }
  pw.addEventListener('input',check); if(pw2) pw2.addEventListener('input',check); check();
  document.querySelectorAll('.mm-eye').forEach(function(b){
    b.addEventListener('click',function(){
      var i=document.getElementById(b.getAttribute('data-for')); if(!i) return;
      var show=i.type==='password'; i.type=show?'text':'password'; b.setAttribute('aria-label',show?'Hide password':'Show password');
      b.querySelector('.on').style.display=show?'none':''; b.querySelector('.off').style.display=show?'':'none';
    });
  });
})();
JS;
}

/** The two-field password block (with show/hide, strength meter and the rules), used by two pages. */
function mm_password_fields(string $firstLabel = 'New password'): string
{
    $eye = static fn(string $for) => '<button type="button" class="mm-eye" data-for="' . $for . '" aria-label="Show password"><span class="on">' . mm_icon('eye', 18) . '</span><span class="off" style="display:none">' . mm_icon('eyeoff', 18) . '</span></button>';
    $rule = static fn(string $id, string $text) => '<li id="' . $id . '"><span class="c">' . mm_icon('check', 10) . '</span>' . $text . '</li>';
    return '<div class="mm-field"><label class="mm-label" for="pw">' . h($firstLabel) . '</label><div class="mm-pwwrap"><input class="mm-input" type="password" id="pw" name="password" autocomplete="new-password" required minlength="10" autofocus>' . $eye('pw') . '</div>'
        . '<div class="mm-meter"><i></i><i></i><i></i><i></i></div></div>'
        . '<div class="mm-field"><label class="mm-label" for="pw2">Repeat the password</label><div class="mm-pwwrap"><input class="mm-input" type="password" id="pw2" name="password2" autocomplete="new-password" required minlength="10">' . $eye('pw2') . '</div></div>'
        . '<ul class="mm-rules" style="margin-bottom:22px">' . $rule('r-len', 'At least 10 characters') . $rule('r-let', 'At least one letter') . $rule('r-dig', 'At least one digit') . $rule('r-same', 'Both passwords match') . '</ul>';
}
