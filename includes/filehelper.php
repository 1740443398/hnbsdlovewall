<?php
/**
 * 文件传输助手：房间与会话
 *
 * =====================================================================
 * 设计要点
 * =====================================================================
 *
 * 【为什么叫「房间」】
 * 电脑打开页面生成一个 6 位配对码 + 二维码。
 * 手机扫码后进同一个房间，两边就能互相看到并传文件。
 * 房间只存在于内存/文件里，传完即销毁。
 *
 * 【为什么不用数据库表】
 * 房间生命周期极短（几分钟），写进 MySQL 只会制造垃圾行。
 * 用 data/filehelper/ 下的小 JSON 文件，清理时直接删文件。
 *
 * 【安全】
 * - 房间码 6 位大写字母数字（去掉易混的 0/O/1/I/L），暴力猜中概率极低
 * - 房间创建后 30 分钟内无人加入则自动过期
 * - 每个房间最多 2 个参与者（电脑 + 手机），第三方无法插队
 * =====================================================================
 */

if (!defined('LW_FH_LOADED')) {
    define('LW_FH_LOADED', 1);

    /* 数据目录必须落在站点内。
     *
     * 【曾经踩的坑】
     * 最初写 dirname(__DIR__, 2)：本文件在 includes/ 里，
     * __DIR__ = .../htdocs/includes，dirname 两层 = .../（站点根之外）。
     * 于是目录建在了 htdocs 外面，浏览器永远访问不到，
     * 表现为「创建房间成功，加入却说不存在」。
     *
     * 正确只往上退一层：includes -> htdocs，再拼 data/filehelper。
     */
    define('LW_FH_DIR', dirname(__DIR__) . '/data/filehelper');
    define('LW_FH_ROOM_TTL', 1800);        // 房间存活 30 分钟
    define('LW_FH_RELAY_TTL', function_exists('lwCfgInt') ? max(1, lwCfgInt('fh_relay_ttl_min', 1)) * 60 : 60); // 后台可配，默认 60 秒
    define('LW_FH_RELAY_MAX', function_exists('lwCfgInt') ? max(1, min(20, lwCfgInt('fh_relay_max_mb', 20))) * 1024 * 1024 : 20 * 1024 * 1024); // 后台可配，上限 20MB（主机限制）
    define('LW_FH_MAX_FILE', 3 * 1024 * 1024 * 1024); // 整体上限 3GB

    /** 保证目录存在。 */
    function lwFhEnsureDir(): void
    {
        $base = LW_FH_DIR;
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        foreach (['rooms', 'relay'] as $sub) {
            if (!is_dir($base . '/' . $sub)) {
                @mkdir($base . '/' . $sub, 0755, true);
            }
        }
    }

    /**
     * 生成房间码。
     *
     * 去掉 0/O/1/I/L/Z/2 这些容易看错的字符 ——
     * 用户要手抄或口头念，混入这些会出现「明明对却进不去」。
     */
    function lwFhNewCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXY3456789';
        $out = '';
        for ($i = 0; $i < 6; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /** 房间文件路径。 */
    function lwFhRoomPath(string $code): string
    {
        $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
        if (strlen($safe) !== 6) {
            return '';
        }
        return LW_FH_DIR . '/rooms/' . $safe . '.json';
    }

    /** 读房间。不存在或过期返回 null。 */
    function lwFhReadRoom(string $code): ?array
    {
        $p = lwFhRoomPath($code);
        if ($p === '' || !is_file($p)) {
            return null;
        }
        $raw = @file_get_contents($p);
        if ($raw === false || $raw === '') {
            return null;
        }
        $r = json_decode($raw, true);
        if (!is_array($r)) {
            return null;
        }
        if ((int)($r['expire_at'] ?? 0) < time()) {
            @unlink($p);
            return null;
        }
        return $r;
    }

    /** 写房间（带锁，避免两端同时写互相覆盖）。 */
    function lwFhWriteRoom(string $code, array $room): bool
    {
        $p = lwFhRoomPath($code);
        if ($p === '') {
            return false;
        }
        $fp = @fopen($p, 'c+');
        if (!$fp) {
            return false;
        }
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($room, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    /**
     * 创建房间。
     *
     * @param string $role 创建者角色：pc / mobile
     */
    function lwFhCreateRoom(string $role = 'pc'): array
    {
        lwFhEnsureDir();
        $code = lwFhNewCode();
        // 极小概率撞码，重试几次
        $tries = 0;
        while (is_file(lwFhRoomPath($code)) && $tries++ < 10) {
            $code = lwFhNewCode();
        }

        $now = time();
        $room = [
            'code' => $code,
            'created_at' => $now,
            'expire_at' => $now + LW_FH_ROOM_TTL,
            'peers' => [],
            'last_active' => $now,
        ];
        $room['peers'][$role] = [
            'joined_at' => $now,
            'last_seen' => $now,
            'ip' => lwFhClientIp(),
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        ];
        lwFhWriteRoom($code, $room);
        return $room;
    }

    /** 取客户端 IP（优先真实 IP 头）。 */
    function lwFhClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $v = trim(explode(',', (string)$_SERVER[$k])[0]);
                if (filter_var($v, FILTER_VALIDATE_IP)) {
                    return $v;
                }
            }
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** 记录一条传输日志（后台可查 IP / 文件名 / 大小）。 */
    function lwFhLog(array $row): void
    {
        lwFhEnsureDir();
        $file = LW_FH_DIR . '/log.jsonl';
        $line = json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // 日志超过 2MB 就滚动，避免无限增长
        $sz = @filesize($file);
        if ($sz !== false && $sz > 2 * 1024 * 1024) {
            @rename($file, $file . '.' . date('YmdHis') . '.old');
        }
    }

    /**
     * 清理过期的中转文件与空房间。
     *
     * 【为什么必须主动清理】
     * 只靠「下载时检查过期」是不够的 —— 用户不点下载，文件就一直在磁盘上。
     * 这里有两条路会被调到：
     *   1. 每次 upload / create 时顺手抽检（概率触发，省资源）
     *   2. tools/fh_cleanup.php 定时跑（有 cron 的部署方用这个）
     *
     * 【清理规则】
     *   - 中转文件：文件 mtime 超过 LW_FH_RELAY_TTL + 60 秒就删
     *     （用 mtime 而不是房间里的 expire_at，因为房间可能已经没了，
     *      但文件还躺在 relay 目录里，这种「孤儿文件」必须靠 mtime 收）
     *   - 房间：过期就删 json
     *   - 孤儿文件：无对应房间且超过 10 分钟的，一律删
     *
     * @param bool $force 忽略概率抽检，强制执行
     * @return array{files:int, rooms:int}
     */
    function lwFhCleanup(bool $force = false): array
    {
        // 非强制模式下只有 20% 概率真的跑，避免每次请求都扫目录
        if (!$force && random_int(1, 100) > 20) {
            return ['files' => 0, 'rooms' => 0, 'skipped' => true];
        }

        lwFhEnsureDir();
        $now = time();
        $removedFiles = 0;
        $removedRooms = 0;

        // ---- 1. 收集所有房间引用到的文件 id ----
        $activeIds = [];
        $roomFiles = glob(LW_FH_DIR . '/rooms/*.json') ?: [];
        foreach ($roomFiles as $rf) {
            $raw = @file_get_contents($rf);
            if ($raw === false || $raw === '') {
                @unlink($rf);
                $removedRooms++;
                continue;
            }
            $room = json_decode($raw, true);
            if (!is_array($room)) {
                @unlink($rf);
                $removedRooms++;
                continue;
            }
            // 房间过期 → 删 json，但里面的文件交给下面按 mtime 收
            if ((int)($room['expire_at'] ?? 0) < $now) {
                @unlink($rf);
                $removedRooms++;
                continue;
            }
            foreach ($room['items'] ?? [] as $it) {
                $fid = (string)($it['id'] ?? '');
                if ($fid !== '' && ($it['channel'] ?? 'relay') === 'relay') {
                    $activeIds[$fid] = (int)($it['expire_at'] ?? 0);
                }
            }
        }

        // ---- 2. 扫 relay 目录 ----
        $relayFiles = glob(LW_FH_DIR . '/relay/*') ?: [];
        foreach ($relayFiles as $f) {
            if (!is_file($f)) {
                continue;
            }
            $base = basename($f);
            $fid = preg_replace('/\..*$/', '', $base);
            $mtime = @filemtime($f) ?: 0;
            $age = $now - $mtime;

            $shouldDelete = false;

            if (isset($activeIds[$fid])) {
                // 房间还在：按房间里的 expire_at 判断（下载中会延到 10 分钟）
                $exp = $activeIds[$fid];
                if ($exp > 0 && $exp < $now) {
                    $shouldDelete = true;
                }
            } else {
                // 孤儿文件（房间没了）：超过 10 分钟就删
                if ($age > 600) {
                    $shouldDelete = true;
                }
            }

            // 兜底：不管什么状态，超过 1 小时一律删
            if ($age > 3600) {
                $shouldDelete = true;
            }

            if ($shouldDelete) {
                @unlink($f);
                $removedFiles++;
            }
        }

        return ['files' => $removedFiles, 'rooms' => $removedRooms, 'skipped' => false];
    }


    /**
     * 网盘待删队列。
     *
     * 【为什么需要队列】
     * 小飞机的删除接口会被一个卡住的任务锁死整个账号，
     * 返回「任务进行中，请稍后操作」，连不存在的 ID 也一样。
     * 这时候文件删不掉，但也不能丢了引用 ——
     * 否则就永远不知道自己往网盘里扔了多少东西。
     *
     * 所以删失败的 fileId 全部记进这个 JSON 队列，
     * 下次任何人用传输助手时顺手重试一遍。
     * 锁恢复的那一刻，积压的文件会被一次性清掉。
     */
    function lwFhPendingFile(): string
    {
        return LW_FH_DIR . '/pending_cloud.json';
    }

    /** 读待删队列。 */
    function lwFhPendingRead(): array
    {
        $f = lwFhPendingFile();
        if (!is_file($f)) {
            return [];
        }
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') {
            return [];
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    /** 写待删队列。 */
    function lwFhPendingWrite(array $list): void
    {
        lwFhEnsureDir();
        @file_put_contents(lwFhPendingFile(),
            json_encode(array_values(array_unique($list)), JSON_UNESCAPED_UNICODE),
            LOCK_EX);
    }

    /** 往队列里加一个 fileId。 */
    function lwFhPendingAdd(string $fileId): void
    {
        if ($fileId === '') {
            return;
        }
        $list = lwFhPendingRead();
        if (!in_array($fileId, $list, true)) {
            $list[] = $fileId;
            // 最多留 200 条，避免文件无限膨胀
            if (count($list) > 200) {
                $list = array_slice($list, -200);
            }
            lwFhPendingWrite($list);
        }
    }

    /** 从队列里移除（删除成功后）。 */
    function lwFhPendingRemove(array $done): void
    {
        if (!$done) {
            return;
        }
        $list = lwFhPendingRead();
        $list = array_values(array_filter($list, function ($id) use ($done) {
            return !in_array($id, $done, true);
        }));
        lwFhPendingWrite($list);
    }

    /**
     * 重试待删队列。概率触发，避免每次请求都打网盘接口。
     *
     * @param bool $force 强制执行（工具脚本用）
     * @return array{ok:int, fail:int, skipped:int, pending:int}
     */
    function lwFhPendingFlush(bool $force = false): array
    {
        $zero = ['ok' => 0, 'fail' => 0, 'skipped' => 0, 'pending' => 0];
        if (!$force && random_int(1, 100) > 25) {
            return $zero + ['pending' => count(lwFhPendingRead())];
        }

        $list = lwFhPendingRead();
        if (!$list) {
            return $zero;
        }

        require_once __DIR__ . '/feijipan.php';
        $fp = new LWFeijiPan();
        if (!$fp->isConfigured()) {
            return $zero + ['pending' => count($list)];
        }

        $r = $fp->removeBatch($list);
        if ($r['ok'] > 0) {
            // 批量删除是整体成功的，成功那批要整块移出队列。
            // removeBatch 已经把成功的算进 $r['ok']，这里按数量从头部摘。
            $done = array_slice($list, 0, $r['ok']);
            lwFhPendingRemove($done);
        }

        return $r + ['pending' => count(lwFhPendingRead())];
    }

}
