<?php
// 必须走 config.php：否则本端点既不经过 WAF/反爬校验，也拿不到统一的安全响应头，
// 会变成一个人人可调、不设限的「服务器代取外网数据」入口（刷量、耗尽出站连接）。
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// 本端点的响应约定是「成功给数据、失败给 error 字段」（前端据此判断），
// 所以这里不用 jsonError 的 {success:false,message:...} 形状，保持一致。
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => '请求方法不允许'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 天气数据可由游客查看，故不强制登录；但按 IP 限流，避免被当成免费代理刷。
if (!checkRateLimit(getClientIP(), 'weather', 30, 60)) {
    http_response_code(429);
    echo json_encode(['error' => '请求过于频繁，请稍后再试'], JSON_UNESCAPED_UNICODE);
    exit;
}

$city = trim($_GET['city'] ?? '淮南');
if ($city === '') {
    $city = '淮南';
}
// 城市名只用于拼进 wttr.in 的路径，限长并去掉控制字符，避免异常参数拖慢上游请求
$city = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $city), 0, 32);

// 兼容 file_get_contents / cURL / fsockopen 三种环境，尽量让天气可用
function httpFetch($url) {
    if (!preg_match('#^(https?)://#', $url)) {
        return false;
    }
    // 1) cURL (推荐)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 10,
            // 必须校验上游证书：此处是服务端对外发起请求，关闭校验等于给中间人攻击开口子。
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0 (compatible; lovewall-weather/1.0)', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body !== false && $body !== '' && (!isset($err) || $err === '')) {
            return $body;
        }
    }
    // 2) allow_url_fopen
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: Mozilla/5.0 (compatible; lovewall-weather/1.0)\r\nAccept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false && $body !== '') {
            return $body;
        }
    }
    // 3) 原生 socket（含 chunked / gzip 解码）
    $parts = parse_url($url);
    $scheme = $parts['scheme'] ?? 'http';
    $host   = $parts['host'] ?? '';
    $port   = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $fp = @fsockopen(($scheme === 'https' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);
    if (!$fp) {
        return false;
    }
    $req = "GET $path HTTP/1.1\r\nHost: $host\r\n"
         . "User-Agent: Mozilla/5.0 (compatible; lovewall-weather/1.0)\r\n"
         . "Accept: application/json\r\nConnection: close\r\n\r\n";
    fwrite($fp, $req);
    $raw = '';
    while (!feof($fp)) {
        $raw .= fgets($fp, 8192);
    }
    fclose($fp);
    $pos = strpos($raw, "\r\n\r\n");
    if ($pos === false) {
        return false;
    }
    $head = substr($raw, 0, $pos);
    $body = substr($raw, $pos + 4);
    if (stripos($head, 'Transfer-Encoding: chunked') !== false) {
        $decoded = '';
        $left = $body;
        while (true) {
            $nl = strpos($left, "\r\n");
            if ($nl === false) { break; }
            $size = hexdec(trim(substr($left, 0, $nl)));
            if ($size <= 0) { break; }
            $decoded .= substr($left, $nl + 2, $size);
            $left = substr($left, $nl + 2 + $size + 2);
        }
        $body = $decoded;
    }
    if (stripos($head, 'Content-Encoding: gzip') !== false && function_exists('gzdecode')) {
        $gz = @gzdecode($body);
        if ($gz !== false) { $body = $gz; }
    }
    return $body;
}

$result = null;
$urls = [
    'https://wttr.in/' . urlencode($city) . '?format=j1&lang=zh&m',
    'http://wttr.in/' . urlencode($city) . '?format=j1&lang=zh&m',
];
foreach ($urls as $u) {
    $result = httpFetch($u);
    if ($result !== false && $result !== '') {
        break;
    }
}

if (!$result) {
    echo json_encode(['error' => '获取天气数据失败，请稍后重试', 'city' => $city], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($result, true);
if (!is_array($data) || empty($data['current_condition'])) {
    echo json_encode(['error' => '天气数据解析失败，请检查城市名', 'city' => $city], JSON_UNESCAPED_UNICODE);
    exit;
}

$cc = $data['current_condition'][0];
$areaName = $data['nearest_area'][0]['areaName'][0]['value'] ?? $city;
$desc = '';
foreach (['lang_zh', 'weatherDesc', 'lang_xx'] as $k) {
    if (!empty($cc[$k][0]['value'])) {
        $desc = $cc[$k][0]['value'];
        break;
    }
}

// 常见英文天气描述翻译为中文
$descZhMap = [
    'Sunny' => '晴', 'Clear' => '晴', 'Partly cloudy' => '多云', 'Cloudy' => '阴',
    'Overcast' => '阴', 'Mist' => '薄雾', 'Fog' => '雾', 'Light rain' => '小雨',
    'Patchy rain nearby' => '局部有小雨', 'Moderate rain' => '中雨', 'Heavy rain' => '大雨',
    'Light drizzle' => '毛毛雨', 'Patchy light drizzle' => '局部毛毛雨', 'Thunder' => '雷阵雨',
    'Thundery outbreaks in nearby' => '局部雷阵雨', 'Light snow' => '小雪', 'Moderate snow' => '中雪',
    'Heavy snow' => '大雪', 'Blowing snow' => '吹雪', 'Light rain shower' => '小阵雨',
    'Moderate or heavy rain shower' => '阵雨', 'Light sleet' => '冻雨', 'Freezing fog' => '冻雾',
];
if (isset($descZhMap[trim($desc)])) {
    $desc = $descZhMap[trim($desc)];
}

$wind = trim(($cc['winddir16Point'] ?? '') . ' ' . ($cc['windspeedKmph'] ?? '--') . 'km/h');

echo json_encode([
    'success' => true,
    'city' => $areaName,
    'temp' => ($cc['temp_C'] ?? '--') . '°C',
    'desc' => $desc ?: '--',
    // 前端天气挂件（assets/js/enhancements.js 的 _render）读的是 wd / ws 两个字段，
    // 这里与 desc / wind 取同一份数据，避免挂件把 desc 显示成 "undefined undefined"。
    'wd' => $desc ?: '--',
    'ws' => $wind,
    'humidity' => ($cc['humidity'] ?? '--') . '%',
    'wind' => $wind,
    'time' => $cc['localObsDateTime'] ?? '',
], JSON_UNESCAPED_UNICODE);