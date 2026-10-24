<?php
/**
 * RSS / Atom 订阅
 *
 * GET           最新帖子的 RSS 2.0
 * GET ?format=atom   Atom 1.0（部分阅读器更偏好）
 * GET ?category=xxx  只订阅某分类
 * GET ?tag=xxx       只订阅某标签
 *
 * 为什么要做：RSS 是「让老读者不用每天来刷」的标准手段，
 * 也是博客类站点被内容聚合器收录的主要途径。成本极低，收益长期。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/enhance.php';

/* ==========================================================================
 * 内容协商：浏览器看美化页，阅读器拿 XML
 * --------------------------------------------------------------------------
 * 【为什么需要】
 * RSS 返回的是 XML，浏览器不认识 application/rss+xml 就会把它当纯文本
 * 显示出来 —— 用户看到一大坨标签，完全不知道这是什么。
 *
 * 所以按 Accept 头分流：
 *   浏览器（Accept: text/html）        → 返回说明页 + 复制/订阅按钮
 *   阅读器（Accept 含 rss/atom/xml）   → 返回真正的 XML
 *   命令行（curl，Accept 为任意）      → 返回 XML（方便脚本消费）
 *
 * 【怎么判断是浏览器】
 * 真实浏览器的 Accept 一定以 text/html 开头或包含它；
 * 而 Feedly/Inoreader/QQ邮箱 这类阅读器会明确声明 rss/atom/xml。
 * 用「Accept 含 text/html 且不含 rss/atom/xml」作为浏览器的判据最稳。
 *
 * ?raw=1 可强制返回 XML（给「我就要在浏览器里看原始 XML」的用户留个口子）。
 * ========================================================================== */
$__accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

/* 【为什么不只看「含 xml 就是阅读器」】
 *
 * 真实浏览器（Chrome/Edge/Firefox/Safari）发的 Accept 长这样：
 *   text/html,application/xhtml+xml,application/xml;q=0.9,*\/*;q=0.8
 * 里面**也含 xml** —— 早期版本用「含 xml 即阅读器」判断，
 * 结果浏览器访问永远走 XML 分支，美化页从来没出现过。
 *
 * 所以改成按 q 值取「首选类型」：
 *   text/html 排在第一位  → 浏览器
 *   明确只要 rss/atom     → 阅读器
 *   其它（curl 等 *\/*）  → 给 XML
 */
$__types = [];
foreach (explode(',', $__accept) as $__part) {
    $__part = trim($__part);
    if ($__part === '') {
        continue;
    }
    $__q = 1.0;
    if (preg_match('/;\s*q=([0-9.]+)/i', $__part, $__qm)) {
        $__q = (float)$__qm[1];
    }
    $__type = trim(preg_replace('/;.*$/', '', $__part));
    $__types[] = ['type' => $__type, 'q' => $__q, 'raw' => $__part];
}

// 按 q 值降序，同 q 保持原顺序
usort($__types, function ($a, $b) {
    if ($a['q'] === $b['q']) {
        return 0;
    }
    return $a['q'] < $b['q'] ? 1 : -1;
});

$__first = $__types[0]['type'] ?? '';

// 首位是 text/html → 浏览器
$__isBrowser = (strpos($__first, 'text/html') === 0 || strpos($__first, 'application/xhtml') === 0);

// 首位明确要 rss / atom → 阅读器（这类才真的需要 XML）
$__isReader = (strpos($__first, 'application/rss') === 0
    || strpos($__first, 'application/atom') === 0);

// 首位是 application/xml（传统阅读器）且没有 text/html 备选 → 阅读器
if (!$__isBrowser && !$__isReader && strpos($__first, 'xml') !== false
    && strpos($__accept, 'text/html') === false) {
    $__isReader = true;
}

$__forceRaw = ((string)($_GET['raw'] ?? '')) === '1';

