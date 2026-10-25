<?php
/**
 * 本地状态初始化接口（需求二、7）
 * ---------------------------------------------------------------------------
 * 「初始化阶段拉取服务端数据覆盖写入本地存储」
 *
 * 服务端下发的都是**用户的个人偏好与状态**（不是业务数据本身），
 * 前端拿到后用 LWStorage 加密写入 localStorage。
 *
 * 为什么需要它：换设备/清缓存后，用户的界面偏好、草稿标记、已读位置等
 * 不该丢；而把这些散落在各接口里逐个拉，会让首屏请求变多。
 *
 * 注意：这里**不返回**任何敏感字段（QQ、邮箱、2FA、密钥），只回展示与偏好。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

$user = requireLoginOrGuest();

// 游客也有最小状态（主题、语言），不落用户表，只回默认值。
// 游客分支的响应与身份完全无关（就两个常量），允许共享缓存 —— 首屏每次都要打这一枪，
// 而且它是「未登录访问任一页面都会触发」的请求。
if (!$user) {
    lwCacheShared(600, 86400);
    jsonSuccess([
        'lw_ui_prefs'  => ['theme' => 'auto', 'lang' => $LANG_CODE ?? 'zh_CN'],
        'lw_guest'     => 1,
    ]);
}

$user = checkBanned($user);
$fs = getFS();
$uid = (int)$user['id'];

// ---- 界面偏好（ui_prefs 是用户表里的 JSON 字符串）----
$uiPrefs = null;
if (!empty($user['ui_prefs'])) {
    $decoded = json_decode((string)$user['ui_prefs'], true);
    if (is_array($decoded)) {
        $uiPrefs = $decoded;
    }
}

// ---- 私信偏好（免打扰 / 置顶）----
$pmPrefs = [];
if (function_exists('lwPmPrefs')) {
    $pmPrefs = lwPmPrefs($uid);
}

// ---- 签到状态（用于「今天是否已签到」的缓存）----
$checkin = null;
foreach ((array)$fs->read('checkins') as $c) {
    if ((int)($c['user_id'] ?? 0) === $uid) {
        $checkin = [
            'last_date' => (string)($c['last_date'] ?? ''),
            'streak' => (int)($c['streak'] ?? 0),
            'total' => (int)($c['total'] ?? 0),
        ];
        break;
    }
}

// ---- 未读计数（用于角标，避免首屏再打一次接口）----
$unreadNotif = 0;
foreach ((array)$fs->read('notifications') as $n) {
    if ((int)($n['user_id'] ?? 0) === $uid && empty($n['is_read'])) {
        $unreadNotif++;
    }
}

jsonSuccess([
    // 键名统一用 lw_ 前缀：LWStorage.clearScope() 按此前缀清理，不会误删别人写的键
    'lw_ui_prefs'      => $uiPrefs,
    'lw_theme'         => (string)($user['theme'] ?? 'auto'),
    'lw_lang'          => $LANG_CODE ?? 'zh_CN',
    'lw_pm_prefs'      => $pmPrefs,
    'lw_checkin'       => $checkin,
    'lw_unread'        => ['notifications' => $unreadNotif],
    'lw_points'        => (int)($user['points'] ?? 0),
    'lw_scope'         => 'u' . $uid,     // 加密作用域：不同账号密钥不同
    'lw_hydrated_at'   => time(),
]);
