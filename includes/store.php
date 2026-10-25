<?php
/**
 * 读写分流数据层（MySQL 优先 + JSON 兜底）
 *
 * =====================================================================
 * 【策略 —— 这是整个设计的核心】
 * =====================================================================
 *
 * 读：MySQL 优先 → 失败自动回退 JSON
 *   1. 先查 MySQL
 *   2. 成功且数据正常 → 返回 MySQL 数据
 *   3. 报错 / 连接失败 / 超时 → 读本地 JSON 文件返回兜底数据
 *
 * 写：只写 MySQL，无兜底
 *   数据库挂了就失败，明确报错，绝不假装成功。
 *   理由：写操作如果「悄悄写进 JSON」，会形成两份互不同步的数据，
 *   等数据库恢复后就是灾难。宁可让用户重试。
 *
 * =====================================================================
 * 【什么数据放 MySQL，什么不放】
 * =====================================================================
 *
 * 适合（读多写少、小体量、简单查询）：
 *   公告、分类、轮播、版本配置、少量用户信息、低频留言、
 *   设置项、敏感词、管理员权限、头衔预设
 *
 * 谨慎（写多读多，需要合并写入）：
 *   评论、打卡、点赞、浏览计数
 *   → 这些**必须走缓存合并写入**，见 includes/counter_buffer.php
 *   → 禁止单次请求就 UPDATE 一次，虚拟主机会被打死
 *
 * 禁止放 MySQL：
 *   二进制文件（图片、附件）→ 放文件系统
 *   实时消息（私信、在线状态）→ 用 JSON 或专门的存储
 *   高并发提交数据（投票、点赞瞬时高峰）
 *   高频日志（WAF 日志、访问日志）→ 用 JSON 追加
 *   复杂统计数据（多维聚合）→ 定时算好存快照
 *
 * =====================================================================
 * 【兜底数据从哪来】
 * =====================================================================
 *
 * 优先读 data/*.json（站点原本的存储），这是最自然的兜底源。
 * 另外提供 tools/snapshot.php 定期生成 data/_snapshot/*.json，
 * 用于「MySQL 里的数据比 JSON 新」的情况 —— 但那是可选的，
 * 当前直接用 data/*.json 就够（两者内容一致）。
 */

