<?php
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

/**
 * 删除指定用户及其关联内容。
 * 覆盖：用户、该用户的帖子（含其帖子下的评论/点赞/收藏）、该用户的评论（含子回复）、
 * 通知、点赞/收藏记录、关注关系、管理员权限分配。
 */
function deleteUserData($fs, $userId, $userQq) {
    // 1. 用户本身
    $fs->delete('users', $userId);

    // 2. 帖子：先收集该用户发布的帖子 id，连同其下的评论/点赞/收藏一起清理
    $posts = $fs->getAll('posts');
    $userPostIds = [];
    foreach ($posts as $p) {
        if (isset($p['user_id']) && (int)$p['user_id'] === (int)$userId) {
            $userPostIds[] = (int)$p['id'];
        }
    }
    foreach ($userPostIds as $pid) {
        $fs->delete('posts', $pid);
        $fs->delete('post_likes', $pid);
        $fs->delete('post_favorites', $pid);
        // 该帖子下所有评论（含子回复）
        $comments = $fs->getAll('comments');
        foreach ($comments as $c) {
            if (
                (isset($c['post_id']) && (int)$c['post_id'] === $pid) ||
                (isset($c['user_id']) && (int)$c['user_id'] === (int)$userId)
            ) {
                $fs->delete('comments', $c['id']);
            }
        }
    }

    // 3. 其余独立关联表：通知、点赞、收藏、关注、权限
    foreach (['notifications', 'post_likes', 'post_favorites', 'follows', 'admin_permissions'] as $relTable) {
        $rows = $fs->getAll($relTable);
        foreach ($rows as $row) {
            foreach (['user_id', 'follower_id', 'followed_id', 'from_id', 'to_id', 'target_user_id'] as $fkey) {
                if (isset($row[$fkey]) && (int)$row[$fkey] === (int)$userId) {
                    $fs->delete($relTable, $row['id']);
                    break;
                }
            }
        }
    }
}

$action = $_POST['action'] ?? 'list';

