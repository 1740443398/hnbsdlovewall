<?php
/**
 * 常见问题（FAQ）
 *
 * 用途：新用户第一次进来最常见的问题集中在这里，减少重复提问，
 * 也让 App 的「全部功能」有一个稳定的帮助入口。
 */
require_once __DIR__ . '/../config/config.php';

$user = requireLoginOrGuest();
if ($user) { $user = checkBanned($user); }
$isGuest = ($user === null);
$pageTitle = "常见问题 · " . SITE_NAME;
$LANG_CODE = $LANG_CODE ?? "zh_CN";
$faqs = [
    ["怎么注册账号？", "点右上角「登录」→「注册」，填昵称、QQ 号和密码即可。QQ 号用于找回账号，请填真实可用的。注册后需要设置一个安全问题，忘记密码时可以靠它重置。"],
    ["为什么我看不到全部帖子？", "游客只能看到最新 3 条。注册登录后可以看到全部公开内容。部分帖子作者设了可见范围（比如仅好友、仅同班），这类帖子对你不显示是正常的。"],
    ["发帖有什么要求？", "标题 2-200 字，正文 5-5000 字。禁止广告、辱骂、隐私泄露、色情与违法内容。系统会自动审核，未通过的内容不会发布，并在后台标记违规类型。"],
    ["帖子被审核了怎么办？", "自动审核会先过 AI 与关键词。如果被判为「待审」，说明系统认为可能有风险但不是确定违规，会等管理员人工复核。复核通过后自动发布，不需要你重新发。"],
    ["账号被盗了怎么办？", "立刻用「忘记密码」通过安全问题重置。如果安全问题也忘了，联系管理员并提供能证明身份的信息（比如注册时的 QQ）。建议开启双重验证（2FA）。"],
    ["怎么删除自己的帖子？", "进入「我的」→「我的帖子」，每条右边有删除按钮。删除后不可恢复，评论和点赞会一起消失。"],
    ["为什么图片上传失败？", "单张图片不能超过 5MB，支持 jpg/png/gif/webp。如果一直失败，可能是网络不稳定，稍后重试；也可能是存储空间满了，可以联系管理员。"],
    ["怎么升级等级？", "发帖 +10 经验、发评论 +3、被点赞 +2、每日签到 +5。等级会解锁不同的头像框和头衔，具体见「成长中心」。"],
    ["App 和网页数据是同步的吗？", "是。App 与网页共用同一个账号和同一份数据。在网页发的帖子，App 刷新后就能看到；反过来也一样。"],
    ["怎么联系管理员？", "站内私信发给管理员账号，或者发一条分类为「网站反馈」的帖子。紧急问题（比如有人发违法内容）请在帖子里写明「紧急」并 @ 管理员。"],
];
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . "/../includes/pwa_head.php"; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <?php require __DIR__ . "/../includes/seo_meta.php"; ?>
    <link rel="stylesheet" href="<?= asset_url("/assets/css/style.css") ?>?v=<?= asset_ver("/assets/css/style.css") ?>">
    <link rel="stylesheet" href="<?= asset_url("/assets/css/enhancements.css") ?>?v=<?= asset_ver("/assets/css/enhancements.css") ?>">
    <script>
        const SITE_URL = "<?= SITE_URL ?>";
        const IS_LOGGED_IN = <?= $user ? "true" : "false" ?>;
        const IS_GUEST = <?= $isGuest ? "true" : "false" ?>;
    </script>
    <style>
    .faq-wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 60px; }
    .faq-title { font-size: 1.5rem; margin: 0 0 6px; }
    .faq-sub { color: #718096; font-size: .9rem; margin: 0 0 24px; }
    .faq-item {
        background: #fff; border-radius: 12px; margin-bottom: 10px;
        border: 1px solid #edf1f5; overflow: hidden;
    }
    .faq-q {
        padding: 16px 18px; font-size: .95rem; font-weight: 600;
        color: #1a202c; cursor: pointer; display: flex;
        justify-content: space-between; align-items: center; gap: 12px;
    }
    .faq-q:hover { background: #fafbfc; }
    .faq-q .arrow { color: #a0aec0; transition: transform .18s; flex-shrink: 0; }
    .faq-item.open .faq-q .arrow { transform: rotate(90deg); }
    .faq-a {
        max-height: 0; overflow: hidden; transition: max-height .22s ease;
        padding: 0 18px; font-size: .88rem; line-height: 1.85; color: #4a5568;
    }
    .faq-item.open .faq-a { max-height: 500px; padding-bottom: 16px; }
    .faq-contact {
        margin-top: 28px; padding: 18px; background: #eef3fa;
        border-radius: 12px; font-size: .88rem; color: #4a5568; line-height: 1.8;
    }
    </style>
</head>
<body>
<?php require __DIR__ . "/../includes/site_header.php"; ?>

<main class="site-main">
<div class="faq-wrap">
    <h1 class="faq-title">常见问题</h1>
    <p class="faq-sub">新同学最常问的都在这里。找不到答案就发帖问，管理员会回。</p>

    <?php foreach ($faqs as $i => $f): ?>
    <div class="faq-item" data-i="<?= $i ?>">
        <div class="faq-q">
            <span><?= htmlspecialchars($f[0]) ?></span>
            <span class="arrow">›</span>
        </div>
        <div class="faq-a"><?= htmlspecialchars($f[1]) ?></div>
    </div>
    <?php endforeach; ?>

    <div class="faq-contact">
        还是没解决？<a href="/pages/post.php" style="color:#2F5B9A">发一条「网站反馈」分类的帖子</a>，
        或者站内私信管理员。紧急问题请在标题写「紧急」。
    </div>
</div>
</main>

<script>
document.querySelectorAll(".faq-q").forEach(function(q){
    q.addEventListener("click", function(){
        q.parentElement.classList.toggle("open");
    });
});
</script>

<?php require __DIR__ . "/../includes/mobile_bottom_nav.php"; ?>
<div class="toast-container" id="toastContainer"></div>
</body>
</html>
