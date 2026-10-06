<?php
/**
 * 备份导出工具类：用于「我的数据备份（ZIP）」公开下载。
 * 职责：
 *  1) 递归收集全站源代码（跳过 data/、uploads/、.git、config/ 下含密钥文件等），放入 ZIP 的 code/ 前缀；
 *  2) 对 data/*.json 全站数据按「白名单 + 规则函数」脱敏，仅保留申请者本人数据；
 *  3) 生成 ZIP：code/ + data/（脱敏 JSON）+ README_脱敏说明.txt。
 * 仅供 api/backup_download_public.php 调用。
 */
class BackupExporter {

    /** 数据脱敏：原样保留（不含个人数据的站点配置 / 聚合统计） */
    const KEEP_AS_IS = ['settings', 'sensitive_words', 'sponsor', 'visit_stats'];

    /** 数据脱敏：整体清空（纯他人 IP / 令牌 / 设备 / 日志，与申请者无直接关联） */
    const CLEAR_ALL = [
        'ip_blacklist',        // 他人被封禁 IP
        'remember_tokens',     // 他人登录令牌 / IP
        'rate_limits',         // 含他人 IP 的限流键
        'waf_logs',            // 含他人 IP / UA 的访问日志
        'waf_rate_limits',     // 含他人 IP 的限流键
        'illegal_access_logs', // 非法访问日志（含 IP / UA）
        'device_ips',          // 设备 IP 记录
        '_ip_registry',        // IP → QQ 映射，直接关联他人身份
    ];

    /**
     * 代码打包：整体跳过的目录。
     * 补充规则见 collectCodeFiles()：**以 `.` 开头的目录一律跳过**。
     * 编辑器配置（.vscode / .idea …）、版本库元数据（.git）、工具缓存都不属于站点本体，
     * 且在不同机器上名字各不相同 —— 与其逐一举名，不如按前缀统一跳过，也免得
     * 「换一个编辑器就要回来改一次白名单」。
     */
    const SKIP_DIRS = ['data', 'uploads', 'music', 'vendor', 'node_modules'];

    /** 代码打包：按文件名跳过（含密钥 / 数据导出 / 一次性脚本 / 打包产物） */
    const SKIP_FILES = [
        'mail_config.php', 'sync_config.php', 'ai_config.php', // config/ 下含密钥（SMTP 授权码 / 同步密钥 / AI 密钥）
        'love_wall.zip', '_setup_git_gh.py',                    // 本地打包 / 同步产物
        '_sync_pull.json', '_sync_write_local.php', '_challenge_solve.php', // 一次性迁移 / 解密脚本（可能含数据）
        'Thumbs.db', '.DS_Store',
    ];

    /** 代码打包：按扩展名跳过（归档 / 媒体 / 二进制运行件；图片等小体积资源保留） */
    const SKIP_EXT = ['zip', 'tar', 'gz', 'rar', '7z', 'bz2', 'xz', 'mp3', 'mp4', 'webm', 'avi', 'mkv', 'mov', 'wav', 'flac', 'exe', 'dll', 'so', 'dylib', 'log'];

    /** 单文件大小上限，超过则跳过（保留目录结构） */
    const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /** 生成的备份 ZIP 在临时目录里的保留时长（秒）：期间可反复续传，超时后自动清理 */
    const TEMP_TTL = 3600;

    /**
     * 备份临时文件目录。
     * 免费主机常把 sys_get_temp_dir() 指向不存在 / 不可写的路径，也可能在 disable_functions 里
     * 禁掉 tempnam()（此时它返回 false 并只留一条 warning），所以逐个探测候选目录、自己拼随机文件名。
     * 首选站点自己的 data/tmp —— 同目录树的 .htaccess 已拒绝外部直接访问。
     *
     * @return string 可写目录的绝对路径；全部候选都不可用时返回空串
     */
    public static function tempDir() {
        $candidates = [
            dirname(__DIR__) . '/data/tmp',
            dirname(__DIR__) . '/data',
            rtrim((string)sys_get_temp_dir(), "/\\"),
        ];
        foreach ($candidates as $dir) {
            if ($dir === '') {
                continue;
            }
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            if (!is_writable($dir)) {
                continue;
            }
            return $dir;
        }
        return '';
    }

