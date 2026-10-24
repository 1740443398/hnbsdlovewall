<?php
/**
 * 文件共享（需求一、6：支持上传任意非可读类文件资源）
 *
 * 「非可读类」= 浏览器不能直接渲染的文件：课件、压缩包、设计稿、音视频源文件等。
 * 安全边界见 includes/attachments.php；下载统一走 pages/file_download.php 带鉴权。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/attachments.php';

$user = requireMember('文件共享需要注册账号后才能使用');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    header('Location: ' . SITE_URL . '/');
    exit();
}

$csrfToken = generateCSRFToken();
$uid = (int)$user['id'];
// 页面打开时顺手清扫一次：用户看到的列表里不会再有已经过期的文件
lwAttachPurgeExpired();
$quotaLeft = lwAttachQuotaLeft($uid);
$exts = array_keys(lwAttachExtMap());

$pageTitle = '文件共享 - ' . SITE_NAME;
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
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = true;
        const USER_DATA = <?= json_encode(['id' => $uid, 'qq' => $user['qq'], 'nickname' => $user['nickname'], 'avatar' => $user['avatar'] ?? '', 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
        window.IS_LOGGED_IN = true;
        window.USER_DATA = USER_DATA;
    </script>
    <style>
        .fs-wrap{max-width:900px;margin:0 auto;padding:1.25rem 1rem 3rem;}
        .fs-hero{padding:1.125rem 1.25rem;border-radius:1rem;background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);box-shadow:var(--shadow-sm);margin-bottom:1.125rem;}
        .fs-hero h1{font-size:1.25rem;font-weight:800;color:var(--text);margin:0 0 .375rem;display:flex;align-items:center;gap:.5rem;}
        .fs-hero p{font-size:.8rem;color:var(--text-secondary);margin:0;line-height:1.7;}
        .fs-drop{
            display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;
            padding:1.75rem 1rem;margin-bottom:1rem;
            border:2px dashed var(--border,#E3E7EC);border-radius:1rem;
            background:var(--bg-secondary,#F4F7FA);color:var(--text-secondary);
            font-size:.85rem;cursor:pointer;transition:border-color .18s,background .18s;
        }
        .fs-drop:hover,.fs-drop.dragover{border-color:var(--primary);background:var(--primary-light,#E7EEF7);color:var(--primary);}
        .fs-list{display:flex;flex-direction:column;gap:.625rem;}
        .fs-item{display:flex;align-items:center;gap:.75rem;padding:.75rem .875rem;border:1px solid var(--border-light,#E4E9F0);border-radius:.75rem;background:var(--surface,#fff);}
        .fs-ico{width:2.25rem;height:2.25rem;flex:0 0 auto;display:flex;align-items:center;justify-content:center;border-radius:.5rem;background:var(--primary-light,#E7EEF7);color:var(--primary);font-size:.6875rem;font-weight:800;text-transform:uppercase;}
        .fs-main{flex:1 1 auto;min-width:0;}
        .fs-name{font-size:.875rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .fs-meta{font-size:.72rem;color:var(--text-secondary);margin-top:.125rem;display:flex;gap:.625rem;flex-wrap:wrap;}
        .fs-empty{padding:2rem;text-align:center;color:var(--text-secondary);font-size:.85rem;border:1px dashed var(--border-light,#E4E9F0);border-radius:.875rem;}
        .fs-btn{padding:.375rem .75rem;border-radius:.5rem;font-size:.75rem;font-weight:600;text-decoration:none;border:1px solid var(--primary);color:var(--primary);background:transparent;cursor:pointer;font-family:inherit;transition:all .18s;}
        .fs-btn:hover{background:var(--primary);color:#fff;}
        .fs-btn.danger{border-color:#cb2431;color:#cb2431;}
        .fs-btn.danger:hover{background:#cb2431;color:#fff;}
    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/site_header.php'; ?>

<div class="fs-wrap">
    <div class="fs-hero">
        <h1>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
            文件共享
        </h1>
        <p>
            这是一个<strong>临时中转站</strong>：文档、压缩包、设计稿、音视频等非可读类文件都可以传（单文件最大
            <?= round(LW_FILE_MAX_SIZE / 1048576) ?>MB）。<strong>文件只保留 1 小时</strong>，
            到点自动从服务器删除；每人每天最多上传
            <strong><?= round(LW_FILE_DAILY_BYTES / 1048576) ?>MB</strong>，今日还剩
            <strong id="fsQuota">--</strong>。脚本与可执行文件不允许上传。
        </p>
    </div>

    <div class="fs-drop" id="fsDrop">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span id="fsDropText">点击选择文件，或把文件拖到这里</span>
        <span style="font-size:.72rem;opacity:.8;">支持：<?= htmlspecialchars(implode(' / ', array_slice($exts, 0, 14))) ?> 等</span>
    </div>
    <input type="file" id="fsFile" hidden>

    <div class="fs-list" id="fsList"><div class="fs-empty">加载中…</div></div>
</div>

<?php require __DIR__ . '/../includes/mobile_bottom_nav.php'; ?>
<script>
(function () {
    'use strict';
    var csrf = <?= json_encode($csrfToken) ?>;
    var drop = document.getElementById('fsDrop');
    var fileEl = document.getElementById('fsFile');
    var listEl = document.getElementById('fsList');
    var quotaEl = document.getElementById('fsQuota');
    var dropText = document.getElementById('fsDropText');

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function toast(m, t) {
        if (window.App && App.showToast) App.showToast(m, t || 'info'); else alert(m);
    }
    function humanSize(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    }

    function load() {
        fetch(SITE_URL + '/api/attachment.php?action=mine', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store'
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) { listEl.innerHTML = '<div class="fs-empty">加载失败</div>'; return; }
            quotaEl.textContent = humanSize(res.data.quota_left) + ' 可用';
            var files = res.data.files || [];
            if (!files.length) {
                listEl.innerHTML = '<div class="fs-empty">你还没有上传过文件</div>';
                return;
            }
            // 剩余时间：服务器只保留 1 小时，列表里必须让人一眼看到「还有多久删」
            function leftText(sec) {
                sec = Number(sec) || 0;
                if (sec <= 0) return '已过期';
                var m = Math.floor(sec / 60), s = sec % 60;
                return m > 0 ? (m + ' 分 ' + s + ' 秒后删除') : (s + ' 秒后删除');
            }
            listEl.innerHTML = files.map(function (f) {
                var d = new Date((f.ts || 0) * 1000);
                var when = isNaN(d.getTime()) ? '' : (d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0') + ' ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0'));
                var urgent = (Number(f.expires_in) || 0) < 600;
                return '<div class="fs-item" data-exp="' + (Number(f.expires_in) || 0) + '">' +
                    '<span class="fs-ico">' + esc(f.ext) + '</span>' +
                    '<div class="fs-main">' +
                      '<div class="fs-name" title="' + esc(f.name) + '">' + esc(f.name) + '</div>' +
                      '<div class="fs-meta"><span>' + humanSize(f.size) + '</span><span>' + when + '</span><span>' + f.downloads + ' 次下载</span>' +
                        '<span class="fs-ttl" style="color:' + (urgent ? '#cb2431' : 'var(--text-secondary)') + ';font-weight:600;">' + leftText(f.expires_in) + '</span>' +
                      '</div>' +
                    '</div>' +
                    '<a class="fs-btn" href="' + esc(f.url) + '">下载</a>' +
                    '<button type="button" class="fs-btn danger" data-del="' + f.id + '">删除</button>' +
                    '</div>';
            }).join('');

            // 本地倒计时（不额外发请求）。归零后就地提示，并顺手刷新一次列表。
            if (window.__fsTick) clearInterval(window.__fsTick);
            window.__fsTick = setInterval(function () {
                var done = false;
                listEl.querySelectorAll('.fs-item[data-exp]').forEach(function (it) {
                    var left = parseInt(it.getAttribute('data-exp'), 10) - 1;
                    if (left <= 0) { done = true; return; }
                    it.setAttribute('data-exp', left);
                    var el = it.querySelector('.fs-ttl');
                    if (el) el.textContent = leftText(left);
                });
                if (done) {
                    clearInterval(window.__fsTick);
                    load();
                }
            }, 1000);

            listEl.querySelectorAll('[data-del]').forEach(function (b) {
                b.addEventListener('click', function () {
                    if (!confirm('确定删除这个文件吗？')) return;
                    var body = new URLSearchParams();
                    body.append('csrf_token', csrf);
                    body.append('action', 'del');
                    body.append('id', b.dataset.del);
                    fetch(SITE_URL + '/api/attachment.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                        body: body
                    }).then(function (r) { return r.json(); }).then(function (d) {
                        if (!d.success) { toast(d.message || '删除失败', 'error'); return; }
                        toast('已删除', 'success');
                        load();
                    });
                });
            });
        }).catch(function () { listEl.innerHTML = '<div class="fs-empty">加载失败</div>'; });
    }

    function upload(file) {
        if (!file) return;
        dropText.textContent = '正在上传：' + file.name;
        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'upload');
        fd.append('file', file);
        fetch(SITE_URL + '/api/attachment.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function (r) { return r.json(); }).then(function (res) {
            dropText.textContent = '点击选择文件，或把文件拖到这里';
            if (!res.success) { toast(res.message || '上传失败', 'error'); return; }
            toast('上传成功', 'success');
            load();
        }).catch(function () {
            dropText.textContent = '点击选择文件，或把文件拖到这里';
            toast('网络错误，上传失败', 'error');
        });
    }

    drop.addEventListener('click', function () { fileEl.click(); });
    fileEl.addEventListener('change', function () {
        if (fileEl.files && fileEl.files[0]) { upload(fileEl.files[0]); fileEl.value = ''; }
    });
    ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('dragover'); });
    });
    drop.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
            upload(e.dataTransfer.files[0]);
        }
    });

    load();
})();
</script>
</body>
</html>
