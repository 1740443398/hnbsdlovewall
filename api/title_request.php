<?php
require_once __DIR__ . '/../config/config.php';

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
    jsonError('账号已被封禁');
}

$fs = getFS();
$action = $_POST['action'] ?? '';

if ($action === 'submit') {
    $titleText = sanitizeInput($_POST['title_text'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if ($titleText === '') {
        jsonError('请输入想申请的头衔文字');
    }
    if (mb_strlen($titleText) > 20) {
        jsonError('头衔文字不能超过20个字符');
    }
    if ($reason && mb_strlen($reason) > 200) {
        jsonError('申请说明不能超过200个字符');
    }

    // 每人只能有一个待审核的申请，避免刷屏
    $pending = $fs->findOne('title_requests', ['user_id' => $user['id'], 'status' => 'pending']);
    if ($pending) {
        jsonError('请耐心等待，你已有待审核的头衔申请');
    }

    // 简单频率限制，防止频繁提交
    $recent = $fs->find('title_requests', ['user_id' => $user['id']]);
    foreach ($recent as $r) {
        if ((time() - strtotime($r['created_at'])) < 300) {
            jsonError('请勿频繁提交申请，请稍后再试');
        }
    }

    $fs->insert('title_requests', [
        'user_id' => $user['id'],
        'qq' => $user['qq'],
        'nickname' => $user['nickname'] ?? '',
        'title_text' => $titleText,
        'reason' => $reason,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    jsonSuccess([], '头衔申请已提交，请等待管理员审核');
}

if ($action === 'my') {
    $list = $fs->find('title_requests', ['user_id' => $user['id']]);
    usort($list, function ($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    jsonSuccess($list);
}

jsonError('未知操作');
