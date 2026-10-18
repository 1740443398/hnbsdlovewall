<?php
/**
 * 公共聊天室内核（唯一真源）
 * ---------------------------------------------------------------------------
 * 需求（一、5）：
 *   - 支持文本 / 图片 / GIF 消息；
 *   - 服务端消息只保留 1 天；
 *   - 单用户每日图片上传上限 20 张；
 *   - 服务端仅持久化存储最新 20 张图片资源。
 *
 * 实现取舍：
 *   - 表 `lobby_messages` 一行一条消息；文本与图片（含 GIF）都是同一张表，
 *     图片额外带 `image` 字段（站内相对路径）与 `kind=image`。
 *   - 保留 1 天：写入时顺手删掉 24 小时前的行（无定时任务也能自愈）。
 *   - 每日 20 图：`lobby_image_quota` 按 (user_id, date) 计数的单行记录。
 *   - 只存最新 20 张资源：写新图后，按时间倒序取前 20 个图片路径，
 *     其余图片记录**从消息表里抹掉图片字段**（保留文本行），并删除磁盘文件。
 *     这样"最新 20 张"始终可用，老图不会占用空间。
 *
 * 安全：
 *   - 所有输出转义在前端；服务端只做长度/类型/路径白名单校验。
 *   - 文件名随机生成，绝不采用原文件名；只允许图片后缀（含 gif 动图）。
 */

if (!defined('LW_LOBBY_RETAIN_HOURS')) {
    define('LW_LOBBY_RETAIN_HOURS', 24);      // 消息保留 1 天
}
if (!defined('LW_LOBBY_DAILY_IMAGES')) {
    define('LW_LOBBY_DAILY_IMAGES', 20);      // 单用户每日图片上限
}
if (!defined('LW_LOBBY_MINUTE_IMAGES')) {
    // 手稿明确「每分钟最多 20 张」：这是防刷的主要闸门，优先级高于每日上限。
    // 每日上限保留作兜底（两个口径同时生效，取更严格者）。
    define('LW_LOBBY_MINUTE_IMAGES', 20);
}
if (!defined('LW_LOBBY_KEEP_IMAGES')) {
    define('LW_LOBBY_KEEP_IMAGES', 20);       // 服务端只保留最新 20 张图片资源
}

