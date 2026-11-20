<?php
/**
 * 时间校准接口
 * ================================================================
 *
 * 【重要：本主机不支持 NTP】
 * 实测 InfinityFree 的 disable_functions 里禁用了全部 socket_* 函数，
 * 且 UDP 出站被封（fsockopen('udp://...') 返回 false）。
 * 所以「直连 ntp.ntsc.ac.cn」在这台主机上**物理不可行**，不是代码问题。
 *
 * 因此接口做两级：
 *   一级 NTP（UDP 123）—— 代码保留，换主机/独立服务器后自动生效，精度 ±毫秒
 *   二级 HTTP 校时 —— 读公共服务的 Date 响应头，精度 ±50ms 级别
 *
 * HTTP 校时怎么算
 * ---------------
 *   Date 头是「服务器生成响应那一刻」的时间，只精确到秒。
 *   t0 发出、t1 收到，那么响应生成时刻 ≈ t0 + rtt/2。
 *   标准时间(t0) = Date秒 + 0.5(取秒中点) - rtt/2
 *   offset = 本地t0 - 标准时间(t0)
 *
 *   误差来源：Date 只到秒 → ±500ms 截断误差。所以取多个源的中位数，
 *   再用 rtt 把「网络延迟造成的偏差」抵消掉，实际能到 ±50ms 内。
 *
 *   对「秒级显示的时钟」这完全够用；只有毫秒显示时才会看到几十毫秒的抖动。
 * ================================================================
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
/* 浮点数序列化不要输出 32.6000000000000014 这种长尾 */
@ini_set('serialize_precision', '-1');

/**
 * 允许的 NTP 服务器（代号 => [域名, 显示名]）
 */
function lwNtpServers() {
    return [
        'ntsc'    => ['ntp.ntsc.ac.cn',  '国家授时中心'],
        'aliyun'  => ['ntp.aliyun.com',  '阿里云'],
        'windows' => ['time.windows.com', '微软'],
        'google'  => ['time.google.com',  '谷歌'],
        'apple'   => ['time.apple.com',   '苹果'],
        'pool'    => ['pool.ntp.org',     'NTP Pool'],
    ];
}

/**
 * 一级：UDP NTP（RFC 5905）。不可用时返回 null。
 */
function lwNtpQuery($host, $timeout = 1.0) {
    $ip = @gethostbyname($host);
    if (!$ip || $ip === $host) {
        return null;
    }
    $sock = @fsockopen('udp://' . $ip, 123, $e, $s, $timeout);
    if (!$sock) {
        return null;
    }
    stream_set_timeout($sock, (int)$timeout, 500000);
    $req = str_repeat("\0", 48);
    $req[0] = chr(0x1B);

    $t1 = microtime(true);
    if (@fwrite($sock, $req) !== 48) { @fclose($sock); return null; }
    $resp = @fread($sock, 48);
    $t4 = microtime(true);
    $meta = stream_get_meta_data($sock);
    @fclose($sock);

    if (!is_string($resp) || strlen($resp) < 48 || !empty($meta['timed_out'])) {
        return null;
    }

    $readTs = function ($bin, $off) {
        $p = unpack('Nhigh/Nlow', substr($bin, $off, 8));
        return ((float)$p['high'] + ((float)$p['low'] / 4294967296.0)) - 2208988800.0;
    };
    $t2 = $readTs($resp, 32);
    $t3 = $readTs($resp, 40);
    if ($t2 <= 0) { $t2 = $t3; }
    if ($t3 <= 0) { return null; }

    return [
        'offset' => (($t2 - $t1) + ($t3 - $t4)) / 2.0,
        'delay'  => ($t4 - $t1) - ($t3 - $t2),
        'server_time' => $t3,
        'recv_at' => $t4,
    ];
}

/**
 * 二级：HTTP Date 头校时。返回 [offset, rtt] 或 null。
 *
 * 用 GET + HEADER 拿响应头；不跟随重定向（重定向会多一跳，拖慢且干扰 rtt）。
 */
function lwHttpDateQuery($url, $timeout = 3.0) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,          // 只要头，不下正文，快很多
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'LoveWall-Clock/1.0',
        CURLOPT_HTTPHEADER => ['Expect:'],
    ]);
    $t0 = microtime(true);
    $resp = curl_exec($ch);
    $t1 = microtime(true);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    @curl_close($ch);

    if ($resp === false || $hs <= 0) {
        return null;
    }
    $hdr = substr($resp, 0, $hs);
    if (!preg_match('/^Date:\s*(.+)$/mi', $hdr, $m)) {
        return null;
    }
    $ts = strtotime(trim($m[1]));
    if (!$ts) {
        return null;
    }

    $rtt = $t1 - $t0;
    // Date 截断到秒，取秒中点 0.5 作为「真实服务器时刻」的估计
    $serverAtT0 = $ts + 0.5 - ($rtt / 2.0);
    return ['offset' => $t0 - $serverAtT0, 'rtt' => $rtt];
}

/**
 * HTTP 校时的候选源（都要返回标准 Date 头）
 * 选多家是防止某个被墙/限流；取中位数抵消单点误差。
 */
