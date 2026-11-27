<?php
/**
 * 发现页：推荐 / 热门 / 主题 / 专栏
 *
 * 商业社区都有一页「发现」，给用户一个不靠时间线的入口。
 * 这里复用 enhance.php 里的推荐与热度算法。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/enhance.php';

$user = requireLoginOrGuest();
if ($user) { $user = checkBanned($user); }
$isGuest = ($user === null);

$tab = (string)($_GET['tab'] ?? 'recommend');
if (!in_array($tab, ['recommend', 'hot', 'tags', 'series'], true)) {
    $tab = 'recommend';
}
$pageTitle = '发现 · ' . SITE_NAME;
$LANG_CODE = $LANG_CODE ?? 'zh_CN';
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <?php require __DIR__ . '/../includes/seo_meta.php'; ?>
    <meta name="description" content="发现值得读的内容 · <?= htmlspecialchars(SITE_NAME) ?>">
    <?php if (!empty($__bundleOffForPage)): ?>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <?php endif; ?>
    <link rel="alternate" type="application/rss+xml" title="RSS" href="/api/rss.php">
    <style>
    /* ==========================================================================
     * 发现页样式
     * ==========================================================================
     * 【2026-11-20 重写】
     *
     * 原实现有三个问题：
     *   1. <style> 写在 <body> 里 —— 部分浏览器会推迟到解析到该处才应用，
     *      首屏可能闪一下无样式内容
     *   2. 全部硬编码颜色（#2F5B9A / #fff / #dfe4ea / #1a202c）——
     *      暗色模式下整页还是白的，与全站主题完全脱节
     *   3. 0 处 var(--) 引用 —— 后台改主题色对它毫无影响
     *
     * 现在全部改用全站 CSS 变量，与 style.css 同一套令牌。
     * ========================================================================== */

    .disc-tabs {
        display: flex;
        gap: 8px;
        margin: 16px 0;
        flex-wrap: wrap;
    }

    .disc-tab {
        padding: 7px 16px;
        border-radius: 999px;
        border: 1px solid var(--border, #dfe4ea);
        background: var(--card-bg, #fff);
        font-size: 0.86rem;
        color: var(--text-secondary, #4a5568);
        text-decoration: none;
        transition: border-color 0.15s var(--ease-sharp, ease),
                    color 0.15s var(--ease-sharp, ease),
                    background-color 0.15s var(--ease-sharp, ease),
                    transform 0.08s var(--ease-sharp, ease);
        -webkit-tap-highlight-color: transparent;
    }

    .disc-tab:hover {
        border-color: var(--primary, #2F5B9A);
        color: var(--primary, #2F5B9A);
    }

    .disc-tab:active {
        transform: scale(0.97);
    }

    .disc-tab.on {
        background: var(--primary, #2F5B9A);
        color: var(--on-solid, #fff);
        border-color: var(--primary, #2F5B9A);
        font-weight: 600;
    }

    .disc-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 14px;
    }

    .disc-card {
        background: var(--card-bg, #fff);
        border-radius: 12px;
        padding: 16px;
        border: 1px solid var(--border-light, #edf1f5);
        transition: box-shadow 0.18s var(--ease-sharp, ease),
                    transform 0.18s var(--ease-sharp, ease);
    }

    .disc-card:hover {
        box-shadow: var(--elev-2, 0 4px 16px rgba(47, 91, 154, 0.10));
        transform: translateY(-2px);
    }

    .disc-card h3 {
        margin: 0 0 8px;
        font-size: 1rem;
        line-height: 1.45;
    }

    .disc-card h3 a {
        color: var(--text, #1a202c);
        text-decoration: none;
    }

    .disc-card h3 a:hover {
        color: var(--primary, #2F5B9A);
    }

    .disc-card .ex {
        font-size: 0.86rem;
        color: var(--text-secondary, #4a5568);
        line-height: 1.65;
        margin-bottom: 10px;
    }

    .disc-card .mt {
        font-size: 0.76rem;
        color: var(--text-muted, #a0aec0);
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .tag-cloud {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .tag-item {
        padding: 6px 13px;
        border-radius: 999px;
        background: var(--primary-light, #eef3fa);
        color: var(--primary, #2F5B9A);
        text-decoration: none;
        font-size: 0.85rem;
        transition: background-color 0.15s var(--ease-sharp, ease),
                    color 0.15s var(--ease-sharp, ease);
    }

    .tag-item:hover {
        background: var(--primary, #2F5B9A);
        color: var(--on-solid, #fff);
    }

    .tag-item .c {
        opacity: 0.65;
        font-size: 0.75rem;
        margin-left: 4px;
    }

    .series-card {
        background: var(--card-bg, #fff);
        border-radius: 12px;
        padding: 16px;
        border-left: 4px solid var(--primary, #2F5B9A);
        border-top: 1px solid var(--border-light, #edf1f5);
        border-right: 1px solid var(--border-light, #edf1f5);
        border-bottom: 1px solid var(--border-light, #edf1f5);
    }

    .series-card h3 {
        margin: 0 0 6px;
        font-size: 1rem;
        color: var(--text, #1a202c);
    }

    .series-card .d {
        font-size: 0.84rem;
        color: var(--text-secondary, #4a5568);
        line-height: 1.6;
        margin-bottom: 8px;
    }

    .empty {
        text-align: center;
        padding: 60px 20px;
        color: var(--text-muted, #a0aec0);
    }

    /* ---- 手机端：单列 + 收紧间距 ---- */
    @media (max-width: 768px) {
        .disc-tabs {
            margin: 12px 0;
            gap: 6px;
        }
        .disc-tab {
            padding: 6px 13px;
            font-size: 0.8rem;
        }
        /* 单列：手机屏放两列每列只有 160px，标题会挤成两行 */
        .disc-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .disc-card {
            padding: 13px;
            border-radius: 10px;
        }
        /* 手机端去掉 hover 位移（触屏没有 hover，位移只会造成抖动） */
        .disc-card:hover {
            transform: none;
        }
        .empty {
            padding: 44px 16px;
        }
    }
    </style>
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
    </script>
</head>
<body>
<?php require __DIR__ . '/../includes/site_header.php'; ?>

<main class="site-main">
<div class="container" style="max-width:1100px;margin:0 auto;padding:0 16px">
    <h1 style="font-size:1.5rem;margin:22px 0 6px">发现</h1>
<div class="disc-quick" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:12px 0 4px">
    <a class="disc-quick-card" href="/pages/lottery.php" style="display:flex;align-items:center;gap:10px;padding:14px;border-radius:14px;border:1px solid var(--border);background:var(--card-bg);text-decoration:none;color:var(--text)">
        <span style="font-size:20px">🎁</span><span><b style="display:block;font-size:14px">站内抽奖</b><small style="color:var(--text-muted);font-size:12px">转发动态即可参与</small></span>
    </a>
    <a class="disc-quick-card" href="/pages/tools.php" style="display:flex;align-items:center;gap:10px;padding:14px;border-radius:14px;border:1px solid var(--border);background:var(--card-bg);text-decoration:none;color:var(--text)">
        <span style="font-size:20px">🧰</span><span><b style="display:block;font-size:14px">实用工具</b><small style="color:var(--text-muted);font-size:12px">34 个校园小工具</small></span>
    </a>
    <a class="disc-quick-card" href="/pages/learning.php" style="display:flex;align-items:center;gap:10px;padding:14px;border-radius:14px;border:1px solid var(--border);background:var(--card-bg);text-decoration:none;color:var(--text)">
        <span style="font-size:20px">📚</span><span><b style="display:block;font-size:14px">学习专区</b><small style="color:var(--text-muted);font-size:12px">答疑 / 经验 / 资料</small></span>
    </a>
    <a class="disc-quick-card" href="/pages/ranking.php" style="display:flex;align-items:center;gap:10px;padding:14px;border-radius:14px;border:1px solid var(--border);background:var(--card-bg);text-decoration:none;color:var(--text)">
        <span style="font-size:20px">🏆</span><span><b style="display:block;font-size:14px">排行榜</b><small style="color:var(--text-muted);font-size:12px">本周活跃同学</small></span>
    </a>
</div>
    <p style="color:#718096;font-size:.88rem;margin:0 0 4px">
        <?php if ($user): ?>根据你的阅读习惯推荐，也可以看看热门与主题<?php else: ?>登录后可按兴趣推荐 · 以下是全站热门<?php endif; ?>
        <a href="/api/rss.php" style="margin-left:12px;font-size:.8rem;color:#2F5B9A;text-decoration:none">RSS 订阅</a>
    </p>

    <div class="disc-tabs">
        <a class="disc-tab <?= $tab === 'recommend' ? 'on' : '' ?>" href="/pages/discover.php?tab=recommend">为你推荐</a>
        <a class="disc-tab <?= $tab === 'hot' ? 'on' : '' ?>" href="/pages/discover.php?tab=hot">热门</a>
        <a class="disc-tab <?= $tab === 'tags' ? 'on' : '' ?>" href="/pages/discover.php?tab=tags">主题</a>
        <a class="disc-tab <?= $tab === 'series' ? 'on' : '' ?>" href="/pages/discover.php?tab=series">专栏</a>
    </div>

    <div id="content" style="margin-bottom:40px"><div class="empty">加载中…</div></div>
</div>
</main>

<script>
var TAB = <?= json_encode($tab) ?>;
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function postCard(p){
    var title = p.title || (p.content || '').slice(0, 40);
    var ex = (p.content || '').slice(0, 90);
    return '<div class="disc-card">' +
        '<h3><a href="/pages/post_detail.php?id=' + p.id + '">' + esc(title) + '</a></h3>' +
        '<div class="ex">' + esc(ex) + '</div>' +
        '<div class="mt"><span>♡ ' + (p.like_count||p.likes||0) + '</span>' +
        '<span>评论 ' + (p.comment_count||p.comments||0) + '</span>' +
        '<span>' + esc(p.created_at||'') + '</span></div></div>';
}
function render(posts, emptyMsg){
    if (!posts.length) { document.getElementById('content').innerHTML = '<div class="empty">' + emptyMsg + '</div>'; return; }
    document.getElementById('content').innerHTML = '<div class="disc-grid">' + posts.map(postCard).join('') + '</div>';
}
function loadRecommend(){
    fetch('/api/recommend.php?action=for_you&limit=12', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){ render((d.data && d.data.posts) || [], '暂无推荐，先去发个帖子吧'); })
    .catch(function(){ document.getElementById('content').innerHTML = '<div class="empty">加载失败</div>'; });
}
function loadHot(){
    fetch('/api/recommend.php?action=hot&limit=12', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){ render((d.data && d.data.posts) || [], '暂无内容'); });
}
function loadTags(){
    fetch('/api/tags.php?action=cloud&limit=60', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        var tags = (d.data && d.data.tags) || [];
        if (!tags.length) { document.getElementById('content').innerHTML = '<div class="empty">还没有标签 —— 发帖时写 #话题# 就会自动聚合</div>'; return; }
        var html = '<div class="tag-cloud">';
        tags.forEach(function(t){
            var size = Math.min(1.3, 0.85 + t.count * 0.05);
            html += '<a class="tag-item" style="font-size:' + size + 'rem" href="/pages/discover.php?tab=tags&tag=' +
                encodeURIComponent(t.slug) + '">' + esc(t.slug) + '<span class="c">' + t.count + '</span></a>';
        });
        html += '</div>';
        document.getElementById('content').innerHTML = html;
    });
}
function loadSeries(){
    fetch('/api/series.php?action=list', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        var list = (d.data && d.data.series) || [];
        if (!list.length) { document.getElementById('content').innerHTML = '<div class="empty">还没有专栏 —— 在发帖页可以把多篇帖子组织成专栏</div>'; return; }
        var html = '<div class="disc-grid">';
        list.forEach(function(s){
            html += '<div class="series-card"><h3>' + esc(s.title) + '</h3>' +
                '<div class="d">' + esc(s.description||'') + '</div>' +
                '<div style="font-size:.76rem;color:#a0aec0">' + s.count + ' 篇 · ' + esc(s.author||'') + '</div></div>';
        });
        html += '</div>';
        document.getElementById('content').innerHTML = html;
    });
}
if (TAB === 'hot') loadHot();
else if (TAB === 'tags') loadTags();
else if (TAB === 'series') loadSeries();
else loadRecommend();
</script>

<?php require __DIR__ . '/../includes/mobile_bottom_nav.php'; ?>
<div class="toast-container" id="toastContainer"></div>
</body>
</html>
