<?php
require_once __DIR__ . '/../../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

if (!in_array($admin['role'], ['admin', 'super_admin'])) {
    jsonError('权限不足', 403);
}

$fs = getFS();
$action = $_POST['action'] ?? '';

if ($action === 'set_title') {
    $userId = intval($_POST['user_id'] ?? 0);
    if (!$userId) {
        jsonError('用户ID无效');
    }

    $user = $fs->findById('users', $userId);
    if (!$user) {
        jsonError('用户不存在');
    }

    $titleText = sanitizeInput($_POST['title_text'] ?? '');
    $titleColor = trim($_POST['title_color'] ?? '');
    $titleBgColor = trim($_POST['title_bg_color'] ?? '');
    $titleRainbow = intval($_POST['title_rainbow'] ?? 0);
    $gradientStart = trim($_POST['gradient_start'] ?? '');
    $gradientEnd = trim($_POST['gradient_end'] ?? '');

    if ($titleText && mb_strlen($titleText) > 20) {
        jsonError('头衔文字不能超过20个字符');
    }

    if ($titleColor && !preg_match('/^#[0-9a-fA-F]{6}$/', $titleColor)) {
        jsonError('文字颜色格式无效');
    }

    if ($titleBgColor && !preg_match('/^#[0-9a-fA-F]{6}$/', $titleBgColor)) {
        jsonError('背景颜色格式无效');
    }

    if ($gradientStart && !preg_match('/^#[0-9a-fA-F]{6}$/', $gradientStart)) {
        jsonError('渐变起始颜色格式无效（请输入16进制如 #ff0000）');
    }
    if ($gradientEnd && !preg_match('/^#[0-9a-fA-F]{6}$/', $gradientEnd)) {
        jsonError('渐变结束颜色格式无效');
    }

    // 若开启彩虹但未填两端颜色，降级使用默认预置色（兼容旧数据）
    if ($titleRainbow && (!$gradientStart || !$gradientEnd)) {
        $gradientStart = '#ff4757';
        $gradientEnd = '#a55eea';
    }

    $updateData = [
        'title_text' => $titleText,
        'title_color' => $titleColor,
        'title_bg_color' => $titleBgColor,
        'title_rainbow' => $titleRainbow,
        'title_gradient_start' => $titleRainbow ? strtolower($gradientStart) : '',
        'title_gradient_end' => $titleRainbow ? strtolower($gradientEnd) : '',
    ];

    if ($fs->update('users', $userId, $updateData)) {
        logOperation($admin['id'], $admin['qq'], 'set_user_title', 'user', $userId, '头衔: ' . $titleText);
        jsonSuccess([], '头衔设置成功');
    } else {
        jsonError('设置失败');
    }
}

if ($action === 'remove_title') {
    $userId = intval($_POST['user_id'] ?? 0);
    if (!$userId) {
        jsonError('用户ID无效');
    }

    $user = $fs->findById('users', $userId);
    if (!$user) {
        jsonError('用户不存在');
    }

    $fs->update('users', $userId, [
        'title_text' => '',
        'title_color' => '',
        'title_bg_color' => '',
        'title_rainbow' => 0,
        'title_gradient_start' => '',
        'title_gradient_end' => '',
    ]);

    logOperation($admin['id'], $admin['qq'], 'remove_user_title', 'user', $userId, '移除头衔');
    jsonSuccess([], '头衔已移除');
}

jsonError('未知操作');