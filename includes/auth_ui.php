<?php
/** Shared look for every sign-in page (website, seller, admin). $root = '' or '../'. */
require_once __DIR__ . '/auth_security.php';

function auth_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function auth_page_start(string $title, string $heading, string $sub = '', string $badge = '', string $root = ''): void {
    $site = defined('SITE_NAME') ? SITE_NAME : 'IANOVA';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . auth_h($title) . ' — ' . auth_h($site) . '</title>'
       . '<link rel="icon" type="image/png" href="' . $root . 'assets/img/favicon.png">'
       . '<link rel="stylesheet" href="' . $root . 'assets/css/auth.css?v=1"></head><body class="auth"><main class="auth-card">'
       . '<img class="auth-logo" src="' . $root . 'assets/img/ianova-wordmark.png" alt="' . auth_h($site) . '" onerror="this.style.display=\'none\'">';
    if ($badge !== '') echo '<span class="auth-badge">' . auth_h($badge) . '</span>';
    echo '<h1>' . auth_h($heading) . '</h1>';
    if ($sub !== '') echo '<p class="auth-sub">' . auth_h($sub) . '</p>';
}

function auth_page_end(): void {
    echo '</main><script>
document.querySelectorAll(".eye").forEach(function(b){b.addEventListener("click",function(){var i=b.parentNode.querySelector("input");var s=i.type==="password";i.type=s?"text":"password";b.textContent=s?"Hide":"Show";});});
var pw=document.querySelector("input[data-meter]");if(pw){var m=document.querySelector(".auth-meter i");pw.addEventListener("input",function(){var v=pw.value,s=0;if(v.length>=8)s++;if(/[A-Za-z]/.test(v)&&/\d/.test(v))s++;if(v.length>=12)s++;if(/[^A-Za-z0-9]/.test(v))s++;m.style.width=(s*25)+"%";m.style.background=s<2?"#E5173F":(s<3?"#F59E0B":"#16A34A");});}
</script></body></html>';
}

function auth_error(?string $e): void { if ($e) echo '<div class="auth-err">' . auth_h($e) . '</div>'; }
function auth_notice(?string $e): void { if ($e) echo '<div class="auth-ok">' . auth_h($e) . '</div>'; }

function auth_pw_field(string $name, string $label, bool $meter = false, string $auto = 'current-password'): void {
    echo '<div class="auth-field"><label for="' . $name . '">' . auth_h($label) . '</label>'
       . '<button type="button" class="eye">Show</button>'
       . '<input id="' . $name . '" type="password" name="' . $name . '" class="has-eye" required autocomplete="' . $auto . '"' . ($meter ? ' data-meter="1" minlength="8"' : '') . '></div>';
    if ($meter) echo '<div class="auth-meter"><i></i></div><p class="auth-hint">At least 8 characters, with a letter and a number.</p>';
}
