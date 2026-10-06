<?php
/**
 * 移动端底部主导航 —— 唯一来源，替换原先在各页面手写复制的 <nav class="mobile-bottom-nav">。
 *
 * 作用域契约：
 *   可选：$mobileNavActive  当前高亮项：'home' | 'fav' | 'compose' | 'msg' | 'me'；传空串表示都不高亮。
 *                           不传时下面会按当前脚本路径兜底推断，页面显式传入的值优先。
 *   必需：$user             当前登录用户数组（未登录传 null）
 *
 * 说明：
 *   - 未登录（含游客）只渲染「首页 + 登录」两项，避免点进受限接口直接 401。
 *   - 5 项顺序：首页 / 收藏 / ＋发帖（中间凸起） / 消息 / 我的。
 *   - 「消息」默认触发站内既有的私信弹窗 App.PrivateMessage；在没有 enhancements.js 的页面
 *     （例如 pages/post.php）自动降级为跳转个人中心，不会出现「点了没反应」。
 *   - 收藏指向 ?tab=favorites —— user_center 用的是 query 参数，原先的 #my-favorites 是死链。
 *
 * 修改导航项请只改这里。
 */
// 未显式传入高亮项时，按当前脚本名兜底推断。
// index.php 在根目录、其余页面在 pages/ 下，所以统一取脚本基名判断，不依赖完整路径；
// 页面显式传入的值（含空串）优先，绝不覆盖。
if (!isset($mobileNavActive) || $mobileNavActive === null) {
    switch (basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))) {
        case 'post.php':
            $mobileNavActive = 'compose';
            break;
        case 'user_center.php':
            // 收藏标签靠 query 参数区分，这里仅作兜底
            $mobileNavActive = (($_GET['tab'] ?? '') === 'favorites') ? 'fav' : 'me';
            break;
        case 'login.php':
            $mobileNavActive = 'me';
            break;
        case 'index.php':
        case '':
            $mobileNavActive = 'home';
            break;
        default:
            // 帖子详情等不属于这 5 个标签的页面：都不高亮
            $mobileNavActive = '';
            break;
    }
}
if (!isset($user)) {
    $user = null;
}
$mnavIs = function ($key) use ($mobileNavActive) {
    return $mobileNavActive === $key ? ' active' : '';
};
$mnavAria = function ($key) use ($mobileNavActive) {
    return $mobileNavActive === $key ? ' aria-current="page"' : '';
};
?>
<nav class="mobile-bottom-nav" id="mobileNav" aria-label="主导航">
    <a href="<?= SITE_URL ?>/" class="mobile-nav-item<?= $mnavIs('home') ?>" data-nav="home"<?= $mnavAria('home') ?>>
        <?= lw_icon('home', 20, ['wrap' => false]) ?>
        <span><?= t('mnav.home') ?></span>
    </a>
    <?php if ($user): ?>
    <a href="<?= SITE_URL ?>/pages/user_center.php?tab=favorites" class="mobile-nav-item<?= $mnavIs('fav') ?>" data-nav="fav"<?= $mnavAria('fav') ?>>
        <?= lw_icon('star', 20, ['wrap' => false]) ?>
        <span><?= t('mnav.fav') ?></span>
    </a>
    <a href="<?= SITE_URL ?>/pages/post.php" class="mobile-nav-item mobile-nav-item--compose<?= $mnavIs('compose') ?>" data-nav="compose" aria-label="<?= t('mnav.compose_aria') ?>"<?= $mnavAria('compose') ?>>
        <?php /* 发帖键线宽原为 2.4，比标尺更粗（要压在蓝色圆底上），显式覆盖以保持原观感 */ ?>
        <?= lw_icon('plus', 24, ['wrap' => false, 'stroke' => '2.4']) ?>
    </a>
    <button type="button" class="mobile-nav-item<?= $mnavIs('msg') ?>" data-nav="msg"<?= $mnavAria('msg') ?>>
        <?= lw_icon('message', 20, ['wrap' => false]) ?>
        <span><?= t('mnav.msg') ?></span>
    </button>
    <a href="<?= SITE_URL ?>/pages/user_center.php" class="mobile-nav-item<?= $mnavIs('me') ?>" data-nav="me"<?= $mnavAria('me') ?>>
        <?= lw_icon('user', 20, ['wrap' => false]) ?>
        <span><?= t('mnav.mine') ?></span>
    </a>
    <?php else: ?>
    <a href="<?= SITE_URL ?>/pages/login.php" class="mobile-nav-item<?= $mnavIs('me') ?>" data-nav="login"<?= $mnavAria('me') ?>>
        <?= lw_icon('login', 20, ['wrap' => false]) ?>
        <span><?= t('mnav.login') ?></span>
    </a>
    <?php endif; ?>
</nav>

<script>
(function () {
    // 「消息」项：优先唤起站内既有私信弹窗；当前页面没有 enhancements.js 时降级跳个人中心，
    // 保证任何页面点它都有反馈（不会出现「点了没反应」）。
    document.addEventListener('click', function (e) {
        var item = e.target.closest ? e.target.closest('.mobile-nav-item[data-nav="msg"]') : null;
        if (!item) { return; }
        e.preventDefault();
        var pm = (window.App && window.App.PrivateMessage) ? window.App.PrivateMessage : null;
        if (pm && typeof pm.open === 'function') {
            pm.open();
        } else {
            window.location.href = '<?= SITE_URL ?>/pages/user_center.php';
        }
    });
})();
</script>
