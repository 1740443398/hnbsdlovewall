<?php
/**
 * 备份恢复：分卷上传
 *
 * =====================================================================
 * 为什么需要分卷
 * =====================================================================
 * 线上主机的 PHP 配置允许 post_max_size = 30M，看起来 10MB 的备份包
 * 完全没问题。但实测发现真正的墙在 PHP 之前 —— openresty 网关：
 *
 *     1.0 MB  -> 通过
 *     1.5 MB  -> 通过
 *     2.0 MB  -> 403 Forbidden（网关直接拒绝，PHP 根本没收到请求）
 *
 * 也就是说超过约 1.5MB 的 POST 请求体会被网关拦掉，返回的 403
 * 还不是 PHP 输出的，控制台只会显示 "responded with a status of 403"。
 * 这和 CSRF 失败、权限不足的 403 长得一模一样，极难排查。
 *
 * 结论：任何可能超过 1.5MB 的上传都必须切块。
 *
 * =====================================================================
 * 协议
 * =====================================================================
 *   action=init     { token, name, size, chunks }  -> 建临时目录
 *   action=chunk    { token, index } + file        -> 存第 index 块
 *   action=finish   { token }                      -> 合并成完整文件
 *   action=abort    { token }                      -> 丢弃临时文件
 *
 * 临时目录在 data/_bkimport/<token>/，合并后的文件同样在那里。
 * 每块上限 1MB，实测安全。
 * =====================================================================
 */