    /** 清理临时目录里过期的备份文件（bk_<token>.zip 及其 meta），避免占用主机配额 */
    public static function purgeExpired($dir, $ttl = self::TEMP_TTL) {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $deadline = time() - (int)$ttl;
        foreach ((array)@glob($dir . '/bk_*') as $file) {
            if (is_file($file) && (int)@filemtime($file) < $deadline) {
                @unlink($file);
            }
        }
    }

    /**
     * 数据脱敏主入口：读取全站 data/*.json，按白名单 + 规则生成脱敏后的表集合。
     *
     * 脱敏总原则（参照个人数据保护合规）：
     *  - 含他人用户 id / qq / ip / 私密内容的表：整体清空，或仅保留与申请者 id/qq 直接相关的条目；
     *  - 不含个人数据的站点配置表：原样保留；
     *  - 匿名 / 隐藏作者的帖子：一律删除，绝不暴露发布者身份。
     *
     * @return array ['<表名>' => 行数组, '_meta' => 元信息]
     */
    public static function sanitizeData($fs, $userId, $userQq) {
        $userId = (int)$userId;
        $userQq = (string)$userQq;
        $dataDir = dirname(__DIR__) . '/data';
        $out = [];

        $files = glob($dataDir . '/*.json') ?: [];
        sort($files);

        foreach ($files as $path) {
            $name = basename($path, '.json');
            $rows = $fs->getAll($name);
            $out[$name] = self::sanitizeTable($name, $rows, $userId, $userQq);
        }

        $out['_meta'] = [
            'app' => 'love_wall',
            'exported_at' => date('Y-m-d H:i:s'),
            'version' => 2,
            'generator' => 'public_backup_download',
            'exported_by' => 'user_' . $userId,
            'note' => '本备份仅含申请人本人数据，他人数据与匿名发布者身份已被移除；详见 README_脱敏说明.txt',
        ];
        return $out;
    }

    /**
     * 单表脱敏：白名单三分类 + 特殊表规则，未命中时走「仅保留与申请者 id/qq 直接相关」兜底。
     */
    private static function sanitizeTable($name, $rows, $userId, $userQq) {
        if (!is_array($rows)) {
            return [];
        }
        // ① 原样保留：不含个人数据的站点配置 / 聚合统计
        if (in_array($name, self::KEEP_AS_IS, true)) {
            return $rows;
        }
        // ② 整体清空：纯他人 IP / 令牌 / 日志类
        if (in_array($name, self::CLEAR_ALL, true)) {
            return [];
        }
        // ③ 特殊表：精确规则
        switch ($name) {
            case 'users':
                return self::sanitizeUsers($rows, $userId);
            case 'posts':
                return self::sanitizePosts($rows, $userId);
            case 'comments':
                return self::sanitizeComments($rows, $userId);
            case 'pm_messages':
                return self::sanitizePmMessages($rows, $userQq);
        }
        // ④ 兜底：仅保留与申请者直接相关的条目（通知/私信已读/点赞/收藏/签到/举报/建议/投票/关注/操作日志等）
        return self::filterByUser($rows, $userId, $userQq);
    }

    /** users：仅保留申请者本人记录；字段白名单化，去除密码/安全戳/登录失败/IP/2FA 等敏感字段 */
    private static function sanitizeUsers($rows, $userId) {
        $keep = ['id', 'qq', 'nickname', 'avatar', 'role', 'created_at'];
        $mine = null;
        foreach ($rows as $u) {
            if (is_array($u) && isset($u['id']) && (int)$u['id'] === $userId) {
                $mine = $u;
                break;
            }
        }
        if ($mine === null) {
            return [];
        }
        $out = [];
        foreach ($keep as $f) {
            if (array_key_exists($f, $mine)) {
                $out[$f] = $mine[$f];
            }
        }
        return [$out];
    }

