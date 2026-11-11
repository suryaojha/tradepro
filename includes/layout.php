<?php
function icon(string $name, int $size = 20): string
{
    static $p = [
        'trade'     => '<polyline points="3 17 9 11 13 15 21 7"/><polyline points="15 7 21 7 21 13"/>',
        'wallet'    => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v10a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-3"/><path d="M21 9h-4a2 2 0 0 0 0 4h4z"/>',
        'history'   => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'assets'    => '<polygon points="12 2 2 7 12 12 22 7"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'inbox'     => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'list'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'settings'  => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'menu'      => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'close'     => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'expand'    => '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
        'shrink'    => '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
        'shield'    => '<path d="M12 2l8 3v6c0 5-3.4 9.3-8 11-4.6-1.7-8-6-8-11V5z"/><polyline points="9 12 11 14 15 10"/>',
        'games'     => '<rect x="2" y="6" width="20" height="12" rx="4"/><line x1="7" y1="10" x2="7" y2="14"/><line x1="5" y1="12" x2="9" y2="12"/><circle cx="16" cy="11" r="1"/><circle cx="18" cy="13" r="1"/>',
        'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** Flash messages appear as SweetAlert toasts (inline boxes remain as a fallback if scripts are blocked). */
/** 'dark' (default) or 'light' – kept in a cookie and saved on the user's profile. */
function theme_now(): string
{
    return ($_COOKIE['theme'] ?? '') === 'light' ? 'light' : 'dark';
}

function theme_toggle(): string
{
    return '<button type="button" class="themebtn" title="Switch dark / light theme" aria-label="Switch dark / light theme"></button>';
}

function render_flashes(): void
{
    $list = flashes();
    if (!$list) return;
    $js = [];
    foreach ($list as $f) {
        $cls = $f['t'] === 'ok' ? 'ok' : ($f['t'] === 'info' ? 'info' : 'err');
        $js[] = ['t' => $cls, 'm' => strip_tags((string)$f['m'])];
        echo '<div class="flash ' . $cls . ' js-flash">' . e($f['m']) . '</div>';
    }
    echo '<script>window.TP_FLASH=(window.TP_FLASH||[]).concat(' . json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP) . ');</script>';
}

function page_head(string $title, string $bodyClass = ''): void
{
    $v = (int)@filemtime(APP_ROOT . '/assets/style.css');
    $j = (int)@filemtime(APP_ROOT . '/assets/app.js');
    ?><!doctype html>
<html lang="en" data-theme="<?= e(theme_now()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= theme_now() === 'light' ? '#f3f5fb' : '#0b1020' ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<script>window.TP_THEME_API=<?= json_encode(url('api/theme.php')) ?>;</script>
<title><?= e($title) ?> · <?= e(setting('site_name', 'TradePro')) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%234c82ff'/%3E%3Cpath d='M6 21l6-7 5 4 9-10' stroke='%23fff' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=<?= $v ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.all.min.js" defer></script>
<script src="<?= e(url('assets/app.js')) ?>?v=<?= $j ?>" defer></script>
</head>
<body class="<?= e($bodyClass) ?>">
<div id="pageLoader" class="pageloader" aria-hidden="true"><div class="spinner"></div><div class="loadtext"><?= e(setting('site_name', 'TradePro')) ?></div></div>
<div id="topProgress" class="topprogress"></div>
<?php
}

function logo_html(): string
{
    $n = setting('site_name', 'TradePro');
    return '<a class="logo" href="' . e(url('')) . '"><span class="logomark">' . icon('trade', 18) . '</span>' . e($n) . '</a>';
}

/** Live clock + IST / UTC switch (choice is remembered in a cookie). */
function tz_widget(): string
{
    $cur = tz_short();
    $h = '<div class="tzw" title="Time zone"><span class="tzclock mono" id="tzClock" data-tz="' . e(display_tz()) . '">--:--:--</span><div class="seg">';
    foreach (TZ_CHOICES as $k => $tz) {
        $h .= '<button type="button" data-tzset="' . e($k) . '" class="' . ($cur === $k ? 'on' : '') . '">' . e($k) . '</button>';
    }
    return $h . '</div></div>';
}

/** Account switcher dropdown used on every logged-in page. */
function account_switcher(array $u, array $wallets): void
{
    $active = $u['active_account'];
    $pnl = pnl_summary((int)$u['id']);
    $m = acc_meta($active);
    ?>
<div class="acctsel" id="acctsel">
  <button type="button" class="acctbtn" id="acctbtn" aria-haspopup="true">
    <span><small><?= e($m['label']) ?></small><b id="activeBal"><?= e(money($wallets[$active], $active)) ?></b><?= $pnl ? pnl_chip($pnl, $active) : '' ?></span>
    <span class="caret">▾</span>
  </button>
  <div class="acctmenu" id="acctmenu">
    <?php foreach (accounts_on() as $a): $mm = acc_meta($a); ?>
      <div class="acctitem <?= $a === $active ? 'on' : '' ?>" data-acc="<?= e($a) ?>">
        <span><span class="dot <?= e($a) ?>"></span><b><?= e($mm['label']) ?></b>
          <small>Balance <span data-bal="<?= e($a) ?>"><?= e(money($wallets[$a], $a)) ?></span></small></span>
        <?php if ($a === $active): ?><span>✓</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="acctfoot">
      <button class="btn sm ghost" type="button" id="resetDemo">Reset demo</button>
      <a class="btn sm green" href="<?= e(url('wallet.php')) ?>">Deposit</a>
    </div>
  </div>
</div>
<script>
(function(){
  var btn=document.getElementById('acctbtn'),menu=document.getElementById('acctmenu');
  var CSRF=<?= json_encode(csrf_token()) ?>, API=<?= json_encode(url('api/account.php')) ?>;
  btn.onclick=function(e){e.stopPropagation();menu.classList.toggle('show')};
  document.addEventListener('click',function(e){if(!menu.contains(e.target))menu.classList.remove('show')});
  function post(data){
    data.csrf=CSRF;
    return fetch(API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},
      body:new URLSearchParams(data)}).then(function(r){return r.json()});
  }
  menu.querySelectorAll('.acctitem').forEach(function(el){
    el.onclick=function(){TP.loading(true);post({action:'switch',account:el.dataset.acc}).then(function(j){if(j.ok)location.reload();else{TP.loading(false);TP.alert(j.error,'error')}})};
  });
  document.getElementById('resetDemo').onclick=function(e){
    e.stopPropagation();
    TP.confirm('Reset your demo balance to the default amount?').then(function(ok){
      if(ok){TP.loading(true);post({action:'reset_demo'}).then(function(j){if(j.ok)location.reload();else{TP.loading(false);TP.alert(j.error,'error')}})}
    });
  };
})();
</script>
<?php
}

