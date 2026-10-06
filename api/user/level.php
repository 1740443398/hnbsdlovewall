<?php
/**
 * 我的成长等级接口。
 *
 * GET  action=me        我的等级信息 + 排名（默认）
 * GET  action=rank      经验榜前 N 名
 * GET  action=rules     经验规则表（展示「怎么涨经验」）
 *
 * 说明：等级是只读数据，由业务动作被动累加（发帖/评论/签到…），
 * 所以本接口**不提供任何写操作**，也就不需要 CSRF。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/level.php';
require_once __DIR__ . '/../../includes/achievements.php';

$user = requireLogin();
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    jsonError('账号已被封禁');
}

$action = $_REQUEST['action'] ?? 'me';

if ($action === 'rules') {
    $rows = [];
    foreach (lwLevelRules() as $key => $r) {
        $rows[] = [
            'key'    => $key,
            'label'  => $r['label'],
            'exp'    => (int)$r['exp'],
        ];
    }
    jsonSuccess([
        'enabled'     => lwLevelEnabled(),
        'daily_cap'   => lwLevelDailyCap(),
        'max_level'   => LW_LEVEL_MAX,
        'rules'       => $rows,
        'level_table' => array_map(function ($n) {
            return [
                'level' => $n,
                'title' => lwLevelTitle($n),
                'exp'   => lwExpForLevel($n),
            ];
        }, range(1, min(10, LW_LEVEL_MAX))),
    ]);
}

if ($action === 'rank') {
    $limit = max(3, min(50, (int)($_REQUEST['limit'] ?? 10)));
    $top = lwLevelTop($limit);

    // 补昵称与头像（一次读全表，避免逐个查）
    $byId = [];
    foreach ((array)getFS()->read('users') as $u) {
        $byId[(int)($u['id'] ?? 0)] = $u;
    }

    $list = [];
    $pos = 0;
    foreach ($top as $t) {
        $pos++;
        $u = $byId[$t['user_id']] ?? null;
        if (!$u) {
            continue;                                   // 用户已删除，跳过（不占名次）
        }
        if (!empty($u['is_banned'])) {
            continue;
        }
        $info = lwLevelProgress($t['exp']);
        $list[] = [
            'rank'     => $pos,
            'user_id'  => $t['user_id'],
            'nickname' => $u['nickname'] ?: ('QQ:' . $u['qq']),
            'qq'       => $u['qq'] ?? '',
            'avatar'   => $u['avatar'] ?: getQQAvatar($u['qq'] ?? ''),
            'exp'      => $t['exp'],
            'level'    => $info['level'],
            'title'    => $info['title'],
            'is_me'    => (int)$t['user_id'] === (int)$user['id'],
        ];
    }

    jsonSuccess(['list' => $list]);
}

// 默认：我的等级
$mine = lwLevelRow((int)$user['id']);
[$rank, $total] = lwLevelRank((int)$user['id']);
$ach = lwAchievementSummary((int)$user['id']);

jsonSuccess([
    'enabled'     => lwLevelEnabled(),
    'level'       => $mine['level'],
    'title'       => $mine['title'],
    'exp'         => $mine['exp'],
    'level_start' => $mine['level_start'],
    'level_end'   => $mine['level_end'],
    'progress'    => $mine['progress'],
    'to_next'     => $mine['to_next'],
    'percent'     => $mine['percent'],
    'is_max'      => $mine['is_max'],
    'today_exp'   => $mine['today_exp'],
    'daily_cap'   => lwLevelDailyCap(),
    'rank'        => $rank,
    'rank_total'  => $total,
    'achievements' => [
        'unlocked' => $ach['unlocked'],
        'total'    => $ach['total'],
        'percent'  => $ach['percent'],
    ],
]);