    /** posts：仅保留申请者本人动态；抹掉可能含他人 id 的可见性/排除字段；投票记录只留本人一票 */
    private static function sanitizePosts($rows, $userId) {
        $out = [];
        foreach ($rows as $p) {
            if (!is_array($p) || !isset($p['user_id']) || (int)$p['user_id'] !== $userId) {
                continue; // 他人的帖子一律删除（含匿名/隐藏作者帖子，绝不暴露发布者身份）
            }
            // 可见范围 / 排除列表可能包含他人用户 id，属于隐私字段，抹掉
            unset($p['visible_to'], $p['exclude_to']);
            // 投票记录 key 为投票者用户 id：只保留申请者本人的一票，其余他人投票清空
            if (isset($p['poll']) && is_array($p['poll']) && isset($p['poll']['votes']) && is_array($p['poll']['votes'])) {
                $votes = [];
                foreach ($p['poll']['votes'] as $opt => $cnt) {
                    if ((string)$opt === (string)$userId) {
                        $votes[$opt] = $cnt;
                    }
                }
                $p['poll']['votes'] = $votes;
            }
            $out[] = $p;
        }
        return $out;
    }

    /** comments：仅保留申请者本人发布的评论 */
    private static function sanitizeComments($rows, $userId) {
        $out = [];
        foreach ($rows as $c) {
            if (is_array($c) && isset($c['user_id']) && (int)$c['user_id'] === $userId) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** pm_messages：仅保留申请者 QQ 参与的私信会话（key 形如 "qq1|qq2"） */
    private static function sanitizePmMessages($rows, $userQq) {
        if ($userQq === '') {
            return [];
        }
        $out = [];
        foreach ($rows as $m) {
            if (!is_array($m)) {
                continue;
            }
            $parts = array_map('trim', explode('|', (string)($m['key'] ?? '')));
            if (in_array($userQq, $parts, true)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** 通用过滤：记录含 user_id/author_id/operator_id 等 id 字段则按 id 匹配；含 qq 字段则按 qq 匹配 */
    private static function filterByUser($rows, $userId, $userQq) {
        $idFields = ['user_id', 'author_id', 'operator_id', 'uid', 'poster_id', 'from_user_id'];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $hit = false;
            foreach ($idFields as $f) {
                if (isset($r[$f]) && is_numeric($r[$f]) && (int)$r[$f] === $userId) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit && $userQq !== '' && isset($r['qq']) && (string)$r['qq'] === $userQq) {
                $hit = true;
            }
            if ($hit) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * 生成备份 ZIP 到 $zipPath。
     * 结构：README_脱敏说明.txt / code/**（源代码）/ data/**（脱敏 JSON，含 _meta.json）。
     * @return bool 成功与否
     */
    public static function createZip($zipPath, $fs, array $user) {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        $userId = (int)($user['id'] ?? 0);
        $userQq = trim((string)($user['qq'] ?? ''));

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // 1) 全站源代码 → code/（跳过 data、uploads、config 密钥文件、.git 等；大文件/归档/媒体跳过，保留目录结构）
        $items = self::collectCodeFiles(dirname(__DIR__));
        $fileEntries = [];
        foreach ($items as $it) {
            if ($it['type'] === 'file') {
                $fileEntries[] = $it['entry'];
            }
        }
        foreach ($items as $it) {
            if ($it['type'] === 'file') {
                $zip->addFile($it['path'], $it['entry']);
            } else {
                // 目录下没有任何文件时补一个空目录条目，保留目录结构
                $prefix = $it['entry'] . '/';
                $covered = false;
                foreach ($fileEntries as $fe) {
                    if (strpos($fe, $prefix) === 0) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    $zip->addEmptyDir($it['entry']);
                }
            }
        }

        // 2) 脱敏数据 → data/
        $zip->addEmptyDir('data');
        $tables = self::sanitizeData($fs, $userId, $userQq);
        foreach ($tables as $name => $rows) {
            $json = self::jsonEncode($rows);
            if ($json === false) {
                $zip->close();
                return false;
            }
            $zip->addFromString('data/' . $name . '.json', $json);
        }

        // 3) 脱敏说明（UTF-8 文件名，设置编码标志避免解压乱码）
        $readme = 'README_脱敏说明.txt';
        $zip->addFromString($readme, self::readmeText());
        if (method_exists($zip, 'setExternalAttributesName')) {
            $zip->setExternalAttributesName($readme, ZipArchive::OPSYS_UNIX, 0100644 << 16, ZipArchive::FL_ENC_UTF_8);
        }

        return $zip->close();
    }

    /**
     * 递归收集待打包文件。
     * @return array<int, array{path:string, entry:string, type:'file'|'dir'}>
     */
    private static function collectCodeFiles($dir, $base = '') {
        $items = [];
        $entries = @scandir($dir);
        if ($entries === false) {
            return $items;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            $rel = ($base === '') ? $name : $base . '/' . $name;
            if (is_dir($path)) {
                // 以 . 开头的目录（.git / .vscode / .idea 等）一律不打包：
                // 它们是编辑器 / 版本控制 / 工具的元数据，不是站点代码，且随机器而异。
                if ($name !== '' && $name[0] === '.') {
                    continue;
                }
                if (in_array($name, self::SKIP_DIRS, true)) {
                    continue;
                }
                $items[] = ['path' => $path, 'entry' => 'code/' . $rel, 'type' => 'dir'];
                $items = array_merge($items, self::collectCodeFiles($path, $rel));
            } elseif (is_file($path)) {
                if (self::shouldSkipFile($rel, $path)) {
                    continue;
                }
                $items[] = ['path' => $path, 'entry' => 'code/' . $rel, 'type' => 'file'];
            }
        }
        return $items;
    }

    /** 判断单个文件是否跳过（按相对路径命中敏感文件名 / 超大小 / 归档媒体扩展名） */
    private static function shouldSkipFile($rel, $path) {
        $name = basename($rel);
        if (in_array($name, self::SKIP_FILES, true)) {
            return true;
        }
        if ((int)@filesize($path) > self::MAX_FILE_SIZE) {
            return true;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::SKIP_EXT, true);
    }

    /** README_脱敏说明.txt 内容 */
    private static function readmeText() {
        $app = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
        return <<<TXT
{$app} —— 我的数据备份（脱敏说明）
=====================================

本 ZIP 由系统为申请者本人生成，仅含您自己的数据，用于个人留档或迁移。

一、ZIP 结构
  code/   全站源代码
          （已排除：data/ 运行时数据、uploads/ 用户上传、config/ 下含密钥的配置文件、
           .git 等；超过 5MB 的大文件与归档/媒体等二进制文件已跳过，目录结构保留）
  data/   脱敏后的数据（JSON，每张表一个文件；含 _meta.json 元信息）
  README_脱敏说明.txt   本文件

二、数据脱敏规则
  1. users：仅保留您本人的账号记录，且已去除 password_hash、security_stamp、
     登录失败次数/锁定、2FA、IP 等敏感字段。
  2. posts：仅保留您本人发布的动态；他人的动态（含匿名/隐藏作者身份的帖子）一律删除，
     绝不暴露发布者身份。
  3. comments：仅保留您本人发布的评论。
  4. 通知/私信/已读/点赞/收藏/签到/举报/建议/投票/关注/操作日志等：
     仅保留与您本人 id/qq 直接相关的条目。
  5. ip_blacklist、rate_limits、waf_logs、waf_rate_limits、device_ips、
     _ip_registry 等含他人 IP/设备/令牌信息的表已整体清空。
  6. settings、sensitive_words、sponsor 等不含个人数据的站点配置原样保留。

三、注意事项
  · 本备份不含任何其他用户的账号、IP、私信、动态或匿名发布者身份信息。
  · 请勿将本 ZIP 外传；迁移到新环境后请重新配置 SMTP 授权码等敏感配置。

TXT;
    }

    /** 统一 JSON 编码（与 FileStorage 写入格式一致） */
    private static function jsonEncode($data) {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
