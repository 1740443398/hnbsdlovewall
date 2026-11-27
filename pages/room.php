<?php
/**
 * 公共聊天室页面
 *
 * 需求（一、5 / 手稿）：
 *   - 文本 / 图片 / GIF
 *   - 服务端消息保留 1 天
 *   - 每分钟最多 20 张图（手稿口径）+ 每日 20 张兜底
 *   - 服务端只持久化最新 20 张图片资源
 *
 * 交互：在线才轮询（visibilitychange + navigator.onLine），离线即停。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/lobby.php';

$user = requireLoginOrGuest();
if ($user) {
    $user = checkBanned($user);
}
$isGuest = ($user === null);

$pageTitle = '公共聊天室 - ' . SITE_NAME;
$quotaLeft = $user ? lwLobbyImageQuotaLeft((int)$user['id']) : 0;
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
    <?php if (!empty($__bundleOffForPage)): ?>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <?php endif; ?>
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const IS_GUEST = <?= $isGuest ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const LOBBY_QUOTA_LEFT = <?= (int)$quotaLeft ?>;
        const LOBBY_DAILY = <?= (int)LW_LOBBY_DAILY_IMAGES ?>;
        window.IS_LOGGED_IN = IS_LOGGED_IN;
        window.IS_GUEST = IS_GUEST;
        window.USER_DATA = USER_DATA;
    </script>
    <style>
        .lb-wrap{max-width:960px;margin:0 auto;padding:1.25rem 1rem 2rem;display:flex;flex-direction:column;height:calc(100dvh - 2rem);}
        .lb-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;}
        .lb-title{display:flex;align-items:center;gap:.5rem;font-size:1.25rem;font-weight:800;color:var(--text);}
        .lb-meta{display:flex;gap:.75rem;flex-wrap:wrap;font-size:.74rem;color:var(--text-secondary);}
        .lb-badge{padding:.15rem .5rem;border-radius:9999px;background:var(--bg-secondary,#F4F7FA);border:1px solid var(--border-light,#E4E9F0);}
        .lb-badge.online{color:#2E8B57;border-color:rgba(46,139,87,.25);background:rgba(46,139,87,.08);}
        .lb-badge.offline{color: var(--danger, #cb2431);border-color:rgba(203,36,49,.25);background:rgba(203,36,49,.08);}

        .lb-panel{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);border-radius:1rem;box-shadow:var(--shadow-sm);overflow:hidden;}
        .lb-msgs{flex:1 1 auto;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:.75rem;overscroll-behavior:contain;}
        .lb-msg{display:flex;gap:.625rem;align-items:flex-start;max-width:100%;}
        .lb-msg.mine{flex-direction:row-reverse;}
        .lb-av{width:2rem;height:2rem;border-radius:50%;flex:0 0 auto;object-fit:cover;background:var(--bg-secondary);}
        .lb-body{min-width:0;max-width:calc(100% - 6rem);}
        .lb-who{font-size:.72rem;color:var(--text-secondary);margin-bottom:.1875rem;display:flex;gap:.375rem;align-items:center;}
        .lb-msg.mine .lb-who{justify-content:flex-end;}
        .lb-role{font-size:.62rem;padding:.05rem .3rem;border-radius:.25rem;background:var(--primary-light,#E7EEF7);color:var(--primary);font-weight:600;}
        .lb-bubble{display:inline-block;padding:.5rem .75rem;border-radius:.75rem;background:var(--bg-secondary,#F4F7FA);color:var(--text);font-size:.875rem;line-height:1.6;word-break:break-word;white-space:pre-wrap;}
        .lb-msg.mine .lb-bubble{background:var(--primary);color: var(--card-bg, #fff);}
        .lb-bubble img{display:block;max-width:min(260px,60vw);max-height:260px;border-radius:.5rem;cursor:zoom-in;}
        .lb-time{font-size:.66rem;color:var(--text-tertiary,#8E9AA8);margin-top:.1875rem;}
        .lb-msg.mine .lb-time{text-align:right;}

        .lb-del-btn{border:0;background:none;color:var(--text-tertiary,#8E9AA8);font-size:.68rem;cursor:pointer;padding:0 2px;margin-left:4px}
    .lb-del-btn:hover{color:#C0392B;text-decoration:underline}
    .lb-at{color:var(--primary,#2F5B9A);font-weight:600}
    .lb-who-name{cursor:pointer}
    .lb-who-name:hover{color:var(--primary);text-decoration:underline}
    .lb-emoji-panel{position:absolute;bottom:64px;left:12px;right:12px;display:grid;grid-template-columns:repeat(8,1fr);gap:4px;padding:10px;border-radius:14px;background:var(--card-bg,#fff);border:1px solid var(--border,#dfe4ea);box-shadow:0 10px 30px rgba(0,0,0,.14);z-index:5}
    .lb-emoji-recent{grid-column:1/-1;display:grid;grid-template-columns:repeat(8,1fr);gap:4px;padding-bottom:6px;margin-bottom:4px;border-bottom:1px dashed var(--border-light,#E4E9F0)}
    .lb-emoji-item{font-size:22px;line-height:1;text-align:center;padding:5px 0;border-radius:8px;cursor:pointer;user-select:none}
    .lb-emoji-item:hover{background:rgba(47,91,154,.10)}
    .lb-more{position:sticky;top:0;align-self:center;margin:6px auto 8px;padding:5px 14px;border-radius:999px;border:1px solid var(--border,#dfe4ea);background:var(--card-bg,#fff);color:var(--text-secondary,#4a5568);font-size:12px;cursor:pointer;z-index:2}
    .lb-more:hover{border-color:var(--primary);color:var(--primary)}
    .lb-empty{flex:1 1 auto;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;color:var(--text-secondary);font-size:.85rem;padding:2rem;}
        .lb-compose{flex:0 0 auto;border-top:1px solid var(--border-light,#E4E9F0);padding:.625rem;display:flex;gap:.5rem;align-items:flex-end;background:var(--surface,#fff);}
        .lb-input{flex:1 1 auto;resize:none;min-height:2.5rem;max-height:7rem;padding:.5rem .75rem;border:1px solid var(--border,#E3E7EC);border-radius:.75rem;font-size:.875rem;line-height:1.5;font-family:inherit;color:var(--text);background:var(--bg,#fff);}
        .lb-input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(47,91,154,.12);}
        .lb-icon-btn{flex:0 0 auto;width:2.5rem;height:2.5rem;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border,#E3E7EC);border-radius:.75rem;background:var(--bg,#fff);color:var(--text-secondary);cursor:pointer;transition:all .18s;}
        .lb-icon-btn:hover{color:var(--primary);border-color:var(--primary);}
        .lb-icon-btn:disabled{opacity:.45;cursor:not-allowed;}
        .lb-send{flex:0 0 auto;height:2.5rem;padding:0 1.125rem;border:none;border-radius:.75rem;background:var(--primary);color: var(--card-bg, #fff);font-weight:600;font-size:.875rem;cursor:pointer;transition:all .18s;}
        .lb-send:hover{filter:brightness(1.06);}
        .lb-send:disabled{opacity:.5;cursor:not-allowed;}

        .lb-guest-note{padding:.75rem 1rem;border-top:1px solid var(--border-light,#E4E9F0);font-size:.8rem;color:var(--text-secondary);text-align:center;background:var(--warning-light,#FFF8E6);}
        .lb-guest-note a{color:var(--primary);font-weight:600;}
        .lb-new{position:sticky;bottom:.5rem;align-self:center;padding:.3rem .75rem;border-radius:9999px;background:var(--primary);color: var(--card-bg, #fff);font-size:.74rem;border:none;cursor:pointer;box-shadow:var(--shadow-md);}
        .lb-lightbox{position:fixed;inset:0;z-index:9999;background:rgba(8,12,20,.86);display:none;align-items:center;justify-content:center;padding:1rem;cursor:zoom-out;}
        .lb-lightbox.show{display:flex;}
        .lb-lightbox img{max-width:96vw;max-height:92vh;border-radius:.5rem;object-fit:contain;}

        @media (max-width:640px){
            .lb-wrap{padding:.75rem .625rem 1rem;height:calc(100dvh - 1.5rem);}
            .lb-title{font-size:1.05rem;}
            .lb-msgs{padding:.75rem .625rem;}
            .lb-body{max-width:calc(100% - 5rem);}
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/site_header.php'; ?>

<div class="lb-wrap">
    <div class="lb-head">
        <div class="lb-title">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            公共聊天室
        </div>
        <div class="lb-meta">
            <span class="lb-badge" id="lbConnState">连接中…</span>
            <span class="lb-badge">消息保留 24 小时</span>
            <span class="lb-badge" id="lbQuota">今日图片剩余 <?= (int)$quotaLeft ?> 张</span>
        </div>
    </div>

    <div class="lb-panel">
        <div class="lb-msgs" id="lbMsgs">
            <button type="button" class="lb-more" id="lbMore" style="display:none">↑ 加载更早消息</button>
            <div class="lb-empty" id="lbEmpty">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <span>还没有人说话，来打个招呼吧</span>
            </div>
        </div>

        <?php if ($user): ?>
        <div class="lb-compose" style="position:relative">
            <button type="button" class="lb-icon-btn" id="lbEmojiBtn" title="表情" aria-label="表情">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
            </button>
            <button type="button" class="lb-icon-btn" id="lbImgBtn" title="发送图片或 GIF" aria-label="发送图片或 GIF">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
            </button>
            <input type="file" id="lbFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
            <div class="lb-emoji-panel" id="lbEmojiPanel" hidden>
                <div class="lb-emoji-recent" id="lbEmojiRecent" hidden></div>
                <span class="lb-emoji-item">😀</span><span class="lb-emoji-item">😂</span><span class="lb-emoji-item">😊</span><span class="lb-emoji-item">😍</span>
                <span class="lb-emoji-item">😎</span><span class="lb-emoji-item">🤔</span><span class="lb-emoji-item">😭</span><span class="lb-emoji-item">😡</span>
                <span class="lb-emoji-item">👍</span><span class="lb-emoji-item">👏</span><span class="lb-emoji-item">🙏</span><span class="lb-emoji-item">💪</span>
                <span class="lb-emoji-item">🎉</span><span class="lb-emoji-item">❤️</span><span class="lb-emoji-item">🔥</span><span class="lb-emoji-item">🌙</span>
                <span class="lb-emoji-item">🍚</span><span class="lb-emoji-item">📚</span><span class="lb-emoji-item">⚽</span><span class="lb-emoji-item">🎵</span>
                <span class="lb-emoji-item">🐱</span><span class="lb-emoji-item">🌸</span><span class="lb-emoji-item">✨</span><span class="lb-emoji-item">👀</span>
            </div>
            <textarea class="lb-input" id="lbInput" rows="1" maxlength="1000" placeholder="说点什么…（Enter 发送，Shift+Enter 换行）"></textarea>
            <button type="button" class="lb-send" id="lbSend">发送</button>
        </div>
        <?php else: ?>
        <div class="lb-guest-note">
            游客可以浏览聊天室，登录后才能发言。
            <a href="<?= SITE_URL ?>/pages/login.php">去登录</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="lb-lightbox" id="lbLightbox"><img src="" alt="预览"></div>

<script>
(function () {
    'use strict';

    var msgsEl   = document.getElementById('lbMsgs');
    var emptyEl  = document.getElementById('lbEmpty');
    var inputEl  = document.getElementById('lbInput');
    var sendBtn  = document.getElementById('lbSend');
    var imgBtn   = document.getElementById('lbImgBtn');
    var fileEl   = document.getElementById('lbFile');
    var stateEl  = document.getElementById('lbConnState');
    var quotaEl  = document.getElementById('lbQuota');
    var lightbox = document.getElementById('lbLightbox');

    var lastTs = 0;
    var seen = {};
    var timer = null;
    var polling = false;

    var INTERVAL_ON  = 5000;    // 前台 5 秒
    var INTERVAL_OFF = 30000;   // 后台标签 30 秒

    var me = window.USER_DATA || null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function timeStr(ts) {
        var d = new Date(Number(ts) || 0);
        if (isNaN(d.getTime())) return '';
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        var now = new Date();
        var sameDay = d.toDateString() === now.toDateString();
        return (sameDay ? '' : (d.getMonth() + 1) + '/' + d.getDate() + ' ') + hh + ':' + mm;
    }

    function setState(text, cls) {
        if (!stateEl) return;
        stateEl.textContent = text;
        stateEl.className = 'lb-badge' + (cls ? ' ' + cls : '');
    }

    function setQuota(left) {
        if (!quotaEl || typeof left !== 'number') return;
        quotaEl.textContent = '今日图片剩余 ' + left + ' 张';
    }

    function atBottom() {
        return msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 60;
    }

    function renderMsg(m, beforeNode) {
        if (seen[m.id]) return;
        seen[m.id] = true;

        var mine = me && Number(m.user_id) === Number(me.id);
        var wrap = document.createElement('div');
        wrap.className = 'lb-msg' + (mine ? ' mine' : '');

        var roleTag = (m.role === 'super_admin' || m.role === 'admin')
            ? '<span class="lb-role">管理员</span>' : '';

        var inner = '';
        if (m.kind === 'image' && m.image) {
            inner = '<img src="' + esc(SITE_URL + m.image) + '" alt="图片消息" loading="lazy">';
        } else {
            /* @昵称 高亮：先转义再替换，只匹配紧跟在 @ 后、到空白/标点为止的串 */
            inner = esc(m.text).replace(/@([^\s@,，。!！?？:：;；]{1,20})/g, '<span class="lb-at">@$1</span>');
        }

        wrap.innerHTML =
            '<img class="lb-av" src="' + esc(m.avatar || (SITE_URL + '/assets/images/default-avatar.svg')) + '" alt="" loading="lazy" onerror="this.src=\'' + SITE_URL + '/assets/images/default-avatar.svg\'">' +
            '<div class="lb-body">' +
              '<div class="lb-who"><span class="lb-who-name" data-nick="' + esc(m.nickname || ('用户' + m.user_id)) + '" title="点一下 @TA">' + esc(m.nickname || ('用户' + m.user_id)) + '</span>' + roleTag + '</div>' +
              '<div class="lb-bubble">' + inner + '</div>' +
              '<div class="lb-time">' + timeStr(m.ts) + ((mine || (typeof me !== 'undefined' && me && (me.role === 'admin' || me.role === 'super_admin'))) ? ' <button type="button" class="lb-del-btn" data-del-msg="' + m.id + '" title="删除这条消息">删除</button>' : '') + '</div>' +
            '</div>';

        var img = wrap.querySelector('.lb-bubble img');
        if (img) {
            img.addEventListener('click', function () {
                lightbox.querySelector('img').src = img.src;
                lightbox.classList.add('show');
            });
        }
        if (beforeNode) { msgsEl.insertBefore(wrap, beforeNode); } else { msgsEl.appendChild(wrap); }
    }

    function appendMessages(list) {
        if (!list || !list.length) return;
        var stick = atBottom();
        if (emptyEl && emptyEl.parentNode) emptyEl.remove();

        list.sort(function (a, b) { return (Number(a.ts) || 0) - (Number(b.ts) || 0); });
        list.forEach(function (m) {
            renderMsg(m);
            if (Number(m.ts) > lastTs) lastTs = Number(m.ts);
        });
        if (stick) msgsEl.scrollTop = msgsEl.scrollHeight;
    }

    var moreBtn = document.getElementById('lbMore');
    var oldestTs = 0, hasMoreOld = false;

    function loadEarlier() {
        if (!moreBtn || !oldestTs) return;
        moreBtn.disabled = true;
        var prevH = msgsEl.scrollHeight;
        fetch(SITE_URL + '/api/lobby.php?action=list&before=' + oldestTs + '&limit=60', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var list = (res && res.data && res.data.messages) || [];
                var firstEl = msgsEl.querySelector('.lb-msg');
                list.sort(function (a, b) { return (Number(a.ts) || 0) - (Number(b.ts) || 0); });
                list.forEach(function (m) {
                    renderMsg(m, firstEl || null);
                    if (!oldestTs || Number(m.ts) < oldestTs) oldestTs = Number(m.ts);
                });
                hasMoreOld = !!(res && res.data && res.data.has_more);
                moreBtn.style.display = hasMoreOld ? '' : 'none';
                msgsEl.scrollTop = msgsEl.scrollHeight - prevH + msgsEl.scrollTop;
            })
            .catch(function () {})
            .then(function () { moreBtn.disabled = false; });
    }
    if (moreBtn) moreBtn.addEventListener('click', loadEarlier);

    /* 删除自己的消息（管理员可删任意消息） */
    msgsEl.addEventListener('click', function (e) {
        var b = e.target.closest('.lb-del-btn');
        if (!b) return;
        e.stopPropagation();
        if (!window.confirm('删除这条消息？')) return;
        var id = b.getAttribute('data-del-msg');
        var fd = new URLSearchParams(); fd.set('action', 'delete'); fd.set('id', id); fd.set('csrf_token', window.CSRF_TOKEN || '');
        fetch(SITE_URL + '/api/lobby.php', { method: 'POST', credentials: 'same-origin', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) { window.lwAlert((d && d.message) || '删除失败'); return; }
                var wrap = b.closest('.lb-msg');
                if (wrap) wrap.remove();
            })
            .catch(function () { window.lwAlert('网络错误，删除失败'); });
    });

    /* 点昵称：把「@昵称 」插进输入框（未登录时只提示登录） */
    msgsEl.addEventListener('click', function (e) {
        var el = e.target.closest('.lb-who-name');
        if (!el) return;
        if (!inputEl) return; // 游客没有输入框
        var nick = el.getAttribute('data-nick') || '';
        var mention = '@' + nick + ' ';
        var start = inputEl.selectionStart || inputEl.value.length;
        inputEl.value = inputEl.value.slice(0, start) + mention + inputEl.value.slice(start);
        inputEl.selectionStart = inputEl.selectionEnd = start + mention.length;
        inputEl.focus();
    });

    function fetchList(incremental) {
        if (polling) return Promise.resolve();
        if (navigator.onLine === false) {
            setState('离线', 'offline');
            return Promise.resolve();
        }
        polling = true;
        var url = SITE_URL + '/api/lobby.php?action=list&limit=60' + (incremental && lastTs ? '&since=' + lastTs : '');
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    setState('连接异常', 'offline');
                    return;
                }
                setState('已连接', 'online');
                var batch = res.data.messages || [];
                appendMessages(batch);
                if (!incremental) {
                    oldestTs = batch.length ? Number(batch[0].ts) || 0 : 0;
                    hasMoreOld = !!res.data.has_more;
                    if (moreBtn) moreBtn.style.display = hasMoreOld ? '' : 'none';
                }
                setQuota(res.data.quota_left);
            })
            .catch(function () { setState('网络异常', 'offline'); })
            .then(function () { polling = false; });
    }

    function schedule(delay) {
        if (timer) { clearTimeout(timer); timer = null; }
        timer = setTimeout(function loop() {
            if (navigator.onLine === false) { setState('离线', 'offline'); schedule(INTERVAL_OFF); return; }
            fetchList(true).then(function () {
                schedule(document.hidden ? INTERVAL_OFF : INTERVAL_ON);
            });
        }, delay);
    }

    function send(text) {
        text = (text || '').trim();
        if (!text) return;
        sendBtn.disabled = true;
        var body = new URLSearchParams();
        body.append('csrf_token', window.CSRF_TOKEN || '');
        body.append('action', 'send');
        body.append('text', text);
        fetch(SITE_URL + '/api/lobby.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) { return r.json(); })
          .then(function (res) {
              sendBtn.disabled = false;
              if (!res.success) { window.lwAlert(res.message || '发送失败'); inputEl.value = text; return; }
              inputEl.value = '';
              inputEl.style.height = 'auto';
              inputEl.focus();
              fetchList(true);
          })
          .catch(function () { sendBtn.disabled = false; window.lwAlert('网络错误，发送失败'); inputEl.value = text; });
    }

    function upload(file) {
        if (!file) return;
        imgBtn.disabled = true;
        var fd = new FormData();
        fd.append('csrf_token', window.CSRF_TOKEN || '');
        fd.append('action', 'upload');
        fd.append('file', file);
        fetch(SITE_URL + '/api/lobby.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function (r) { return r.json(); })
          .then(function (res) {
              imgBtn.disabled = false;
              if (!res.success) { window.lwAlert(res.message || '上传失败'); return; }
              setQuota(res.data.quota_left);
              fetchList(true);
          })
          .catch(function () { imgBtn.disabled = false; window.lwAlert('网络错误，上传失败'); });
    }

    if (inputEl && sendBtn) {
        var emojiBtn = document.getElementById('lbEmojiBtn');
    var emojiPanel = document.getElementById('lbEmojiPanel');
    var emojiRecent = document.getElementById('lbEmojiRecent');
    var EMOJI_KEY = 'lw_chat_emoji_recent';
    function readRecentEmoji() {
        try { var a = JSON.parse(localStorage.getItem(EMOJI_KEY) || '[]'); return Array.isArray(a) ? a.slice(0, 8) : []; } catch (e) { return []; }
    }
    function renderRecentEmoji() {
        if (!emojiRecent) return;
        var list = readRecentEmoji();
        if (!list.length) { emojiRecent.hidden = true; emojiRecent.innerHTML = ''; return; }
        emojiRecent.hidden = false;
        emojiRecent.innerHTML = list.map(function (e) { return '<span class="lb-emoji-item">' + e + '</span>'; }).join('');
    }
    function rememberEmoji(ch) {
        var list = readRecentEmoji().filter(function (x) { return x !== ch; });
        list.unshift(ch);
        try { localStorage.setItem(EMOJI_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) {}
        renderRecentEmoji();
    }
    renderRecentEmoji();

    if (emojiBtn && emojiPanel) {
        emojiBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            emojiPanel.hidden = !emojiPanel.hidden;
        });
        emojiPanel.addEventListener('click', function (e) {
            var it = e.target.closest('.lb-emoji-item');
            if (!it) return;
            var ch = it.textContent;
            rememberEmoji(ch);
            var start = inputEl.selectionStart || inputEl.value.length;
            var end = inputEl.selectionEnd || inputEl.value.length;
            inputEl.value = inputEl.value.slice(0, start) + ch + inputEl.value.slice(end);
            inputEl.selectionStart = inputEl.selectionEnd = start + ch.length;
            inputEl.focus();
            emojiPanel.hidden = true;
        });
        document.addEventListener('click', function (e) {
            if (!emojiPanel.hidden && !e.target.closest('#lbEmojiPanel') && !e.target.closest('#lbEmojiBtn')) emojiPanel.hidden = true;
        });
    }

    sendBtn.addEventListener('click', function () { send(inputEl.value); });

        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send(inputEl.value);
            }
        });

        inputEl.addEventListener('input', function () {
            inputEl.style.height = 'auto';
            inputEl.style.height = Math.min(inputEl.scrollHeight, 112) + 'px';
        });
    }

    if (imgBtn && fileEl) {
        imgBtn.addEventListener('click', function () { fileEl.click(); });
        fileEl.addEventListener('change', function () {
            if (fileEl.files && fileEl.files[0]) {
                upload(fileEl.files[0]);
                fileEl.value = '';
            }
        });
    }

    if (lightbox) {
        lightbox.addEventListener('click', function () { lightbox.classList.remove('show'); });
    }

    window.addEventListener('online', function () { setState('已连接', 'online'); schedule(0); });
    window.addEventListener('offline', function () { setState('离线', 'offline'); });
    document.addEventListener('visibilitychange', function () {
        schedule(document.hidden ? INTERVAL_OFF : 0);
    });

    // 首屏：先拉一次全量，再进入增量轮询
    fetchList(false).then(function () { schedule(INTERVAL_ON); });
})();
</script>

<?php require __DIR__ . '/../includes/mobile_bottom_nav.php'; ?>
<?php if (is_file(__DIR__ . '/../includes/ai_widget.php')) require __DIR__ . '/../includes/ai_widget.php'; ?>
</body>
</html>
