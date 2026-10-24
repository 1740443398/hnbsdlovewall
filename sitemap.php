<?php
/**
 * 动态站点地图（sitemap.xml）
 *
 * 为什么用 PHP 而不是静态文件：
 *   帖子与用户主页会持续新增，静态 sitemap 一发就过期。这里按需生成，
 *   只列「公开可访问」的地址 —— 登录、注册、后台、个人中心一律不进地图，
 *   避免搜索引擎索引到需要鉴权的页面。
 *
 * 缓存策略：文件缓存 6 小时。共享主机上每次请求都重建 sitemap 是浪费，
 * 而站点内容更新频率远低于这个窗口。
 */

require_once __DIR__ . '/config/config.php';

header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

// 缓存放 data/ 下：该目录已被 .htaccess 禁止外网直读，
// 文件只经本脚本 readfile() 输出，不会被当成静态文件越过 CSP 拉走。
$cacheFile = __DIR__ . '/data/sitemap_cache.xml';
$ttl = 6 * 3600;

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    readfile($cacheFile);
    exit;
}

$siteUrl = rtrim(SITE_URL, '/');
$urls = [];

// ---- 固定页 ----
// 注意：.htaccess 会把 /pages/x.php 重写成可直接访问的 /pages/x，
// 这里统一用「无扩展名」形式，避免和 canonical 里的写法不一致被当成重复页。
$staticPages = [
    ['/',                   '1.0', 'daily'],
    ['/pages/ranking',      '0.8', 'daily'],
    ['/pages/tools',        '0.7', 'weekly'],
    ['/pages/topic',        '0.6', 'daily'],
];
foreach ($staticPages as [$p, $pri, $freq]) {
    $urls[] = ['loc' => $siteUrl . $p, 'priority' => $pri, 'changefreq' => $freq, 'lastmod' => null];
}

// ---- 帖子详情（只收公开帖）----
try {
    $posts = getFS()->read('posts');
} catch (Throwable $e) {
    $posts = [];
}

foreach ($posts as $post) {
    if (!is_array($post)) { continue; }
    // 跳过已删除 / 不可见 / 匿名类不公开的帖子
    if (!empty($post['is_deleted'])) { continue; }
    $vis = (string)($post['visibility'] ?? 'public');
    if ($vis !== 'public') { continue; }

    $id = (int)($post['id'] ?? 0);
    if ($id <= 0) { continue; }

    // 站内帖子的 created_at / updated_at 是「Y-m-d H:i:s」字符串而非时间戳，
    // 两种形态都兜住，避免 sitemap 里出现 1970-01-01 这种无效 lastmod。
    $lastmod = null;
    foreach (['updated_at', 'created_at'] as $k) {
        $v = $post[$k] ?? null;
        if (is_numeric($v) && (int)$v > 0) {
            $lastmod = date('Y-m-d', (int)$v);
            break;
        }
        if (is_string($v) && $v !== '') {
            $ts = strtotime($v);
            if ($ts !== false && $ts > 0) { $lastmod = date('Y-m-d', $ts); break; }
        }
    }

    $urls[] = [
        'loc'        => $siteUrl . '/pages/post_detail?id=' . $id,
        'priority'   => '0.6',
        'changefreq' => 'weekly',
        'lastmod'    => $lastmod,
    ];
}

// ---- 输出 ----
$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    $xml .= "  <url>\n";
    $xml .= '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
    if (!empty($u['lastmod'])) {
        $xml .= '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
    }
    $xml .= '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
    $xml .= '    <priority>' . $u['priority'] . "</priority>\n";
    $xml .= "  </url>\n";
}
$xml .= '</urlset>' . "\n";

// 写缓存（失败不影响输出）
@file_put_contents($cacheFile, $xml);

echo $xml;
