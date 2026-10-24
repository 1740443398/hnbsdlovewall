<?php
/**
 * 计数器合并写入（count buffer）
 *
 * =====================================================================
 * 【要解决什么问题】
 * =====================================================================
 *
 * 点赞、浏览、评论数的典型写法是「用户点一次，UPDATE 一次」。
 * 在共享虚拟主机上这是**致命的**：
 *
 *   - 免费套餐每小时查询数有上限（本站 100000/小时）
 *   - 热帖每被刷一次就一条 UPDATE，一天几千次
 *   - 并发时 UPDATE 会锁行，MySQL 连接数很快被打满
 *   - 连接满了 → 整个站点所有请求都卡住 → 用户看到 500
 *
 * =====================================================================
 * 【做法】
 * =====================================================================
 *
 * 1. 用户点赞/浏览时，**只累加内存里的差值**，不碰数据库
 * 2. 累计到阈值（默认 20 次）或距上次刷盘超过 30 秒，才一次性 UPDATE
 * 3. 请求结束时 register_shutdown_function 兜底刷一次
 *
 * 结果：1000 次点赞从 1000 条 UPDATE 变成 ~50 条。
 *
 * =====================================================================
 * 【代价】
 * =====================================================================
 *
 * 计数会延迟几十秒才反映到数据库。对「点赞数」这种数据完全可接受 ——
 * 没有用户会因为点赞数晚 30 秒更新而投诉。
 * 但**不能用在这类场景**：
 *   - 余额、积分（必须实时准确）
 *   - 库存（超卖）
 *   - 权限判断依赖的计数
 *
 * =====================================================================
 * 【为什么用文件存缓冲区】
 * =====================================================================
 *
 * PHP 是无共享的（每个请求独立进程），没法用真正的内存缓存。
 * 用文件做「进程间累加器」，配合 LOCK_EX 保证并发安全。
 * 文件很小（只存待刷新的差值），读写都是微秒级。
 */

if (!defined("LW_COUNTER_LOADED")) {
    define("LW_COUNTER_LOADED", 1);

    /** 缓冲区阈值：累计多少次才刷到数据库。 */
    define("LW_COUNTER_FLUSH_EVERY", 20);
    /** 缓冲区最大存活秒数：超过就刷，避免长时间不落库。 */
    define("LW_COUNTER_MAX_AGE", 30);

    /** 缓冲区文件路径。 */
    function lwCounterFile(): string
    {
        return __DIR__ . "/../data/_counter_buffer.json";
    }

    /** 读缓冲区。 */
    function lwCounterLoad(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $f = lwCounterFile();
        if (!is_file($f)) {
            $cache = ["saved_at" => time(), "ops" => []];
            return $cache;
        }
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || !isset($d["ops"])) {
            $cache = ["saved_at" => time(), "ops" => []];
            return $cache;
        }
        $cache = $d;
        return $cache;
    }

    /** 写缓冲区。 */
    function lwCounterSave(array $buf): void
    {
        $f = lwCounterFile();
        $dir = dirname($f);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($f, json_encode($buf, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * 累加一个计数器（不立即写库）。
     *
     * @param string $table 表名，如 "posts"
     * @param int    $id    行 id
     * @param string $field 字段名，如 "likes"
     * @param int    $delta 增量（可负，如取消点赞）
     */
    function lwCounterBump(string $table, int $id, string $field, int $delta = 1): void
    {
        if ($id <= 0 || $delta === 0) {
            return;
        }
        $buf = lwCounterLoad();
        $key = $table . ":" . $id . ":" . $field;
        if (!isset($buf["ops"][$key])) {
            $buf["ops"][$key] = 0;
        }
        $buf["ops"][$key] += $delta;

        // 触发条件：增量足够多，或缓冲区存在太久
        $total = 0;
        foreach ($buf["ops"] as $v) {
            $total += abs($v);
        }
        $age = time() - (int)($buf["saved_at"] ?? time());
        if ($total >= LW_COUNTER_FLUSH_EVERY || $age >= LW_COUNTER_MAX_AGE) {
            lwCounterFlush();
        } else {
            lwCounterSave($buf);
        }
    }

    /**
     * 把缓冲区刷到数据库。
     *
     * 用 GREATEST(...,0) 防止计数被减成负数（取消点赞重复调用时会遇到）。
     * 失败不重试 —— 计数丢失比卡住请求更可接受。
     */
    function lwCounterFlush(): void
    {
        $buf = lwCounterLoad();
        $ops = $buf["ops"] ?? [];
        if (!$ops) {
            return;
        }

        // 先清空缓冲区（避免刷盘期间又有新请求累加导致重复）
        lwCounterSave(["saved_at" => time(), "ops" => []]);

        if (!lwDbAvailable()) {
            return;
        }

        foreach ($ops as $key => $delta) {
            if ($delta === 0) {
                continue;
            }
            $parts = explode(":", $key);
            if (count($parts) !== 3) {
                continue;
            }
            list($table, $id, $field) = $parts;
            // 表名/字段名白名单校验（防注入）
            if (!preg_match("/^[a-z_]+$/", $table) || !preg_match("/^[a-z_]+$/", $field)) {
                continue;
            }
            $sql = "UPDATE `lw_" . $table . "` SET `" . $field . "` = GREATEST(0, `" . $field . "` + ?) WHERE `id` = ?";
            lwDbExec($sql, [(int)$delta, (int)$id]);
        }
    }

    /** 读取「数据库值 + 未刷新的差值」，得到用户看到的真实计数。 */
    function lwCounterRead(string $table, int $id, string $field, int $dbValue): int
    {
        $buf = lwCounterLoad();
        $key = $table . ":" . $id . ":" . $field;
        $pending = (int)($buf["ops"][$key] ?? 0);
        return max(0, $dbValue + $pending);
    }

    /** 把待刷新的差值批量合并进一组行（列表页用，避免逐行调用）。 */
    function lwCounterApplyBatch(string $table, string $field, array $rows, string $idKey = "id"): array
    {
        $buf = lwCounterLoad();
        $ops = $buf["ops"] ?? [];
        if (!$ops) {
            return $rows;
        }
        foreach ($rows as &$r) {
            $id = (int)($r[$idKey] ?? 0);
            $key = $table . ":" . $id . ":" . $field;
            $pending = (int)($ops[$key] ?? 0);
            if ($pending !== 0) {
                $r[$field] = max(0, (int)($r[$field] ?? 0) + $pending);
            }
        }
        unset($r);
        return $rows;
    }

    // 请求结束兜底刷盘：即使这一轮没达到阈值也不丢
    register_shutdown_function(function () {
        lwCounterFlush();
    });
}