if (!defined("LW_STORE_LOADED")) {
    define("LW_STORE_LOADED", 1);

    require_once __DIR__ . "/db.php";

    /* ============ 读路径 ============ */

    /**
     * 读一张表（MySQL 优先，失败回退 JSON）。
     *
     * @param string $table 不带前缀的表名，如 "posts"
     * @param array  $where 简单等值条件，如 ['status' => 'published']
     * @param string $order 排序子句，如 "created_at DESC"（可空）
     * @param int    $limit 限制条数（0 = 不限）
     * @return array 行数组（永远返回数组，失败时可能为空）
     */
    function storeRead(string $table, array $where = [], string $order = "", int $limit = 0): array
    {
        // 1) 试 MySQL
        if (lwDbAvailable()) {
            $rows = lwStoreQuery($table, $where, $order, $limit);
            if ($rows !== null) {
                return $rows;
            }
            // null = 查询失败，落到下面的 JSON 兜底
            if (!defined("LW_DB_FELLBACK")) {
                define("LW_DB_FELLBACK", 1);
            }
        }

        // 2) 回退 JSON
        return lwStoreFromJson($table, $where, $order, $limit);
    }

    /** 真正查 MySQL。失败返回 null（与「空结果」区分）。 */
    function lwStoreQuery(string $table, array $where, string $order, int $limit): ?array
    {
        $sql = "SELECT * FROM `lw_" . $table . "`";
        $args = [];
        if ($where) {
            $conds = [];
            foreach ($where as $k => $v) {
                $conds[] = "`" . $k . "` = ?";
                $args[] = $v;
            }
            $sql .= " WHERE " . implode(" AND ", $conds);
        }
        if ($order !== "" && preg_match("/^[a-zA-Z0-9_ ,]+$/", $order)) {
            $sql .= " ORDER BY " . $order;
        }
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }
        $rows = lwDbQuery($sql, $args);
        if ($rows === null) {
            return null;
        }
        // 把 TEXT 里存的 JSON 还原成数组，让调用方拿到的形状与 JSON 版一致
        return lwStoreHydrate($rows);
    }

    /** 从 JSON 文件读（兜底路径）。 */
    function lwStoreFromJson(string $table, array $where, string $order, int $limit): array
    {
        $file = __DIR__ . "/../data/" . $table . ".json";
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            return [];
        }

        // 等值过滤
        if ($where) {
            $rows = array_values(array_filter($rows, function ($r) use ($where) {
                foreach ($where as $k => $v) {
                    if (!is_array($r) || !array_key_exists($k, $r)) {
                        return false;
                    }
                    // 宽松比较：JSON 里布尔可能是 0/1，"0"/"1"
                    if ((string)$r[$k] !== (string)$v) {
                        return false;
                    }
                }
                return true;
            }));
        }

        // 排序（只支持单字段 + ASC/DESC）
        if ($order !== "" && preg_match("/^([a-zA-Z0-9_]+)\s*(ASC|DESC)?$/i", trim($order), $m)) {
            $col = $m[1];
            $dir = strtoupper($m[2] ?? "ASC");
            usort($rows, function ($a, $b) use ($col, $dir) {
                $va = $a[$col] ?? null;
                $vb = $b[$col] ?? null;
                $c = ($va <=> $vb);
                return $dir === "DESC" ? -$c : $c;
            });
        }

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    /**
     * 把 DB 行里的 JSON 字符串还原成数组/对象。
     *
     * 为什么要做：迁移时数组字段被 json_encode 存成了 TEXT。
     * 直接返回字符串的话，调用方用 $p["images"][0] 会拿到 "["，
     * 与 JSON 兜底路径的形状不一致 —— 那是最难查的一类 bug。
     */
    function lwStoreHydrate(array $rows): array
    {
        foreach ($rows as &$r) {
            foreach ($r as $k => &$v) {
                if (is_string($v) && $v !== "" && ($v[0] === "[" || $v[0] === "{")) {
                    $decoded = json_decode($v, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $v = $decoded;
                    }
                }
                // MySQL 的 int 列返回字符串，转回数字以便与 JSON 一致
                if (is_string($v) && $v !== "" && ctype_digit(ltrim($v, "-"))) {
                    $v = (int)$v;
                }
            }
            unset($v);
        }
        unset($r);
        return $rows;
    }

    /** 按 id 取单行。 */
    function storeFindById(string $table, int $id): ?array
    {
        $rows = storeRead($table, ["id" => $id], "", 1);
        return $rows[0] ?? null;
    }

    /* ============ 写路径（无兜底） ============ */

    /**
     * 插入一行。
     *
     * 【重要】写入不做 JSON 兜底 —— 数据库挂了就返回 0（失败）。
     * 让调用方明确报错，而不是悄悄写进 JSON 造成两份数据。
     */
    function storeInsert(string $table, array $row): int
    {
        if (!lwDbAvailable()) {
            error_log("storeInsert: 数据库不可用，写入失败 table=$table");
            return 0;
        }
        $id = lwDbInsert("lw_" . $table, lwStoreDehydrate($row));
        if ($id > 0) {
            // 双写 JSON 作为**备份**（不是兜底）：
            // 万一数据库日后损坏，JSON 还能救回来。
            // 失败不影响主流程。
            lwStoreBackupJson($table, array_merge($row, ["id" => $id]));
        }
        return $id;
    }

    /** 更新一行，返回受影响行数。 */
    function storeUpdate(string $table, int $id, array $patch): int
    {
        if (!lwDbAvailable() || !$patch) {
            return -1;
        }
        $sets = [];
        $args = [];
        foreach (lwStoreDehydrate($patch) as $k => $v) {
            $sets[] = "`" . $k . "` = ?";
            $args[] = $v;
        }
        $args[] = $id;
        $sql = "UPDATE `lw_" . $table . "` SET " . implode(", ", $sets) . " WHERE `id` = ?";
        return lwDbExec($sql, $args);
    }

    /** 删除一行。 */
    function storeDelete(string $table, int $id): int
    {
        if (!lwDbAvailable()) {
            return -1;
        }
        return lwDbExec("DELETE FROM `lw_" . $table . "` WHERE `id` = ?", [$id]);
    }

    /** 数组字段转 JSON 字符串（写库前）。 */
    function lwStoreDehydrate(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $row[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($v)) {
                $row[$k] = $v ? 1 : 0;
            }
        }
        return $row;
    }

    /**
     * 双写 JSON 备份（追加或更新）。
     *
     * 这是「数据库之外的第二份拷贝」，用于灾难恢复，
     * **不是**写入失败时的兜底 —— 写入失败就是失败。
     */
    function lwStoreBackupJson(string $table, array $row): void
    {
        try {
            $file = __DIR__ . "/../data/" . $table . ".json";
            $dir = dirname($file);
            if (!is_dir($dir)) {
                return;
            }
            $rows = [];
            if (is_file($file)) {
                $decoded = json_decode((string)@file_get_contents($file), true);
                if (is_array($decoded)) {
                    $rows = $decoded;
                }
            }
            $id = (int)($row["id"] ?? 0);
            $replaced = false;
            foreach ($rows as $i => $r) {
                if ((int)($r["id"] ?? 0) === $id) {
                    $rows[$i] = $row;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $rows[] = $row;
            }
            @file_put_contents(
                $file,
                json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        } catch (Throwable $e) {
            // 备份失败不影响主流程
        }
    }

    /** 当前读路径是 MySQL 还是 JSON 兜底（供后台/调试展示）。 */
    function storeStatus(): array
    {
        return [
            "db_available" => lwDbAvailable(),
            "fell_back" => defined("LW_DB_FELLBACK"),
            "health" => lwDbAvailable() ? lwDbHealth() : null,
        ];
    }
}
