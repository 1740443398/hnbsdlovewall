<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/invite.php';

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

// 新生验证题（二选一，答对方可注册）—— 由站点档案开关控制，只有线上版启用
// （比赛演示版 LW_REGISTER_GATE=false，整段跳过，前端也不会渲染题目）
// 归一化：忽略空白与全半角标点；Q1 允许把整句呼号「我自豪，我是北师淮实人」照抄进来
if (LW_REGISTER_GATE) {
    $gateQ = $_POST['gate_q'] ?? '';
    $gateA = $_POST['gate_a'] ?? '';
    $gateNorm = preg_replace('/[\s\x{3000}。，、！？；：·．,!?.;:~～_\-—]+/u', '', $gateA);
    $gateAnswers = ['1' => '我是北师淮实人', '2' => '郝波'];
    if (!isset($gateAnswers[$gateQ])) {
        jsonError('请选择并回答验证问题');
    }
    $gateExpected = $gateAnswers[$gateQ];
    $gateFull = ($gateQ === '1') ? ('我自豪' . $gateExpected) : $gateExpected;
    if ($gateNorm === '' || ($gateNorm !== $gateExpected && $gateNorm !== $gateFull)) {
        jsonError('验证问题回答错误，请确认后重试');
    }
    unset($gateQ, $gateA, $gateNorm, $gateAnswers, $gateExpected, $gateFull);
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
    'uuid' => generateUUID(),
    'password_hash' => $hashedPassword,
    'nickname' => $nickname,
    'avatar' => $avatar,
    'role' => 'user',
    'security_stamp' => generateSecurityStamp(),
    'is_banned' => 0,
    // 注册即分配邀请码：老用户的邀请码由 lwInviteEnsureCode 懒补，新用户当场就位
    'invite_code' => lwInviteAllocate($fs),
    'invited_by' => 0,
], $profile));

if (!$user) {
    jsonError('注册失败，请重试');
}

logUserActivity($user['id'], 'register', '注册成功');

// 邀请归属：带码注册则绑定邀请人、发双方奖励、通知邀请人。
// 这是旁路功能（内部全程容错），任何失败都不影响注册主流程。
$inviteCode = (string)($_POST['invite_code'] ?? $_POST['invite'] ?? '');
if ($inviteCode !== '') {
    lwInviteAttribute((int)$user['id'], $inviteCode);
}

$fs->insert('_ip_registry', [
    'ip' => $ip,
    'date' => date('Y-m-d'),
    'qq' => $qq
]);

$_SESSION['user_id'] = $user['id'];
$_SESSION['security_stamp'] = $user['security_stamp'];
$_SESSION['user_role'] = $user['role'];
disableGuestMode();
session_regenerate_id(true);

markDeviceKnown();

// 仪表盘数据变化提醒：有新用户注册，通知可访问后台的管理员
try {
    notifyAdmins('新用户注册：' . $nickname . '（QQ：' . $qq . '）', 'admin');
} catch (\Exception $e) {
    error_log('notifyAdmins on register failed: ' . $e->getMessage());
}

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
            // 站长姓名/班级交给邮件模板渲染（模板里再套 HTML，这里只给纯文本）
            $devNameForMail = $devName;
        }
        // 邮件正文统一由 includes/mail_templates.php 提供（后台「测试邮件」发的是同一份）
        require_once __DIR__ . '/../../includes/mail_templates.php';
        $mail = lwMailBuild('welcome', [
            'nick'             => $nick,
            'qq'               => $qq,
            'dev_name'         => isset($devNameForMail) ? $devNameForMail : '',
            'dev_snippet_html' => isset($devSnippet) ? $devSnippet : '',
        ]);
        QQMailer::send($qq . '@qq.com', $mail['subject'], $mail['html']);
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