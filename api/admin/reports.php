<?php
/**
 * 后台举报接口
 *
 * ==========================================================================
 * 这一版做了什么改变
 * ==========================================================================
 * 【原来】
 *   - 帖子举报和私信举报是两套完全独立的列表（分标签页）
 *   - 处置动作只有一个「删除举报」
 *
 * 【问题】
 *   1. 分标签让人漏看：管理员点开默认只看私信举报，
 *      帖子举报堆了十条也不知道
 *   2. 只有「删除」意味着处理完什么痕迹都不留 ——
 *      无法回答「这条举报后来怎么处理的」「为什么驳回了」
 *   3. 举报人得不到任何反馈，下次就不举报了
 *
 * 【现在】
 *   - 两种举报合并成一个时间流，按时间倒序混排
 *   - 处置动作完整：忽略 / 警告 / 删除内容 / 封禁 / 已处理
 *   - 每条处置都写 status + 处理人 + 处理时间 + 备注
 *   - 处置结果通知举报人（站内通知）
 * ==========================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/sort_util.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();
$action = $_POST['action'] ?? 'list';

/** 举报状态 -> 中文名 */
function lwReportStatusLabel(string $s): string
{
    return [
        'pending'   => '待处理',
        'ignored'   => '已忽略',
        'warned'    => '已警告',
        'removed'   => '已删除内容',
        'banned'    => '已封禁',
        'resolved'  => '已处理',
    ][$s] ?? $s;
}

