<?php
// 维护页自行读取最新提示语与当前用户，不依赖调用方传值，避免旧入口文件导致文案不生效
$maintenanceMsg = function_exists('getSetting')
    ? getSetting('maintenance_message', '网站正在维护中，请稍后再来。')
    : '网站正在维护中，请稍后再来。';
$user = function_exists('getCurrentUser') ? getCurrentUser() : null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
    <?php require_once __DIR__ . '/../includes/theme_dark_tokens.php'; ?>
    <title>网站维护中</title>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <style>
        :root {
            --primary: #2F5B9A;
            --bg: #F4F6F9;
            --bg-secondary: #EEF2F6;
            --card-bg: #FFFFFF;
            --text: #2D3748;
            --text-secondary: #718096;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh;
            background: var(--bg);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            color: var(--text);
        }
        .maintenance-card {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
            text-align: center;
            padding: 40px 32px;
            max-width: 440px; width: 90%;
        }
        .maintenance-icon {
            width: 80px; height: 80px; margin: 0 auto 24px;
            background: var(--bg-secondary);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
        }
        h1 { font-family: 'Noto Serif SC', 'Source Han Serif SC', 'Songti SC', 'STSong', 'SimSun', serif; font-size: 1.6rem; font-weight: 700; letter-spacing: -0.02em; color: var(--text); margin-bottom: 12px; }
        p { color: var(--text-secondary); line-height: 1.6; }
        .admin-link {
            display: inline-block; margin-top: 24px; padding: 12px 32px;
            background: var(--primary);
            color: #fff; text-decoration: none; border-radius: 9999px;
            font-weight: 600;
        }
        .admin-link:hover { opacity: 0.9; }
        .admin-link.secondary { background: var(--bg-secondary); color: var(--text-secondary); margin-left: 8px; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>
    <div class="maintenance-card">
        <div class="maintenance-icon">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none">
                <path d="M12 2L2 7l10 5 10-5-10-5z" fill="#718096" opacity="0.9"/>
                <path d="M2 17l10 5 10-5" stroke="#718096" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M2 12l10 5 10-5" stroke="#718096" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <h1>网站维护中</h1>
        <p><?= htmlspecialchars($maintenanceMsg ?? '网站正在维护中，请稍后再来。') ?></p>
        <?php if ($user && in_array($user['role'], ['admin', 'super_admin'])): ?>
        <a href="/admin/" class="admin-link">进入管理后台</a>
        <?php else: ?>
        <a href="/pages/login.php" class="admin-link">管理员登录</a>
        <?php endif; ?>
    </div>
</body>
</html>
