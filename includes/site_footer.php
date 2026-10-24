<?php
/**
 * 全站统一页脚
 *
 * 【为什么抽出来】
 * 原来 index.php / post_detail.php / user_center.php 等 7 个页面各写一份
 * <footer class="site-footer">，内容还不完全一样。加一个版本号就得改 7 处，
 * 漏一处就出现「首页有版本号、详情页没有」。
 *
 * 用法：页面里 <?php lwRenderFooter(); ?>
 *
 * 【版本号从哪来】
 * config/app_config.php 的 version_name（如 2.4.1）。
 * 这是 App 与站点共用的唯一版本源，发版时只改那一处。
 */

if (!function_exists('lwSiteVersion')) {
    /** 取站点当前版本号（与 App 同源）。 */
    function lwSiteVersion(): string
    {
        static $v = null;
        if ($v !== null) {
            return $v;
        }
        $v = '1.0.0';
        $cfgFile = dirname(__DIR__) . '/config/app_config.php';
        if (is_file($cfgFile)) {
            try {
                $cfg = require $cfgFile;
                if (is_array($cfg) && !empty($cfg['version_name'])) {
                    $v = (string)$cfg['version_name'];
                }
            } catch (Throwable $e) {
                // 配置读取失败就用默认值，绝不因为版本号让页面崩掉
            }
        }
        return $v;
    }
}

if (!function_exists('lwRenderFooter')) {
    /**
     * 渲染页脚。
     *
     * @param array $opts
     *   sponsor  => 是否显示「支持我们」入口（仅首页有赞助弹窗，默认 false）
     *   extra    => 额外 HTML，插在版本行之前
     */
    function lwRenderFooter(array $opts = []): void
    {
        $siteName = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
        $version = lwSiteVersion();
        $repoUrl = defined('GITHUB_REPO_URL') ? GITHUB_REPO_URL : '#';
        $repoName = defined('GITHUB_REPO_NAME') ? GITHUB_REPO_NAME : 'GitHub';
        $showSponsor = !empty($opts['sponsor']);
        $extra = (string)($opts['extra'] ?? '');
        ?>
    <footer class="site-footer">
        <div class="container">
            <p><?= t('footer.disclaimer') ?></p>
            <p>&copy; 2026 <?= htmlspecialchars($siteName) ?> · 蕭遞版权所有</p>
            <p><?= t('footer.open_source') ?><a href="<?= htmlspecialchars($repoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($repoName) ?></a></p>
            <p style="opacity:.7;font-size:12px;"><?= t('footer.ai_note') ?></p>
            <?php if ($extra !== ''): ?><?= $extra ?><?php endif; ?>
            <?php if ($showSponsor): ?>
            <p><a href="#sponsor" data-open-sponsor style="color:inherit;text-decoration:underline dotted;">支持我们</a></p>
            <?php endif; ?>

            <?php /* 版本号：常驻显示，方便用户和开发者确认「线上是哪一版」 */ ?>
            <p class="site-version" style="opacity:.6;font-size:12px;letter-spacing:.02em;">
                <span>LoveWall v<?= htmlspecialchars($version) ?></span>
                <span style="opacity:.5;margin:0 .35em">·</span>
                <span>Build <?= date('Ymd', @filemtime(dirname(__DIR__) . '/index.php') ?: time()) ?></span>
                <span style="opacity:.5;margin:0 .35em">·</span>
                <a href="<?= htmlspecialchars($repoUrl) ?>/releases" target="_blank" rel="noopener noreferrer" style="color:inherit;">更新日志</a>
            </p>
        </div>
    </footer>
        <?php
    }
}
