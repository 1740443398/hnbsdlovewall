<?php
/**
 * 文件传输助手 API
 *
 * 所有动作走一个入口：action=create|join|poll|upload|download|pick|leave|text
 *
 * =====================================================================
 * 为什么合成一个文件
 * =====================================================================
 * 免费主机的 FTP 上传很慢，一个文件比五个文件少四次往返。
 * 而且这些动作共享房间读写逻辑，拆开会重复 require。
 *
 * =====================================================================
 * 安全边界
 * =====================================================================
 * - 无登录，故所有入口都做房间码校验 + 体积限制
 * - 下载走一次性令牌，防止别人猜房间码偷文件
 * - 中转文件 60 秒后清理，下载中自动续期
 * =====================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/filehelper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

/**
 * 把前端传来的 role 归一化成 a / b。
 *
 * 前端历史上用过 pc / mobile，现在统一用 a / b，
 * 但仍然接受旧值，避免用户开着旧页面时加入失败。
 */
function fhNormRole($r): string
{
    $r = strtolower((string)$r);
    if ($r === 'a' || $r === 'pc') {
        return 'a';
    }
    return 'b';
}

/* 统一读取请求体。
 *
 * 前端有两种发送方式：
 *   - FormData（上传文件）→ PHP 自动填好 $_FILES / $_POST
 *   - JSON（发文字、信令）→ PHP 不会自动解析，必须手动 json_decode
 *
 * 这里提前解析好放进 $FH_BODY，各分支直接用，避免每处重复。
 */
$FH_BODY = [];
$__ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($__ct, 'application/json') !== false) {
    $raw = @file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $FH_BODY = $decoded;
        }
    }
} elseif (!empty($_POST)) {
    $FH_BODY = $_POST;
}

