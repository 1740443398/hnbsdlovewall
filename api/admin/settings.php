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

    $superAdminKeys = ['maintenance_mode', 'maintenance_message', 'register_enabled'];
    if (in_array($key, $superAdminKeys) && $admin['role'] !== 'super_admin') {
        jsonError('只有超级管理员才能修改此设置', 403);
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