<?php
define('SITE_NAME', '淮南北师大实验中学高中部校园交流墙');
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
             (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
             (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
define('SITE_URL', $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
define('BASE_PATH', __DIR__ . '/..');
define('UPLOAD_DIR', BASE_PATH . '/uploads');
define('UPLOAD_IMAGES_DIR', UPLOAD_DIR . '/images');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);
define('MAX_SINGLE_IMAGE_SIZE', 5 * 1024 * 1024);
define('DAILY_UPLOAD_LIMIT', 50 * 1024 * 1024);
define('POST_COOLDOWN', 300);
define('POST_LIMIT_SHORT', 5);
define('SESSION_LIFETIME', 86400);
define('COOKIE_REMEMBER_DAYS', 15);
define('BCRYPT_COST', 10);
define('TWOFA_LOCKOUT_ATTEMPTS', 5);
define('TWOFA_LOCKOUT_MINUTES', 10);
define('SUPER_ADMIN_QQ', 'admin');
define('SUPER_ADMIN_NICKNAME', '站长');
define('ITEMS_PER_PAGE', 20);

// 预期访问域名白名单：浏览器地址栏域名必须在此名单内才视为本平台，否则可能被 DNS 劫持/仿冒站
// 线上主站 https://hnbsd.ct.ws，另放行本地开发 127.0.0.1 / localhost（含各端口）
define('EXPECTED_HOSTS', ['hnbsd.ct.ws', 'www.hnbsd.ct.ws', '127.0.0.1', 'localhost']);

// GitHub 开源仓库地址
define('GITHUB_REPO_URL', 'https://github.com/1740443398/hnbsdlovewall');
define('GITHUB_REPO_NAME', 'hnbsdlovewall');