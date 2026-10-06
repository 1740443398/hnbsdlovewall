<?php
/**
 * 备份打包 / 解析（数据 + 图片统一走这里）
 * ---------------------------------------------------------------------------
 * 为什么要有这一层：
 *   最早「导出」是把一大段 JSON 复制到剪贴板、「导入」是把 JSON 粘回文本框 ——
 *   数据量一大就不可用（剪贴板塞不下、粘错一个字符整包作废、无法校验完整性）。
 *   随后统一成 ZIP（只含 data/*.json），但图片实体在 uploads/ 另存一份，
 *   留档要下两个包、还原要分两步，容易漏。
 *   现在**一个包就是完整备份**：数据表 + uploads 图片，导出一次、上传一次就还原。
 *
 * ZIP 结构：
 *   <table>.json        每个数据表一个文件，内容就是该表的行数组
 *   uploads/<相对路径>   uploads/ 下的图片实体（保留子目录）
 *   _meta.json          导出来源、时间、版本、图片清单
 *   README_还原说明.txt  还原说明
 *
 * 兼容性：
 *   - 旧版「仅数据 ZIP」仍可导入（没有 uploads/ 条目就只还原数据）。
 *   - 旧版「图片 ZIP」（media/ + manifest.json）也可导入，media/ 会被识别为图片。
 *   - .json 单文件备份仍可导入。
 */

if (!defined('MAX_BACKUP_IMPORT_SIZE')) {
    // 完整备份（数据 + 图片）可能远大于单张图片的 10MB 上限，单独给一个宽松值。
    // 真正生效的上限还受 php.ini 的 post_max_size / upload_max_filesize 约束。
    define('MAX_BACKUP_IMPORT_SIZE', 256 * 1024 * 1024);
}

