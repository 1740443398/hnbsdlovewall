<?php
/**
 * 通知中心（整页版）
 * 顶部铃铛下拉适合「瞄一眼」，整页版用于：手机端深链（App #notifications 兜底）、
 * 以及需要翻历史/按分类看的时候。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$user = requireLogin();
$user = checkBanned($user);
$pageTitle = '通知中心 · ' . SITE_NAME;
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
<title><?= htmlspecialchars($pageTitle) ?></title>
<?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
<style>
.ntf-page{max-width:760px;margin:0 auto;padding:18px 14px 90px}
.ntf-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.ntf-head h1{font-size:19px;margin:0}
.ntf-head button{background:none;border:1px solid var(--border);border-radius:999px;padding:6px 14px;font-size:12.5px;color:var(--primary);cursor:pointer;background:var(--card-bg)}
.ntf-head .ntf-toggle.on{background:var(--primary);border-color:var(--primary);color:#fff}
.ntf-tabs{display:flex;gap:8px;margin-bottom:12px;overflow-x:auto;padding-bottom:2px}
.ntf-tab{flex:0 0 auto;padding:6px 14px;border-radius:999px;border:1px solid var(--border);background:var(--card-bg);color:var(--text-muted);font-size:12.5px;cursor:pointer}
.ntf-tab.on{background:var(--primary);color:#fff;border-color:var(--primary)}
.ntf-list{display:flex;flex-direction:column;gap:8px}
.ntf-item{display:flex;gap:10px;align-items:flex-start;background:var(--card-bg);border:1px solid var(--border);border-radius:14px;padding:12px 14px;text-decoration:none;color:var(--text)}
.ntf-item.unread{box-shadow:inset 3px 0 0 var(--primary)}
.ntf-item .cat{font-size:11px;color:var(--primary);background:rgba(47,91,154,.10);border-radius:6px;padding:1px 6px;margin-right:6px;white-space:nowrap}
.ntf-item .txt{flex:1;font-size:13.5px;line-height:1.55}
.ntf-item .tm{font-size:11.5px;color:var(--text-muted);margin-top:4px}
.ntf-empty{text-align:center;color:var(--text-muted);padding:60px 16px;font-size:13.5px}
.ntf-more{display:block;width:100%;margin-top:12px;padding:10px;border-radius:12px;border:1px solid var(--border);background:var(--card-bg);color:var(--text-muted);font-size:13px;cursor:pointer}
.sk{height:64px;border-radius:14px;background:linear-gradient(90deg,rgba(140,150,165,.14),rgba(140,150,165,.28),rgba(140,150,165,.14));background-size:200% 100%;animation:skm 1.2s infinite}
@keyframes skm{0%{background-position:200% 0}100%{background-position:-200% 0}}
@media (prefers-reduced-motion: reduce){.sk{animation:none}}
</style>
</head>
<body>
<?php require __DIR__ . '/../includes/site_header.php'; ?>
<main class="ntf-page">
  <div class="ntf-head">
    <h1>🔔 通知中心</h1>
    <div style="display:flex;gap:8px;align-items:center">
      <button type="button" id="unreadOnlyBtn" class="ntf-toggle" aria-pressed="false">只看未读</button>
      <button type="button" id="markAll">全部已读</button>
    </div>
  </div>
  <div class="ntf-tabs" id="tabs">
    <button class="ntf-tab on" data-tab="all">全部</button>
    <button class="ntf-tab" data-tab="interact">互动</button>
    <button class="ntf-tab" data-tab="lottery">抽奖</button>
    <button class="ntf-tab" data-tab="system">系统</button>
  </div>
  <div class="ntf-list" id="list"><div class="sk"></div><div class="sk"></div><div class="sk"></div></div>
  <button class="ntf-more" id="more" style="display:none">加载更多</button>
</main>
<script>
var CSRF = <?= json_encode(generateCSRFToken()) ?>;
var TAB = 'all', PAGE = 1, HAS_MORE = false, ITEMS = [];
var UNREAD_ONLY = false;
try { UNREAD_ONLY = localStorage.getItem('lw_ntf_unread_only') === '1'; } catch (e) {}
var UOB = document.getElementById('unreadOnlyBtn');
var CAT_NAME = { comment: '评论', reply: '回复', mention: '@我', follow: '关注', like: '点赞',
  pm: '私信', admin: '管理', system: '系统', lottery_result: '抽奖' };

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
  return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

function itemHtml(n) {
  var link = n.link ? (n.link.charAt(0) === '/' ? n.link : '') : (n.post_id ? '/pages/post_detail.php?id=' + n.post_id : '/');
  return '<a class="ntf-item' + (n.is_read ? '' : ' unread') + '" href="' + esc(link) + '" data-id="' + n.id + '">' +
    '<div class="txt"><span class="cat">' + esc(CAT_NAME[n.type] || '通知') + '</span>' + esc(n.content || '') +
    '<div class="tm">' + esc(n.timeAgo || n.created_at || '') + '</div></div></a>';
}

function render(append) {
  var box = document.getElementById('list');
  ITEMS = append ? ITEMS.concat(curr) : curr;
  if (!ITEMS.length) { box.innerHTML = '<div class="ntf-empty">暂无通知</div>'; document.getElementById('more').style.display = 'none'; return; }
  box.innerHTML = ITEMS.map(itemHtml).join('');
  bindItemClicks();
  document.getElementById('more').style.display = HAS_MORE ? 'block' : 'none';
  document.getElementById('more').textContent = HAS_MORE ? '加载更多' : '没有更多了';
}
var curr = [];
function load(page, append) {
  var url = '/api/user/notifications.php?page=' + page + '&limit=20' + (TAB !== 'all' ? '&category=' + TAB : '') + (UNREAD_ONLY ? '&unread=1' : '');
  if (!append) document.getElementById('list').innerHTML = '<div class="sk"></div><div class="sk"></div><div class="sk"></div>';
  fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.success) { document.getElementById('list').innerHTML = '<div class="ntf-empty">' + esc(d.message || '加载失败') + '</div>'; return; }
    curr = d.data.notifications || [];
    HAS_MORE = !!(d.data.pagination ? d.data.pagination.has_more : false);
    PAGE = page;
    render(append);
  }).catch(function () { document.getElementById('list').innerHTML = '<div class="ntf-empty">网络错误</div>'; });
}

/* 点击通知项：先标已读（服务端即删），再跳转；失败也照常跳转 */
function bindItemClicks() {
  document.querySelectorAll('#list .ntf-item').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var href = a.getAttribute('href') || '/';
      var id = a.getAttribute('data-id');
      var fd = new URLSearchParams(); fd.set('action', 'read'); fd.set('id', id); fd.set('csrf_token', CSRF);
      fetch('/api/user/notifications.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .catch(function () {})
        .then(function () { window.location.href = href; });
    });
  });
}

