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

// 游客模式：未注册访客可浏览的动态条数上限，其余功能需注册账号
define('GUEST_VISIBLE_POSTS', 3);

// 预期访问域名白名单：浏览器地址栏域名必须在此名单内才视为本平台，否则可能被 DNS 劫持/仿冒站
// 线上主站 https://hnbsd.ct.ws，另放行本地开发 127.0.0.1 / localhost（含各端口）
define('EXPECTED_HOSTS', ['hnbsd.ct.ws', 'www.hnbsd.ct.ws', '127.0.0.1', 'localhost']);

// GitHub 开源仓库地址
define('GITHUB_REPO_URL', 'https://github.com/1740443398/hnbsdlovewall');
define('GITHUB_REPO_NAME', 'hnbsdlovewall');

// 站长/开发者公开账号 QQ（用于欢迎邮件中的身份担保，从 users 表动态读取其真实信息）
define('DEV_QQ', '1740443398');
define('DEV_EMAIL', '1740443398@qq.com');

// ── 站点档案（线上版 / 比赛演示版的差异开关）────────────────────────────────
// 差异全部声明在 config/site_profile.php 这一个数据文件里（各站自行维护、
// 同步时有意排除），代码里只判断下面这些常量，不再出现「两份各自分叉的代码」。
// 说明与新增方式见 config/site_profile.php 顶部注释。
$_lwProfileFile = __DIR__ . '/site_profile.php';
$_lwProfile = is_file($_lwProfileFile) ? (array) require $_lwProfileFile : [];

// 新生验证题：注册时须答对一道校情题。线上 true / 比赛版 false。
define('LW_REGISTER_GATE', !empty($_lwProfile['register_gate']));

unset($_lwProfile, $_lwProfileFile);