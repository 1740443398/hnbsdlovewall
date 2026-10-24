<?php
/**
 * App 启动聚合接口
 * =====================================================================
 * 【为什么需要】
 * App 冷启动原来要发 6 个请求：
 *   posts/list    首页信息流
 *   notifications 未读数
 *   user/stats    个人统计
 *   app_version   版本检查
 *   local_state   本地化/主题
 *   checkin       签到状态
 *
 * 每个请求都要：建立连接 → 过主机反爬 → 启动 PHP → 读数据 → 返回。
 * 6 次就是 6 倍开销，而虚拟主机最贵的就是「PHP 启动 + 连接数」。
 * 合成 1 个请求后，冷启动从 6 次往返降到 1 次。
 *
 * 【设计要点】
 *   1. 部分失败不影响整体：某模块出错给空数据 + error 标记，
 *      不能因为「签到状态查不到」就让整个 App 打不开
 *   2. 游客也能用：未登录时不返回私有数据，首页和版本照常给
 *   3. 可缓存：游客视角下对所有人相同，给 60 秒共享缓存
 *   4. 版本字段永远返回：版本检查最关键，绝不能因别处失败而丢
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/enhance.php';

$user = getCurrentUser();
$isGuest = ($user === null);
if ($user !== null) {
    $user = checkBanned($user);
}

// 游客视角与调用者无关，可共享缓存；登录用户则不能。
if ($isGuest) {
    lwCacheShared(60, 300);
}

$fs = getFS();
$out = [
    'server_time' => time(),
    'logged_in' => !$isGuest,
];

// ---- 1) 版本信息（永远返回，最关键） ----
try {
    $cfgFile = __DIR__ . '/../config/app_config.php';
    $cfg = is_file($cfgFile) ? include $cfgFile : [];
    $cfg = is_array($cfg) ? $cfg : [];
    $out['version'] = [
        'version_code' => (int)($cfg['version_code'] ?? 0),
        'version_name' => (string)($cfg['version_name'] ?? ''),
        'apk_file' => (string)($cfg['apk_file'] ?? ''),
        'sha256' => (string)($cfg['sha256'] ?? ''),
        'notes' => (string)($cfg['notes'] ?? ''),
    ];
} catch (Throwable $e) {
    $out['version'] = [
        'version_code' => 0, 'version_name' => '', 'apk_file' => '',
        'sha256' => '', 'notes' => '',
    ];
}

// ---- 2) 首页信息流 ----
try {
    $all = [];
    foreach ($fs->read('posts') as $p) {
        if (($p['status'] ?? 'published') !== 'published') {
            continue;
        }
        if (($p['visibility'] ?? 'public') !== 'public') {
            continue;
        }
        $all[] = $p;
    }
    usort($all, function ($x, $y) {
        return strcmp((string)($y['created_at'] ?? ''), (string)($x['created_at'] ?? ''));
    });
    // 游客只看前 3 条（与 list.php 的 GUEST_VISIBLE_POSTS 一致）
    $guestLimit = defined('GUEST_VISIBLE_POSTS') ? (int)GUEST_VISIBLE_POSTS : 3;
    $limit = $isGuest ? $guestLimit : 20;
    $slice = array_slice($all, 0, $limit);
    $out['feed'] = [
        'posts' => lwPostListPayload($slice),
        'has_more' => count($all) > count($slice),
        'total' => count($all),
    ];
} catch (Throwable $e) {
    $out['feed'] = [
        'posts' => [], 'has_more' => false, 'total' => 0,
        'error' => $e->getMessage(),
    ];
}

// ---- 3) 登录用户私有数据 ----
if (!$isGuest) {
    $uid = (int)$user['id'];

    // 3.1 未读通知数
    try {
        $unread = 0;
        foreach ($fs->find('notifications', ['user_id' => $uid]) as $n) {
            if (empty($n['is_read'])) {
                $unread++;
            }
        }
        $out['unread'] = ['count' => $unread];
    } catch (Throwable $e) {
        $out['unread'] = ['count' => 0];
    }

    // 3.2 个人统计
    try {
        $myPosts = $fs->find('posts', ['user_id' => $uid]);
        $published = 0;
        $likes = 0;
        foreach ($myPosts as $p) {
            if (($p['status'] ?? 'published') === 'published') {
                $published++;
                $likes += (int)($p['likes'] ?? 0);
            }
        }
        $out['stats'] = [
            'post_count' => $published,
            'like_total' => $likes,
            'level' => (int)($user['level'] ?? 0),
            'points' => (int)($user['points'] ?? 0),
        ];
    } catch (Throwable $e) {
        $out['stats'] = [
            'post_count' => 0, 'like_total' => 0, 'level' => 0, 'points' => 0,
        ];
    }

    // 3.3 个人资料（脱敏：不含密码、安全问题）
    try {
        $out['profile'] = [
            'id' => $uid,
            'nickname' => (string)($user['nickname'] ?? ''),
            'avatar' => (string)($user['avatar'] ?? ''),
            'qq' => (string)($user['qq'] ?? ''),
            'role' => (string)($user['role'] ?? 'user'),
            'title_text' => (string)($user['title_text'] ?? ''),
            'title_color' => (string)($user['title_color'] ?? ''),
        ];
    } catch (Throwable $e) {
        $out['profile'] = ['id' => $uid];
    }

    // 3.4 签到状态
    try {
        $today = date('Y-m-d');
        $checked = false;
        foreach ($fs->find('checkins', ['user_id' => $uid]) as $c) {
            if (strpos((string)($c['created_at'] ?? ''), $today) === 0) {
                $checked = true;
                break;
            }
        }
        $out['checkin'] = ['today_done' => $checked];
    } catch (Throwable $e) {
        $out['checkin'] = ['today_done' => false];
    }
}

// ---- 4) 客户端配置（游客也要） ----
try {
    $out['config'] = [
        'site_name' => defined('SITE_NAME') ? SITE_NAME : '',
        'site_url' => defined('SITE_URL') ? SITE_URL : '',
        'lang' => $LANG_CODE ?? 'zh_CN',
        'guest_visible_posts' => defined('GUEST_VISIBLE_POSTS') ? (int)GUEST_VISIBLE_POSTS : 3,
    ];
} catch (Throwable $e) {
    $out['config'] = ['guest_visible_posts' => 3];
}

jsonSuccess($out);
