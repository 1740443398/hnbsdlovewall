<?php

require_once __DIR__ . '/../config/config.php';
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
    <title>403 禁止访问 - <?= SITE_NAME ?></title>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <style>
        .error-wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
            background: var(--bg);
        }
        .error-code {
            font-size: 3.25rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.03em;
            line-height: 1;
            margin-bottom: 14px;
        }
        .error-msg { font-size: 1.0625rem; font-weight: 600; color: var(--text); margin-bottom: 10px; }
        .error-desc { font-size: 0.875rem; color: var(--text-secondary); line-height: 1.75; margin-bottom: 28px; max-width: 420px; }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 26px;
            background: var(--primary);
            color: #fff;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9375rem;
            transition: background 0.2s ease;
            min-height: 44px;
        }
        .btn-home:hover { background: var(--primary-hover); color: #fff; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>
    <div class="error-wrap">
        <div class="error-code">403</div>
        <div class="error-msg">你没有权限访问此页面</div>
        <div class="error-desc">该页面需要特定权限才能访问。如果你认为这是个错误，请联系管理员。</div>
        <a href="<?= SITE_URL ?>/" class="btn-home">返回首页</a>
    </div>
</body>
</html>