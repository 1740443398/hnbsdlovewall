<?php
/**
 * 待审核帖子 —— 独立页面
 *
 * 站长反馈：「后台给未审核帖子单独开一个页面，不然太麻烦了」
 * 原来待审核混在「帖子管理」里，要先点筛选、还要翻页找，处理几条要来回折腾。
 * 这里把 pending 单独成页：一屏看完、逐条通过/拒绝、支持一键全部通过。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_posts')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}
$hasAuditPosts = checkPermission($adminUser, 'audit_posts');

$fs = getFS();
$posts = $fs->read('posts') ?: [];
$pending = array_values(array_filter($posts, function ($p) {
    return ($p['status'] ?? '') === 'pending';
}));
usort($pending, function ($a, $b) {
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});

/* 取作者信息做展示 */
$userById = [];
foreach (($fs->read('users') ?: []) as $u) {
    $userById[(int) ($u['id'] ?? 0)] = $u;
}

adminHeader('待审核', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header">
        <h3>待审核（<?= count($pending) ?>）</h3>
        <div>
            <?php if ($hasAuditPosts && $pending): ?>
            <button class="btn btn-success btn-sm" onclick="approveAll()">全部通过</button>
            <?php endif; ?>
            <a class="btn btn-secondary btn-sm" href="/admin/posts.php?status=pending">在帖子管理中查看</a>
        </div>
    </div>

    <?php if (!$pending): ?>
    <div style="padding:48px 16px;text-align:center;color:var(--text-muted)">
        <div style="font-size:34px;margin-bottom:10px">🎉</div>
        <div>没有待审核帖子，辛苦了</div>
    </div>
    <?php else: ?>
    <div class="pending-list">
        <?php foreach ($pending as $p):
            $uid = (int) ($p['user_id'] ?? 0);
            $author = $userById[$uid] ?? [];
            $nick = (string) ($author['nickname'] ?? ('用户' . $uid));
            $avatar = (string) ($author['avatar'] ?? '/assets/images/default-avatar.svg');
            $imgs = json_decode((string) ($p['images'] ?? '[]'), true) ?: [];
            $isQz = ($p['source'] ?? '') === 'qzone';
            $reason = (string) ($p['moderation_reason'] ?? ($p['ai_reason'] ?? ''));
        ?>
        <div class="pending-item" id="pending-<?= (int) $p['id'] ?>">
            <div class="pending-thumb">
                <?php if (!empty($imgs[0])): ?>
                <img src="<?= htmlspecialchars((string) $imgs[0]) ?>" alt="" loading="lazy"
                     onclick="openZoom(this.src)" style="cursor:zoom-in">
                <?php else: ?>
                <div class="pending-thumb-empty">无图</div>
                <?php endif; ?>
            </div>
            <div class="pending-main">
                <div class="pending-title">
                    <?php if ($isQz): ?><span class="badge badge-info">QQ同步</span><?php endif; ?>
                    <?= htmlspecialchars(mb_substr((string) ($p['title'] ?? ''), 0, 40) ?: '（无标题）') ?>
                </div>
                <div class="pending-meta">
                    <img class="pending-avatar" src="<?= htmlspecialchars($avatar) ?>" alt="">
                    <span><?= htmlspecialchars($nick) ?></span>
                    <span>·</span>
                    <span><?= htmlspecialchars((string) ($p['created_at'] ?? '')) ?></span>
                    <?php if ($reason !== ''): ?>
                    <span class="pending-reason">命中：<?= htmlspecialchars(mb_substr($reason, 0, 30)) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($p['content'])): ?>
                <div class="pending-content"><?= htmlspecialchars(mb_substr(strip_tags((string) $p['content']), 0, 120)) ?></div>
                <?php endif; ?>
            </div>
            <div class="pending-actions">
                <?php if ($hasAuditPosts): ?>
                <button class="btn btn-success btn-sm" onclick="doAction('approve', <?= (int) $p['id'] ?>, this)">通过</button>
                <button class="btn btn-danger btn-sm" onclick="doAction('reject', <?= (int) $p['id'] ?>, this)">拒绝</button>
                <?php endif; ?>
                <button class="btn btn-outline btn-sm" onclick="previewPostReal(<?= (int) $p['id'] ?>)">预览</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.pending-list { display: flex; flex-direction: column; gap: 10px; }
.pending-item {
    display: flex; gap: 12px; align-items: flex-start;
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: 14px; padding: 12px;
}
.pending-thumb { width: 72px; height: 72px; flex: 0 0 72px; border-radius: 10px; overflow: hidden; background: var(--bg-secondary); }
.pending-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pending-thumb-empty { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 12px; color: var(--text-muted); }
.pending-main { flex: 1 1 auto; min-width: 0; }
.pending-title { font-weight: 600; font-size: 15px; margin-bottom: 4px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.pending-meta { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); flex-wrap: wrap; }
.pending-avatar { width: 18px; height: 18px; border-radius: 50%; object-fit: cover; }
.pending-reason { color: var(--danger); }
.pending-content { margin-top: 4px; font-size: 13px; color: var(--text-secondary); line-height: 1.5; }
.pending-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.badge { font-size: 11px; padding: 1px 6px; border-radius: 6px; }
.badge-info { background: var(--primary-light); color: var(--primary); }
@media (max-width: 640px) {
    .pending-item { flex-wrap: wrap; }
    .pending-actions { width: 100%; justify-content: flex-start; }
}
</style>

