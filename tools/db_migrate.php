<?php
/**
 * 数据库建表脚本
 *
 * 从 data/*.json 的 50 张表自动推导建表语句。
 *
 * 【字段类型推导规则】
 *   id / *_id           -> INT（加索引）
 *   *_at / date / time  -> DATETIME（加索引，便于按时间排序）
 *   likes / count / *_n -> INT
 *   is_* / *_flag       -> TINYINT(1)
 *   visibility / status -> VARCHAR(32)（枚举值）
 *   其他                -> TEXT（JSON 里字段长度不可控，TEXT 最安全）
 *
 * 【为什么要加索引】
 * 虚拟主机的 MySQL 性能很弱，慢查询会直接拖垮进程。
 * 索引是本项目唯一能做的「便宜且有效」的性能优化。
 * 具体加在：主键、所有 *_id（外键查找）、created_at（排序）、
 * status/visibility（筛选）。
 */

require_once __DIR__ . "/../config/config.php";
require_once __DIR__ . "/../includes/db.php";

header("Content-Type: text/plain; charset=utf-8");

$c = lwDb();
if ($c === null) {
    exit("数据库连接失败
");
}

$prefix = "lw_";
$dataDir = __DIR__ . "/../data/";
$files = glob($dataDir . "*.json") ?: [];

/** 判断字段应使用什么类型。 */
function lwColType(string $name, array $samples): string
{
    if ($name === "id") {
        return "INT NOT NULL";
    }
    /* 【_id 不能一律当 INT】
     * device_id 存的是 32 位哈希串（如 1bb6076a0c834849ff73e326502709bf），
     * 按 INT 建表会让插入失败 —— 而且失败是静默的（lwDbExec 返回 -1，
     * 迁移脚本只计数不报错）。所以这里要按样例值判断。 */
    if (substr($name, -3) === "_id") {
        foreach ($samples as $v) {
            if (is_string($v) && $v !== "" && !ctype_digit($v)) {
                return "VARCHAR(191) NULL";
            }
        }
        return "INT NULL";
    }
    /* 【*_at 也不能一律当 DATETIME】
     * rate_limits.reset_at 存的是 Unix 时间戳（1791710618），
     * 按 DATETIME 建表会报 "Incorrect datetime value"。 */
    if (in_array($name, ["created_at", "updated_at", "handled_at", "reviewed_at", "mod_at",
        "expire_at", "last_seen", "last_login", "last_active", "reset_at", "viewed_at",
        "started_at", "finished_at"], true)) {
        foreach ($samples as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v) && (int)$v > 1000000000)) {
                return "BIGINT NULL";
            }
        }
        return "DATETIME NULL";
    }
    if (preg_match("/^(is_|has_)/", $name)) {
        return "TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (in_array($name, ["likes", "comments", "views", "count", "score", "level", "points",
        "amount", "total", "sort", "weight", "votes"], true)) {
        return "INT NOT NULL DEFAULT 0";
    }
    if (in_array($name, ["status", "visibility", "category", "type", "role", "action",
        "collection", "key"], true)) {
        return "VARCHAR(64) NULL";
    }
    if ($name === "qq" || $name === "nickname" || $name === "username" || $name === "slug") {
        return "VARCHAR(191) NULL";
    }
    // 默认 TEXT：JSON 字段长度不可控
    return "TEXT NULL";
}

/** 该字段是否值得建索引。 */
function lwShouldIndex(string $name, string $type): bool
{
    if ($name === "id") {
        return false; // 已经是主键
    }
    if (substr($name, -3) === "_id") {
        return true;
    }
    if (in_array($name, ["created_at", "status", "visibility", "category", "type",
        "qq", "slug", "user_id", "post_id", "comment_id"], true)) {
        return true;
    }
    // TEXT 字段不能直接建索引（除非前缀索引），跳过
    return false;
}

$created = 0;
$skipped = 0;
$totalRows = 0;
$report = [];

foreach ($files as $f) {
    $table = $prefix . basename($f, ".json");
    $raw = @file_get_contents($f);
    if ($raw === false) {
        continue;
    }
    $rows = json_decode($raw, true);
    if (!is_array($rows)) {
        $report[] = "跳过 $table（不是数组）";
        $skipped++;
        continue;
    }
    if (!$rows) {
        // 空表：只建 id 列，等有数据再 ALTER
        @mysqli_query($c, "CREATE TABLE IF NOT EXISTS `$table` (
            `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $report[] = "建表 $table（空表）";
        $created++;
        continue;
    }

    // 收集所有出现过的字段
    $cols = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        foreach (array_keys($r) as $k) {
            $cols[$k] = true;
        }
    }
    $colNames = array_keys($cols);
    if (!$colNames) {
        $skipped++;
        continue;
    }

    // 两类数据文件：有 id 的沿用原 id；没有 id 的（如 app_devices）用自增。
    // 原来一律 AUTO_INCREMENT，导致 device_id 的值被当成 id，报 Duplicate entry。
    $hasIdColumn = in_array("id", $colNames, true);
    $defs = [];
    if ($hasIdColumn) {
        $defs[] = "`id` INT NOT NULL PRIMARY KEY";
    } else {
        $defs[] = "`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY";
    }
    $indexes = [];
    foreach ($colNames as $name) {
        if ($name === "id") {
            continue;
        }
        // 收集该字段的样例值（最多 5 个），供类型推导判断
        $samples = [];
        foreach ($rows as $rr) {
            if (is_array($rr) && array_key_exists($name, $rr) && $rr[$name] !== null) {
                $samples[] = $rr[$name];
                if (count($samples) >= 5) {
                    break;
                }
            }
        }
        $type = lwColType($name, $samples);
        $defs[] = "`$name` $type";
        if (lwShouldIndex($name, $type)) {
            $indexes[] = "KEY `idx_$name` (`$name`)";
        }
    }
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
  " . implode(",
  ", array_merge($defs, $indexes))
        . "
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!@mysqli_query($c, $sql)) {
        $report[] = "建表失败 $table: " . mysqli_error($c);
        $skipped++;
        continue;
    }

    // 导入数据
    $okRows = 0;
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $keys = [];
        $vals = [];
        foreach ($r as $k => $v) {
            // 跳过未知列（避免 ALTER 后字段不匹配）
            if (!in_array($k, $colNames, true)) {
                continue;
            }
            $keys[] = "`$k`";
            if (is_array($v)) {
                $vals[] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($v)) {
                $vals[] = $v ? 1 : 0;
            } elseif ($v === null) {
                $vals[] = null;
            } else {
                $vals[] = $v;
            }
        }
        if (!$keys) {
            continue;
        }
        $ph = implode(",", array_fill(0, count($keys), "?"));
        $ins = "INSERT INTO `$table` (" . implode(",", $keys) . ") VALUES ($ph)";
        if (lwDbExec($ins, $vals) >= 0) {
            $okRows++;
        }
    }
    $report[] = sprintf("表 %-34s 列 %2d, 导入 %d/%d 条", $table, count($colNames), $okRows, count($rows));
    $created++;
    $totalRows += $okRows;
}

echo "=== 建表完成 ===

";
foreach ($report as $line) {
    echo $line . "
";
}
echo "
共建表 $created 个，跳过 $skipped 个，导入 $totalRows 行
";

// 确认
$r = @mysqli_query($c, "SHOW TABLES");
echo "
数据库现有表数量: " . ($r ? mysqli_num_rows($r) : 0) . "
";
