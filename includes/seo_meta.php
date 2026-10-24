<?php
/**
 * 社交分享 / 搜索引擎元信息（OG + Twitter Card + canonical + 结构化数据）。
 *
 * 为什么单独抽一个文件：
 *   站内原本只有 <title> 和 <meta name="description">，分享到 QQ / 微信 / 群里
 *   是一张没有缩略图的裸链接，搜索引擎也拿不到任何语义。这一层补上之后
 *   任意页面分享出去都有卡片预览，也是网页设计比赛「细节完成度」的常见加分项。
 *
 * 用法（在 <head> 里 <title> 之后）：
 *   $seoTitle       = '页面标题';           // 不传则用 SITE_NAME
 *   $seoDescription = '页面描述';           // 不传则用 site.meta_desc 词条
 *   $seoImage       = '/assets/images/og-cover.png';  // 不传则用默认封面
 *   $seoType        = 'website';            // 或 article
 *   require __DIR__ . '/includes/seo_meta.php';
 *
 * 注意：所有值都经过 htmlspecialchars 转义，传进来的是原始字符串即可。
 */

if (!function_exists('lwSeoMeta')) {
    /**
     * 输出一组社交分享 / SEO 元标签。
     * 幂等：同一次请求内重复 include 只会输出一次。
     */
    function lwSeoMeta(array $opt = []): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;

        $siteName = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
        $siteUrl  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';

        // ---- 当前页面地址（canonical 用）----
        // 只保留 path，丢掉查询串：站内很多页面靠 ?category= / ?tag= 切换内容，
        // 那些是同一份内容的视图，不该被搜索引擎当成多个页面收录。
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $canonical = $siteUrl . $path;

        $title = trim((string)($opt['title'] ?? '')) ?: $siteName;
        $desc  = trim((string)($opt['description'] ?? ''));
        if ($desc === '' && function_exists('t')) {
            $desc = (string)t('site.meta_desc');
        }
        $image = trim((string)($opt['image'] ?? '')) ?: '/assets/images/og-cover.png';
        $type  = ($opt['type'] ?? 'website') === 'article' ? 'article' : 'website';

        // 图片补成绝对地址（OG 规范要求）
        if ($image !== '' && strpos($image, 'http') !== 0) {
            $image = $siteUrl . '/' . ltrim($image, '/');
        }

        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        echo "\n    <!-- 社交分享 / SEO -->\n";
        echo '    <link rel="canonical" href="' . $e($canonical) . '">' . "\n";

        // Open Graph（QQ / 微信 / 大多数中文社交平台优先读这组）
        echo '    <meta property="og:type" content="' . $e($type) . '">' . "\n";
        echo '    <meta property="og:site_name" content="' . $e($siteName) . '">' . "\n";
        echo '    <meta property="og:title" content="' . $e($title) . '">' . "\n";
        if ($desc !== '') {
            echo '    <meta property="og:description" content="' . $e($desc) . '">' . "\n";
        }
        echo '    <meta property="og:url" content="' . $e($canonical) . '">' . "\n";
        echo '    <meta property="og:image" content="' . $e($image) . '">' . "\n";
        echo '    <meta property="og:image:alt" content="' . $e($siteName) . '">' . "\n";
        echo '    <meta property="og:locale" content="zh_CN">' . "\n";

        // Twitter Card（部分海外平台 / 抓取器读这组）
        echo '    <meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '    <meta name="twitter:title" content="' . $e($title) . '">' . "\n";
        if ($desc !== '') {
            echo '    <meta name="twitter:description" content="' . $e($desc) . '">' . "\n";
        }
        echo '    <meta name="twitter:image" content="' . $e($image) . '">' . "\n";

        // 结构化数据：让搜索引擎把本站识别成一个站点而不是散页面
        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $siteName,
            'url'      => $siteUrl,
        ];
        if ($desc !== '') { $ld['description'] = $desc; }
        echo '    <script type="application/ld+json">'
            . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>' . "\n";
    }
}

lwSeoMeta([
    'title'       => $seoTitle       ?? '',
    'description' => $seoDescription ?? '',
    'image'       => $seoImage       ?? '',
    'type'        => $seoType        ?? 'website',
]);
