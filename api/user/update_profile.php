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

$fs = getFS();
$fs->update('users', $user['id'], $update);

logUserActivity($user['id'], 'profile_update', '更新个人资料');

jsonSuccess([], '资料更新成功');