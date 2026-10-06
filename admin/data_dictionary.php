<?php
/**
 * D23 数据字典与字段说明
 * 列出 data/ 下所有 JSON 数据表：行数、体积、字段名与用途。
 * 字段名取自真实数据（array_keys 首行），避免臆造；关键表附中文说明。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_health')) {
    http_response_code(403);
    die('403 Forbidden');
}

adminHeader('数据字典', $adminUser, $csrfToken);

// 各表字段中文说明（只标注关键表，其余动态取字段名）
$DESC = [
    'posts'            => '帖子主表。status 正常值为 published；poll 为投票对象（question/options/votes）。',
    'comments'         => '评论。无 parent_id，楼中楼走 comment_replies。',
    'follows'          => '关注关系：user_id 关注 target_id。',
    'checkins'         => '签到，按用户聚合一行（last_date/streak/total）。',
    'pm_messages'      => '私信，key = min|max 双方 id。',
    'notifications'    => '通知：type/content/post_id/from_user/is_read。',
    'post_likes'       => '点赞：user_id + post_id。',
    'post_favorites'   => '收藏：user_id + post_id。',
    'users'            => '用户主表。role: super_admin/admin/user；theme: light/dark/system。',
    'admin_permissions'=> '管理员权限逐项分配：user_id + permission_key。',
    'user_levels'      => '成长等级缓存（等级由累计经验推导，落库仅作列表缓存）。',
    'user_achievements'=> '用户已解锁成就。',
    'user_blocks'      => '屏蔽关系（单向发起、双向隔离）。',
    'pm_settings'      => '私信免打扰 / 置顶设置。',
    'operation_logs'   => '管理员操作审计日志（全站唯一带 ip 的用户行为表）。',
    'post_reports'     => '举报记录（无 status 字段，仅累计）。',
    'title_requests'   => '头衔申请，status: pending/approved/rejected。',
    'qq_change_requests'=> 'QQ 修改申请，status: pending/approved/rejected。',
];

$dataDir = __DIR__ . '/../data';
$tables = [];
foreach (glob($dataDir . '/*.json') as $f) {
    $name = basename($f, '.json');
    $rows = json_decode(file_get_contents($f), true);
    if (!is_array($rows)) { $rows = []; }
    $fields = [];
    if (!empty($rows) && is_array($rows) && ($first = reset($rows)) !== null && is_array($first)) {
        $fields = array_keys($first);
    }
    $tables[] = [
        'name'   => $name,
        'count'  => count($rows),
        'size'   => filesize($f),
        'fields' => $fields,
        'desc'   => $DESC[$name] ?? '',
    ];
}
usort($tables, function ($a, $b) { return $b['size'] - $a['size']; });

function fmtSize($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 2) . ' MB';
}
?>
<style>
.dd-table td, .dd-table th { vertical-align: top; }
.dd-fields { display: flex; flex-wrap: wrap; gap: 4px; }
.dd-field { font-size: 11px; padding: 2px 7px; border-radius: 8px; background: var(--primary-light); color: var(--primary); font-family: ui-monospace, monospace; }
.dd-desc { color: var(--text-secondary); font-size: 12px; max-width: 360px; }
.dd-name { font-family: ui-monospace, monospace; font-weight: 700; color: var(--text); }
</style>

<div class="section">
    <div class="section-header">
        <h3>数据字典（<?= count($tables) ?> 张表）</h3>
        <span class="badge-info">字段名取自真实数据，非臆造</span>
    </div>
    <div class="section-body">
        <div class="table-wrapper">
            <table class="dd-table">
                <thead><tr><th>数据表</th><th>行数</th><th>体积</th><th>字段（<?= ' ' ?>）</th><th>说明</th></tr></thead>
                <tbody>
                <?php foreach ($tables as $t): ?>
                    <tr>
                        <td class="dd-name"><?= xss_clean($t['name']) ?></td>
                        <td><?= $t['count'] ?></td>
                        <td><?= fmtSize($t['size']) ?></td>
                        <td><div class="dd-fields"><?php foreach ($t['fields'] as $f): ?><span class="dd-field"><?= xss_clean($f) ?></span><?php endforeach; ?><?php if (empty($t['fields'])): ?><span style="color:var(--text-muted);font-size:12px;">（空表）</span><?php endif; ?></div></td>
                        <td class="dd-desc"><?= xss_clean($t['desc']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
