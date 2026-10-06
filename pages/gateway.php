<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

// 防缓存：避免退回旧页面文案
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// 目标：落地页 CTA 后跳转到登录 or 注册
$to = isset($_GET['to']) && $_GET['to'] === 'register' ? 'register' : 'login';

// 邀请码透传：从邀请链接第一次进入时（声明尚未确认）会先落到本页，
// 必须一路带到最终注册页，否则用户走完声明页回来邀请码就丢了。
$invite = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['invite'] ?? '')) ?? '';
$inviteQs = $invite !== '' ? '&invite=' . rawurlencode($invite) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gate_confirm'])) {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        // 不再裸 die：带着提示回到本页，页面会用刚换发的新令牌重新渲染，
        // 用户再点一次即可继续（会话被主机弄丢时第二次通常就成功了）。
        header('Location: ' . SITE_URL . '/pages/gateway.php?to=' . $to . '&csrf_fail=1' . $inviteQs);
        exit();
    }
    acceptGateway();
    $next = $to === 'register'
        ? SITE_URL . '/pages/register.php' . ($invite !== '' ? '?invite=' . rawurlencode($invite) : '')
        : SITE_URL . '/pages/login.php';
    header('Location: ' . $next);
    exit();
}

// 游客模式：不注册先浏览（受限：仅前若干条动态、公告与社区规范、只读工具页）
if (isset($_GET['browse'])) {
    enableGuestMode();
    header('Location: ' . SITE_URL . '/');
    exit();
}

$csrfToken = generateCSRFToken();
$pageTitle = SITE_NAME;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
<?php require_once __DIR__ . '/../includes/theme_dark_tokens.php'; ?>
<script src="<?= asset_url('/assets/js/anti_hijack.js') ?>?v=<?= asset_ver('/assets/js/anti_hijack.js') ?>"></script>
<link rel="icon" href="/icon.ico" type="image/x-icon">
<title><?= htmlspecialchars(SITE_NAME) ?> · 校园交流墙</title>
<meta name="description" content="<?= htmlspecialchars(SITE_NAME) ?> —— 淮南市北师大实验中学高中部非官方学生自发交流平台，现已开源。">
<style>
:root {
  --primary: #2F5B9A;
  --primary-dark: #1E3A63;
  --primary-hover: #24487C;
  --primary-light: #E7EEF7;
  --accent: #C9A96E;
  --accent-light: #F8F3E7;
  --bg: #F6F7F9;
  --bg-secondary: #EEF0F3;
  --card-bg: #FFFFFF;
  --text: #1C2733;
  --text-secondary: #5B6B7B;
  --text-muted: #8E9AA8;
  --border: #E3E7EC;
  --border-light: #EEF1F4;
  --success: #2E8B57;
  --success-light: #E6F4EC;
  /* 卡片圆角与全站统一（style.css 的 --radius-card = 1.5rem） */
  --radius: 24px;
  --radius-full: 9999px;
  --shadow: 0 2px 10px rgba(16,24,40,0.06);
  --shadow-md: 0 10px 30px rgba(16,24,40,0.10);
  --shadow-hover: 0 8px 24px rgba(47,91,154,0.16);
  --transition: 0.24s ease;
  --nav-h: 64px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
body {
  font-family: 'Noto Sans SC', -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', 'Segoe UI', Arial, sans-serif;
  min-height: 100vh;
  color: var(--text);
  background: var(--bg);
  background-attachment: fixed;
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
  line-height: 1.7;
}
a { color: var(--primary); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ===== 顶部导航 ===== */
.site-nav {
  position: sticky; top: 0; z-index: 20;
  height: var(--nav-h);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 clamp(16px, 5vw, 64px);
  background: rgba(255,255,255,0.78);
  backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--border-light);
}
.nav-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; color: var(--primary-dark); font-size: 16px; }
.brand-shield {
  width: 34px; height: 34px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  background: var(--primary);
  color: #fff;
}
.nav-links { display: flex; align-items: center; gap: 6px; }
.nav-links a { font-size: 14px; color: var(--text-secondary); padding: 8px 12px; border-radius: 10px; font-weight: 500; }
.nav-links a:hover { color: var(--primary); background: var(--primary-light); text-decoration: none; }
.nav-links .btn { width: auto; }