if (!function_exists('lwLobbyDir')) {
    /** 聊天室图片目录（uploads/lobby）。 */
    function lwLobbyDir(): string
    {
        $dir = UPLOAD_DIR . '/lobby';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('lwLobbyPrune')) {
    /**
     * 过期清理：删掉超过保留期的消息行；被删行若带图片，顺带删磁盘文件。
     * @return int 删除条数
     */
    function lwLobbyPrune(): int
    {
        $fs = getFS();
        $rows = $fs->read('lobby_messages');
        if (!is_array($rows) || !$rows) {
            return 0;
        }
        // ts 统一是毫秒时间戳（与前端 Date.now() 同尺度），cutoff 也必须用毫秒，
        // 否则秒级 cutoff 会把所有消息都判成「未过期」，清理永远不生效。
        $cutoffMs = (int)round(microtime(true) * 1000) - LW_LOBBY_RETAIN_HOURS * 3600 * 1000;
        $removed = 0;
        foreach ($rows as $r) {
            $ts = (int)($r['ts'] ?? 0);
            if ($ts >= $cutoffMs) {
                continue;
            }
            if (!empty($r['image'])) {
                $file = lwLobbyImageAbs((string)$r['image']);
                if ($file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }
            try {
                $fs->delete('lobby_messages', (int)$r['id']);
                $removed++;
            } catch (Exception $e) {
                // 删除失败不阻断（下次再清）
            }
        }
        return $removed;
    }
}

if (!function_exists('lwLobbyImageAbs')) {
    /** 把 /uploads/lobby/xxx.jpg 映射回磁盘绝对路径；非法路径返回空串。 */
    function lwLobbyImageAbs(string $rel): string
    {
        if ($rel === '' || strpos($rel, '/uploads/lobby/') !== 0) {
            return '';
        }
        $name = basename($rel);
        if ($name === '' || !preg_match('/^[a-f0-9]{32}_[a-z0-9]+\.(jpg|jpeg|png|gif|webp)$/i', $name)) {
            return '';
        }
        return lwLobbyDir() . '/' . $name;
    }
}

if (!function_exists('lwLobbyEnforceImageCap')) {
    /**
     * 服务端只保留最新 N 张图片资源：
     * 按 (ts desc) 找出所有带 image 的消息，第 N+1 张起的图片
     *   - 删磁盘文件
     *   - 把消息行的 image 置空、kind 改成 text（保留文字，避免整条消失得莫名其妙）
     * @return int 清理张数
     */
    function lwLobbyEnforceImageCap(int $keep = LW_LOBBY_KEEP_IMAGES): int
    {
        $fs = getFS();
        $rows = $fs->read('lobby_messages');
        if (!is_array($rows) || !$rows) {
            return 0;
        }
        $withImg = array_filter($rows, function ($r) {
            return !empty($r['image']);
        });
        if (count($withImg) <= $keep) {
            return 0;
        }
        usort($withImg, function ($a, $b) {
            return (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0);
        });
        $drop = array_slice($withImg, $keep);
        $n = 0;
        foreach ($drop as $r) {
            $abs = lwLobbyImageAbs((string)$r['image']);
            if ($abs !== '' && is_file($abs)) {
                @unlink($abs);
            }
            try {
                $fs->update('lobby_messages', (int)$r['id'], [
                    'image' => '',
                    'kind' => 'text',
                ]);
                $n++;
            } catch (Exception $e) {
                // ignore
            }
        }
        return $n;
    }
}

if (!function_exists('lwLobbyImageQuotaLeft')) {
    /** 今天的图片剩余额度。 */
    function lwLobbyImageQuotaLeft(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $today = date('Y-m-d');
        $row = getFS()->findOne('lobby_image_quota', ['user_id' => $userId, 'date' => $today]);
        $used = $row ? (int)$row['count'] : 0;
        return max(0, LW_LOBBY_DAILY_IMAGES - $used);
    }
}

if (!function_exists('lwLobbyConsumeImageQuota')) {
    /**
     * 扣一次图片额度。返回 false 表示今天已用满。
     * 用单行计数，避免每条消息都去扫全表。
     */
    function lwLobbyConsumeImageQuota(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $fs = getFS();
        $today = date('Y-m-d');
        $row = $fs->findOne('lobby_image_quota', ['user_id' => $userId, 'date' => $today]);
        if ($row) {
            $used = (int)$row['count'];
            if ($used >= LW_LOBBY_DAILY_IMAGES) {
                return false;
            }
            $fs->update('lobby_image_quota', (int)$row['id'], ['count' => $used + 1]);
            return true;
        }
        if (LW_LOBBY_DAILY_IMAGES <= 0) {
            return false;
        }
        $fs->insert('lobby_image_quota', [
            'user_id' => $userId,
            'date' => $today,
            'count' => 1,
        ]);
        return true;
    }
}

if (!function_exists('lwLobbyMessages')) {
    /**
     * 读最近消息（升序，最多 100 条）。附带发送者昵称/头像快照，
     * 避免渲染时对每个发送者再查一次 users。
     */
    function lwLobbyMessages(int $limit = 50): array
    {
        $rows = getFS()->read('lobby_messages');
        if (!is_array($rows)) {
            return [];
        }
        $cutoff = time() - LW_LOBBY_RETAIN_HOURS * 3600;
        $rows = array_values(array_filter($rows, function ($r) use ($cutoff) {
            return (int)($r['ts'] ?? 0) >= $cutoff;
        }));
        usort($rows, function ($a, $b) {
            return (int)($a['ts'] ?? 0) <=> (int)($b['ts'] ?? 0);
        });
        if (count($rows) > $limit) {
            $rows = array_slice($rows, -$limit);
        }
        return array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'user_id' => (int)($r['user_id'] ?? 0),
                'nickname' => (string)($r['nickname'] ?? ''),
                'avatar' => (string)($r['avatar'] ?? ''),
                'role' => (string)($r['role'] ?? 'user'),
                'kind' => (string)($r['kind'] ?? 'text'),
                'text' => (string)($r['text'] ?? ''),
                'image' => (string)($r['image'] ?? ''),
                'ts' => (int)($r['ts'] ?? 0),
            ];
        }, $rows);
    }
}

if (!function_exists('lwLobbyAppend')) {
    /**
     * 追加一条消息。
     * @return array{ok:bool,error:string,id:int}
     */
    function lwLobbyAppend(array $user, string $kind, string $text, string $image = ''): array
    {
        $text = trim($text);
        if ($kind === 'text' && $text === '') {
            return ['ok' => false, 'error' => '消息不能为空', 'id' => 0];
        }
        if (mb_strlen($text) > 1000) {
            return ['ok' => false, 'error' => '消息过长（最多 1000 字）', 'id' => 0];
        }
        // 敏感词：与帖子同一套判定，避免聊天室成为绕过出口
        if ($text !== '' && function_exists('checkSensitiveWords') && checkSensitiveWords($text)) {
            return ['ok' => false, 'error' => '内容包含敏感词', 'id' => 0];
        }

        $ts = (int)round(microtime(true) * 1000);
        try {
            $row = getFS()->insert('lobby_messages', [
                'user_id' => (int)$user['id'],
                'nickname' => (string)($user['nickname'] ?? ''),
                'avatar' => (string)($user['avatar'] ?? ''),
                'role' => (string)($user['role'] ?? 'user'),
                'kind' => $kind === 'image' ? 'image' : 'text',
                'text' => mb_substr($text, 0, 1000),
                'image' => $image,
                'ts' => $ts,
            ]);
            return ['ok' => true, 'error' => '', 'id' => (int)($row['id'] ?? 0)];
        } catch (Exception $e) {
            error_log('[love_wall] 聊天室写入失败：' . $e->getMessage());
            return ['ok' => false, 'error' => '发送失败，请稍后重试', 'id' => 0];
        }
    }
}
