<?php
// 公开下载「我的数据备份」：申请后系统向注册 QQ 邮箱发送验证码，输对验证码方可下载。
// 备份为 ZIP：全站源代码 + 脱敏后的本人数据（不含他人信息与匿名发布者身份）。
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

$user = requireMember('申请下载备份需要注册账号后才能使用');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    http_response_code(403);
    die('账号已被封禁');
}

$csrfToken = generateCSRFToken();
$qq = trim((string)($user['qq'] ?? ''));
$email = ($qq !== '' && isValidQQ($qq)) ? $qq . '@qq.com' : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
<script src="<?= asset_url('/assets/js/anti_hijack.js') ?>?v=<?= asset_ver('/assets/js/anti_hijack.js') ?>"></script>
<link rel="icon" href="/icon.ico" type="image/x-icon">
<title>数据备份下载 - <?= SITE_NAME ?></title>
<style>
:root {
  --primary: #2F5B9A; --primary-dark: #1E3A63; --primary-hover: #24487C; --primary-light: #E7EEF7;
  --accent: #C9A96E; --bg: #F6F7F9; --card-bg: #FFFFFF;
  --text: #1C2733; --text-secondary: #5B6B7B; --text-muted: #8E9AA8;
  --border: #E3E7EC; --border-light: #EEF1F4;
  --success: #2E8B57; --success-light: #E6F4EC; --danger: #C2453F; --danger-light: #FBE9E8;
  /* 卡片圆角与全站统一（style.css 的 --radius-card = 1.5rem） */
  --radius: 24px; --radius-full: 9999px;
  --shadow-md: 0 8px 24px rgba(16,24,40,0.10);
  --transition: 0.24s ease;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; }