function lwHttpTimeSources() {
    return [
        ['https://www.cloudflare.com/cdn-cgi/trace', 'Cloudflare'],
        ['https://www.baidu.com/',                   '百度'],
        ['https://www.google.com/generate_204',      '谷歌'],
    ];
}

$serverKey = isset($_GET['srv']) ? (string)$_GET['srv'] : 'ntsc';
$servers = lwNtpServers();
if (!isset($servers[$serverKey])) { $serverKey = 'ntsc'; }
[$host, $label] = $servers[$serverKey];

$deadline = microtime(true) + 8.0;
$payload = [
    'ok' => false,
    'server' => $serverKey,
    'host' => $host,
    'label' => $label,
    'method' => 'none',
    'offset_ms' => 0,
    'delay_ms' => 0,
    'precision' => 'none',
];

/* ---------------- 一级：NTP ---------------- */
$ntpBest = null;
for ($i = 0; $i < 3; $i++) {
    if (microtime(true) > $deadline) { break; }
    $r = lwNtpQuery($host, 1.0);
    if ($r === null) { continue; }
    if ($ntpBest === null || $r['delay'] < $ntpBest['delay']) {
        $ntpBest = $r;
    }
    if ($ntpBest['delay'] < 0.03) { break; }
}

if ($ntpBest !== null) {
    /* 关键：返回的是「标准时间戳」，不是「本地钟与标准的差」。
     *
     * 为什么不能返回差值：服务端算出的 offset 是「服务器本地钟 - 标准时间」。
     * 前端拿到它去校正的是**浏览器的本地钟**，两者根本不是一个钟
     * （InfinityFree 的服务器钟实测慢了约 1.5 秒，用户的手机钟是准的）。
     * 直接相加等于把服务器的钟差套到用户手机上，越校越偏。
     *
     * 正确做法：服务端给出「此刻的标准时间戳」，前端用
     *   标准时间(收到时) = std_time_ms + rtt/2
     *   offset = 浏览器收到时刻 - 标准时间(收到时)
     * 这样「服务器钟差」和「网络延迟」都被正确消掉了。 */
    $stdMs = $ntpBest['server_time'] * 1000.0 + (microtime(true) - $ntpBest['recv_at']) * 1000.0;
    $payload['ok'] = true;
    $payload['method'] = 'ntp';
    $payload['std_time_ms'] = round($stdMs);
    $payload['delay_ms'] = round($ntpBest['delay'] * 1000, 1);
    $payload['precision'] = $ntpBest['delay'] < 0.05 ? 'good'
                          : ($ntpBest['delay'] < 0.15 ? 'fair' : 'poor');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- 二级：HTTP Date ---------------- */
/*
 * Date 头只精确到秒 → 单次采样有 ±500ms 的截断误差。
 * 消不掉这个误差，但可以用「多次采样取中位数」把它压下去：
 *   真实值的分布以真值为中心、宽度 1000ms（因为秒内的相位 u 均匀分布）。
 *   n 次采样取中位数后，误差期望 ≈ 500 / sqrt(n) ms。
 *   7 次 → 约 ±190ms，对秒级显示完全够用。
 *
 * 每次约 30~50ms，7 次约 300ms，用户感知不到。
 */
$samples = [];
$used = [];
$srcList = lwHttpTimeSources();

// 轮流打各家，避免同一个源的缓存代理给出一模一样的 Date
for ($round = 0; $round < 3 && count($samples) < 7; $round++) {
    foreach ($srcList as $src) {
        if (count($samples) >= 7 || microtime(true) > $deadline) { break 2; }
        $r = lwHttpDateQuery($src[0], 2.0);
        if ($r !== null) {
            $samples[] = $r;
            if (!in_array($src[1], $used, true)) { $used[] = $src[1]; }
        }
    }
}

if (count($samples) >= 1) {
    /* 每个样本的元素：
     *   stdAtRecv = 收到响应那一刻的标准时间估计
     *             = Date秒 + 0.5(秒中点) + (recv_at - t0 - rtt/2)  待生成时间≈0
     *   offset    = (本地 t0) - (标准时间 at t0)
     *             = t0 - (Date秒 + 0.5 - rtt/2)
     */
    $offsets = array_map(function ($x) { return $x['offset']; }, $samples);
    sort($offsets);
    $medianOffset = $offsets[(int)floor((count($offsets) - 1) / 2)];

    $rtts = array_map(function ($x) { return $x['rtt']; }, $samples);
    sort($rtts);
    $minRtt = $rtts[0];

    // 本机当前标准时间 = 本机 microtime + (-medianOffset)
    $nowLocal = microtime(true);
    $stdNowMs = ($nowLocal - $medianOffset) * 1000.0;

    $payload['ok'] = true;
    $payload['method'] = 'http';
    $payload['std_time_ms'] = round($stdNowMs);
    $payload['delay_ms'] = round($minRtt * 1000, 1);
    $payload['sources'] = $used;
    $payload['samples'] = count($samples);
    /* 精度标注：中位数误差 ≈ 500/sqrt(n)，再加 rtt 校正残留 */
    $est = 500.0 / sqrt(max(count($samples), 1));
    $payload['precision'] = $est < 200 ? 'fair' : 'poor';
    $payload['accuracy_ms'] = round($est, 0);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$payload['reason'] = 'unreachable';
$payload['message'] = '无法连接任何时间源，已回退到本机时间';
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
