<?php
/**
 * 抽奖「参与条件一键补全」页
 * 把关注 / 点赞 / 评论 / 转发 等未完成条件集中成清单，逐个完成后再回详情参与。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/lottery.php';

$user = requireLogin();
$user = checkBanned($user);
$id = max(0, (int) ($_GET['lottery_id'] ?? 0));
$l = $id > 0 ? lwLotteryGet($id) : null;
if (!$l) { http_response_code(404); die('抽奖不存在'); }

$status = lwLotteryRuleStatus($l, (int) $user['id']);
$postId = (int) ($l['post_id'] ?? 0);
$ownerId = (int) ($l['created_by'] ?? 0);
$csrf = generateCSRFToken();
$pageTitle = '补全参与条件';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
<title><?= htmlspecialchars($pageTitle) ?> · <?= htmlspecialchars(SITE_NAME) ?></title>
<style>
body{margin:0;background:var(--bg,#F4F6F9);color:var(--text,#1C2733);font-family:'Noto Sans SC',-apple-system,'PingFang SC','Microsoft YaHei',sans-serif}
.wrap{max-width:640px;margin:0 auto;padding:18px 14px 60px}
h1{font-size:20px;margin:8px 0 6px}
.sub{color:var(--text-muted,#6B7A8B);font-size:13px;margin-bottom:14px}
.card{background:var(--card-bg,#fff);border:1px solid var(--border,#E3E7EC);border-radius:16px;padding:14px 16px;margin-bottom:12px}
.row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px dashed var(--border,#E3E7EC);font-size:14px}
.row:last-child{border-bottom:none}
.row .nm{flex:1}
.row .ok{color:#1E7A4C;font-weight:700}
.btn{border:0;border-radius:10px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;background:var(--primary,#2F5B9A);color:#fff;text-decoration:none;display:inline-flex;align-items:center}
.btn.ghost{background:var(--bg-secondary,#EEF1F5);color:var(--text)}
.btn:disabled{opacity:.5}
.tip{font-size:12.5px;color:var(--text-muted,#6B7A8B);margin-top:10px}
</style>
</head>
<body>
<div class="wrap">
  <h1>🎁 补全参与条件</h1>
  <div class="sub">抽奖：<?= htmlspecialchars((string) $l['title']) ?> · 完成下列条件即可参与</div>

  <div class="card" id="list">
    <?php
    $rules = (array) ($l['rules'] ?? []);
    $any = false;
    foreach ($rules as $r):
        $r = (string) $r;
        $done = null;
        if (mb_strpos($r, '关注') !== false && $ownerId > 0) { $done = $status['关注'] ?? false; }
        elseif (mb_strpos($r, '点赞') !== false && $postId > 0) { $done = $status['点赞'] ?? false; }
        elseif (mb_strpos($r, '评论') !== false && $postId > 0) { $done = $status['评论'] ?? false; }
        elseif (mb_strpos($r, '转发') !== false && $postId > 0) { $done = $status['转发'] ?? false; }
        if ($done === null) { continue; }
        $any = true;
    ?>
    <div class="row">
      <span class="nm">
        <?php if (mb_strpos($r, '关注') !== false): ?>➕ 关注发起人
        <?php elseif (mb_strpos($r, '点赞') !== false): ?>👍 点赞附带动态
        <?php elseif (mb_strpos($r, '评论') !== false): ?>💬 在附带动态下评论
        <?php else: ?>🔁 转发附带动态
        <?php endif; ?>
      </span>
      <?php if ($done): ?>
        <span class="ok">已完成 ✓</span>
      <?php else: ?>
        <?php if (mb_strpos($r, '关注') !== false && $ownerId > 0): ?>
          <button class="btn" onclick="doFollow(<?= (int) $ownerId ?>, this)">去关注</button>
        <?php elseif (mb_strpos($r, '点赞') !== false && $postId > 0): ?>
          <button class="btn" onclick="doLike(<?= (int) $postId ?>, this)">去点赞</button>
        <?php elseif (mb_strpos($r, '转发') !== false && $postId > 0): ?>
          <button class="btn" onclick="openRepost(<?= (int) $postId ?>, this)">写转发</button>
        <?php elseif (mb_strpos($r, '评论') !== false && $postId > 0): ?>
          <button class="btn" onclick="openComment(<?= (int) $postId ?>, this)">写评论</button>
        <?php elseif ($postId > 0): ?>
          <a class="btn" href="/pages/post_detail.php?id=<?= (int) $postId ?>" target="_blank">去完成</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$any): ?><div class="row">本抽奖没有可自动核验的参与条件，直接去参与即可。</div><?php endif; ?>
  </div>

  <a class="btn" href="/pages/lottery.php?id=<?= (int) $id ?>" style="width:100%;justify-content:center">返回抽奖详情</a>
  <div class="tip">完成后点返回，条件会实时更新；若仍未通过，刷新一下页面。</div>
</div>
<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';
const BACK_URL = '/pages/lottery.php?id=<?= (int) $id ?>';

/* 全部条件完成后自动返回抽奖详情（带参与按钮），无需手动点返回 */
function maybeDoneAll() {
  var rows = Array.prototype.slice.call(document.querySelectorAll('.row'));
  var pending = rows.filter(function (r) { return !r.querySelector('.ok') && r.querySelector('.btn, button'); });
  if (pending.length === 0) {
    var tip = document.createElement('div');
    tip.textContent = '条件已全部完成，正在返回抽奖…';
    tip.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:#1E7A4C;color:#fff;padding:10px 18px;border-radius:999px;font-size:13px;z-index:999';
    document.body.appendChild(tip);
    setTimeout(function () { location.href = BACK_URL; }, 900);
  }
}
function doFollow(targetId, btn) {
  btn.disabled = true; btn.textContent = '处理中…';
  var body = new URLSearchParams({ csrf_token: CSRF, action: 'follow', target_id: targetId });
  fetch('/api/user/follow.php', { method: 'POST', credentials: 'same-origin', body: body })
    .then(r => r.json()).then(j => {
      if (j && (j.success === true || j.ok === true)) { btn.outerHTML = '<span class="ok">已完成 ✓</span>'; maybeDoneAll(); }
      else { btn.disabled = false; btn.textContent = '重试'; alert((j && j.message) || '关注失败'); }
    }).catch(() => { btn.disabled = false; btn.textContent = '重试'; });
}
/* 评论条件内联完成：点「写评论」展开输入框，提交后原地打勾 */
function openComment(postId, btn) {
  var row = btn.closest('.row');
  if (row.querySelector('.cmt-box')) return;
  btn.style.display = 'none';
  var box = document.createElement('div');
  box.className = 'cmt-box';
  box.style.cssText = 'width:100%;margin-top:8px;display:flex;gap:8px;align-items:flex-start';
  box.innerHTML = '<textarea placeholder="写一句评论，完成参与条件…" maxlength="500" style="flex:1;min-height:64px;padding:8px 10px;border:1px solid var(--border,#E3E7EC);border-radius:10px;font-size:13px;resize:vertical;background:var(--card-bg,#fff);color:var(--text,#1C2733)"></textarea>'
    + '<button class="btn" type="button">发送</button>';
  row.appendChild(box);
  box.querySelector('textarea').focus();
  box.querySelector('button').addEventListener('click', function () {
    var ta = box.querySelector('textarea');
    var text = (ta.value || '').trim();
    if (!text) { ta.focus(); return; }
    var self = this;
    self.disabled = true; self.textContent = '发送中…';
    var body = new URLSearchParams({ csrf_token: CSRF, post_id: postId, content: text });
    fetch('/api/posts/comment.php', { method: 'POST', credentials: 'same-origin', body: body })
      .then(r => r.json()).then(j => {
        if (j && (j.success === true || j.ok === true)) {
          box.remove();
          row.querySelector('.nm').insertAdjacentHTML('beforeend', ' <span class="ok">✓</span>');
          btn.outerHTML = '<span class="ok">已完成 ✓</span>';
          maybeDoneAll();
        } else {
          self.disabled = false; self.textContent = '发送';
          alert((j && (j.message || j.error)) || '评论失败');
        }
      }).catch(() => { self.disabled = false; self.textContent = '发送'; });
  });
}

