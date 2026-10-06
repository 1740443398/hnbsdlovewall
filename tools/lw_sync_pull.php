<?php
/**
 * ai_sync 拉取工具：从线上 ai_sync 端点取回全量数据，覆盖写入本地 data/。
 * =====================================================================
 * 用法：
 *   php tools/lw_sync_pull.php              # 正常拉取并覆盖
 *   php tools/lw_sync_pull.php --dry-run    # 只拉取对比，不落盘
 *
 * 鉴权：读 config/sync_config.php 里的 secret_key，按
 *   HMAC-SHA256(seed, "时间戳\n方法\n路径\nsha256(请求体)")
 * 现算签名，所以这里不需要「一次性票据」，每次运行都是新的时间戳。
 *
 * 宿主机的 JS 挑战：hnbsd.ct.ws 会返回 slowAES 挑战页（200 + text/html），
 * 必须先解出 __test cookie 再重试，否则拿到的永远是挑战页而不是 JSON。
 * 解法见 solveAesChallenge()：slowAES.decrypt(c, 2, a, b) 等价于标准
 * AES-128-CBC 解密（key=a, iv=b, data=c），明文 hex 就是 cookie 值。
 */

declare(strict_types=1);

const SYNC_PATH = '/api/ai_sync.php';
const SYNC_UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

$ROOT     = dirname(__DIR__);
$DATA_DIR = $ROOT . '/data';
$CFG_FILE = $ROOT . '/config/sync_config.php';
$SITE_DIR = $ROOT . '/config/constants.php';

$dryRun = in_array('--dry-run', $argv, true);

// ---------------------------------------------------------------- 站点地址
function resolveBaseUrl(string $constFile): string {
    if (is_file($constFile)) {
        $t = (string)file_get_contents($constFile);
        if (preg_match("/EXPECTED_HOSTS'\s*,\s*\[([^\]]*)\]/", $t, $m)) {
            $first = trim(explode(',', $m[1])[0], " '\"\t\n\r");
            if ($first !== '' && !in_array($first, ['127.0.0.1', 'localhost'], true)) {
                return 'https://' . $first;
            }
        }
    }
    return 'https://hnbsd.ct.ws';
}

// ---------------------------------------------------------------- 读密钥
function readSeed(string $cfgFile): string {
    if (!is_file($cfgFile)) {
        fwrite(STDERR, "找不到密钥文件：$cfgFile\n");
        exit(1);
    }
    $t = (string)file_get_contents($cfgFile);
    if (!preg_match("/'secret_key'\s*=>\s*'([^']*)'/", $t, $m) || $m[1] === '') {
        fwrite(STDERR, "密钥文件里没读到 secret_key\n");
        exit(1);
    }
    return $m[1];
}

// ---------------------------------------------------------------- 解 JS 挑战
function solveAesChallenge(string $html): ?string {
    $pat = '/var\s+a\s*=\s*toNumbers\("([0-9a-f]+)"\)\s*,\s*'
         . 'b\s*=\s*toNumbers\("([0-9a-f]+)"\)\s*,\s*'
         . 'c\s*=\s*toNumbers\("([0-9a-f]+)"\)/i';
    if (!preg_match($pat, $html, $m)) return null;

    $key = hex2bin($m[1]);
    $iv  = hex2bin($m[2]);
    $ct  = hex2bin($m[3]);
    if (strlen($key) !== 16 || strlen($iv) !== 16 || strlen($ct) % 16 !== 0 || $ct === '') return null;

    $pt = openssl_decrypt($ct, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    return $pt === false ? null : bin2hex($pt);
}

// ---------------------------------------------------------------- 找 CA 证书
function findCaBundle(): ?string {
    $candidates = [
        'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/mingw64/ssl/certs/ca-bundle.crt',
        'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
    ];
    foreach ($candidates as $c) if (is_file($c)) return $c;
    return null;
}

// ---------------------------------------------------------------- HTTP
function httpGet(string $url, ?string $cookieJar, string $ca): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . SYNC_UA,
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Referer: ' . preg_replace('~/api/.*$~', '/', $url),
        ],
    ];
    if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
    if ($cookieJar !== null) { $opts[CURLOPT_COOKIEJAR] = $cookieJar; $opts[CURLOPT_COOKIEFILE] = $cookieJar; }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, (string)$body, $err];
}

function signedUrl(string $base, string $seed, int $offset = 0): string {
    $ts = time() + $offset;
    $canonical = $ts . "\n" . 'GET' . "\n" . SYNC_PATH . "\n" . hash('sha256', '');
    $sig = hash_hmac('sha256', $canonical, $seed);
    return $base . SYNC_PATH . '?ts=' . $ts . '&sig=' . $sig;
}

// ================================================================== 主流程
echo "ai_sync 拉取工具\n";
echo str_repeat('-', 60) . "\n";

$seed = readSeed($CFG_FILE);
$base = resolveBaseUrl($SITE_DIR);
$ca   = findCaBundle();

