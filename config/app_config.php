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
    'version_code' => 20500,
    'version_name' => '2.5.0',
    'apk_file'     => 'lovewall-v2.5.0.apk',
    'sha256'       => 'db80876ad9d6d818e514e28fb8be6a052de25dbdfc1c6a7cfaf8957d90ed6540',
    'notes'        => '全新设计系统：品牌色改为暖橙，全站视觉重做（对标微博）；'
        . '支持暗色模式，跟随系统自动切换；'
        . '首页新增横向分类条与骨架屏，加载不再白屏；'
        . '帖子卡片统一为九宫格图文 + 投票卡；'
        . '发现页四标签（推荐/热门/主题/专栏）；'
        . '个人主页重做：渐变横幅 + 头像压边 + 数据条；'
        . '消息中心按类型分色，未读整行高亮；'
        . '缓存 TTL 全面调大，重复打开页面几乎零等待；'
        . '修复上传文件报「网络错误」',
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
