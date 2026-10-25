<?php
/**
 * 自恢复守护（Self-Healing Guard）
 *
 * =====================================================================
 * 解决什么问题
 * =====================================================================
 * 站点跑在免费共享主机上，会遇到这些问题：
 *
 *   1. 被刷 / 被 CC 攻击  → 响应变慢，正常用户也发不出帖子
 *   2. 磁盘写满           → 发帖写文件失败，但页面还是 200，用户以为发成功了
 *   3. JSON 数据文件损坏   → 读取时 json_decode 失败，整页白屏
 *   4. 某个 API 变慢       → 前端一直转圈，用户狂点，雪上加霜
 *   5. 进程被杀 / 内存爆   → 只剩半截文件，下次读取直接崩
 *
 * 这个文件提供四层自愈，全部是「出问题后自动往回走」：
 *
 *   L1 熔断（Circuit Breaker）
 *      某个操作连续失败 N 次 → 直接快速失败，不再拖慢整体。
 *      冷却一段时间后自动半开试探，成功就恢复。
 *
 *   L2 数据自愈（Data Self-Heal）
 *      JSON 读坏 → 自动从 .bak 恢复；没有备份 → 重建空结构并记录事件，
 *      保证不白屏。
 *
 *   L3 磁盘保护（Disk Guard）
 *      剩余空间低于阈值 → 拒绝写入并给出人话提示，不产生半截文件。
 *
 *   L4 保护模式（Safe Mode）
 *      单位时间内错误率过高 → 自动进入保护模式：
 *      关闭非核心功能（AI、搜索、推荐），只保留浏览和发帖。
 *
 * 所有状态都落在 data/_selfheal.json，可以随时人工查看 / 清空。
 * 不依赖数据库，主机挂了也不影响它本身工作。
 * =====================================================================
 */

