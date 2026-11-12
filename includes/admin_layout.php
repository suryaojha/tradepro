<?php
function admin_start(string $title, string $active): array
{
    $a = require_admin();
    $pending = (int)q("SELECT COUNT(*) FROM payment_requests WHERE status = 'pending'")->fetchColumn();
    $items = [
        'index.php'    => ['Dashboard', 'dashboard', 0],
        'assets.php'   => ['Markets & Payout', 'assets', 0],
        'requests.php' => ['Deposits / Withdrawals', 'inbox', $pending],
        'users.php'    => ['Users', 'users', 0],
        'kyc.php'      => ['KYC (PAN / Aadhaar)', 'shield', (int)q("SELECT COUNT(*) FROM kyc WHERE status = 'pending'")->fetchColumn()],
        'trades.php'   => ['Trades', 'list', 0],
        'games.php'    => ['Games', 'games', 0],
        'bigbang.php'   => ['BigBang Sandbox', 'gamepad', 0],
        'api_keys.php'  => ['API Keys', 'key-round', 0],
        'settings.php' => ['Settings', 'settings', 0],
    ];
    page_head('Admin · ' . $title, 'is-admin');
    ?>
<div class="admin-top">
  <button type="button" class="iconbtn" id="admMenu" aria-label="Menu"><?= icon('menu', 22) ?></button>
  <a class="logo" href="<?= e(url('admin/')) ?>"><?= e(setting('site_name', 'TradePro')) ?> <span class="muted" style="font-size:12px;font-weight:600">admin</span></a>
  <?= tz_widget() ?><?= theme_toggle() ?>
</div>
<div class="admin-shell">
  <div class="scrim" id="admScrim"></div>
  <aside class="sidebar" id="admSide">
    <div class="sidehead">
      <?= logo_html() ?>
      <button type="button" class="iconbtn only-m" id="admClose" aria-label="Close"><?= icon('close', 20) ?></button>
    </div>
    <span class="adminchip muted">admin</span>
    <?php foreach ($items as $href => [$label, $ic, $badge]): ?>
      <a class="item <?= $active === $href ? 'on' : '' ?>" href="<?= e(url('admin/' . $href)) ?>"><span class="il"><?= icon($ic, 18) ?><?= e($label) ?></span><?php if ($badge): ?><span class="pill"><?= $badge ?></span><?php endif; ?></a>
    <?php endforeach; ?>
    <a class="item" href="<?= e(url('')) ?>" target="_blank"><span class="il"><?= icon('globe', 18) ?>View site</span></a>
    <div class="sidefoot">
      <div class="hide-m inline"><?= tz_widget() ?><?= theme_toggle() ?></div>
      <div class="muted who"><?= e($a['name']) ?><br><small><?= e($a['email']) ?></small></div>
      <a class="item" href="<?= e(url('admin/logout.php')) ?>"><span class="il"><?= icon('logout', 18) ?>Logout</span></a>
    </div>
  </aside>
  <main class="adminmain">
    <?php
    if (!isset($_SESSION['adm_pw_default'])) {
        $_SESSION['adm_pw_default'] = password_verify(DEFAULT_ADMIN_PASSWORD, $a['password_hash']) ? 1 : 0;
    }
    if ($_SESSION['adm_pw_default']) {
        echo '<div class="flash err"><b>Security:</b> you are still using the initial admin password. <a href="' . e(url('admin/settings.php#account')) . '">Change it now</a>.</div>';
    }
    render_flashes();
    return $a;
}

function admin_end(): void
{
    echo '</main></div></body></html>';
}

function pager(int $total, int $page, int $per, string $baseQs): void
{
    $pages = max(1, (int)ceil($total / $per));
    if ($pages <= 1) return;
    echo '<div class="pager">';
    if ($page > 1) echo '<a class="btn ghost sm" href="?' . e($baseQs) . 'p=' . ($page - 1) . '">‹ Prev</a>';
    echo '<span class="muted">Page ' . $page . ' / ' . $pages . ' (' . $total . ' rows)</span>';
    if ($page < $pages) echo '<a class="btn ghost sm" href="?' . e($baseQs) . 'p=' . ($page + 1) . '">Next ›</a>';
    echo '</div>';
}
