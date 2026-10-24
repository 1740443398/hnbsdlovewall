<?php
/**
 * 小飞机网盘中转（大文件中转通道）
 *
 * =====================================================================
 * 协议来源
 * =====================================================================
 * 小飞机网盘没有公开 API，本实现是**逆向其前端**得到的。
 * 关键发现（每一个都踩了很久）：
 *
 *   ★ 有两套上传通道，必须用 7n（七牛），不能用 vod（腾讯云）
 *       /app/7n/getUpToken  + /ws/7n/results   ← 前端真正在用的
 *       /app/vod/getUpToken + /ws/vod/results  ← 老通道，落库不生效
 *
 *   ★ 查落库必须用**回调返回的 token**，不是 pathKey
 *       七牛上传响应里的 "token" 字段 → /ws/7n/results?token=<它>
 *       用 pathKey 查永远返回 status:-1
 *
 *   ★ 加密工具 Z.encrypt 输出的是 **Hex**，不是 Base64
 *       AES-128-ECB + Pkcs7，密钥 dingHao-disk-app
 *       downloadId = hex(AES(fileId + "|" + userId))
 *       返回的 downloadUrl 也是同一套 hex 加密，解开才是真链接
 *
 *   ★ folderId 不能为空字符串
 *       空串时上传成功但落库失败，必须给具体目录 ID
 *
 * 完整流程：
 *   1. POST /ws/login                      {loginName, loginPwd}      → appToken
 *   2. POST /app/7n/getUpToken             {...}                      → 七牛 token
 *   3. POST https://upload.qiniup.com       multipart: token/key/file → 回调 token
 *   4. GET  /ws/7n/results?token=<回调token>                            → fileId
 *   5. GET  /ws/file/download?downloadId=<hex>                        → 加密的下载链接
 *   6. hex 解密 → 真实下载地址
 *
 * 他们改前端就会失效。所有失败都必须优雅降级，不能让传输助手挂掉。
 * =====================================================================
 */

