<?php
/**
 * 前台 —— 液态玻璃：全局配置下发 + 分项开关使用上报。
 *
 * GET  action=config   读取后台下发的全局参数（速度/光强/是否强制关闭）
 * POST action=report   上报本用户各分项开关状态（聚合计数，不存用户明细）
 *
 * 设计：聚合到 `liquid_glass_stats` 表，每行一个 key，含 on_count/off_count。
 *      不记录 user_id，避免隐私留存；同一用户/设备重复上报只累加趋势。
 */
require_once __DIR__ . '/../config/config.php';

// 公开只读数据：液态玻璃配置 1 小时。
// 加共享缓存后，重复打开不再查数据文件，也不会重复跑 PHP。
lwCacheShared(3600, 86400);

$fs     = getFS();
$action = $_REQUEST['action'] ?? 'config';

function lg_map($fs) {
    $map = [];
    foreach ((array)$fs->read('settings') as $s) {
        $map[$s['setting_key']] = $s['setting_value'];
    }
    return $map;
}

// ---------------------------------------------------------------- 配置下发
if ($action === 'config') {
    $map = lg_map($fs);
    jsonSuccess([
        'enabled'   => ($map['lg_enabled'] ?? '1') === '1',
        'force_off' => ($map['lg_force_off'] ?? '0') === '1',
        'speed'     => (float)($map['lg_speed'] ?? 1),
        'light'     => (float)($map['lg_light'] ?? 1),
    ]);
}

// ---------------------------------------------------------------- 上报
if ($action === 'report') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('请求方法不允许', 405);
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonError('CSRF验证失败', 403);
    }

    $raw = $_POST['states'] ?? '';
    $states = json_decode((string)$raw, true);
    if (!is_array($states)) {
        jsonError('参数格式错误');
    }

    $validKeys = ['master', 'bgFluid', 'dockFluid', 'gloss'];
    $rows = $fs->read('liquid_glass_stats');
    if (!is_array($rows)) $rows = [];
    $index = [];
    foreach ($rows as $i => $r) {
        $index[$r['key'] ?? ''] = $i;
    }

    foreach ($validKeys as $k) {
        if (!array_key_exists($k, $states)) continue;
        $on = !empty($states[$k]) ? 1 : 0;
        if (isset($index[$k])) {
            $i = $index[$k];
            if ($on) $rows[$i]['on_count']  = (int)($rows[$i]['on_count'] ?? 0) + 1;
            else     $rows[$i]['off_count'] = (int)($rows[$i]['off_count'] ?? 0) + 1;
            $rows[$i]['updated_at'] = date('Y-m-d H:i:s');
        } else {
            $rows[] = [
                'key' => $k,
                'on_count' => $on,
                'off_count' => $on ? 0 : 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    $fs->write('liquid_glass_stats', $rows);
    jsonSuccess([], 'ok');
}

jsonError('未知操作');
