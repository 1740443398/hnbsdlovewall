<?php
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/config.php';

$fs = getFS();
$method = $_SERVER['REQUEST_METHOD'];

// 列表：返回所有功能建议及其票数、当前用户是否已投
if ($method === 'GET') {
    $user = getCurrentUser();
    $features = $fs->orderBy('feature_requests', 'created_at', 'DESC');
    $allVotes = $fs->getAll('feature_votes');
    $counts = [];
    foreach ($allVotes as $v) {
        if (empty($v['feature_id'])) continue;
        $fid = (int)$v['feature_id'];
        $counts[$fid] = ($counts[$fid] ?? 0) + 1;
    }
    $votedByMe = [];
    if ($user && !empty($user['qq'])) {
        $myVotes = $fs->find('feature_votes', ['qq' => $user['qq']]);
        $votedByMe = array_map('intval', array_column($myVotes, 'feature_id'));
    }
    $result = array_map(function ($f) use ($counts, $votedByMe) {
        $fid = (int)$f['id'];
        $f['vote_count'] = $counts[$fid] ?? 0;
        $f['voted'] = in_array($fid, $votedByMe);
        return $f;
    }, $features);
    jsonSuccess($result);
    exit;
}

if ($method !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败，请刷新页面后重试', 403);
}

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁，无法操作');
}

$ip = getClientIP();
if (!checkRateLimit($ip, 'feature_vote', 10, 60)) {
    jsonError('操作过于频繁，请稍后再试', 429);
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $title = trim($_POST['title'] ?? '');
    if (mb_strlen($title) < 2) {
        jsonError('功能建议不能少于2个字符');
    }
    if (mb_strlen($title) > 500) {
        jsonError('功能建议不能超过500个字符');
    }
    if (!empty(checkSensitiveWords($title))) {
        jsonError('内容包含敏感词，无法提交');
    }
    $existing = $fs->find('feature_requests', ['qq' => $user['qq']]);
    if (count($existing) >= 20) {
        jsonError('单用户最多提交20条功能建议');
    }
    $created = $fs->insert('feature_requests', [
        'user_id' => $user['id'],
        'qq' => $user['qq'],
        'nickname' => $user['nickname'] ?? '用户',
        'title' => $title,
        'status' => 'pending'
    ]);
    if (!$created) {
        jsonError('提交失败，请稍后重试');
    }
    logUserActivity($user['id'], 'feature_propose', '提交功能建议ID:' . $created['id']);
    jsonSuccess(['id' => $created['id']], '提交成功');
}

if ($action === 'vote') {
    $featureId = intval($_POST['feature_id'] ?? 0);
    if (!$featureId) {
        jsonError('参数无效');
    }
    $feature = $fs->findById('feature_requests', $featureId);
    if (!$feature) {
        jsonError('该功能建议不存在');
    }
    $existing = $fs->findOne('feature_votes', ['feature_id' => $featureId, 'qq' => $user['qq']]);
    if ($existing) {
        $fs->delete('feature_votes', $existing['id']);
        jsonSuccess([
            'voted' => false,
            'vote_count' => $fs->count('feature_votes', ['feature_id' => $featureId])
        ], '已取消投票');
    } else {
        $fs->insert('feature_votes', [
            'feature_id' => $featureId,
            'qq' => $user['qq'],
            'user_id' => $user['id']
        ]);
        jsonSuccess([
            'voted' => true,
            'vote_count' => $fs->count('feature_votes', ['feature_id' => $featureId])
        ], '投票成功');
    }
}

jsonError('未知操作');