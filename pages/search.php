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
    <?php if (!empty($__bundleOffForPage)): ?>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <?php endif; ?>
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
    </script>
    <style>
    .sr-box{display:flex;gap:10px;margin:20px 0}
    .sr-box input{flex:1;padding:12px 16px;border: 2px solid var(--border, #dfe4ea);border-radius:10px;font-size:1rem;outline:none;transition:border-color .15s}
    .sr-box input:focus{border-color: var(--primary, #2F5B9A)}
    .sr-box button{padding:12px 24px;background: var(--primary, #2F5B9A);color: var(--card-bg, #fff);border:none;border-radius:10px;font-size:.95rem;cursor:pointer}
    .sr-tabs{display:flex;gap:8px;margin-bottom:16px}
    .sr-tab{padding:6px 14px;border-radius:999px;border: 1px solid var(--border, #dfe4ea);background: var(--card-bg, #fff);font-size:.84rem;color: var(--text-secondary, #4a5568);cursor:pointer;text-decoration:none}
    .sr-tab.on{background: var(--primary, #2F5B9A);color: var(--card-bg, #fff);border-color: var(--primary, #2F5B9A)}
    .sr-item{background: var(--card-bg, #fff);border-radius:10px;padding:16px;margin-bottom:10px;border: 1px solid var(--border-light, #edf1f5)}
    .sr-item h3{margin:0 0 6px;font-size:1.02rem}
    .sr-item h3 a{color: var(--text, #1a202c);text-decoration:none}
    .sr-item h3 a:hover{color: var(--primary, #2F5B9A)}
    .sr-item .sn{font-size:.88rem;color: var(--text-secondary, #4a5568);line-height:1.7;margin-bottom:8px}
    .sr-item mark{background:#fff3bf;padding:0 2px;border-radius:2px}
    .sr-item .mt{font-size:.76rem;color: var(--text-muted, #a0aec0);display:flex;gap:14px;flex-wrap:wrap}
    .sr-item .fd{font-size:.7rem;background: var(--primary-light, #eef3fa);color: var(--primary, #2F5B9A);padding:1px 6px;border-radius:4px;margin-left:6px}
    .sr-empty{text-align:center;padding:60px 20px;color: var(--text-muted, #a0aec0)}
    .sr-suggest{text-align:center;margin-top:6px}
    .sr-suggest-title{font-size:.8rem;color:var(--text-muted,#a0aec0);margin-bottom:10px}
    .sr-suggest-chips{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}
    .sr-suggest-chips a{background:var(--card-bg,#fff);border:1px solid var(--border,#dfe4ea);border-radius:999px;padding:6px 14px;font-size:.84rem;color:var(--text-secondary,#4a5568);text-decoration:none}
    .sr-suggest-chips a:hover{border-color:var(--primary,#2F5B9A);color:var(--primary,#2F5B9A)}
    #moreWrap{text-align:center;margin:18px 0 8px}
    #moreBtn{padding:10px 26px;border-radius:999px;border:1px solid var(--border,#dfe4ea);background:var(--card-bg,#fff);color:var(--text-secondary,#4a5568);font-size:.9rem;cursor:pointer}
    #moreBtn:disabled{opacity:.6;cursor:default}
    .sr-history{display:none;margin:4px 0 14px;font-size:.85rem}
    .sr-history-head{display:flex;justify-content:space-between;align-items:center;color:var(--text-muted,#a0aec0);margin-bottom:8px}
    .sr-history-head button{background:none;border:none;color:var(--primary,#2F5B9A);cursor:pointer;font-size:.82rem;padding:2px 4px}
    .sr-history-chips{display:flex;flex-wrap:wrap;gap:8px}
    .sr-history-chips a{background:var(--card-bg,#fff);border:1px solid var(--border,#dfe4ea);border-radius:999px;padding:5px 12px;color:var(--text-secondary,#4a5568);text-decoration:none;font-size:.82rem}
    .sr-history-chips a:hover{border-color:var(--primary,#2F5B9A);color:var(--primary,#2F5B9A)}
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

    <div class="sr-history" id="history">
        <div class="sr-history-head"><span>最近搜索</span><button type="button" id="clearHistory">清空</button></div>
        <div class="sr-history-chips" id="historyChips"></div>
    </div>

    <div class="sr-tabs" id="sorts" style="display:none">
        <a class="sr-tab on" data-sort="relevance" href="#">相关度</a>
        <a class="sr-tab" data-sort="latest" href="#">最新</a>
        <a class="sr-tab" data-sort="hot" href="#">最热</a>
    </div>

    <div id="result"><div class="sr-empty">输入关键词开始搜索</div></div>
    <div id="moreWrap" style="display:none"><button type="button" id="moreBtn">加载更多</button></div>
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

function itemHtml(p) {
    var fields = (p.matched_fields || []).map(function (f) {
        return '<span class="fd">' + fieldLabel(f) + '</span>';
    }).join('');
    return '<div class="sr-item">' +
        '<h3><a href="/pages/post_detail.php?id=' + p.id + '">' +
        (p.title ? highlight(p.title) : highlight((p.snippet || '').slice(0, 30))) + fields + '</a></h3>' +
        '<div class="sn">' + highlight(p.snippet || '') + '</div>' +
        '<div class="mt"><span>' + esc(p.author_name) + '</span>' +
        '<span>♡ ' + p.like_count + '</span><span>评论 ' + p.comment_count + '</span>' +
        '<span>' + esc(p.created_at) + '</span></div></div>';
}

function render(data, append) {
    TERMS = data.terms || [];
    var posts = data.posts || [];
    ITEMS = append ? ITEMS.concat(posts) : posts;
    HAS_MORE = !!(data.pagination && data.pagination.has_more);
    var box = document.getElementById('result');
    var moreWrap = document.getElementById('moreWrap');
    var moreBtn = document.getElementById('moreBtn');
    var sorts = document.getElementById('sorts');
    if (sorts) sorts.style.display = ITEMS.length ? 'flex' : 'none';

    if (!ITEMS.length) {
        var sugg = ['失物招领', '学习互助', '交友闲聊', '表白', '校园资讯', '二手交易'];
        box.innerHTML = '<div class="sr-empty">' +
            (data.message || ('没有找到与「' + esc(data.query) + '」相关的内容')) +
            '</div><div class="sr-suggest"><div class="sr-suggest-title">试试这些热门词</div><div class="sr-suggest-chips">' +
            sugg.map(function (w) { return '<a href="#" data-w="' + esc(w) + '">' + esc(w) + '</a>'; }).join('') +
            '</div></div>';
        box.querySelectorAll('.sr-suggest a').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('q').value = el.getAttribute('data-w');
                search();
            });
        });
        if (moreWrap) moreWrap.style.display = 'none';
        return;
    }
    var head = '<div style="font-size:.84rem;color:#718096;margin-bottom:12px">共 ' + data.total + ' 条结果</div>';
    box.innerHTML = head + ITEMS.map(itemHtml).join('');
    if (moreWrap) moreWrap.style.display = 'block';
    if (moreBtn) { moreBtn.disabled = !HAS_MORE; moreBtn.textContent = HAS_MORE ? '加载更多' : '没有更多了'; }
}

function fetchPage(page, append) {
    var q = LAST_Q;
    var url = '/api/search/fulltext.php?q=' + encodeURIComponent(q) + '&sort=' + SORT +
        '&page=' + page + '&limit=20';
    document.getElementById('result').innerHTML = '<div class="sr-empty">搜索中…</div>';
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) {
                document.getElementById('result').innerHTML = '<div class="sr-empty">' + esc(d.message || '搜索失败') + '</div>';
                return;
            }
            PAGE = page;
            render(d.data, append);
        })
        .catch(function () {
            document.getElementById('result').innerHTML = '<div class="sr-empty">网络错误</div>';
        });
}

function search() {
    var q = document.getElementById('q').value.trim();
    if (!q) {
        document.getElementById('result').innerHTML = '<div class="sr-empty">请输入关键词</div>';
        renderHistory();
        return;
    }
    LAST_Q = q;
    saveHistory(q);
    var __h = document.getElementById('history'); if (__h) __h.style.display = 'none';
    history.replaceState(null, '', '/pages/search.php?q=' + encodeURIComponent(q) + '&sort=' + SORT);
    ITEMS = [];
    fetchPage(1, false);
}

function loadMore() {
    if (!HAS_MORE || !LAST_Q) return;
    fetchPage(PAGE + 1, true);
}
document.getElementById('moreBtn').addEventListener('click', loadMore);

/* ---------- 最近搜索（localStorage，最多 8 条） ---------- */
var HIST_KEY = 'lw_search_history';
function readHistory() {
    try { var a = JSON.parse(localStorage.getItem(HIST_KEY) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; }
}
function saveHistory(q) {
    q = String(q || '').trim();
    if (!q) return;
    var a = readHistory().filter(function (x) { return x !== q; });
    a.unshift(q);
    try { localStorage.setItem(HIST_KEY, JSON.stringify(a.slice(0, 8))); } catch (e) {}
    renderHistory();
}
function renderHistory() {
    var box = document.getElementById('history');
    var chips = document.getElementById('historyChips');
    if (!box || !chips) return;
    var a = readHistory();
    if (!a.length) { box.style.display = 'none'; return; }
    box.style.display = 'block';
    chips.innerHTML = a.map(function (q) {
        return '<a href="#" data-q="' + esc(q) + '">' + esc(q) + '</a>';
    }).join('');
    chips.querySelectorAll('a').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('q').value = el.getAttribute('data-q');
            document.getElementById('history').style.display = 'none';
            search();
        });
    });
}
document.getElementById('clearHistory').addEventListener('click', function () {
    try { localStorage.removeItem(HIST_KEY); } catch (e) {}
    renderHistory();
});

function doSearch(e){ e.preventDefault(); search(); }

/* 输入防抖：停顿 400ms 自动搜（≥1 个字符），省去反复点「搜索」 */
var __srTimer = null;
document.getElementById('q').addEventListener('input', function () {
    var v = this.value.trim();
    clearTimeout(__srTimer);
    if (!v) { document.getElementById('result').innerHTML = '<div class="sr-empty">输入关键词开始搜索</div>'; renderHistory(); return; }
    __srTimer = setTimeout(function () { search(); }, 400);
});

document.querySelectorAll('.sr-tab').forEach(function(a){
    a.addEventListener('click', function(e){
        e.preventDefault();
        document.querySelectorAll('.sr-tab').forEach(function(x){ x.classList.remove('on'); });
        a.classList.add('on');
        SORT = a.dataset.sort;
        search();
    });
});

<?php if ($q === ''): ?>
renderHistory();
<?php endif; ?>
<?php if ($q !== ''): ?>
document.getElementById('q').value = <?= json_encode($q) ?>;
search();
<?php endif; ?>
</script>

<?php require __DIR__ . '/../includes/mobile_bottom_nav.php'; ?>
<div class="toast-container" id="toastContainer"></div>
</body>
</html>
