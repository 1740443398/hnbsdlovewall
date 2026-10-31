<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$user = requireLogin();

$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁，无法修改资料');
}

$nickname = sanitizeInput($_POST['nickname'] ?? '');
$bio = sanitizeInput($_POST['bio'] ?? '');

$trimmedNickname = trim($nickname);
if ($trimmedNickname === '') {
    jsonError('昵称不能为空');
}
// 不重名
$duplicateNick = getFS()->findOne('users', ['nickname' => $trimmedNickname]);
if ($duplicateNick !== null && (int)$duplicateNick['id'] !== (int)$user['id']) {
    jsonError('该昵称已被他人使用');
}
$nickname = $trimmedNickname;

$update = ['nickname' => $nickname, 'bio' => $bio];

// ---- 年级 / 班级 / 选科 / 真实姓名（可选，可再次修改） ----
$gradeIdxRaw = $_POST['grade'] ?? '';
if ($gradeIdxRaw !== '' && $gradeIdxRaw !== null) {
    $gradeIdx = intval($gradeIdxRaw);
    if ($gradeIdx < 0 || $gradeIdx > 3) {
        jsonError('年级选择无效');
    }
    $update['entrance_year'] = entranceYearFromGrade($gradeIdx);
} elseif ($gradeIdxRaw === '') {
    $update['entrance_year'] = 0;
}

$classNum = intval($_POST['class_num'] ?? -1);
if ($classNum !== -1) {
    if ($classNum < 0 || $classNum > 20) {
        jsonError('班级需在1-20之间');
    }
    $update['class_num'] = $classNum;
}

$realName = sanitizeInput($_POST['real_name'] ?? '');
if ($realName !== '') {
    if (mb_strlen($realName) < 2 || mb_strlen($realName) > 20) {
        jsonError('真实姓名长度需在2-20个字符之间');
    }
    $update['real_name'] = $realName;
} elseif (isset($_POST['real_name']) && $realName === '') {
    $update['real_name'] = '';
}

// 选科
$firstSub = sanitizeInput($_POST['first_subject'] ?? '');
$secondSub = $_POST['second_subject'] ?? [];
if (is_string($secondSub)) {
    $secondSub = array_map('trim', explode(',', $secondSub));
}
if (!is_array($secondSub)) {
    $secondSub = [];
}
$secondSub = array_values(array_filter($secondSub, function ($s) {
    return $s !== '';
}));
$secondSub = array_values(array_unique($secondSub));

if ($firstSub === '' && empty($secondSub)) {
    // 用户选择清空选科
    $update['subject_first'] = '';
    $update['subject_second'] = '';
} else {
    $subRes = validateSubjectSelection($firstSub, $secondSub);
    if ($subRes !== true) {
        jsonError($subRes);
    }
    $update['subject_first'] = $firstSub;
    $update['subject_second'] = implode(',', array_slice($secondSub, 0, 2));
}

// ---- 个性头衔（商城装扮，不是开放填写）----
// 规则（站长口径）：头衔是**购买**得到的装扮，没买过就没有填写资格。
//   · 已有头衔但槽位过期：保留旧文字不清空（用户只是不能再改），避免"付费内容被收回"的观感；
//   · 想改 / 想设新头衔：必须有至少 1 个有效槽位，否则直接拒绝并提示去商城；
//   · 内容只做两件事：长度上限 + 违规词拦截（checkSensitiveWords 是唯一词库入口）。
if (isset($_POST['title_text'])) {
    $titleText = trim(sanitizeInput($_POST['title_text'] ?? ''));
    $currentTitle = (string)($user['title_text'] ?? '');

    if ($titleText !== $currentTitle) {
        if (!function_exists('lwUserTitleSlots')) {
            require_once __DIR__ . '/../../includes/widgets.php';
        }
        if (lwUserTitleSlots((int)$user['id']) < 1) {
            jsonError('自定义头衔需先在装扮商城购买「头衔」类装扮');
        }
        if ($titleText === '') {
            $update['title_text'] = '';
        } else {
            if (mb_strlen($titleText) > 12) {
                jsonError('头衔最多 12 个字');
            }
            $hits = checkSensitiveWords($titleText);
            if (!empty($hits)) {
                jsonError('头衔包含违规词：' . implode('、', array_slice($hits, 0, 3)));
            }
            $update['title_text'] = $titleText;
        }
    }
}

$fs = getFS();
$fs->update('users', $user['id'], $update);

logUserActivity($user['id'], 'profile_update', '更新个人资料');

jsonSuccess([], '资料更新成功');