document.getElementById('tabs').addEventListener('click', function (e) {
  var b = e.target.closest('.ntf-tab'); if (!b) return;
  document.querySelectorAll('.ntf-tab').forEach(function (x) { x.classList.toggle('on', x === b); });
  TAB = b.getAttribute('data-tab');
  load(1, false);
});
document.getElementById('more').addEventListener('click', function () { if (HAS_MORE) load(PAGE + 1, true); });
function syncUnreadBtn() {
  if (!UOB) return;
  UOB.classList.toggle('on', UNREAD_ONLY);
  UOB.setAttribute('aria-pressed', UNREAD_ONLY ? 'true' : 'false');
  UOB.textContent = UNREAD_ONLY ? '只看未读 ✓' : '只看未读';
}
if (UOB) {
  UOB.addEventListener('click', function () {
    UNREAD_ONLY = !UNREAD_ONLY;
    try { localStorage.setItem('lw_ntf_unread_only', UNREAD_ONLY ? '1' : '0'); } catch (e) {}
    syncUnreadBtn();
    load(1, false);
  });
  syncUnreadBtn();
}

document.getElementById('markAll').addEventListener('click', function () {
  var fd = new URLSearchParams(); fd.set('action', 'read_all'); fd.set('csrf_token', CSRF);
  fetch('/api/user/notifications.php', { method: 'POST', credentials: 'same-origin', body: fd })
    .then(function () { load(1, false); }).catch(function () {});
});
load(1, false);
</script>
<?php require __DIR__ . '/../includes/mobile_bottom_nav.php'; ?>
</body>
</html>