if ($__isBrowser && !$__forceRaw) {
    // ---------- 美化说明页 ----------
    $__siteName = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
    $__base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    $__self = $__base . '/api/rss.php';
    $__atom = $__self . '?format=atom';
    $__qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($__qs !== '') {
        $__self .= '?' . $__qs;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RSS 订阅 · <?= htmlspecialchars($__siteName) ?></title>
<style>
  *{box-sizing:border-box}
  body{
    margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    padding:24px;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;
    background:linear-gradient(135deg,#eef3fa 0%,#f7f9fc 50%,#eaf1f9 100%);color:#1F2733;
  }
  .card{
    width:100%;max-width:560px;background:#fff;border-radius:18px;padding:36px 32px;
    box-shadow:0 12px 48px rgba(47,91,154,.12);text-align:center;
  }
  .icon{
    width:64px;height:64px;margin:0 auto 18px;border-radius:16px;
    background:linear-gradient(135deg,#FFA500,#FF6B00);display:flex;align-items:center;
    justify-content:center;color:#fff;font-weight:700;font-size:22px;letter-spacing:-1px;
  }
  h1{font-size:20px;margin:0 0 8px}
  .sub{color:#6B7684;font-size:14px;line-height:1.7;margin:0 0 24px}
  .url{
    background:#F5F7FA;border:1px solid #E8ECF2;border-radius:10px;padding:14px 16px;
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:#2F5B9A;
    word-break:break-all;text-align:left;margin-bottom:16px;user-select:all;
  }
  .btns{display:flex;gap:10px;flex-wrap:wrap}
  .btn{
    flex:1;min-width:140px;padding:12px 18px;border-radius:10px;border:0;cursor:pointer;
    font-size:14px;font-weight:600;transition:transform .12s,box-shadow .12s;
  }
  .btn:active{transform:scale(.97)}
  .primary{background:linear-gradient(135deg,#4F7FE0,#2F5B9A);color:#fff;box-shadow:0 4px 14px rgba(47,91,154,.28)}
  .ghost{background:#F0F3F8;color:#4A5568}
  .ghost:hover{background:#E4E9F1}
  .hint{margin-top:24px;padding-top:20px;border-top:1px solid #EEF1F6;font-size:13px;color:#9AA4B2;line-height:1.8;text-align:left}
  .hint b{color:#6B7684}
  .hint a{color:#2F5B9A;text-decoration:none}
  .hint a:hover{text-decoration:underline}
  .readers{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
  .readers a{
    padding:6px 12px;background:#F5F7FA;border-radius:8px;font-size:12px;
    color:#4A5568;text-decoration:none;transition:background .15s;
  }
  .readers a:hover{background:#E8EDF5}
</style>
</head>
<body>
<div class="card">
  <div class="icon">RSS</div>
  <h1>这是一个 RSS 订阅源</h1>
  <p class="sub">
    这里不是给浏览器看的页面 —— 它输出的是一份标准 RSS 2.0 数据，<br>
    专门给<strong>阅读器软件</strong>订阅用的。
  </p>

  <div class="url" id="feedUrl"><?= htmlspecialchars($__self) ?></div>

  <div class="btns">
    <button class="btn primary" id="copyBtn">复制订阅地址</button>
    <a class="btn ghost" href="<?= htmlspecialchars($__self . (strpos($__self,'?') !== false ? '&' : '?') . 'raw=1') ?>" style="text-decoration:none;display:flex;align-items:center;justify-content:center">查看原始 XML</a>
  </div>

  <div class="hint">
    <b>怎么用？</b><br>
    把上面的地址粘贴到任意 RSS 阅读器里即可订阅。有新帖子时会自动推送。<br>
    <div class="readers">
      <a href="https://feedly.com/i/subscription/feed/<?= urlencode($__self) ?>" target="_blank" rel="noopener">Feedly</a>
      <a href="https://www.inoreader.com/?add_feed=<?= urlencode($__self) ?>" target="_blank" rel="noopener">Inoreader</a>
      <a href="<?= htmlspecialchars($__atom) ?>" target="_blank" rel="noopener">Atom 格式</a>
    </div>
    <br>
    <b>参数</b><br>
    · <code>?format=atom</code> 切换成 Atom 1.0<br>
    · <code>?category=study_help</code> 只订阅某分类<br>
    · <code>?limit=50</code> 条数（最多 50）<br>
    · <code>?raw=1</code> 强制返回原始 XML
  </div>
</div>
<script>
document.getElementById('copyBtn').addEventListener('click', function(){
  var url = document.getElementById('feedUrl').textContent.trim();
  var done = function(){
    var b = document.getElementById('copyBtn');
    var old = b.textContent;
    b.textContent = '已复制 ✓';
    setTimeout(function(){ b.textContent = old; }, 1600);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(done, function(){
      // 降级：选中文本让用户手动复制
      var r = document.createRange();
      r.selectNodeContents(document.getElementById('feedUrl'));
      var sel = window.getSelection();
      sel.removeAllRanges(); sel.addRange(r);
    });
  } else {
    var t = document.createElement('textarea');
    t.value = url; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); done(); } catch(e) {}
    document.body.removeChild(t);
  }
});
</script>
</body>
</html>
    <?php
    exit;
}

$fmt = strtolower((string)($_GET['format'] ?? 'rss'));
$category = trim((string)($_GET['category'] ?? ''));
$tag = lwSlugify((string)($_GET['tag'] ?? ''));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

$posts = [];
foreach (lwEnhRead('posts') as $p) {
    if (($p['status'] ?? 'published') !== 'published') continue;
    if (($p['visibility'] ?? 'public') !== 'public') continue;
    if ($category !== '' && (string)($p['category'] ?? '') !== $category) continue;
    if ($tag !== '') {
        $ts = array_map('lwSlugify', lwExtractTags($p));
        if (!in_array($tag, $ts, true)) continue;
    }
    $posts[] = $p;
}
usort($posts, static fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
$posts = array_slice($posts, 0, $limit);

$siteName = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$selfUrl = $siteUrl . '/api/rss.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
$now = date('r');

if ($fmt === 'atom') {
    header('Content-Type: application/atom+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    echo '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
    echo '  <title>' . htmlspecialchars($siteName, ENT_XML1) . '</title>' . "\n";
    echo '  <link href="' . htmlspecialchars($selfUrl, ENT_XML1) . '" rel="self"/>' . "\n";
    echo '  <link href="' . htmlspecialchars($siteUrl, ENT_XML1) . '"/>' . "\n";
    echo '  <updated>' . date('c') . '</updated>' . "\n";
    echo '  <id>' . htmlspecialchars($siteUrl, ENT_XML1) . '</id>' . "\n";
    foreach ($posts as $p) {
        $url = $siteUrl . '/pages/post_detail.php?id=' . (int)$p['id'];
        $title = (string)($p['title'] ?? '') ?: mb_substr(trim((string)($p['content'] ?? '')), 0, 40);
        echo '  <entry>' . "\n";
        echo '    <title>' . htmlspecialchars($title, ENT_XML1) . '</title>' . "\n";
        echo '    <link href="' . htmlspecialchars($url, ENT_XML1) . '"/>' . "\n";
        echo '    <id>' . htmlspecialchars($url, ENT_XML1) . '</id>' . "\n";
        $ts = strtotime((string)($p['created_at'] ?? 'now')) ?: time();
        echo '    <updated>' . date('c', $ts) . '</updated>' . "\n";
        $body = mb_substr(trim((string)($p['content'] ?? '')), 0, 500);
        echo '    <summary>' . htmlspecialchars($body, ENT_XML1) . '</summary>' . "\n";
        echo '  </entry>' . "\n";
    }
    echo '</feed>';
    exit;
}

header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
echo '  <channel>' . "\n";
echo '    <title>' . htmlspecialchars($siteName, ENT_XML1) . '</title>' . "\n";
echo '    <link>' . htmlspecialchars($siteUrl, ENT_XML1) . '</link>' . "\n";
echo '    <description>' . htmlspecialchars($siteName . ' 最新内容', ENT_XML1) . '</description>' . "\n";
echo '    <language>zh-cn</language>' . "\n";
echo '    <lastBuildDate>' . $now . '</lastBuildDate>' . "\n";
echo '    <atom:link href="' . htmlspecialchars($selfUrl, ENT_XML1) . '" rel="self" type="application/rss+xml"/>' . "\n";
foreach ($posts as $p) {
    $url = $siteUrl . '/pages/post_detail.php?id=' . (int)$p['id'];
    $title = (string)($p['title'] ?? '') ?: mb_substr(trim((string)($p['content'] ?? '')), 0, 40);
    $ts = strtotime((string)($p['created_at'] ?? 'now')) ?: time();
    $body = mb_substr(trim((string)($p['content'] ?? '')), 0, 500);
    echo '    <item>' . "\n";
    echo '      <title>' . htmlspecialchars($title, ENT_XML1) . '</title>' . "\n";
    echo '      <link>' . htmlspecialchars($url, ENT_XML1) . '</link>' . "\n";
    echo '      <guid isPermaLink="true">' . htmlspecialchars($url, ENT_XML1) . '</guid>' . "\n";
    echo '      <pubDate>' . date('r', $ts) . '</pubDate>' . "\n";
    echo '      <description>' . htmlspecialchars($body, ENT_XML1) . '</description>' . "\n";
    echo '    </item>' . "\n";
}
echo '  </channel>' . "\n";
echo '</rss>';
