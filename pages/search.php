<?php
/**
 * 搜索结果页
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/enhance.php';

$user = requireLoginOrGuest();
if ($user) { $user = checkBanned($user); }
$isGuest = ($user === null);

$q = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'relevance');
$pageTitle = ($q !== '' ? $q . ' · 搜索' : '搜索') . ' · ' . SITE_NAME;
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
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
    </script>
    <style>
    .sr-box{display:flex;gap:10px;margin:20px 0}
    .sr-box input{flex:1;padding:12px 16px;border:2px solid #dfe4ea;border-radius:10px;font-size:1rem;outline:none;transition:border-color .15s}
    .sr-box input:focus{border-color:#2F5B9A}
    .sr-box button{padding:12px 24px;background:#2F5B9A;color:#fff;border:none;border-radius:10px;font-size:.95rem;cursor:pointer}
    .sr-tabs{display:flex;gap:8px;margin-bottom:16px}
    .sr-tab{padding:6px 14px;border-radius:999px;border:1px solid #dfe4ea;background:#fff;font-size:.84rem;color:#4a5568;cursor:pointer;text-decoration:none}
    .sr-tab.on{background:#2F5B9A;color:#fff;border-color:#2F5B9A}
    .sr-item{background:#fff;border-radius:10px;padding:16px;margin-bottom:10px;border:1px solid #edf1f5}
    .sr-item h3{margin:0 0 6px;font-size:1.02rem}
    .sr-item h3 a{color:#1a202c;text-decoration:none}
    .sr-item h3 a:hover{color:#2F5B9A}
    .sr-item .sn{font-size:.88rem;color:#4a5568;line-height:1.7;margin-bottom:8px}
    .sr-item mark{background:#fff3bf;padding:0 2px;border-radius:2px}
    .sr-item .mt{font-size:.76rem;color:#a0aec0;display:flex;gap:14px;flex-wrap:wrap}
    .sr-item .fd{font-size:.7rem;background:#eef3fa;color:#2F5B9A;padding:1px 6px;border-radius:4px;margin-left:6px}
    .sr-empty{text-align:center;padding:60px 20px;color:#a0aec0}
    </style>
</head>
<body>
<?php require __DIR__ . '/../includes/site_header.php'; ?>

<main class="site-main">
<div class="container" style="max-width:900px;margin:0 auto;padding:0 16px">
    <h1 style="font-size:1.4rem;margin:22px 0 0">搜索</h1>

    <form class="sr-box" onsubmit="doSearch(event)">
        <input type="search" id="q" value="<?= htmlspecialchars($q) ?>" placeholder="搜索帖子标题、正文或作者…" autofocus>
        <button type="submit">搜索</button>
    </form>

    <div class="sr-tabs" id="sorts" style="display:none">
        <a class="sr-tab on" data-sort="relevance" href="#">相关度</a>
        <a class="sr-tab" data-sort="latest" href="#">最新</a>
        <a class="sr-tab" data-sort="hot" href="#">最热</a>
    </div>

    <div id="result"><div class="sr-empty">输入关键词开始搜索</div></div>
</div>
</main>

<script>
var SORT = <?= json_encode($sort) ?>;
var TERMS = [];

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function highlight(text){
    var out = esc(text);
    TERMS.forEach(function(t){
        if (!t) return;
        var re = new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        out = out.replace(re, '<mark>$1</mark>');
    });
    return out;
}

function fieldLabel(f){
    return {title:'标题', content:'正文', author:'作者'}[f] || f;
}

function render(data){
    TERMS = data.terms || [];
    var posts = data.posts || [];
    document.getElementById('sorts').style.display = posts.length ? 'flex' : 'none';
    if (!posts.length) {
        document.getElementById('result').innerHTML = '<div class="sr-empty">' +
            (data.message || ('没有找到与「' + esc(data.query) + '」相关的内容')) + '</div>';
        return;
    }
    var html = '<div style="font-size:.84rem;color:#718096;margin-bottom:12px">共 ' + data.total + ' 条结果</div>';
    posts.forEach(function(p){
        var fields = (p.matched_fields || []).map(function(f){
            return '<span class="fd">' + fieldLabel(f) + '</span>';
        }).join('');
        html += '<div class="sr-item">' +
            '<h3><a href="/pages/post_detail.php?id=' + p.id + '">' +
            (p.title ? highlight(p.title) : highlight(p.snippet.slice(0, 30))) + fields + '</a></h3>' +
            '<div class="sn">' + highlight(p.snippet) + '</div>' +
            '<div class="mt"><span>' + esc(p.author_name) + '</span>' +
            '<span>♡ ' + p.like_count + '</span><span>评论 ' + p.comment_count + '</span>' +
            '<span>' + esc(p.created_at) + '</span></div></div>';
    });
    document.getElementById('result').innerHTML = html;
}

function search(){
    var q = document.getElementById('q').value.trim();
    if (!q) { document.getElementById('result').innerHTML = '<div class="sr-empty">请输入关键词</div>'; return; }
    var url = '/api/search/fulltext.php?q=' + encodeURIComponent(q) + '&sort=' + SORT + '&limit=30';
    // 同步 URL，方便分享与前进后退
    history.replaceState(null, '', '/pages/search.php?q=' + encodeURIComponent(q) + '&sort=' + SORT);
    document.getElementById('result').innerHTML = '<div class="sr-empty">搜索中…</div>';
    fetch(url, {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (!d.success) { document.getElementById('result').innerHTML = '<div class="sr-empty">' + esc(d.message||'搜索失败') + '</div>'; return; }
        render(d.data);
    })
    .catch(function(){ document.getElementById('result').innerHTML = '<div class="sr-empty">网络错误</div>'; });
}

function doSearch(e){ e.preventDefault(); search(); }

document.querySelectorAll('.sr-tab').forEach(function(a){
    a.addEventListener('click', function(e){
        e.preventDefault();
        document.querySelectorAll('.sr-tab').forEach(function(x){ x.classList.remove('on'); });
        a.classList.add('on');
        SORT = a.dataset.sort;
        search();
    });
});

<?php if ($q !== ''): ?>
document.getElementById('q').value = <?= json_encode($q) ?>;
search();
<?php endif; ?>
</script>

<?php require __DIR__ . '/../includes/mobile_bottom_nav.php'; ?>
<div class="toast-container" id="toastContainer"></div>
</body>
</html>
