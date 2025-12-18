<?php

use Xincheng\Health\CheckResult;

$report = $report ?? [];
$checks = $report['checks'] ?? [];
$status = $report['status'] ?? 'unknown';

$colors = [
    CheckResult::STATUS_OK => '#16a34a',
    CheckResult::STATUS_WARNING => '#d97706',
    CheckResult::STATUS_CRITICAL => '#dc2626',
    CheckResult::STATUS_SKIPPED => '#6b7280',
];

$statusColor = $colors[$status] ?? '#0f172a';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Health</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; background: #0f172a; color: #e5e7eb; padding: 24px; }
        .card { background: #111827; border-radius: 12px; padding: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.4); max-width: 960px; margin: 0 auto; }
        h1 { margin: 0 0 8px 0; font-size: 26px; letter-spacing: 0.5px; }
        .status { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 999px; font-size: 14px; font-weight: 600; background: rgba(255,255,255,0.06); }
        .dot { width: 12px; height: 12px; border-radius: 50%; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.06); }
        th { color: #9ca3af; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; }
        td.meta { color: #9ca3af; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Service health</h1>
        <div class="status" style="color: <?= $statusColor ?>; border: 1px solid <?= $statusColor ?>;">
            <span class="dot" style="background: <?= $statusColor ?>;"></span>
            <?= htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8') ?>
            <span style="color:#9ca3af;">·</span>
            <span><?= $report['duration'] ?? 0 ?> ms</span>
            <span style="color:#9ca3af;">·</span>
            <span><?= date('c', $report['timestamp'] ?? time()) ?></span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($checks as $check): ?>
                <?php
                $meta = $check['meta'] ?? [];
                $reason = $meta['reason'] ?? ($meta['error'] ?? '');
                $detail = $reason !== '' ? $reason : json_encode($meta, JSON_UNESCAPED_SLASHES);
                $color = $colors[$check['status']] ?? '#9ca3af';
                ?>
                <tr>
                    <td><?= htmlspecialchars($check['name'] ?? $check['id'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color: <?= $color ?>; font-weight: 600;"><?= htmlspecialchars($check['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $check['duration'] ?? 0 ?> ms</td>
                    <td class="meta"><?= htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
