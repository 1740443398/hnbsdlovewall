<?php
/**
 * 站内动态抽奖（B 站式）
 * ---------------------------------------------------------------------------
 * /pages/lottery.php          列表
 * /pages/lottery.php?id=123   详情（参与 / 倒计时 / 参与者 / 中奖名单）
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lottery.php';

$user = function_exists('getCurrentUser') ? getCurrentUser() : null;
$isGuest = !$user;

/* 访问即兜底开奖：到点的自动抽（不用定时任务） */
lwLotteryAutoDraw();

$id = max(0, (int) ($_GET['id'] ?? 0));
$detail = $id > 0 ? lwLotteryGet($id) : null;
if ($detail) { lwLotteryTrackView($id); }
/* 分享来源浏览：同会话只记一次 */
if ($detail && (string) ($_GET['from'] ?? '') === 'share') {
    $__seenShare = $_SESSION['lottery_share_viewed'] ?? [];
    if (empty($__seenShare[$id])) {
        $__seenShare[$id] = 1;
        $_SESSION['lottery_share_viewed'] = $__seenShare;
        try { getFS()->update('lotteries', $id, ['share_views' => (int) ($detail['share_views'] ?? 0) + 1]); } catch (Throwable $e) {}
    }
}

$userIndex = [];
foreach ((array) getFS()->read('users') as $u) { $userIndex[(int) ($u['id'] ?? 0)] = $u; }

/** 显示用：从 id 关联读取昵称/头像（不复制数据） */
function lwLUser(array $idx, int $uid): array
{
    $u = $idx[$uid] ?? [];
    return [
        'id'       => $uid,
        'nickname' => (string) ($u['nickname'] ?? ('用户' . $uid)),
        'avatar'   => (string) ($u['avatar'] ?? '/assets/images/default-avatar.svg'),
    ];
}

$pageTitle = $detail ? ($detail['title'] ?? '抽奖') : '站内抽奖';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
<title><?= htmlspecialchars((string) $pageTitle) ?> · <?= htmlspecialchars(SITE_NAME) ?></title>
<?php if ($detail): ?>
<script src="/assets/js/qrcode.min.js"></script>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= htmlspecialchars(SITE_NAME) ?>">
<meta property="og:title" content="🎁 <?= htmlspecialchars((string) ($detail['title'] ?? '')) ?>">
<meta property="og:description" content="奖品：<?= htmlspecialchars((string) ($detail['prize'] ?? '')) ?> × <?= (int) ($detail['prize_count'] ?? 1) ?> · 开奖：<?= htmlspecialchars((string) ($detail['draw_at'] ?? '')) ?> · 快来参与">
<?php
  $__ogImg = trim((string) ($detail['cover_image'] ?? ''));
  if ($__ogImg === '') { $__ogImg = SITE_URL . '/icon.ico'; }
  elseif ($__ogImg[0] === '/') { $__ogImg = SITE_URL . $__ogImg; }