printf("站点      : %s\n", $base);
printf("本地数据  : %s\n", $DATA_DIR);
printf("CA 证书   : %s\n", $ca ?? '（未找到，将不校验 TLS —— 有中间人风险）');
printf("模式      : %s\n\n", $dryRun ? 'DRY-RUN（不落盘）' : '实际覆盖写入');

$jar = sys_get_temp_dir() . '/lw_sync_jar_' . getmypid() . '.txt';
@unlink($jar);

// --- 第 1 步：拿一次，看是不是挑战页 ---
[$c1, $b1, $e1] = httpGet(signedUrl($base, $seed), null, $ca ?? '');
if ($e1 !== '') { fwrite(STDERR, "请求失败：$e1\n"); exit(1); }
printf("[1/3] 首次请求 HTTP=%d，%d 字节\n", $c1, strlen($b1));

$payload = null;
if (json_decode($b1, true) !== null) {
    $payload = json_decode($b1, true);
    echo "      直接返回 JSON，无需过挑战。\n";
} else {
    $cookie = solveAesChallenge($b1);
    if ($cookie === null) {
        fwrite(STDERR, "      非 JSON 且不是已知挑战页，无法继续。响应前 200 字节：\n" . substr($b1, 0, 200) . "\n");
        exit(1);
    }
    echo "      命中宿主机 JS 挑战，已解出 __test=$cookie\n";

    // 用真实 host 写 cookie jar
    $host = parse_url($base, PHP_URL_HOST);
    file_put_contents($jar,
        "# Netscape HTTP Cookie File\n"
        . "$host\tFALSE\t/\tFALSE\t2147483647\t__test\t$cookie\n");

    // --- 第 2 步：换新时间戳重签 + 带 cookie ---
    [$c2, $b2, $e2] = httpGet(signedUrl($base, $seed, 2), $jar, $ca ?? '');
    if ($e2 !== '') { fwrite(STDERR, "带 cookie 请求失败：$e2\n"); @unlink($jar); exit(1); }
    printf("[2/3] 带 cookie 请求 HTTP=%d，%d 字节\n", $c2, strlen($b2));

    $payload = json_decode($b2, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "      仍非 JSON。响应前 300 字节：\n" . substr($b2, 0, 300) . "\n");
        @unlink($jar);
        exit(1);
    }
}
@unlink($jar);

$meta = $payload['_meta'] ?? [];
unset($payload['_meta']);

// --- 第 3 步：写回 ---
$total = 0; $tables = 0; $written = 0; $failed = [];
$rows = [];
foreach ($payload as $t => $recs) {
    $n = is_array($recs) ? count($recs) : 0;
    $rows[] = [$t, $n];
    $total += $n; $tables++;
}

echo "[3/3] 收到 $tables 张表 / $total 条记录";
if (isset($meta['exported_at'])) echo "（线上导出时间 " . $meta['exported_at'] . "）";
echo "\n\n";

printf("  %-28s %8s   %s\n", '表名', '条数', '状态');
echo '  ' . str_repeat('-', 58) . "\n";

// 先全部写进临时文件，最后统一 rename —— 避免写到一半失败留下半份数据
$staged = [];
foreach ($rows as [$t, $n]) {
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string)$t)) {
        printf("  %-28s %8d   SKIP（表名非法）\n", $t, $n);
        $failed[] = $t;
        continue;
    }
    $recs = is_array($payload[$t]) ? array_values($payload[$t]) : [];
    // 与站点一致的紧凑写入格式
    $json = json_encode($recs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        printf("  %-28s %8d   FAIL（JSON 编码失败）\n", $t, $n);
        $failed[] = $t;
        continue;
    }
    $staged[$t] = $json;
    if ($dryRun) {
        printf("  %-28s %8d   [dry-run] 将写入\n", $t, $n);
    } else {
        printf("  %-28s %8d   OK\n", $t, $n);
        $written++;
    }
}

if ($dryRun) {
    echo "\nDRY-RUN 结束，未改动任何文件。\n";
    exit(0);
}

// 落盘：临时文件 + rename 原子替换
$tmpSuffix = '.sync_tmp_' . getmypid();
$ok = 0;
foreach ($staged as $t => $json) {
    $final = $DATA_DIR . '/' . $t . '.json';
    $tmp   = $final . $tmpSuffix;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) { $failed[] = $t; continue; }
    // 与 FileStorage::writeUnlocked 一致：Windows 上 rename 覆盖已存在文件可能失败，
    // 先删目标再 rename，失败则退化为 copy。
    if (DIRECTORY_SEPARATOR === '\\') @unlink($final);
    if (!@rename($tmp, $final)) {
        if (!@copy($tmp, $final)) { @unlink($tmp); $failed[] = $t; continue; }
        @unlink($tmp);
    }
    $ok++;
}

echo "\n" . str_repeat('-', 60) . "\n";
printf("完成：%d 张表已覆盖写入 %s\n", $ok, $DATA_DIR);
if ($failed) {
    echo "失败：" . implode(', ', $failed) . "\n";
    exit(1);
}
echo "线上数据已同步到本地。\n";
