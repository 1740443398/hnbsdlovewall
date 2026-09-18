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

if ($nickname === '') {
    jsonError('请输入用户名');
}

$nickLen = mb_strlen($nickname);
if ($nickLen < 2 || $nickLen > 20) {
    jsonError('用户名长度需在2-20个字符之间');
}

if (preg_match('/[\r\n<>\/\\\"\'`]/', $nickname)) {
    jsonError('用户名包含不允许的特殊字符');
}

$duplicateNick = $fs->findOne('users', ['nickname' => $nickname]);
if ($duplicateNick !== null) {
    jsonError('该用户名已被使用');
}

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

jsonSuccess(['user' => [
    'id' => $user['id'],
    'qq' => $user['qq'],
    'nickname' => $user['nickname'],
    'avatar' => $user['avatar'],
    'role' => $user['role']
]], '注册成功');