<div class="lw-pv-mask" id="pvMask">
    <div class="lw-pv">
        <button class="lw-pv-close" id="pvClose" aria-label="关闭">✕</button>
        <div class="lw-pv-head">
            <div class="lw-pv-title" id="pvTitle"></div>
            <div class="lw-pv-meta" id="pvMeta"></div>
        </div>
        <div class="lw-pv-body" id="pvBody"></div>
    </div>
</div>

<style>
.lw-pv-mask { position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,.5); display: none; align-items: center; justify-content: center; padding: 16px; }
.lw-pv-mask.is-open { display: flex; }
.lw-pv { position: relative; width: min(680px, 100%); max-height: 88vh; overflow: auto; background: var(--card-bg, #fff); border-radius: 16px; padding: 18px; box-shadow: 0 24px 60px rgba(0,0,0,.28); }
.lw-pv-close { position: absolute; top: 10px; right: 10px; width: 34px; height: 34px; border-radius: 50%; border: none; background: var(--input-bg, #f1f5f9); color: var(--text, #111); font-size: 16px; cursor: pointer; }
.lw-pv-title { font-size: 17px; font-weight: 700; padding-right: 40px; margin-bottom: 6px; }
.lw-pv-meta { font-size: 12px; color: var(--text-muted, #64748b); margin-bottom: 12px; }
.lw-pv-body { font-size: 14px; line-height: 1.6; color: var(--text, #111); }
.lw-pv-body img { max-width: 100%; border-radius: 10px; margin: 6px 0; display: block; cursor: zoom-in; }
.lw-pv-cmt { font-size: 13px; padding: 8px 0; border-top: 1px solid var(--border, #e2e8f0); }
.lw-pv-cmt b { font-weight: 600; }
</style>

<script>
var CSRF = <?= json_encode($csrfToken) ?>;

function postApi(payload, done) {
    var body = 'csrf_token=' + encodeURIComponent(CSRF);
    for (var k in payload) { body += '&' + k + '=' + encodeURIComponent(payload[k]); }
    fetch(SITE_URL + '/api/admin/posts.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function (r) { return r.json(); }).then(function (j) {
        done(j && (j.success === true || j.ok === true));
    }).catch(function () { done(false); });
}

function doAction(action, id, btn) {
    if (btn) { btn.disabled = true; btn.textContent = '处理中'; }
    postApi({ action: action, post_id: id }, function (ok) {
        if (ok) {
            var el = document.getElementById('pending-' + id);
            if (el) { el.style.opacity = '.3'; setTimeout(function () { el.remove(); }, 200); }
        } else if (btn) {
            btn.disabled = false; btn.textContent = action === 'approve' ? '通过' : '拒绝';
            alert('操作失败，请刷新重试');
        }
    });
}

function previewPostReal(id) {
    var body = 'csrf_token=' + encodeURIComponent(CSRF) + '&action=detail&post_id=' + encodeURIComponent(id);
    fetch(SITE_URL + '/api/admin/posts.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j || !j.success) { alert((j && (j.error || j.message)) || '加载失败'); return; }
        var d = j.data || j;
        document.getElementById('pvTitle').textContent = d.title || '（无标题）';
        document.getElementById('pvMeta').textContent =
            (d.nickname || '') + (d.qq ? '（' + d.qq + '）' : '') + ' · ' + (d.created_at || '') +
            ' · 状态：' + (d.status || '') + ' · 评论 ' + ((d.comments || []).length);
        var html = '';
        if (d.content) { html += '<div>' + escHtml(d.content) + '</div>'; }
        (d.images || []).forEach(function (u) {
            html += '<img src="' + escHtml(u) + '" alt="" onclick="zoomPreview(this)">';
        });
        (d.comments || []).forEach(function (c) {
            html += '<div class="lw-pv-cmt"><b>' + escHtml(c.nickname || (c.is_anonymous ? '匿名' : '')) + '</b>　' +
                    escHtml(c.content || '') + '<div style="font-size:11px;opacity:.6">' + escHtml(c.created_at || '') + '</div></div>';
        });
        document.getElementById('pvBody').innerHTML = html || '<div style="opacity:.6">（无内容）</div>';
        document.getElementById('pvMask').classList.add('is-open');
    }).catch(function () { alert('加载失败'); });
}

function zoomPreview(img) {
    if (window.openZoom) { window.openZoom(img.src); return; }
    window.open(img.src, '_self');
}

function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

document.getElementById('pvClose').addEventListener('click', function () {
    document.getElementById('pvMask').classList.remove('is-open');
});
document.getElementById('pvMask').addEventListener('click', function (e) {
    if (e.target === this) { this.classList.remove('is-open'); }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { document.getElementById('pvMask').classList.remove('is-open'); }
});

function approveAll() {
    if (!confirm('确定把所有待审核帖子都通过吗？')) return;
    var ids = [];
    document.querySelectorAll('[id^="pending-"]').forEach(function (el) {
        var m = el.id.match(/pending-(\d+)/);
        if (m) ids.push(m[1]);
    });
    if (!ids.length) return;
    postApi({ action: 'batch', post_ids: JSON.stringify(ids.map(Number)), batch_action: 'approve' }, function (ok) {
        if (ok) { location.reload(); } else { alert('批量通过失败，请稍后重试'); }
    });
}
</script>

<?php adminFooter(); ?>
