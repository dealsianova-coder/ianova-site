<section class="section">
  <div class="newsletter">
    <div>
      <h3><?= h($site_settings["newsletter_title"] ?? "Get deals before everyone else") ?></h3>
      <p><?= h($site_settings["newsletter_text"] ?? "Weekly drops, flash sale alerts, and restock notices — straight to your inbox.") ?></p>
    </div>
    <form class="nl-form" action="newsletter.php" method="post">
      <input type="email" name="email" placeholder="<?= h($site_settings["newsletter_placeholder"] ?? "you@email.com") ?>" required>
      <button class="btn btn-amber" type="submit"><?= h($site_settings["newsletter_button"] ?? "Subscribe") ?></button>
    </form>
  </div>
  <?php if (!empty($_SESSION['flash_message'])): ?>
    <p class="wrap flash-msg"><?= h($_SESSION['flash_message']) ?></p>
    <?php unset($_SESSION['flash_message']); ?>
  <?php endif; ?>
</section>

<footer>
  <div class="wrap">
    <div class="footer-grid">
      <div>
        <div class="foot-logo"><?= SITE_NAME ?><span class="dot">.</span></div>
        <p class="tag"><?= h($site_settings['footer_message'] ?? (SITE_SLOGAN . ' Marketplace variety and hypermarket essentials, in one checkout.')) ?></p>
      </div>
      <div>
        <h5>Shop</h5>
        <ul>
          <?php foreach ($categories_nav as $c): ?>
            <li><a href="category.php?slug=<?= h($c['slug']) ?>"><?= h($c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h5>Company</h5>
        <ul>
          <li><a href="about.php">About IANOVA</a></li>
          <li><a href="careers.php">Careers</a></li>
          <li><a href="seller/register.php">Sell with us</a></li>
          <li><a href="press.php">Press</a></li>
        </ul>
      </div>
      <div>
        <h5>Support</h5>
        <ul>
          <li><a href="help.php">Help center</a></li>
          <li><a href="suggestions.php">Suggestion box</a></li>
          <li><a href="cart.php">My cart</a></li>
          <li><a href="returns.php">Returns</a></li>
          <li><a href="contact.php">Contact us</a></li>
        </ul>
      </div>
      <div>
        <h5>Legal</h5>
        <ul>
          <li><a href="terms.php">Terms of service</a></li>
          <li><a href="privacy.php">Privacy policy</a></li>
          <li><a href="seller-agreement.php">Seller agreement</a></li>
        </ul>
      </div>
    </div>
    <div class="foot-bottom">
      <span>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</span>
      <div class="payments">
        <span>VISA</span><span>MASTERCARD</span><span>MOBILE MONEY</span><span>COD</span>
      </div>
    </div>
  </div>
</footer>

<?php $__cur = basename($_SERVER['SCRIPT_NAME'] ?? ''); $__cc = function_exists('cart_count') ? cart_count() : 0; ?>
<nav class="tabbar" aria-label="Primary">
  <a href="index.php" class="<?= $__cur === 'index.php' ? 'is-active' : '' ?>"><span>⌂</span>Home</a>
  <a href="search.php" class="<?= $__cur === 'search.php' ? 'is-active' : '' ?>"><span>⌕</span>Search</a>
  <a href="saved.php" class="<?= $__cur === 'saved.php' ? 'is-active' : '' ?>"><span>♡</span>Saved</a>
  <a href="cart.php" class="<?= $__cur === 'cart.php' ? 'is-active' : '' ?>"><span>🛒</span>Cart<?php if ($__cc > 0): ?><i class="cart-count"><?= (int) $__cc ?></i><?php endif; ?></a>
  <a href="<?= !empty($_SESSION['user_id']) ? 'account.php' : 'login.php' ?>" class="<?= in_array($__cur, ['account.php','login.php','register.php'], true) ? 'is-active' : '' ?>"><span>♙</span>Account</a>
</nav>

<script src="assets/js/backnav.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
