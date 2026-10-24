<?php
/**
 * 流体云 —— 多端同步个人 UI 偏好（效果开关 / 主题 / 通知偏好 / Dock位置）。
 *
 * GET  ：读取当前用户云端 UI 偏好（登录用户），匿名返回空偏好。
 * POST ：写入当前用户云端 UI 偏好（需登录 + CSRF）。
 *
 * 设计：
 *   - 偏好整体序列化后存入 users.ui_prefs 字段（JSON 字符串），不新增表、不改动既有字段。
 *   - 仅白名单键落库（glass / notify / theme / dockPos），非法键一律丢弃，杜绝脏数据。
 *   - 匿名用户不回写、不吃 401：GET 直接返回空偏好，前端本地 localStorage 兜底。
 *
 * 与既有 update_theme.php 的分工：主题仍由 update_theme.php 写 users.theme（历史契约不变），
 * 本接口额外把「效果开关 / 通知偏好 / Dock位置」并入同一份云端快照，供多端拉取。
 */
require_once __DIR__ . '/../../config/config.php';

$fs = getFS();

/** 白名单规整：只保留约定键，并做类型/范围收敛 */
function lw_uiprefs_sanitize($raw): array
{
    $out = [];
    if (!is_array($raw)) {
        return $out;
    }

    // 效果开关：布尔收敛；dockPos 为 {x:0..1,y:px}；theme 三态；notify 为布尔+数值区间
    if (isset($raw['glass']) && is_array($raw['glass'])) {
        $g = [];
        foreach (['master', 'bgFluid', 'dockFluid', 'gloss'] as $k) {
            if (array_key_exists($k, $raw['glass'])) {
                $g[$k] = (bool)$raw['glass'][$k];
            }
        }
        if (isset($raw['glass']['speed']) && is_numeric($raw['glass']['speed'])) {
            $g['speed'] = max(0.2, min(3, (float)$raw['glass']['speed']));
        }
        if (isset($raw['glass']['light']) && is_numeric($raw['glass']['light'])) {
            $g['light'] = max(0, min(2, (float)$raw['glass']['light']));
        }
        if (isset($raw['glass']['dockPos']) && is_array($raw['glass']['dockPos'])
            && isset($raw['glass']['dockPos']['x'], $raw['glass']['dockPos']['y'])
            && is_numeric($raw['glass']['dockPos']['x']) && is_numeric($raw['glass']['dockPos']['y'])) {
            $g['dockPos'] = [
                'x' => max(0.0, min(1.0, (float)$raw['glass']['dockPos']['x'])),
                'y' => max(0.0, min(20000.0, (float)$raw['glass']['dockPos']['y'])),
            ];
        }
        $out['glass'] = $g;
    }

    if (isset($raw['notify']) && is_array($raw['notify'])) {
        $n = [];
        if (array_key_exists('silent', $raw['notify'])) {
            $n['silent'] = (bool)$raw['notify']['silent'];
        }
        foreach (['silentStart', 'silentEnd'] as $k) {
            if (isset($raw['notify'][$k]) && is_numeric($raw['notify'][$k])) {
                $n[$k] = max(0, min(23, (int)$raw['notify'][$k]));
            }
        }
        $out['notify'] = $n;
    }

    if (isset($raw['theme']) && in_array($raw['theme'], ['light', 'dark', 'system'], true)) {
        $out['theme'] = $raw['theme'];
    }

    return $out;
}

// ---------------------------------------------------------------- 读取（多端拉取）
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_SESSION['user_id'])) {
        jsonSuccess(['prefs' => new stdClass(), 'logged_in' => false]);
    }
    $u = $fs->findById('users', (int)$_SESSION['user_id']);
    $prefs = [];
    if ($u && !empty($u['ui_prefs'])) {
        $decoded = json_decode((string)$u['ui_prefs'], true);
        $prefs = is_array($decoded) ? $decoded : [];
    }
    jsonSuccess([
        'prefs'     => empty($prefs) ? new stdClass() : $prefs,
        'logged_in' => true,
        'theme'     => $u['theme'] ?? 'system',
    ]);
}

// ---------------------------------------------------------------- 写入（多端上云）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonError('CSRF验证失败', 403);
    }
    $user = requireLogin();
    $user = checkBanned($user);
    if ($user['is_banned']) {
        jsonError('账号已被封禁');
    }

    $payload = json_decode((string)($_POST['prefs'] ?? '{}'), true);
    $clean = lw_uiprefs_sanitize($payload);

    $fs->update('users', $user['id'], ['ui_prefs' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);

    // 主题若随快照一起提交，同步写回 users.theme，保持与 update_theme.php 一致
    if (isset($clean['theme'])) {
        $fs->update('users', $user['id'], ['theme' => $clean['theme']]);
    }

    jsonSuccess(['prefs' => $clean], '偏好已同步');
}

jsonError('请求方法不允许', 405);
