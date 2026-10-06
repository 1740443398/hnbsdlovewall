<?php

require_once __DIR__ . '/../config/config.php';
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
    <title>404 页面未找到 - <?= SITE_NAME ?></title>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <!-- 404 不经过 pwa_head.php，彩蛋资源在这里单独引入（绝对路径，与全站一致） -->
    <link rel="stylesheet" href="<?= asset_url('/assets/css/easter-eggs.css') ?>?v=<?= asset_ver('/assets/css/easter-eggs.css') ?>">
    <style>
        .error-wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
            background: var(--bg);
        }
        .error-code {
            font-size: 3.25rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.03em;
            line-height: 1;
            margin-bottom: 14px;
        }
        .error-msg { font-size: 1.0625rem; font-weight: 600; color: var(--text); margin-bottom: 10px; }
        .error-desc { font-size: 0.875rem; color: var(--text-secondary); line-height: 1.75; margin-bottom: 22px; max-width: 420px; }
        .error-wrap .lw-scene { margin-bottom: 26px; }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 26px;
            background: var(--primary);
            color: #fff;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9375rem;
            transition: background 0.2s ease;
            min-height: 44px;
        }
        .btn-home:hover { background: var(--primary-hover); color: #fff; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>
    <div class="error-wrap">
        <div class="error-code">404</div>
        <div class="error-msg">页面不存在</div>
        <div class="error-desc">你访问的页面可能已被移除或地址有误，请检查链接后重试。</div>

        <!-- 静谧小场景：点灯 / 窗 / 纸飞机，各会回一句暖心话（纯 CSS + 内联 SVG，无图片无外链） -->
        <div class="lw-scene" id="lw404Scene" role="group" aria-label="<?= htmlspecialchars(t('egg.scene.hint')) ?>">
            <div class="lw-scene-room">
                <button type="button" class="lw-hotspot lw-window" data-lw-egg="window" aria-label="<?= htmlspecialchars(t('egg.scene.window')) ?>">
                    <svg viewBox="0 0 130 98" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <defs>
                            <linearGradient id="lwWinSky" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#2F5B9A" stop-opacity="0.30"/>
                                <stop offset="1" stop-color="#2773C4" stop-opacity="0.12"/>
                            </linearGradient>
                        </defs>
                        <rect class="lw-win-pane" x="3" y="3" width="124" height="92" rx="9"/>
                        <circle class="lw-win-moon" cx="94" cy="28" r="10"/>
                        <circle class="lw-win-star" cx="32" cy="22" r="1.9"/>
                        <circle class="lw-win-star" cx="52" cy="42" r="1.4"/>
                        <circle class="lw-win-star" cx="26" cy="58" r="1.7"/>
                        <circle class="lw-win-star" cx="76" cy="64" r="1.3"/>
                        <line class="lw-win-bar" x1="65" y1="3" x2="65" y2="95"/>
                        <line class="lw-win-bar" x1="3" y1="49" x2="127" y2="49"/>
                        <rect class="lw-win-frame" x="3" y="3" width="124" height="92" rx="9"/>
                    </svg>
                </button>

                <button type="button" class="lw-hotspot lw-lamp" data-lw-egg="lamp" aria-label="<?= htmlspecialchars(t('egg.scene.lamp')) ?>">
                    <svg viewBox="0 0 66 104" preserveAspectRatio="xMidYMin meet" aria-hidden="true" focusable="false">
                        <defs>
                            <linearGradient id="lwLampGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="#E8D5A3"/>
                                <stop offset="1" stop-color="#B8943E"/>
                            </linearGradient>
                        </defs>
                        <line class="lw-lamp-cord" x1="33" y1="0" x2="33" y2="46"/>
                        <path class="lw-lamp-shade" d="M15 46 L51 46 L59 78 L7 78 Z"/>
                        <ellipse class="lw-lamp-bulb" cx="33" cy="78" rx="7" ry="4.5"/>
                    </svg>
                </button>

                <button type="button" class="lw-hotspot lw-plane" data-lw-egg="plane" aria-label="<?= htmlspecialchars(t('egg.scene.plane')) ?>">
                    <svg viewBox="0 0 72 44" aria-hidden="true" focusable="false">
                        <path class="lw-plane-path" d="M2 42 C 16 36, 30 32, 44 28"/>
                        <polygon class="lw-plane-body" points="2,20 70,4 34,24"/>
                        <polygon class="lw-plane-fold" points="34,24 70,4 40,40"/>
                    </svg>
                </button>
            </div>
            <p class="lw-scene-caption" id="lwSceneCaption" aria-live="polite"></p>
            <p class="lw-scene-hint"><?= htmlspecialchars(t('egg.scene.hint')) ?></p>
        </div>

        <a href="<?= SITE_URL ?>/" class="btn-home">返回首页</a>
    </div>
    <script>
        // 场景文案：由 t() 渲染后交给 easter-eggs.js 使用
        window.LW_EGG_SCENE_TEXTS = <?= json_encode([t('egg.scene.1'), t('egg.scene.2'), t('egg.scene.3')], JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="<?= asset_url('/assets/js/easter-eggs.js') ?>?v=<?= asset_ver('/assets/js/easter-eggs.js') ?>" defer></script>
</body>
</html>
