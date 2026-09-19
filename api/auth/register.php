<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$ip = getClientIP();
if (!checkRateLimit($ip, 'register')) {
    jsonError('请求过于频繁，请稍后再试', 429);
}

$fs = getFS();

$honeypot = $_POST['website'] ?? '';
if (!empty($honeypot)) {
    $blocked = $fs->read('_honeypot_blocked');
    $blocked[] = ['ip' => $ip, 'time' => date('Y-m-d H:i:s'), 'ua' => ($_SERVER['HTTP_USER_AGENT'] ?? '')];
    $fs->write('_honeypot_blocked', $blocked);
    jsonSuccess([], '注册成功');
}

// 图形/算术验证码校验，防机器人批量注册
// （置于 check_only 分支之后，避免 QQ 查重接口被验证码阻塞）

$formTimestamp = intval($_POST['_form_ts'] ?? 0);
if ($formTimestamp > 0) {
    $elapsed = time() - $formTimestamp;
    if ($elapsed < 3) {
        jsonError('请稍后再试', 429);
    }
    if ($elapsed > 7200) {
        jsonError('表单已过期，请刷新页面后重试', 400);
    }
}

$today = date('Y-m-d');
$ipRegCount = count($fs->find('_ip_registry', ['ip' => $ip, 'date' => $today]));
if ($ipRegCount >= 3) {
    jsonError('今日注册次数已达上限，请明天再试', 429);
}

if (getSetting('register_enabled', '1') != '1') {
    jsonError('管理员已关闭新用户注册', 403);
}

$checkOnly = ($_POST['check_only'] ?? '') === '1';
$qq = sanitizeInput($_POST['qq'] ?? '');
if ($checkOnly) {
    if (!isValidQQ($qq)) {
        jsonError('QQ号格式不正确');
    }
    $fs = getFS();
    $existing = $fs->findOne('users', ['qq' => $qq]);
    if ($existing) {
        jsonError('该QQ号已被注册');
    }
    jsonSuccess([], 'QQ号可用');
}

// 正式注册：校验算术验证码，防机器人批量注册
if (!verifyCaptcha('register', $_POST['captcha'] ?? '')) {
    jsonError('验证码错误或已过期，请刷新后重试');
}

