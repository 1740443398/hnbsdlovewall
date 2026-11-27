<?php
/**
 * 空间动态（QQ 空间镜像）
 * ---------------------------------------------------------------------------
 * 展示由后台「QQ 空间转发」同步过来的说说。
 *
 * 设计上有三点是和数据形态绑定的，不是随便排的：
 *   1) **以图为主**：实测对方 bot 的说说 18/19 条是纯图片（content 为空），
 *      所以卡片主体是图，正文有就显示、没有就不留空行。
 *   2) 图片必须走 /api/qzone/image.php：QQ 图片有防盗链，
 *      带本站 Referer 直连拿到的是 3.7KB 的占位图（真图 48KB）。
 *      代理层会本地缓存，第二次就完全不出网。
 *   3) 点赞/评论是**只读镜像**：站内不能点，也不能回复 —— 这里不显示任何交互按钮，
 *      避免用户以为能互动。要互动请去原空间。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/site_footer.php';
require_once __DIR__ . '/../includes/qzone_sync.php';

$maintenanceMode = getSetting('maintenance_mode', '0') == '1';
if ($maintenanceMode) {
    $u = getCurrentUser();
    if (!$u || !in_array($u['role'], ['admin', 'super_admin'])) {
        require_once __DIR__ . '/maintenance.php';
        exit();
    }
}

$user = requireLoginOrGuest();
if ($user) { $user = checkBanned($user); }
$isGuest = ($user === null);
$siteName = getSetting('site_name', SITE_NAME);
$announcement = trim((string) getSetting('announcement', ''));

$cfg = lwQzoneConfig();
$uin = preg_replace('/\D/', '', (string) $cfg['uin']);

$perPage = 10;
$pageNo  = max(1, (int) ($_GET['p'] ?? 1));
$offset  = ($pageNo - 1) * $perPage;
$q       = trim((string) ($_GET['q'] ?? ''));
$kind    = (string) ($_GET['kind'] ?? '');
if (!in_array($kind, ['image', 'text'], true)) { $kind = ''; }
if ($q !== '' || $kind !== '') {
    /* 搜索：在本地镜像数据里过滤正文与评论，再分页（数据量小，无需索引） */
    $fsAll = getFS()->read('qzone_items');
    $__all = is_array($fsAll) ? $fsAll : [];
    usort($__all, function ($a, $b) { return (int) ($b['posted_ts'] ?? 0) <=> (int) ($a['posted_ts'] ?? 0); });
    $__hit = array_values(array_filter($__all, function ($it) use ($q, $kind) {
        if ($kind === 'image' && empty($it['images_arr'])) { return false; }
        if ($kind === 'text' && !empty($it['images_arr'])) { return false; }
        if ($q === '') { return true; }
        if (mb_stripos((string) ($it['content'] ?? ''), $q) !== false) { return true; }
        foreach ((array) ($it['comments_arr'] ?? []) as $c) {
            if (mb_stripos((string) ($c['content'] ?? ''), $q) !== false) { return true; }
        }
        return false;
    }));
    $total   = count($__hit);
    $items   = array_slice($__hit, $offset, $perPage);
    $hasNext = ($offset + $perPage) < $total;
} else {
    $items   = lwQzoneListItems($perPage + 1, $offset);
    $hasNext = count($items) > $perPage;
    if ($hasNext) { $items = array_slice($items, 0, $perPage); }
    $fsAll = getFS()->read('qzone_items');
    $total = is_array($fsAll) ? count($fsAll) : 0;
}
$showLikes = (int) $cfg['sync_likes'] === 1;
$showComments = (int) $cfg['sync_comments'] === 1;