/** 从 body / POST / GET 依次取参数。 */
function fhParam(string $key, $default = null)
{
    global $FH_BODY;
    if (isset($FH_BODY[$key])) {
        return $FH_BODY[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    return $default;
}

function fhOk(array $data = []): void
{
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function fhErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 从请求里取房间码并校验。 */
function fhRequireRoom(): array
{
    $code = strtoupper((string)($_REQUEST['code'] ?? ''));
    $code = preg_replace('/[^A-Z0-9]/', '', $code);
    if (strlen($code) !== 6) {
        fhErr('配对码格式不对');
    }
    $room = lwFhReadRoom($code);
    if (!$room) {
        fhErr('房间不存在或已过期', 404);
    }
    return $room;
}

switch ($action) {

    // =================================================================
    // 创建房间（电脑端）
    // =================================================================
    case 'create': {
        // 每次建房间顺手清理一次（内部是概率触发，不会每次都扫盘）
        lwFhCleanup();
        // 顺便重试网盘待删队列（同样概率触发）
        lwFhPendingFlush();
        $room = lwFhCreateRoom('a');
        $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
        fhOk([
            'code' => $room['code'],
            'expire_at' => $room['expire_at'],
            'join_url' => $base . '/filehelper?code=' . $room['code'],
        ]);
    }

    // =================================================================
    // 加入房间（手机端）
    // =================================================================
    case 'join': {
        $room = fhRequireRoom();
        $code = $room['code'];

        $role = fhNormRole($_REQUEST['role'] ?? 'b');
        $now = time();

        /* 【房间容量】
         *
         * 原来是「最多 2 人（电脑 + 手机）」，因为场景被限定成电脑 ↔ 手机。
         * 但实际需求是：任意两台能上网的设备都可能配对 ——
         * 手机↔手机、电脑↔电脑、平板↔电脑……角色根本不该是 pc/mobile。
         *
         * 所以现在用中性的 slot 名（a / b），最多 2 个端点。
         * 前端会把自己那侧叫「本机」、对面叫「对方」，不再出现「电脑」「手机」字样。
         */
        $peers = $room['peers'] ?? [];

        // 兼容旧房间里的 pc/mobile 键名
        $roleMap = ['pc' => 'a', 'mobile' => 'b'];
        if (isset($roleMap[$role])) {
            $role = $roleMap[$role];
        }

        // 已经有 2 个端点且当前角色不在其中 → 满
        if (!isset($peers[$role]) && count($peers) >= 2) {
            fhErr('这个配对码已经有人用了，请让对方重新生成', 409);
        }

        $peers[$role] = [
            'joined_at' => $peers[$role]['joined_at'] ?? $now,
            'last_seen' => $now,
            'ip' => lwFhClientIp(),
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        ];
        $room['peers'] = $peers;
        $room['last_active'] = $now;
        // 有人加入后延长房间寿命，避免正在传就过期
        $room['expire_at'] = max((int)$room['expire_at'], $now + LW_FH_ROOM_TTL);
        lwFhWriteRoom($code, $room);

        fhOk(['code' => $code, 'role' => $role, 'peers' => array_keys($peers)]);
    }

    // =================================================================
    // 轮询房间状态
    // =================================================================
    case 'poll': {
        $role = fhNormRole($_REQUEST['role'] ?? 'b');
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_REQUEST['code'] ?? '')));
        $room = lwFhReadRoom($code);
        if (!$room) {
            fhErr('房间已过期', 404);
        }

        // 更新自己的心跳（同时刷新房间过期时间，长传输期间不会被回收）
        $now = time();
        if (isset($room['peers'][$role])) {
            $room['peers'][$role]['last_seen'] = $now;
        }
        $room['last_active'] = $now;
        $room['expire_at'] = max((int)$room['expire_at'], $now + LW_FH_ROOM_TTL);
        lwFhWriteRoom($code, $room);

        $others = [];
        foreach ($room['peers'] as $r => $p) {
            if ($r !== $role) {
                $others[] = [
                    'role' => $r,
                    // 15 秒内有心跳算在线
                    'online' => ($now - (int)$p['last_seen']) < 15,
                ];
            }
        }

        $items = $room['items'] ?? [];
        // 只回传最近的，避免响应过大
        $items = array_slice($items, -40);

        fhOk([
            'code' => $code,
            'role' => $role,
            'peers' => $others,
            'items' => $items,
            'server_time' => $now,
        ]);
    }

    // =================================================================
    // 上传到服务器中转（≤20MB）
    // =================================================================
    case 'upload': {
        lwFhCleanup();
        $room = fhRequireRoom();
        $code = $room['code'];
        $role = fhNormRole($_REQUEST['role'] ?? 'b');

        if (empty($_FILES['file'])) {
            fhErr('没有收到文件');
        }
        $f = $_FILES['file'];
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE => '文件超过服务器上限（20MB）',
                UPLOAD_ERR_FORM_SIZE => '文件超过表单上限',
                UPLOAD_ERR_PARTIAL => '只传了一半，请重试',
                UPLOAD_ERR_NO_FILE => '没有选择文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不可用',
                UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
            ];
            fhErr($map[$f['error']] ?? '上传失败');
        }
        if ((int)$f['size'] > LW_FH_RELAY_MAX) {
            fhErr('中转文件不能超过 20MB');
        }

        lwFhEnsureDir();
        $id = bin2hex(random_bytes(8));
        $orig = (string)($f['name'] ?? 'file');
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext);
        $storeName = $id . ($ext !== '' ? '.' . substr($ext, 0, 8) : '');

        $dest = LW_FH_DIR . '/relay/' . $storeName;
        if (!@move_uploaded_file($f['tmp_name'], $dest)) {
            fhErr('保存失败，磁盘可能已满', 500);
        }

        $now = time();
        $item = [
            'id' => $id,
            'kind' => 'file',
            'channel' => 'relay',
            'name' => mb_substr($orig, 0, 120),
            'size' => (int)$f['size'],
            'from' => $role,
            'at' => $now,
            'expire_at' => $now + LW_FH_RELAY_TTL,
            'downloaded' => false,
            'locked' => false,
        ];

        // 写进房间列表
        $room['items'] = array_slice(array_merge($room['items'] ?? [], [$item]), -60);
        $room['last_active'] = $now;
        lwFhWriteRoom($code, $room);

        // 后台日志：记录 IP / 文件名 / 大小
        lwFhLog([
            'at' => date('Y-m-d H:i:s', $now),
            'ip' => lwFhClientIp(),
            'code' => $code,
            'role' => $role,
            'channel' => 'relay',
            'name' => $item['name'],
            'size' => $item['size'],
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
        ]);

        fhOk(['item' => $item]);
    }

    // =================================================================
    // 大文件中转（20MB~3GB，走小飞机网盘）
    // =================================================================
    case 'big': {
        $room = fhRequireRoom();
        $code = $room['code'];
        $role = fhNormRole($_REQUEST['role'] ?? 'b');

        require_once __DIR__ . '/../includes/feijipan.php';
        $fp = new LWFeijiPan();
        if (!$fp->isConfigured()) {
            fhErr('管理员还没有配置网盘，暂时只能传 20MB 以内的文件', 503);
        }

        if (empty($_FILES['file'])) {
            fhErr('没有收到文件');
        }
        $f = $_FILES['file'];
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            fhErr('上传失败（错误码 ' . ($f['error'] ?? '?') . '）');
        }

        $orig = (string)($f['name'] ?? 'file');
        $t0 = microtime(true);
        $res = $fp->upload($f['tmp_name'], $orig);
        $ms = round((microtime(true) - $t0) * 1000);

        if (!$res['ok']) {
            lwFhLog([
                'at' => date('Y-m-d H:i:s'),
                'ip' => lwFhClientIp(),
                'code' => $code,
                'role' => $role,
                'channel' => 'cloud',
                'name' => mb_substr($orig, 0, 120),
                'size' => (int)($f['size'] ?? 0),
                'fail' => $res['msg'],
            ]);
            fhErr('网盘上传失败：' . $res['msg'], 502);
        }

        $now = time();
        $item = [
            'id' => bin2hex(random_bytes(8)),
            'kind' => 'file',
            'channel' => 'cloud',
            'name' => mb_substr($orig, 0, 120),
            'size' => (int)($f['size'] ?? 0),
            'from' => $role,
            'at' => $now,
            'file_id' => $res['fileId'],
            'cloud_key' => $res['key'],
            'downloaded' => false,
        ];

        $room['items'] = array_slice(array_merge($room['items'] ?? [], [$item]), -60);
        $room['last_active'] = $now;
        lwFhWriteRoom($code, $room);

        lwFhLog([
            'at' => date('Y-m-d H:i:s', $now),
            'ip' => lwFhClientIp(),
            'code' => $code,
            'role' => $role,
            'channel' => 'cloud',
            'name' => $item['name'],
            'size' => $item['size'],
            'ms' => $ms,
            'file_id' => $res['fileId'],
        ]);

        fhOk(['item' => $item]);
    }

    // =================================================================
    // 【大文件直传】第一步：发上传凭证
    //
    // 文件不进我们服务器 —— InfinityFree 的 upload_max_filesize 只有 20M，
    // 超过就 403，PHP 连代码都跑不到。所以大文件让浏览器直传七牛。
    // =================================================================
    case 'big_prepare': {
        $room = fhRequireRoom();
        $role = fhNormRole($_REQUEST['role'] ?? 'a');
        lwFhPendingFlush();

        require_once __DIR__ . '/../includes/feijipan.php';
        $fp = new LWFeijiPan();
        if (!$fp->isConfigured()) {
            fhErr('管理员还没有配置网盘，暂时只能传 20MB 以内的文件', 503);
        }

        $name = trim((string)fhParam('name', ''));
        $size = (int)fhParam('size', 0);
        $md5 = trim((string)fhParam('md5', ''));

        if ($name === '') {
            fhErr('缺少文件名');
        }
        if ($size <= 0) {
            fhErr('文件大小无效');
        }
        if ($size > 3 * 1024 * 1024 * 1024) {
            fhErr('单个文件最大 3GB');
        }

        /* 先查容量再传。
         *
         * 免费版总容量只有 10MB，不先查的话用户传 100MB 会等半天
         * 然后收到一句「个人容量不足」，体验很差。
         */
        $q = $fp->quota();
        if ($q['ok'] && $q['total'] > 0 && $size > $q['free']) {
            $freeMb = round($q['free'] / 1048576, 1);
            $totalMb = round($q['total'] / 1048576, 1);
            fhErr('网盘剩余空间不够：这个文件需要 '
                . round($size / 1048576, 1) . 'MB，但只剩 ' . $freeMb
                . 'MB（免费版总共 ' . $totalMb . 'MB）。请清理网盘或改用小文件。', 507);
        }

        $prep = $fp->prepareUpload($name, $size, $md5, '');
        if (!$prep['ok']) {
            fhErr($prep['msg'], 502);
        }

        if (!empty($prep['fast'])) {
            $item = [
                'id' => bin2hex(random_bytes(8)),
                'kind' => 'file',
                'channel' => 'cloud',
                'name' => mb_substr($name, 0, 120),
                'size' => $size,
                'from' => $role,
                'at' => time(),
                'file_id' => $prep['fileId'],
                'cloud_key' => $prep['key'],
                'downloaded' => false,
            ];
            $room['items'] = array_slice(array_merge($room['items'] ?? [], [$item]), -60);
            $room['last_active'] = time();
            lwFhWriteRoom($room['code'], $room);

            lwFhLog([
                'at' => date('Y-m-d H:i:s'),
                'ip' => lwFhClientIp(),
                'code' => $room['code'],
                'role' => $role,
                'channel' => 'cloud',
                'name' => $item['name'],
                'size' => $item['size'],
                'fast' => 1,
            ]);

            fhOk(['fast' => true, 'item' => $item]);
        }

        fhOk([
            'fast' => false,
            'token' => $prep['token'],
            'key' => $prep['key'],
            'uploadUrl' => 'https://upload.qiniup.com',
        ]);
    }

    // =================================================================
    // 【大文件直传】第二步：浏览器传完后回报，登记到房间
    // =================================================================
    case 'big_finish': {
        $room = fhRequireRoom();
        $role = fhNormRole($_REQUEST['role'] ?? 'a');

        require_once __DIR__ . '/../includes/feijipan.php';
        $fp = new LWFeijiPan();

        $cbToken = trim((string)fhParam('token', ''));
        $name = trim((string)fhParam('name', ''));
        $size = (int)fhParam('size', 0);
        $key = trim((string)fhParam('key', ''));

        if ($cbToken === '') {
            fhErr('缺少回调凭证');
        }

        $fin = $fp->finishUpload($cbToken);
        if (!$fin['ok']) {
            lwFhLog([
                'at' => date('Y-m-d H:i:s'),
                'ip' => lwFhClientIp(),
                'code' => $room['code'],
                'role' => $role,
                'channel' => 'cloud',
                'name' => mb_substr($name, 0, 120),
                'size' => $size,
                'fail' => $fin['msg'],
            ]);
            fhErr('网盘落库失败：' . $fin['msg'], 502);
        }

        $item = [
            'id' => bin2hex(random_bytes(8)),
            'kind' => 'file',
            'channel' => 'cloud',
            'name' => mb_substr($name !== '' ? $name : (string)($fin['name'] ?? 'file'), 0, 120),
            'size' => $size,
            'from' => $role,
            'at' => time(),
            'file_id' => $fin['fileId'],
            'cloud_key' => $key,
            'downloaded' => false,
        ];

        $room['items'] = array_slice(array_merge($room['items'] ?? [], [$item]), -60);
        $room['last_active'] = time();
        lwFhWriteRoom($room['code'], $room);

        lwFhLog([
            'at' => date('Y-m-d H:i:s'),
            'ip' => lwFhClientIp(),
            'code' => $room['code'],
            'role' => $role,
            'channel' => 'cloud',
            'name' => $item['name'],
            'size' => $item['size'],
            'file_id' => $fin['fileId'],
        ]);

        fhOk(['item' => $item]);
    }

    // =================================================================
    // 取网盘文件的下载链接
    // =================================================================
    case 'cloud_url': {
        $room = fhRequireRoom();
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_REQUEST['id'] ?? ''));

        $target = null;
        foreach ($room['items'] ?? [] as $it) {
            if (($it['id'] ?? '') === $id && ($it['channel'] ?? '') === 'cloud') {
                $target = $it;
                break;
            }
        }
        if (!$target) {
            fhErr('文件不存在', 404);
        }

        require_once __DIR__ . '/../includes/feijipan.php';
        $fp = new LWFeijiPan();
        if (!$fp->isConfigured()) {
            fhErr('管理员还没有配置网盘', 503);
        }

        $url = $fp->downloadUrl((string)($target['file_id'] ?? ''));
        if ($url === '') {
            fhErr('网盘没有返回下载地址', 502);
        }

        $room['last_active'] = time();
        lwFhWriteRoom($room['code'], $room);

        lwFhLog([
            'at' => date('Y-m-d H:i:s'),
            'ip' => lwFhClientIp(),
            'code' => $room['code'],
            'role' => 'download',
            'channel' => 'cloud',
            'name' => $target['name'] ?? 'file',
            'size' => (int)($target['size'] ?? 0),
        ]);

        fhOk(['url' => $url, 'name' => $target['name'] ?? 'file']);
    }

    // =================================================================
    // 删除网盘文件（传完即焚）
    // =================================================================
    case 'cloud_cleanup': {
        $room = fhRequireRoom();
        $code = $room['code'];
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_REQUEST['id'] ?? ''));

        require_once __DIR__ . '/../includes/feijipan.php';
        $fp = new LWFeijiPan();

        $target = null;
        foreach ($room['items'] ?? [] as $it) {
            if (($it['id'] ?? '') === $id && ($it['channel'] ?? '') === 'cloud') {
                $target = $it;
                break;
            }
        }
        if (!$target) {
            fhErr('文件不存在', 404);
        }

        $fid = (string)($target['file_id'] ?? '');
        $ok = $fp->remove($fid);

        if ($ok) {
            $room['items'] = array_values(array_filter($room['items'], function ($it) use ($id) {
                return ($it['id'] ?? '') !== $id;
            }));
            lwFhWriteRoom($code, $room);
        } else {
            /* 删不掉就记账。
             *
             * 网盘的删除接口会被一个卡住的任务锁死整个账号，
             * 这时候单次调用必然失败。记进队列，等锁松了自动补删，
             * 免得文件永久堆在网盘里把额度吃光。
             */
            lwFhPendingAdd($fid);
        }
        fhOk(['deleted' => $ok, 'pending' => !$ok]);
    }

    // =================================================================
    // 领取下载令牌（防止猜码偷文件）
    // =================================================================
    case 'pick': {
        $room = fhRequireRoom();
        $code = $room['code'];
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_REQUEST['id'] ?? ''));

        $found = null;
        foreach ($room['items'] ?? [] as &$it) {
            if (($it['id'] ?? '') === $id) {
                $it['locked'] = true;
                $it['locked_at'] = time();
                $found = $it;
                break;
            }
        }
        unset($it);

        if (!$found) {
            fhErr('文件不存在或已被清理', 404);
        }

        // 过期判断必须在这里也做一遍 —— 前端是先 pick 拿令牌再 download，
        // 只在 download 拦会让「领取成功但下载 410」的体验很怪。
        $expAt = (int)($found['expire_at'] ?? 0);
        if ($expAt > 0 && $expAt < time()) {
            foreach (glob(LW_FH_DIR . '/relay/' . $id . '.*') ?: [] as $g) {
                @unlink($g);
            }
            $room['items'] = array_values(array_filter(
                $room['items'] ?? [],
                function ($it) use ($id) {
                    return ($it['id'] ?? '') !== $id;
                }
            ));
            lwFhWriteRoom($code, $room);
            fhErr('文件已过期（中转文件只保留 1 分钟）', 410);
        }

        if (!empty($found['downloaded'])) {
            fhErr('这个文件已经被下载过了', 410);
        }

        $room['items'] = $room['items'] ?? [];
        $room['last_active'] = time();
        lwFhWriteRoom($code, $room);

        fhOk([
            'url' => '/api/filehelper.php?action=download&code=' . $code . '&id=' . $id,
            'name' => $found['name'],
            'size' => $found['size'],
        ]);
    }

    // =================================================================
    // 下载中转文件（会把留存时间延长到下载完成）
    // =================================================================
    case 'download': {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_GET['code'] ?? '')));
        $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? ''));
        if (strlen($code) !== 6 || $id === '') {
            fhErr('参数不对');
        }

        $room = lwFhReadRoom($code);
        if (!$room) {
            fhErr('房间已过期', 404);
        }

        $target = null;
        foreach ($room['items'] ?? [] as $it) {
            if (($it['id'] ?? '') === $id) {
                $target = $it;
                break;
            }
        }
        if (!$target) {
            fhErr('文件已清理', 404);
        }

        /* ★ 必须先判过期，再决定要不要延期。
         *
         * 原来的顺序是「先无条件把 expire_at 延到 10 分钟后，再发文件」，
         * 等于任何一次下载都会给过期的文件续命 —— 用户点下载永远成功，
         * 「1 分钟自动删除」形同虚设。
         *
         * 正确逻辑：
         *   已经过期 → 立即删盘 + 删记录，返回 410
         *   还没过期 → 才开始下载，这时才延到 10 分钟（保护大文件传不完）
         */
        $now0 = time();
        $exp = (int)($target['expire_at'] ?? 0);
        if ($exp > 0 && $exp < $now0) {
            // 过期：立刻清理，不给任何下载机会
            $dirGone = LW_FH_DIR . '/relay/';
            foreach (glob($dirGone . $id . '.*') ?: [] as $g) {
                @unlink($g);
            }
            $room['items'] = array_values(array_filter(
                $room['items'] ?? [],
                function ($it) use ($id) {
                    return ($it['id'] ?? '') !== $id;
                }
            ));
            lwFhWriteRoom($code, $room);
            fhErr('文件已过期（中转文件只保留 1 分钟）', 410);
        }

        // 已经被下载过一次就不再给
        if (!empty($target['downloaded'])) {
            fhErr('这个文件已经被下载过了', 410);
        }

        // 找实际文件（扩展名不固定）
        $dir = LW_FH_DIR . '/relay/';
        $path = null;
        foreach (glob($dir . $id . '.*') ?: [] as $g) {
            $path = $g;
            break;
        }
        if (!$path || !is_file($path)) {
            fhErr('文件已清理', 410);
        }

        // 校验通过 → 标记已下载 + 锁定 + 延期 10 分钟
        foreach ($room['items'] as &$it) {
            if (($it['id'] ?? '') === $id) {
                $it['locked'] = true;
                $it['locked_at'] = $now0;
                $it['downloaded'] = true;
                $it['downloaded_at'] = $now0;
                $it['expire_at'] = $now0 + 600;
                break;
            }
        }
        unset($it);
        lwFhWriteRoom($code, $room);

        lwFhLog([
            'at' => date('Y-m-d H:i:s'),
            'ip' => lwFhClientIp(),
            'code' => $code,
            'role' => 'download',
            'channel' => 'relay',
            'name' => $target['name'] ?? 'file',
            'size' => filesize($path) ?: 0,
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
        ]);

        // 输出文件
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' .
            str_replace(['"', '\r', '\n'], '', (string)($target['name'] ?? 'file')) . '"');
        header('X-Accel-Buffering: no');
        @set_time_limit(0);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        readfile($path);
        exit;
    }

    // =================================================================
    // 发文本消息
    // =================================================================
    case 'text': {
        $room = fhRequireRoom();
        $code = $room['code'];
        $role = fhNormRole($_REQUEST['role'] ?? 'b');

        $text = trim((string)fhParam('text', ''));
        if ($text === '') {
            fhErr('内容为空');
        }
        if (mb_strlen($text) > 5000) {
            fhErr('文字太长（最多 5000 字）');
        }

        $now = time();
        $item = [
            'id' => bin2hex(random_bytes(6)),
            'kind' => 'text',
            'text' => $text,
            'from' => $role,
            'at' => $now,
            'expire_at' => $now + 3600,
        ];
        $room['items'] = array_slice(array_merge($room['items'] ?? [], [$item]), -60);
        $room['last_active'] = $now;
        lwFhWriteRoom($code, $room);

        lwFhLog([
            'at' => date('Y-m-d H:i:s', $now),
            'ip' => lwFhClientIp(),
            'code' => $code,
            'role' => $role,
            'channel' => 'text',
            'name' => mb_substr($text, 0, 60),
            'size' => mb_strlen($text),
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
        ]);

        fhOk(['item' => $item]);
    }

    // =================================================================
    // 离开房间
    // =================================================================
    case 'leave': {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_REQUEST['code'] ?? '')));
        $role = fhNormRole($_REQUEST['role'] ?? 'b');
        $room = lwFhReadRoom($code);
        if ($room) {
            unset($room['peers'][$role]);
            // 两边都走了就删房间 + 清文件
            if (empty($room['peers'])) {
                fhTryCleanupRoom($code, $room);
            } else {
                lwFhWriteRoom($code, $room);
            }
        }
        fhOk();
    }

    // =================================================================
    // WebRTC 信令交换（P2P 用）
    // =================================================================
    case 'signal': {
        $room = fhRequireRoom();
        $code = $room['code'];
        $role = fhNormRole($_REQUEST['role'] ?? 'b');

        // 读取对方的信令
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $box = $room['signal'][$role] ?? [];
            fhOk(['messages' => $box]);
        }

        // 写入信令（发给对方）
        $to = $role === 'pc' ? 'mobile' : 'pc';
        if (!empty($FH_BODY)) {
            $msg = $FH_BODY;
        } else {
            $msg = ['type' => (string)fhParam('type', ''), 'payload' => fhParam('payload', '')];
        }
        if (!is_array($msg) || empty($msg['type'])) {
            fhErr('信令内容为空');
        }
        $room['signal'] = $room['signal'] ?? [];
        $room['signal'][$to] = $room['signal'][$to] ?? [];
        $room['signal'][$to][] = $msg;
        // 只看最新的 30 条，避免无限增长
        $room['signal'][$to] = array_slice($room['signal'][$to], -30);
        fhWrite($code, $room);
        fhOk();
    }

    default:
        fhErr('未知操作', 404);
}

/** 写房间（signal 分支用，保持接口一致）。 */
function fhWrite(string $code, array $room): void
{
    lwFhWriteRoom($code, $room);
}

/**
 * 清理房间及其文件。
 *
 * 【删除策略】
 * 传完立即删 —— 用户不需要在服务器上留任何痕迹。
 * 但「下载中」的文件不能删（lock 中的），所以保留给清理任务处理。
 */
function fhTryCleanupRoom(string $code, array $room): void
{
    $dir = LW_FH_DIR . '/relay/';
    $now = time();
    foreach ($room['items'] ?? [] as $it) {
        $locked = !empty($it['locked']) && ($now - (int)($it['locked_at'] ?? 0)) < 300;
        if ($locked) {
            continue;
        }
        foreach (glob($dir . ($it['id'] ?? '') . '.*') ?: [] as $g) {
            @unlink($g);
        }
    }
    $p = lwFhRoomPath($code);
    if ($p !== '') {
        @unlink($p);
    }
}