$qq = sanitizeInput($_POST['qq'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';
$nickname = sanitizeInput($_POST['username'] ?? '');

if (!isValidQQ($qq)) {
    jsonError('QQ号格式不正确');
}

$trimmedNickname = trim($nickname);
if ($trimmedNickname === '') {
    jsonError('请输入用户名');
}

$duplicateNick = $fs->findOne('users', ['nickname' => $trimmedNickname]);
if ($duplicateNick !== null) {
    jsonError('该用户名已被使用');
}
$nickname = $trimmedNickname;

$pwdCheck = validatePasswordStrength($password);
if ($pwdCheck !== true) {
    jsonError($pwdCheck);
}

if ($password !== $confirmPassword) {
    jsonError('两次输入的密码不一致');
}

$existing = $fs->findOne('users', ['qq' => $qq]);
if ($existing) {
    jsonError('该QQ号已被注册');
}

// ---- 年级 / 班级 / 真实姓名（可选，后端校验。选科选项已移除） ----
$profile = [
    'entrance_year' => 0,
    'class_num' => 0,
    'subject_first' => '',
    'subject_second' => '',
    'real_name' => ''
];

$gradeIdxRaw = $_POST['grade'] ?? '';
if ($gradeIdxRaw !== '' && $gradeIdxRaw !== null) {
    $gradeIdx = intval($gradeIdxRaw);
    if ($gradeIdx < 0 || $gradeIdx > 3) {
        jsonError('年级选择无效');
    }
    // 已毕业记录为入学高一那年分别在读同学第 A-3 届
    $profile['entrance_year'] = entranceYearFromGrade($gradeIdx);
}

$classNum = intval($_POST['class_num'] ?? 0);
if ($classNum < 0 || $classNum > 20) {
    jsonError('班级需在1-20之间');
}
$profile['class_num'] = $classNum;

$realName = sanitizeInput($_POST['real_name'] ?? '');
if ($realName !== '' && (mb_strlen($realName) < 2 || mb_strlen($realName) > 20)) {
    jsonError('真实姓名长度需在2-20个字符之间');
}
$profile['real_name'] = $realName;

$hashedPassword = hashPassword($password);
$avatar = getQQAvatar($qq);

$user = $fs->insert('users', array_merge([
    'qq' => $qq,
    'password_hash' => $hashedPassword,
    'nickname' => $nickname,
    'avatar' => $avatar,
    'role' => 'user',
    'security_stamp' => generateSecurityStamp(),
    'is_banned' => 0
], $profile));

if (!$user) {
    jsonError('注册失败，请重试');
}

logUserActivity($user['id'], 'register', '注册成功');

$fs->insert('_ip_registry', [
    'ip' => $ip,
    'date' => date('Y-m-d'),
    'qq' => $qq
]);

$_SESSION['user_id'] = $user['id'];
$_SESSION['security_stamp'] = $user['security_stamp'];
$_SESSION['user_role'] = $user['role'];
session_regenerate_id(true);

// 注册成功，尝试发送欢迎邮件（不影响注册结果；失败仅记入日志）
try {
    if (QQMailer::isConfigured()) {
        $nick = htmlspecialchars($nickname, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $siteName = htmlspecialchars(SITE_NAME, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 站长（开发者）公开身份与账号数据，作为「非盗号/诈骗」担保。
        // 从本站 users 表按 DEV_QQ 动态读取；开源版（data 为空）自动退化为通用说明，
        // 因此真实姓名/班级只存在于服务器数据里，不会写死进代码、也不会出现在开源仓库。
        $devName = '';
        $devSnippet = '';
        $devHtmlBlock = '';
        $dev = defined('DEV_QQ') ? getFS()->findOne('users', ['qq' => DEV_QQ]) : null;
        if ($dev && !empty($dev['real_name'])) {
            $nowYear = (int)date('Y');
            $entryYear = (int)($dev['entrance_year'] ?? $nowYear);
            $gradeIdx = max(0, min(3, $nowYear - $entryYear));
            $gMap = ['高一', '高二', '高三', '资深学长'];
            $cnClass = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];
            $cn = (int)($dev['class_num'] ?? 0);
            $clsTxt = ($cn > 0 && $cn <= 10) ? ($cnClass[$cn] . '班') : ($cn . '班');
            $devGrade = ($gMap[$gradeIdx] ?? '高一') . $clsTxt;
            $devName = $devGrade . ' ' . $dev['real_name'];
            // 展示后台真实存储的完整用户记录 JSON，向用户证明「数据就是这样明文 JSON 存储」。
            // 敏感字段（密码哈希、会话/安全戳、2FA密钥）保留键名以展示结构，但隐藏真实值。
            $pub = $dev;
            foreach (['password_hash', 'security_stamp', 'twofa_secret'] as $_sf) {
                if (isset($pub[$_sf]) && $pub[$_sf] !== '') {
                    $pub[$_sf] = '(已隐藏，前端展示结构)';
                }
            }
            $devSnippet = htmlspecialchars(json_encode($pub, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $devHtmlBlock = '<li><strong>站长担保：</strong>我是本站站长 <strong>' . htmlspecialchars($devName) . '</strong>（QQ：1740443398，邮箱：1740443398@qq.com），本人实名注册并使用本站。为防止「盗号/诈骗」的疑虑，以下是我在后台数据库里的<strong>完整账号数据（真实存储样式，敏感字段已打码）</strong>，可供懂的编程的同学核对存储格式：'
                . '<pre style="background:#F6F8FA;border:1px solid #E2E8F0;border-radius:6px;padding:10px;font-size:11px;line-height:1.5;max-height:260px;overflow:auto;margin:8px 0;">' . $devSnippet . '</pre>'
                . '任何关于密码或账号的可疑情况，都可随时通过上方的联系方式找我本人核实。</li>';
        } else {
            $devHtmlBlock = '<li><strong>站长担保：</strong>本站站长本人实名注册并使用本站，遇到任何密码或账号的可疑情况，都可联系站长核实（QQ：1740443398）。</li>';
        }
        $welcomeMailHtml = '<div style="font-family:Arial,\'Microsoft YaHei\',sans-serif;line-height:1.8;color:#1B2A3A;">'
            . '<h2 style="color:#1B3A5C;">欢迎加入「' . $siteName . '」</h2>'
            . '<p>您好，' . $nick . '（QQ：' . htmlspecialchars($qq) . '）：</p>'
            . '<p>欢迎加入『' . $siteName . '』！</p>'
            . '<p>在开始之前，我们想郑重向您说明以下事项，请务必留意：</p>'
            . '<p style="background:#FAECEE;padding:12px 16px;border-radius:6px;"><strong>这不是盗号或诈骗网站。</strong>本平台是学生自发搭建的校园交流平台（<strong>非官方</strong>）。网站托管在免费主机服务商提供的空间上，因此网址看起来可能不像普通的学校官方域名，请放心，这是正常的。</p>'
            . '<ul>'
            . '<li><strong>完全开源：</strong>本站全部代码可在 GitHub 公开审计：<a href="' . GITHUB_REPO_URL . '">' . GITHUB_REPO_NAME . '</a>。您（或任何懂技术的同学）都可以亲自核对代码，确认它是否安全。</li>'
            . $devHtmlBlock
            . '<li><strong>安全感：</strong>本站<strong>绝不会索取</strong>您的 QQ 登录密码、短信验证码或邮箱验证码。请您也切勿向任何个人或所谓「客服」透露自己的密码。</li>'
            . '</ul>'
            . '<p>如果在使用中有任何疑问，欢迎随时联系站长。祝您使用愉快！</p>'
            . '<hr style="border:none;border-top:1px solid #eee;">'
            . '<p style="color:#888;">校园交流墙 站长：Slate</p>'
            . '</div>';
        QQMailer::send($qq . '@qq.com', '欢迎加入' . SITE_NAME, $welcomeMailHtml);
    }
} catch (\Exception $e) {
    error_log('welcome mail failed: ' . $e->getMessage());
}

jsonSuccess(['user' => [
    'id' => $user['id'],
    'qq' => $user['qq'],
    'nickname' => $user['nickname'],
    'avatar' => $user['avatar'],
    'role' => $user['role']
]], '注册成功');