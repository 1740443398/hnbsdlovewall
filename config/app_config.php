<?php
/**
 * 安卓客户端（App）发布配置
 * ------------------------------------------------------------------
 * 【重要】这些值现在都可以在后台「全站配置 → 安卓客户端」里改，
 * 改完立即生效，不需要动这个文件、也不需要重新上传。
 *
 * 本文件的作用退化为「默认值 / 兜底」：
 *   - 后台没配过 → 用这里的值
 *   - 配置系统不可用（比如命令行工具单独引用本文件）→ 用这里的值
 *
 * 只有在「配置中心都进不去」的极端情况下，才需要直接改这个文件。
 */

$__appDefaults = [
    'version_code' => 20402,
    'version_name' => '2.4.2',
    'apk_file'     => 'lovewall-v2.4.2.apk',
    'sha256'       => '19d62e32ae50f54ed8c4db4c69a3286c4c2433bf108c795b9417cc6ef0c0dcfd',
    'notes'        => '装机统计修正：同一账号在同一台设备上只记一条，不再因重装变多条；'
        . '修复评论发布报 CSRF 失败（token 过期会自动换新并重试）；'
        . '修复帖子详情页不显示投票；'
        . '全新极简浅色毛玻璃界面，圆角更柔和；'
        . '启动并发预取 + 磁盘缓存，打开页面先出内容再后台更新；'
        . '发帖频率限制放宽到 20 次/5 分钟',
    'force_update' => 0,
];

// 后台覆盖（后台没配、或配置系统不可用时保持默认）
if (function_exists('lwCfg')) {
    try {
        $__appDefaults['version_code'] = lwCfgInt('app_latest_version_code', $__appDefaults['version_code']);

        $__vn = (string)lwCfg('app_latest_version_name', $__appDefaults['version_name']);
        if ($__vn !== '') $__appDefaults['version_name'] = $__vn;

        $__af = (string)lwCfg('app_apk_file', $__appDefaults['apk_file']);
        if ($__af !== '') $__appDefaults['apk_file'] = $__af;

        $__nt = (string)lwCfg('app_update_notes', '');
        if ($__nt !== '') $__appDefaults['notes'] = $__nt;

        $__appDefaults['force_update'] = lwCfgBool('app_force_update', false) ? 1 : 0;
    } catch (Throwable $e) {
        // 配置读取失败就用默认值，绝不让发布配置把一个页面搞挂
    }
}

return $__appDefaults;
