<?php
/**
 * 安卓客户端版本信息（公开接口，无需登录）
 * ------------------------------------------------------------------
 * 用途：
 *   - App 启动时静默检查、以及用户点「检查更新」时询问站点最新版本；
 *   - 站点「用户菜单 → 安装应用」也用它拿 APK 地址。
 *
 * 返回：{ success, message, data: { version_code, version_name, apk_url, sha256, notes, size } }
 * 维护：只改 config/app_config.php，并把新 APK 放进 /download/。
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=UTF-8');

$cfgFile = __DIR__ . '/../config/app_config.php';
$cfg = file_exists($cfgFile) ? include $cfgFile : [];

if (!is_array($cfg) || empty($cfg['apk_file'])) {
    jsonError('暂无可用的客户端版本信息', 404);
}

// basename 兜底：即使配置被写错也不会跳出 /download/
$apkName = basename((string)$cfg['apk_file']);
$apkPath = __DIR__ . '/../download/' . $apkName;
$exists  = is_file($apkPath);

$sha256 = trim((string)($cfg['sha256'] ?? ''));
if ($sha256 === '' && $exists) {
    $sha256 = (string)hash_file('sha256', $apkPath);
}

jsonSuccess([
    'version_code' => intval($cfg['version_code'] ?? 0),
    'version_name' => (string)($cfg['version_name'] ?? ''),
    'apk_url'      => '/download/' . rawurlencode($apkName),
    'sha256'       => $sha256,
    'notes'        => (string)($cfg['notes'] ?? ''),
    'size'         => $exists ? (int)filesize($apkPath) : 0,
]);