function user_topbar(array $u, string $active = ''): void
{
    $w = wallets_of((int)$u['id']);
    $links = ['trade.php' => ['Trade', 'trade'], 'games.php' => ['Play', 'games'], 'wallet.php' => ['Wallet', 'wallet'], 'history.php' => ['History', 'history'], 'profile.php' => ['Profile', 'user']];
    ?>
<header class="topbar">
  <?= logo_html() ?>
  <nav class="nav">
    <?php foreach ($links as $href => [$label]): ?>
      <a href="<?= e(url($href)) ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="topright">
    <?= tz_widget() ?>
    <?= theme_toggle() ?>
    <?php account_switcher($u, $w); ?>
    <a class="btn green sm hide-m" href="<?= e(url('wallet.php')) ?>">Deposit</a>
    <a class="btn ghost sm hide-m" href="<?= e(url('logout.php')) ?>">Logout</a>
  </div>
</header>
<nav class="mnav" aria-label="Main">
  <?php foreach ($links as $href => [$label, $ic]): ?>
    <a href="<?= e(url($href)) ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= icon($ic, 22) ?><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php
}

/** Standard logged-in page (wallet / history / profile). */
function user_page_start(string $title, string $active): array
{
    $u = require_user();
    page_head($title, 'has-mnav');
    user_topbar($u, $active);
    echo '<div class="container">';
    render_flashes();
    return $u;
}

function user_page_end(): void
{
    echo '</div></body></html>';
}

/** jQuery + Select2 (searchable dropdowns). Add class "s2" to a <select>; app.js turns it into a Select2 box. */
function select2_assets(): void
{
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">';
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>';
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>';
}

/** Password field with show/hide + live strength hint. */
function password_field(string $name, string $label, bool $meter = false, string $autocomplete = 'new-password'): string
{
    return '<div class="field"><label>' . e($label) . '</label><div class="pwbox"><input type="password" name="' . e($name) . '" required autocomplete="' . e($autocomplete) . '"' . ($meter ? ' data-meter' : '') . '>'
        . '<button type="button" class="pwtoggle" data-pwtoggle tabindex="0" aria-label="Show password" aria-pressed="false">Show</button></div>'
        . ($meter ? '<div class="meter"><i></i></div><div class="meterhint muted">' . e(PASSWORD_HINT) . '</div>' : '') . '</div>';
}
