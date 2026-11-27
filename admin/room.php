<?php
/**
 * 聊天室管理：查看最近消息、按关键词过滤、删除单条。
 * 为什么需要：聊天室是唯一「发完即对所有登录用户可见」的入口，
 * 出现辱骂/广告时管理员此前只能干看着（没有后台入口）。
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
$canDelete = checkPermission($adminUser, 'delete_posts');

$notice = '';
$noticeType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!$canDelete) {
        $notice = '没有删除权限';
        $noticeType = 'danger';
    } elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $notice = 'CSRF 校验失败，请刷新页面重试';
        $noticeType = 'danger';
    } else {
        $mid = (int) ($_POST['id'] ?? 0);
        if ($mid > 0) {
            try {
                getFS()->delete('lobby_messages', $mid);
                logOperation($adminUser['id'], $adminUser['qq'], 'delete_lobby_msg', 'lobby_message', $mid, '删除聊天室消息');
                $notice = '已删除消息 #' . $mid;
            } catch (Throwable $e) {
                $notice = '删除失败：' . $e->getMessage();
                $noticeType = 'danger';
            }
        }
    }
}

$kw = trim((string) ($_GET['kw'] ?? ''));
$rows = (array) getFS()->read('lobby_messages');
usort($rows, function ($a, $b) { return (int) ($b['ts'] ?? 0) <=> (int) ($a['ts'] ?? 0); });
if ($kw !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($kw) {
        return mb_stripos((string) ($r['text'] ?? ''), $kw) !== false || mb_stripos((string) ($r['nickname'] ?? ''), $kw) !== false;
    }));
}
$total = count($rows);
$rows = array_slice($rows, 0, 200);

adminHeader('聊天室管理', $adminUser, $csrfToken);
?>
<div class="section">
    <div class="section-header">
        <h3>聊天室消息</h3>
        <span style="font-size:13px;color:var(--text-secondary)">最近 200 条 · 共 <?= (int)$total ?> 条匹配</span>
    </div>
    <?php if ($notice !== ''): ?>
    <div class="alert alert-<?= $noticeType ?>" style="margin:0 0 12px"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>
    <div class="section-body">
        <form method="get" class="filter-row" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <input type="text" name="kw" value="<?= htmlspecialchars($kw) ?>" placeholder="搜索内容或昵称…" style="flex:1 1 220px;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
            <button class="btn btn-primary btn-sm" type="submit">搜索</button>
            <?php if ($kw !== ''): ?><a class="btn btn-outline btn-sm" href="/admin/room.php">清除</a><?php endif; ?>
        </form>

        <div class="table-wrapper">
            <table>
                <thead><tr><th>ID</th><th>用户</th><th>内容</th><th>时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5" class="empty-state">暂无消息</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)($r['id'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string)($r['nickname'] ?? '') ?: ('用户' . (int)($r['user_id'] ?? 0))) ?>
                            <?php if (($r['role'] ?? '') === 'admin' || ($r['role'] ?? '') === 'super_admin'): ?><span class="badge badge-warning">管理员</span><?php endif; ?>
                        </td>
                        <td style="max-width:360px;word-break:break-word">
                            <?php if (($r['kind'] ?? 'text') === 'image'): ?>
                                <span class="badge">图片</span>
                                <a href="<?= htmlspecialchars((string)($r['image'] ?? '')) ?>" target="_blank" rel="noopener">查看</a>
                            <?php else: ?>
                                <?= nl2br(htmlspecialchars((string)($r['text'] ?? ''))) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(date('m-d H:i', (int)(($r['ts'] ?? 0) / 1000))) ?></td>
                        <td>
                            <?php if ($canDelete): ?>
                            <form method="post" onsubmit="return confirm('确定删除这条消息？')" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)($r['id'] ?? 0) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">删除</button>
                            </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php adminFooter(); ?>
