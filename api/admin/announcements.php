<?php
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

$action = $_POST['action'] ?? '';

if ($action === 'list') {
    $posts = $fs->find('posts', ['category' => 'announcement']);
    $posts = array_values($posts);
    usort($posts, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    $result = [];
    foreach ($posts as $p) {
        $user = $fs->findById('users', $p['user_id']);
        $result[] = [
            'id' => $p['id'],
            'title' => $p['title'],
            'content' => $p['content'],
            'is_active' => $p['status'] === 'published',
            'nickname' => $user['nickname'] ?? '',
            'qq' => $user['qq'] ?? '',
            'created_at' => $p['created_at']
        ];
    }

    jsonSuccess($result);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    if (!checkPermission($admin, 'edit_announcement')) {
        jsonError('没有权限创建公告', 403);
    }
    $title = sanitizeInput($_POST['title'] ?? '');
    $content = sanitizeInput($_POST['content'] ?? '');

    if (!$title) jsonError('标题不能为空');
    if (!$content) jsonError('内容不能为空');

    $post = $fs->insert('posts', [
        'user_id' => $admin['id'],
        'category' => 'announcement',
        'title' => $title,
        'content' => $content,
        'images' => '[]',
        'is_anonymous' => 0,
        'visibility' => 'public',
        'status' => 'published',
        'likes' => 0,
        'comments' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    logOperation($admin['id'], $admin['qq'], 'create_announcement', 'post', $post['id'], '创建公告：' . $title);
    jsonSuccess([], '公告创建成功');
}

if ($action === 'update') {
    if (!checkPermission($admin, 'edit_announcement')) {
        jsonError('没有权限编辑公告', 403);
    }
    $id = intval($_POST['id'] ?? 0);
    $title = sanitizeInput($_POST['title'] ?? '');
    $content = sanitizeInput($_POST['content'] ?? '');
    $isActive = intval($_POST['is_active'] ?? 0);

    if (!$id) jsonError('ID无效');
    if (!$title) jsonError('标题不能为空');
    if (!$content) jsonError('内容不能为空');

    $post = $fs->findById('posts', $id);
    if (!$post || $post['category'] !== 'announcement') {
        jsonError('公告不存在');
    }

    $fs->update('posts', $id, [
        'title' => $title,
        'content' => $content,
        'status' => $isActive ? 'published' : 'rejected',
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    logOperation($admin['id'], $admin['qq'], 'update_announcement', 'post', $id, '更新公告：' . $title);
    jsonSuccess([], '公告更新成功');
}

if ($action === 'delete') {
    if (!checkPermission($admin, 'edit_announcement')) {
        jsonError('没有权限删除公告', 403);
    }
    $id = intval($_POST['id'] ?? 0);
    if (!$id) jsonError('ID无效');

    $post = $fs->findById('posts', $id);
    if (!$post || $post['category'] !== 'announcement') {
        jsonError('公告不存在');
    }

    $fs->delete('posts', $id);
    $comments = $fs->find('comments', ['post_id' => $id]);
    foreach ($comments as $c) {
        $fs->delete('comments', $c['id']);
    }

    logOperation($admin['id'], $admin['qq'], 'delete_announcement', 'post', $id, '删除公告：' . $post['title']);
    jsonSuccess([], '公告删除成功');
}

jsonError('未知操作');