?>
<meta property="og:image" content="<?= htmlspecialchars($__ogImg) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<link rel="icon" href="/icon.ico" type="image/x-icon">
<style>
.dark-theme, .dark-theme body {
  --bg: #0F1520; --bg-secondary: #171E2B; --card-bg: rgba(27, 36, 52, .75);
  --border: #2A3748; --text: #E4E9F0; --text-muted: #7C8B9E;
  --primary: #5B8BD0; --primary-hover: #7AA3DE;
}
:root { --primary:#2F5B9A; --card:#fff; --bg:#F4F6F9; --text:#1C2733; --muted:#6B7A8B; --border:#E3E7EC; }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--text); font-family: 'Noto Sans SC', -apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif; }
.wrap { max-width: 760px; margin: 0 auto; padding: 16px 14px 60px; }
h1 { font-size: 20px; margin: 6px 0 14px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 16px; margin-bottom: 12px; }
.card h2 { font-size: 17px; margin: 0 0 6px; }
.meta { font-size: 12px; color: var(--muted); display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.prize { display:inline-block; background: rgba(47,91,154,.08); color: var(--primary); border-radius: 8px; padding: 2px 8px; font-size: 12px; font-weight: 700; }
.desc { font-size: 14px; line-height: 1.7; color: #3a4a5b; margin: 10px 0; white-space: pre-wrap; }
.cd { font-variant-numeric: tabular-nums; font-weight: 700; color: var(--primary); }
.btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; border:0; border-radius: 10px; padding: 10px 18px; font-size: 14px; font-weight: 700; cursor:pointer; background: var(--primary); color:#fff; }
.btn:disabled { opacity:.55; cursor: default; }
.btn.ghost { background: #EEF1F5; color: var(--text); }
.avatars { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
.avatars img { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e8edf2; }
.empty { text-align:center; padding: 36px 0; color: var(--muted); font-size: 14px; }
.winners { margin-top:8px; font-size:14px; }
.winners a { color: var(--primary); text-decoration: none; font-weight:600; }
.toast { position: fixed; left:50%; bottom: 30px; transform: translateX(-50%) translateY(10px); background: #111; color:#fff; padding: 10px 16px; border-radius: 20px; font-size: 13px; opacity:0; transition: all .2s; pointer-events:none; z-index: 99; }
.toast.show { opacity:1; transform: translateX(-50%) translateY(0); }
</style>
</head>
<body class="page-lottery">
<div class="wrap">
  <h1><a href="/pages/lottery.php" style="color:inherit;text-decoration:none">🎁 站内抽奖</a></h1>
<?php
/* 相关抽奖（详情页用）：同奖品优先，其次其它进行中活动 */
$__relLotteries = [];
if (!empty($detail)) {
    foreach (lwLotteryAll() as $__lo) {
        if ((int) ($__lo['id'] ?? 0) === (int) ($detail['id'] ?? 0)) { continue; }
        if ((string) ($__lo['status'] ?? 'open') !== 'open') { continue; }
        $__lo['__same_prize'] = (string) ($__lo['prize'] ?? '') === (string) ($detail['prize'] ?? '');
        $__relLotteries[] = $__lo;
    }
    usort($__relLotteries, function ($a, $b) {
        if ($a['__same_prize'] !== $b['__same_prize']) { return $a['__same_prize'] ? -1 : 1; }
        return strcmp((string) ($a['draw_at'] ?? ''), (string) ($b['draw_at'] ?? ''));
    });
    $__relLotteries = array_slice($__relLotteries, 0, 3);
}

/* 我的参与记录：只按 lottery_id 关联读取，不复制抽奖数据 */
$myLotIds = [];
if ($user) {
    foreach ((array) getFS()->read('lottery_entries') as $__e) {
        if ((int) ($__e['user_id'] ?? 0) === (int) $user['id']) { $myLotIds[(int) $__e['lottery_id']] = true; }
    }
}
?>
<?php if ($user && !$detail): ?>
  <div style="display:flex;gap:8px;margin:10px 0 4px;font-size:12.5px">
    <a href="/pages/lottery.php?view=all" style="padding:4px 12px;border-radius:999px;text-decoration:none;font-weight:600;<?= ($_GET['view'] ?? 'all') !== 'mine' ? 'background:var(--primary);color:#fff' : 'background:var(--card-bg);border:1px solid var(--border);color:var(--text-muted)' ?>">全部抽奖</a>
    <a href="/pages/lottery.php?view=mine" style="padding:4px 12px;border-radius:999px;text-decoration:none;font-weight:600;<?= ($_GET['view'] ?? '') === 'mine' ? 'background:var(--primary);color:#fff' : 'background:var(--card-bg);border:1px solid var(--border);color:var(--text-muted)' ?>">我参与的（<?= count($myLotIds) ?>）</a>
    <span style="color:var(--border)">|</span>
    <?php $__vq = 'view=' . urlencode($_GET['view'] ?? 'all'); ?>
    <a href="/pages/lottery.php?<?= $__vq ?>&status=all" style="padding:4px 10px;border-radius:999px;text-decoration:none;font-weight:600;<?= ($_GET['status'] ?? 'all') === 'all' ? 'background:var(--primary);color:#fff' : 'background:var(--card-bg);border:1px solid var(--border);color:var(--text-muted)' ?>">全部状态</a>
    <a href="/pages/lottery.php?<?= $__vq ?>&status=open" style="padding:4px 10px;border-radius:999px;text-decoration:none;font-weight:600;<?= ($_GET['status'] ?? '') === 'open' ? 'background:var(--primary);color:#fff' : 'background:var(--card-bg);border:1px solid var(--border);color:var(--text-muted)' ?>">进行中</a>
    <a href="/pages/lottery.php?<?= $__vq ?>&status=drawn" style="padding:4px 10px;border-radius:999px;text-decoration:none;font-weight:600;<?= ($_GET['status'] ?? '') === 'drawn' ? 'background:var(--primary);color:#fff' : 'background:var(--card-bg);border:1px solid var(--border);color:var(--text-muted)' ?>">已开奖</a>
    <span style="color:var(--border)">|</span>
    <?php $__sq = $__vq . '&status=' . urlencode($_GET['status'] ?? 'all'); ?>
    <a href="/pages/lottery.php?<?= $__sq ?>&sort=default" style="padding:4px 8px;text-decoration:none;<?= ($_GET['sort'] ?? 'default') === 'default' ? 'color:var(--primary);font-weight:700' : 'color:var(--text-muted)' ?>">最新</a>
    <a href="/pages/lottery.php?<?= $__sq ?>&sort=draw_soon" style="padding:4px 8px;text-decoration:none;<?= ($_GET['sort'] ?? '') === 'draw_soon' ? 'color:var(--primary);font-weight:700' : 'color:var(--text-muted)' ?>">即将开奖</a>
    <a href="/pages/lottery.php?<?= $__sq ?>&sort=entries" style="padding:4px 8px;text-decoration:none;<?= ($_GET['sort'] ?? '') === 'entries' ? 'color:var(--primary);font-weight:700' : 'color:var(--text-muted)' ?>">最热门</a>
  </div>
  <form method="get" style="display:flex;gap:8px;margin:8px 0 12px">
    <input type="hidden" name="view" value="<?= htmlspecialchars($_GET['view'] ?? 'all') ?>">
    <input type="hidden" name="status" value="<?= htmlspecialchars($_GET['status'] ?? 'all') ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($_GET['sort'] ?? 'default') ?>">
    <input type="search" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="搜索抽奖标题或奖品…"
           style="flex:1 1 auto;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:var(--card);color:var(--text);font-size:13px">
    <button class="btn ghost" type="submit" style="padding:8px 14px;font-size:13px">搜索</button>
    <?php if (($_GET['q'] ?? '') !== ''): ?><a href="/pages/lottery.php?view=<?= urlencode($_GET['view'] ?? 'all') ?>&status=<?= urlencode($_GET['status'] ?? 'all') ?>&sort=<?= urlencode($_GET['sort'] ?? 'default') ?>" class="btn ghost" style="padding:8px 12px;font-size:13px;text-decoration:none">清除</a><?php endif; ?>
  </form>
<?php endif; ?>

<?php
if ($detail):
    $l = $detail;
    $status = (string) ($l['status'] ?? 'open');
    $drawTs = strtotime((string) ($l['draw_at'] ?? ''));
    $entries = lwLotteryEntries((int) $l['id']);
    $joined = $user ? lwLotteryJoined((int) $l['id'], (int) $user['id']) : false;
    $winnerIds = json_decode((string) ($l['winners'] ?? '[]'), true) ?: [];
    /* 优先用新字段 rules（数组，含「关注」等可校验规则）；老数据回退到三个布尔字段。
     * 之前只读布尔字段，导致「关注」规则永远显示不出来、校验也无从触发。 */
    $rules = (array) ($l['rules'] ?? []);
    if (!$rules) {
        if (!empty($l['rule_like'])) { $rules[] = '点赞'; }
        if (!empty($l['rule_comment'])) { $rules[] = '评论'; }
        if (!empty($l['rule_repost'])) { $rules[] = '转发'; }
    }
?>
  <div class="card">
    <h2><?php
        $__lcover = trim((string) ($l['cover_image'] ?? ''));
        if ($__lcover !== '' && $__lcover[0] === '/') { $__lcover = 'https://' . ltrim((string) parse_url(SITE_URL, PHP_URL_HOST), '/') . $__lcover; }
    ?><?php if ($__lcover !== ''): ?><img class="lot-thumb" src="<?= htmlspecialchars($__lcover) ?>" alt="" loading="lazy" style="width:40px;height:40px;border-radius:10px;object-fit:cover;vertical-align:middle;margin-right:8px;background:#e8edf2" onerror="this.style.display='none'"><?php endif; ?>🎁 <?= htmlspecialchars((string) $l['title']) ?></h2>
    <div class="meta">
      <span class="prize">奖品：<?= htmlspecialchars((string) $l['prize']) ?> × <?= (int) $l['prize_count'] ?></span>
      <span>参与 <?= count($entries) ?> 人</span>
      <?php if ($status === 'open'): ?>
      <span>开奖倒计时：<span class="cd" id="cd" data-ts="<?= (int) $drawTs ?>">—</span></span>
      <?php else: ?>
      <span>已开奖</span>
      <?php endif; ?>
    </div>
    <?php if (!empty($l['description'])): ?>
    <div class="desc"><?= htmlspecialchars((string) $l['description']) ?></div>
    <?php endif; ?>
    <div class="lot-rules">
      <span class="lot-rules-label">参与方式</span>
      <?php
        $__ruleState = $user ? lwLotteryRuleStatus($l, (int) $user['id']) : [];
        $__missingRules = 0;
        foreach ($__ruleState as $__v) { if (!$__v) { $__missingRules++; } }
        $__customRules = [];
        foreach ($rules as $__crr) {
            $__crr = (string) $__crr;
            if (mb_strpos($__crr, '关注') === false && mb_strpos($__crr, '点赞') === false
                && mb_strpos($__crr, '评论') === false && mb_strpos($__crr, '转发') === false) {
                $__customRules[] = $__crr;
            }
        }
      ?>
      <?php if ($rules): foreach ($rules as $__r):
          $__icon = '✅';
          if (mb_strpos($__r, '转发') !== false) { $__icon = '🔁'; }
          elseif (mb_strpos($__r, '评论') !== false) { $__icon = '💬'; }
          elseif (mb_strpos($__r, '点赞') !== false) { $__icon = '👍'; }
          elseif (mb_strpos($__r, '关注') !== false) { $__icon = '➕'; }
          $__st = null;
          foreach ($__ruleState as $__k => $__v) { if (mb_strpos((string) $__r, $__k) !== false) { $__st = $__v; break; } }
      ?>
        <?php
          $__cls = 'lot-rule-chip' . ($__st === true ? ' is-ok' : ($__st === false ? ' is-miss' : ''));
          $__href = '';
          if ($__st === false) {
              if (mb_strpos((string) $__r, '关注') !== false && (int) ($l['created_by'] ?? 0) > 0) {
                  $__href = '/pages/u.php?id=' . (int) $l['created_by'];
              } elseif ((int) ($l['post_id'] ?? 0) > 0) {
                  $__href = '/pages/post_detail.php?id=' . (int) $l['post_id'];
              }
          }
          $__body = '<i>' . $__icon . '</i>' . htmlspecialchars($__r) . ($__st === true ? ' ✓' : ($__st === false ? ' ✗' : ''));
        ?>
        <?php if ($__href !== ''): ?>
          <a class="<?= $__cls ?>" href="<?= htmlspecialchars($__href) ?>" style="text-decoration:none" title="点此去完成"><?= $__body ?></a>
        <?php else: ?>
          <span class="<?= $__cls ?>"><?= $__body ?></span>
        <?php endif; ?>
      <?php endforeach; else: ?>
        <span class="lot-rule-chip"><i>✅</i>直接参与</span>
      <?php endif; ?>
      <span class="lot-rules-note">每人一次</span>
    </div>
    <?php if (!empty($__customRules) && $status === 'open' && !$isGuest): ?>
    <label class="lot-custom-ack" style="display:flex;align-items:flex-start;gap:8px;margin:8px 0 0;font-size:12.5px;color:var(--text);cursor:pointer">
      <input type="checkbox" id="customAck" style="margin-top:2px;flex:0 0 auto">
      <span>我已阅读并知晓：<?= htmlspecialchars(implode('；', $__customRules)) ?></span>
    </label>
    <?php endif; ?>

    <?php if ($status === 'open'): ?>
      <?php if ($isGuest): ?>
        <div class="lot-actions">
          <a class="btn" href="/pages/login.php">登录后参与</a>
        </div>
      <?php elseif ($joined): ?>
        <div class="lot-actions">
          <button class="btn ghost" disabled>已参与 ✓</button>
          <button class="btn ghost" onclick="copyShare()">复制分享链接</button>
          <button class="btn ghost" onclick="buildPoster()">生成海报</button>
          <?php if ($user): ?><button class="btn ghost lot-remind<?= lwLotteryRemindHas((int) $user['id'], (int) $l['id']) ? ' on' : '' ?>" id="lotRemind" data-id="<?= (int) $l['id'] ?>"><?= lwLotteryRemindHas((int) $user['id'], (int) $l['id']) ? '🔔 已设提醒' : '🔔 提醒我' ?></button><?php $__pushOn = lwLotteryPushEnabled((int) $user['id']); ?><button class="btn ghost" id="lotPushPref" data-on="<?= $__pushOn ? 1 : 0 ?>" style="font-size:12px"><?= $__pushOn ? '🔔 推送已开' : '🔕 推送已关' ?></button><?php endif; ?>
        </div>
      <?php else: ?>
        <?php if (!empty($__missingRules)): ?>
        <div class="lot-missing-hint">还差 <?= (int) $__missingRules ?> 项参与条件，点上面的红叉去完成，或 <a href="/pages/lottery_guide.php?lottery_id=<?= (int) $l['id'] ?>" style="color:inherit;font-weight:700;text-decoration:underline">一键补全 →</a></div>
        <?php endif; ?>
        <div class="lot-actions">
          <?php if (!empty($__missingRules)): ?>
          <button class="btn" type="button" disabled title="请先完成参与条件">还差 <?= (int) $__missingRules ?> 项条件</button>
          <?php elseif (!empty($__customRules)): ?>
          <button class="btn" id="joinBtn" data-id="<?= (int) $l['id'] ?>" data-need-ack="1" disabled title="请先勾选已知晓条款">参与抽奖</button>
          <?php else: ?>
          <button class="btn" id="joinBtn" data-id="<?= (int) $l['id'] ?>">参与抽奖</button>
          <?php endif; ?>
          <button class="btn ghost" onclick="copyShare()">复制分享链接</button>
          <button class="btn ghost" onclick="buildPoster()">生成海报</button>
          <?php if ($user): ?><button class="btn ghost lot-remind<?= lwLotteryRemindHas((int) $user['id'], (int) $l['id']) ? ' on' : '' ?>" id="lotRemind" data-id="<?= (int) $l['id'] ?>"><?= lwLotteryRemindHas((int) $user['id'], (int) $l['id']) ? '🔔 已设提醒' : '🔔 提醒我' ?></button><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($user && in_array((int) $user['id'], array_map('intval', $winnerIds), true)): ?>
      <p style="margin:8px 0;padding:10px 12px;border-radius:10px;background:rgba(47,91,154,.08);font-size:14px;font-weight:600;color:var(--primary)">
        🎉 恭喜你中奖了！奖品：<?= htmlspecialchars((string) $l['prize']) ?>（请联系管理员领取）
      </p>
      <?php endif; ?>
      <div class="winners">
        <b>🎉 中奖名单：</b>
        <?php if ($winnerIds): ?>
          <?php foreach ($winnerIds as $wi): $wu = lwLUser($userIndex, (int) $wi); ?>
            <span class="lot-winner">
              <a href="/pages/u.php?id=<?= (int) $wu['id'] ?>"><?= htmlspecialchars($wu['nickname']) ?></a>
              <button type="button" class="lot-congrats" data-name="<?= htmlspecialchars($wu['nickname'], ENT_QUOTES) ?>">🎉 恭喜</button>
            </span>
          <?php endforeach; ?>
        <?php else: ?>
          无人参与，本轮流抽
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($entries): ?>
    <div class="avatars" id="lotAvatars">
      <?php foreach ($entries as $__ei => $e): $eu = lwLUser($userIndex, (int) ($e['user_id'] ?? 0)); ?>
        <a href="/pages/u.php?id=<?= (int) $eu['id'] ?>" title="<?= htmlspecialchars($eu['nickname']) ?>"<?= $__ei >= 24 ? ' class="lot-extra" hidden' : '' ?>>
          <img src="<?= htmlspecialchars($eu['avatar']) ?>" alt="" loading="lazy">
        </a>
      <?php endforeach; ?>
    </div>
    <?php if (count($entries) > 24): ?>
    <button type="button" class="lot-expand" id="lotExpand" data-total="<?= count($entries) ?>">展开全部 <?= count($entries) ?> 人（还有 <?= count($entries) - 24 ?> 位）</button>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <p style="font-size:12px;color:var(--muted)"><a href="/pages/lottery.php">← 返回抽奖列表</a></p>
  <?php if (!empty($__relLotteries)): ?>
  <div class="card" style="margin-top:4px">
    <h2 style="font-size:15px">相关抽奖</h2>
    <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px">
      <?php foreach ($__relLotteries as $__o): ?>
      <a href="/pages/lottery.php?id=<?= (int)$__o['id'] ?>" style="display:flex;justify-content:space-between;gap:10px;text-decoration:none;color:inherit;padding:8px 10px;border:1px solid var(--border);border-radius:10px">
        <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;font-size:14px"><?= htmlspecialchars((string)($__o['title'] ?? '')) ?></span>
        <span class="prize" style="flex:0 0 auto;font-size:11px"><?= htmlspecialchars((string)($__o['prize'] ?? '')) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <script>
  (function () {
    var cd = document.getElementById('cd');
    var ts = cd ? parseInt(cd.getAttribute('data-ts'), 10) : 0;
    function tick() {
      if (!cd) return;
      var left = ts - Math.floor(Date.now() / 1000);
      if (left <= 0) { cd.textContent = '即将开奖'; location.reload(); return; }
      var d = Math.floor(left / 86400), h = Math.floor(left % 86400 / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
      cd.textContent = (d > 0 ? d + '天 ' : '') + h + '时' + m + '分' + s + '秒';
      setTimeout(tick, 1000);
    }
    tick();

        (function () {
      var pb = document.getElementById('lotPushPref');
      if (!pb) return;
      pb.addEventListener('click', function () {
        var on = pb.getAttribute('data-on') !== '1';
        var body = new URLSearchParams();
        body.set('on', on ? '1' : '0');
        fetch('/api/lottery/notify_pref.php', { method: 'POST', credentials: 'same-origin', body: body })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var st = j && j.push_on;
            if (st === undefined) { st = on; }
            pb.setAttribute('data-on', st ? '1' : '0');
            pb.textContent = st ? '🔔 推送已开' : '🔕 推送已关';
            if (typeof toast === 'function') toast(st ? '已开启抽奖推送' : '已关闭抽奖推送');
          }).catch(function () { if (typeof toast === 'function') toast('网络错误'); });
      });
    })();

    /* 自定义条款：勾选后才开放参与按钮 */
    (function () {
      var ack = document.getElementById('customAck');
      var jb = document.getElementById('joinBtn');
      if (!ack || !jb || !jb.getAttribute('data-need-ack')) return;
      function sync() { jb.disabled = !ack.checked; }
      ack.addEventListener('change', sync);
      sync();
    })();

    var btn = document.getElementById('joinBtn');
    if (btn) btn.addEventListener('click', function () {
      var self = this;
      self.disabled = true;
      self.textContent = '参与中…';
      var body = 'lottery_id=' + encodeURIComponent(self.getAttribute('data-id'))
        + '&via=' + ((new URLSearchParams(location.search)).get('from') === 'share' ? 'share' : 'manual');
      fetch('/api/lottery/join.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.success) {
          toast('参与成功，祝你好运 🍀');
          setTimeout(function () { location.reload(); }, 800);
        } else {
          if (j && j.need_repost) {
        if (typeof toast === 'function') toast('需要先转发附带动态，正在打开动态页…');
        setTimeout(function () { location.href = '/pages/post_detail.php?id=' + j.need_repost; }, 900);
      } else if (j && (j.need_like || j.need_comment)) {
        if (typeof toast === 'function') toast(j.message || '请先完成附带动态的互动');
        setTimeout(function () { location.href = '/pages/post_detail.php?id=' + (j.need_like || j.need_comment); }, 900);
      } else if (j && j.need_follow) {
        if (typeof toast === 'function') toast('需要先关注发起人，正在打开 TA 的主页…');
        setTimeout(function () { location.href = '/pages/u.php?id=' + j.need_follow; }, 900);
      } else {
        toast((j && (j.error || j.message)) || '参与失败');
      }
          self.disabled = false;
          self.textContent = '参与抽奖';
        }
      }).catch(function () { toast('网络错误'); self.disabled = false; self.textContent = '参与抽奖'; });
    });

    function copyShare() {
      /* 分享链接带来源标记：?from=share —— 参与者通过分享链接进入时会被记一次分享浏览，
       * 参与时 entry.via 也会写成 share，后台能看到分享带来的转化。 */
      var u = new URL(location.href);
      u.searchParams.set('from', 'share');
      var url = u.toString();
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () { toast('分享链接已复制'); },
          function () { prompt('手动复制：', url); });
      } else { prompt('手动复制：', url); }
    }

    function toast(msg) {
      var t = document.querySelector('.toast');
      if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
      t.textContent = msg; t.classList.add('show');
      setTimeout(function () { t.classList.remove('show'); }, 2200);
    }
  })();
  </script>

<?php else:
    $all = lwLotteryAll();
    /* 列表视图：全部 / 只看我参与的 */
    $listView = (string) ($_GET['view'] ?? 'all');
    if (!in_array($listView, ['all', 'mine'], true)) { $listView = 'all'; }
    if ($listView === 'mine') {
        $all = array_values(array_filter($all, function ($__l) use ($myLotIds) {
            return !empty($myLotIds[(int) ($__l['id'] ?? 0)]);
        }));
    }
    /* 二级过滤：进行中 / 已开奖 */
    $listStatus = (string) ($_GET['status'] ?? 'all');
    if (!in_array($listStatus, ['all', 'open', 'drawn'], true)) { $listStatus = 'all'; }
    /* 排序：默认最新创建 / 开奖时间升序 / 参与人数倒序 */
    $listSort = (string) ($_GET['sort'] ?? 'default');
    if (!in_array($listSort, ['default', 'draw_soon', 'entries'], true)) { $listSort = 'default'; }
    if ($listSort === 'draw_soon') {
        usort($all, function ($a, $b) {
            return strcmp((string) ($a['draw_at'] ?? ''), (string) ($b['draw_at'] ?? ''));
        });
    } elseif ($listSort === 'entries') {
        $__cntMap = [];
        foreach ((array) getFS()->read('lottery_entries') as $__e3) {
            $__lid3 = (int) ($__e3['lottery_id'] ?? 0);
            if ($__lid3 > 0) { $__cntMap[$__lid3] = ($__cntMap[$__lid3] ?? 0) + 1; }
        }
        usort($all, function ($a, $b) use ($__cntMap) {
            return ($__cntMap[(int) ($b['id'] ?? 0)] ?? 0) <=> ($__cntMap[(int) ($a['id'] ?? 0)] ?? 0);
        });
    }
    /* 关键词：标题 / 奖品 */
    $__lotQ = trim((string) ($_GET['q'] ?? ''));
    if ($__lotQ !== '') {
        $all = array_values(array_filter($all, function ($__l) use ($__lotQ) {
            return mb_stripos((string) ($__l['title'] ?? ''), $__lotQ) !== false
                || mb_stripos((string) ($__l['prize'] ?? ''), $__lotQ) !== false;
        }));
    }
    if ($listStatus !== 'all') {
        $all = array_values(array_filter($all, function ($__l) use ($listStatus) {
            $st = (string) ($__l['status'] ?? 'open');
            return $listStatus === 'open' ? ($st === 'open') : ($st !== 'open');
        }));
    }
    /* 分页：每页 10 条，筛选/排序后再切页 */
    $__perPage = 10;
    $__totalLots = count($all);
    $__pages = max(1, (int) ceil($__totalLots / $__perPage));
    $__page = max(1, min($__pages, (int) ($_GET['page'] ?? 1)));
    if ($__totalLots > 0) {
        $all = array_slice($all, ($__page - 1) * $__perPage, $__perPage);
    }
    $__keepQ = 'view=' . urlencode($listView) . '&status=' . urlencode($listStatus) . '&sort=' . urlencode($listSort) . ($__lotQ !== '' ? '&q=' . urlencode($__lotQ) : '');

    if (!$all): ?>
  <div class="card"><div class="empty"><?= $__lotQ !== '' ? '没有找到包含「' . htmlspecialchars($__lotQ) . '」的抽奖' : '还没有抽奖活动' ?><br><span style="font-size:12px">管理员可以在后台「抽奖管理」创建</span></div></div>
    <?php else: foreach ($all as $l):
        $status = (string) ($l['status'] ?? 'open');
        $drawTs = strtotime((string) ($l['draw_at'] ?? ''));
        $cnt = count(lwLotteryEntries((int) $l['id']));
    ?>
  <a class="card" href="/pages/lottery.php?id=<?= (int) $l['id'] ?>" style="display:block;text-decoration:none;color:inherit">
    <h2><?php
        $__lcover = trim((string) ($l['cover_image'] ?? ''));
        if ($__lcover !== '' && $__lcover[0] === '/') { $__lcover = 'https://' . ltrim((string) parse_url(SITE_URL, PHP_URL_HOST), '/') . $__lcover; }
      ?><?php if ($__lcover !== ''): ?><img class="lot-thumb" src="<?= htmlspecialchars($__lcover) ?>" alt="" loading="lazy" style="width:40px;height:40px;border-radius:10px;object-fit:cover;vertical-align:middle;margin-right:8px;background:#e8edf2" onerror="this.style.display='none'"><?php endif; ?>🎁 <?= htmlspecialchars((string) $l['title']) ?></h2>
    <div class="meta">
      <span class="prize"><?= htmlspecialchars((string) $l['prize']) ?> × <?= (int) $l['prize_count'] ?></span>
      <span>参与 <?= $cnt ?> 人</span>
      <?php if ($status === 'open'):
          $__left = max(0, $drawTs - time());
          if ($__left > 0) {
              $__h = (int) floor($__left / 3600);
              $__m = (int) floor(($__left % 3600) / 60);
              if ($__h >= 24) { $__txt = (int) floor($__h / 24) . ' 天后开奖'; }
              elseif ($__h > 0) { $__txt = '剩 ' . $__h . ' 小时 ' . $__m . ' 分'; }
              else { $__txt = '剩 ' . $__m . ' 分'; }
          } else { $__txt = '即将开奖'; }
      ?>
        <span class="lot-badge<?= $__left > 0 && $__left < 86400 ? ' urgent' : '' ?>" data-ts="<?= (int) $drawTs ?>"><i></i><span class="lot-badge-text"><?= htmlspecialchars($__txt) ?></span></span>
        <span style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars((string) $l['draw_at']) ?></span>
      <?php else: ?><span>已开奖</span><?php endif; ?>
    </div>
    <?php $__lrules = (array) ($l['rules'] ?? []); if ($__lrules): ?>
    <div class="lot-rules lot-rules--compact">
      <?php foreach ($__lrules as $__r):
          $__ic = '✅';
          if (mb_strpos($__r, '转发') !== false) { $__ic = '🔁'; }
          elseif (mb_strpos($__r, '评论') !== false) { $__ic = '💬'; }
          elseif (mb_strpos($__r, '点赞') !== false) { $__ic = '👍'; }
          elseif (mb_strpos($__r, '关注') !== false) { $__ic = '➕'; }
      ?>
        <span class="lot-rule-chip"><i><?= $__ic ?></i><?= htmlspecialchars($__r) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </a>
    <?php endforeach; endif; ?>
    <?php if ($__pages > 1): ?>
    <div class="lottery-pager" style="display:flex;gap:6px;justify-content:center;align-items:center;margin:14px 0 4px;font-size:12.5px">
        <?php if ($__page > 1): ?><a href="?<?= $__keepQ ?>&page=<?= $__page - 1 ?>" style="padding:4px 10px;border-radius:999px;background:var(--card-bg);border:1px solid var(--border);color:var(--text-muted);text-decoration:none">上一页</a><?php endif; ?>
        <span style="color:var(--text-muted)"><?= (int) $__page ?> / <?= (int) $__pages ?> 页 · 共 <?= (int) $__totalLots ?> 个</span>
        <?php if ($__page < $__pages): ?><a href="?<?= $__keepQ ?>&page=<?= $__page + 1 ?>" style="padding:4px 10px;border-radius:999px;background:var(--card-bg);border:1px solid var(--border);color:var(--text-muted);text-decoration:none">下一页</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php
    /* 最近开奖：横向滚动卡片，给列表页一个"结果感" */
    $__recent = [];
    foreach (lwLotteryAll() as $__rl) {
        if ((string) ($__rl['status'] ?? 'open') === 'open') { continue; }
        $__recent[] = $__rl;
    }
    usort($__recent, function ($a, $b) {
        $ta = strtotime((string) ($a['drawn_at'] ?? $a['draw_at'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['drawn_at'] ?? $b['draw_at'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });
    $__recent = array_slice($__recent, 0, 6);
    ?>
    <?php if ($__recent): ?>
    <div class="lot-recent" style="margin:16px 0 6px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:8px">
        <b style="font-size:14.5px">🎉 最近开奖</b>
        <a href="/pages/lottery.php?status=drawn" style="font-size:12px;color:var(--primary);text-decoration:none">查看全部 →</a>
      </div>
      <div style="display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;-webkit-overflow-scrolling:touch">
        <?php foreach ($__recent as $__rl):
            $__rw = array_map('intval', (array) json_decode((string) ($__rl['winners'] ?? '[]'), true));
            $__names = []; $__avatars = [];
            foreach (array_slice($__rw, 0, 3) as $__ru) {
                $__u2 = lwLUser($userIndex, (int) $__ru);
                $__names[] = $__u2['nickname'];
                $__avatars[] = $__u2['avatar'];
            }
            if (!$__names) { $__names[] = '流抽'; }
            $__cover = trim((string) ($__rl['cover_image'] ?? ''));
            if ($__cover !== '' && $__cover[0] === '/') { $__cover = 'https://' . ltrim((string) parse_url(SITE_URL, PHP_URL_HOST), '/') . $__cover; }
        ?>
        <a href="/pages/lottery.php?id=<?= (int) $__rl['id'] ?>" style="flex:0 0 180px;background:var(--card-bg);border:1px solid var(--border);border-radius:14px;padding:10px 12px;text-decoration:none;color:var(--text)">
          <?php if ($__cover !== ''): ?>
          <div class="recent-cover" style="height:56px;border-radius:9px;overflow:hidden;margin-bottom:8px;background:var(--bg-secondary,#EEF1F5)">
            <img src="<?= htmlspecialchars($__cover) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover" onerror="this.parentNode.style.display='none'">
          </div>
          <?php elseif ($__avatars): ?>
          <div class="recent-cover" style="height:56px;border-radius:9px;margin-bottom:8px;background:linear-gradient(135deg,rgba(47,91,154,.12),rgba(56,161,105,.14));display:flex;align-items:center;justify-content:center;gap:-6px">
            <?php foreach ($__avatars as $__ai => $__av): ?>
            <img src="<?= htmlspecialchars($__av) ?>" alt="" loading="lazy" style="width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid #fff;margin-left:<?= $__ai === 0 ? '0' : '-10px' ?>;background:#e8edf2" onerror="this.style.display='none'">
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars((string) ($__rl['title'] ?? '')) ?></div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">🎁 <?= htmlspecialchars((string) ($__rl['prize'] ?? '')) ?></div>
          <div style="font-size:11.5px;color:#C0392B;margin-top:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">🎉 <?= htmlspecialchars(implode('、', $__names)) ?></div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($user && $myLotIds): ?>
      <div class="card">
        <h2 style="font-size:15px">我参与的抽奖</h2>
        <div class="meta" style="flex-direction:column;align-items:flex-start;gap:6px">
          <?php foreach (lwLotteryAll() as $__l2):
              if (empty($myLotIds[(int) $__l2['id']])) { continue; }
              $__st = (string) ($__l2['status'] ?? 'open');
              $__win = json_decode((string) ($__l2['winners'] ?? '[]'), true) ?: [];
              $__won = in_array((int) $user['id'], array_map('intval', $__win), true);
          ?>
          <?php $__remind = lwLotteryRemindHas((int) $user['id'], (int) $__l2['id']); ?>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <a href="/pages/lottery.php?id=<?= (int) $__l2['id'] ?>" style="color:var(--primary);text-decoration:none">
              <?= htmlspecialchars((string) $__l2['title']) ?>
              <?= $__st === 'drawn' ? ($__won ? '· 🎉 已中奖' : '· 已开奖') : '· 进行中' ?>
            </a>
            <?php if ($__st === 'open' && $__remind): ?><span class="lot-badge" style="font-size:11px"><i></i>已设开奖提醒</span><?php endif; ?>
            <?php if ($__st === 'open' && !$__remind): ?><a href="/pages/lottery.php?id=<?= (int) $__l2['id'] ?>" style="font-size:12px;color:var(--text-muted);text-decoration:none">设置提醒 →</a><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
/* 抽奖列表倒计时：每秒按 data-ts 重算文案，避免“页面开着一小时后还显示剩 2 小时” */
(function () {
  function fmt(left) {
    if (left <= 0) return '即将开奖';
    var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), sec = left % 60;
    if (h >= 24) return Math.floor(h / 24) + ' 天后开奖';
    if (h > 0) return '剩 ' + h + ' 小时 ' + m + ' 分';
    if (m > 0) return '剩 ' + m + ' 分 ' + sec + ' 秒';
    return '剩 ' + sec + ' 秒';
  }
  function tick() {
    var now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('.lot-badge[data-ts]').forEach(function (el) {
      var ts = parseInt(el.getAttribute('data-ts'), 10) || 0;
      var left = ts - now;
      var txt = el.querySelector('.lot-badge-text') || el;
      txt.textContent = fmt(left);
      el.classList.toggle('urgent', left > 0 && left < 86400);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tick); else tick();
  setInterval(tick, 1000);
})();
</script>

<script>
/* 参与者头像墙：点击展开全部 / 收起 */
(function () {
  var btn = document.getElementById('lotExpand');
  var box = document.getElementById('lotAvatars');
  if (!btn || !box) return;
  var total = btn.getAttribute('data-total') || '';
  btn.addEventListener('click', function () {
    var opened = btn.getAttribute('data-open') === '1';
    box.querySelectorAll('.lot-extra').forEach(function (a) { a.hidden = opened; });
    btn.setAttribute('data-open', opened ? '0' : '1');
    btn.textContent = opened ? ('展开全部 ' + total + ' 人') : '收起参与者';
  });
})();
</script>

<script>
/* 中奖名单：一键复制恭喜语（避免直接评论带来的骚扰与刷屏） */
function copyCongrats(name) {
  var text = '恭喜 ' + name + ' 中奖啦 🎉 记得找管理员领奖～';
  var done = function () { if (typeof toast === 'function') { toast('恭喜语已复制，去评论区送上祝福吧'); } };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done, function () { prompt('手动复制：', text); });
  } else { prompt('手动复制：', text); }
}
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('.lot-congrats');
  if (b) copyCongrats(b.getAttribute('data-name') || '同学');
});
</script>

<script>
/* 开奖提醒：登录用户可设置/取消，开奖时收到站内通知 */
(function () {
  var b = document.getElementById('lotRemind');
  if (!b) return;
  b.addEventListener('click', function () {
    var on = !b.classList.contains('on');
    var body = new URLSearchParams();
    body.set('lottery_id', b.getAttribute('data-id'));
    body.set('on', on ? '1' : '0');
    b.disabled = true;
    fetch('/api/lottery/remind.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var ok = j && (j.success === true || j.ok === true);
        if (ok) {
          var st = j.data ? j.data.on : on;
          b.classList.toggle('on', !!st);
          b.textContent = st ? '🔔 已设提醒' : '🔔 提醒我';
          if (typeof toast === 'function') toast(st ? '开奖时会通知你' : '已取消提醒');
        } else if (typeof toast === 'function') {
          toast((j && (j.error || j.message)) || '设置失败');
        }
        b.disabled = false;
      })
      .catch(function () { b.disabled = false; if (typeof toast === 'function') toast('网络错误'); });
  });
})();
</script>
<script>
/* 抽奖分享海报：Canvas 合成标题/奖品/开奖时间 + 二维码，可下载 PNG */
function buildPoster() {
  var qrlib = window.qrcode ? window.qrcode : (typeof qrcode !== 'undefined' ? qrcode : null);
  if (!qrlib) { alert('二维码组件加载失败，请刷新重试'); return; }
  var u = new URL(location.href);
  u.searchParams.set('from', 'share');
  var url = u.toString();
  var title = <?= json_encode((string) ($detail['title'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
  var prize = <?= json_encode((string) ($detail['prize'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
  var count = <?= (int) ($detail['prize_count'] ?? 1) ?>;
  var drawAt = <?= json_encode((string) ($detail['draw_at'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;

  var W = 640, H = 880;
  var c = document.createElement('canvas');
  c.width = W; c.height = H;
  var g = c.getContext('2d');

  var grad = g.createLinearGradient(0, 0, W, H);
  grad.addColorStop(0, '#2F5B9A'); grad.addColorStop(1, '#5B8BD0');
  g.fillStyle = grad; g.fillRect(0, 0, W, H);

  // 白色内容卡
  g.fillStyle = '#fff';
  var r = 24, x0 = 28, y0 = 120, x1 = W - 28, y1 = H - 28;
  g.beginPath();
  g.moveTo(x0 + r, y0); g.lineTo(x1 - r, y0); g.quadraticCurveTo(x1, y0, x1, y0 + r);
  g.lineTo(x1, y1 - r); g.quadraticCurveTo(x1, y1, x1 - r, y1);
  g.lineTo(x0 + r, y1); g.quadraticCurveTo(x0, y1, x0, y1 - r);
  g.lineTo(x0, y0 + r); g.quadraticCurveTo(x0, y0, x0 + r, y0);
  g.closePath(); g.fill();

  g.fillStyle = '#fff'; g.textAlign = 'center';
  g.font = 'bold 34px "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif';
  g.fillText('🎁 校园墙抽奖', W / 2, 78);

  g.fillStyle = '#1C2733'; g.textAlign = 'left';
  g.font = 'bold 36px "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif';
  var lines = [];
  var t = title || '抽奖活动';
  while (t.length > 0 && lines.length < 2) {
    var n = t.length > 14 ? 14 : t.length;
    lines.push(t.slice(0, n)); t = t.slice(n);
  }
  lines.forEach(function (ln, i) { g.fillText(ln, x0 + 28, y0 + 70 + i * 48); });

  g.fillStyle = '#C0392B';
  g.font = 'bold 40px "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif';
  g.fillText('奖品：' + prize + ' × ' + count, x0 + 28, y0 + 200);

  g.fillStyle = '#44566C';
  g.font = '24px "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif';
  g.fillText('开奖时间：' + drawAt, x0 + 28, y0 + 248);

  g.fillStyle = '#6B7A8B';
  g.font = '22px "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif';
  g.fillText('扫码或长按识别，立即参与', x0 + 28, y0 + 300);

  // 二维码
  var qr = qrlib(0, 'M');
  qr.addData(url); qr.make();
  var n2 = qr.getModuleCount();
  var size = 300, cell = Math.floor(size / n2);
  var ox = W / 2 - (cell * n2) / 2, oy = y0 + 340;
  g.fillStyle = '#fff'; g.fillRect(ox - 8, oy - 8, cell * n2 + 16, cell * n2 + 16);
  g.fillStyle = '#111';
  for (var row = 0; row < n2; row++) {
    for (var col = 0; col < n2; col++) {
      if (qr.isDark(row, col)) g.fillRect(ox + col * cell, oy + row * cell, cell, cell);
    }
  }

  g.fillStyle = '#9AA7B4'; g.textAlign = 'center';
  g.font = '18px "Noto Sans SC", sans-serif';
  g.fillText('来自校园墙 · 公平公正公开', W / 2, H - 52);

  // 展示 + 下载
  var dataUrl = c.toDataURL('image/png');
  var mask = document.createElement('div');
  mask.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px';
  var box = document.createElement('div');
  box.style.cssText = 'background:#fff;border-radius:18px;padding:12px;max-width:92vw;max-height:92vh;overflow:auto;text-align:center';
  box.innerHTML = '<img src="' + dataUrl + '" style="width:100%;max-width:420px;border-radius:12px;display:block;margin:0 auto 10px">'
    + '<div style="display:flex;gap:10px;justify-content:center">'
    + '<a class="btn" href="' + dataUrl + '" download="lottery-poster.png">保存海报</a>'
    + '<button class="btn ghost" type="button" onclick="this.closest(\'div[style*=fixed]\').remove()">关闭</button></div>';
  mask.appendChild(box);
  mask.addEventListener('click', function (e) { if (e.target === mask) mask.remove(); });
  document.body.appendChild(mask);
}
</script>
</body>
</html>
