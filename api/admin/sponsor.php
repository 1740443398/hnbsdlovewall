<?php
require_once __DIR__ . '/../../config/config.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
if ($adminUser['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$action = $_POST['action'] ?? '';

if ($action === 'update') {
    $currentAmount = floatval($_POST['current_amount'] ?? 0);
    $fs->update('sponsor', 1, ['current_amount' => $currentAmount]);
    logOperation($adminUser['id'], $adminUser['qq'], 'update_sponsor', 'sponsor', '1', '更新赞助金额');
    jsonSuccess(['current_amount' => $currentAmount], '赞助信息更新成功');
}

if ($action === 'update_list') {
    $sponsorList = $_POST['sponsor_list'] ?? '[]';
    $list = json_decode($sponsorList, true);
    if (!is_array($list)) {
        jsonError('赞助榜单格式错误');
    }
    $totalAmount = 0;

    $cleanList = [];
    foreach ($list as $item) {
        $cleanList[] = [
            'qq' => sanitizeInput($item['qq'] ?? ''),
            'name' => sanitizeInput($item['name'] ?? ''),
            'amount' => floatval($item['amount'] ?? 0)
        ];
        $totalAmount += floatval($item['amount'] ?? 0);
    }
    $fs->update('sponsor', 1, [
        'total_amount' => $totalAmount,
        'sponsor_list' => json_encode($cleanList, JSON_UNESCAPED_UNICODE)
    ]);
    logOperation($adminUser['id'], $adminUser['qq'], 'update_sponsor_list', 'sponsor', '1', '更新赞助榜单');
    jsonSuccess(['total_amount' => $totalAmount, 'sponsor_list' => $cleanList], '赞助榜单更新成功');
}

if ($action === 'add_sponsor') {
    $qq = sanitizeInput($_POST['qq'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);

    if (empty($qq)) {
        jsonError('请输入赞助者QQ号');
    }
    if ($amount <= 0) {
        jsonError('请输入有效的赞助金额');
    }

    $users = $fs->read('users');
    $foundUser = null;
    foreach ($users as $u) {
        if (isset($u['qq']) && $u['qq'] == $qq) {
            $foundUser = $u;
            break;
        }
    }

    $nickname = $foundUser ? ($foundUser['nickname'] ?? '未知用户') : '未知用户';

    $sponsorRecord = $fs->findById('sponsor', 1);
    if (!$sponsorRecord) {
        $fs->insert('sponsor', [
            'current_amount' => $amount,
            'total_amount' => $amount,
            'sponsor_list' => json_encode([['qq' => $qq, 'name' => $nickname, 'amount' => $amount]], JSON_UNESCAPED_UNICODE)
        ]);
    } else {
        $list = json_decode($sponsorRecord['sponsor_list'] ?? '[]', true) ?: [];
        $list[] = ['qq' => $qq, 'name' => $nickname, 'amount' => $amount];

        $totalAmount = 0;
        foreach ($list as $item) {
            $totalAmount += floatval($item['amount']);
        }

        $fs->update('sponsor', 1, [
            'current_amount' => floatval($sponsorRecord['current_amount'] ?? 0) + $amount,
            'total_amount' => $totalAmount,
            'sponsor_list' => json_encode($list, JSON_UNESCAPED_UNICODE)
        ]);
    }

    logOperation($adminUser['id'], $adminUser['qq'], 'add_sponsor', 'sponsor', '1', '添加赞助者：' . $qq . ' ' . $nickname . ' ' . $amount . '元');
    jsonSuccess(['qq' => $qq, 'name' => $nickname, 'amount' => $amount], '赞助者添加成功');
}

if ($action === 'delete_sponsor') {
    $index = intval($_POST['index'] ?? -1);
    if ($index < 0) {
        jsonError('无效的赞助记录索引');
    }

    $sponsorRecord = $fs->findById('sponsor', 1);
    if (!$sponsorRecord) {
        jsonError('赞助记录不存在');
    }

    $list = json_decode($sponsorRecord['sponsor_list'] ?? '[]', true) ?: [];
    if (!isset($list[$index])) {
        jsonError('赞助记录不存在');
    }

    $deleted = $list[$index];
    array_splice($list, $index, 1);

    $totalAmount = 0;
    foreach ($list as $item) {
        $totalAmount += floatval($item['amount']);
    }

    $fs->update('sponsor', 1, [
        'total_amount' => $totalAmount,
        'sponsor_list' => json_encode($list, JSON_UNESCAPED_UNICODE)
    ]);

    logOperation($adminUser['id'], $adminUser['qq'], 'delete_sponsor', 'sponsor', '1', '删除赞助者：' . $deleted['name']);
    jsonSuccess([], '赞助记录已删除');
}

jsonError('未知操作');