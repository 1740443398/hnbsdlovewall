<?php
/**
 * 后台 —— 全局液态玻璃效果管控接口。
 *
 * action=overview    读取全局配置（总开关 / 参数）+ 用户分项开关使用统计
 * action=save        保存全局参数（速度倍率 / 光强倍率 / 强制策略）
 *
 * 权限：manage_liquid_glass。
 * 说明：用户端各分项开关的使用量由 api/liquid_glass.php 上报聚合到
 *       `liquid_glass_stats` 表；本接口只读聚合结果，不重复存用户明细。
 */
require_once __DIR__ . '/../../config/config.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

if (!checkPermission($admin, 'manage_liquid_glass')) {
    jsonError('无权限管理液态玻璃效果', 403);
}

$fs     = getFS();
$action = $_POST['action'] ?? 'overview';

// 读取当前全局配置（settings 表 key/value）
function lg_settings_map($fs) {
    $map = [];
    foreach ((array)$fs->read('settings') as $s) {
        $map[$s['setting_key']] = $s['setting_value'];
    }
    return $map;
}

// ---------------------------------------------------------------- 总览
if ($action === 'overview') {
    $map = lg_settings_map($fs);

    $config = [
        'enabled'    => ($map['lg_enabled'] ?? '1') === '1',   // 全局是否允许液态玻璃
        'force_off'  => ($map['lg_force_off'] ?? '0') === '1', // 强制关闭（临时全站降级）
        'speed'      => (float)($map['lg_speed'] ?? 1),
        'light'      => (float)($map['lg_light'] ?? 1),
        'millix_hint'=> $map['lg_miuix_hint'] ?? '关闭总开关将回退 MIUIX 主题',
    ];

    // 聚合分项使用统计
    $stats = [];
    foreach ((array)$fs->read('liquid_glass_stats') as $row) {
        $stats[$row['key'] ?? ''] = [
            'on'  => (int)($row['on_count'] ?? 0),
            'off' => (int)($row['off_count'] ?? 0),
        ];
    }
    $keys = ['master', 'bgFluid', 'dockFluid', 'gloss'];
    $items = [];
    $totalUsers = 0;
    foreach ($keys as $k) {
        $on  = $stats[$k]['on']  ?? 0;
        $off = $stats[$k]['off'] ?? 0;
        $totalUsers += $on;
        $items[] = ['key' => $k, 'on' => $on, 'off' => $off];
    }

    jsonSuccess([
        'config' => $config,
        'stats'  => $items,
        'sample' => $totalUsers,
    ]);
}

// ---------------------------------------------------------------- 保存
if ($action === 'save') {
    $enabled   = ($_POST['enabled'] ?? '1') === '1' ? '1' : '0';
    $forceOff  = ($_POST['force_off'] ?? '0') === '1' ? '1' : '0';
    $speed     = max(0.2, min(3.0, (float)($_POST['speed'] ?? 1)));
    $light     = max(0.0, min(2.0, (float)($_POST['light'] ?? 1)));

    updateSetting('lg_enabled', $enabled);
    updateSetting('lg_force_off', $forceOff);
    updateSetting('lg_speed', (string)round($speed, 2));
    updateSetting('lg_light', (string)round($light, 2));

    logOperation($admin['id'], $admin['qq'] ?? '', 'liquid_glass_save', 'settings', '', '更新液态玻璃全局配置');

    jsonSuccess([], '已保存');
}

jsonError('未知操作');
