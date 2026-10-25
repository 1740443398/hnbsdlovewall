<?php
/**
 * 数据库连接（MySQL/MariaDB）
 *
 * 【为什么现在才加】
 * 站点原本用 JSON 平文件存储（includes/filestorage.php）。
 * 在 110 用户 / 32 帖子的量级下这完全够用，但有两个场景会出问题：
 *   1. 并发写：JSON 每次写要重写整个文件，两人同时发帖会互相覆盖
 *   2. 聚合查询：统计要全表遍历，数据一多就慢
 *
 * 现在接上 MySQL，但**不是全量替换** —— 见下面的「双写策略」。
 *
 * 【关键约束：字符集】
 * InfinityFree 的 MariaDB 默认 character_set 是 latin1。
 * 不显式指定 utf8mb4 的话，中文会被存成乱码（而且是静默的，不报错）。
 * 所以连接后第一件事就是 SET NAMES utf8mb4。
 *
 * 【连接数限制】
 * 免费套餐每小时 100000 次查询、连接数很少。
 * 所以这里用单例 + 长连接复用，绝不在循环里反复 mysqli_connect。
 */

if (!defined("LW_DB_LOADED")) {
    define("LW_DB_LOADED", 1);

    /** 数据库配置。放在独立文件里，方便开源版排除。 */
    function lwDbConfig(): array
    {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        $file = __DIR__ . "/../config/db_config.php";
        if (is_file($file)) {
            $loaded = include $file;
            if (is_array($loaded)) {
                $cfg = $loaded;
                return $cfg;
            }
        }
        // 兜底：从环境变量读（便于不同环境切换）
        $cfg = [
            "host" => getenv("LW_DB_HOST") ?: "",
            "user" => getenv("LW_DB_USER") ?: "",
            "pass" => getenv("LW_DB_PASS") ?: "",
            "name" => getenv("LW_DB_NAME") ?: "",
            "port" => (int)(getenv("LW_DB_PORT") ?: 3306),
            "enabled" => getenv("LW_DB_ENABLED") === "1",
        ];
        return $cfg;
    }

    /**
     * 数据库是否可用。
     *
     * 任何一步失败都返回 false，调用方据此**回退到 JSON**。
     * 这是设计的核心：数据库挂了站点不能挂。
     */
    function lwDbAvailable(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $cfg = lwDbConfig();
        if (empty($cfg["enabled"]) || empty($cfg["host"])) {
            $ok = false;
            return $ok;
        }
        $ok = (lwDb() !== null);
        return $ok;
    }

    /** 单例连接。失败返回 null（不抛异常）。 */
    function lwDb(): ?mysqli
    {
        static $conn = null;
        static $tried = false;
        if ($tried) {
            return $conn;
        }
        $tried = true;

        if (!function_exists("mysqli_connect")) {
            return null;
        }
        $cfg = lwDbConfig();
        if (empty($cfg["host"]) || empty($cfg["name"])) {
            return null;
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $c = @mysqli_connect(
                (string)$cfg["host"],
                (string)$cfg["user"],
                (string)$cfg["pass"],
                (string)$cfg["name"],
                (int)($cfg["port"] ?? 3306)
            );
            if (!$c) {
                error_log("lwDb: connect failed - " . mysqli_connect_error());
                return null;
            }
            // 必须显式设 utf8mb4，否则中文乱码（见文件头注释）
            if (!@mysqli_set_charset($c, "utf8mb4")) {
                @mysqli_query($c, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            // 会话级优化：免费主机默认 sort_buffer 很小，JOIN 稍大就落盘
            @mysqli_query($c, "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            $conn = $c;
        } catch (Throwable $e) {
            error_log("lwDb: " . $e->getMessage());
            $conn = null;
        }
        return $conn;
    }

    /**
     * 执行查询，返回结果行数组。失败返回 null（区分「空结果」与「出错」）。
     *
     * @param string $sql 带 ? 占位符的 SQL
     * @param array  $args 参数（自动推断类型）
     */
    function lwDbQuery(string $sql, array $args = []): ?array
    {
        $c = lwDb();
        if ($c === null) {
            return null;
        }
        $stmt = @mysqli_prepare($c, $sql);
        if (!$stmt) {
            error_log("lwDbQuery prepare: " . mysqli_error($c) . " | SQL: " . substr($sql, 0, 200));
            return null;
        }
        if ($args) {
            $types = "";
            $vals = [];
            foreach ($args as $a) {
                if (is_int($a)) {
                    $types .= "i";
                } elseif (is_float($a)) {
                    $types .= "d";
                } elseif (is_null($a)) {
                    $types .= "s";
                    $a = null;
                } else {
                    $types .= "s";
                }
                $vals[] = $a;
            }
            $bind = [$types];
            foreach ($vals as $k => $v) {
                $bind[] = &$vals[$k];
            }
            @call_user_func_array([$stmt, "bind_param"], $bind);
        }
        if (!@mysqli_stmt_execute($stmt)) {
            error_log("lwDbQuery execute: " . mysqli_stmt_error($stmt));
            @mysqli_stmt_close($stmt);
            return null;
        }
        $res = @mysqli_stmt_get_result($stmt);
        $rows = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
            mysqli_free_result($res);
        }
        @mysqli_stmt_close($stmt);
        return $rows;
    }

    /** 执行写操作，返回受影响行数；失败返回 -1。 */
    function lwDbExec(string $sql, array $args = []): int
    {
        $c = lwDb();
        if ($c === null) {
            return -1;
        }
        $stmt = @mysqli_prepare($c, $sql);
        if (!$stmt) {
            error_log("lwDbExec prepare: " . mysqli_error($c) . " | SQL: " . substr($sql, 0, 200));
            return -1;
        }
        if ($args) {
            $types = "";
            $vals = [];
            foreach ($args as $a) {
                if (is_int($a)) {
                    $types .= "i";
                } elseif (is_float($a)) {
                    $types .= "d";
                } else {
                    $types .= "s";
                }
                $vals[] = $a;
            }
            $bind = [$types];
            foreach ($vals as $k => $v) {
                $bind[] = &$vals[$k];
            }
            @call_user_func_array([$stmt, "bind_param"], $bind);
        }
        $ok = @mysqli_stmt_execute($stmt);
        $affected = $ok ? mysqli_stmt_affected_rows($stmt) : -1;
        if (!$ok) {
            error_log("lwDbExec execute: " . mysqli_stmt_error($stmt));
        }
        @mysqli_stmt_close($stmt);
        return $affected;
    }

    /** 插入并返回自增 id；失败返回 0。 */
    function lwDbInsert(string $table, array $row): int
    {
        $c = lwDb();
        if ($c === null || !$row) {
            return 0;
        }
        $cols = array_keys($row);
        $ph = implode(",", array_fill(0, count($cols), "?"));
        $sql = "INSERT INTO `" . $table . "` (`" . implode("`,`", $cols) . "`) VALUES ($ph)";
        if (lwDbExec($sql, array_values($row)) < 0) {
            return 0;
        }
        return (int)mysqli_insert_id($c);
    }

    /** 健康检查：返回连接状态与版本，供后台展示。 */
    function lwDbHealth(): array
    {
        $c = lwDb();
        if ($c === null) {
            return ["ok" => false, "error" => "无法连接"];
        }
        $r = @mysqli_query($c, "SELECT VERSION() AS v, DATABASE() AS d");
        $row = $r ? mysqli_fetch_assoc($r) : null;
        $tables = 0;
        $tr = @mysqli_query($c, "SHOW TABLES");
        if ($tr) {
            $tables = mysqli_num_rows($tr);
            mysqli_free_result($tr);
        }
        return [
            "ok" => true,
            "version" => $row["v"] ?? "?",
            "database" => $row["d"] ?? "?",
            "tables" => $tables,
        ];
    }
}
