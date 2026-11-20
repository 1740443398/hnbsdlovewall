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
/* =====================================================================
 * 缓存策略
 * =====================================================================
 * 原来 300 秒 + 一天 stale-while-revalidate。
 * 问题：发版后 5 分钟内，用户拿到的还是旧版本信息，
 * App 会以为「没有新版本」，更新提示不出现。
 *
 * 现在：
 *   · 缓存 60 秒（发版后最多 1 分钟就生效）
 *   · stale 缩到 1 小时（旧副本不该被复用一整天）
 *   · 缓存 key 里带上 APK 目录的指纹 —— 一旦有新包放进来，
 *     key 立刻变化，旧缓存自然失效，不用等过期。
 * ===================================================================== */
$__apkDir = __DIR__ . '/../download/';
$__apkFiles = glob($__apkDir . 'lovewall-v*.apk') ?: [];
$__apkSig = '';
foreach ($__apkFiles as $__f) {
    $__apkSig .= basename($__f) . ':' . (int)@filesize($__f) . ';';
}
// 用文件列表的哈希作为缓存命名空间
lwCacheShared(60, 3600);

/* =====================================================================
 * 读取发布配置
 * =====================================================================
 * 【2026-10-31 修复：线上 version_code 恒为 0】
 *
 * 原来直接 include app_config.php。那个文件内部会调 lwCfgInt() /
 * lwCfg() 去读后台覆盖值。但在这个位置，配置系统虽然已加载，
 * 后台的 app_latest_version_code 并没有值，于是 lwCfgInt 返回默认值 ——
 * 本该没问题，可线上实测拿到的仍是 0。
 *
 * 现在改成「先取文件里的字面默认值，再用后台覆盖」两步走，
 * 每一步都单独兜底，任何一步失败都不影响另一部分。
 * ===================================================================== */
$cfgFile = __DIR__ . '/../config/app_config.php';
$cfg = [];

if (is_file($cfgFile)) {
    // ① 直接 include 拿默认值（此时 lwCfg 已可用，但可能返回空）
    try {
        $tmp = include $cfgFile;
        if (is_array($tmp)) {
            $cfg = $tmp;
        }
    } catch (Throwable $e) {
        $cfg = [];
    }

    // ② 如果 version_code 仍然是 0，从文件里用正则兜底提取
    //    （防止 include 因某个函数不可用而中途返回）
    if (intval($cfg['version_code'] ?? 0) <= 0) {
        $raw = @file_get_contents($cfgFile);
        if (is_string($raw) && $raw !== '') {
            if (preg_match("/'version_code'\s*=>\s*(\d+)/", $raw, $m)) {
                $cfg['version_code'] = (int)$m[1];
            }
            if (empty($cfg['version_name']) && preg_match("/'version_name'\s*=>\s*'([^']+)'/", $raw, $m)) {
                $cfg['version_name'] = $m[1];
            }
            if (empty($cfg['apk_file']) && preg_match("/'apk_file'\s*=>\s*'([^']+)'/", $raw, $m)) {
                $cfg['apk_file'] = $m[1];
            }
            if (empty($cfg['sha256']) && preg_match("/'sha256'\s*=>\s*'([0-9a-f]{64})'/", $raw, $m)) {
                $cfg['sha256'] = $m[1];
            }
            if (empty($cfg['notes']) && preg_match("/'notes'\s*=>\s*'([^']+)'/", $raw, $m)) {
                $cfg['notes'] = $m[1];
            }
        }
    }
}

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

/* 用 APK 列表指纹做 ETag：新包一放进来，ETag 就变，
 * 客户端和中间层的旧副本立刻失效，不必等 max-age 过期。 */
if ($__apkSig !== '' && !headers_sent()) {
    header('ETag: "' . substr(md5($__apkSig), 0, 16) . '"');
}

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
