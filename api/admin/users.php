<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/sort_util.php';

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
    // 只读列表也要按权限码区分：否则仅持 T1（只读）之外的管理员也能拉到全量用户资料
    if (!checkPermission($admin, 'view_users')) {
        jsonError('无权限查看用户列表', 403);
    }
    $page = max(1, intval($_REQUEST['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $keyword = sanitizeInput($_REQUEST['search'] ?? $_REQUEST['keyword'] ?? '');

    // ── 高级检索条件（批次 D：D12）——全部可选，多条件 AND 组合 ──
    $fRole = sanitizeInput($_REQUEST['role'] ?? '');
    $fStatus = sanitizeInput($_REQUEST['status'] ?? '');     // banned | normal
    $fTwofa = isset($_REQUEST['twofa']) ? (string)$_REQUEST['twofa'] : '';
    $fHasTitle = isset($_REQUEST['has_title']) ? (string)$_REQUEST['has_title'] : '';
    $fRegFrom = sanitizeInput($_REQUEST['reg_from'] ?? '');
    $fRegTo = sanitizeInput($_REQUEST['reg_to'] ?? '');
    $fSort = sanitizeInput($_REQUEST['sort'] ?? '');
    $fOrder = (sanitizeInput($_REQUEST['order'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

    $users = $fs->read('users');

    if ($keyword) {
        $users = array_filter($users, function($u) use ($keyword) {
            return stripos((string)$u['qq'], $keyword) !== false || stripos((string)$u['nickname'], $keyword) !== false;
        });
    }

    if ($fRole !== '' && in_array($fRole, ['user', 'admin', 'super_admin'], true)) {
        $users = array_filter($users, function ($u) use ($fRole) {
            return ($u['role'] ?? 'user') === $fRole;
        });
    }
    if ($fStatus !== '') {
        $want = ($fStatus === 'banned');
        $users = array_filter($users, function ($u) use ($want) {
            return !empty($u['is_banned']) === $want;
        });
    }
    if ($fTwofa === '0' || $fTwofa === '1') {
        $want = ($fTwofa === '1');
        $users = array_filter($users, function ($u) use ($want) {
            return !empty($u['twofa_enabled']) === $want;
        });
    }
    if ($fHasTitle === '0' || $fHasTitle === '1') {
        $want = ($fHasTitle === '1');
        $users = array_filter($users, function ($u) use ($want) {
            return (trim((string)($u['title_text'] ?? '')) !== '') === $want;
        });
    }
    // 注册时间范围：按日期字符串前缀比较（created_at 是 'Y-m-d H:i:s'，字典序即时间序）
    if ($fRegFrom !== '') {
        $users = array_filter($users, function ($u) use ($fRegFrom) {
            return substr((string)($u['created_at'] ?? ''), 0, 10) >= $fRegFrom;
        });
    }
    if ($fRegTo !== '') {
        $users = array_filter($users, function ($u) use ($fRegTo) {
            return substr((string)($u['created_at'] ?? ''), 0, 10) <= $fRegTo;
        });
    }

    $users = array_values($users);

    // 排序：默认按注册时间倒序；支持访问次数 / 最近访问
    $sortKeyMap = ['created_at' => 'created_at', 'visit_count' => 'visit_count', 'last_visit' => 'last_visit'];
    $sortKey = $sortKeyMap[$fSort] ?? '';
    if ($sortKey === '') {
        lwSortByCreatedAtDesc($users);
    } else {
        usort($users, function ($a, $b) use ($sortKey, $fOrder) {
            if ($sortKey === 'visit_count') {
                $cmp = (int)($a['visit_count'] ?? 0) <=> (int)($b['visit_count'] ?? 0);
            } else {
                $cmp = strcmp((string)($a[$sortKey] ?? ''), (string)($b[$sortKey] ?? ''));
            }
            return $fOrder === 'asc' ? $cmp : -$cmp;
        });
    }

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

    // 永久封禁是不可自愈的重罚，必须单独持权；只有 ban_user 的管理员只能做定时封禁。
    // （后台页面的「永久」按钮也受同一权限码控制，此处是接口层的兜底，防止绕过 UI 直接 POST。）
    if ($duration === 'permanent' && !checkPermission($admin, 'permanent_ban')) {
        jsonError('无权限执行永久封禁', 403);
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
    // F11：重置他人 2FA 属账号接管类高危操作，需管理员二次密码确认
    requireAdminPassword($admin);

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
    // F11：重置他人密码属账号接管类高危操作，需管理员二次密码确认
    requireAdminPassword($admin);

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

    // ── 关联统计（批次 D：D13 用户详情抽屉）────────────────────────────────
    // 全部按 data/*.json 的**实测字段契约**取（字段名先 dump 过，不臆造）：
    //   posts.user_id / comments.user_id / post_likes.post_id / post_favorites.post_id
    //   follows.user_id(我关注) & follows.target_id(关注我) / checkins 按用户聚合一行
    //   user_achievements.unlocked 是数组 / user_activity_logs 是全站唯一带 ip 的用户行为表
    $postRows = $fs->read('posts') ?: [];
    $commentRows = $fs->read('comments') ?: [];
    $likes = $fs->read('post_likes') ?: [];
    $favs = $fs->read('post_favorites') ?: [];
    $follows = $fs->read('follows') ?: [];
    $checkins = $fs->read('checkins') ?: [];
    $achievements = $fs->read('user_achievements') ?: [];
    $logs = $fs->read('user_activity_logs') ?: [];

    $myPosts = array_values(array_filter($postRows, function ($p) use ($userId) {
        return (int)($p['user_id'] ?? 0) === $userId;
    }));
    $myComments = array_values(array_filter($commentRows, function ($c) use ($userId) {
        return (int)($c['user_id'] ?? 0) === $userId;
    }));

    $myPostIds = array_map(function ($p) { return (int)($p['id'] ?? 0); }, $myPosts);
    $likesReceived = 0;
    $favoritesReceived = 0;
    foreach ($likes as $l) {
        if (in_array((int)($l['post_id'] ?? 0), $myPostIds, true)) { $likesReceived++; }
    }
    foreach ($favs as $f) {
        if (in_array((int)($f['post_id'] ?? 0), $myPostIds, true)) { $favoritesReceived++; }
    }

    $fans = 0;
    $following = 0;
    foreach ($follows as $f) {
        if ((int)($f['target_id'] ?? 0) === $userId) { $fans++; }
        if ((int)($f['user_id'] ?? 0) === $userId) { $following++; }
    }

    $checkin = null;
    foreach ($checkins as $c) {
        if ((int)($c['user_id'] ?? 0) === $userId) { $checkin = $c; break; }
    }

    $achUnlocked = 0;
    foreach ($achievements as $a) {
        if ((int)($a['user_id'] ?? 0) === $userId) {
            $achUnlocked = is_array($a['unlocked'] ?? null) ? count($a['unlocked']) : (int)($a['unlocked'] ?? 0);
            break;
        }
    }

    // 活动/登录记录：取最近 12 条（含 ip）
    $myLogs = array_values(array_filter($logs, function ($l) use ($userId) {
        return (int)($l['user_id'] ?? 0) === $userId;
    }));
    usort($myLogs, function ($a, $b) {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    $myLogs = array_slice($myLogs, 0, 12);

    usort($myPosts, function ($a, $b) {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    usort($myComments, function ($a, $b) {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    $recentPosts = [];
    foreach (array_slice($myPosts, 0, 6) as $p) {
        $recentPosts[] = [
            'id' => $p['id'] ?? '',
            'title' => (isset($p['title']) && $p['title'] !== '') ? $p['title'] : mb_substr((string)($p['content'] ?? ''), 0, 30),
            'status' => $p['status'] ?? '',
            'category' => $p['category'] ?? '',
            'created_at' => $p['created_at'] ?? '',
        ];
    }
    $recentComments = [];
    foreach (array_slice($myComments, 0, 6) as $c) {
        $recentComments[] = [
            'post_id' => $c['post_id'] ?? '',
            'content' => mb_substr((string)($c['content'] ?? ''), 0, 40),
            'created_at' => $c['created_at'] ?? '',
        ];
    }
    $activity = [];
    foreach ($myLogs as $l) {
        $activity[] = [
            'action' => $l['action'] ?? '',
            'details' => $l['details'] ?? '',
            'ip' => $l['ip'] ?? '',
            'created_at' => $l['created_at'] ?? '',
        ];
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
        'is_banned' => !empty($user['is_banned']),
        'ban_reason' => $user['ban_reason'] ?? '',
        'ban_until' => $user['ban_until'] ?? '',
        'twofa_enabled' => !empty($user['twofa_enabled']),
        'title_text' => $user['title_text'] ?? '',
        'stats' => [
            'posts' => count($myPosts),
            'comments' => count($myComments),
            'likes_received' => $likesReceived,
            'favorites_received' => $favoritesReceived,
            'fans' => $fans,
            'following' => $following,
            'checkin_streak' => (int)($checkin['streak'] ?? 0),
            'checkin_total' => (int)($checkin['total'] ?? 0),
            'achievements' => $achUnlocked,
        ],
        'recent_posts' => $recentPosts,
        'recent_comments' => $recentComments,
        'activity' => $activity,
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
    $newUsername = trim($newUsername);
    if ($newUsername === '') {
        jsonError('请输入新用户名');
    }

    $duplicate = $fs->findOne('users', ['nickname' => $newUsername]);
    if ($duplicate !== null && (int)$duplicate['id'] !== $userId) {
        jsonError('该用户名已被使用');
    }

    $fs->update('users', $userId, ['nickname' => $newUsername]);

    logOperation($admin['id'], $admin['qq'], 'change_username', 'user', $userId, '被更改用户名为：' . $newUsername);
    jsonSuccess([], '用户名已更新');
}

if ($action === 'change_qq') {
    if (!checkPermission($admin, 'change_user_qq')) {
        jsonError('无权限执行此操作', 403);
    }

    $userId = intval($_POST['user_id'] ?? 0);
    $newQq = sanitizeInput($_POST['new_qq'] ?? '');

    if (!$userId) {
        jsonError('用户ID无效');
    }
    $newQq = trim($newQq);
    if (!isValidQQ($newQq)) {
        jsonError('新QQ号格式不正确');
    }

    $target = $fs->findById('users', $userId);
    if (!$target) {
        jsonError('用户不存在');
    }
    if ($target['role'] === 'super_admin') {
        jsonError('不能修改超级管理员的绑定QQ', 403);
    }
    if ($target['qq'] === $newQq) {
        jsonError('新QQ号与原QQ号相同');
    }

    // 新QQ号不得已被其他账号使用
    $dup = $fs->findOne('users', ['qq' => $newQq]);
    if ($dup !== null && (int)$dup['id'] !== $userId) {
        jsonError('该QQ号已被其他账号绑定');
    }

    $oldQq = $target['qq'];
    $oldNickname = $target['nickname'];

    $fs->update('users', $userId, [
        'qq' => $newQq,
        // 变更绑定QQ属敏感操作，同时刷新安全戳强制原会话下线，需重新登录
        'security_stamp' => generateSecurityStamp(),
    ]);

    // 向原QQ邮箱发送换绑通知（正文统一由 includes/mail_templates.php 提供）
    if (QQMailer::isConfigured()) {
        require_once __DIR__ . '/../../includes/mail_templates.php';
        $mail = lwMailBuild('qq_changed', [
            'nick'    => $oldNickname,
            'old_qq'  => $oldQq,
            'new_qq'  => $newQq,
        ]);
        QQMailer::send($oldQq . '@qq.com', $mail['subject'], $mail['html']);
    }

    logOperation($admin['id'], $admin['qq'], 'change_user_qq', 'user', $userId, '绑定QQ由 ' . $oldQq . ' 变更为 ' . $newQq);
    jsonSuccess([], '绑定QQ已更新，已向原QQ邮箱发送换绑通知');
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
    // F11：删除用户会连带清除其全部帖子/评论等数据，不可恢复，需管理员二次密码确认
    requireAdminPassword($admin);

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