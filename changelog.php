<?php
/**
 * 更新日志
 *
 * 布局参考业界常见的「时间线 + 卡片」写法：
 *   左侧一条竖线，每个版本一个圆点；
 *   右侧卡片内是「版本号 + 日期」，下面逐条列出改动。
 *
 * 每条改动前面挂一个彩色小标签（新增 / 优化 / 修复 / 安全），
 * 让人不用读全文就能扫出这次更新属于哪一类。
 *
 * 为什么值得做成一个正经页面：
 *   用户遇到问题时的第一反应是「是不是只有我这样」。
 *   有一个按时间倒序、写清「原来哪里不好」的日志，
 *   他能自己确认「哦，这个问题上次修了」，
 *   比去群里问一圈、或者怀疑自己手机坏了要省事得多。
 */
/* 只引 config.php —— 它内部已经 require 了 security.php。
 * 再单独引一次会触发 hashPassword() 重复声明，整页 500。
 */
require_once __DIR__ . '/config/config.php';

$user = getCurrentUser();
$logs = require __DIR__ . '/includes/changelog_data.php';
$logs = is_array($logs) ? $logs : [];

// 标签配色与图标
$typeStyle = [
    '新增' => ['bg' => '#EAF7F1', 'fg' => '#178A62', 'ring' => '#C5E9D8', 'icon' => '＋'],
    '优化' => ['bg' => '#EAF1FB', 'fg' => '#3B6FB6', 'ring' => '#C9DCF5', 'icon' => '↑'],
    '修复' => ['bg' => '#FEF6E7', 'fg' => '#B06A16', 'ring' => '#F5E0BC', 'icon' => '✓'],
    '安全' => ['bg' => '#FDECEC', 'fg' => '#C0392B', 'ring' => '#F5C6C6', 'icon' => '⛨'],
];

$channelLabel = [
    'web'  => '网页版',
    'app'  => '安卓客户端',
    'both' => '网页版 + 安卓',
];

