<?php
/**
 * 本地抓取 QQ 空间说说 → 生成可导入的 JSON
 * ---------------------------------------------------------------------------
 * 用法（在站点根目录执行）：
 *   php tools/qzone_scrape.php --uin=3893174881 --cookie="uin=o...; skey=...; p_skey=..."
 *   php tools/qzone_scrape.php --uin=3893174881 --cookie-file=cookie.txt --pages=3
 *
 * 可选参数：
 *   --pages=N     抓取页数（每页 20 条，默认 2）
 *   --out=路径    输出文件（默认 data/qzone-bundle-<时间>.json）
 *   --comments=N  每条取多少评论（默认 30）
 *
 * 设计意图：
 *   本地跑一遍把历史数据抓全，再把 JSON 粘进后台「数据导入」，
 *   服务器只需要 24 小时抓一次增量 —— 共享主机的压力就小很多。
 *   （QQ 空间需要登录态，所以这里必须由站长提供一次 Cookie。）
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/qzone_sync.php';

function qzArg(array $argv, string $name, string $default = ''): string
{
    foreach ($argv as $a) {
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

$argv = $_SERVER['argv'] ?? [];
if (in_array('--help', $argv, true) || count($argv) < 2) {
    echo "本地抓取 QQ 空间说说\n";
    echo "用法: php tools/qzone_scrape.php --uin=<QQ号> --cookie=\"<完整Cookie>\" [--pages=2] [--out=路径] [--comments=30]\n";
    echo "  或: php tools/qzone_scrape.php --uin=<QQ号> --cookie-file=cookie.txt\n";
    exit(0);
}

$uin = preg_replace('/\D/', '', qzArg($argv, 'uin'));
$cookie = qzArg($argv, 'cookie');
$cookieFile = qzArg($argv, 'cookie-file');
if ($cookie === '' && $cookieFile !== '' && is_file($cookieFile)) {
    $cookie = trim((string) file_get_contents($cookieFile));
}
$pages = max(1, min(10, (int) qzArg($argv, 'pages', '2')));
$comments = max(0, min(50, (int) qzArg($argv, 'comments', '30')));
$out = qzArg($argv, 'out', '');
if ($out === '') {
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $out = $dir . '/qzone-bundle-' . date('Ymd-His') . '.json';
}

if ($uin === '' || $cookie === '') {
    fwrite(STDERR, "缺少参数：--uin 和 --cookie（或 --cookie-file）都是必需的\n");
    exit(1);
}

/* 写进本地配置，让引擎走同一套逻辑（Cookie 会加密落本地 data/settings.json） */
lwQzoneSaveConfig(['uin' => $uin, 'comments_per' => $comments, 'page_size' => 20]);
lwQzoneSetCookie($cookie);

$items = [];
$total = 0;
for ($p = 0; $p < $pages; $p++) {
    $r = lwQzoneFetchFeeds($p * 20, 20);
    if (!$r['ok']) {
        fwrite(STDERR, "抓取失败（第 " . ($p + 1) . " 页）：state={$r['state']} {$r['err']}\n");
        if ($p === 0) {
            fwrite(STDERR, "第一页就失败，未生成文件。请检查 Cookie 是否有效。\n");
            exit(2);
        }
        break;
    }
    $items = array_merge($items, $r['items']);
    $total += count($r['items']);
    fwrite(STDERR, "  第 " . ($p + 1) . " 页：{$total} 条\n");
    if (count($r['items']) < 20) { break; }
}

/* 组装成与后台「数据导入」一致的格式 */
$bundle = ['version' => 1, 'exported_at' => date('Y-m-d H:i:s'), 'uin' => $uin, 'items' => [], 'comments' => []];
foreach ($items as $it) {
    $tid = $it['tid'];
    $bundle['items'][] = [
        'tid'         => $tid,
        'content'     => $it['content'],
        'images'      => $it['images'],
        'posted_ts'   => $it['created_at'],
        'likes'       => $it['likes'],
        'comment_num' => $it['comments'],
    ];
    $bundle['comments'][$tid] = [];
    foreach ($it['comment_list'] as $c) {
        $bundle['comments'][$tid][] = [
            'tid'        => $c['tid'],
            'author'     => $c['author'],
            'author_uin' => $c['author_uin'],
            'content'    => $c['content'],
            'posted_ts'  => $c['created_at'],
        ];
    }
}

$json = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "JSON 编码失败\n");
    exit(3);
}
if (@file_put_contents($out, $json) === false) {
    fwrite(STDERR, "写入失败：{$out}\n");
    exit(4);
}
fwrite(STDERR, "\n完成：{$total} 条说说，输出到 {$out}\n");
fwrite(STDERR, "下一步：打开后台 /admin/qzone.php，把该文件内容粘到「导入 JSON」里。\n");
echo $json;