/* ===== 通用 ===== */
.wrap { max-width: 1080px; margin: 0 auto; padding: 0 clamp(16px, 5vw, 40px); }
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 12px 24px; border: none; border-radius: var(--radius-full);
  font-size: 15px; font-weight: 600; cursor: pointer;
  font-family: inherit; text-decoration: none; transition: all var(--transition);
}
.btn-primary { background: var(--primary); color: #fff; box-shadow: 0 4px 14px rgba(47,91,154,0.28); }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 22px rgba(47,91,154,0.36); text-decoration: none; color: #fff; }
.btn-ghost { background: rgba(255,255,255,0.7); color: var(--text); border: 1px solid var(--border); }
.btn-ghost:hover { border-color: var(--primary); color: var(--primary); background: #fff; text-decoration: none; }
.btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
.btn-outline:hover { background: var(--primary-light); text-decoration: none; }
.section { padding: 72px 0; }
.section-head { text-align: center; max-width: 640px; margin: 0 auto 40px; }
.eyebrow {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 13px; font-weight: 600; color: var(--primary);
  background: var(--primary-light); border: 1px solid rgba(47,91,154,0.16);
  padding: 5px 14px; border-radius: var(--radius-full); margin-bottom: 16px;
}
.section-head h2 { font-family: 'Noto Serif SC', 'Source Han Serif SC', 'Songti SC', 'STSong', 'SimSun', serif; font-size: clamp(24px, 3.4vw, 32px); font-weight: 700; letter-spacing: -0.02em; color: var(--text); margin-bottom: 12px; }
.section-head p { font-size: 15px; color: var(--text-secondary); }

/* ===== Hero ===== */
.hero { padding: clamp(48px, 9vw, 104px) 0 clamp(40px, 7vw, 84px); }
.hero-inner { max-width: 760px; margin: 0 auto; text-align: center; }
.hero h1 {
  font-family: 'Noto Serif SC', 'Source Han Serif SC', 'Songti SC', 'STSong', 'SimSun', 'Times New Roman', serif;
  font-size: clamp(32px, 5.6vw, 54px); font-weight: 700; line-height: 1.25;
  letter-spacing: -0.03em; color: var(--primary-dark); margin-bottom: 18px;
}
.hero h1 .hl { color: var(--accent); }
.hero .sub { font-size: clamp(15px, 2vw, 18px); color: var(--text-secondary); max-width: 560px; margin: 0 auto 28px; }
.hero .cta { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-bottom: 20px; }
.hero .hint { font-size: 13px; color: var(--text-muted); }
.hero .hint a { font-weight: 600; }
.hero-glow {
  margin-top: 36px; display: flex; justify-content: center;
}
.hero-card {
  width: 100%; max-width: 720px;
  background: var(--card-bg); border: 1px solid var(--border-light);
  border-radius: var(--radius); box-shadow: var(--shadow-md);
  padding: 22px; text-align: left;
  display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
}
.hero-card .hc-ico { flex: 0 0 auto; width: 48px; height: 48px; border-radius: 12px; background: var(--primary-light); display: flex; align-items: center; justify-content: center; }
.hero-card .hc-body { flex: 1 1 260px; min-width: 220px; }
.hero-card .hc-title { font-weight: 700; font-size: 15px; margin-bottom: 4px; }
.hero-card .hc-desc { font-size: 13px; color: var(--text-secondary); }

/* ===== 功能区 ===== */
.features { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 18px; }
.feat-card {
  background: var(--card-bg); border: 1px solid var(--border-light);
  border-radius: var(--radius); padding: 26px 24px; box-shadow: var(--shadow);
  transition: all var(--transition);
}
.feat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-hover); border-color: rgba(47,91,154,0.22); }
.feat-ico { width: 46px; height: 46px; border-radius: 12px; background: var(--primary-light); display: flex; align-items: center; justify-content: center; margin-bottom: 16px; }
.feat-card h3 { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
.feat-card p { font-size: 13.5px; color: var(--text-secondary); }

/* ===== 开源 ===== */
.open-card {
  display: flex; gap: 32px; align-items: center; flex-wrap: wrap;
  background: var(--card-bg); border: 1px solid var(--border-light);
  border-radius: var(--radius); box-shadow: var(--shadow-md);
  padding: clamp(24px, 5vw, 44px);
}
.open-card .oc-body { flex: 1 1 320px; min-width: 260px; }
.open-card .oc-body h2 { font-size: clamp(22px, 3vw, 28px); font-weight: 800; margin-bottom: 12px; }
.open-card .oc-body p { font-size: 14px; color: var(--text-secondary); margin-bottom: 20px; }
.open-card .oc-side { flex: 0 0 auto; text-align: center; }
.repo-chip {
  display: inline-flex; align-items: center; gap: 10px;
  background: var(--primary-light); border: 1px solid rgba(47,91,154,0.16);
  border-radius: 14px; padding: 14px 20px; font-weight: 600; color: var(--primary-dark);
}
.repo-chip svg { display: block; }

/* ===== CTA ===== */
.cta-band { text-align: center; }
.cta-band h2 { font-family: 'Noto Serif SC', 'Source Han Serif SC', 'Songti SC', 'STSong', 'SimSun', serif; font-size: clamp(24px, 3.4vw, 32px); font-weight: 700; letter-spacing: -0.02em; margin-bottom: 12px; }
.cta-band p { font-size: 15px; color: var(--text-secondary); margin-bottom: 28px; }

/* ===== 页脚 ===== */
.site-foot { border-top: 1px solid var(--border-light); background: rgba(255,255,255,0.5); padding: 28px 0 40px; margin-top: 40px; }
.foot-inner { max-width: 1080px; margin: 0 auto; padding: 0 clamp(16px, 5vw, 40px); text-align: center; }
.foot-safe {
  display: inline-flex; gap: 8px; align-items: center;
  font-size: 13px; color: var(--text-secondary);
  background: var(--success-light); border: 1px solid rgba(46,139,87,0.22);
  border-radius: var(--radius-full); padding: 8px 16px; margin-bottom: 16px;
}
.foot-safe strong { color: #2E8B57; }
.foot-meta { font-size: 13px; color: var(--text-muted); }
.foot-meta a { font-weight: 600; }

@media (max-width: 768px) {
  .nav-links a { font-size: 13px; padding: 8px 8px; }
  .nav-links .btn { display: none; }
  .section { padding: 52px 0; }
}
@media (max-width: 480px) {
  .brand-txt { display: none; }
}
</style>
</head>
<body>

<?php require __DIR__ . '/../includes/ai_widget.php'; ?>

<header class="site-nav">
  <a class="nav-brand" href="<?= SITE_URL ?>/">
    <span class="brand-shield"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg></span>
    <span class="brand-txt">校园交流墙</span>
  </a>
  <nav class="nav-links">
    <a href="#features">功能</a>
    <a href="#open">开源</a>
    <a href="<?= GITHUB_REPO_URL ?>" target="_blank" rel="noopener">GitHub</a>
  </nav>
</header>

<section class="hero">
  <div class="wrap hero-inner">
    <span class="eyebrow">淮南市北师大实验中学高中部 · 学生自发交流平台</span>
    <h1>写下属于我们校园的<span class="hl">每一句心里话</span></h1>
    <p class="sub">在这里分享日常、表白、求助与点滴心情，匿名或实名随你选择。社区由同学自发搭建，纯开源、可溯源。</p>
    <div class="cta">
      <?php if (isset($_GET['csrf_fail'])): ?>
      <div style="max-width:420px;margin:0 auto 16px;padding:10px 14px;border:1px solid #f3d8a4;background:#fdf6e7;color:#8a6a24;border-radius:10px;font-size:14px;">
        会话校验已过期，已为你重新准备安全令牌，请再点一次下面的按钮继续。
      </div>
      <?php endif; ?>
      <form method="post" action="<?= SITE_URL ?>/pages/gateway.php?to=register<?= $inviteQs ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="gate_confirm" value="1">
        <button type="submit" class="btn btn-primary">开始使用</button>
      </form>
      <a class="btn btn-ghost" href="<?= SITE_URL ?>/pages/gateway.php?browse=1">先逛逛（游客浏览）</a>
    </div>
    <div class="hint">
      已有账号？<form method="post" action="<?= SITE_URL ?>/pages/gateway.php?to=login" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="gate_confirm" value="1">
        <a href="javascript:void(0)" onclick="this.closest('form').submit()" style="font-weight:600;">直接去登录</a>
      </form>
      · 无账号将引导注册 · 游客仅可浏览前 <?= GUEST_VISIBLE_POSTS ?> 条动态
    </div>

    <div class="hero-glow">
      <div class="hero-card">
        <div class="hc-ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2F5B9A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
        <div class="hc-body">
          <div class="hc-title">非官方 · 内部圈子</div>
          <div class="hc-desc">本平台为同学们自发搭建的交流环境，不属于学校官方平台。通过自动登录保障账号体验。</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="features">
  <div class="wrap">
    <div class="section-head">
      <span class="eyebrow">社区功能</span>
      <h2>一个完整的校园线上社区</h2>
      <p>轻量、流畅、面向低配设备优化，随时随地都能用。</p>
    </div>
    <div class="features">
      <div class="feat-card">
        <div class="feat-ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2F5B9A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg></div>
        <h3>发动态 · 可匿名</h3>
        <p>文字、图片、投票轻松发布，实名或匿名随心切换，投稿头衔展示个性。</p>
      </div>
      <div class="feat-card">
        <div class="feat-ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2F5B9A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
        <h3>私信互动</h3>
        <p>站内私信多端同步，恶意链接自动预警，举报体系保障社区安全。</p>
      </div>
      <div class="feat-card">
        <div class="feat-ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2F5B9A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg></div>
        <h3>安全可信</h3>
        <p>防钓鱼网关、双重验证、IP/内容风控、数据备份，全方位守护隐私。</p>
      </div>
      <div class="feat-card">
        <div class="feat-ico"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2F5B9A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <h3>数据自主</h3>
        <p>签到、投票、头衔申请一个不少；数据可导出备份、公开可审计。</p>
      </div>
    </div>
  </div>
</section>

<section class="section" id="open" style="padding-top:0;">
  <div class="wrap">
    <div class="open-card">
      <div class="oc-body">
        <span class="eyebrow">开放源码</span>
        <h2>全程开源，欢迎共同维护</h2>
        <p>整个平台完全开源托管在 GitHub，代码公开、更新可追溯。无论是想部署、研究还是帮忙改进，都欢迎前往仓库了解。</p>
        <a class="btn btn-outline" href="<?= GITHUB_REPO_URL ?>" target="_blank" rel="noopener">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 .5A11.5 11.5 0 0 0 .5 12a11.5 11.5 0 0 0 7.86 10.92c.58.11.79-.25.79-.56v-2c-3.2.7-3.88-1.37-3.88-1.37-.53-1.34-1.29-1.7-1.29-1.7-1.05-.72.08-.71.08-.71 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.57-.29-5.27-1.28-5.27-5.7 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11.06 11.06 0 0 1 5.79 0c2.21-1.49 3.18-1.18 3.18-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.84 1.19 3.1 0 4.43-2.7 5.41-5.28 5.69.41.36.78 1.06.78 2.14v3.18c0 .31.21.68.8.56A11.5 11.5 0 0 0 23.5 12 11.5 11.5 0 0 0 12 .5z"/></svg>
          前往 <?= GITHUB_REPO_NAME ?> 查看源码
        </a>
      </div>
      <div class="oc-side">
        <span class="repo-chip"><?= GITHUB_REPO_NAME ?>&nbsp;<span style="color:var(--primary);font-weight:700;">GitHub◆</span></span>
      </div>
    </div>
  </div>
</section>

<section class="cta-band" id="getstarted">
  <div class="wrap">
    <h2>准备好开始了吗？</h2>
    <p>加入我们，把你的想法写进校园墙。</p>
    <form method="post" action="<?= SITE_URL ?>/pages/gateway.php?to=register<?= $inviteQs ?>" style="display:inline-block;">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="gate_confirm" value="1">
      <button type="submit" class="btn btn-primary">立即开始</button>
    </form>
  </div>
</section>

<footer class="site-foot">
  <div class="foot-inner">
    <div class="foot-safe">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2E8B57" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
      <span><strong>官方入口</strong>：本页为本站官方访问入口，不会索要验证码或转账信息，填写前请确认网址无误。</span>
    </div>
    <div class="foot-meta">
      本平台为学生自发搭建的交流平台，不属于淮南市北师大实验中学官方平台 © 2026 · 开源于 <a href="<?= GITHUB_REPO_URL ?>" target="_blank" rel="noopener">GitHub</a>
    </div>
  </div>
</footer>

</body>
</html>