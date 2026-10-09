<?php
require_once 'includes/auth.php';

$defaults = [
    'site_name' => SITE_NAME,
    'site_slogan' => SITE_SLOGAN,
    'currency' => CURRENCY,
    'free_delivery_threshold' => (string) FREE_DELIVERY_THRESHOLD,

    'delivery_banner' => '✦ FREE DELIVERY ON ORDERS OVER {currency}{threshold}',
    'search_placeholder' => 'What are you looking for?',
    'flash_deals_title' => 'Flash Deals',
    'categories_title' => 'Shop by Category',
    'cart_title' => 'Your Cart',
    'delivery_title' => 'Delivery details',
    'trust_delivery_title' => 'Fast delivery',
    'trust_delivery_text' => 'Nationwide dispatch, 24–72h',
    'trust_secure_title' => 'Secure checkout',
    'trust_secure_text' => 'Encrypted payments, every order',
    'trust_returns_title' => 'Easy returns',
    'trust_returns_text' => '7-day no-questions policy',
    'trust_verified_title' => 'Verified sellers',
    'trust_verified_text' => 'Every store manually checked',
    'newsletter_title' => 'Get deals before everyone else',
    'newsletter_text' => 'Weekly drops, flash sale alerts, and restock notices — straight to your inbox.',
    'newsletter_placeholder' => 'you@email.com',
    'newsletter_button' => 'Subscribe',
    'delivery_message' => 'Free delivery on orders over {currency}{threshold}',
    'returns_message' => 'Easy returns on eligible products.',
    'footer_message' => 'Shop smart, live better.',
];

$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value TEXT NULL
    ) ENGINE=InnoDB
");

foreach ($defaults as $key => $value) {
    $pdo->prepare(
        'INSERT IGNORE INTO settings(setting_key, setting_value) VALUES(?, ?)'
    )->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_check_csrf();

    foreach ($defaults as $key => $default) {
        $value = trim($_POST[$key] ?? '');
        $pdo->prepare(
            'UPDATE settings SET setting_value=? WHERE setting_key=?'
        )->execute([$value, $key]);
    }

    header('Location: settings.php?saved=1');
    exit;
}

$rows = $pdo->query(
    'SELECT setting_key, setting_value FROM settings'
)->fetchAll();

$settings = [];

foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$page_title = 'Site content & settings';
require 'includes/header.php';
?>

<div class="card form-card">

<?php if (isset($_GET['saved'])): ?>
    <div class="notice">
        Site content and settings saved successfully.
    </div>
<?php endif; ?>

<form method="post">

<input type="hidden" name="csrf" value="<?= admin_csrf() ?>">

<h2>Store settings</h2>

<div class="field">
    <label>Site name</label>
    <input name="site_name"
           value="<?= admin_h($settings['site_name'] ?? SITE_NAME) ?>">
</div>

<div class="field">
    <label>Site slogan</label>
    <input name="site_slogan"
           value="<?= admin_h($settings['site_slogan'] ?? SITE_SLOGAN) ?>">
</div>

<div class="row">

    <div class="field">
        <label>Currency</label>
        <input name="currency"
               value="<?= admin_h($settings['currency'] ?? CURRENCY) ?>">
    </div>

    <div class="field">
        <label>Free delivery threshold</label>
        <input type="number"
               step="0.01"
               name="free_delivery_threshold"
               value="<?= admin_h($settings['free_delivery_threshold'] ?? FREE_DELIVERY_THRESHOLD) ?>">
    </div>

</div>

<hr style="border:0;border-top:1px solid #e8eeea;margin:24px 0;">

<h2>Website text</h2>

<p class="muted">
    These fields control wording displayed around the store.
    You can change them from this page without editing PHP files.
</p>

