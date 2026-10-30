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

/* 表单时间戳检查。
 *
 * 【为什么从 3 秒放宽到 1 秒】
 * 原来是「提交时间 - 表单加载时间 < 3 秒就拒绝」，
 * 目的是挡脚本秒填。但真实用户里手快的（用浏览器自动填充、
 * 或者把信息先打在备忘录里复制粘贴）完全可能在 3 秒内提交完，
 * 他们看到的就是「请稍后再试」—— 而且重试还是同样结果，
 * 因为时间戳没变。
 *
 * 1 秒足够挡住「零延迟脚本」，又不会误伤手快的人。
 */
$formTimestamp = intval($_POST['_form_ts'] ?? 0);
if ($formTimestamp > 0) {
    $elapsed = time() - $formTimestamp;
    if ($elapsed < 1) {
        // 这里返 400 而不是 429：429 会让前端当成「被限流」，
        // 用户不知道该怎么办；400 至少能提示「请重新提交」。
        jsonError('提交太快了，请点一次「注册」按钮', 400);
    }
    if ($elapsed > 7200) {
        jsonError('表单打开太久了（超过 2 小时），请刷新页面后重试', 400);
    }
}

/* 每日每 IP 注册上限。
 *
 * 【为什么从 3 次放宽到 20 次】
 * 校园网的典型结构是「全校一个出口 IP 走 NAT」，
 * 课间几十个同学同时注册时，他们共用同一个 $ip。
 * 3 次的上限意味着：前 3 个人注册成功，后面所有人当天都进不来，
 * 而且提示是「请明天再试」—— 这是最劝退的一类错误。
 *
 * 放宽到 20 次后：
 *   - 正常校园场景（一天几十人注册）基本够用
 *   - 仍然能挡住「一个脚本批量注册几百个号」
 *   - 真正的滥用还有 QQ 唯一性 + 验证码 + 邀请码三道关
 *
 * 如果将来发现被刷，调小这个数字即可，不必改逻辑。
 */
$today = date('Y-m-d');
$ipRegCount = count($fs->find('_ip_registry', ['ip' => $ip, 'date' => $today]));
$__regDailyLimit = function_exists('lwCfgInt') ? lwCfgInt('register_daily_limit', 20) : 20;
if ($ipRegCount >= $__regDailyLimit) {
    jsonError('今日注册次数已达上限（同一网络 ' . $__regDailyLimit . ' 个），请明天再试或换一个网络', 429);
}

$__regOpen = function_exists('lwCfgBool')
    ? lwCfgBool('register_enabled', true)
    : (getSetting('register_enabled', '1') == '1');
if (!$__regOpen) {
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
/* ── 进站门槛：邀请码 或 新生验证题，二选一 ─────────────────────────────
 *
 * 【为什么允许邀请码免答题】
 * 新生验证题（校训 / 校长姓名）是为了挡住「外面的人随便注册」。
 * 但一位已经在站内的同学把邀请码发给他的同桌——这个动作本身就完成了
 * 同样的验证：发码的人认识被邀请的人，并且愿意为他背书。
 *
 * 与其让被邀请的人再答一遍校训，不如认这个邀请关系。
 * 邀请码是 7 位、由站内成员一对一发出的，猜中概率极低。
 *
 * 【关键：邀请码必须是真实存在的】
 * 光看「填了个像邀请码的字符串」就放行是不行的 —— 那样门槛形同虚设，
 * 随便填 "AAAAAAA" 就能进。所以必须先 lwInviteFindInviter() 查出邀请人，
 * 查得到才免答，查不到仍然走验证题。
 */
$inviteCodeRaw = trim((string)($_POST['invite_code'] ?? $_POST['invite'] ?? ''));
$inviteInviter = null;
if ($inviteCodeRaw !== '') {
    $inviteInviter = lwInviteFindInviter($inviteCodeRaw);
}

$__regGate = function_exists('lwCfgBool')
    ? lwCfgBool('register_gate', defined('LW_REGISTER_GATE') && LW_REGISTER_GATE)
    : (defined('LW_REGISTER_GATE') && LW_REGISTER_GATE);
if ($__regGate && $inviteInviter === null) {
    // 没有有效邀请码 → 必须答对新生验证题
    $gateQ = $_POST['gate_q'] ?? '';
    $gateA = $_POST['gate_a'] ?? '';
    $gateNorm = preg_replace('/[\s\x{3000}。，、！？；：·．,!?.;:~～_\-—]+/u', '', $gateA);
    $gateAnswers = ['1' => '我是北师淮实人', '2' => '郝波'];
    if (!isset($gateAnswers[$gateQ])) {
        jsonError('请选择并回答验证问题（或填写邀请码）');
    }
    $gateExpected = $gateAnswers[$gateQ];
    $gateFull = ($gateQ === '1') ? ('我自豪' . $gateExpected) : $gateExpected;
    if ($gateNorm === '' || ($gateNorm !== $gateExpected && $gateNorm !== $gateFull)) {
        jsonError('验证问题回答错误，请确认后重试');
    }
    unset($gateQ, $gateA, $gateNorm, $gateAnswers, $gateExpected, $gateFull);
}
// 有有效邀请码：跳过验证题，直接放行（邀请关系在下面落库）

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

// 欢迎邮件（邮箱由 QQ 号推导，见 includes/mail_service.php）
if (is_file(__DIR__ . '/../../includes/mail_service.php')) {
    require_once __DIR__ . '/../../includes/mail_service.php';
    lwMailNotify('register', (int)$user['id'], [
        'title' => '欢迎加入 ' . (defined('SITE_NAME') ? SITE_NAME : '校园交流墙'),
        'lines' => [
            '你的账号已经创建好了，现在就可以发帖、评论、和大家聊天。',
            '你的登录账号是 QQ 号 ' . $qq . '，请保管好密码。',
            '发帖前建议先看一下社区规范，友善交流能让这里更舒服一些。',
        ],
        'link' => defined('SITE_URL') ? SITE_URL . '/' : '',
        'linkText' => '去首页看看',
    ]);
}

// 邀请归属：带码注册则绑定邀请人、发双方奖励、通知邀请人。
// 这是旁路功能（内部全程容错），任何失败都不影响注册主流程。
$inviteCode = (string)($_POST['invite_code'] ?? $_POST['invite'] ?? '');
if ($inviteCode !== '') {
    lwInviteAttribute((int)$user['id'], $inviteCode);
}

/* ── 新用户默认关注站长 ──────────────────────────────────────────────
 *
 * 站长是唯一会发全站公告的账号（版本更新、规则调整、故障说明）。
 * 新用户不关注他，就收不到任何官方信息 —— 这不是「涨粉」，
 * 是让新人有渠道知道站点发生了什么。
 *
 * 只加一条关注记录，用户随时可以在个人主页取消。
 * 全程容错：失败也不影响注册。
 */
if (function_exists('lwDefaultFollowOwner')) {
    try {
        lwDefaultFollowOwner((int)$user['id']);
    } catch (Throwable $e) {
        error_log('[lw] 默认关注站长失败: ' . $e->getMessage());
    }
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
// 注册即登录，响应里带着新会话 ID：不可被缓存
lwMarkSessionCookieWritten();

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