if (!defined('LW_BACKUP_CHUNK_LOADED')) {
    define('LW_BACKUP_CHUNK_LOADED', 1);

    /** 每个分卷的大小上限。留足余量，实测 1.5MB 以下稳定。 */
    if (!defined('LW_BACKUP_CHUNK_BYTES')) {
        define('LW_BACKUP_CHUNK_BYTES', 1048576);   // 1 MB
    }

    /** 分卷会话有效期（秒）。超过就自动清掉。 */
    if (!defined('LW_BACKUP_CHUNK_TTL')) {
        define('LW_BACKUP_CHUNK_TTL', 3600);
    }

    /** 分卷临时根目录 */
    function lwBackupChunkDir(): string
    {
        return dirname(__DIR__) . '/data/_bkimport';
    }

    /**
     * 校验 token：只允许十六进制，防止路径穿越。
     */
    function lwBackupChunkSafeToken(string $token): ?string
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) {
            return null;
        }
        return $token;
    }

    /**
     * 取一个会话目录，不存在则抛错。
     */
    function lwBackupChunkSession(string $token): ?string
    {
        $token = lwBackupChunkSafeToken($token);
        if ($token === null) {
            return null;
        }
        $dir = lwBackupChunkDir() . '/' . $token;
        return is_dir($dir) ? $dir : null;
    }

    /**
     * 清理过期的分卷会话。
     *
     * 用概率触发，避免每次请求都扫目录。
     */
    function lwBackupChunkGc(bool $force = false): void
    {
        if (!$force && mt_rand(1, 100) > 15) {
            return;
        }
        $root = lwBackupChunkDir();
        if (!is_dir($root)) {
            return;
        }
        $edge = time() - LW_BACKUP_CHUNK_TTL;
        foreach ((array)@scandir($root) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $dir = $root . '/' . $name;
            if (!is_dir($dir)) {
                continue;
            }
            $mt = @filemtime($dir);
            if ($mt !== false && $mt < $edge) {
                lwBackupChunkRemoveDir($dir);
            }
        }
    }

    /** 递归删除目录（只删本模块建的临时目录）。 */
    function lwBackupChunkRemoveDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        // 安全检查：必须在 _bkimport 下面
        $root = realpath(lwBackupChunkDir());
        $real = realpath($dir);
        if ($root === false || $real === false || strpos($real, $root) !== 0) {
            return;
        }
        foreach ((array)@scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $p = $dir . '/' . $name;
            if (is_dir($p)) {
                lwBackupChunkRemoveDir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    /**
     * 初始化一次分卷上传。
     *
     * @param string $name   原始文件名
     * @param int    $size   文件总字节数
     * @param int    $chunks 分卷数量
     * @return array{ok:bool,token?:string,message?:string}
     */
    function lwBackupChunkInit(string $name, int $size, int $chunks): array
    {
        if ($size <= 0) {
            return ['ok' => false, 'message' => '文件大小无效'];
        }
        if ($chunks <= 0 || $chunks > 5000) {
            return ['ok' => false, 'message' => '分卷数量无效'];
        }
        // 上限 256MB，防止有人在服务器上堆垃圾
        if ($size > 256 * 1024 * 1024) {
            return ['ok' => false, 'message' => '备份包超过 256MB 上限'];
        }

        $root = lwBackupChunkDir();
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            return ['ok' => false, 'message' => '无法创建临时目录，请检查 data 目录权限'];
        }

        // 磁盘余量检查
        if (class_exists('LWSelfHeal') && method_exists('LWSelfHeal', 'diskOk') && !LWSelfHeal::diskOk()) {
            return ['ok' => false, 'message' => '服务器存储空间不足，暂时无法导入'];
        }

        lwBackupChunkGc();

        $token = bin2hex(random_bytes(16));
        $dir = $root . '/' . $token;
        if (!@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'message' => '无法创建分卷目录'];
        }

        @file_put_contents($dir . '/meta.json', json_encode([
            'name'   => mb_substr($name, 0, 200),
            'size'   => $size,
            'chunks' => $chunks,
            'at'     => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));

        return ['ok' => true, 'token' => $token];
    }

    /**
     * 收到一个分卷。
     *
     * @param string $token 会话 token
     * @param int    $index 从 0 开始
     * @param string $tmp   上传的临时文件路径
     * @return array{ok:bool,message?:string,received?:int,total?:int}
     */
    function lwBackupChunkStore(string $token, int $index, string $tmp): array
    {
        $dir = lwBackupChunkSession($token);
        if ($dir === null) {
            return ['ok' => false, 'message' => '分卷会话不存在或已过期，请重新上传'];
        }
        if ($index < 0 || $index > 10000) {
            return ['ok' => false, 'message' => '分卷序号无效'];
        }
        if (!is_uploaded_file($tmp) && !is_file($tmp)) {
            return ['ok' => false, 'message' => '分卷内容为空'];
        }
        $sz = @filesize($tmp);
        if ($sz === false || $sz <= 0) {
            return ['ok' => false, 'message' => '分卷内容为空'];
        }
        // 单块超过 4MB 说明客户端切分逻辑有问题
        if ($sz > 4 * 1024 * 1024) {
            return ['ok' => false, 'message' => '单个分卷过大，请重新切分'];
        }

        $dest = $dir . '/' . sprintf('%05d', $index) . '.part';
        if (!@move_uploaded_file($tmp, $dest) && !@copy($tmp, $dest)) {
            return ['ok' => false, 'message' => '分卷写入失败，请检查磁盘空间'];
        }
        @touch($dir);

        $received = count((array)@glob($dir . '/*.part'));
        $meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
        $total = (int)($meta['chunks'] ?? 0);

        return ['ok' => true, 'received' => $received, 'total' => $total];
    }

    /**
     * 合并分卷，返回完整文件路径。
     *
     * @return array{ok:bool,path?:string,name?:string,message?:string}
     */
    function lwBackupChunkFinish(string $token): array
    {
        $dir = lwBackupChunkSession($token);
        if ($dir === null) {
            return ['ok' => false, 'message' => '分卷会话不存在或已过期，请重新上传'];
        }

        $meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
        if (!is_array($meta)) {
            return ['ok' => false, 'message' => '分卷元数据丢失，请重新上传'];
        }
        $chunks = (int)($meta['chunks'] ?? 0);
        $expectSize = (int)($meta['size'] ?? 0);
        $name = (string)($meta['name'] ?? 'backup.zip');

        $parts = (array)@glob($dir . '/*.part');
        sort($parts, SORT_STRING);
        if (count($parts) !== $chunks) {
            return [
                'ok' => false,
                'message' => '分卷不完整（收到 ' . count($parts) . ' / ' . $chunks . '），请重试',
            ];
        }

        $out = $dir . '/merged.zip';
        $fh = @fopen($out, 'wb');
        if (!$fh) {
            return ['ok' => false, 'message' => '无法写入合并文件，请检查磁盘空间'];
        }
        foreach ($parts as $p) {
            $in = @fopen($p, 'rb');
            if (!$in) {
                fclose($fh);
                return ['ok' => false, 'message' => '分卷读取失败'];
            }
            stream_copy_to_stream($in, $fh);
            fclose($in);
            @unlink($p);   // 合并后立即删掉分卷，省空间
        }
        fclose($fh);

        $realSize = @filesize($out);
        if ($realSize === false || ($expectSize > 0 && $realSize !== $expectSize)) {
            @unlink($out);
            return [
                'ok' => false,
                'message' => '合并后大小不符（期望 ' . $expectSize . '，实际 ' . (int)$realSize . '），请重新上传',
            ];
        }

        return ['ok' => true, 'path' => $out, 'name' => $name];
    }

    /**
     * 丢弃一次分卷会话。
     */
    function lwBackupChunkAbort(string $token): void
    {
        $dir = lwBackupChunkSession($token);
        if ($dir !== null) {
            lwBackupChunkRemoveDir($dir);
        }
    }
}