function qzTime(int $ts): string
{
    if ($ts <= 0) { return ''; }
    $y = date('Y', $ts);
    return ($y === date('Y') ? date('m月d日 H:i', $ts) : date('Y年m月d日', $ts));
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG_CODE) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title>空间动态 - <?= htmlspecialchars($siteName) ?></title>
    <?php require __DIR__ . '/../includes/seo_meta.php'; ?>
    <style>
        .qz-main { padding: var(--space-lg) 0 var(--space-xl); }
        .qz-wrap { max-width: 720px; margin: 0 auto; padding: 0 var(--space-md); }
        .qz-head { margin-bottom: var(--space-lg); }
        .qz-head h1 { font-size: 1.5rem; margin: 0 0 6px; }
        .qz-head p { margin: 0; font-size: 0.86rem; color: var(--text-muted); }
        .qz-head a { color: var(--primary); text-decoration: none; }
        .qz-search { display: flex; gap: 8px; margin-top: 12px; }
        .qz-search input { flex: 1 1 auto; padding: 9px 12px; border: 1px solid var(--border-glass); border-radius: 10px; background: var(--card-bg); color: var(--text); font-size: .88rem; }
        .qz-search button { padding: 9px 16px; border-radius: 10px; border: none; background: var(--primary); color: #fff; font-size: .88rem; cursor: pointer; }

        .qz-card {
            background: var(--card-bg);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-card);
            margin-bottom: var(--space-lg);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .qz-meta {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 14px; font-size: 0.78rem; color: var(--text-muted);
        }
        .qz-meta .qz-src { color: var(--text-secondary); font-weight: 600; }
        .qz-meta .qz-stats { margin-left: auto; display: inline-flex; align-items: center; gap: 10px; }
        .qz-meta .qz-stat { display: inline-flex; align-items: center; gap: 3px; color: var(--text-secondary); }
        .qz-meta .qz-time { margin-left: 10px; }
        .qz-body { padding: 0 14px 12px; }
        .qz-text { font-size: 0.95rem; line-height: 1.75; margin: 0 0 10px; white-space: pre-wrap; word-break: break-word; }
        .qz-media { display: block; }
        .qz-media img {
            display: block; width: 100%; height: auto;
            background: var(--bg-secondary);
            max-height: 80vh; object-fit: contain;
        }
        .qz-foot {
            display: flex; align-items: center; gap: 16px;
            padding: 10px 14px; border-top: 1px solid var(--border-glass);
            font-size: 0.82rem; color: var(--text-secondary);
        }
        .qz-foot .qz-stat { display: inline-flex; align-items: center; gap: 5px; }
        .qz-foot svg { opacity: .75; }

        .qz-comments { border-top: 1px solid var(--border-glass); padding: 10px 14px 12px; background: var(--bg-secondary); }
        .qz-c { font-size: 0.86rem; line-height: 1.65; margin-bottom: 8px; color: var(--text); }
        .qz-c:last-child { margin-bottom: 0; }
        .qz-c b { color: var(--primary); font-weight: 600; }
        .qz-more-c { font-size: 0.8rem; color: var(--text-muted); }

        .qz-empty {
            text-align: center; padding: 64px 20px; color: var(--text-muted);
            background: var(--card-bg); border: 1px dashed var(--border-glass);
            border-radius: var(--radius-card);
        }
        .qz-pager { display: flex; gap: 10px; justify-content: center; margin-top: var(--space-lg); }
        .qz-pager a, .qz-pager span {
            padding: 8px 16px; border-radius: 999px; font-size: 0.86rem;
            border: 1px solid var(--border-glass); text-decoration: none; color: var(--text-secondary);
        }
        .qz-pager .qz-off { opacity: .4; }
    </style>
</head>
<body>
<?php
$headerGuestCta = 'login';
$pageTitle = '空间动态';
require __DIR__ . '/../includes/site_header.php';
?>
<?php require __DIR__ . '/../includes/ai_widget.php'; ?>

<?php if ($isGuest): ?>
<div class="guest-bar">
    <div class="container">
        <span class="guest-bar-text"><?= t('guest.banner') ?></span>
        <a href="<?= SITE_URL ?>/pages/register.php" class="guest-bar-btn"><?= t('guest.register_cta') ?></a>
    </div>
</div>
<?php endif; ?>

<?php if ($announcement): ?>
<div class="announcement-bar">
    <div class="container">
        <div class="announcement-scroll"><span><?= htmlspecialchars($announcement) ?></span></div>
    </div>
</div>
<?php endif; ?>

<main id="main-content" class="qz-main">
    <div class="qz-wrap">
        <div class="qz-head">
            <div class="qz-chips" style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
                <?php
                $__kinds = ['' => '全部', 'image' => '有图', 'text' => '纯文字'];
                foreach ($__kinds as $__k => $__label): ?>
                <a href="?kind=<?= $__k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                   style="padding:5px 12px;border-radius:999px;font-size:.8rem;text-decoration:none;border:1px solid var(--border-glass);<?= $kind === $__k ? 'background:var(--primary);color:#fff;border-color:var(--primary)' : 'color:var(--text-secondary)' ?>"><?= $__label ?></a>
                <?php endforeach; ?>
            </div>
            <form class="qz-search" method="get">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="搜索说说内容或评论…">
                <?php if ($kind !== ''): ?><input type="hidden" name="kind" value="<?= htmlspecialchars($kind) ?>"><?php endif; ?>
                <button type="submit">搜索</button>
                <?php if ($q !== ''): ?><a href="/pages/qzone.php" style="align-self:center;font-size:.82rem">清除</a><?php endif; ?>
            </form>
            <h1>空间动态</h1>
            <p>
                同步自 QQ 空间<?php if ($uin !== ''): ?>（<a href="https://user.qzone.qq.com/<?= $uin ?>" target="_blank" rel="noopener nofollow"><?= $uin ?></a>）<?php endif; ?>，
                共 <?= $total ?> 条。
                点赞与评论为只读镜像 —— 想互动请到原空间。
            </p>
        </div>

        <?php if (!$items): ?>
        <div class="qz-empty">
            <p><?= ($q !== '' || $kind !== '') ? '没有符合筛选条件的说说。' : '还没有同步到内容。' ?></p>
            <?php if ($q !== ''): ?><p style="font-size:.82rem;"><a href="/pages/qzone.php">清除搜索</a></p><?php endif; ?>
            <p style="font-size:.82rem;">管理员在后台「QQ 空间转发」里配置 Cookie 后即可同步。</p>
        </div>
        <?php else: ?>
            <?php foreach ($items as $it):
                $tid = (string) $it['tid'];
                $imgs = $it['images_arr'] ?? [];
                $comments = $it['comments_arr'] ?? [];
                $text = trim((string) $it['content']);
                $likeN = (int) ($it['likes'] ?? 0);
                $cmtN  = (int) ($it['comment_num'] ?? 0);
            ?>
            <article class="qz-card">
                <div class="qz-meta">
                    <span class="qz-src">QQ 空间</span>
                    <span class="qz-stats">
                        <?php if ($showLikes): ?>
                        <span class="qz-stat"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-7-4.4-9.3-8.4C.6 8.9 2.2 5 5.8 5c2 0 3.4 1.1 4.2 2.3C10.8 6.1 12.2 5 14.2 5c3.6 0 5.2 3.9 3.1 7.6C19 16.6 12 21 12 21z"/></svg><?= $likeN ?></span>
                        <?php endif; ?>
                        <span class="qz-stat"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><?= $cmtN ?></span>
                    </span>
                    <span class="qz-time"><?= qzTime((int) $it['posted_ts']) ?></span>
                </div>
                <?php if ($imgs): ?>
                <a class="qz-media" href="<?= SITE_URL ?>/api/qzone/image.php?tid=<?= urlencode($tid) ?>&i=0" target="_blank" rel="noopener">
                    <img src="<?= SITE_URL ?>/api/qzone/image.php?tid=<?= urlencode($tid) ?>&i=0"
                         alt="" loading="lazy" decoding="async" width="892" height="1200">
                </a>
                <?php endif; ?>
                <div class="qz-body">
                    <?php if ($text !== ''): ?><p class="qz-text"><?= nl2br(htmlspecialchars($text)) ?></p><?php endif; ?>
                    <?php if (!$imgs && $text === ''): ?><p class="qz-text" style="color:var(--text-muted);">（这条说说没有可展示的内容）</p><?php endif; ?>
                </div>

                <?php if ($showComments && $comments): ?>
                <div class="qz-comments">
                    <?php foreach (array_slice($comments, 0, 8) as $c): ?>
                    <div class="qz-c">
                        <b><?= htmlspecialchars((string) $c['author']) ?></b>：
                        <?= htmlspecialchars((string) $c['content']) ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($comments) > 8): ?>
                    <div class="qz-more-c">还有 <?= count($comments) - 8 ?> 条评论，去原空间看全部</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($total > $perPage): ?>
        <div class="qz-pager">
            <?php if ($pageNo > 1): ?>
            <a href="?p=<?= $pageNo - 1 ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $kind !== '' ? '&kind=' . urlencode($kind) : '' ?>">上一页</a>
            <?php else: ?><span class="qz-off">上一页</span><?php endif; ?>
            <span><?= $pageNo ?></span>
            <?php if ($hasNext): ?>
            <a href="?p=<?= $pageNo + 1 ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $kind !== '' ? '&kind=' . urlencode($kind) : '' ?>">下一页</a>
            <?php else: ?><span class="qz-off">下一页</span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
<script>
/* 页面渲染完再异步触发同步检查 —— 同步要出网十几次，
   绝不能放在渲染路径上，否则首屏会被拖慢。 */
(function () {
    setTimeout(function () {
        fetch('/api/qzone/tick.php', { credentials: 'same-origin' }).catch(function () {});
    }, 1200);
})();
</script>
</body>
</html>
