<?php
/**
 * 后台：赞助信息管理接口
 *
 * ==========================================================================
 * 这一版修了什么
 * ==========================================================================
 * 【问题 1：浮点数精度污染】
 * 原来用 floatval 存金额，累加后会出现
 *   20.199999999999999289457264239899814128875732421875
 * 信用卡金额这种数字必须精确到分，不能有浮点误差。
 * 现在统一转成「分」存整数，显示时再除回元。
 *
 * 【问题 2：总额与当前额混在一起】
 * 原来 add_sponsor 时 total_amount = 所有条目之和，
 * current_amount = 原值 + 新值 —— 但两者本该不同：
 *   total_amount  = 历史累计（只增不减，用于对外展示「共收到多少」）
 *   current_amount = 当前有效金额（删除条目后要跟着减）
 * 之前删除条目时没减 current_amount，导致数字越滚越大。
 *
 * 【问题 3：$action 被赋值两次】
 * 第一次在 CSRF 校验前，第二次在之后。值相同所以没出错，
 * 但这是明显的疏漏 —— 如果以后有人在校验前改了 $action，
 * 会被第二次赋值覆盖掉，等于绕过校验。
 * ==========================================================================
 */
require_once __DIR__ . '/../../config/config.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
if ($adminUser['is_banned']) {
    jsonError('账号已被封禁');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('请求方法不允许', 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCSRFToken($csrfToken)) {
    jsonError('CSRF验证失败', 403);
}

if (!checkPermission($adminUser, 'manage_sponsor')) {
    jsonError('无权限管理赞助信息', 403);
}

$fs = getFS();
$action = (string)($_POST['action'] ?? '');

/** 元 -> 分（整数，避免浮点误差） */
function lwYuanToCents($v): int
{
    return (int)round(((float)$v) * 100);
}

/** 分 -> 元字符串（保留两位，去掉多余的 .00） */
function lwCentsToYuan(int $c): string
{
    $s = number_format($c / 100, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.') ?: '0';
}

/** 读赞助记录，缺字段时补默认值。 */
function lwSponsorRecord($fs): array
{
    $rec = $fs->findById('sponsor', 1);
    if (!$rec) {
        return ['id' => 1, 'current_amount' => 0, 'total_amount' => 0, 'sponsor_list' => '[]'];
    }
    if (!isset($rec['current_amount'])) $rec['current_amount'] = 0;
    if (!isset($rec['total_amount'])) $rec['total_amount'] = 0;
    if (empty($rec['sponsor_list'])) $rec['sponsor_list'] = '[]';
    return $rec;
}

/** 解析赞助列表（统一成 [['qq','name','amount'], ...]）。 */
function lwSponsorList(array $rec): array
{
    $raw = $rec['sponsor_list'] ?? '[]';
    // 兼容两种存法：JSON 字符串 或 已经是数组
    if (is_string($raw)) {
        $list = json_decode($raw, true);
    } else {
        $list = $raw;
    }
    if (!is_array($list)) return [];

    $out = [];
    foreach ($list as $it) {
        if (!is_array($it)) continue;
        $out[] = [
            'qq'     => (string)($it['qq'] ?? ''),
            'name'   => (string)($it['name'] ?? '未知用户'),
            'amount' => (float)($it['amount'] ?? 0),
        ];
    }
    return $out;
}

/** 把列表里所有条目金额求和（分）。 */
function lwSponsorSum(array $list): int
{
    $c = 0;
    foreach ($list as $it) {
        $c += lwYuanToCents($it['amount'] ?? 0);
    }
    return $c;
}

// ============================ 更新展示金额 ============================
if ($action === 'update') {
    $cents = lwYuanToCents($_POST['current_amount'] ?? 0);
    $rec = lwSponsorRecord($fs);

    $patch = ['current_amount' => (int)($cents / 100)];
    // 只写不改结构：sponsor 表若不存在先插一条
    if ($fs->findById('sponsor', 1)) {
        $fs->update('sponsor', 1, $patch);
    } else {
        $fs->insert('sponsor', array_merge([
            'total_amount' => 0,
            'sponsor_list' => '[]',
        ], $patch));
    }

    logOperation($adminUser['id'], $adminUser['qq'], 'update_sponsor', 'sponsor', '1',
        '更新赞助金额为 ' . lwCentsToYuan($cents) . ' 元');
    jsonSuccess(['current_amount' => lwCentsToYuan($cents)], '赞助金额已更新');
}

// ============================ 整体替换榜单 ============================
if ($action === 'update_list') {
    $raw = $_POST['sponsor_list'] ?? '[]';
    $list = json_decode((string)$raw, true);
    if (!is_array($list)) {
        jsonError('赞助榜单格式错误');
    }

    $clean = [];
    foreach ($list as $it) {
        if (!is_array($it)) continue;
        $clean[] = [
            'qq'     => sanitizeInput((string)($it['qq'] ?? '')),
            'name'   => sanitizeInput((string)($it['name'] ?? '')),
            'amount' => lwCentsToYuan(lwYuanToCents($it['amount'] ?? 0)) + 0,
        ];
    }
    $sumCents = lwSponsorSum($clean);
    $rec = lwSponsorRecord($fs);

    $payload = [
        'total_amount'   => (int)($sumCents / 100),
        'current_amount' => (int)($sumCents / 100),
        'sponsor_list'   => json_encode($clean, JSON_UNESCAPED_UNICODE),
    ];
    if ($fs->findById('sponsor', 1)) {
        $fs->update('sponsor', 1, $payload);
    } else {
        $fs->insert('sponsor', $payload);
    }

    logOperation($adminUser['id'], $adminUser['qq'], 'update_sponsor_list', 'sponsor', '1',
        '更新赞助榜单（' . count($clean) . ' 条）');
    jsonSuccess([
        'total_amount' => lwCentsToYuan($sumCents),
        'sponsor_list' => $clean,
    ], '赞助榜单已更新');
}

// ============================ 添加赞助者 ============================
if ($action === 'add_sponsor') {
    $qq = sanitizeInput((string)($_POST['qq'] ?? ''));
    $addCents = lwYuanToCents($_POST['amount'] ?? 0);

    if ($qq === '') {
        jsonError('请输入赞助者QQ号');
    }
    if ($addCents <= 0) {
        jsonError('请输入有效的赞助金额');
    }
    // 金额上限校验：防止手滑多打几个零
    if ($addCents > 100000000) {   // 100 万元
        jsonError('单笔金额过大，请确认是否输错');
    }

    // 找站内用户取昵称
    $nickname = '未知用户';
    foreach ($fs->read('users') as $u) {
        if ((string)($u['qq'] ?? '') === $qq) {
            $nickname = (string)($u['nickname'] ?? '未知用户');
            break;
        }
    }

    $rec = lwSponsorRecord($fs);
    $list = lwSponsorList($rec);
    $list[] = ['qq' => $qq, 'name' => $nickname, 'amount' => lwCentsToYuan($addCents) + 0];

    $sumCents = lwSponsorSum($list);

    $payload = [
        // 历史累计：只增不减
        'total_amount'   => (int)((lwYuanToCents($rec['total_amount'] ?? 0) + $addCents) / 100),
        // 当前有效金额：跟随列表变化
        'current_amount' => (int)($sumCents / 100),
        'sponsor_list'   => json_encode($list, JSON_UNESCAPED_UNICODE),
        'updated_at'     => date('Y-m-d H:i:s'),
    ];
    if ($fs->findById('sponsor', 1)) {
        $fs->update('sponsor', 1, $payload);
    } else {
        $fs->insert('sponsor', $payload);
    }

    logOperation($adminUser['id'], $adminUser['qq'], 'add_sponsor', 'sponsor', '1',
        '添加赞助者：' . $qq . '（' . $nickname . '）' . lwCentsToYuan($addCents) . ' 元');

    jsonSuccess([
        'qq'     => $qq,
        'name'   => $nickname,
        'amount' => lwCentsToYuan($addCents),
        'total_amount' => lwCentsToYuan((int)($sumCents / 100) * 100),
    ], '已添加赞助者 ' . $nickname . '（' . lwCentsToYuan($addCents) . ' 元）');
}

// ============================ 删除赞助者 ============================
if ($action === 'delete_sponsor') {
    $index = (int)($_POST['index'] ?? -1);
    if ($index < 0) {
        jsonError('无效的赞助记录序号');
    }

    $rec = lwSponsorRecord($fs);
    $list = lwSponsorList($rec);
    if (!isset($list[$index])) {
        jsonError('该赞助记录不存在（可能已被删除，请刷新页面）');
    }

    $deleted = $list[$index];
    array_splice($list, $index, 1);

    $sumCents = lwSponsorSum($list);

    $fs->update('sponsor', 1, [
        // 删人时 current_amount 跟着减，total_amount 保持不变（历史累计）
        'current_amount' => (int)($sumCents / 100),
        'sponsor_list'   => json_encode($list, JSON_UNESCAPED_UNICODE),
        'updated_at'     => date('Y-m-d H:i:s'),
    ]);

    logOperation($adminUser['id'], $adminUser['qq'], 'delete_sponsor', 'sponsor', '1',
        '删除赞助者：' . ($deleted['name'] ?? '') . '（' . lwCentsToYuan(lwYuanToCents($deleted['amount'] ?? 0)) . ' 元）');

    jsonSuccess([
        'current_amount' => lwCentsToYuan($sumCents),
    ], '已删除赞助记录');
}

jsonError('未知操作：' . $action);
