<?php
require 'config.php';
require 'includes/functions.php';

require_once __DIR__.'/includes/category_images.php'; cat_img_ensure($pdo);
$categories = $pdo->query("SELECT * FROM categories WHERE " . display_sql() . " ORDER BY sort_order")->fetchAll();

// category item counts (for the "N items" caption on each card)
$counts_stmt = $pdo->query("SELECT category_id, COUNT(*) AS n FROM products WHERE " . display_sql() . " GROUP BY category_id");
$counts = [];
foreach ($counts_stmt as $row) $counts[$row['category_id']] = $row['n'];

$flash_deals = $pdo->query("SELECT * FROM products WHERE is_flash_deal = 1 AND status='approved' AND " . dedupe_sql() . " ORDER BY created_at DESC LIMIT 8")->fetchAll();

$all_products = $pdo->query("SELECT * FROM products WHERE status='approved' AND " . dedupe_sql() . " ORDER BY created_at DESC LIMIT 24")->fetchAll();

$page_title = null; // use default title
require 'includes/header.php';
?>

<?php
$banners = [];
try { $banners = $pdo->query("SELECT * FROM banners WHERE is_active = 1 AND " . display_sql() . " ORDER BY sort_order, id")->fetchAll(); } catch (Throwable $e) { $banners = []; }

$video_ad_on = (($site_settings['video_ad_enabled'] ?? '0') === '1') && !empty($site_settings['video_ad_src']) && display_setting_allows($site_settings['disp_video_ad'] ?? 'both');
if ($video_ad_on):
    $video_ad_href = '';
    $vLinkType = $site_settings['video_ad_link_type'] ?? 'none';
    $vLinkValue = $site_settings['video_ad_link_value'] ?? '';
    if ($vLinkType === 'product' && $vLinkValue) {
        $vps = $pdo->prepare('SELECT slug FROM products WHERE id = ?'); $vps->execute([(int) $vLinkValue]);
        $vslug = $vps->fetchColumn();
        if ($vslug) $video_ad_href = 'product.php?slug=' . urlencode($vslug);
    } elseif ($vLinkType === 'category' && $vLinkValue) {
        $vcs = $pdo->prepare('SELECT slug FROM categories WHERE id = ?'); $vcs->execute([(int) $vLinkValue]);
        $vcslug = $vcs->fetchColumn();
        if ($vcslug) $video_ad_href = 'category.php?slug=' . urlencode($vcslug);
    } elseif ($vLinkType === 'url' && $vLinkValue) {
        $video_ad_href = $vLinkValue;
    }
    ?>
<div class="video-ad" id="videoAd" data-interval-min="<?= (int) ($site_settings['video_ad_interval_minutes'] ?? 3) ?>" data-min-seconds="<?= (int) ($site_settings['video_ad_min_seconds'] ?? 30) ?>" data-no-banners="<?= empty($banners) ? '1' : '0' ?>">
  <div class="wrap video-ad-inner">
    <span class="video-ad-tag">Ad</span>
    <video id="videoAdEl" src="<?= h($site_settings['video_ad_src']) ?>" playsinline muted autoplay controls></video>
    <?php if ($video_ad_href !== ''): ?>
      <a class="video-ad-cta" href="<?= h($video_ad_href) ?>">Shop now →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($banners): ?>
<section class="banner-strip"><div class="wrap"><div class="banner-track">
<?php foreach ($banners as $bn):
    $href = '#'; $img = (string) $bn['image'];
    if ($bn['link_type'] === 'product') {
        $ps = $pdo->prepare('SELECT slug, image FROM products WHERE id = ?'); $ps->execute([(int) $bn['link_value']]); $pr = $ps->fetch();
        if ($pr) { $href = 'product.php?slug=' . urlencode($pr['slug']); if ($img === '') $img = (string) $pr['image']; }
    } elseif ($bn['link_type'] === 'category') {
        $cs = $pdo->prepare('SELECT slug FROM categories WHERE id = ?'); $cs->execute([(int) $bn['link_value']]); $slug = $cs->fetchColumn();
        if ($slug) $href = 'category.php?slug=' . urlencode($slug);
    } elseif ($bn['link_type'] === 'search') {
        $href = 'search.php?q=' . urlencode($bn['link_value']);
    } ?>
  <a class="banner-card banner-<?= h($bn['color']) ?>" href="<?= h($href) ?>">
    <div class="banner-copy"><h3><?= h($bn['title']) ?></h3><?php if ($bn['subtitle'] !== ''): ?><p><?= h($bn['subtitle']) ?></p><?php endif; ?><span class="banner-btn"><?= h($bn['button_label']) ?></span></div>
    <?php if ($img !== ''): ?><img src="<?= h($img) ?>" alt=""><?php endif; ?>
  </a>
