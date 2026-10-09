<?php
require 'config.php';
require 'includes/functions.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?redirect=suggestions.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');
    if ($message === '') {
        $error = 'Please write a suggestion before submitting.';
    } elseif (mb_strlen($message) > 2000) {
        $error = 'Please keep your suggestion under 2000 characters.';
    } else {
        $pdo->prepare('INSERT INTO suggestions (user_id, message) VALUES (?, ?)')->execute([$user_id, $message]);
        $success = true;
    }
}

$page_title = 'Suggestion Box';
require 'includes/header.php';
?>
<section class="wrap section" style="max-width:640px;margin:40px auto;">
  <h1>Suggestion Box</h1>
  <p style="color:var(--muted);">Have an idea to make IANOVA better? We read every suggestion.</p>

  <?php if ($success): ?>
    <div style="background:#e7f4ed;color:#146356;padding:14px 16px;border-radius:9px;margin-top:20px;">
      Thanks — your suggestion has been submitted!
    </div>
  <?php else: ?>
    <?php if ($error): ?>
      <p style="background:#fdeaea;color:#8a2424;padding:12px 14px;border-radius:9px;"><?= h($error) ?></p>
    <?php endif; ?>
    <form method="post" style="margin-top:20px;">
      <textarea name="message" required rows="6" placeholder="Tell us what you think..."
                style="width:100%;padding:14px;border:1px solid var(--line);border-radius:9px;font-family:inherit;"><?= h($_POST['message'] ?? '') ?></textarea>
      <button type="submit" class="btn btn-amber" style="margin-top:14px;">Submit suggestion</button>
    </form>
  <?php endif; ?>
</section>
<?php require 'includes/footer.php'; ?>
