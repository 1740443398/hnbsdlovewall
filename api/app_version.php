<?php
/**
 * 安卓客户端版本信息（公开接口，无需登录）
 * ------------------------------------------------------------------
 * 用途：
 *   - App 启动时静默检查、以及用户点「检查更新」时询问站点最新版本；
 *   - 站点「用户菜单 → 安装应用」也用它拿 APK 地址。
 *
 * 返回：{ success, message, data: {
 *   version_code, version_name, apk_url, sha256, notes, size,   // 兼容字段：始终 = 最新版
 *   versions: [ { version_name, version_code, apk_url, sha256, size, notes, is_latest } ]
 * } }
 *
 * 版本来源：自动扫描 /download/ 目录下的 lovewall-v*.apk（按版本号从高到低），
 * 并与 config/app_config.php 的配置合并 —— 配置里的版本作为「推荐版本」置顶。
 * 维护：把打包好的 APK 放进 /download/ 即可被识别；想指定推荐版本再改 app_config.php。
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=UTF-8');

// 公开数据：响应与调用者身份无关（App 启动时每台设备都会打一次，而且是安装量级的请求）。
// 300 秒 + 一天 stale-while-revalidate：新版本发布后最迟 5 分钟对所有人都生效，
// 期间所有人拿到的都是缓存副本 —— 这台共享主机最不擅长的就是「每次都跑 PHP」。
lwCacheShared(300, 86400);

$cfgFile = __DIR__ . '/../config/app_config.php';
$cfg = file_exists($cfgFile) ? include $cfgFile : [];
$cfg = is_array($cfg) ? $cfg : [];

$downloadDir = __DIR__ . '/../download/';

/** 把 "1.8.0" 这类版本名拆成可比较的整数数组 */
$parseVer = function ($name) {
    $name = trim((string)$name);
    if (!preg_match('/\d+(?:\.\d+)*/', $name, $m)) {
        return [0];
    }
    $parts = array_map('intval', explode('.', $m[0]));
    while (count($parts) < 4) {
        $parts[] = 0;
    }
    return $parts;
};

$cmpVer = function ($a, $b) use ($parseVer) {
    $pa = $parseVer($a);
    $pb = $parseVer($b);
    $len = max(count($pa), count($pb));
    for ($i = 0; $i < $len; $i++) {
        $x = $pa[$i] ?? 0;
        $y = $pb[$i] ?? 0;
        if ($x !== $y) {
            return $x <=> $y;
        }
    }
    return 0;
};

$versions = [];
$seen = [];

// 1) 扫描 /download/ 里的所有安装包
foreach (glob($downloadDir . 'lovewall-v*.apk') ?: [] as $file) {
    $base = basename($file);
    if (strpos($base, '..') !== false) {
        continue;
    }
    if (!preg_match('/lovewall-v(.+)\.apk$/i', $base, $m)) {
        continue;
    }
    $verName = $m[1];
    $versions[] = [
        'version_name' => $verName,
        'version_code' => 0,
        'apk_url'      => '/download/' . rawurlencode($base),
        'sha256'       => (string)hash_file('sha256', $file),
        'size'         => (int)filesize($file),
        'notes'        => '',
        'is_latest'    => false,
    ];
    $seen[$verName] = true;
}

// 2) 合并配置文件里的推荐版本（补齐 version_code / notes，或补一个目录外配置）
$cfgName = '';
if (!empty($cfg['apk_file'])) {
    $cfgName = basename((string)$cfg['apk_file']);
    if (preg_match('/lovewall-v(.+)\.apk$/i', $cfgName, $m)) {
        $cfgVer = $m[1];
        $found = false;
        foreach ($versions as &$v) {
            if ($v['version_name'] === $cfgVer) {
                $v['version_code'] = intval($cfg['version_code'] ?? 0);
                $v['notes'] = (string)($cfg['notes'] ?? '');
                if (!empty($cfg['sha256'])) {
                    $v['sha256'] = (string)$cfg['sha256'];
                }
                $found = true;
                break;
            }
        }
        unset($v);
        if (!$found) {
            $cfgPath = $downloadDir . $cfgName;
            $exists = is_file($cfgPath);
            $versions[] = [
                'version_name' => $cfgVer,
                'version_code' => intval($cfg['version_code'] ?? 0),
                'apk_url'      => '/download/' . rawurlencode($cfgName),
                'sha256'       => trim((string)($cfg['sha256'] ?? '')) ?: ($exists ? (string)hash_file('sha256', $cfgPath) : ''),
                'size'         => $exists ? (int)filesize($cfgPath) : 0,
                'notes'        => (string)($cfg['notes'] ?? ''),
                'is_latest'    => false,
            ];
        }
    }
}

if (empty($versions)) {
    jsonError('暂无可用的客户端版本信息', 404);
}

// 3) 排序：版本号从高到低；同版本时推荐配置版本（有 notes）优先
usort($versions, function ($a, $b) use ($cmpVer, $cfgName) {
    $c = $cmpVer($b['version_name'], $a['version_name']);
    if ($c !== 0) {
        return $c;
    }
    $aCfg = (basename((string)$a['apk_url']) === $cfgName) ? 1 : 0;
    $bCfg = (basename((string)$b['apk_url']) === $cfgName) ? 1 : 0;
    return $bCfg - $aCfg;
});

// 4) 标记最新版 = 排序后的第一条
$versions[0]['is_latest'] = true;
$latest = $versions[0];

jsonSuccess([
    // 兼容旧字段：始终返回最新版
    'version_code' => intval($latest['version_code']),
    'version_name' => (string)$latest['version_name'],
    'apk_url'      => (string)$latest['apk_url'],
    'sha256'       => (string)$latest['sha256'],
    'notes'        => (string)$latest['notes'],
    'size'         => (int)$latest['size'],
    // 新增：历史版本列表
    'versions'     => $versions,
]);
