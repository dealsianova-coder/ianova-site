<?php
/** Extra "needs your attention" counts for the dashboards (website and Admin app). Each one fails safe to 0. */
function attn_counts(PDO $pdo): array {
    $n = fn(string $sql) => (function () use ($pdo, $sql): int { try { return (int) $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } })();
    $out = [
        'old_pending' => $n("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
        'stuck_shipped' => $n("SELECT COUNT(*) FROM orders WHERE status = 'shipped' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'to_collect' => 0,
        'payouts_due' => 0,
        'backup_old' => 1,
    ];
    // newest database backup (same folder the backup tool writes to); none in 3 days = needs attention
    $bdir = rtrim((string) (getenv('IANOVA_BACKUP_DIR') ?: (getenv('HOME') . '/ianova-backups')), '/');
    $files = @glob($bdir . '/ianova-*.sql.gz') ?: [];
    $newest = 0; foreach ($files as $f) $newest = max($newest, (int) @filemtime($f));
    $out['backup_old'] = ($newest > 0 && $newest > time() - 3 * 86400) ? 0 : 1;
    try { require_once __DIR__ . '/payments.php'; $out['to_collect'] = count(pay_balances($pdo, 'part', 400)); } catch (Throwable $e) { }
    try {
        require_once __DIR__ . '/payouts.php';
        $due = 0; foreach (po_figures($pdo) as $f) if ((float) $f['balance'] > 0.009) $due++;
        $out['payouts_due'] = $due;
    } catch (Throwable $e) { }
    return $out;
}