<?php endforeach; ?>
</div></div></section>
<script>(function(){var t=document.querySelector('.banner-track');if(!t||t.children.length<2)return;var i=0;setInterval(function(){i=(i+1)%t.children.length;t.scrollTo({left:t.children[i].offsetLeft-t.offsetLeft,behavior:'smooth'});},4500);})();</script>
<?php endif; ?>

<section class="hero">
  <div class="hero-inner" style="grid-template-columns:1fr">
    <div>
      <span class="eyebrow">Marketplace variety, hypermarket trust</span>
      <h1>Shop smart, live better.</h1>
      <p>One store for everything — daily essentials at hypermarket prices, and a marketplace of independent sellers for everything else. Vetted quality, fast delivery, honest pricing.</p>
      <div class="hero-ctas">
        <a href="#deals" class="btn btn-amber">Explore today's deals</a>
        <a href="seller/register.php" class="btn btn-ghost">Become a seller</a>
      </div>
    </div>
  </div>
  <div class="scallop on-dark"></div>
</section>

<section class="trust-strip">
  <div class="wrap trust-grid">
    <div class="trust-item"><div class="trust-icon">🚚</div><div><h4>Fast delivery</h4><p>Nationwide dispatch, 24–72h</p></div></div>
    <div class="trust-item"><div class="trust-icon">🔒</div><div><h4>Secure checkout</h4><p>Encrypted payments, every order</p></div></div>
    <div class="trust-item"><div class="trust-icon">↺</div><div><h4>Easy returns</h4><p>7-day no-questions policy</p></div></div>
    <div class="trust-item"><div class="trust-icon">✔</div><div><h4>Verified sellers</h4><p>Every store manually checked</p></div></div>
  </div>
</section>

<section class="section" id="categories">
  <div class="wrap">
    <div class="section-head">
      <div><span class="tag">Browse</span><h2>Shop by category</h2></div>
      <a href="search.php" class="section-link">View all</a>
    </div>
    <div class="cat-grid">
      <?php foreach ($categories as $c): ?>
        <a class="cat-card" href="category.php?slug=<?= h($c['slug']) ?>">
          <?= cat_icon_html($c, 'emoji') ?>
          <h4><?= h($c['name']) ?></h4>
          <span class="count"><?= (int) ($counts[$c['id']] ?? 0) ?> items</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php $flash_on = (($site_settings['show_flash'] ?? '1') !== '0') && display_setting_allows($site_settings['disp_flash'] ?? 'both'); if (!empty($site_settings['flash_ends_at']) && strtotime($site_settings['flash_ends_at']) && strtotime($site_settings['flash_ends_at']) < time()) $flash_on = false; if ($flash_on): ?>
<section class="flash" id="deals">
  <div class="scallop to-white" style="transform:rotate(180deg);"></div>
  <div class="flash-bar">
    <div class="flash-left">
      <h3>⚡ Flash deals</h3>
      <span style="color:#9FB0A8;font-size:13px;">Ends today — while stock lasts</span>
    </div>
    <div class="countdown mono" id="countdown">
      <div class="u"><b id="hh">04</b><small>HRS</small></div>
      <div class="u"><b id="mm">12</b><small>MIN</small></div>
      <div class="u"><b id="ss">33</b><small>SEC</small></div>
    </div>
  </div>
  <div class="wrap" style="padding-bottom:56px;">
    <div class="product-grid">
      <?php if ($flash_deals): foreach ($flash_deals as $p): echo render_product_card($p); endforeach; else: ?>
        <p style="color:#9FB0A8;">No flash deals right now — check back soon.</p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section" id="all-products">
  <div class="wrap">
    <div class="section-head">
      <div><span class="tag">Catalog</span><h2>All products</h2></div>
      <a href="search.php" class="section-link">Browse everything</a>
    </div>
    <div class="product-grid">
      <?php if ($all_products): foreach ($all_products as $p): echo render_product_card($p); endforeach; else: ?>
        <p class="muted">No products listed yet.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="combo">
  <div class="wrap">
    <div class="section-head" style="margin-bottom:0;">
      <div><span class="tag">Why <?= SITE_NAME ?></span><h2>Two ways to shop, one checkout</h2></div>
    </div>
    <div class="combo-grid">
      <div class="combo-card">
        <span class="num">Marketplace</span>
        <h3>Thousands of independent sellers</h3>
        <p>Discover fashion, electronics, and niche goods from verified local and regional sellers — with buyer protection on every order.</p>
      </div>
      <div class="combo-card">
        <span class="num">Hypermarket</span>
        <h3>Everyday essentials, stocked and priced right</h3>
        <p>Groceries, household goods, and staples sourced directly and sold at transparent, consistently low prices — no surprise markups.</p>
      </div>
      <div class="combo-card">
        <span class="num">One account</span>
        <h3>A single cart, a single delivery window</h3>
        <p>Mix marketplace finds with hypermarket essentials in one order, tracked in one place, delivered on your schedule.</p>
      </div>
    </div>
  </div>
</section>

<?php require 'includes/footer.php'; ?>
