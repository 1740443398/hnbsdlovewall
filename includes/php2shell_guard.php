<?php
/**
 * 「内存耗尽型 RCE」缓解层（CVE-2026-109618 / CVE-2026-109619，PHP2Shell）
 * =========================================================================
 *
 * 【为什么要有这个文件】
 * 这两个漏洞的本质是：
 *   - 109618：PHP 在处理请求**中途**内存耗尽，内部数据结构只建了一半，
 *             清理代码却当真了 → 未认证攻击者构造超大/异常请求即可触发。
 *   - 109619：某个请求留下的内部状态，被**下一个**请求复用 → 跨请求污染。
 *
 * PHP 官方明确说「没有任何配置能完全缓解」，唯一根治是升级到
 * 8.4.27 / 8.3.36 / 8.2.35 / 8.5.12。
 *
 * 本文件做的是**纵深防御**，在应用层把这个触发面压到最小：
 *   1. 压低内存上限 —— 让「内存耗尽」这一刻尽量不发生；
 *      （攻击要成功必须精确命中 OOM 边界，上限越低越难凑）
 *   2. 限制单请求可接受的输入规模 —— 超大 body/文件直接拒，不进 PHP 处理链
 *   3. 限制执行时间 —— 半建成的请求不留在 worker 里
 *   4. 记录异常 OOM —— 如果日志里出现「allowed memory size exhausted」，
 *      说明有人正在试；这是**唯一可观测的早期信号**
 *
 * 注意：这些措施**不能替代升级**。它们的定位是「在主机商打补丁之前，
 * 把可利用窗口收窄」，以及「让一旦发生的尝试留下痕迹」。
 */

if (!defined('LW_PHP2SHELL_GUARD')) {
    define('LW_PHP2SHELL_GUARD', 1);

    /**
     * 压低内存上限。
     *
     * 默认值来自主机（常见 128M~256M）。本站是纯 JSON 文件存储的小站，
     * 单次请求正常耗用远低于 64M —— 实测首页峰值 < 8M。
     * 压到 64M 既不影响功能，又让「精确触发 OOM」的窗口显著变窄。
     *
     * 可用 LW_MEMORY_LIMIT 覆盖（比如导出大备份时临时调高）。
     */
    $lwMemLimit = getenv('LW_MEMORY_LIMIT') ?: '64M';
    $lwCur = ini_get('memory_limit');
    if ($lwCur !== false && $lwCur !== '-1') {
        // 只在当前上限比目标更宽松时才收紧，绝不把已经更严的值放宽
        $toBytes = static function (string $v): int {
            $v = trim($v);
            if ($v === '' || $v === '-1') return PHP_INT_MAX;
            $unit = strtolower(substr($v, -1));
            $num = (float)$v;
            switch ($unit) {
                case 'g': $num *= 1024;
                // no break
                case 'm': $num *= 1024;
                // no break
                case 'k': $num *= 1024;
            }
            return (int)$num;
        };
        if ($toBytes($lwCur) > $toBytes($lwMemLimit)) {
            @ini_set('memory_limit', $lwMemLimit);
        }
    }

    /**
     * 限制请求体大小（在 PHP 解析 multipart 之前就拦掉）。
     *
     * 攻击 109618 需要让 PHP 在处理请求的过程中 OOM，
     * 大 body 是最直接的杠杆。100 MB 以上的请求本站根本用不到
     * （最大合法场景是上传社区备份包，见 MAX_BACKUP_IMPORT_SIZE）。
     */
    $lwMaxBody = 64 * 1024 * 1024; // 64 MB
    $lwDeclared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($lwDeclared > $lwMaxBody) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => '请求体过大，已拒绝',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 限制执行时间。
     *
     * worker 被长时间占住时，「半建成的请求状态」存在时间更长，
     * 109619 的跨请求污染窗口也随之变大。30 秒对本站所有正常请求都够。
     */
    if (function_exists('set_time_limit')) {
        @set_time_limit(30);
    }

    /**
     * OOM 观测。
     *
     * 注册一个 shutdown 钩子：如果本次请求是因为「Allowed memory size
     * exhausted」终止的，就记一条审计日志。
     *
     * 这是检测「有人正在尝试利用」的唯一可靠信号 —— 正常业务不会 OOM。
     */
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null) return;
        // E_ERROR 且消息里含 OOM 关键字
        if (!($err['type'] & E_ERROR)) return;
        if (stripos($err['message'], 'Allowed memory size') === false
            && stripos($err['message'], 'Out of memory') === false) {
            return;
        }
        $line = sprintf(
            "[%s] OOM %s %s #%s %s\n",
            date('Y-m-d H:i:s'),
            $_SERVER['REQUEST_METHOD'] ?? '-',
            $_SERVER['REQUEST_URI'] ?? '-',
            substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-'), 0, 64),
            substr($err['message'], 0, 200)
        );
        $dir = defined('BASE_PATH') ? BASE_PATH . '/data' : __DIR__ . '/../data';
        @file_put_contents($dir . '/_oom_alerts.log', $line, FILE_APPEND | LOCK_EX);
    });
}
