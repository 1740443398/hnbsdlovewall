<?php
/**
 * 排序性能助手
 * ---------------------------------------------------------------------------
 * 站点数据是 JSON 全表存储，列表接口拿到的是「整个数组的副本」，排序是内存操作。
 * 原先大量代码写成：
 *
 *     usort($rows, function($a, $b) {
 *         return strtotime($b['created_at']) - strtotime($a['created_at']);
 *     });
 *
 * 比较器会被调用约 n·log2(n) 次，每次对 **两个元素各解析一次时间字符串**，
 * 也就是说 5000 行数据要跑 12 万次 strtotime() —— 这是本站点最典型的隐藏热点。
 *
 * 解决办法是「装饰—排序—去装饰」（Schwartzian transform）：
 * 先对每一行**只算一次**排序键，排序时比数字，排完再把装饰去掉。
 * 5000 行的解析次数从 12 万降到 5000，且比较本身变成整数比较。
 *
 * 时间字符串缓存：同一请求里同一个时间串可能被算很多次（多张表、多次排序），
 * 用静态数组做一层记忆化。
 */

if (!function_exists('lwTimestampOf')) {
    /**
     * 时间字符串 → Unix 时间戳（带请求内缓存）
     * 非法或缺失的时间按「很早」处理，保证排序稳定不会抛错。
     */
    function lwTimestampOf($value) {
        static $cache = [];
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_string($value)) {
            $value = (string)$value;
        }
        if (isset($cache[$value])) {
            return $cache[$value];
        }
        $ts = strtotime($value);
        if ($ts === false) {
            $ts = 0;
        }
        // 防止缓存被异常大的输入撑爆（正常只有几十个不同时间串）
        if (count($cache) > 20000) {
            $cache = [];
        }
        $cache[$value] = $ts;
        return $ts;
    }
}

if (!function_exists('lwSortByKey')) {
    /**
     * 按「排序键函数」降序/升序排序（装饰—排序—去装饰）。
     *
     * @param array    $rows  待排序数组（原地修改）
     * @param callable $keyFn 接收一行，返回用于比较的标量（数字或字符串）
     * @param bool     $desc  是否降序
     */
    function lwSortByKey(array &$rows, callable $keyFn, bool $desc = true) {
        if (count($rows) < 2) {
            return;
        }
        $decorated = [];
        foreach ($rows as $idx => $row) {
            $decorated[] = [$keyFn($row), $idx, $row];
        }
        usort($decorated, function ($x, $y) use ($desc) {
            if ($x[0] == $y[0]) {
                return $x[1] - $y[1]; // 键相等时按原始顺序，保证稳定排序
            }
            $cmp = ($x[0] < $y[0]) ? -1 : 1;
            return $desc ? -$cmp : $cmp;
        });
        $rows = [];
        foreach ($decorated as $d) {
            $rows[] = $d[2];
        }
    }
}

if (!function_exists('lwSortByCreatedAtDesc')) {
    /**
     * 按 created_at 倒序（最新在前）。缺失时间的行排到最后。
     */
    function lwSortByCreatedAtDesc(array &$rows, string $field = 'created_at') {
        lwSortByKey($rows, function ($row) use ($field) {
            return lwTimestampOf($row[$field] ?? null);
        }, true);
    }
}