$pageTitle = '更新日志';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>更新日志 · <?= htmlspecialchars(SITE_NAME) ?></title>
<meta name="description" content="LoveWall 每次发布带来的新功能、体验优化与问题修复，写清原来哪里不好、这次改了什么。">
<style>
*,*::before,*::after{box-sizing:border-box}
body{
  margin:0;
  background:linear-gradient(180deg,#F7F9FC 0%,#EFF3F9 100%);
  color:#1A1D23;
  font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;
  font-size:15px;
  line-height:1.7;
  min-height:100vh;
}
.wrap{max-width:820px;margin:0 auto;padding:36px 20px 80px}
.top-nav{
  display:flex;align-items:center;gap:12px;margin-bottom:28px;
  font-size:13px;color:#5A6472;
}
.top-nav a{color:#3B6FB6;text-decoration:none;font-weight:500}
.top-nav a:hover{text-decoration:underline}
.top-nav .sep{color:#C5CDD8}

h1{font-size:26px;font-weight:700;margin:0 0 8px;letter-spacing:-.3px}
.sub{color:#5A6472;font-size:14px;margin:0 0 30px;line-height:1.75}

/* ---- 时间线 ---- */
.timeline{position:relative;margin:0;padding:0;list-style:none}
.timeline::before{
  content:"";position:absolute;left:6px;top:8px;bottom:8px;width:2px;
  background:linear-gradient(180deg,#DDE4EE 0%,#E9EEF6 100%);border-radius:2px;
}
.entry{position:relative;margin-bottom:22px;padding-left:34px}
.dot{
  position:absolute;left:0;top:22px;
  width:14px;height:14px;border-radius:50%;
  background:#3B6FB6;
  box-shadow:0 0 0 4px #F7F9FC,0 0 0 5px rgba(59,111,182,.18);
}
.entry:first-child .dot{background:#3B6FB6;box-shadow:0 0 0 4px #F7F9FC,0 0 0 5px rgba(59,111,182,.32)}

/* ---- 卡片（毛玻璃）---- */
.card{
  background:rgba(255,255,255,.82);
  border:1px solid rgba(15,23,42,.07);
  border-radius:18px;
  padding:22px 24px;
  box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px -12px rgba(16,24,40,.08);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
}
.head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.ver{font-size:18px;font-weight:700;letter-spacing:-.2px}
.date{font-size:13px;color:#8A94A6}
.chan{
  font-size:11px;padding:2px 9px;border-radius:99px;
  background:#F0F3F8;color:#5A6472;font-weight:600;
}

/* ---- 条目 ---- */
ul.items{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:14px}
ul.items li{display:flex;align-items:flex-start;gap:10px}
.tag{
  flex-shrink:0;display:inline-flex;align-items:center;gap:3px;
  font-size:11px;font-weight:700;padding:3px 8px;border-radius:7px;
  margin-top:2px;white-space:nowrap;letter-spacing:.2px;
}
.txt{min-width:0;flex:1;color:#2B313B;font-size:14.5px;line-height:1.75}
.link{
  display:inline-flex;align-items:center;gap:3px;
  font-size:13px;color:#3B6FB6;text-decoration:none;margin-top:5px;
}
.link:hover{text-decoration:underline}
.link::before{content:"↗";font-size:12px;opacity:.75}

/* ---- 底部 ---- */
.foot{margin-top:44px;text-align:center;color:#98A2B3;font-size:12.5px;line-height:1.9}
.foot a{color:#3B6FB6;text-decoration:none}

@media (max-width:600px){
  .wrap{padding:24px 14px 60px}
  h1{font-size:22px}
  .card{padding:18px 16px;border-radius:16px}
  .entry{padding-left:28px}
  .timeline::before{left:5px}
  .dot{width:12px;height:12px;top:20px}
  .txt{font-size:14px}
}
</style>
</head>
<body>
<div class="wrap">

    <div class="top-nav">
        <a href="/">← 回到首页</a>
        <span class="sep">·</span>
        <a href="/pages/tools.php">全部功能</a>
    </div>

    <h1>更新日志</h1>
    <p class="sub">
        每次发布带来的新功能、体验优化与问题修复。<br>
        写清「原来哪里不好」，而不是只说「优化了体验」——
        这样你遇到问题时能自己确认是不是已经修过了。
    </p>

    <?php if (!$logs): ?>
        <div class="card"><p style="margin:0;color:#8A94A6">还没有更新记录。</p></div>
    <?php else: ?>
    <ol class="timeline">
        <?php foreach ($logs as $entry):
            $ver     = (string)($entry['version'] ?? '');
            $date    = (string)($entry['date'] ?? '');
            $channel = (string)($entry['channel'] ?? 'both');
            $items   = is_array($entry['items'] ?? null) ? $entry['items'] : [];
        ?>
        <li class="entry">
            <span class="dot"></span>
            <div class="card">
                <div class="head">
                    <span class="ver"><?= htmlspecialchars($ver) ?></span>
                    <?php if ($date !== ''): ?>
                        <span class="date"><?= htmlspecialchars($date) ?></span>
                    <?php endif; ?>
                    <span class="chan"><?= htmlspecialchars($channelLabel[$channel] ?? $channel) ?></span>
                </div>
                <ul class="items">
                    <?php foreach ($items as $it):
                        $type = (string)($it['type'] ?? '优化');
                        $text = (string)($it['text'] ?? '');
                        $link = (string)($it['link'] ?? '');
                        $st   = $typeStyle[$type] ?? $typeStyle['优化'];
                        if ($text === '') continue;
                    ?>
                    <li>
                        <span class="tag"
                              style="background:<?= $st['bg'] ?>;color:<?= $st['fg'] ?>;box-shadow:inset 0 0 0 1px <?= $st['ring'] ?>">
                            <span style="font-size:10px"><?= $st['icon'] ?></span><?= htmlspecialchars($type) ?>
                        </span>
                        <div class="txt">
                            <?= htmlspecialchars($text) ?>
                            <?php if ($link !== ''): ?>
                                <br><a class="link" href="<?= htmlspecialchars($link) ?>">去看看</a>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <div class="foot">
        版本号与安卓客户端保持一致 · <a href="/download/">下载最新版</a><br>
        LoveWall · <?= htmlspecialchars(SITE_NAME) ?>
    </div>

</div>
</body>
</html>
