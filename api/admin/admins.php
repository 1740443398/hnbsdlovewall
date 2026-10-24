<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/sort_util.php';

$admin = requireSuperAdmin();
$admin = checkBanned($admin);
if ($admin['is_banned']) {
    jsonError('账号已被封禁');
}

$fs = getFS();

// 先做请求方法与 CSRF 校验，再分发任何动作（含只读的 list），
// 避免 list 在无 CSRF 令牌情况下也能被触发。
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

$action = $_POST['action'] ?? '';

if ($action === 'list') {
    $admins = $fs->find('users', ['role' => 'admin']);
    $superAdmins = $fs->find('users', ['role' => 'super_admin']);
    $allAdmins = array_merge($superAdmins, $admins);

    lwSortByCreatedAtDesc($allAdmins);

    $result = [];
    foreach ($allAdmins as $a) {
        $permissions = [];
        if ($a['role'] !== 'super_admin') {
            $perms = $fs->find('admin_permissions', ['user_id' => $a['id']]);
            $permissions = array_column($perms, 'permission_key');
        }
        $result[] = [
            'id' => $a['id'],
            'qq' => $a['qq'],
            'nickname' => $a['nickname'],
            'avatar' => $a['avatar'],
            'role' => $a['role'],
            'perm_mode' => getPermissionMode($a),
            'permissions' => $permissions,
            'created_at' => $a['created_at']
        ];
    }

    $permissionGroups = getAllPermissionDefinitions();

    jsonSuccess([
        'admins' => $result,
        'permission_groups' => $permissionGroups,
        'perm_templates' => getPermissionTemplates(),
        'perm_mode_labels' => [
            'super' => '站长',
            'custom' => '自定义',
            't1' => '模版T1',
            't2' => '模版T2',
            't3' => '模版T3',
        ]
    ]);
}

if ($action === 'add') {
    $qq = sanitizeInput($_POST['qq'] ?? '');
    $permissionsJson = $_POST['permissions'] ?? '[]';
    $permissions = json_decode($permissionsJson, true) ?: [];
    $permMode = sanitizeInput($_POST['perm_mode'] ?? 'custom');
    if (!isset(PERMISSION_TEMPLATES[$permMode])) {
        $permMode = 'custom';
    }

    $validPermissions = [];
    foreach (getAllPermissionDefinitions() as $group) {
        foreach ($group as $key => $desc) {
            $validPermissions[] = $key;
        }
    }
    $permissions = array_intersect($permissions, $validPermissions);

    if (!isValidQQ($qq)) {
        jsonError('QQ号格式不正确');
    }

    $existing = $fs->findOne('users', ['qq' => $qq]);
    if ($existing) {
        if ($existing['role'] === 'super_admin') {
            jsonError('该用户已是超级管理员');
        }
        $fs->update('users', $existing['id'], ['role' => 'admin']);
        $userId = $existing['id'];

        $oldPerms = $fs->find('admin_permissions', ['user_id' => $userId]);
        foreach ($oldPerms as $p) {
            $fs->delete('admin_permissions', $p['id']);
        }
    } else {
        jsonError('该QQ号尚未注册，请先注册后再添加为管理员');
    }

    if ($permMode === 'custom') {
        foreach ($permissions as $perm) {
            $fs->insert('admin_permissions', ['user_id' => $userId, 'permission_key' => $perm]);
        }
        $fs->update('users', $userId, ['perm_mode' => 'custom']);
    } else {
        applyPermissionTemplate($userId, $permMode);
    }

    logOperation($admin['id'], $admin['qq'], 'add_admin', 'user', $userId, '新增管理员（权限模式：' . $permMode . '）');
    jsonSuccess([], '管理员添加成功');
}

if ($action === 'delete') {
    $userId = intval($_POST['admin_id'] ?? $_POST['user_id'] ?? 0);

    if (!$userId) {
        jsonError('用户ID无效');
    }

    $user = $fs->findById('users', $userId);
    if (!$user || ($user['role'] !== 'admin' && $user['role'] !== 'super_admin')) {
        jsonError('管理员不存在');
    }

    if ($user['role'] === 'super_admin') {
        jsonError('无法删除超级管理员');
    }

    $fs->update('users', $userId, ['role' => 'user', 'perm_mode' => 'custom']);
    $permissions = $fs->find('admin_permissions', ['user_id' => $userId]);
    foreach ($permissions as $p) {
        $fs->delete('admin_permissions', $p['id']);
    }

    logOperation($admin['id'], $admin['qq'], 'delete_admin', 'user', $userId, '删除管理员');
    jsonSuccess([], '管理员删除成功');
}

if ($action === 'edit_permissions') {
    $userId = intval($_POST['admin_id'] ?? $_POST['user_id'] ?? 0);
    $permissionsJson = $_POST['permissions'] ?? '[]';
    $permissions = json_decode($permissionsJson, true) ?: [];
    $permMode = sanitizeInput($_POST['perm_mode'] ?? 'custom');
    if (!isset(PERMISSION_TEMPLATES[$permMode])) {
        $permMode = 'custom';
    }

    $validPermissions = [];
    foreach (getAllPermissionDefinitions() as $group) {
        foreach ($group as $key => $desc) {
            $validPermissions[] = $key;
        }
    }
    $permissions = array_intersect($permissions, $validPermissions);

    if (!$userId) {
        jsonError('用户ID无效');
    }

    $user = $fs->findById('users', $userId);
    if (!$user || $user['role'] !== 'admin') {
        jsonError('管理员不存在');
    }

    if ($permMode === 'custom') {
        $existing = $fs->find('admin_permissions', ['user_id' => $userId]);
        foreach ($existing as $p) {
            $fs->delete('admin_permissions', $p['id']);
        }

        foreach ($permissions as $perm) {
            $fs->insert('admin_permissions', ['user_id' => $userId, 'permission_key' => $perm]);
        }
        $fs->update('users', $userId, ['perm_mode' => 'custom']);
    } else {
        applyPermissionTemplate($userId, $permMode);
    }

    logOperation($admin['id'], $admin['qq'], 'update_admin_permissions', 'user', $userId, '更新管理员权限（权限模式：' . $permMode . '）');
    jsonSuccess([], '权限更新成功');
}

jsonError('未知操作');