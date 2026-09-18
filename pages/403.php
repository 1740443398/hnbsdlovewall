<?php

require_once __DIR__ . '/../config/config.php';
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 禁止访问 - <?= SITE_NAME ?></title>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= asset_ver('/assets/css/style.css') ?>">
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
            font-size: 8rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--accent-orange));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            margin-bottom: 8px;
        }
        .error-icon { font-size: 5rem; margin-bottom: 16px; }
        .error-msg { font-size: 1.25rem; color: var(--text-secondary); margin-bottom: 24px; }
        .error-desc { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 32px; max-width: 400px; }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: linear-gradient(135deg, #1B3A5C, #142B44);
            color: #fff;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.2s ease;
            min-height: 44px;
            box-shadow: 0 4px 16px rgba(27, 58, 92, 0.2);
        }
        .btn-home:hover { background: linear-gradient(135deg, #2A5078, #1B3A5C); box-shadow: 0 6px 20px rgba(27, 58, 92, 0.3); color: #fff; transform: translateY(-1px); }
    </style>
</head>
<body>
    <div class="error-wrap">
        <div class="error-code">403</div>
        <div class="error-msg">你没有权限访问此页面</div>
        <div class="error-desc">该页面需要特定权限才能访问。如果你认为这是个错误，请联系管理员。</div>
        <a href="<?= SITE_URL ?>/" class="btn-home">返回首页</a>
    </div>
</body>
</html>