if ($action === 'list') {
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $keyword = sanitizeInput($_REQUEST['search'] ?? $_REQUEST['keyword'] ?? '');

    $users = $fs->read('users');

    if ($keyword) {
        $users = array_filter($users, function($u) use ($keyword) {
            return stripos($u['qq'], $keyword) !== false || stripos($u['nickname'], $keyword) !== false;
        });
    }

    $users = array_values($users);
    usort($users, function($a, $b) {
        return strtotime($b['created_at'] ?? '2000-01-01') - strtotime($a['created_at'] ?? '2000-01-01');
    });

    $total = count($users);
    $totalPages = ceil($total / $limit);
    $users = array_slice($users, $offset, $limit);

    $result = [];
    foreach ($users as $u) {
        $result[] = [
            'id' => $u['id'],
            'qq' => $u['qq'],
            'nickname' => $u['nickname'],
            'avatar' => $u['avatar'],
            'role' => $u['role'],
            'is_banned' => $u['is_banned'],
            'ban_reason' => $u['ban_reason'] ?? '',
            'ban_until' => $u['ban_until'] ?? '',
            'twofa_enabled' => !empty($u['twofa_enabled']),
            'title_text' => $u['title_text'] ?? '',
            'title_color' => $u['title_color'] ?? '',
            'title_bg_color' => $u['title_bg_color'] ?? '',
            'title_rainbow' => intval($u['title_rainbow'] ?? 0),
            'title_gradient_start' => $u['title_gradient_start'] ?? '',
            'title_gradient_end' => $u['title_gradient_end'] ?? '',
            'visit_count' => (int)($u['visit_count'] ?? 0),
            'last_visit' => $u['last_visit'] ?? '',
            'created_at' => $u['created_at'] ?? ''
        ];
    }

    jsonSuccess([
        'users' => $result,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$action = $_POST['action'] ?? '';

if ($action === 'ban') {
    if (!checkPermission($admin, 'ban_user')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    $duration = $_POST['duration'] ?? '1';
    $reason = sanitizeInput($_POST['reason'] ?? '');

    if (!$userId) {
        jsonError('用户ID无效');
    }

    $allUsers = $fs->read('users');
    $userIndex = [];
    foreach ($allUsers as $u) {
        if (isset($u['id'])) {
            $userIndex[(int)$u['id']] = $u;
        }
    }
    $user = $userIndex[$userId] ?? null;
    if (!$user) {
        jsonError('用户不存在');
    }

    if ($user['role'] === 'super_admin') {
        jsonError('无法封禁超级管理员', 403);
    }

    $days = $duration === 'permanent' ? 0 : max(1, intval($duration));
    $banUntil = $days > 0 ? date('Y-m-d H:i:s', time() + ($days * 24 * 3600)) : null;

    $fs->update('users', $userId, [
        'is_banned' => 1,
        'ban_reason' => $reason,
        'ban_until' => $banUntil
    ]);

    logOperation($admin['id'], $admin['qq'], 'ban_user', 'user', $userId, '封禁用户，原因：' . $reason);
    jsonSuccess([], '封禁成功');
}

if ($action === 'unban') {
    if (!checkPermission($admin, 'unban_user')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    if (!$userId) {
        jsonError('用户ID无效');
    }

    $fs->update('users', $userId, [
        'is_banned' => 0,
        'ban_reason' => '',
        'ban_until' => null
    ]);

    logOperation($admin['id'], $admin['qq'], 'unban_user', 'user', $userId, '解除封禁');
    jsonSuccess([], '解除封禁成功');
}

if ($action === 'edit_ban_reason') {
    if (!checkPermission($admin, 'edit_ban_reason')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    $reason = sanitizeInput($_POST['reason'] ?? '');

    if (!$userId) {
        jsonError('用户ID无效');
    }

    $fs->update('users', $userId, ['ban_reason' => $reason]);
    jsonSuccess([], '封禁原因已更新');
}

if ($action === 'reset_2fa') {
    if (!checkPermission($admin, 'reset_user_2fa')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    if (!$userId) {
        jsonError('用户ID无效');
    }

    $fs->update('users', $userId, [
        'twofa_enabled' => 0,
        'twofa_secret' => '',
        'twofa_lockout_attempts' => 0,
        'twofa_lockout_until' => null
    ]);

    logOperation($admin['id'], $admin['qq'], 'reset_user_2fa', 'user', $userId, '重置2FA');
    jsonSuccess([], '2FA已重置');
}

if ($action === 'reset_password') {
    if (!checkPermission($admin, 'reset_user_password')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';

    if (!$userId) {
        jsonError('用户ID无效');
    }

    $passwordCheck = validatePasswordStrength($newPassword);
    if ($passwordCheck !== true) {
        jsonError($passwordCheck);
    }

    $fs->update('users', $userId, [
        'password_hash' => hashPassword($newPassword),
        'security_stamp' => generateSecurityStamp()
    ]);

    logOperation($admin['id'], $admin['qq'], 'reset_user_password', 'user', $userId, '重置密码');
    jsonSuccess([], '密码已重置');
}

if ($action === 'view_detail') {
    if (!checkPermission($admin, 'view_user_detail')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    if (!$userId) {
        jsonError('用户ID无效');
    }

    $user = $fs->findById('users', $userId);
    if (!$user) {
        jsonError('用户不存在');
    }

    jsonSuccess([
        'id' => $user['id'],
        'qq' => $user['qq'],
        'nickname' => $user['nickname'],
        'avatar' => $user['avatar'],
        'role' => $user['role'],
        'real_name' => $user['real_name'] ?? '',
        'class_num' => $user['class_num'] ?? 0,
        'entrance_year' => $user['entrance_year'] ?? 0,
        'created_at' => $user['created_at'] ?? '',
        'last_visit' => $user['last_visit'] ?? '',
        'visit_count' => (int)($user['visit_count'] ?? 0),
    ]);
}

if ($action === 'change_username') {
    if (!checkPermission($admin, 'change_username')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    $newUsername = sanitizeInput($_POST['new_username'] ?? '');

    if (!$userId) {
        jsonError('用户ID无效');
    }
    if ($newUsername === '') {
        jsonError('请输入新用户名');
    }

    $nickLen = mb_strlen($newUsername);
    if ($nickLen < 2 || $nickLen > 20) {
        jsonError('用户名长度需在2-20个字符之间');
    }
    if (preg_match('/[\r\n<>\/\\\"\'`]/', $newUsername)) {
        jsonError('用户名包含不允许的特殊字符');
    }

    $duplicate = $fs->findOne('users', ['nickname' => $newUsername]);
    if ($duplicate !== null && (int)$duplicate['id'] !== $userId) {
        jsonError('该用户名已被使用');
    }

    $fs->update('users', $userId, ['nickname' => $newUsername]);

    logOperation($admin['id'], $admin['qq'], 'change_username', 'user', $userId, '被更改用户名为：' . $newUsername);
    jsonSuccess([], '用户名已更新');
}

if ($action === 'view_post_author') {
    // 查看匿名/不公开动态的发布者身份（敏感操作，需保密）
    if (!checkPermission($admin, 'view_anonymous_author')) {
        jsonError('无权限执行此操作', 403);
    }

    $postId = intval($_POST['post_id'] ?? 0);
    if (!$postId) {
        jsonError('动态ID无效');
    }

    $post = $fs->findById('posts', $postId);
    if (!$post) {
        jsonError('动态不存在');
    }

    $author = empty($post['user_id']) ? null : $fs->findById('users', $post['user_id']);
    if (!$author) {
        jsonError('未找到发布者信息');
    }

    logOperation($admin['id'], $admin['qq'], 'view_anonymous_author', 'post', $postId, '查看了匿名/不公开动态的发布者身份');

    jsonSuccess([
        'post_id' => $postId,
        'post_title' => $post['title'] ?? '',
        'author_id' => $author['id'],
        'author_qq' => $author['qq'],
        'author_nickname' => $author['nickname'],
        'author_avatar' => $author['avatar'],
    ]);
}

if ($action === 'delete_user') {
    if (!checkPermission($admin, 'delete_user')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    if (!$userId) {
        jsonError('用户ID无效');
    }

    $target = $fs->findById('users', $userId);
    if (!$target) {
        jsonError('用户不存在');
    }
    if ($target['role'] === 'super_admin') {
        jsonError('无法删除超级管理员', 403);
    }
    if ((int)$admin['id'] === $userId) {
        jsonError('不能删除自己当前登录的账号', 403);
    }

    deleteUserData($fs, $userId, $target['qq']);

    logOperation($admin['id'], $admin['qq'], 'delete_user', 'user', $userId, '删除用户：' . $target['qq'] . ' / ' . $target['nickname']);
    jsonSuccess([], '用户及其内容已删除');
}

if ($action === 'batch') {
    $batchAction = $_POST['batch_action'] ?? '';
    $userIds = json_decode($_POST['user_ids'] ?? '[]', true) ?: [];

    if (empty($userIds)) {
        jsonError('请选择用户');
    }

    $allUsers = $fs->read('users');
    $userIndex = [];
    foreach ($allUsers as $i => $u) {
        if (isset($u['id'])) {
            $userIndex[(int)$u['id']] = $i;
        }
    }

    $affected = 0;
    if ($batchAction === 'ban') {
        if (!checkPermission($admin, 'ban_user')) {
            jsonError('无权限执行此操作', 403);
        }
        foreach ($userIds as $uid) {
            $idx = $userIndex[(int)$uid] ?? null;
            if ($idx === null) continue;
            if ($allUsers[$idx]['role'] === 'super_admin') continue;
            $allUsers[$idx]['is_banned'] = 1;
            $allUsers[$idx]['ban_reason'] = '批量封禁';
            $allUsers[$idx]['ban_until'] = date('Y-m-d H:i:s', time() + 7 * 24 * 3600);
            $allUsers[$idx]['updated_at'] = date('Y-m-d H:i:s');
            $affected++;
        }
    } elseif ($batchAction === 'unban') {
        if (!checkPermission($admin, 'unban_user')) {
            jsonError('无权限执行此操作', 403);
        }
        foreach ($userIds as $uid) {
            $idx = $userIndex[(int)$uid] ?? null;
            if ($idx === null) continue;
            $allUsers[$idx]['is_banned'] = 0;
            $allUsers[$idx]['ban_reason'] = '';
            $allUsers[$idx]['ban_until'] = null;
            $allUsers[$idx]['updated_at'] = date('Y-m-d H:i:s');
            $affected++;
        }
    }

    if ($affected > 0) {
        $fs->write('users', $allUsers);
    }

    jsonSuccess(['affected' => $affected], '批量操作完成');
}

jsonError('未知操作');