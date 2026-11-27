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
<link rel="icon" href="/icon.ico" type="image/x-icon">
<style>
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
<body>
<div class="wrap">
  <h1><a href="/pages/lottery.php" style="color:inherit;text-decoration:none">🎁 站内抽奖</a></h1>

<?php
/* 我的参与记录：只按 lottery_id 关联读取，不复制抽奖数据 */
$myLotIds = [];
if ($user) {
    foreach ((array) getFS()->read('lottery_entries') as $__e) {
        if ((int) ($__e['user_id'] ?? 0) === (int) $user['id']) { $myLotIds[(int) $__e['lottery_id']] = true; }
    }
}

if ($detail):
    $l = $detail;
    $status = (string) ($l['status'] ?? 'open');
    $drawTs = strtotime((string) ($l['draw_at'] ?? ''));
    $entries = lwLotteryEntries((int) $l['id']);
    $joined = $user ? lwLotteryJoined((int) $l['id'], (int) $user['id']) : false;
    $winnerIds = json_decode((string) ($l['winners'] ?? '[]'), true) ?: [];
    $rules = [];
    if (!empty($l['rule_like'])) { $rules[] = '点赞'; }
    if (!empty($l['rule_comment'])) { $rules[] = '评论'; }
    if (!empty($l['rule_repost'])) { $rules[] = '转发'; }
?>
  <div class="card">
    <h2>🎁 <?= htmlspecialchars((string) $l['title']) ?></h2>
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
      <?php if ($rules): foreach ($rules as $__r):
          $__icon = '✅';
          if (mb_strpos($__r, '转发') !== false) { $__icon = '🔁'; }
          elseif (mb_strpos($__r, '评论') !== false) { $__icon = '💬'; }
          elseif (mb_strpos($__r, '点赞') !== false) { $__icon = '👍'; }
          elseif (mb_strpos($__r, '关注') !== false) { $__icon = '➕'; }
      ?>
        <span class="lot-rule-chip"><i><?= $__icon ?></i><?= htmlspecialchars($__r) ?></span>
      <?php endforeach; else: ?>
        <span class="lot-rule-chip"><i>✅</i>直接参与</span>
      <?php endif; ?>
      <span class="lot-rules-note">每人一次</span>
    </div>

    <?php if ($status === 'open'): ?>
      <?php if ($isGuest): ?>
        <div class="lot-actions">
          <a class="btn" href="/pages/login.php">登录后参与</a>
        </div>
      <?php elseif ($joined): ?>
        <div class="lot-actions">
          <button class="btn ghost" disabled>已参与 ✓</button>
          <button class="btn ghost" onclick="copyShare()">复制分享链接</button>
          <?php if ($user): ?><button class="btn ghost lot-remind<?= lwLotteryRemindHas((int) $user['id'], (int) $l['id']) ? ' on' : '' ?>" id="lotRemind" data-id="<?= (int) $l['id'] ?>"><?= lwLotteryRemindHas((int) $user['id'], (int) $l['id']) ? '🔔 已设提醒' : '🔔 提醒我' ?></button><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="lot-actions">
          <button class="btn" id="joinBtn" data-id="<?= (int) $l['id'] ?>">参与抽奖</button>
          <button class="btn ghost" onclick="copyShare()">复制分享链接</button>
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

    var btn = document.getElementById('joinBtn');
    if (btn) btn.addEventListener('click', function () {
      var self = this;
      self.disabled = true;
      self.textContent = '参与中…';
      var body = 'lottery_id=' + encodeURIComponent(self.getAttribute('data-id'));
      fetch('/api/lottery/join.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
      }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.success) {
          toast('参与成功，祝你好运 🍀');
          setTimeout(function () { location.reload(); }, 800);
        } else {
          toast((j && (j.error || j.message)) || '参与失败');
          self.disabled = false;
          self.textContent = '参与抽奖';
        }
      }).catch(function () { toast('网络错误'); self.disabled = false; self.textContent = '参与抽奖'; });
    });

    function copyShare() {
      var url = location.href;
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
    if (!$all): ?>
  <div class="card"><div class="empty">还没有抽奖活动<br><span style="font-size:12px">管理员可以在后台「抽奖管理」创建</span></div></div>
    <?php else: foreach ($all as $l):
        $status = (string) ($l['status'] ?? 'open');
        $drawTs = strtotime((string) ($l['draw_at'] ?? ''));
        $cnt = count(lwLotteryEntries((int) $l['id']));
    ?>
  <a class="card" href="/pages/lottery.php?id=<?= (int) $l['id'] ?>" style="display:block;text-decoration:none;color:inherit">
    <h2>🎁 <?= htmlspecialchars((string) $l['title']) ?></h2>
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
</body>
</html>