/* 转发条件内联完成：写一条引用附带动态的帖子 */
function openRepost(postId, btn) {
  var row = btn.closest('.row');
  if (row.querySelector('.rpt-box')) return;
  btn.style.display = 'none';
  var box = document.createElement('div');
  box.className = 'rpt-box';
  box.style.cssText = 'width:100%;margin-top:8px;display:flex;gap:8px;align-items:flex-start';
  box.innerHTML = '<textarea placeholder="说点什么，转发这条动态即可完成条件…" maxlength="500" style="flex:1;min-height:64px;padding:8px 10px;border:1px solid var(--border,#E3E7EC);border-radius:10px;font-size:13px;resize:vertical;background:var(--card-bg,#fff);color:var(--text,#1C2733)"></textarea>'
    + '<button class="btn" type="button">转发</button>';
  row.appendChild(box);
  box.querySelector('textarea').focus();
  box.querySelector('button').addEventListener('click', function () {
    var ta = box.querySelector('textarea');
    var text = (ta.value || '').trim();
    if (!text) { ta.focus(); return; }
    var self = this;
    self.disabled = true; self.textContent = '发送中…';
    var body = new URLSearchParams({
      csrf_token: CSRF, category: 'social_chat', title: '转发抽奖',
      content: text, quote_post_id: postId, visibility: 'public'
    });
    fetch('/api/posts/create.php', { method: 'POST', credentials: 'same-origin', body: body })
      .then(r => r.json()).then(j => {
        if (j && (j.success === true || j.ok === true)) {
          box.remove();
          btn.outerHTML = '<span class="ok">已完成 ✓</span>';
          maybeDoneAll();
        } else {
          self.disabled = false; self.textContent = '转发';
          alert((j && (j.message || j.error)) || '转发失败');
        }
      }).catch(() => { self.disabled = false; self.textContent = '转发'; });
  });
}

function doLike(postId, btn) {
  btn.disabled = true; btn.textContent = '处理中…';
  var body = new URLSearchParams({ csrf_token: CSRF, post_id: postId });
  fetch('/api/posts/like.php', { method: 'POST', credentials: 'same-origin', body: body })
    .then(r => r.json()).then(j => {
      if (j && (j.success === true || j.ok === true)) { btn.outerHTML = '<span class="ok">已完成 ✓</span>'; maybeDoneAll(); }
      else { btn.disabled = false; btn.textContent = '重试'; alert((j && j.message) || '点赞失败'); }
    }).catch(() => { btn.disabled = false; btn.textContent = '重试'; });
}
</script>
</body>
</html>
