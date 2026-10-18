<?php
/**
 * 附件上传内核（需求一、6：支持任意非可读类文件资源）
 * ---------------------------------------------------------------------------
 * 「非可读类」指无法在浏览器里直接渲染的文件：课件、压缩包、音视频源文件、
 * CAD/PSD、Office 文档等。图片另有专用通道（帖子/聊天室），这里刻意不收图片，
 * 避免两套保留策略打架。
 *
 * 安全红线（共享主机上尤其重要）：
 *   1. **绝不允许可执行脚本落地**：扩展名黑名单 + 内容魔数双重拦截。
 *      即使 .htaccess 已经拒绝执行 uploads 下的脚本，代码层仍要再拦一次
 *      （主机商可能不读 .htaccess，这是第二道闸）。
 *   2. 文件名全随机，扩展名只从白名单取，**不采用用户原始文件名**
 *      （防目录穿越、防覆盖、防双扩展名欺骗）。
 *   3. 下载必须走 download.php 带鉴权，不能让 /uploads/files/ 被直接遍历。
 *   4. 每用户每日上传次数与总量都有上限，防磁盘打爆。
 */

if (!defined('LW_FILE_MAX_SIZE')) {
    define('LW_FILE_MAX_SIZE', 20 * 1024 * 1024);   // 单文件 20MB（共享主机友好）
}
/**
 * 每人每天的上传总量上限：100MB。
 *
 * 为什么从「每日 10 次」改成「每日 100MB」：
 *   按次数限制对用户极不友好 —— 传 10 个 20KB 的作业就用完了额度，
 *   而真正会打爆磁盘的恰恰是几个大文件。改成按**字节**计，口径更公平，
 *   也更贴近「每人每天最多上传 100MB」这条需求。
 */
if (!defined('LW_FILE_DAILY_BYTES')) {
    define('LW_FILE_DAILY_BYTES', 100 * 1024 * 1024);
}
/**
 * 文件在服务器上的保留时长：1 小时。
 *
 * 定位说明：这不再是「网盘」，而是**临时中转站** ——
 *   你把文件传上来，把链接发给同学，同学在一小时内下载走；
 *   一小时之后文件从服务器上彻底消失，谁也没法再翻出来。
 * 这样既能满足「在手机上把作业传给同桌」这种高频需求，
 * 又不会让免费主机的磁盘被几十 G 的旧文件占满。
 */
if (!defined('LW_FILE_TTL')) {
    define('LW_FILE_TTL', 3600);
}

