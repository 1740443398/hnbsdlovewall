<?php
/**
 * 后台 —— 成长体系管理接口。
 *
 * action=overview        等级规则 + 全站分布 + 经验榜 + 成就解锁统计
 * action=save_rules      保存经验规则 / 每日上限 / 总开关
 * action=user_detail     某用户的等级与成就明细
 * action=reset_user      重置某用户的等级与成就（清空重来，需 manage_growth）
 * action=recalc          按当前规则重算全部用户的等级缓存字段
 *
 * 权限：manage_growth（重置与重算额外要求非 T1，由权限码本身区分）。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/level.php';
require_once __DIR__ . '/../../includes/achievements.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}

// 后台接口一律要求 CSRF（读接口也不例外：后台面板含用户隐私聚合数据）
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonError('CSRF验证失败', 403);
}

if (!checkPermission($admin, 'manage_growth')) {
    jsonError('无权限管理成长体系', 403);
}

$fs     = getFS();
$action = $_POST['action'] ?? 'overview';

// ---------------------------------------------------------------- 总览
if ($action === 'overview') {
    $rows = (array)$fs->read('user_levels');

    // 等级分布
    $dist = [];
    $expTop = [];
    foreach ($rows as $r) {
        $e = (int)($r['exp'] ?? 0);
        if ($e < 0) {
            continue;
        }
        $lv = lwLevelFromExp($e);
        $dist[$lv] = ($dist[$lv] ?? 0) + 1;
        $expTop[] = ['user_id' => (int)($r['user_id'] ?? 0), 'exp' => $e, 'level' => $lv];
    }
    ksort($dist);
    usort($expTop, function ($a, $b) { return $b['exp'] <=> $a['exp']; });
    $expTop = array_slice($expTop, 0, 15);

    // 补昵称
    $byId = [];
    foreach ((array)$fs->read('users') as $u) {
        $byId[(int)($u['id'] ?? 0)] = $u;
    }
    foreach ($expTop as &$t) {
        $u = $byId[$t['user_id']] ?? null;
        $t['nickname'] = $u ? ($u['nickname'] ?: ('QQ:' . $u['qq'])) : '（已注销）';
        $t['qq'] = $u['qq'] ?? '';
    }
    unset($t);

    // 成就解锁统计
    $unlockCount = [];
    $totalUnlocks = 0;
    foreach ((array)$fs->read('user_achievements') as $row) {
        if (!is_array($row['unlocked'] ?? null)) {
            continue;
        }
        foreach ($row['unlocked'] as $k => $ts) {
            $unlockCount[$k] = ($unlockCount[$k] ?? 0) + 1;
            $totalUnlocks++;
        }
    }
    $achStats = [];
    foreach (lwAchievementDefs() as $def) {
        $achStats[] = [
            'key'      => $def['key'],
            'name'     => $def['name'],
            'icon'     => $def['icon'],
            'group'    => $def['group'],
            'desc'     => $def['desc'],
            'unlocked' => (int)($unlockCount[$def['key']] ?? 0),
        ];
    }
    usort($achStats, function ($a, $b) { return $b['unlocked'] <=> $a['unlocked']; });

    // 规则表
    $rules = [];
    foreach (lwLevelRules() as $k => $r) {
        $rules[] = ['key' => $k, 'label' => $r['label'], 'exp' => (int)$r['exp']];
    }

    jsonSuccess([
        'enabled'        => lwLevelEnabled(),
        'daily_cap'      => lwLevelDailyCap(),
        'max_level'      => LW_LEVEL_MAX,
        'rules'          => $rules,
        'distribution'   => $dist,
        'exp_top'        => $expTop,
        'achievements'   => $achStats,
        'total_users'    => count($rows),
        'total_unlocks'  => $totalUnlocks,
        'level_titles'   => array_map(function ($n) {
            return ['level' => $n, 'title' => lwLevelTitle($n), 'exp' => lwExpForLevel($n)];
        }, range(1, min(12, LW_LEVEL_MAX))),
    ]);
}

// ---------------------------------------------------------------- 保存规则
if ($action === 'save_rules') {
    $enabled = ($_POST['enabled'] ?? '1') === '1';
    $cap     = (int)($_POST['daily_cap'] ?? 200);
    if ($cap < 1 || $cap > 100000) {
        jsonError('每日经验上限需在 1–100000 之间');
    }

    $defaults = lwLevelDefaults();
    $incoming = json_decode((string)($_POST['rules'] ?? '[]'), true);
    if (!is_array($incoming)) {
        jsonError('规则格式不正确');
    }

    $clean = [];
    foreach ($incoming as $item) {
        $k = (string)($item['key'] ?? '');
        if (!isset($defaults[$k])) {
            continue;                                    // 不认识的键直接丢弃，避免写入脏数据
        }
        $v = (int)($item['exp'] ?? 0);
        $clean[$k] = max(0, min(10000, $v));
    }
    if (!$clean) {
        jsonError('至少要保留一条经验规则');
    }

    updateSetting('level_enabled', $enabled ? '1' : '0');
    updateSetting('level_daily_cap', (string)$cap);
    updateSetting('level_exp_rules', json_encode($clean, JSON_UNESCAPED_UNICODE));

    logOperation($admin['id'], $admin['qq'], 'save_growth_rules', 'settings', '', '更新成长体系规则');
    jsonSuccess(['rules' => $clean], '已保存，立即生效');
}

// ---------------------------------------------------------------- 用户明细
if ($action === 'user_detail') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) {
        jsonError('缺少用户 ID');
    }
    $u = $fs->findById('users', $uid);
    if (!$u) {
        jsonError('用户不存在', 404);
    }
    $info = lwLevelRow($uid);
    $ach  = lwAchievementSummary($uid);
    [$rank, $total] = lwLevelRank($uid);

    jsonSuccess([
        'user' => [
            'id'       => $uid,
            'nickname' => $u['nickname'] ?: ('QQ:' . $u['qq']),
            'qq'       => $u['qq'],
        ],
        'level'  => [
            'level' => $info['level'], 'title' => $info['title'], 'exp' => $info['exp'],
            'rank' => $rank, 'rank_total' => $total, 'percent' => $info['percent'],
            'today_exp' => $info['today_exp'],
        ],
        'achievements' => $ach,
    ]);
}

// ---------------------------------------------------------------- 重置用户
if ($action === 'reset_user') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) {
        jsonError('缺少用户 ID');
    }
    if ($uid === (int)$admin['id']) {
        jsonError('不能重置自己的成长数据');
    }

    // 只删成长数据，不碰用户的帖子/评论 —— 重置是「清空积分」而不是「删号」
    $row = $fs->findOne('user_levels', ['user_id' => $uid]);
    if ($row) {
        $fs->delete('user_levels', $row['id']);
    }
    $arow = $fs->findOne('user_achievements', ['user_id' => $uid]);
    if ($arow) {
        $fs->delete('user_achievements', $arow['id']);
    }

    logOperation($admin['id'], $admin['qq'], 'reset_growth', 'user', $uid, '重置用户成长数据');
    jsonSuccess([], '已重置该用户的等级与成就');
}

// ---------------------------------------------------------------- 重算等级缓存
if ($action === 'recalc') {
    $rows = (array)$fs->read('user_levels');
    $fixed = 0;
    foreach ($rows as $r) {
        $exp = (int)($r['exp'] ?? 0);
        $lv  = lwLevelFromExp($exp);
        if ((int)($r['level'] ?? 0) !== $lv) {
            if ($fs->update('user_levels', $r['id'], ['level' => $lv])) {
                $fixed++;
            }
        }
    }
    logOperation($admin['id'], $admin['qq'], 'recalc_growth', 'settings', '', '重算等级缓存，修正 ' . $fixed . ' 条');
    jsonSuccess(['fixed' => $fixed, 'total' => count($rows)], $fixed > 0 ? ('已修正 ' . $fixed . ' 条等级缓存') : '所有等级缓存均一致，无需修正');
}

jsonError('未知操作');