<div class="field">
    <label>Free delivery banner</label>
    <input name="delivery_banner"
           value="<?= admin_h($settings['delivery_banner'] ?? '') ?>">
    <div class="muted">
        Use <code>{currency}</code> and <code>{threshold}</code> where you want the values inserted.
    </div>
</div>

<div class="field">
    <label>Search placeholder</label>
    <input name="search_placeholder"
           value="<?= admin_h($settings['search_placeholder'] ?? '') ?>">
</div>

<div class="field">
    <label>Flash Deals heading</label>
    <input name="flash_deals_title"
           value="<?= admin_h($settings['flash_deals_title'] ?? '') ?>">
</div>

<div class="field">
    <label>Shop by Category heading</label>
    <input name="categories_title"
           value="<?= admin_h($settings['categories_title'] ?? '') ?>">
</div>

<div class="field">
    <label>Cart heading</label>
    <input name="cart_title"
           value="<?= admin_h($settings['cart_title'] ?? '') ?>">
</div>

<div class="field">
    <label>Delivery message</label>
    <textarea name="delivery_message"><?= admin_h($settings['delivery_message'] ?? '') ?></textarea>
</div>

<div class="field">
    <label>Returns message</label>
    <textarea name="returns_message"><?= admin_h($settings['returns_message'] ?? '') ?></textarea>
</div>

<div class="field">
    <label>Footer message</label>
    <textarea name="footer_message"><?= admin_h($settings['footer_message'] ?? '') ?></textarea>
</div>

<h3 style="margin-top:28px;">Storefront text</h3>

<div class="row">
  <div class="field">
    <label>Fast delivery title</label>
    <input name="trust_delivery_title" value="<?= admin_h($settings['trust_delivery_title'] ?? '') ?>">
  </div>
  <div class="field">
    <label>Fast delivery description</label>
    <input name="trust_delivery_text" value="<?= admin_h($settings['trust_delivery_text'] ?? '') ?>">
  </div>
</div>

<div class="row">
  <div class="field">
    <label>Secure checkout title</label>
    <input name="trust_secure_title" value="<?= admin_h($settings['trust_secure_title'] ?? '') ?>">
  </div>
  <div class="field">
    <label>Secure checkout description</label>
    <input name="trust_secure_text" value="<?= admin_h($settings['trust_secure_text'] ?? '') ?>">
  </div>
</div>

<div class="row">
  <div class="field">
    <label>Easy returns title</label>
    <input name="trust_returns_title" value="<?= admin_h($settings['trust_returns_title'] ?? '') ?>">
  </div>
  <div class="field">
    <label>Easy returns description</label>
    <input name="trust_returns_text" value="<?= admin_h($settings['trust_returns_text'] ?? '') ?>">
  </div>
</div>

<div class="row">
  <div class="field">
    <label>Verified sellers title</label>
    <input name="trust_verified_title" value="<?= admin_h($settings['trust_verified_title'] ?? '') ?>">
  </div>
  <div class="field">
    <label>Verified sellers description</label>
    <input name="trust_verified_text" value="<?= admin_h($settings['trust_verified_text'] ?? '') ?>">
  </div>
</div>

<div class="row">
  <div class="field">
    <label>Newsletter heading</label>
    <input name="newsletter_title" value="<?= admin_h($settings['newsletter_title'] ?? '') ?>">
  </div>
  <div class="field">
    <label>Newsletter button</label>
    <input name="newsletter_button" value="<?= admin_h($settings['newsletter_button'] ?? '') ?>">
  </div>
</div>

<div class="field">
  <label>Newsletter description</label>
  <textarea name="newsletter_text"><?= admin_h($settings['newsletter_text'] ?? '') ?></textarea>
</div>

<div class="field">
  <label>Newsletter email placeholder</label>
  <input name="newsletter_placeholder" value="<?= admin_h($settings['newsletter_placeholder'] ?? '') ?>">
</div>

<button class="btn">Save all changes</button>

</form>

</div>

<?php require 'includes/footer.php'; ?>