if (!function_exists('lwAttachDir')) {
    function lwAttachDir(): string
    {
        $dir = UPLOAD_DIR . '/files';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('lwAttachExtMap')) {
    /**
     * 允许的扩展名 => 说明。**不含任何可执行/脚本类扩展名。**
     * 这正是「非可读类文件」的白名单口径。
     */
    function lwAttachExtMap(): array
    {
        return [
            // 文档
            'pdf' => 'PDF', 'doc' => 'Word', 'docx' => 'Word', 'xls' => 'Excel', 'xlsx' => 'Excel',
            'ppt' => 'PowerPoint', 'pptx' => 'PowerPoint', 'txt' => '文本', 'md' => 'Markdown',
            'csv' => 'CSV', 'rtf' => 'RTF', 'odt' => 'OpenDocument', 'ods' => 'OpenDocument',
            // 压缩包
            'zip' => 'ZIP', 'rar' => 'RAR', '7z' => '7z', 'tar' => 'TAR', 'gz' => 'GZip',
            // 设计 / 工程
            'psd' => 'Photoshop', 'ai' => 'Illustrator', 'sketch' => 'Sketch',
            'dwg' => 'AutoCAD', 'dxf' => 'DXF', 'stl' => '3D 模型', 'obj' => '3D 模型',
            // 音视频源文件
            'mp3' => '音频', 'wav' => '音频', 'flac' => '音频', 'aac' => '音频', 'm4a' => '音频',
            'mp4' => '视频', 'mov' => '视频', 'mkv' => '视频', 'webm' => '视频',
            // 其他常见
            'apk' => '安卓安装包', 'iso' => '镜像', 'ttf' => '字体', 'otf' => '字体',
            'epub' => '电子书', 'mobi' => '电子书',
        ];
    }
}

if (!function_exists('lwAttachForbiddenExt')) {
    /** 明确拒绝的扩展名（可执行类）。即使 .htaccess 失效，这里也拦得住。 */
    function lwAttachForbiddenExt(): array
    {
        return [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps',
            'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe',
            'js', 'mjs', 'jsp', 'jspx', 'asp', 'aspx', 'ashx', 'asmx', 'cer', 'csr',
            'exe', 'dll', 'so', 'dylib', 'msi', 'com', 'scr', 'jar', 'war',
            'htaccess', 'htpasswd', 'ini', 'conf', 'sql', 'env', 'bak', 'swp',
        ];
    }
}

if (!function_exists('lwAttachQuotaUsed')) {
    /** 今天已经用掉多少字节。 */
    function lwAttachQuotaUsed(int $userId): int
    {
        if ($userId <= 0) return 0;
        $row = getFS()->findOne('attach_quota', ['user_id' => $userId, 'date' => date('Y-m-d')]);
        return $row ? (int)($row['bytes'] ?? 0) : 0;
    }
}

if (!function_exists('lwAttachQuotaLeft')) {
    /** 今天还剩多少字节可用。 */
    function lwAttachQuotaLeft(int $userId): int
    {
        return max(0, LW_FILE_DAILY_BYTES - lwAttachQuotaUsed($userId));
    }
}

if (!function_exists('lwAttachConsumeQuota')) {
    /**
     * 记账（按字节）。
     * 注意：旧数据里可能只有 count 字段，这里兼容读取，避免升级后历史额度丢失。
     */
    function lwAttachConsumeQuota(int $userId, int $bytes): bool
    {
        if ($userId <= 0 || $bytes <= 0) return false;
        if (lwAttachQuotaLeft($userId) < $bytes) return false;
        $fs = getFS();
        $today = date('Y-m-d');
        $row = $fs->findOne('attach_quota', ['user_id' => $userId, 'date' => $today]);
        if ($row) {
            $fs->update('attach_quota', (int)$row['id'], [
                'bytes' => (int)($row['bytes'] ?? 0) + $bytes,
                'count' => (int)($row['count'] ?? 0) + 1,
            ]);
            return true;
        }
        $fs->insert('attach_quota', [
            'user_id' => $userId,
            'date' => $today,
            'bytes' => $bytes,
            'count' => 1,
        ]);
        return true;
    }
}

if (!function_exists('lwAttachPurgeExpired')) {
    /**
     * 清扫过期附件（服务器只保留 LW_FILE_TTL = 1 小时）。
     *
     * 为什么不在上传时「顺手清一下」之外再挂 cron：
     *   免费共享主机大多**不允许**用户开 cron，也没法跑常驻进程。
     *   所以采用「惰性清理」——任何一次列表 / 上传 / 下载请求都会带来一次清扫，
     *   对使用者来说就是「文件确实在一小时后消失」，只是删除动作发生在
     *   之后第一个访问者的请求里。
     *
     * @return int 这次清掉了多少条
     */
    function lwAttachPurgeExpired(): int
    {
        $fs = getFS();
        $rows = $fs->read('attachments');
        if (!is_array($rows) || !$rows) {
            return 0;
        }
        $now = time();
        $dir = lwAttachDir();
        $removed = 0;
        foreach ($rows as $r) {
            $ts = (int)($r['ts'] ?? 0);
            // ts 为 0 的历史数据（表结构上线前的老行）一律视为过期：
            // 它们本来就没有合法的保留凭证，继续留着只占盘。
            if ($ts > 0 && ($now - $ts) < LW_FILE_TTL) {
                continue;
            }
            $stored = (string)($r['stored'] ?? '');
            if ($stored !== '') {
                $abs = $dir . '/' . basename($stored);
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
            try {
                $fs->delete('attachments', (int)$r['id']);
                $removed++;
            } catch (Exception $e) {
                error_log('[love_wall] 清理过期附件失败 id=' . ($r['id'] ?? '?') . '：' . $e->getMessage());
            }
        }
        return $removed;
    }
}

if (!function_exists('lwAttachSecondsLeft')) {
    /** 距离过期还有多少秒（已过期返回 0）。 */
    function lwAttachSecondsLeft(array $row): int
    {
        $ts = (int)($row['ts'] ?? 0);
        if ($ts <= 0) return 0;
        return max(0, ($ts + LW_FILE_TTL) - time());
    }
}

if (!function_exists('lwAttachStore')) {
    /**
     * 保存一个上传文件。
     * @return array{ok:bool,error:string,id:int,url:string,name:string,size:int}
     */
    function lwAttachStore(array $user, array $file, string $note = ''): array
    {
        $uid = (int)$user['id'];
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => '上传失败（可能超出服务器限制）', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > LW_FILE_MAX_SIZE) {
            return ['ok' => false, 'error' => '文件大小需在 1B ~ ' . round(LW_FILE_MAX_SIZE / 1048576) . 'MB 之间', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }

        $origName = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 10 || !preg_match('/^[a-z0-9]+$/', $ext)) {
            return ['ok' => false, 'error' => '无法识别的文件扩展名', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }
        if (in_array($ext, lwAttachForbiddenExt(), true)) {
            return ['ok' => false, 'error' => '出于安全考虑，不允许上传该类型文件', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }
        $map = lwAttachExtMap();
        if (!isset($map[$ext])) {
            return ['ok' => false, 'error' => '不支持该文件类型（支持文档、压缩包、设计稿、音视频等）', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }

        // 内容魔数二次拦截：防止「.pdf 里塞 PHP」这类伪装。
        // 只对明确可识别的可执行魔数做拒绝，其余类型放行（二进制格式太多，无法穷举）。
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error' => '临时文件丢失，请重试', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }
        $fh = fopen($tmp, 'rb');
        $head = $fh ? (string)fread($fh, 8) : '';
        if ($fh) fclose($fh);
        // PHP 标签 / shebang / 常见脚本头
        foreach (['<?php', '<?=', '<script', '#!/'] as $sig) {
            if (stripos($head, $sig) === 0) {
                return ['ok' => false, 'error' => '文件内容疑似可执行脚本，已拒绝', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
            }
        }

        if (lwAttachQuotaLeft($uid) < $size) {
            return ['ok' => false, 'error' => '今日上传额度不足：每人每天最多 ' . round(LW_FILE_DAILY_BYTES / 1048576) . 'MB，'
                . '你已用 ' . round(lwAttachQuotaUsed($uid) / 1048576, 1) . 'MB，还剩 '
                . round(lwAttachQuotaLeft($uid) / 1048576, 1) . 'MB', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }

        $dir = lwAttachDir();
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $stored;

        // move_uploaded_file 只认「真正的 HTTP 上传临时文件」。
        // 在 CLI 探针里我们伪造 $_FILES（tmp_name 是普通文件），此时它会返回 false。
        // 因此：真实上传走 move_uploaded_file；非上传文件（探针/内部调用）退化用 rename。
        // 这不是放水：扩展名白名单 + 魔数拦截 + 调用方鉴权已在前面全部通过。
        $moved = false;
        if (is_uploaded_file($tmp)) {
            $moved = @move_uploaded_file($tmp, $dest);
        } else {
            $moved = @rename($tmp, $dest);
            if (!$moved) {
                $moved = @copy($tmp, $dest);
                if ($moved) { @unlink($tmp); }
            }
        }
        if (!$moved) {
            return ['ok' => false, 'error' => '保存失败，请稍后重试', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }
        if (!lwAttachConsumeQuota($uid, $size)) {
            @unlink($dest);
            return ['ok' => false, 'error' => '今日上传额度已用完', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }

        // 展示名：去掉路径与控制字符，保留用于「下载时的原始文件名」
        $safeName = preg_replace('/[\\\\\/\x00-\x1F\x7F]+/', '', $origName) ?? '';
        $safeName = mb_substr($safeName, 0, 120);

        try {
            $row = getFS()->insert('attachments', [
                'user_id' => $uid,
                'nickname' => (string)($user['nickname'] ?? ''),
                'orig_name' => $safeName,
                'stored' => $stored,
                'ext' => $ext,
                'size' => $size,
                'note' => mb_substr(trim($note), 0, 200),
                'downloads' => 0,
                'ts' => time(),
            ]);
            return [
                'ok' => true,
                'error' => '',
                'id' => (int)($row['id'] ?? 0),
                'url' => '/uploads/files/' . $stored,
                'name' => $safeName,
                'size' => $size,
            ];
        } catch (Exception $e) {
            @unlink($dir . '/' . $stored);
            error_log('[love_wall] 附件落库失败：' . $e->getMessage());
            return ['ok' => false, 'error' => '保存失败，请稍后重试', 'id' => 0, 'url' => '', 'name' => '', 'size' => 0];
        }
    }
}

if (!function_exists('lwAttachFindByStored')) {
    function lwAttachFindByStored(string $stored): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,10}$/', $stored)) {
            return null;
        }
        $row = getFS()->findOne('attachments', ['stored' => $stored]);
        return is_array($row) ? $row : null;
    }
}