if (!class_exists('BackupPack')) {
    class BackupPack
    {
        /** 备份格式版本：1 = 仅数据；2 = 数据 + 图片 */
        const FORMAT_VERSION = 2;

        /** 导出/导入的数据表白名单（防止写出任意文件） */
        public static function allowedTables(): array
        {
            return [
                'users', 'posts', 'comments', 'post_likes', 'post_favorites',
                'notifications', 'settings', 'sensitive_words', 'sponsor',
                'operation_logs', 'user_activity_logs', 'ip_blacklist', 'ai_logs',
                'admin_permissions', 'pm_messages', 'pm_read', 'pm_reports',
                'feature_requests', 'checkins', 'checkin_records', 'remember_tokens',
            ];
        }

        /** 允许被还原的图片后缀（白名单，杜绝从备份包里写出可执行脚本） */
        public static function allowedUploadExts(): array
        {
            return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'ico', 'svg'];
        }

        public static function dataDir(): string
        {
            return (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/data';
        }

        public static function uploadsDir(): string
        {
            return (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/uploads';
        }

        /**
         * 收集全站数据表 + _meta。
         * @param object $fs  FileStorage 实例
         * @return array{table=>array, _meta=>array}
         */
        public static function collectTables($fs, string $generator = 'admin_backup'): array
        {
            $dataDir = self::dataDir();
            $out = [];
            foreach (@glob($dataDir . '/*.json') ?: [] as $path) {
                $name = basename($path, '.json');
                // 跳过临时/派生文件（备份自身的中间产物），否则会把 zip 里的东西又打进去
                if (strpos($name, 'bk_') === 0 || strpos($name, '_wtest') === 0) {
                    continue;
                }
                $out[$name] = $fs->getAll($name);
            }
            $out['_meta'] = [
                'app' => 'love_wall',
                'exported_at' => date('Y-m-d H:i:s'),
                'version' => self::FORMAT_VERSION,
                'generator' => $generator,
                'exported_by' => 'super_admin',
                'contains_uploads' => true,
                'uploads' => self::uploadsIndex(),
            ];
            return $out;
        }

        /** 递归列出 uploads/ 下所有文件（相对路径 + 字节数 + SHA-1） */
        public static function listUploadFiles(): array
        {
            $dir = self::uploadsDir();
            $files = [];
            if (!is_dir($dir)) {
                return $files;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $fi) {
                    if (!$fi->isFile()) {
                        continue;
                    }
                    $rel = str_replace('\\', '/', substr($fi->getPathname(), strlen($dir) + 1));
                    // 站点保护文件（.htaccess 等）不进备份，也不参与还原
                    if ($rel === '' || substr(basename($rel), 0, 1) === '.') {
                        continue;
                    }
                    $files[] = [
                        'path' => $rel,
                        'abs' => $fi->getPathname(),
                        'bytes' => (int)$fi->getSize(),
                        'sha1' => @sha1_file($fi->getPathname()) ?: '',
                        'mtime' => date('Y-m-d H:i:s', (int)@filemtime($fi->getPathname())),
                    ];
                }
            } catch (Exception $e) {
                return [];
            }
            return $files;
        }

        /** uploads/ 文件清单（_meta 用） */
        public static function uploadsIndex(): array
        {
            $files = self::listUploadFiles();
            $slim = [];
            foreach ($files as $f) {
                $slim[] = ['path' => $f['path'], 'bytes' => $f['bytes']];
            }
            return [
                'total_files' => count($slim),
                'total_bytes' => array_sum(array_column($slim, 'bytes')),
                'files' => $slim,
            ];
        }

        /**
         * 把数据表（+ 可选图片）打成 ZIP。
         * @param bool $withUploads 是否把 uploads/ 里的图片实体一起写进去
         */
        public static function writeZip(string $zipPath, array $tables, bool $withUploads = false): bool
        {
            if (!class_exists('ZipArchive')) {
                return false;
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return false;
            }
            foreach ($tables as $name => $rows) {
                $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    $zip->close();
                    return false;
                }
                $zip->addFromString($name . '.json', $json);
            }

            $uploadCount = 0;
            if ($withUploads) {
                foreach (self::listUploadFiles() as $f) {
                    if (@$zip->addFile($f['abs'], 'uploads/' . $f['path'])) {
                        $uploadCount++;
                    }
                }
            }

            $readme = 'README_还原说明.txt';
            $zip->addFromString($readme, self::readmeText($withUploads, $uploadCount));
            if (method_exists($zip, 'setExternalAttributesName')) {
                $zip->setExternalAttributesName($readme, ZipArchive::OPSYS_UNIX, 0100644 << 16, ZipArchive::FL_ENC_UTF_8);
            }
            return $zip->close();
        }

        /** 完整备份（数据 + 图片）：导出的默认行为 */
        public static function writeFullZip(string $zipPath, array $tables): bool
        {
            return self::writeZip($zipPath, $tables, true);
        }

        /** 去掉 UTF-8 BOM —— Windows 记事本 / 部分导出工具会在文件开头写入 EF BB BF，
         *  json_decode 遇到 BOM 会直接判为非法 JSON，报出「格式不正确」这种误导性提示。 */
        private static function stripBom(string $s): string
        {
            return (substr($s, 0, 3) === "\xEF\xBB\xBF") ? substr($s, 3) : $s;
        }

        /**
         * 从上传的备份文件解析出 {表名: 行数组}（只要数据，兼容旧调用方）。
         * @return array{table=>array} 失败返回空数组
         */
        public static function readBackupFile(string $path, string $origName = ''): array
        {
            $full = self::readFullBackup($path, $origName);
            return $full['tables'];
        }

        /**
         * 解析完整备份文件：数据表 + 图片条目清单。
         *
         * 图片**不在这里读进内存**（大站会撑爆 memory_limit），只返回条目名，
         * 真正落盘交给 restoreUploads() 逐条流式解压。
         *
         * @return array{tables:array, uploads:array<int,array{entry:string,rel:string}>, isZip:bool}
         */
        public static function readFullBackup(string $path, string $origName = ''): array
        {
            $out = ['tables' => [], 'uploads' => [], 'isZip' => false];
            $ext = strtolower(pathinfo($origName !== '' ? $origName : $path, PATHINFO_EXTENSION));

            if ($ext === 'zip' && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($path) !== true) {
                    return $out;
                }
                $out['isZip'] = true;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if (!$entry) {
                        continue;
                    }
                    $entry = str_replace('\\', '/', $entry);

                    // 图片条目：uploads/** 或旧版图片包的 media/**
                    $rel = self::uploadRelFromEntry($entry);
                    if ($rel !== null) {
                        $out['uploads'][] = ['entry' => $entry, 'rel' => $rel];
                        continue;
                    }

                    // 只取 ZIP 顶层的 <table>.json；子目录里的（manifest.json 等）忽略
                    if (substr($entry, -5) !== '.json' || strpos($entry, '/') !== false) {
                        continue;
                    }
                    $rows = json_decode(self::stripBom((string)$zip->getFromIndex($i)), true);
                    if (!is_array($rows)) {
                        continue;
                    }
                    $out['tables'][basename($entry, '.json')] = $rows;
                }
                $zip->close();
                return $out;
            }

            // .json / 未知扩展名：整体当成一个 JSON 包
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                return $out;
            }
            $decoded = json_decode(self::stripBom($raw), true);
            if (is_array($decoded)) {
                $out['tables'] = $decoded;
            }
            return $out;
        }

        /**
         * 把 ZIP 条目名转成「相对 uploads/ 的安全路径」；不是图片条目则返回 null。
         * 安全要点：拒绝 ..、绝对路径、反斜杠、点开头的隐藏文件与非图片后缀。
         */
        public static function uploadRelFromEntry(string $entry): ?string
        {
            $rel = null;
            if (strpos($entry, 'uploads/') === 0) {
                $rel = substr($entry, 8);
            } elseif (strpos($entry, 'media/') === 0) {
                $rel = substr($entry, 6);
            } else {
                return null;
            }

            if ($rel === '' || substr($entry, -1) === '/') {
                return null; // 目录条目
            }
            if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
                return null;
            }
            if ($rel[0] === '/' || $rel[0] === '\\') {
                return null;
            }
            if (preg_match('#[^A-Za-z0-9._/\-]#', $rel)) {
                return null;
            }
            $base = basename($rel);
            if ($base === '' || $base[0] === '.') {
                return null; // .htaccess / .DS_Store 等
            }
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (!in_array($ext, self::allowedUploadExts(), true)) {
                return null;
            }
            return $rel;
        }

        /**
         * 把备份包里的图片流式还原到 uploads/。
         * @return array{written:int, skipped:int, bytes:int, errors:array}
         */
        public static function restoreUploads(string $zipPath, array $entries): array
        {
            $res = ['written' => 0, 'skipped' => 0, 'bytes' => 0, 'errors' => []];
            if (empty($entries)) {
                return $res;
            }
            if (!class_exists('ZipArchive')) {
                $res['errors'][] = 'ZipArchive 未启用，图片未还原';
                return $res;
            }
            $dest = self::uploadsDir();
            if (!is_dir($dest) && !@mkdir($dest, 0755, true) && !is_dir($dest)) {
                $res['errors'][] = 'uploads/ 目录不可创建，图片未还原';
                return $res;
            }
            $destReal = realpath($dest);
            if ($destReal === false) {
                $res['errors'][] = 'uploads/ 目录不可用，图片未还原';
                return $res;
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                $res['errors'][] = '无法打开备份包读取图片';
                return $res;
            }

            foreach ($entries as $item) {
                $rel = (string)($item['rel'] ?? '');
                $entry = (string)($item['entry'] ?? '');
                if ($rel === '' || $entry === '') {
                    $res['skipped']++;
                    continue;
                }
                $target = $destReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                // 二次确认最终路径没跑出 uploads/（防 `a/../../x` 之类的绕过）
                $targetDir = dirname($target);
                if (strpos($targetDir, $destReal) !== 0) {
                    $res['skipped']++;
                    continue;
                }

                $stream = $zip->getStream($entry);
                if (!$stream) {
                    $res['errors'][] = $rel;
                    continue;
                }
                if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                    fclose($stream);
                    $res['errors'][] = $rel;
                    continue;
                }
                $out = @fopen($target, 'wb');
                if (!$out) {
                    fclose($stream);
                    $res['errors'][] = $rel;
                    continue;
                }
                $bytes = @stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                if ($bytes === false) {
                    $res['errors'][] = $rel;
                    continue;
                }
                @chmod($target, 0644);
                $res['written']++;
                $res['bytes'] += (int)$bytes;
            }
            $zip->close();
            return $res;
        }

        /** README.txt 内容 */
        public static function readmeText(bool $withUploads = false, int $uploadCount = 0): string
        {
            $app = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
            $hasUploads = $withUploads ? "  uploads/      帖子图片原始文件（本包含 {$uploadCount} 个），保留 uploads/ 原目录结构\n" : "  （本包不含图片，仅数据）\n";
            $restoreNote = $withUploads
                ? "  提交后系统会**按表覆盖数据**，并把 uploads/ 下的图片按原路径写回。\n  未包含在备份里的表保持原样。"
                : "  系统会按表覆盖写回，其余表不动的保持原样。";
            return <<<TXT
{$app} —— 完整备份（数据 + 图片，ZIP）
=====================================

一、压缩包结构
  <表名>.json         每个数据表一个文件，内容是该表的行数组
{$hasUploads}  _meta.json          导出来源 / 时间 / 版本，以及 uploads/ 图片文件清单
  README_还原说明.txt  本文件

二、如何还原
  后台 → 数据备份/恢复 → 「从备份包恢复」→ 选择本 .zip 上传即可，**一个包全搞定**。
{$restoreNote}

三、注意
  1) 还原是**覆盖式**操作，不可撤销；恢复前请先导出一次当前数据。
  2) 含用户、私信等敏感数据，请勿外传。
  3) 本包只还原 uploads/ 下的图片；站点自身资源（assets/、图标等）不在包内。
TXT;
        }
    }
}
