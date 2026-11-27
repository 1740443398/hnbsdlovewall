<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/post_visibility.php';

$user = requireMember('注册账号后可查看完整内容');
$user = checkBanned($user);
$fs = getFS();
$userIsAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);

$postIdsStr = $_REQUEST['ids'] ?? '';
$lastCheck = $_REQUEST['since'] ?? '';

if ($lastCheck) {
    $lastCheck = date('Y-m-d H:i:s', strtotime($lastCheck));
}
$postIds = $postIdsStr ? array_map('intval', array_filter(explode(',', $postIdsStr))) : [];

$result = ['posts' => [], 'new_comments' => []];

foreach ($postIds as $postId) {
    $post = $fs->findById('posts', $postId);
    if (!$post) continue;

    if (!$userIsAdmin) {
        if ($post['status'] !== 'published') {
            if (!$user || $post['user_id'] != $user['id']) continue;
        }
        $vis = $post['visibility'] ?? 'public';
        if ($vis === 'visible_to') {
            $allowedQQs = array_map('trim', explode(',', $post['visible_to'] ?? ''));
            if (!$user || !in_array($user['qq'], $allowedQQs)) continue;
        } elseif ($vis === 'exclude_to') {
            $excludedQQs = array_map('trim', explode(',', $post['exclude_to'] ?? ''));
            if ($user && in_array($user['qq'], $excludedQQs)) continue;
        }
    }

    $result['posts'][$postId] = [
        'id' => $postId,
        'like_count' => intval($post['likes'] ?? 0),
        'comment_count' => intval($post['comments'] ?? 0),
    ];
}

/* 新动态提示：轮询返回「比上次检查更新的、当前列表里还没有的」帖子数量与标题。
 * 不直接插入 DOM —— 用户正在读的位置不该被新内容顶下去，给一个可点击的提示条更合适。 */
$result['new_posts'] = [];
$result['new_count'] = 0;
if ($lastCheck) {
    $visibleNow = [];
    foreach (lwFilterVisiblePosts($fs->read('posts'), $user) as $np) {
        if ((string) ($np['status'] ?? 'published') !== 'published') { continue; }
        if (in_array((int) ($np['id'] ?? 0), $postIds, true)) { continue; }
        if ((string) ($np['created_at'] ?? '') <= $lastCheck) { continue; }
        $visibleNow[] = $np;
    }
    usort($visibleNow, function ($a, $b) {
        return strtotime((string) ($b['created_at'] ?? '')) - strtotime((string) ($a['created_at'] ?? ''));
    });
    $result['new_count'] = count($visibleNow);
    foreach (array_slice($visibleNow, 0, 3) as $np) {
        $result['new_posts'][] = ['id' => (int) $np['id'], 'title' => (string) ($np['title'] ?? '')];
    }
}

if ($lastCheck && $postIds) {
    $allComments = $fs->read('comments');

    foreach ($postIds as $postId) {
        $newComments = array_filter($allComments, function($c) use ($postId, $lastCheck) {
            return $c['post_id'] == $postId && $c['created_at'] > $lastCheck;
        });

        if (!empty($newComments)) {
            usort($newComments, function($a, $b) {
                return strtotime($a['created_at']) - strtotime($b['created_at']);
            });

            $formatted = [];
            foreach ($newComments as $c) {
                $cAnonymous = !empty($c['is_anonymous']);
                $cUser = $cAnonymous ? null : $fs->findById('users', $c['user_id']);

                $author = null;
                if (!$cAnonymous && $cUser) {
                    $author = [
                        'id' => $cUser['id'],
                        'nickname' => $cUser['nickname'] ?? '',
                        'avatar' => $cUser['avatar'] ?? '/assets/images/default-avatar.svg',
                        'title_text' => $cUser['title_text'] ?? '',
                        'title_color' => $cUser['title_color'] ?? '',
                        'title_bg_color' => $cUser['title_bg_color'] ?? '',
                        'title_rainbow' => intval($cUser['title_rainbow'] ?? 0),
                        'title_gradient_start' => $cUser['title_gradient_start'] ?? '',
                        'title_gradient_end' => $cUser['title_gradient_end'] ?? '',
                    ];
                }

                $formatted[] = [
                    'id' => $c['id'],
                    'post_id' => $c['post_id'],
                    'content' => $c['content'],
                    'is_anonymous' => $cAnonymous,
                    'author' => $author,
                    'created_at' => $c['created_at']
                ];
            }
            $result['new_comments'][$postId] = $formatted;
        }
    }
}

jsonSuccess($result);