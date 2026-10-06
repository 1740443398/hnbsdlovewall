<?php
/**
 * 顶栏用户下拉菜单 —— 全站唯一来源，所有带标准顶栏的页面都 require 本文件。
 *
 * 用法：放在顶栏 <div class="header-actions"> 内即可；
 * 作用域中需已有 $user（已登录用户数组），未登录时本文件不输出任何内容。
 *
 * 内含下拉菜单本体（「申请头衔」按钮在内；其弹窗已迁出 header，
 * 见 includes/title_request_modal.php —— 因 header 的 backdrop-filter 会形成 fixed 包含块）。
 * 修改菜单项请只改这里，避免各页面再次出现差异。
 */
if (empty($user) || !is_array($user)) {
    return;
}
$ddIsAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin']);
?>
<div class="user-menu">
    <button class="user-menu-btn" id="userMenuBtn">
        <img src="<?= htmlspecialchars($user['avatar'] ?? '') ?>" alt="<?= t('common.avatar_alt') ?>" class="user-avatar" onerror="this.style.display='none'">
        <span class="user-name"><?= htmlspecialchars($user['nickname'] ?? '') ?></span>
    </button>
    <div class="user-dropdown" id="userDropdown">
        <?php /* 手机端「快捷操作」挂载点：签到 / 功能投票 / 私信 / 随便看看 / 草稿箱 等
                 次要按钮由 JS 在 ≤768px 时搬到这里（桌面端它们仍留在顶栏，行为不变）。
                 必须保持「无空白字符」以保证 :empty 生效。 */ ?>
        <div class="me-panel" data-mount="me-panel"></div>
        <a href="/pages/user_center.php" class="dropdown-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <?= t('nav.profile') ?>
        </a>
        <a href="/pages/user_center.php?tab=posts" class="dropdown-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <?= t('nav.my_posts') ?>
        </a>
        <a href="/pages/user_center.php?tab=favorites" class="dropdown-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <?= t('nav.my_favorites') ?>
        </a>
        <a href="/pages/growth.php" class="dropdown-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
            <?= t('growth.page_title') ?>
        </a>
        <button type="button" class="dropdown-item" id="titleRequestBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.9 6.26L21 9.27l-4.5 4.38.94 6.35L12 17.42l-5.44 2.58.94-6.35L3 9.27l6.1-1.01z"/></svg>
            <?= t('nav.request_title') ?>
        </button>
        <a href="/pages/tools.php" class="dropdown-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v4m0 12v4M2 12h4m12 0h4" stroke-width="1.5" stroke-linecap="round"/><circle cx="12" cy="12" r="9" opacity="0.4"/></svg>
            <?= t('nav.tools') ?>
        </a>
        <a href="/pages/ai_assistant.php" class="dropdown-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4m0 4a4 4 0 1 1 4 4h-4z"/><path d="M12 8v6m0 0a4 4 0 1 0 4-4"/><circle cx="12" cy="16" r="2"/></svg>
            <?= t('nav.ai_assistant') ?>
        </a>
        <a href="/pages/download_backup.php" class="dropdown-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <?= t('nav.backup_download') ?>
        </a>
        <?php
        /* 安装应用：安卓走 APK 直装，其余平台走 PWA。
           在安卓客户端内打开时，enhancements.js 的 PWAInstall 会调 window.LoveWallApp
           让客户端自己下载并安装；在安卓浏览器里则直接下载 /download/ 下的安装包；
           其它平台保留原来的「添加到主屏幕」引导。已装成 App（standalone）时按钮被移除。 */
        $__appCfg = @include __DIR__ . '/../config/app_config.php';
        $__apkUrl = '';
        if (is_array($__appCfg) && !empty($__appCfg['apk_file'])) {
            $__apkUrl = '/download/' . rawurlencode(basename((string)$__appCfg['apk_file']));
        }
        ?>
        <button type="button" class="dropdown-item" id="pwaInstallBtn"
                data-apk="<?= htmlspecialchars($__apkUrl) ?>"
                data-version="<?= htmlspecialchars((string)($__appCfg['version_name'] ?? '')) ?>"
                data-sha256="<?= htmlspecialchars((string)($__appCfg['sha256'] ?? '')) ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><line x1="12" y1="8" x2="12" y2="16"/><polyline points="8.5 12.5 12 16 15.5 12.5"/></svg>
            <?= t('nav.install_app') ?>
        </button>
        <?php $__appCfg = null; $__apkUrl = null; ?>
        <?php if ($ddIsAdmin): ?>
        <a href="/admin/" class="dropdown-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <?= t('nav.admin') ?>
        </a>
        <?php endif; ?>
        <button type="button" class="dropdown-item" id="themeToggle" aria-label="<?= t('nav.theme') ?>">
            <span class="lw-theme-ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg></span>
            <span class="theme-toggle-label"><?= t('nav.theme') ?></span>
        </button>
        <button class="dropdown-item" id="logoutBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <?= t('nav.logout') ?>
        </button>
    </div>
</div>

<?php /* 「申请头衔」弹窗不在这里 —— 已迁到 includes/title_request_modal.php，
        由 site_header.php 在 </header> 之后 require。原因：header 的 backdrop-filter
        会成为 position:fixed 后代的包含块，弹窗放 header 内会错位。 */ ?>