/** 读一张举报表并统一成同一形状。 */
function lwReportNormalize(array $rows, string $kind, $fs): array
{
    $out = [];
    foreach ($rows as $r) {
        $base = [
            'id'         => (int)($r['id'] ?? 0),
            'kind'       => $kind,                       // post | pm
            'kind_label' => $kind === 'post' ? '帖子' : '私信',
            'reason'     => (string)($r['reason'] ?? ''),
            'detail'     => (string)($r['description'] ?? ''),
            'reporter'   => (string)($r['reporter_qq'] ?? ''),
            'status'     => (string)($r['status'] ?? 'pending'),
            'status_label' => lwReportStatusLabel((string)($r['status'] ?? 'pending')),
            'note'       => (string)($r['handle_note'] ?? ''),
            'handled_by' => (string)($r['handled_by'] ?? ''),
            'handled_at' => (string)($r['handled_at'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'records'    => null,
            'post_id'    => 0,
            'target_qq'  => '',
            'target_name'=> '',
            'target_sub' => '',
            'target_user_id' => 0,
        ];

        if ($kind === 'post') {
            $pid = (int)($r['post_id'] ?? 0);
            $post = $pid > 0 ? $fs->findById('posts', $pid) : null;
            $base['post_id'] = $pid;
            $base['target_name'] = '帖子 #' . $pid;
            $base['target_sub'] = $post ? (string)($post['title'] ?? '（无标题）') : '（帖子已删除）';
            if ($post) {
                $base['target_user_id'] = (int)($post['user_id'] ?? 0);
                $author = $fs->findById('users', (int)($post['user_id'] ?? 0));
                $base['target_qq'] = $author ? (string)($author['qq'] ?? '') : '';
            }
        } else {
            $base['target_qq'] = (string)($r['target_qq'] ?? '');
            $base['target_name'] = !empty($r['target_nickname'])
                ? $r['target_nickname'] . '（QQ:' . $r['target_qq'] . '）'
                : 'QQ:' . $base['target_qq'];
            $base['records'] = $r['records'] ?? [];
            $tu = $base['target_qq'] !== '' ? $fs->findOne('users', ['qq' => $base['target_qq']]) : null;
            if ($tu) $base['target_user_id'] = (int)($tu['id'] ?? 0);
        }

        $out[] = $base;
    }
    return $out;
}

// ============================ 列表（合并两种举报）============================
if ($action === 'list') {
    if (!checkPermission($admin, 'view_reports')) {
        jsonError('无权限查看举报', 403);
    }

    $page    = max(1, (int)($_REQUEST['page'] ?? 1));
    $limit   = 20;
    $keyword = sanitizeInput($_REQUEST['keyword'] ?? '');
    // 状态筛选：all / pending / handled
    $statusFilter = (string)($_REQUEST['status'] ?? 'all');
    // 兼容旧的 type 参数（老前端可能还在传），但不作为必需
    $kindFilter = (string)($_REQUEST['type'] ?? '');

    $all = array_merge(
        lwReportNormalize((array)$fs->read('post_reports'), 'post', $fs),
        lwReportNormalize((array)$fs->read('pm_reports'), 'pm', $fs)
    );

    if ($kindFilter === 'post' || $kindFilter === 'pm') {
        $all = array_values(array_filter($all, function ($r) use ($kindFilter) {
            return $r['kind'] === $kindFilter;
        }));
    }

    if ($statusFilter === 'pending') {
        $all = array_values(array_filter($all, function ($r) {
            return $r['status'] === 'pending';
        }));
    } elseif ($statusFilter === 'handled') {
        $all = array_values(array_filter($all, function ($r) {
            return $r['status'] !== 'pending';
        }));
    }

    if ($keyword !== '') {
        $all = array_values(array_filter($all, function ($r) use ($keyword) {
            $hay = $r['reason'] . ' ' . $r['detail'] . ' ' . $r['target_qq'] . ' '
                 . $r['target_name'] . ' ' . $r['reporter'];
            return stripos($hay, $keyword) !== false;
        }));
    }

    // 按创建时间倒序（两种举报混排，最新的在最上面）
    usort($all, function ($a, $b) {
        $ta = strtotime($a['created_at']) ?: 0;
        $tb = strtotime($b['created_at']) ?: 0;
        return $tb <=> $ta;
    });

    $total      = count($all);
    $totalPages = max(1, (int)ceil($total / $limit));
    $page       = min($page, $totalPages);
    $items      = array_slice($all, ($page - 1) * $limit, $limit);

    // 各状态计数，供前端显示徽标
    $counts = ['pending' => 0, 'handled' => 0, 'post' => 0, 'pm' => 0];
    foreach ($all as $r) {
        if ($r['status'] === 'pending') $counts['pending']++; else $counts['handled']++;
        $counts[$r['kind']]++;
    }
    // 注意：counts 是全量统计，不受分页影响

    jsonSuccess([
        'items'       => $items,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $totalPages,
        'counts'      => $counts,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

// ============================ 处置 ============================
if ($action === 'handle') {
    if (!checkPermission($admin, 'manage_reports')) {
        jsonError('无权限处置举报', 403);
    }

    $kind    = ($_POST['kind'] ?? '') === 'post' ? 'post' : 'pm';
    $id      = (int)($_POST['id'] ?? 0);
    $verdict = (string)($_POST['verdict'] ?? '');   // ignored|warned|removed|banned|resolved
    $note    = sanitizeInput((string)($_POST['note'] ?? ''));

    if ($id <= 0) {
        jsonError('举报ID无效');
    }
    if (!in_array($verdict, ['ignored', 'warned', 'removed', 'banned', 'resolved'], true)) {
        jsonError('处置方式无效');
    }

    $table = $kind === 'post' ? 'post_reports' : 'pm_reports';
    $item  = $fs->findById($table, $id);
    if (!$item) {
        jsonError('举报不存在');
    }

    $targetUid = 0;
    $actionText = '';

    // ---- 按处置方式执行实际动作 ----
    if ($verdict === 'removed' && $kind === 'post') {
        $pid = (int)($item['post_id'] ?? 0);
        if ($pid > 0 && $fs->findById('posts', $pid)) {
            $fs->update('posts', $pid, [
                'status'     => 'rejected',
                'mod_status' => 'admin_removed',
                'mod_at'     => date('Y-m-d H:i:s'),
            ]);
            $actionText = '已删除帖子 #' . $pid;
        }
    } elseif ($verdict === 'banned') {
        // 找被举报人
        if ($kind === 'post') {
            $post = $fs->findById('posts', (int)($item['post_id'] ?? 0));
            $targetUid = $post ? (int)($post['user_id'] ?? 0) : 0;
        } else {
            $tu = $fs->findOne('users', ['qq' => (string)($item['target_qq'] ?? '')]);
            $targetUid = $tu ? (int)($tu['id'] ?? 0) : 0;
        }
        if ($targetUid > 0) {
            $u = $fs->findById('users', $targetUid);
            if ($u && ($u['role'] ?? '') !== 'super_admin') {
                $fs->update('users', $targetUid, [
                    'is_banned'  => 1,
                    'ban_reason' => '举报核实：' . ($note !== '' ? $note : ($item['reason'] ?? '违反社区规范')),
                    'ban_until'  => null,
                ]);
                $actionText = '已封禁用户 #' . $targetUid;

                // 封禁邮件（安全类，不受偏好开关影响）
                if (class_exists('QQMailer') && function_exists('lwMailNotify')) {
                    lwMailNotify('ban', $targetUid, [
                        'subject' => '你的账号已被封禁',
                        'title'   => '账号封禁通知',
                        'lines'   => [
                            '你的账号因被举报核实违规，已被管理员封禁。',
                            '原因：' . ($note !== '' ? $note : ($item['reason'] ?? '违反社区规范')),
                            '如果你认为这是误判，可以联系管理员说明情况。',
                        ],
                    ]);
                }
            }
        }
    } elseif ($verdict === 'warned') {
        // 发警告通知（不封禁、不删内容）
        if ($kind === 'post') {
            $post = $fs->findById('posts', (int)($item['post_id'] ?? 0));
            $targetUid = $post ? (int)($post['user_id'] ?? 0) : 0;
        } else {
            $tu = $fs->findOne('users', ['qq' => (string)($item['target_qq'] ?? '')]);
            $targetUid = $tu ? (int)($tu['id'] ?? 0) : 0;
        }
        if ($targetUid > 0) {
            $fs->insert('notifications', [
                'user_id'   => $targetUid,
                'type'      => 'system',
                'content'   => '你的内容被举报并核实违规，这是一次警告。'
                             . ($note !== '' ? '（管理员备注：' . $note . '）' : '')
                             . '请遵守社区规范，再次违规将被封禁。',
                'from_user' => '管理员',
                'is_read'   => false,
            ]);
            $actionText = '已警告用户 #' . $targetUid;
        }
    }

    // ---- 更新举报记录状态 ----
    $fs->update($table, $id, [
        'status'      => $verdict,
        'handle_note' => $note,
        'handled_by'  => (string)($admin['qq'] ?? ''),
        'handled_at'  => date('Y-m-d H:i:s'),
    ]);

    // ---- 通知举报人（让其知道举报有结果）----
    $reporterQq = (string)($item['reporter_qq'] ?? '');
    if ($reporterQq !== '') {
        $ru = $fs->findOne('users', ['qq' => $reporterQq]);
        if ($ru) {
            $fs->insert('notifications', [
                'user_id'   => (int)$ru['id'],
                'type'      => 'system',
                'content'   => '你提交的举报已处理：' . lwReportStatusLabel($verdict)
                             . ($note !== '' ? '（' . $note . '）' : ''),
                'from_user' => '管理员',
                'is_read'   => false,
            ]);
        }
    }

    logOperation(
        $admin['id'], $admin['qq'], 'handle_report',
        $kind . '_report', $id,
        '处置举报：' . lwReportStatusLabel($verdict)
        . ($actionText !== '' ? '，' . $actionText : '')
        . ($note !== '' ? '，备注：' . $note : '')
    );

    jsonSuccess([], '已处理：' . lwReportStatusLabel($verdict) . ($actionText !== '' ? '，' . $actionText : ''));
}

// ============================ 删除举报记录 ============================
if ($action === 'delete') {
    if (!checkPermission($admin, 'manage_reports')) {
        jsonError('无权限删除举报', 403);
    }
    $kind = ($_POST['kind'] ?? $_POST['type'] ?? '') === 'post' ? 'post' : 'pm';
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonError('举报ID无效');
    }
    $table = $kind === 'post' ? 'post_reports' : 'pm_reports';
    $item = $fs->findById($table, $id);
    if (!$item) {
        jsonError('举报不存在');
    }
    $fs->delete($table, $id);
    logOperation($admin['id'], $admin['qq'], 'delete_report', $kind . '_report', $id,
        '删除举报记录（' . ($item['reason'] ?? '') . '）');
    jsonSuccess([], '举报记录已删除');
}

jsonError('未知操作');