body {
  font-family: 'Noto Sans SC', -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', 'Segoe UI', Arial, sans-serif;
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 24px; color: var(--text); line-height: 1.7;
  background: var(--bg);
  background-attachment: fixed; -webkit-font-smoothing: antialiased;
  overflow-y: auto;
}
.card { width: 100%; max-width: 560px; background: var(--card-bg); border: 1px solid var(--border-light); border-radius: var(--radius); box-shadow: var(--shadow-md); padding: 36px 32px; }
.head { text-align: center; margin-bottom: 22px; }
.head .ico { width: 62px; height: 62px; margin: 0 auto 14px; border-radius: 16px; background: var(--primary); display: flex; align-items: center; justify-content: center; }
.head h1 { font-family: 'Noto Serif SC', 'Source Han Serif SC', 'Songti SC', 'STSong', 'SimSun', serif; font-size: 23px; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 6px; }
.head p { font-size: 14px; color: var(--text-secondary); }
.warn {
  display: flex; gap: 10px; align-items: flex-start;
  background: var(--danger-light); border: 1px solid rgba(194,69,63,0.22); border-radius: 12px;
  padding: 13px 16px; font-size: 13.5px; color: #7A2B27; margin-bottom: 20px;
}
.mail-chip { display: flex; align-items: center; gap: 8px; background: var(--primary-light); border: 1px solid rgba(47,91,154,0.18); border-radius: 12px; padding: 12px 14px; font-size: 14px; color: var(--primary-dark); margin-bottom: 18px; }
.mail-chip b { font-weight: 700; }
.step { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px; }
.step .num { flex: 0 0 auto; width: 22px; height: 22px; border-radius: 50%; background: var(--primary); color: #fff; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: center; margin-top: 2px; }
.step .txt { font-size: 14px; color: var(--text-secondary); padding-top: 2px; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 13px 20px; border: none; border-radius: var(--radius-full); font-size: 15px; font-weight: 600; cursor: pointer; font-family: inherit; text-decoration: none; transition: all var(--transition); }
.btn-primary { background: var(--primary); color: #fff; box-shadow: 0 4px 14px rgba(47,91,154,0.28); }
.btn-ghost { background: transparent; color: var(--text-secondary); border: 1px solid var(--border); }
.btn-ghost:hover { color: var(--primary); background: var(--primary-light); }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.inp { width: 100%; padding: 13px 16px; border: 1px solid var(--border); border-radius: 12px; font-size: 16px; color: var(--text); background: #fff; outline: none; font-family: inherit; text-align: center; letter-spacing: 8px; }
.inp:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(47,91,154,0.12); }
.label { display: block; font-size: 13px; font-weight: 600; color: var(--text); margin: 0 0 8px; }
.back { display:inline-block; margin-top: 16px; text-align:center; width:100%; font-size: 13px; color: var(--text-muted); text-decoration: none; }
.back:hover { color: var(--primary); }
</style>
</head>
<body>
<?php require __DIR__ . '/../includes/ai_widget.php'; ?>
<div class="card">
  <div class="head">
    <div class="ico"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg></div>
    <h1>数据备份下载</h1>
    <p>下载我的数据备份（ZIP，仅含我自己的数据）：全站源代码 + 脱敏后的个人数据，用于留档或迁移。</p>
  </div>

  <div class="warn">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C2453F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto;margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <span>备份仅含<b>您本人</b>的数据（脱敏后的个人数据与全站源代码），不含他人信息与匿名发布者身份。为防泄露，下载需向您本人的注册邮箱发送验证码，验证通过后才能获取，请勿把备份文件外传。</span>
  </div>

  <div class="mail-chip">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2F5B9A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg>
    <span>验证码将发送至：<b><?= htmlspecialchars($email ?: $qq) ?></b></span>
  </div>

  <?php if ($email === ''): ?>
    <div class="warn">您的账号没有有效的 QQ 号，无法接收邮件验证码。请联系管理员处理。</div>
    <a class="btn btn-ghost" href="<?= SITE_URL ?>/">返回首页</a>
  <?php else: ?>
    <div class="step"><span class="num">1</span><span class="txt">点击下方按钮，系统会向您的邮箱发送一封包含验证码的邮件。</span></div>
    <button class="btn btn-primary" id="sendBtn" onclick="sendCode()">发送验证码邮件</button>

    <div style="margin-top:22px;">
      <div class="step"><span class="num">2</span><span class="txt">输入邮件中的验证码，验证通过后即可下载。</span></div>
      <label class="label" for="codeInput">邮箱验证码</label>
      <input type="text" id="codeInput" class="inp" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="6 位验证码">
      <form id="dlForm" method="post" action="<?= SITE_URL ?>/api/backup_download_public.php" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="code" id="dlCode">
        <button type="submit" class="btn btn-primary" id="dlBtn">确认并下载</button>
      </form>
    </div>

    <a class="back" href="<?= SITE_URL ?>/">← 返回首页</a>
  <?php endif; ?>
</div>

<?php if ($email !== ''): ?>
<script>
var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
function toast(msg, type) {
  var t = document.createElement('div');
  t.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;padding:13px 18px;border-radius:10px;color:#fff;font-size:14px;box-shadow:0 4px 20px rgba(0,0,0,0.15);max-width:340px;background:' + (type === 'error' ? '#C2453F' : '#2E8B57') + ';';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function(){ t.style.transition='opacity .3s'; t.style.opacity='0'; setTimeout(function(){ t.remove(); }, 320); }, 2600);
}
function sendCode() {
  var btn = document.getElementById('sendBtn');
  if (btn.dataset.disable) return;
  btn.dataset.disable = '1'; btn.disabled = true; btn.textContent = '正在发送...';
  var fd = new URLSearchParams();
  fd.append('csrf_token', CSRF_TOKEN);
  fetch('/api/backup_request.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() })
    .then(function(r){ return r.json(); })
    .then(function(res){
      if (res && res.success) {
        toast(res.message || '验证码已发送', 'ok');
        btn.textContent = '已发送，请查收邮箱';
        var cd = document.getElementById('codeInput'); if (cd) cd.focus();
      } else {
        toast((res && res.message) || '发送失败，请稍后再试', 'error');
        btn.disabled = false; btn.textContent = '发送验证码邮件'; btn.dataset.disable = '';
      }
    })
    .catch(function(){ toast('网络异常，发送失败', 'error'); btn.disabled = false; btn.textContent = '发送验证码邮件'; btn.dataset.disable = ''; });
}
document.getElementById('dlForm').addEventListener('submit', function(e){
  var c = document.getElementById('codeInput').value.trim();
  if (c.length !== 6) { e.preventDefault(); toast('请输入 6 位邮箱验证码', 'error'); return; }
  document.getElementById('dlCode').value = c;
});
// 展示服务端错误
(function(){
  var q = new URLSearchParams(location.search);
  var err = q.get('err');
  if (err) { toast(decodeURIComponent(err), 'error'); if (history && history.replaceState) history.replaceState(null,'',location.pathname); }
})();
</script>
<?php endif; ?>
</body>
</html>