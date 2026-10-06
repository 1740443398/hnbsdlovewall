<?php
/**
 * D15 角色与权限矩阵（只读总览）
 * 以「管理员 × 权限」矩阵呈现每个管理员当前生效的权限，便于审计与排错。
 * 超级管理员（super_admin）在 checkPermission 中恒为真，故整列标记为「全部」。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_admins')) {
    http_response_code(403);
    die('403 Forbidden - 仅超级管理员可访问');
}

adminHeader('权限矩阵', $adminUser, $csrfToken);

$fs = getFS();
$users = $fs->getAll('users');
$admins = array_filter($users, function ($u) {
    return in_array($u['role'] ?? '', ['super_admin', 'admin'], true);
});
usort($admins, function ($a, $b) {
    $ra = ($a['role'] === 'super_admin') ? 0 : 1;
    $rb = ($b['role'] === 'super_admin') ? 0 : 1;
    return $ra - $rb;
});

$perms = getAllPermissionDefinitions();
$adminPerms = [];
foreach ($admins as $a) {
    $adminPerms[$a['id']] = array_flip(getAdminPermissions($a['id']));
}
?>
<style>
.matrix-wrap { overflow-x: auto; }
table.matrix { border-collapse: collapse; min-width: 640px; }
table.matrix th, table.matrix td { padding: 10px 12px; border-bottom: 1px solid var(--border); font-size: 13px; text-align: center; white-space: nowrap; }
table.matrix thead th { position: sticky; top: 0; background: var(--input-bg); z-index: 2; }
table.matrix th.perm-name, table.matrix td.perm-name { text-align: left; position: sticky; left: 0; background: var(--card-bg); z-index: 1; min-width: 220px; }
table.matrix thead th.perm-name { z-index: 3; }
table.matrix tbody tr:hover td { background: var(--bg-secondary); }
table.matrix td.perm-name { color: var(--text); font-weight: 500; }
.perm-group-row td { background: var(--primary-light) !important; color: var(--primary); font-weight: 700; text-align: left; font-size: 12px; letter-spacing: .5px; text-transform: uppercase; }
.cell-yes { color: var(--success); font-weight: 700; font-size: 15px; }
.cell-no  { color: var(--text-muted); }
.col-admin { min-width: 120px; }
.col-admin .a-name { font-weight: 600; color: var(--text); }
.col-admin .a-role { font-size: 11px; }
.badge-all { background: var(--primary); color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
</style>

<div class="section">
    <div class="section-header">
        <h3>权限矩阵（只读）</h3>
        <span class="badge-info">共 <?= count($admins) ?> 位管理员 · <?= array_sum(array_map('count', $perms)) ?> 项权限</span>
    </div>
    <div class="section-body">
        <p style="color:var(--text-secondary);font-size:13px;margin-bottom:14px;">
            下表展示每位管理员当前生效的权限。带「全部」标记的管理员在系统判定中拥有所有权限（不受逐项勾选限制）。
        </p>
        <div class="matrix-wrap">
            <table class="matrix">
                <thead>
                    <tr>
                        <th class="perm-name">权限 / 管理员</th>
                        <?php foreach ($admins as $a): ?>
                        <th class="col-admin">
                            <div class="a-name"><?= xss_clean($a['nickname'] ?: ('QQ:' . $a['qq'])) ?></div>
                            <div class="a-role">
                                <?php if ($a['role'] === 'super_admin'): ?><span class="badge-all">全部</span>
                                <?php else: ?><span class="badge-user">管理员</span><?php endif; ?>
                            </div>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($perms as $group => $items): ?>
                    <tr class="perm-group-row"><td class="perm-name" colspan="<?= count($admins) + 1 ?>"><?= xss_clean($group) ?></td></tr>
                    <?php foreach ($items as $key => $label): ?>
                    <tr>
                        <td class="perm-name" title="<?= xss_clean($key) ?>"><?= xss_clean($label) ?></td>
                        <?php foreach ($admins as $a): ?>
                            <?php $granted = ($a['role'] === 'super_admin') || isset($adminPerms[$a['id']][$key]); ?>
                            <td class="<?= $granted ? 'cell-yes' : 'cell-no' ?>"><?= $granted ? '✓' : '—' ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
