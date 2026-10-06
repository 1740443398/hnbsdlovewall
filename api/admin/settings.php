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

$fs = getFS();

$action = $_POST['action'] ?? '';

if ($action === 'save') {
    $key = sanitizeInput($_POST['key'] ?? '');
    $value = sanitizeInput($_POST['value'] ?? '');

    // 仅站长可修改的设置
    $superAdminKeys = ['maintenance_mode', 'maintenance_message', 'register_enabled', 'site_name', 'invite_enabled'];
    if (in_array($key, $superAdminKeys, true) && $admin['role'] !== 'super_admin') {
        jsonError('只有超级管理员才能修改此设置', 403);
    }

    // 需具体权限的设置项（防止只读模版 T1 管理员越权修改全站配置）
    $keyPermissionMap = [
        'announcement' => 'edit_announcement',
        'community_rules' => 'edit_rules',
    ];
    if (isset($keyPermissionMap[$key])) {
        if (!checkPermission($admin, $keyPermissionMap[$key])) {
            jsonError('无权限修改此设置', 403);
        }
    } elseif (!in_array($key, $superAdminKeys, true)) {
        // 未登记的新设置项：默认仅站长可改，避免权限遗漏被越权
        if ($admin['role'] !== 'super_admin') {
            jsonError('无权限修改此设置', 403);
        }
    }

    updateSetting($key, $value);
    jsonSuccess([], '保存成功');
}

if ($action === 'save_words') {
    if ($admin['role'] !== 'super_admin') {
        jsonError('只有超级管理员才能修改敏感词', 403);
    }
    $words = sanitizeInput($_POST['words'] ?? '');
    $wordList = array_filter(array_map('trim', explode("\n", $words)));

    $existing = $fs->read('sensitive_words');
    foreach ($existing as $w) {
        $fs->delete('sensitive_words', $w['id']);
    }

    foreach ($wordList as $word) {
        if ($word) {
            $fs->insert('sensitive_words', ['word' => $word]);
        }
    }

    jsonSuccess([], '保存成功');
}

jsonError('未知操作');