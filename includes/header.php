<?php
/**
 * IANOVA Store Header
 */
require_once __DIR__.'/category_images.php'; cat_img_ensure($pdo);
require_once __DIR__.'/notify.php'; notify_tick($pdo); // email push
$categories_nav = $pdo->query("SELECT name, slug, emoji, image FROM categories WHERE is_hidden = 0 AND " . display_sql() . " ORDER BY sort_order")->fetchAll();

$site_settings = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
    foreach ($rows as $row) {
        $site_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable $e) {
    // Settings table may not exist yet; fall back to config.php values.
}

$delivery_banner = $site_settings['delivery_banner'] ?? '✦ FREE DELIVERY ON ORDERS OVER {currency}{threshold}';
$delivery_banner = str_replace(
    ['{currency}', '{threshold}'],
    [CURRENCY, number_format((float)($site_settings['free_delivery_threshold'] ?? FREE_DELIVERY_THRESHOLD), 0)],
    $delivery_banner
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? h($page_title) . ' — ' . SITE_NAME : SITE_NAME . ' — ' . SITE_SLOGAN ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/ianova-theme.css?v=5">
<link rel="stylesheet" href="assets/css/ianova-header-fix.css?v=2">
<link rel="icon" type="image/png" href="assets/img/favicon.png">
<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
</head>

<body>

<?php if (($site_settings['announcement_enabled'] ?? '0') === '1' && display_setting_allows($site_settings['disp_announcement'] ?? 'both') && trim($site_settings['announcement_text'] ?? '') !== ''): ?><div class="announce"><?= h($site_settings['announcement_text']) ?></div><?php endif; ?>
<div class="promo-ticker">
  <div class="track">
    <span><?= h($delivery_banner) ?></span>
    <span>✦ SECURE PAYMENTS</span>
    <span>✦ VERIFIED SELLERS</span>
    <span>✦ FAST DELIVERY ACROSS KENYA</span>
    <span><?= h($delivery_banner) ?></span>
    <span>✦ SECURE PAYMENTS</span>
    <span>✦ VERIFIED SELLERS</span>
    <span>✦ FAST DELIVERY ACROSS KENYA</span>
  </div>
</div>

<header class="site-header">

  <div class="nav-inner">

    <button class="main-menu-btn" type="button" id="main-menu-btn" aria-expanded="false" aria-controls="main-menu-drawer" aria-label="Open menu">
      <span class="main-menu-icon"><span></span><span></span><span></span></span>
    </button>

    <a href="index.php" class="logo">
      <img class="logo-img" src="assets/img/ianova-wordmark.png" alt="<?= h(SITE_NAME) ?>" height="34">
    </a>

    <form class="search-bar" action="search.php" method="get">
      <span class="search-icon">⌕</span>
      <input
        type="text"
        name="q"
        placeholder="<?= h($site_settings['search_placeholder'] ?? 'What are you looking for?') ?>"
        value="<?= isset($_GET['q']) ? h($_GET['q']) : '' ?>"
        aria-label="Search products"
      >
      <button type="submit">Search</button>
    </form>

    <div class="nav-actions">

      <a class="nav-action" href="saved.php">
        <span class="nav-action-icon">♡</span>
        <span>
          <small>Wishlist</small>
          <b>Saved items</b>
        </span>
      </a>

      <?php if (!empty($_SESSION['user_id'])): ?>
      <a class="nav-action" href="account.php">
        <span class="nav-action-icon">♙</span>
        <span>
          <small>Hello,</small>
          <b><?= h($_SESSION['user_name'] ?? 'Account') ?></b>
        </span>
      </a>
      <?php else: ?>
      <a class="nav-action" href="login.php">
        <span class="nav-action-icon">♙</span>
        <span>
          <small>Sign in</small>
          <b>Account</b>
        </span>
      </a>
      <?php endif; ?>

      <a class="nav-action cart-action" href="cart.php">
        <span class="nav-action-icon">🛒</span>
        <span>
          <small>Your</small>
          <b>Cart</b>
        </span>

        <?php $cc = cart_count(); ?>
        <?php if ($cc > 0): ?>
          <span class="cart-count"><?= $cc ?></span>
        <?php endif; ?>
      </a>

    </div>

  </div>

  <div class="nav-bottom">

    <div class="nav-bottom-inner">

      <button class="shop-menu" type="button" id="shop-menu-btn" aria-expanded="false" aria-controls="shop-menu-panel">
        <span class="shop-menu-icon"><span></span><span></span><span></span></span>
        <span>Shop by category</span>
      </button>

      <div class="shop-menu-backdrop" id="shop-menu-backdrop"></div>

      <div class="shop-menu-panel" id="shop-menu-panel">
        <div class="shop-menu-panel-head">Browse categories</div>
        <?php foreach ($categories_nav as $c): ?>
          <a href="category.php?slug=<?= h($c['slug']) ?>" class="shop-menu-item">
            <?= cat_icon_html($c, 'shop-menu-emoji') ?>
            <span><?= h($c['name']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <nav class="cat-nav" aria-label="Quick links">
        <a class="nav-deal" href="index.php#deals">
          ⚡ Flash Deals
        </a>

        <a href="seller/register.php">
          Sell on IANOVA
        </a>
      </nav>

    </div>

  </div>

</header>

<div class="main-menu-backdrop" id="main-menu-backdrop"></div>

<nav class="main-menu-drawer" id="main-menu-drawer" aria-label="Main menu">
  <div class="main-menu-head">
    <span><?= SITE_NAME ?><span class="dot">.</span></span>
    <button type="button" id="main-menu-close" aria-label="Close menu">✕</button>
  </div>

  <?php if (!empty($_SESSION['user_id'])): ?>
    <div class="main-menu-user">Signed in as <b><?= h($_SESSION['user_name'] ?? 'Account') ?></b></div>
  <?php endif; ?>

  <div class="main-menu-links">
    <a href="index.php">🏠 Home</a>
    <?php if (!empty($_SESSION['user_id'])): ?>
      <a href="account.php">👤 My account</a>
      <a href="account.php">📦 My orders</a>
    <?php else: ?>
      <a href="login.php">👤 Sign in</a>
    <?php endif; ?>
    <a href="saved.php">♡ Saved items</a>
    <a href="cart.php">🛒 Cart</a>
    <a href="seller/register.php">🏪 Sell on IANOVA</a>
  </div>

  <div class="main-menu-footer">
    <?php if (!empty($_SESSION['user_id'])): ?>
      <a href="logout.php">🚪 Sign out</a>
    <?php endif; ?>
    <a href="#" id="main-menu-exit">⏻ Exit</a>
  </div>
</nav>
