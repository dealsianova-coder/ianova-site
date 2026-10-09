<?php
// Composer's autoloader provides PHPMailer (run `composer install` in this folder).
$__autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($__autoload)) { require_once $__autoload; }

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email via Gmail SMTP. Returns true on success, false on failure
 * (failures are logged, never thrown, so checkout never breaks over email).
 */
function send_mail($to, $to_name, $subject, $html_body) {
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('Mail not sent: PHPMailer is missing. Run `composer install`.');
        return false;
    }
    if (SMTP_PASS === '') {
        error_log('Mail not sent: SMTP_PASS is empty. Set it in config.local.php.');
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_USER, SITE_NAME);
        $mail->addAddress($to, $to_name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html_body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mail send failed: ' . $mail->ErrorInfo);
        return false;
    }
}

function send_order_emails(PDO $pdo, int $order_id, string $customer_name, string $customer_email, array $items, float $grand_total) {
    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee;">' . h($item['product']['name']) . ' × ' . (int)$item['qty'] . '</td><td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;">' . CURRENCY . number_format($item['subtotal'], 2) . '</td></tr>';
    }

    // Customer confirmation
    $customer_html = '<div style="font-family:Arial,sans-serif;color:#0B2B1D;max-width:520px;">'
        . '<h2>Thanks for your order, ' . h($customer_name) . '!</h2>'
        . '<p>Your order <strong>#' . $order_id . '</strong> has been received and is being prepared.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:12px;">' . $rows . '</table>'
        . '<p style="margin-top:14px;font-size:16px;"><strong>Total: ' . CURRENCY . number_format($grand_total, 2) . '</strong></p>'
        . '<p style="margin-top:20px;color:#6b7973;font-size:13px;">— ' . SITE_NAME . '</p>'
        . '</div>';
    send_mail($customer_email, $customer_name, 'Your ' . SITE_NAME . ' order #' . $order_id . ' is confirmed', $customer_html);

    // Admin alert
    $admin_html = '<div style="font-family:Arial,sans-serif;color:#0B2B1D;max-width:520px;">'
        . '<h2>New order #' . $order_id . '</h2>'
        . '<p><strong>Customer:</strong> ' . h($customer_name) . ' (' . h($customer_email) . ')</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:12px;">' . $rows . '</table>'
        . '<p style="margin-top:14px;font-size:16px;"><strong>Total: ' . CURRENCY . number_format($grand_total, 2) . '</strong></p>'
        . '</div>';
    send_mail(ADMIN_EMAIL, 'Admin', 'New order #' . $order_id . ' — ' . CURRENCY . number_format($grand_total, 2), $admin_html);
}


/**
 * Tell a seller whether their application was approved or rejected.
 * Returns true when the email was handed to the mail server.
 */
function send_seller_status_email(string $email, string $business, string $status): bool {
    $login = (defined('SITE_URL') && SITE_URL !== '') ? rtrim(SITE_URL, '/') . '/seller/login.php' : '';
    $wrap = function (string $inner): string {
        return '<div style="font-family:Arial,sans-serif;color:#0F0F12;max-width:520px;line-height:1.5;">'
            . $inner
            . '<p style="margin-top:24px;color:#5F6575;font-size:13px;">— ' . h(SITE_NAME) . ' team</p></div>';
    };

    if ($status === 'approved') {
        $btn = $login !== ''
            ? '<p><a href="' . h($login) . '" style="display:inline-block;background:#0F0F12;color:#fff;padding:12px 22px;border-radius:12px;text-decoration:none;font-weight:700;">Open seller area</a></p>'
            : '<p>Sign in on the ' . h(SITE_NAME) . ' website with the email and password you used to apply.</p>';
        $html = $wrap('<h2>You are approved, ' . h($business) . '!</h2>'
            . '<p>Your ' . h(SITE_NAME) . ' seller account is now active. You can add products, set prices and manage stock.</p>'
            . $btn
            . '<p style="color:#5F6575;font-size:13px;">New products may be reviewed before they appear in the store.</p>');
        return send_mail($email, $business, 'Your ' . SITE_NAME . ' seller account is approved', $html);
    }

    if ($status === 'rejected') {
        $html = $wrap('<h2>About your seller application</h2>'
            . '<p>Hello ' . h($business) . ', thank you for applying to sell on ' . h(SITE_NAME) . '.</p>'
            . '<p>We are not able to approve your application right now. You are welcome to reply to this email with more details about your business and we will take another look.</p>');
        return send_mail($email, $business, 'Update on your ' . SITE_NAME . ' seller application', $html);
    }

    return false;
}


/** Email a seller the admin's reply (it also shows in the app). */
function send_seller_reply_email(string $email, string $business, string $message): bool {
    $html = '<div style="font-family:Arial,sans-serif;color:#0F0F12;max-width:520px;line-height:1.5;">'
        . '<h2>New message from ' . h(SITE_NAME) . '</h2>'
        . '<p>Hello ' . h($business) . ',</p>'
        . '<div style="background:#F3F4F7;border-radius:16px;padding:14px 16px;white-space:pre-wrap;">' . h($message) . '</div>'
        . '<p style="margin-top:16px;">Reply in the ' . h(SITE_NAME) . ' app: Profile, then Sell on ' . h(SITE_NAME) . '.</p>'
        . '<p style="margin-top:24px;color:#5F6575;font-size:13px;">— ' . h(SITE_NAME) . ' team</p></div>';
    return send_mail($email, $business, 'New message about your ' . SITE_NAME . ' seller account', $html);
}

/** Tell the admin a seller wrote or applied. */
function send_admin_seller_alert(string $business, string $email, string $message): bool {
    if (!defined('ADMIN_EMAIL') || ADMIN_EMAIL === '') return false;
    $html = '<div style="font-family:Arial,sans-serif;color:#0F0F12;max-width:520px;line-height:1.5;">'
        . '<h2>Seller message</h2>'
        . '<p><strong>' . h($business) . '</strong> (' . h($email) . ')</p>'
        . '<div style="background:#F3F4F7;border-radius:16px;padding:14px 16px;white-space:pre-wrap;">' . h($message) . '</div>'
        . '<p style="margin-top:16px;">Reply from Admin, then Sellers, then Messages.</p></div>';
    return send_mail(ADMIN_EMAIL, 'Admin', 'Seller message from ' . $business, $html);
}
