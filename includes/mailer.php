<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email via Gmail SMTP. Returns true on success, false on failure
 * (failures are logged, never thrown, so checkout never breaks over email).
 */
function send_mail($to, $to_name, $subject, $html_body) {
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