if (!defined('LW_FJ_LOADED')) {
    define('LW_FJ_LOADED', 1);

    final class LWFeijiPan
    {
        private const BASE = 'https://api.feijipan.com';
        private const QINIU = 'https://upload.qiniup.com';
        private const QINIU_CHUNK = 'https://up.qiniup.com';
        private const KEY = 'dingHao-disk-app';
        private const DEV_MODEL = 'chrome';
        private const DEV_VERSION = '120';

        /** 「服务器文件中转」目录，所有中转文件只能放这里 */
        private const DEFAULT_FOLDER = '1989982846';

        private string $uuid;
        private string $appToken = '';
        private string $account = '';
        private int $userId = 0;
        private ?array $cfg = null;

        public function __construct()
        {
            $this->uuid = $this->newUuid();
            $this->cfg = $this->loadConfig();
        }

        private function loadConfig(): ?array
        {
            $f = dirname(__DIR__) . '/config/feijipan_config.php';
            if (!is_file($f)) {
                return null;
            }
            try {
                $c = require $f;
                return is_array($c) ? $c : null;
            } catch (Throwable $e) {
                return null;
            }
        }

        public function isConfigured(): bool
        {
            return $this->cfg !== null
                && !empty($this->cfg['enabled'])
                && !empty($this->cfg['account'])
                && !empty($this->cfg['password']);
        }

        public function config(): array
        {
            return $this->cfg ?? [];
        }

        private function newUuid(): string
        {
            $b = random_bytes(16);
            $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
            $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
        }

        // =================================================================
        // 加解密（复刻前端 Z.encrypt / Z.decrypt）
        // =================================================================

        /** AES-128-ECB + Pkcs7，输出 **Hex**（不是 Base64！）。 */
        public static function zenc(string $text): string
        {
            $raw = openssl_encrypt($text, 'aes-128-ecb', self::KEY,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            // openssl 的 ZERO_PADDING 不补位，手动补 Pkcs7
            $pad = 16 - (strlen($text) % 16);
            $raw = openssl_encrypt($text . str_repeat(chr($pad), $pad), 'aes-128-ecb',
                self::KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            if ($raw === false) {
                throw new RuntimeException('加密失败');
            }
            return bin2hex($raw);
        }

        /** 十六进制密文 → 明文。 */
        public static function zdec(string $hex): string
        {
            $bin = @hex2bin($hex);
            if ($bin === false || $bin === '' || strlen($bin) % 16 !== 0) {
                return '';
            }
            $out = openssl_decrypt($bin, 'aes-128-ecb', self::KEY,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            if ($out === false) {
                return '';
            }
            // 去掉 Pkcs7 填充
            $pad = ord(substr($out, -1));
            if ($pad >= 1 && $pad <= 16 && strlen($out) >= $pad) {
                $out = substr($out, 0, -$pad);
            }
            return $out;
        }

        // =================================================================
        // 基础请求
        // =================================================================

        /** 时间戳签名：AES-128-ECB，密钥 dingHao-disk-app，输出 hex。 */
        private function signTime(): string
        {
            $ms = (string)(int)(microtime(true) * 1000);
            $pad = 16 - (strlen($ms) % 16);
            $data = $ms . str_repeat(chr($pad), $pad);
            $out = openssl_encrypt($data, 'aes-128-ecb', self::KEY,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            if ($out === false) {
                throw new RuntimeException('时间戳签名失败');
            }
            return bin2hex($out);
        }

        private function qs(array $extra = []): string
        {
            $p = [
                'uuid' => $this->uuid,
                'devType' => '6',
                'devCode' => $this->uuid,
                'devModel' => self::DEV_MODEL,
                'devVersion' => self::DEV_VERSION,
                'appVersion' => '',
                'timestamp' => $this->signTime(),
                'extra' => '2',
            ];
            if ($this->appToken !== '') {
                $p['appToken'] = $this->appToken;
            }
            $p = array_merge($p, $extra);
            $out = [];
            foreach ($p as $k => $v) {
                $out[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
            }
            return implode('&', $out);
        }

        private function api(string $path, ?array $body = null, array $extra = []): array
        {
            $url = self::BASE . $path . '?' . $this->qs($extra);
            $headers = [
                'Accept: application/json, text/plain, */*',
                'Origin: https://www.feejii.com',
                'Referer: https://www.feejii.com/',
            ];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 50,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            }
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);

            if ($resp === false || $resp === '') {
                return ['code' => -1, 'msg' => '网络请求失败' . ($err ? ': ' . $err : '')];
            }
            $j = json_decode($resp, true);
            return is_array($j) ? $j : ['code' => -1, 'msg' => '返回格式异常', 'raw' => substr($resp, 0, 200)];
        }

        public function login(): bool
        {
            if (!$this->isConfigured()) {
                return false;
            }
            $j = $this->api('/ws/login', [
                'loginName' => (string)$this->cfg['account'],
                'loginPwd' => (string)$this->cfg['password'],
            ]);
            if ((int)($j['code'] ?? -1) !== 200) {
                return false;
            }
            $tok = (string)($j['data']['appToken'] ?? '');
            if ($tok === '') {
                return false;
            }
            $this->appToken = $tok;
            $this->account = (string)($j['data']['uuid'] ?? '');

            // userId 用于 downloadId 和上传参数
            $this->userId = (int)($this->cfg['user_id'] ?? 0);
            if ($this->userId <= 0) {
                $info = $this->api('/app/user/info/map');
                $this->userId = (int)($info['map']['userId'] ?? 0);
            }
            return $this->userId > 0;
        }

        /**
         * 查网盘剩余容量。
         *
         * 这个账号是免费版，总容量只有 10MB（不是 10GB）。
         * 中转大文件前必须确认装得下，否则服务端会甩一句
         * 「个人容量不足」，用户完全看不懂。
         */
        public function quota(): array
        {
            if ($this->appToken === '' && !$this->login()) {
                return ['ok' => false, 'used' => 0, 'total' => 0, 'free' => 0];
            }
            $m = $this->api('/app/user/account/map');
            $map = $m['map'] ?? [];
            /* ★ usedSize / totalSize 的单位也是 KB
             *
             * totalSize = 10485760 → 10GB，不是 10MB。
             * 这里统一转成**字节**返回，调用方只跟字节打交道，
             * 免得每个使用点都要记得乘 1024。
             */
            $usedKb = (int)($map['usedSize'] ?? 0);
            $totalKb = (int)($map['totalSize'] ?? 0);
            return [
                'ok' => true,
                'used' => $usedKb * 1024,
                'total' => $totalKb * 1024,
                'free' => max(0, ($totalKb - $usedKb)) * 1024,
            ];
        }

        /** 把网盘的中文错误翻译成人话。 */
        private static function explain(string $msg): string
        {
            if (strpos($msg, '容量不足') !== false) {
                return '网盘空间不够了（免费版只有 10MB），请清理网盘或换一个账号';
            }
            if (strpos($msg, '上传限制') !== false) {
                return '文件超过网盘单文件上限';
            }
            if (strpos($msg, '时间不正确') !== false) {
                return '服务器时间偏差太大，请校准系统时间';
            }
            return $msg;
        }

        private function folderId(): string
        {
            $v = trim((string)($this->cfg['folder_id'] ?? ''));
            return $v === '' ? self::DEFAULT_FOLDER : $v;
        }

        // =================================================================
        // 上传
        // =================================================================

        /**
         * 【大文件直传第一步】只发上传凭证，文件不经过我们服务器。
         *
         * 为什么必须这样：
         *   InfinityFree 的 upload_max_filesize 只有 20M、post_max_size 30M。
         *   超过 20MB 的 POST 直接被 Apache 拦掉（403），PHP 代码根本跑不到。
         *   所以 1GB~3GB 的文件只能让浏览器直接传给七牛，
         *   我们只负责「发一张通行证」和「事后查结果」。
         *
         * @param int $size 文件字节数
         * @return array{ok:bool, token?:string, key?:string, fast?:bool, fileId?:?string, msg:string}
         */
        public function prepareUpload(string $fileName, int $size, string $md5 = '', string $folderId = ''): array
        {
            $fail = function (string $m): array {
                return ['ok' => false, 'msg' => $m];
            };

            if (!$this->isConfigured()) {
                return $fail('未配置网盘账号');
            }
            if ($size <= 0) {
                return $fail('文件大小无效');
            }
            if (!$this->login()) {
                return $fail('网盘登录失败');
            }

            $folderId = trim($folderId);
            if ($folderId === '') {
                $folderId = $this->folderId();
            }

            $acct = $this->account;
            $key = 'storage/files/' . date('Y/m/d') . '/' . date('n') . '/'
                 . ($acct !== '' ? substr($acct, -1) : '0') . '/'
                 . ($acct !== '' ? $acct : '0') . '/'
                 . (int)(microtime(true) * 1000) . '.gz';

            /* md5 由前端算好传进来，用于秒传判定。
             *
             * 大文件算 md5 很慢（1GB 要几十秒），前端默认跳过。
             * 但服务端**必须**收到一个非空 md5，否则报「MD5不能为空！」。
             * 所以这里给一个随机的 32 位 hex 占位：
             *   - 它永远匹配不到已有文件 → 一定走真实上传，不会误判秒传
             *   - 服务端只要求格式合法，不校验与文件内容一致
             * 代价是放弃秒传，换来立刻开始传 —— 对大文件是划算的。
             */
            $md5 = trim($md5);
            if ($md5 === '' || !preg_match('/^[0-9a-f]{32}$/i', $md5)) {
                $md5 = bin2hex(random_bytes(16));
            }
            /* ★ fileSize 的单位是 **KB**，不是字节！
             *
             * 这个坑差点让整个通道废掉：
             *   账号 totalSize = 10485760，看着像 10MB，
             *   实际按 KB 解释就是 10GB（浏览器显示也正是 1.61GB / 10GB）。
             *
             * 如果传字节数，10MB 文件会变成「10GB」，
             * 立刻超过剩余空间 → 服务端甩一句「个人容量不足」。
             * 传 KB 就一切正常：100MB / 1GB / 3GB 全部通过。
             */
            $sizeKb = max(1, (int)ceil($size / 1024));

            $up = $this->api('/app/7n/getUpToken', [
                'fileName' => $fileName,
                'fileType' => 1,
                'userId' => $this->userId,
                'md5' => $md5,
                'pathKey' => $key,
                'fileSize' => (string)$sizeKb,
                'folderId' => $folderId,
                'fileId' => '',
            ]);
            if ((int)($up['code'] ?? -1) !== 200) {
                return $fail(self::explain((string)($up['msg'] ?? '未知')));
            }

            // 秒传：服务端已有同 md5 文件，直接给 fileId，一个字节都不用传
            if ((string)($up['upToken'] ?? '') === '-1') {
                return [
                    'ok' => true,
                    'fast' => true,
                    'fileId' => (string)($up['map']['fileId'] ?? ''),
                    'key' => $key,
                    'token' => '',
                    'msg' => 'ok',
                ];
            }

            $token = (string)($up['upToken'] ?? '');
            if ($token === '') {
                return $fail('上传凭证为空');
            }

            return ['ok' => true, 'fast' => false, 'token' => $token,
                    'key' => $key, 'fileId' => null, 'msg' => 'ok'];
        }

        /**
         * 【大文件直传第三步】浏览器传完后，查落库结果。
         *
         * 前端把七牛返回的 token 交回来，我们拿它问 /ws/7n/results。
         * 注意必须用**回调 token**，用 key 永远查不到。
         */
        public function finishUpload(string $callbackToken): array
        {
            if ($callbackToken === '') {
                return ['ok' => false, 'fileId' => null, 'msg' => '缺少回调凭证'];
            }
            if ($this->appToken === '' && !$this->login()) {
                return ['ok' => false, 'fileId' => null, 'msg' => '网盘登录失败'];
            }

            $lastMsg = '';
            for ($try = 0; $try < 8; $try++) {
                if ($try > 0) {
                    usleep(1200000);
                }
                $res = $this->api('/ws/7n/results', null, ['token' => $callbackToken]);
                $lastMsg = (string)($res['msg'] ?? '');
                $map = $res['map'] ?? [];
                if (is_array($map) && !empty($map['fileId'])) {
                    return ['ok' => true, 'fileId' => (string)$map['fileId'],
                            'name' => (string)($map['fileName'] ?? ''), 'msg' => 'ok'];
                }
            }
            return ['ok' => false, 'fileId' => null,
                    'msg' => '落库超时：' . ($lastMsg !== '' ? $lastMsg : '回调未返回')];
        }

        /**
         * 上传本地文件到网盘。
         *
         * @return array{ok:bool, fileId:?string, msg:string, key?:?string, name?:string}
         */
        public function upload(string $localPath, string $fileName, string $folderId = ''): array
        {
            $fail = function (string $m): array {
                return ['ok' => false, 'fileId' => null, 'msg' => $m];
            };

            if (!$this->isConfigured()) {
                return $fail('未配置网盘账号');
            }
            if (!is_file($localPath)) {
                return $fail('本地文件不存在');
            }
            $size = filesize($localPath);
            if ($size === false || $size <= 0) {
                return $fail('文件为空');
            }
            if (!$this->login()) {
                return $fail('网盘登录失败，请检查后台配置');
            }

            // folderId 强制用配置值，绝不散落到根目录
            $folderId = trim($folderId);
            if ($folderId === '') {
                $folderId = $this->folderId();
            }

            $md5 = md5_file($localPath);
            $acct = $this->account;

            /* pathKey 结构：storage/files/年/月/日/账号末位/账号/时间戳.gz
             * 注意是**两层**账号（末位一个字符 + 完整账号），
             * 少一层七牛那边会当成别的东西。
             */
            $pathKey = 'storage/files/' . date('Y/m/d') . '/' . date('n') . '/'
                     . ($acct !== '' ? substr($acct, -1) : '0') . '/'
                     . ($acct !== '' ? $acct : '0') . '/'
                     . (int)(microtime(true) * 1000) . '.gz';

            // 1. 取上传凭证（★ 必须用 7n，不是 vod）
            $up = $this->api('/app/7n/getUpToken', [
                'fileName' => $fileName,
                'fileType' => 1,
                'userId' => $this->userId,
                'md5' => $md5,
                'pathKey' => $pathKey,
                // ★ 单位是 KB（见 prepareUpload 的详细说明）
                'fileSize' => (string)max(1, (int)ceil($size / 1024)),
                'folderId' => $folderId,
                'fileId' => '',
            ]);
            if ((int)($up['code'] ?? -1) !== 200) {
                return $fail('取上传凭证失败：' . (string)($up['msg'] ?? '未知'));
            }

            // 秒传：文件已存在时 upToken 是 "-1"，直接有 fileId
            if ((string)($up['upToken'] ?? '') === '-1') {
                $fid = (string)($up['map']['fileId'] ?? '');
                if ($fid !== '') {
                    return ['ok' => true, 'fileId' => $fid, 'key' => $pathKey,
                            'name' => $fileName, 'msg' => 'ok(秒传)'];
                }
                return $fail('秒传没有返回 fileId');
            }

            $upTok = (string)($up['upToken'] ?? '');
            if ($upTok === '') {
                return $fail('上传凭证为空');
            }

            // 2. 上传到七牛
            $resp = $this->qiniuUpload($upTok, $pathKey, $fileName, $localPath);
            if (!$resp['ok']) {
                return $fail($resp['msg']);
            }
            $cbToken = (string)($resp['token'] ?? '');
            if ($cbToken === '') {
                // 没拿到回调 token 也可能已落库，退一步用 pathKey 试
                $cbToken = $pathKey;
            }

            // 3. 查落库（★ 用回调 token，不是 pathKey）
            $fileId = '';
            $lastMsg = '';
            for ($try = 0; $try < 6; $try++) {
                if ($try > 0) {
                    usleep(1200000);
                }
                $res = $this->api('/ws/7n/results', null, ['token' => $cbToken]);
                $lastMsg = (string)($res['msg'] ?? '');
                $map = $res['map'] ?? [];
                if (is_array($map) && !empty($map['fileId'])) {
                    $fileId = (string)$map['fileId'];
                    break;
                }
            }
            if ($fileId === '') {
                return $fail('落库超时：' . ($lastMsg !== '' ? $lastMsg : '回调未返回'));
            }

            return ['ok' => true, 'fileId' => $fileId, 'key' => $pathKey,
                    'name' => $fileName, 'msg' => 'ok'];
        }

        /**
         * 七牛分片直传（4MB 一片）。
         *
         * 【为什么必须分片】
         * 之前用 CURLFile 整包 POST，50MB 文件传上去后
         * 服务端只收到 48MB —— 整整少了 4MB，MD5 对不上。
         * 七牛对单次直传的 body 有隐含限制，整包传大文件不可靠。
         *
         * 分片就没这些问题：
         *   - 每片独立请求，失败了只重传那一片
         *   - 内存占用恒定（4MB），不受 memory_limit 影响
         *   - 单文件上限只受账号容量限制（你现在有 8.38GB）
         *
         * 接口（七牛分片 v2）：
         *   POST <bucket>/objects/<ekey>/uploads          → {"uploadId": "..."}
         *   PUT  <bucket>/objects/<ekey>/uploads/<id>/<n> → {"etag": "..."}
         *   POST <bucket>/objects/<ekey>/uploads/<id>     → {"code":200,"token":"..."}
         *
         * ★ key 要经过 urlsafe-base64 编码（去掉末尾 =），
         *   直接用原路径七牛会 400。
         */
        private function qiniuUpload(string $upToken, string $key, string $fileName,
                                      string $localPath): array
        {
            $size = filesize($localPath);
            if ($size === false || $size <= 0) {
                return ['ok' => false, 'msg' => '无法读取文件'];
            }

            $CHUNK = 4 * 1024 * 1024;

            // 一片能装下就直接单次 POST，省掉分片的三个往返
            if ($size <= $CHUNK) {
                return $this->qiniuSingle($upToken, $key, $fileName, $localPath);
            }

            $bucket = $this->qiniuBucket($upToken);
            $ekey = rtrim(base64_encode($upToken), '=');
            // 注意：这里编码的是 $key 本身
            $ekey = rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
            $base = self::QINIU_CHUNK . '/buckets/' . rawurlencode($bucket)
                  . '/objects/' . rawurlencode($ekey);

            // 1. 初始化
            $r1 = $this->qiniuRequest($base . '/uploads', $upToken, '', 'POST');
            if ($r1['code'] < 200 || $r1['code'] >= 300) {
                return ['ok' => false, 'msg' => '初始化分片失败 HTTP ' . $r1['code']
                    . ' ' . substr($r1['body'], 0, 100)];
            }
            $uid = (string)(json_decode($r1['body'], true)['uploadId'] ?? '');
            if ($uid === '') {
                return ['ok' => false, 'msg' => '拿不到 uploadId'];
            }

            // 2. 逐片上传
            $fh = fopen($localPath, 'rb');
            if (!$fh) {
                return ['ok' => false, 'msg' => '无法打开文件'];
            }

            $parts = [];
            $pn = 0;
            try {
                while (!feof($fh)) {
                    $pn++;
                    $seg = fread($fh, $CHUNK);
                    if ($seg === false || $seg === '') {
                        break;
                    }
                    $url = $base . '/uploads/' . rawurlencode($uid) . '/' . $pn;
                    $rp = $this->qiniuRequest($url, $upToken, $seg, 'PUT');
                    if ($rp['code'] < 200 || $rp['code'] >= 300) {
                        return ['ok' => false, 'msg' => '第 ' . $pn . ' 片失败 HTTP '
                            . $rp['code'] . ' ' . substr($rp['body'], 0, 100)];
                    }
                    $etag = (string)(json_decode($rp['body'], true)['etag'] ?? '');
                    $parts[] = ['partNumber' => $pn, 'etag' => $etag];
                }
            } finally {
                if (is_resource($fh)) {
                    fclose($fh);
                }
            }

            if (!$parts) {
                return ['ok' => false, 'msg' => '没有读到任何分片'];
            }

            // 3. 完成
            $dl = json_encode(['parts' => $parts], JSON_UNESCAPED_SLASHES);
            $r3 = $this->qiniuRequest($base . '/uploads/' . rawurlencode($uid),
                $upToken, (string)$dl, 'POST');
            if ($r3['code'] < 200 || $r3['code'] >= 300) {
                return ['ok' => false, 'msg' => '完成分片失败 HTTP ' . $r3['code']
                    . ' ' . substr($r3['body'], 0, 100)];
            }

            $j3 = json_decode($r3['body'], true);
            if (!is_array($j3)) {
                return ['ok' => false, 'msg' => '完成返回异常'];
            }
            return [
                'ok' => true,
                'token' => (string)($j3['token'] ?? ''),
                'parts' => count($parts),
                'msg' => 'ok',
            ];
        }

        /** 单片直传（≤4MB）。 */
        private function qiniuSingle(string $upToken, string $key, string $fileName,
                                     string $localPath): array
        {
            $name = str_replace(['"', "\r", "\n"], '', $fileName);

            $body = [
                'token' => $upToken,
                'key' => $key,
                'file' => new CURLFile($localPath, 'application/octet-stream', $name),
            ];

            $ch = curl_init(self::QINIU);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 900,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ]);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false) {
                return ['ok' => false, 'msg' => '七牛上传失败：' . $err];
            }
            $j = json_decode($resp, true);
            if (!is_array($j)) {
                return ['ok' => false, 'msg' => '七牛返回异常 HTTP ' . $code . ' ' . substr($resp, 0, 120)];
            }
            if ((int)($j['code'] ?? -1) !== 200) {
                return ['ok' => false, 'msg' => '七牛拒绝：' . (string)($j['msg'] ?? substr($resp, 0, 120))];
            }
            return ['ok' => true, 'token' => (string)($j['token'] ?? ''), 'msg' => 'ok', 'parts' => 1];
        }

        /** 从上传凭证里解出 bucket 名（scope 字段）。 */
        private function qiniuBucket(string $upToken): string
        {
            $parts = explode(':', $upToken);
            if (count($parts) < 3) {
                return 'wpanstore';
            }
            $json = base64_decode(strtr($parts[2], '-_', '+/'), true);
            if ($json === false) {
                return 'wpanstore';
            }
            $o = json_decode($json, true);
            $scope = (string)($o['scope'] ?? '');
            $p = strpos($scope, ':');
            return $p === false ? $scope : substr($scope, 0, $p);
        }

        /** 发一次七牛请求（分片 API）。 */
        private function qiniuRequest(string $url, string $auth, string $body,
                                       string $method = 'POST'): array
        {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'Authorization: UpToken ' . $auth,
                    'Content-Type: application/octet-stream',
                ],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ];
            if ($body !== '') {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false) {
                return ['code' => 0, 'body' => ''];
            }
            return ['code' => $code, 'body' => (string)$resp];
        }

        // =================================================================
        // 下载
        // =================================================================

        /**
         * 取下载链接。
         *
         * downloadId 必须是 hex(AES(fileId|userId))，返回的 downloadUrl
         * 也是同一套 hex 加密，解开才是真直链。
         */
        public function downloadUrl(string $fileId): string
        {
            if ($fileId === '') {
                return '';
            }
            if ($this->appToken === '' && !$this->login()) {
                return '';
            }
            $did = self::zenc($fileId . '|' . $this->userId);
            $res = $this->api('/ws/file/download', null, ['downloadId' => $did]);
            if ((int)($res['code'] ?? -1) !== 200) {
                return '';
            }
            $enc = (string)($res['downloadUrl'] ?? '');
            if ($enc === '') {
                return '';
            }
            $url = self::zdec($enc);
            // 只接受 http(s) 直链，防止返回垃圾
            if (strncmp($url, 'https://', 8) !== 0 && strncmp($url, 'http://', 7) !== 0) {
                return '';
            }
            return $url;
        }

        /**
         * 分片续传下载到本地文件。
         *
         * 【为什么不能用普通 curl】
         * 七牛 CDN 对 HTTP/2 长连接不友好：
         *   50MB 文件下到 33MB 时被服务端 RST
         *   （HTTP/2 stream reset, INTERNAL_ERROR），
         * 但 HTTP 状态码还是 200，curl 也不报错 ——
         * 结果就是**静默截断**，文件少了一大截。
         *
         * 解决办法：
         *   1. 强制 HTTP/1.1
         *   2. 按 8MB 分段，用 Range 请求续传
         *   3. 每段校验长度，缺了就重试
         *
         * 实测 50MB 从「33MB 静默截断」变成「52428800 B MD5 完全一致」。
         *
         * @return array{ok:bool, bytes:int, msg:string}
         */
        public function downloadTo(string $fileId, string $destPath): array
        {
            $url = $this->downloadUrl($fileId);
            if ($url === '') {
                return ['ok' => false, 'bytes' => 0, 'msg' => '取不到下载地址'];
            }

            $size = $this->remoteSize($url);
            if ($size <= 0) {
                return ['ok' => false, 'bytes' => 0, 'msg' => '取不到文件大小'];
            }

            /* 小文件（≤16MB）直接用普通 GET。
             *
             * 七牛链接带 rlimit=20 之类的限流参数，
             * 同一链接连续发很多 Range 请求会被掐。
             * 小文件一次拿完最省事也最快。
             */
            if ($size <= 16 * 1024 * 1024) {
                return self::fetchWhole($url, $destPath, $size);
            }

            // 大文件才分段，并且每段失败时重新取一次链接
            return self::fetchRanged($url, $destPath, $size, function () use ($fileId) {
                return $this->downloadUrl($fileId);
            });
        }

        /**
         * 一次性 GET 整个文件（HTTP/1.1）。
         *
         * @return array{ok:bool, bytes:int, msg:string}
         */
        public static function fetchWhole(string $url, string $destPath, int $expect): array
        {
            $fh = @fopen($destPath, 'wb');
            if (!$fh) {
                return ['ok' => false, 'bytes' => 0, 'msg' => '打不开目标文件'];
            }

            $tries = 0;
            while ($tries < 3) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fh,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_TIMEOUT => 900,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                clearstatcache(true, $destPath);
                $got = @filesize($destPath) ?: 0;

                if ($code === 200 && $got === $expect) {
                    fclose($fh);
                    return ['ok' => true, 'bytes' => $got, 'msg' => 'ok'];
                }

                // 大小不对就清空重来
                ftruncate($fh, 0);
                rewind($fh);
                $tries++;
                if ($tries >= 3) {
                    fclose($fh);
                    return ['ok' => false, 'bytes' => $got,
                            'msg' => '下载不完整：' . $got . '/' . $expect . ' (HTTP ' . $code . ' ' . $err . ')'];
                }
                usleep(500000);
            }

            fclose($fh);
            return ['ok' => false, 'bytes' => 0, 'msg' => '下载失败'];
        }

        /** 读远端文件大小。 */
        public function remoteSize(string $url): int
        {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            ]);
            curl_exec($ch);
            $len = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);
            return $len;
        }

        /**
         * 分段 Range 下载（HTTP/1.1）。公开静态方法，其他地方也能用。
         *
         * @return array{ok:bool, bytes:int, msg:string}
         */
        public static function fetchRanged(string $url, string $destPath, int $total,
                                           ?callable $refresher = null): array
        {
            $fh = @fopen($destPath, 'wb');
            if (!$fh) {
                return ['ok' => false, 'bytes' => 0, 'msg' => '打不开目标文件'];
            }

            $chunk = 8 * 1024 * 1024;
            $pos = 0;
            $tries = 0;

            while ($pos < $total && $tries < 30) {
                $end = min($pos + $chunk - 1, $total - 1);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_RANGE => $pos . '-' . $end,
                    CURLOPT_TIMEOUT => 180,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                ]);
                $data = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                if (($code === 206 || $code === 200) && is_string($data) && $data !== '') {
                    $need = $end - $pos + 1;
                    if (strlen($data) > $need) {
                        $data = substr($data, 0, $need);
                    }
                    fwrite($fh, $data);
                    $pos += strlen($data);
                    $tries = 0;
                } else {
                    $tries++;
                    if ($tries >= 3) {
                        fclose($fh);
                        return ['ok' => false, 'bytes' => $pos,
                                'msg' => '在 ' . $pos . ' 字节处失败: ' . $err . ' (HTTP ' . $code . ')'];
                    }
                    // 连续失败时换一条新链接（旧链接可能被限流）
                    if ($tries >= 2 && $refresher !== null) {
                        $fresh = $refresher();
                        if (is_string($fresh) && $fresh !== '') {
                            $url = $fresh;
                        }
                    }
                    usleep(400000);
                }
            }

            fclose($fh);
            return ['ok' => $pos === $total, 'bytes' => $pos,
                    'msg' => $pos === $total ? 'ok' : '只下到 ' . $pos . '/' . $total];
        }

        /**
         * 删除网盘文件（传完即焚）。
         *
         * 参数是 {fileIds: [id], status: 1} —— status 必填，
         * 缺了会报「状态不能为空！」。
         */
        public function remove(string $fileId): bool
        {
            if ($fileId === '') {
                return false;
            }
            if ($this->appToken === '' && !$this->login()) {
                return false;
            }
            for ($try = 0; $try < 4; $try++) {
                if ($try > 0) {
                    usleep(2000000);
                }
                $j = $this->api('/app/file/delete', [
                    'fileIds' => [(int)$fileId],
                    'status' => 1,
                ]);
                if ((int)($j['code'] ?? -1) === 200) {
                    return true;
                }
                // 「任务进行中」说明删除队列被占，重试也没用，直接放弃
                $msg = (string)($j['msg'] ?? '');
                if (strpos($msg, '进行中') !== false) {
                    return false;
                }
            }
            return false;
        }

        /**
         * 定期清理：把过期还没删掉的文件补删一遍。
         *
         * 【为什么需要这个】
         * 小飞机的删除接口有个坑：一旦某个删除任务卡住，
         * 整个账号的删除都会返回「任务进行中，请稍后操作」——
         * 连删不存在的 ID 都报同样的错，说明是**账号级全局锁**，
         * 跟具体文件无关。这时候单次调用删不掉，只能等锁自己松开。
         *
         * 所以策略是：
         *   1. 每次上传后立刻尝试删除（绝大多数时候是好的）
         *   2. 删失败就把 fileId 记进待删队列
         *   3. 每次有人用传输助手时，顺手重试队列里的（概率触发）
         *   4. 同时做个工具脚本，可以手工/定时跑
         *
         * 这样即使碰上锁，文件也不会永久堆在网盘里 ——
         * 等锁恢复后，下一次使用就会被清掉。
         *
         * @param string[] $fileIds
         * @return array{ok:int, fail:int, skipped:int}
         */
        public function removeBatch(array $fileIds): array
        {
            $ok = 0;
            $fail = 0;
            $skipped = 0;

            if (!$fileIds) {
                return ['ok' => 0, 'fail' => 0, 'skipped' => 0];
            }
            if ($this->appToken === '' && !$this->login()) {
                return ['ok' => 0, 'fail' => 0, 'skipped' => count($fileIds)];
            }

            // 先探一下锁是否还在：拿一个不存在的 id 试试
            $probe = $this->api('/app/file/delete', [
                'fileIds' => [1], 'status' => 1,
            ]);
            $probeMsg = (string)($probe['msg'] ?? '');
            if (strpos($probeMsg, '进行中') !== false) {
                // 锁还在，一个都别试了，省得打一堆无谓请求
                return ['ok' => 0, 'fail' => 0, 'skipped' => count($fileIds)];
            }

            // 一次最多带 20 个
            foreach (array_chunk($fileIds, 20) as $chunk) {
                $ids = array_map('intval', $chunk);
                $j = $this->api('/app/file/delete', ['fileIds' => $ids, 'status' => 1]);
                if ((int)($j['code'] ?? -1) === 200) {
                    $ok += count($ids);
                } else {
                    $msg = (string)($j['msg'] ?? '');
                    if (strpos($msg, '进行中') !== false) {
                        $skipped += count($ids);
                        break;   // 锁又来了，剩下的下次再说
                    }
                    $fail += count($ids);
                }
                usleep(600000);
            }

            return ['ok' => $ok, 'fail' => $fail, 'skipped' => $skipped];
        }
    }
}