if (!defined('LW_SELFHEAL_LOADED')) {
    define('LW_SELFHEAL_LOADED', 1);

    class LWSelfHeal
    {
        /** 熔断器状态文件 */
        private const STATE_FILE = 'data/_selfheal.json';

        /** 日志（保留最近 200 条，超过自动截断） */
        private const LOG_FILE = 'data/_selfheal_log.jsonl';

        /** 熔断参数 */
        private const FAIL_THRESHOLD = 5;      // 连续失败几次触发
        private const OPEN_SECONDS = 30;       // 断开后多久半开
        private const RATE_WINDOW = 60;        // 错误率统计窗口（秒）
        private const RATE_THRESHOLD = 0.35;   // 错误率超过 35% 进保护模式
        private const RATE_MIN_SAMPLES = 20;   // 样本太少不判断（避免冷启动误判）

        /** 磁盘水位 */
        private const DISK_MIN_BYTES = 20 * 1024 * 1024;   // 留 20MB

        private static ?array $state = null;
        private static bool $dirty = false;

        // =================================================================
        // 状态存取
        // =================================================================

        private static function root(): string
        {
            return dirname(__DIR__);
        }

        private static function path(string $rel): string
        {
            return self::root() . '/' . $rel;
        }

        private static function defaultState(): array
        {
            return [
                'version' => 1,
                'updated' => 0,
                // 每个操作的熔断状态
                'breakers' => [],
                // 错误率窗口
                'samples' => ['ok' => 0, 'err' => 0, 'since' => 0],
                // 保护模式
                'safe_mode' => false,
                'safe_until' => 0,
                // 最近事件
                'events' => [],
            ];
        }

        private static function state(): array
        {
            if (self::$state !== null) {
                return self::$state;
            }

            $f = self::path(self::STATE_FILE);
            $s = null;

            if (is_file($f)) {
                $raw = @file_get_contents($f);
                if ($raw !== false && $raw !== '') {
                    $s = json_decode($raw, true);
                }
            }

            if (!is_array($s)) {
                // 文件坏了：尝试 .bak，再不行就重建
                $bak = $f . '.bak';
                if (is_file($bak)) {
                    $raw = @file_get_contents($bak);
                    if ($raw !== false) {
                        $s = json_decode($raw, true);
                        if (is_array($s)) {
                            self::note('state', '状态文件损坏，已从 .bak 恢复');
                        }
                    }
                }
            }

            if (!is_array($s)) {
                $s = self::defaultState();
            }

            // 补字段，兼容老版本文件
            $s = array_merge(self::defaultState(), $s);

            self::$state = $s;
            return $s;
        }

        /** 写回状态（原子写：先写 .tmp 再 rename，避免半截文件）。 */
        private static function save(): void
        {
            if (self::$state === null) {
                return;
            }
            self::$state['updated'] = time();

            // 事件只留最近 40 条
            if (count(self::$state['events']) > 40) {
                self::$state['events'] = array_slice(self::$state['events'], -40);
            }

            $f = self::path(self::STATE_FILE);
            $dir = dirname($f);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $json = json_encode(self::$state, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }

            $tmp = $f . '.tmp.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
                return;
            }

            // 旧文件留一份 .bak，方便下次损坏时恢复
            if (is_file($f)) {
                @copy($f, $f . '.bak');
            }
            @rename($tmp, $f);
        }

        /** 记录一条事件（同时写日志文件，供后台查看）。 */
        public static function note(string $kind, string $msg): void
        {
            $s = self::state();
            $s['events'][] = [
                'at' => date('Y-m-d H:i:s'),
                'kind' => $kind,
                'msg' => mb_substr($msg, 0, 300),
            ];
            self::$state = $s;
            self::$dirty = true;

            // 追加日志（失败无所谓）
            $line = json_encode([
                'at' => date('Y-m-d H:i:s'),
                'ip' => function_exists('lwClientIpSafe') ? lwClientIpSafe() : ($_SERVER['REMOTE_ADDR'] ?? ''),
                'kind' => $kind,
                'msg' => mb_substr($msg, 0, 500),
            ], JSON_UNESCAPED_UNICODE) . "\n";
            @file_put_contents(self::path(self::LOG_FILE), $line, FILE_APPEND | LOCK_EX);
        }

        // =================================================================
        // L1 熔断
        // =================================================================

        /**
         * 能不能执行这个操作？
         *
         * @return array{allowed:bool, reason:string}
         */
        public static function can(string $op): array
        {
            $s = self::state();
            $b = $s['breakers'][$op] ?? null;

            if (!is_array($b) || empty($b['until'])) {
                return ['allowed' => true, 'reason' => ''];
            }

            if (time() < (int)$b['until']) {
                // 还在冷却期
                return ['allowed' => false, 'reason' => 'cooling',
                        'retry_after' => (int)$b['until'] - time()];
            }

            // 冷却结束 → 半开：放一次请求试探
            return ['allowed' => true, 'reason' => 'half_open'];
        }

        /** 报告成功（熔断器归零）。 */
        public static function ok(string $op): void
        {
            $s = self::state();
            if (isset($s['breakers'][$op])) {
                unset($s['breakers'][$op]);
                self::$state = $s;
                self::$dirty = true;
            }
            self::sample(true);
        }

        /** 报告失败（累计到阈值就打开熔断）。 */
        public static function fail(string $op, string $why = ''): void
        {
            $s = self::state();
            $b = $s['breakers'][$op] ?? ['fails' => 0, 'until' => 0, 'opened_at' => 0];
            $b['fails'] = (int)($b['fails'] ?? 0) + 1;
            $b['last'] = date('Y-m-d H:i:s');

            if ($b['fails'] >= self::FAIL_THRESHOLD) {
                $b['until'] = time() + self::OPEN_SECONDS;
                $b['opened_at'] = time();
                $b['fails'] = 0;   // 打开后清零，半开时重新计数
                self::note('breaker', sprintf('熔断打开：%s（%s）', $op, $why));
            }

            $s['breakers'][$op] = $b;
            self::$state = $s;
            self::$dirty = true;

            self::sample(false);
        }

        /**
         * 包一层熔断：执行业务，自动记成功/失败。
         *
         * @param callable $fn 业务函数
         * @param mixed $fallback 熔断或异常时的返回值
         * @return mixed
         */
        public static function guard(string $op, callable $fn, $fallback = null)
        {
            $c = self::can($op);
            if (!$c['allowed']) {
                return $fallback;
            }

            try {
                $r = $fn();
                self::ok($op);
                return $r;
            } catch (Throwable $e) {
                self::fail($op, $e->getMessage());
                return $fallback;
            }
        }

        // =================================================================
        // L4 保护模式
        // =================================================================

        /** 采样一次结果，用于算错误率。 */
        private static function sample(bool $ok): void
        {
            $s = self::state();
            $now = time();

            // 窗口过期就重置
            if (empty($s['samples']['since']) || $now - (int)$s['samples']['since'] > self::RATE_WINDOW) {
                $s['samples'] = ['ok' => 0, 'err' => 0, 'since' => $now];
            }

            if ($ok) {
                $s['samples']['ok']++;
            } else {
                $s['samples']['err']++;
            }

            $total = $s['samples']['ok'] + $s['samples']['err'];
            if ($total >= self::RATE_MIN_SAMPLES) {
                $rate = $s['samples']['err'] / $total;
                if ($rate >= self::RATE_THRESHOLD && !$s['safe_mode']) {
                    $s['safe_mode'] = true;
                    $s['safe_until'] = $now + 120;
                    self::note('safe_mode', sprintf(
                        '错误率 %.0f%% 超过阈值，进入保护模式 2 分钟', $rate * 100
                    ));
                }
            }

            // 保护模式到期自动退出
            if ($s['safe_mode'] && $now >= (int)$s['safe_until']) {
                $s['safe_mode'] = false;
                self::note('safe_mode', '保护模式已到期，恢复正常');
                $s['samples'] = ['ok' => 0, 'err' => 0, 'since' => $now];
            }

            self::$state = $s;
            self::$dirty = true;
        }

        /** 当前是否处于保护模式。 */
        public static function inSafeMode(): bool
        {
            $s = self::state();
            if (empty($s['safe_mode'])) {
                return false;
            }
            if (time() >= (int)$s['safe_until']) {
                $s['safe_mode'] = false;
                self::$state = $s;
                self::$dirty = true;
                return false;
            }
            return true;
        }

        /**
         * 这个功能在保护模式下要不要跳过？
         *
         * 保护模式只关「锦上添花」的功能，浏览和发帖永远保留 ——
         * 用户来这个站就是为了发帖，关掉就本末倒置了。
         */
        public static function skipFeature(string $name): bool
        {
            if (!self::inSafeMode()) {
                return false;
            }
            return in_array($name, ['ai', 'search', 'recommend', 'widgets'], true);
        }

        // =================================================================
        // L3 磁盘保护
        // =================================================================

        /** 磁盘还有空间吗？ */
        public static function diskOk(): bool
        {
            $dir = self::root() . '/data';
            if (!is_dir($dir)) {
                $dir = self::root();
            }
            $free = @disk_free_space($dir);
            if ($free === false) {
                return true;   // 查不到就别拦，避免误伤
            }
            if ($free < self::DISK_MIN_BYTES) {
                self::note('disk', sprintf('磁盘剩余仅 %.1f MB', $free / 1048576));
                return false;
            }
            return true;
        }

        // =================================================================
        // L2 数据自愈
        // =================================================================

        /**
         * 安全读 JSON：坏文件自动恢复。
         *
         * @param string $rel 相对站点根目录的路径
         * @param mixed $default 读不出来时返回的默认值
         * @return mixed
         */
        public static function readJson(string $rel, $default = [])
        {
            $f = self::path($rel);

            if (!is_file($f)) {
                return $default;
            }

            $raw = @file_get_contents($f);
            if ($raw === false || $raw === '') {
                return $default;
            }

            $j = json_decode($raw, true);
            if (is_array($j)) {
                return $j;
            }

            // 坏了：尝试 .bak
            $bak = $f . '.bak';
            if (is_file($bak)) {
                $r2 = @file_get_contents($bak);
                if ($r2 !== false) {
                    $j2 = json_decode($r2, true);
                    if (is_array($j2)) {
                        self::note('heal', 'JSON 损坏，已从备份恢复：' . $rel);
                        return $j2;
                    }
                }
            }

            // 备份也不行：把坏文件改名留证，返回默认值
            $corrupt = $f . '.corrupt.' . date('YmdHis');
            @rename($f, $corrupt);
            self::note('heal', 'JSON 无法恢复，已隔离：' . $rel . ' -> ' . basename($corrupt));
            return $default;
        }

        /**
         * 安全写 JSON：原子写 + 自动备份 + 磁盘检查。
         *
         * @return bool 是否写入成功
         */
        public static function writeJson(string $rel, $data): bool
        {
            if (!self::diskOk()) {
                self::note('disk', '磁盘空间不足，拒绝写入：' . $rel);
                return false;
            }

            $f = self::path($rel);
            $dir = dirname($f);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                self::note('write', '目录创建失败：' . $dir);
                return false;
            }

            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                self::note('write', 'JSON 编码失败：' . $rel);
                return false;
            }

            // 原子写
            $tmp = $f . '.tmp.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
                self::note('write', '写入失败：' . $rel);
                return false;
            }

            // 留一份备份，下次坏了能救
            if (is_file($f)) {
                @copy($f, $f . '.bak');
            }

            if (!@rename($tmp, $f)) {
                @unlink($tmp);
                self::note('write', '重命名失败：' . $rel);
                return false;
            }

            return true;
        }

        // =================================================================
        // 对外：一次请求的收尾
        // =================================================================

        /** 请求结束时调用，把状态落盘（有变化才写，避免每次都写）。 */
        public static function shutdown(): void
        {
            if (self::$dirty && self::$state !== null) {
                self::save();
            }
        }

        /** 供后台展示：完整状态快照。 */
        public static function snapshot(): array
        {
            $s = self::state();
            $s['_safe_mode_now'] = self::inSafeMode();
            $s['_disk_ok'] = self::diskOk();
            $s['_disk_free_mb'] = round((@disk_free_space(self::root() . '/data') ?: 0) / 1048576, 1);
            return $s;
        }

        /** 人工重置（后台按钮用）。 */
        public static function reset(): void
        {
            self::$state = self::defaultState();
            self::$dirty = true;
            self::save();
            self::note('reset', '管理员手动重置了自愈状态');
            self::save();
        }
    }

    // 请求结束时落盘
    register_shutdown_function(['LWSelfHeal', 'shutdown']);
}
