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
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <link rel="alternate" type="application/rss+xml" title="RSS" href="/api/rss.php">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
    </script>
</head>
<body>
<?php require __DIR__ . '/../includes/site_header.php'; ?>

<style>
.disc-tabs{display:flex;gap:8px;margin:18px 0;flex-wrap:wrap}
.disc-tab{padding:8px 18px;border-radius:999px;border:1px solid #dfe4ea;background:#fff;font-size:.88rem;color:#4a5568;text-decoration:none;transition:all .15s}
.disc-tab:hover{border-color:#2F5B9A;color:#2F5B9A}
.disc-tab.on{background:#2F5B9A;color:#fff;border-color:#2F5B9A}
.disc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.disc-card{background:#fff;border-radius:12px;padding:16px;border:1px solid #edf1f5;transition:box-shadow .15s}
.disc-card:hover{box-shadow:0 4px 16px rgba(47,91,154,.10)}
.disc-card h3{margin:0 0 8px;font-size:1rem;line-height:1.45}
.disc-card h3 a{color:#1a202c;text-decoration:none}
.disc-card h3 a:hover{color:#2F5B9A}
.disc-card .ex{font-size:.86rem;color:#4a5568;line-height:1.65;margin-bottom:10px}
.disc-card .mt{font-size:.76rem;color:#a0aec0;display:flex;gap:12px;flex-wrap:wrap}
.tag-cloud{display:flex;flex-wrap:wrap;gap:10px}
.tag-item{padding:6px 14px;border-radius:999px;background:#eef3fa;color:#2F5B9A;text-decoration:none;font-size:.85rem}
.tag-item:hover{background:#2F5B9A;color:#fff}
.tag-item .c{opacity:.65;font-size:.75rem;margin-left:4px}
.series-card{background:#fff;border-radius:12px;padding:16px;border-left:4px solid #2F5B9A}
.series-card h3{margin:0 0 6px;font-size:1rem}
.series-card .d{font-size:.84rem;color:#4a5568;line-height:1.6;margin-bottom:8px}
.empty{text-align:center;padding:60px 20px;color:#a0aec0}
</style>

<main class="site-main">
<div class="container" style="max-width:1100px;margin:0 auto;padding:0 16px">
    <h1 style="font-size:1.5rem;margin:22px 0 6px">发现</h1>
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
