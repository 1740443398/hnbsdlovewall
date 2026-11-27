<?php
/**
 * 通知群发：给全站 / 指定角色 / 单个用户发一条站内通知。
 * 场景：停机维护、活动预告、重要公告的即时触达（发帖公告不一定人人看首页）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_users')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

$notice = '';
$noticeType = 'success';

$target = (string) ($_POST['target'] ?? ($_GET['target'] ?? 'all'));
if (!in_array($target, ['all', 'role_admin', 'role_user', 'single'], true)) { $target = 'all'; }
$singleId = (int) ($_POST['single_id'] ?? ($_GET['single_id'] ?? 0));

$users = (array) getFS()->read('users');

$matchUsers = function () use ($users, $target, $singleId) {
    $out = [];
    foreach ($users as $u) {
        if (!empty($u['is_bot'])) { continue; }
        $role = (string) ($u['role'] ?? 'user');
        if ($target === 'role_admin' && !in_array($role, ['admin', 'super_admin'], true)) { continue; }
        if ($target === 'role_user' && in_array($role, ['admin', 'super_admin'], true)) { continue; }
        if ($target === 'single' && (int) ($u['id'] ?? 0) !== $singleId) { continue; }
        $out[] = (int) ($u['id'] ?? 0);
    }
    return array_values(array_filter($out));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $notice = 'CSRF 校验失败，请刷新页面重试';
        $noticeType = 'danger';
    } else {
        $content = trim((string) ($_POST['content'] ?? ''));
        $link = trim((string) ($_POST['link'] ?? ''));
        if ($content === '') {
            $notice = '通知内容不能为空';
            $noticeType = 'danger';
        } elseif (mb_strlen($content) > 500) {
            $notice = '内容过长（最多 500 字）';
            $noticeType = 'danger';
        } elseif ($link !== '' && !preg_match('#^/(?![/\\\\])#', $link)) {
            $notice = '链接必须是站内相对路径（以 / 开头）';
            $noticeType = 'danger';
        } else {
            $ids = $matchUsers();
            $ok = 0;
            foreach ($ids as $uid) {
                try {
                    getFS()->insert('notifications', [
                        'user_id'    => $uid,
                        'type'       => 'system',
                        'content'    => $content,
                        'post_id'    => 0,
                        'post_title' => '',
                        'link'       => $link,
                        'from_user'  => (string) ($adminUser['nickname'] ?? '管理员'),
                        'is_read'    => false,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $ok++;
                } catch (Throwable $e) {}
            }
            logOperation($adminUser['id'], $adminUser['qq'], 'broadcast_notify', 'notification', 0, '群发通知至 ' . $ok . ' 人');
            $notice = '已发送给 ' . $ok . ' 位用户';
        }
    }
}

$count = count($matchUsers());
adminHeader('通知群发', $adminUser, $csrfToken);
?>
<div class="section">
    <div class="section-header"><h3>通知群发</h3>
        <span style="font-size:13px;color:var(--text-secondary)">当前筛选可触达 <b><?= (int)$count ?></b> 位用户</span>
    </div>
    <div class="section-body">
        <?php if ($notice !== ''): ?>
        <div class="alert alert-<?= $noticeType ?>" style="margin:0 0 12px"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="send">
            <div class="form-group" style="margin-bottom:14px">
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">发送对象</label>
                <select name="target" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;min-width:220px">
                    <option value="all" <?= $target==='all'?'selected':'' ?>>全部用户</option>
                    <option value="role_user" <?= $target==='role_user'?'selected':'' ?>>仅普通用户</option>
                    <option value="role_admin" <?= $target==='role_admin'?'selected':'' ?>>仅管理员</option>
                    <option value="single" <?= $target==='single'?'selected':'' ?>>指定用户 ID</option>
                </select>
                <input type="number" name="single_id" value="<?= (int)$singleId ?>" placeholder="用户 ID（仅「指定用户」时生效）"
                       style="margin-left:8px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;width:200px">
            </div>
            <div class="form-group" style="margin-bottom:14px">
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">通知内容</label>
                <textarea name="content" rows="4" maxlength="500" placeholder="例：今晚 23:00-24:00 站点维护，期间可能无法访问"
                    style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;resize:vertical"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:18px">
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">点击跳转（可选，站内路径）</label>
                <input type="text" name="link" placeholder="/pages/lottery.php" maxlength="200"
                    style="width:320px;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
            </div>
            <button class="btn btn-primary" type="submit" onclick="return confirm('确认向当前筛选的用户群发通知？')">立即发送</button>
        </form>

        <p style="margin-top:14px;font-size:12.5px;color:var(--text-secondary);line-height:1.7">
            说明：群发写入「系统」分类，用户可在通知面板/通知中心查看；不会发邮件或短信，也不会触发 App 推送。
        </p>
    </div>
</div>
<?php adminFooter(); ?>
