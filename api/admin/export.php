<?php
require_once __DIR__ . '/../../config/config.php';

$user = requireAdmin();
$user = checkBanned($user);
if ($user['is_banned']) {
    jsonError('账号已被封禁');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许，请使用POST请求', 405);
}
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$fs = getFS();

// 导出为敏感操作：需显式授权（防止只读模版管理员批量带走数据）
if (!checkPermission($user, 'export_data')) {
    jsonError('无权限导出站点数据', 403);
}
$canViewAnonymousAuthor = checkPermission($user, 'view_anonymous_author');

$type = $_POST['type'] ?? 'posts';
$allowedTypes = ['posts', 'users', 'comments', 'full'];

if (!in_array($type, $allowedTypes)) {
    jsonError('无效的导出类型，可选: posts, users, comments');
}

$exportData = [];

switch ($type) {
    case 'posts':
        $posts = $fs->getAll('posts');
        usort($posts, function($a, $b) {
            return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
        });
        $exportData = array_map(function($p) use ($fs, $canViewAnonymousAuthor) {
            $author = ($p['user_id'] ?? 0) ? $fs->findById('users', $p['user_id']) : null;
            // 匿名/不公开动态的发布者身份需 view_anonymous_author 权限，否则一律脱敏
            $hideAuthor = (!empty($p['is_anonymous']) || ($p['visibility'] ?? 'public') !== 'public') && !$canViewAnonymousAuthor;
            return [
                'id' => $p['id'] ?? '',
                'title' => $p['title'] ?? '',
                'content' => $p['content'] ?? '',
                'category' => $p['category'] ?? 'other',
                'status' => $p['status'] ?? 'published',
                'visibility' => $p['visibility'] ?? 'public',
                'is_anonymous' => !empty($p['is_anonymous']),
                'likes' => intval($p['likes'] ?? 0),
                'comments' => intval($p['comments'] ?? 0),
                'views' => intval($p['views'] ?? 0),
                'author' => $hideAuthor ? '匿名' : ($author ? $author['nickname'] : '匿名'),
                'author_qq' => $hideAuthor ? '' : ($author ? $author['qq'] : ''),
                'created_at' => $p['created_at'] ?? '',
                'updated_at' => $p['updated_at'] ?? ''
            ];
        }, $posts);
        break;

    case 'users':
        $users = $fs->getAll('users');
        usort($users, function($a, $b) {
            return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
        });
        $exportData = array_map(function($u) {
            return [
                'id' => $u['id'] ?? '',
                'qq' => $u['qq'] ?? '',
                'nickname' => $u['nickname'] ?? '',
                'role' => $u['role'] ?? 'user',
                'is_banned' => !empty($u['is_banned']),
                'ban_reason' => $u['ban_reason'] ?? '',
                'ban_until' => $u['ban_until'] ?? '',
                'title_text' => $u['title_text'] ?? '',
                'post_count' => intval($u['post_count'] ?? 0),
                'created_at' => $u['created_at'] ?? ''
            ];
        }, $users);
        break;

    case 'comments':
        $comments = $fs->getAll('comments');
        usort($comments, function($a, $b) {
            return strtotime($b['created_at'] ?? '') - strtotime($a['created_at'] ?? '');
        });
        $exportData = array_map(function($c) use ($fs, $canViewAnonymousAuthor) {
            $author = ($c['user_id'] ?? 0) ? $fs->findById('users', $c['user_id']) : null;
            $post = ($c['post_id'] ?? 0) ? $fs->findById('posts', $c['post_id']) : null;
            $hideAuthor = !empty($c['is_anonymous']) && !$canViewAnonymousAuthor;
            return [
                'id' => $c['id'] ?? '',
                'post_id' => $c['post_id'] ?? '',
                'post_title' => $post ? ($post['title'] ?? '无标题') : '已删除',
                'content' => $c['content'] ?? '',
                'is_anonymous' => !empty($c['is_anonymous']),
                'author' => $hideAuthor ? '匿名用户' : ($author ? $author['nickname'] : ($c['is_anonymous'] ? '匿名用户' : '未知')),
                'author_qq' => $hideAuthor ? '' : ($author ? $author['qq'] : ''),
                'created_at' => $c['created_at'] ?? ''
            ];
        }, $comments);
        break;

    // 完整备份：导出全部数据表原始 JSON（含私信、签到、设置等），便于迁移/更新维护
    case 'full':
        // 含密码哈希与全站私信等最敏感数据，仅站长可导出
        if (($user['role'] ?? '') !== 'super_admin') {
            jsonError('完整备份仅站长可导出', 403);
        }
        $tables = [
            'users', 'posts', 'comments', 'post_likes', 'post_favorites',
            'notifications', 'settings', 'sensitive_words', 'sponsor',
            'operation_logs', 'user_activity_logs', 'ip_blacklist', 'ai_logs',
            'admin_permissions', 'pm_messages', 'pm_read', 'pm_reports',
            'feature_requests', 'checkins', 'checkin_records', 'remember_tokens',
        ];
        foreach ($tables as $t) {
            $exportData[$t] = $fs->getAll($t);
        }
        $exportData['_meta'] = [
            'app' => 'love_wall',
            'exported_at' => date('Y-m-d H:i:s'),
            'version' => 1,
        ];
        break;
}

// 直接返回导出的数据本身（完整备份为 {表名: 行数组} 结构），
// 前端下载后即为一大段 JSON，可直接粘贴回「导入前恢复」。
jsonSuccess($exportData);