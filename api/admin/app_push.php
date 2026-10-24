<?php
/**
 * 后台：App 系统通知推送（读写）
 * ---------------------------------------------------------------------------
 * action         方法   权限                 说明
 *   stats        GET    view_app_devices     装机概览（总量 / 今日 / 近7日 / 版本分布）
 *   list         GET    view_app_devices     推送历史
 *   create       POST   manage_app_push      新建一条推送
 *   delete       POST   manage_app_push      删除一条
 *   clear        POST   manage_app_push      清空历史
 *
 * 推送的实际投递方式见 includes/app_push.php 顶部注释：客户端轮询 + 本地系统通知
 * （共享主机上没有可用的推送服务凭据）。因此创建成功只代表「已入队」，
 * 真正弹到用户手机上要等 App 下一次后台轮询。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/app_push.php';

header('Content-Type: application/json; charset=utf-8');

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!empty($admin['is_banned'])) {
    jsonError('账号已被封禁');
}

$action = $_REQUEST['action'] ?? 'list';
$isWrite = in_array($action, ['create', 'delete', 'clear'], true);

// 所有写操作必须 POST + CSRF（对齐 api/admin/comments.php 的既有范例）
if ($isWrite) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('请求方法不允许', 405);
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonError('CSRF验证失败', 403);
    }
}

$fs = getFS();

/**
 * 读取类（stats / list）的权限判定。
 *
 * 之所以放开「能推送的人也能看」：这两个能力同属一个后台分组，
 * 若只给 manage_app_push 的管理员打开页面却看不到推送历史，界面会莫名空白。
 * 反过来 view_app_devices（只读观察者）不能借此发推送 —— 写操作仍各自校验。
 */
$canView = checkPermission($admin, 'view_app_devices') || checkPermission($admin, 'manage_app_push');

if ($action === 'stats') {
    if (!$canView) {
        jsonError('没有权限查看 App 装机情况', 403);
    }
    jsonSuccess(['stats' => lwAppStats($fs)]);
}

if ($action === 'list') {
    if (!$canView) {
        jsonError('没有权限查看推送记录', 403);
    }
    $list = lwAppPushList($fs, 200);
    jsonSuccess(['pushes' => $list, 'total' => count($list)]);
}

if ($action === 'create') {
    if (!checkPermission($admin, 'manage_app_push')) {
        jsonError('没有权限推送系统通知', 403);
    }

    $title   = trim((string)($_POST['title'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $url     = trim((string)($_POST['url'] ?? ''));
    $target  = trim((string)($_POST['target'] ?? ''));

    if ($title === '') {
        jsonError('请填写通知标题');
    }
    if (mb_strlen($title) > 40) {
        jsonError('标题不能超过 40 个字符');
    }
    if ($content === '') {
        jsonError('请填写通知内容');
    }
    if (mb_strlen($content) > 300) {
        jsonError('内容不能超过 300 个字符');
    }
    // 跳转地址只允许站内相对路径 —— 否则这条通知会变成现成的钓鱼跳板。
    // 两个坑都在这一行里：
    //   ① 字符类里的 `#` 必须转义：分隔符也是 `#`，不转义会把模式提前闭合，
    //      报 "Unknown modifier ']'" 并返回 false → 所有合法地址都被拒（实测踩过）；
    //   ② 必须排除 `//host` 形态：协议相对 URL 同样以 `/` 开头，客户端一旦原样跳转
    //      就是跳到外部站点。用负向断言 (?!/) 卡掉第二个斜杠。
    if ($url !== '' && !preg_match('#^/(?!/)[A-Za-z0-9_\-./?=&%\#]*$#', $url)) {
        jsonError('跳转地址只能填站内相对路径（以 / 开头）');
    }

    // target：逗号分隔的 user_id；留空 = 发给全部已装 App 的用户
    $targetClean = '';
    if ($target !== '') {
        $ids = array_filter(array_map('intval', explode(',', $target)), fn($n) => $n > 0);
        if ($ids === []) {
            jsonError('指定的用户 ID 不合法');
        }
        $targetClean = implode(',', array_slice(array_unique($ids), 0, 200));
    }

    $row = $fs->insertCapped('app_pushes', [
        'title'      => $title,
        'content'    => $content,
        'url'        => $url,
        'target'     => $targetClean,
        'admin_id'   => (int)$admin['id'],
        'admin_name' => (string)($admin['nickname'] ?? ''),
    ], LW_APP_PUSH_MAX);

    if (!$row) {
        jsonError('推送创建失败，请稍后重试', 500);
    }

    logOperation(
        (int)$admin['id'],
        (string)($admin['qq'] ?? ''),
        'app_push_create',
        'app_pushes',
        (string)($row['id'] ?? ''),
        $targetClean === '' ? "全体：{$title}" : "指定用户({$targetClean})：{$title}"
    );

    jsonSuccess(
        ['push' => $row],
        $targetClean === '' ? '推送已创建，App 下次轮询时即为全体用户弹通知' : '推送已创建，仅指定的用户会收到'
    );
}

if ($action === 'delete') {
    if (!checkPermission($admin, 'manage_app_push')) {
        jsonError('没有权限删除推送', 403);
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID 无效');
    }
    $row = $fs->findById('app_pushes', $id);
    if (!$row) {
        jsonError('推送不存在', 404);
    }
    $fs->delete('app_pushes', $id);
    logOperation((int)$admin['id'], (string)($admin['qq'] ?? ''), 'app_push_delete', 'app_pushes', (string)$id, (string)($row['title'] ?? ''));
    jsonSuccess([], '已删除');
}

if ($action === 'clear') {
    if (!checkPermission($admin, 'manage_app_push')) {
        jsonError('没有权限清空推送', 403);
    }
    $n = count($fs->read('app_pushes'));
    $fs->write('app_pushes', []);
    logOperation((int)$admin['id'], (string)($admin['qq'] ?? ''), 'app_push_clear', 'app_pushes', '', "清空 {$n} 条");
    jsonSuccess(['removed' => $n], "已清空 {$n} 条推送记录");
}

jsonError('未知操作');
