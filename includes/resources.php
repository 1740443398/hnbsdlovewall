<?php
/**
 * 服务器资源监控
 *
 * 【用途】后台仪表盘展示「全站已用了多少空间」。
 *
 * 【为什么自己算而不是调系统命令】
 * 虚拟主机禁用 exec/shell_exec，df/du 都跑不了。
 * 所以只能靠目录遍历统计。
 *
 * 【统计口径】
 *   - 站点总占用 = data/ + uploads/ + assets/ + 代码文件
 *   - data/ 单独列出（用户数据，会持续增长）
 *   - uploads/ 单独列出（图片视频，增长最快）
 *   - 缓存与临时文件单独列（可清理的）
 *
 * 【性能】
 * 目录遍历在共享主机上是有成本的（上千文件要 stat 上千次）。
 * 所以结果缓存 5 分钟，避免每次刷后台都重扫。
 */

if (!defined('LW_RESOURCE_LOADED')) {
    define('LW_RESOURCE_LOADED', 1);

    /** 递归统计目录大小（字节）与文件数。 */
    function lwDirSize(string $dir): array
    {
        $bytes = 0;
        $files = 0;
        if (!is_dir($dir)) {
            return ['bytes' => 0, 'files' => 0];
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $bytes += $f->getSize();
                    $files++;
                }
            }
        } catch (Throwable $e) {
            // 权限问题时返回已知部分
        }
        return ['bytes' => $bytes, 'files' => $files];
    }

    /** 人类可读的大小。 */
    function lwFmtBytes(float $b): string
    {
        if ($b >= 1073741824) {
            return number_format($b / 1073741824, 2) . ' GB';
        }
        if ($b >= 1048576) {
            return number_format($b / 1048576, 1) . ' MB';
        }
        if ($b >= 1024) {
            return number_format($b / 1024, 1) . ' KB';
        }
        return (int)$b . ' B';
    }

    /**
     * 站点资源使用概览。
     *
     * @param int $serverTotalBytes 服务器总容量（5GB = 5368709120）
     */
    function lwResourceUsage(int $serverTotalBytes = 5368709120): array
    {
        $root = dirname(__DIR__);
        $cacheFile = $root . '/data/_resource_cache.json';
        $ttl = 300;

        // 5 分钟缓存
        if (is_file($cacheFile)) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && isset($c['at']) && time() - (int)$c['at'] < $ttl) {
                $c['cached'] = true;
                return $c;
            }
        }

        $data = lwDirSize($root . '/data');
        $uploads = lwDirSize($root . '/uploads');
        $assets = lwDirSize($root . '/assets');
        $other = 0;
        $otherFiles = 0;

        // 代码文件（不含上面三个目录）
        foreach (['admin', 'api', 'config', 'includes', 'pages', 'lang', 'tools', 'download', 'zanzhu'] as $d) {
            $r = lwDirSize($root . '/' . $d);
            $other += $r['bytes'];
            $otherFiles += $r['files'];
        }
        // 根目录文件
        foreach (glob($root . '/*.php') ?: [] as $f) {
            if (is_file($f)) {
                $other += filesize($f) ?: 0;
                $otherFiles++;
            }
        }

        $total = $data['bytes'] + $uploads['bytes'] + $assets['bytes'] + $other;
        $free = @disk_free_space($root);
        $pct = $serverTotalBytes > 0 ? ($total / $serverTotalBytes * 100) : 0;

        $result = [
            'at' => time(),
            'cached' => false,
            'total_bytes' => $total,
            'total_text' => lwFmtBytes($total),
            'server_total_bytes' => $serverTotalBytes,
            'server_total_text' => lwFmtBytes($serverTotalBytes),
            'percent' => round($pct, 2),
            'free_bytes' => $free === false ? null : $free,
            'free_text' => $free === false ? '未知' : lwFmtBytes($free),
            'breakdown' => [
                ['key' => 'uploads', 'label' => '上传文件', 'bytes' => $uploads['bytes'],
                 'text' => lwFmtBytes($uploads['bytes']), 'files' => $uploads['files']],
                ['key' => 'data', 'label' => '用户数据', 'bytes' => $data['bytes'],
                 'text' => lwFmtBytes($data['bytes']), 'files' => $data['files']],
                ['key' => 'assets', 'label' => '静态资源', 'bytes' => $assets['bytes'],
                 'text' => lwFmtBytes($assets['bytes']), 'files' => $assets['files']],
                ['key' => 'code', 'label' => '程序代码', 'bytes' => $other,
                 'text' => lwFmtBytes($other), 'files' => $otherFiles],
            ],
        ];

        // 排序：大的在前
        usort($result['breakdown'], function ($a, $b) {
            return $b['bytes'] <=> $a['bytes'];
        });

        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $result;
    }

    /** PHP 进程自身的内存占用（当前请求）。 */
    function lwPhpMemory(): array
    {
        $used = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = ini_get('memory_limit');
        return [
            'used_text' => lwFmtBytes($used),
            'peak_text' => lwFmtBytes($peak),
            'limit' => $limit,
            'used_bytes' => $used,
            'peak_bytes' => $peak,
        ];
    }
}
