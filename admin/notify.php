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

/* 可编辑模板：存 data/broadcast_templates.json，缺省用内置文案 */
$__defaultTpls = [
    'maintenance' => '【维护公告】今晚 23:00-24:00 站点将进行例行维护，期间可能短暂无法访问，请提前保存草稿。给大家带来不便，敬请谅解。',
    'event'       => '【活动预告】新一轮校园活动即将开始，详情见站内公告，欢迎同学们参与！',
    'feature'     => '【新功能上线】站内新增若干功能，欢迎体验并反馈建议，你的意见会直接影响后续迭代。',
    'exam'        => '【考试周提醒】本周进入考试周，注意休息、合理分配复习时间。祝大家取得好成绩！',
];
$tpls = getFS()->read('broadcast_templates');
if (!is_array($tpls)) { $tpls = []; }
$tpls = array_merge($__defaultTpls, array_intersect_key($tpls, $__defaultTpls));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_schedule') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $notice = 'CSRF 校验失败，请刷新页面重试';
        $noticeType = 'danger';
    } else {
        $qid = (int) ($_POST['id'] ?? 0);
        $rows = (array) getFS()->read('scheduled_broadcasts');
        foreach ($rows as $i => $r) {
            if ((int) ($r['id'] ?? 0) === $qid && (string) ($r['status'] ?? '') === 'pending') {
                $rows[$i]['status'] = 'cancelled';
                $rows[$i]['updated_at'] = date('Y-m-d H:i:s');
                getFS()->write('scheduled_broadcasts', array_values($rows));
                logOperation($adminUser['id'], $adminUser['qq'], 'cancel_scheduled_notify', 'scheduled_broadcast', $qid, '取消定时群发');
                $notice = '已取消定时任务 #' . $qid;
                break;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_tpls') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $notice = 'CSRF 校验失败，请刷新页面重试';
        $noticeType = 'danger';
    } else {
        $new = [];
        foreach ($__defaultTpls as $k => $__v) {
            $txt = trim((string) ($_POST['tpl_' . $k] ?? ''));
            $new[$k] = mb_substr($txt !== '' ? $txt : $__v, 0, 500);
        }
        getFS()->write('broadcast_templates', $new);
        $tpls = $new;
        $notice = '模板已保存';
    }
}

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

            /* 定时发送：先入队，由 includes/scheduled_broadcast.php 在心跳里到点执行 */
            $sendAtRaw = trim((string) ($_POST['send_at'] ?? ''));
            $sendAtTs = $sendAtRaw !== '' ? strtotime(str_replace('T', ' ', $sendAtRaw)) : false;
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            if ($sendAtTs !== false && $sendAtTs > time() + 30) {
                try {
                    if ($scheduleId > 0) {
                        $__all = (array) getFS()->read('scheduled_broadcasts');
                        foreach ($__all as $__i => $__r) {
                            if ((int) ($__r['id'] ?? 0) === $scheduleId && (string) ($__r['status'] ?? '') === 'pending') {
                                $__all[$__i] = array_merge($__r, [
                                    'target' => $target, 'single_id' => $target === 'single' ? $singleId : 0,
                                    'content' => $content, 'link' => $link,
                                    'app_push' => !empty($_POST['app_push']) ? 1 : 0,
                                    'push_title' => trim((string) ($_POST['push_title'] ?? '')) ?: '站内公告',
                                    'send_at' => date('Y-m-d H:i:s', $sendAtTs),
                                    'updated_at' => date('Y-m-d H:i:s'),
                                ]);
                                break;
                            }
                        }
                        getFS()->write('scheduled_broadcasts', array_values($__all));
                        logOperation($adminUser['id'], $adminUser['qq'], 'edit_scheduled_notify', 'scheduled_broadcast', $scheduleId, '修改定时群发');
                        $notice = '已更新定时任务 #' . $scheduleId . '，将于 ' . date('m-d H:i', $sendAtTs) . ' 前触达';
                        $__scheduled = true;
                    } else {
                        getFS()->insert('scheduled_broadcasts', [
                        'admin_id'   => (int) $adminUser['id'],
                        'admin_name' => (string) ($adminUser['nickname'] ?? ''),
                        'target'     => $target,
                        'single_id'  => $target === 'single' ? $singleId : 0,
                        'content'    => $content,
                        'link'       => $link,
                        'app_push'   => !empty($_POST['app_push']) ? 1 : 0,
                        'push_title' => trim((string) ($_POST['push_title'] ?? '')) ?: '站内公告',
                        'send_at'    => date('Y-m-d H:i:s', $sendAtTs),
                        'status'     => 'pending',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    logOperation($adminUser['id'], $adminUser['qq'], 'schedule_notify', 'scheduled_broadcast', 0, '定时群发 @ ' . date('Y-m-d H:i:s', $sendAtTs));
                    $notice = '已加入定时队列，将于 ' . date('m-d H:i', $sendAtTs) . ' 前触达（约 1 分钟内）';
                    $__scheduled = true;
                    }
                } catch (Throwable $__se) {
                    $notice = '定时任务写入失败：' . $__se->getMessage();
                    $noticeType = 'danger';
                }
                $ids = [];
                $__scheduled = true;
            }

            $ok = 0;
            $__nickMap = [];
            foreach ((array) getFS()->read('users') as $__u2) { $__nickMap[(int)($__u2['id'] ?? 0)] = (string)($__u2['nickname'] ?? ''); }
            foreach ($ids as $uid) {
                try {
                    $__nick = $__nickMap[$uid] ?? '';
                    $__content = str_replace(['{昵称}', '{nickname}'], $__nick !== '' ? $__nick : '同学', $content);
                    getFS()->insert('notifications', [
                        'user_id'    => $uid,
                        'type'       => 'system',
                        'content'    => $__content,
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
            if (empty($__scheduled)) {
                logOperation($adminUser['id'], $adminUser['qq'], 'broadcast_notify', 'notification', 0, '群发通知至 ' . $ok . ' 人');
            }

            /* App 推送：直接写 app_pushes（App 的 NotificationWorker 轮询消费）。
             * target 为空 = 全体；指定用户时写 user_id 白名单（最多 500，与 App 侧一致）。 */
            $__pushOk = 0;
            if (empty($__scheduled) && !empty($_POST['app_push'])) {
                try {
                    $__pushTitle = trim((string) ($_POST['push_title'] ?? ''));
                    if ($__pushTitle === '') { $__pushTitle = '站内公告'; }
                    getFS()->insert('app_pushes', [
                        'title'      => mb_substr($__pushTitle, 0, 40),
                        'content'    => mb_substr($content, 0, 300),
                        'url'        => $link,
                        'target'     => ($target === 'all') ? '' : implode(',', array_slice($ids, 0, 500)),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $__pushOk = 1;
                } catch (Throwable $__pe) {
                    error_log('broadcast app push failed: ' . $__pe->getMessage());
                }
            }
            if (empty($__scheduled)) try {
                getFS()->insert('broadcast_logs', [
                    'admin_id'   => (int) $adminUser['id'],
                    'admin_name' => (string) ($adminUser['nickname'] ?? ''),
                    'target'     => $target,
                    'single_id'  => $target === 'single' ? $singleId : 0,
                    'content'    => $content,
                    'link'       => $link,
                    'sent_count' => $ok,
                    'app_push'   => $__pushOk ? 1 : 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $__le) {}

            if (empty($__scheduled)) {
                $notice = '已发送给 ' . $ok . ' 位用户' . ($__pushOk ? '，App 推送已排队（约 15 分钟内到达）' : '');
            }
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
            <input type="hidden" name="schedule_id" value="0">
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
                <div class="ntf-template-row" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
                    <button type="button" class="btn btn-outline btn-sm ntf-template" data-tpl="maintenance">维护公告</button>
                    <button type="button" class="btn btn-outline btn-sm ntf-template" data-tpl="event">活动预告</button>
                    <button type="button" class="btn btn-outline btn-sm ntf-template" data-tpl="feature">新功能上线</button>
                    <button type="button" class="btn btn-outline btn-sm ntf-template" data-tpl="exam">考试周提醒</button>
                    <span style="font-size:12px;color:var(--text-secondary);align-self:center">点模板快速填入</span>
                </div>
                <div style="font-size:12px;color:var(--text-secondary);margin-bottom:6px">支持变量：<code>{昵称}</code> 会替换为每位用户的昵称</div>
                <textarea name="content" rows="4" maxlength="500" placeholder="例：今晚 23:00-24:00 站点维护，期间可能无法访问"
                    style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;resize:vertical"></textarea>
            </div>
            <div class="form-group" style="margin-bottom:14px">
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">定时发送（可选，留空立即发送）</label>
                <input type="datetime-local" name="send_at" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px">
                <span style="font-size:12px;color:var(--text-secondary);margin-left:8px">到点后由站点访问自动触达（约 1 分钟内）</span>
            </div>
            <div class="form-group" style="margin-bottom:18px">
                <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">点击跳转（可选，站内路径）</label>
                <input type="text" name="link" placeholder="/pages/lottery.php" maxlength="200"
                    style="width:320px;padding:8px 10px;border:1px solid var(--border);border-radius:8px">
            </div>
            <div class="form-group" style="margin-bottom:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
                    <input type="checkbox" name="app_push" value="1"> 同时发送 App 推送
                </label>
                <input type="text" name="push_title" placeholder="推送标题（默认：站内公告）" maxlength="40"
                    style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;width:260px">
            </div>
            <button class="btn btn-primary" type="submit" onclick="return confirm('确认向当前筛选的用户群发通知？')">立即发送</button>
            <button class="btn btn-outline" type="button" id="previewBtn" style="margin-left:8px">预览</button>
        </form>

        <details style="margin-top:16px">
            <summary style="cursor:pointer;font-size:13px;color:var(--text-secondary)">✎ 编辑模板文案</summary>
            <form method="post" style="margin-top:10px">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_tpls">
                <?php foreach (['maintenance' => '维护公告', 'event' => '活动预告', 'feature' => '新功能上线', 'exam' => '考试周提醒'] as $__k => $__label): ?>
                <div style="margin-bottom:8px">
                    <label style="display:block;font-size:12.5px;font-weight:600;margin-bottom:4px"><?= $__label ?></label>
                    <textarea name="tpl_<?= $__k ?>" rows="2" maxlength="500" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;resize:vertical"><?= htmlspecialchars($tpls[$__k]) ?></textarea>
                </div>
                <?php endforeach; ?>
                <button class="btn btn-outline btn-sm" type="submit">保存模板</button>
            </form>
        </details>

        <?php
        $__pending = [];
        foreach ((array) getFS()->read('scheduled_broadcasts') as $__q) {
            if ((string) ($__q['status'] ?? 'pending') === 'pending') { $__pending[] = $__q; }
        }
        usort($__pending, function ($a, $b) { return strtotime((string)($a['send_at'] ?? '')) - strtotime((string)($b['send_at'] ?? '')); });
        ?>
        <?php if ($__pending): ?>
        <div style="margin-top:20px">
            <h4 style="font-size:14px;margin-bottom:8px">待发送的定时任务（<?= count($__pending) ?>）</h4>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>计划时间</th><th>对象</th><th>内容</th><th>App推送</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($__pending as $__q): ?>
                        <tr>
                            <td><?php
                                $__at = strtotime((string)($__q['send_at'] ?? '')) ?: 0;
                                $__left = max(0, (int) ceil(($__at - time()) / 60));
                                echo htmlspecialchars((string)($__q['send_at'] ?? ''));
                                if ($__at > 0) { echo '<div style="font-size:11.5px;color:var(--text-secondary);margin-top:2px">距发送 ' . $__left . ' 分钟</div>'; }
                            ?></td>
                            <td><?= ['all'=>'全部','role_user'=>'普通用户','role_admin'=>'管理员','single'=>'用户#' . (int)($__q['single_id'] ?? 0)][(string)($__q['target'] ?? 'all')] ?? '全部' ?></td>
                            <td style="max-width:320px;word-break:break-word"><?= htmlspecialchars(mb_substr((string)($__q['content'] ?? ''), 0, 60)) ?></td>
                            <td><?= !empty($__q['app_push']) ? '✓' : '—' ?></td>
                            <td>
                                <button type="button" class="btn btn-outline btn-sm edit-schedule"
                                    data-id="<?= (int)($__q['id'] ?? 0) ?>"
                                    data-target="<?= htmlspecialchars((string)($__q['target'] ?? 'all')) ?>"
                                    data-single="<?= (int)($__q['single_id'] ?? 0) ?>"
                                    data-content="<?= htmlspecialchars((string)($__q['content'] ?? '')) ?>"
                                    data-link="<?= htmlspecialchars((string)($__q['link'] ?? '')) ?>"
                                    data-push="<?= !empty($__q['app_push']) ? 1 : 0 ?>"
                                    data-sendat="<?= htmlspecialchars(str_replace(' ', 'T', substr((string)($__q['send_at'] ?? ''), 0, 16))) ?>">编辑</button>
                                <form method="post" onsubmit="return confirm('取消这条定时群发？')" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="cancel_schedule">
                                    <input type="hidden" name="id" value="<?= (int)($__q['id'] ?? 0) ?>">
                                    <button class="btn btn-outline btn-sm" type="submit">取消</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $__logs = (array) getFS()->read('broadcast_logs');
        usort($__logs, function ($a, $b) { return strtotime((string)($b['created_at'] ?? '')) - strtotime((string)($a['created_at'] ?? '')); });
        $__logs = array_slice($__logs, 0, 20);
        ?>
        <?php if ($__logs): ?>
        <div style="margin-top:20px">
            <h4 style="font-size:14px;margin-bottom:8px">最近群发记录</h4>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>时间</th><th>发送人</th><th>对象</th><th>内容</th><th>触达</th><th>App推送</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($__logs as $__lg): ?>
                        <tr>
                            <td style="white-space:nowrap"><?= htmlspecialchars((string)($__lg['created_at'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($__lg['admin_name'] ?? '')) ?></td>
                            <td><?= ['all'=>'全部','role_user'=>'普通用户','role_admin'=>'管理员','single'=>'用户#' . (int)($__lg['single_id'] ?? 0)][(string)($__lg['target'] ?? 'all')] ?? '全部' ?></td>
                            <td style="max-width:320px;word-break:break-word"><?= htmlspecialchars(mb_substr((string)($__lg['content'] ?? ''), 0, 60)) ?></td>
                            <td><?= (int)($__lg['sent_count'] ?? 0) ?></td>
                            <td><?= !empty($__lg['app_push']) ? '✓' : '—' ?></td>
                            <td><button type="button" class="btn btn-outline btn-sm refill-broadcast"
                                data-target="<?= htmlspecialchars((string)($__lg['target'] ?? 'all')) ?>"
                                data-single="<?= (int)($__lg['single_id'] ?? 0) ?>"
                                data-content="<?= htmlspecialchars((string)($__lg['content'] ?? '')) ?>"
                                data-link="<?= htmlspecialchars((string)($__lg['link'] ?? '')) ?>"
                                data-push="<?= !empty($__lg['app_push']) ? 1 : 0 ?>">重发</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <p style="margin-top:14px;font-size:12.5px;color:var(--text-secondary);line-height:1.7">
            说明：群发写入「系统」分类，用户可在通知面板/通知中心查看；勾选 App 推送后，安装了 App 的用户会在后台轮询周期内收到系统通知（不保证秒达）。
        </p>
    </div>
</div>
<script>
(function () {
    document.querySelectorAll('.edit-schedule').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.querySelector('form[method="post"]');
            if (!form) return;
            form.querySelector('input[name="schedule_id"]').value = btn.getAttribute('data-id') || '0';
            form.querySelector('select[name="target"]').value = btn.getAttribute('data-target') || 'all';
            form.querySelector('input[name="single_id"]').value = btn.getAttribute('data-single') || '';
            form.querySelector('textarea[name="content"]').value = btn.getAttribute('data-content') || '';
            form.querySelector('input[name="link"]').value = btn.getAttribute('data-link') || '';
            form.querySelector('input[name="app_push"]').checked = btn.getAttribute('data-push') === '1';
            var sa = form.querySelector('input[name="send_at"]');
            if (sa) sa.value = btn.getAttribute('data-sendat') || '';
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            form.querySelector('textarea[name="content"]').focus();
        });
    });

    document.querySelectorAll('.refill-broadcast').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.querySelector('form[method="post"]');
            if (!form) return;
            var targetSel = form.querySelector('select[name="target"]');
            var single = form.querySelector('input[name="single_id"]');
            var content = form.querySelector('textarea[name="content"]');
            var link = form.querySelector('input[name="link"]');
            var push = form.querySelector('input[name="app_push"]');
            if (targetSel) targetSel.value = btn.getAttribute('data-target') || 'all';
            if (single) single.value = btn.getAttribute('data-single') || '';
            if (content) { content.value = btn.getAttribute('data-content') || ''; }
            if (link) link.value = btn.getAttribute('data-link') || '';
            if (push) push.checked = btn.getAttribute('data-push') === '1';
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (content) content.focus();
        });
    });
})();
</script>
<script>
(function () {
    var TPL = <?= json_encode([
        'maintenance' => ['text' => $tpls['maintenance'], 'link' => '/', 'title' => '维护公告'],
        'event'       => ['text' => $tpls['event'], 'link' => '/pages/lottery.php', 'title' => '活动预告'],
        'feature'     => ['text' => $tpls['feature'], 'link' => '/pages/discover.php', 'title' => '新功能上线'],
        'exam'        => ['text' => $tpls['exam'], 'link' => '/pages/tools.php', 'title' => '考试周提醒'],
    ], JSON_UNESCAPED_UNICODE) ?>;
    document.querySelectorAll('.ntf-template').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var t = TPL[btn.getAttribute('data-tpl')];
            if (!t) return;
            var form = document.querySelector('form[method="post"]');
            if (!form) return;
            var content = form.querySelector('textarea[name="content"]');
            var link = form.querySelector('input[name="link"]');
            var pushTitle = form.querySelector('input[name="push_title"]');
            if (content) { content.value = t.text; content.focus(); }
            if (link && !link.value) { link.value = t.link; }
            if (pushTitle && !pushTitle.value) { pushTitle.value = t.title; }
        });
    });
})();
</script>
<div id="previewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.42);z-index:9999;align-items:center;justify-content:center;padding:16px">
    <div style="background:var(--card-bg,#fff);border-radius:16px;max-width:420px;width:100%;box-shadow:0 18px 50px rgba(0,0,0,.22);overflow:hidden">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border-light,#E4E9F0)">
            <b style="font-size:14px">通知预览</b>
            <button type="button" id="previewClose" style="border:none;background:none;font-size:18px;cursor:pointer;color:var(--text-secondary)">×</button>
        </div>
        <div style="padding:14px 16px">
            <div style="display:flex;gap:10px;align-items:flex-start">
                <div style="width:32px;height:32px;border-radius:50%;background:rgba(47,91,154,.12);display:flex;align-items:center;justify-content:center;font-size:16px;flex:0 0 auto">🔔</div>
                <div style="flex:1;min-width:0">
                    <div id="previewContent" style="font-size:14px;line-height:1.6;color:var(--text,#1a202c);word-break:break-word"></div>
                    <div style="margin-top:6px;font-size:11.5px;color:var(--text-muted,#9AA7B8)">刚刚 · 系统通知</div>
                </div>
            </div>
            <div id="previewLink" style="display:none;margin-top:10px;padding:8px 10px;border-radius:8px;background:var(--bg-secondary,#F7F9FC);font-size:12.5px;color:var(--primary)"></div>
        </div>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('previewBtn');
    var modal = document.getElementById('previewModal');
    if (!btn || !modal) return;
    btn.addEventListener('click', function () {
        var form = document.querySelector('form[method="post"]');
        if (!form) return;
        var content = (form.querySelector('textarea[name="content"]') || {}).value || '';
        var link = (form.querySelector('input[name="link"]') || {}).value || '';
        document.getElementById('previewContent').textContent = content || '（未填写内容）';
        var linkEl = document.getElementById('previewLink');
        if (link) { linkEl.style.display = 'block'; linkEl.textContent = '跳转：' + link; }
        else { linkEl.style.display = 'none'; }
        modal.style.display = 'flex';
    });
    document.getElementById('previewClose').addEventListener('click', function () { modal.style.display = 'none'; });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });
})();
</script>
<?php adminFooter(); ?>
