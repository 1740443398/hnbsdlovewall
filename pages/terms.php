<?php
/**
 * 服务条款与隐私政策
 *
 * 商业社区必备页面：既是合规要求，也是「出现纠纷时怎么处理」的依据。
 * 内容按高中校园场景写，不套用模板套话。
 */
require_once __DIR__ . '/../config/config.php';

$user = requireLoginOrGuest();
if ($user) { $user = checkBanned($user); }
$isGuest = ($user === null);
$pageTitle = "服务条款 · " . SITE_NAME;
$LANG_CODE = $LANG_CODE ?? "zh_CN";
$updated = "2026-10-24";
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
    .tos-wrap { max-width: 720px; margin: 0 auto; padding: 24px 16px 60px; }
    .tos-wrap h1 { font-size: 1.5rem; margin: 0 0 4px; }
    .tos-updated { color: #a0aec0; font-size: .8rem; margin: 0 0 24px; }
    .tos-wrap h2 {
        font-size: 1.05rem; margin: 28px 0 10px; color: #1a202c;
        padding-left: 10px; border-left: 3px solid #2F5B9A;
    }
    .tos-wrap p, .tos-wrap li {
        font-size: .9rem; line-height: 1.9; color: #4a5568;
    }
    .tos-wrap ul { padding-left: 22px; margin: 8px 0; }
    .tos-wrap li { margin-bottom: 4px; }
    .tos-note {
        background: #fff8e6; border-left: 3px solid #e08a2e;
        padding: 12px 16px; border-radius: 6px; margin: 16px 0;
        font-size: .86rem; line-height: 1.8; color: #744210;
    }
    .tos-wrap a { color: #2F5B9A; }
    </style>
</head>
<body>
<?php require __DIR__ . "/../includes/site_header.php"; ?>

<main class="site-main">
<div class="tos-wrap">
    <h1>服务条款与隐私政策</h1>
    <p class="tos-updated">最后更新：<?= $updated ?></p>

    <h2>一、这是什么</h2>
    <p>
        <?= htmlspecialchars(SITE_NAME) ?> 是一个面向本校高中生的内容交流平台，
        由学生自发维护，非学校官方产品。使用本站即表示你同意以下条款。
    </p>

    <h2>二、账号</h2>
    <ul>
        <li>一人一号。禁止批量注册、买卖账号、共用账号。</li>
        <li>QQ 号用于身份找回，请填真实可用的，我们不会对外公开。</li>
        <li>账号被盗请立即重置密码并联系管理员，因未及时处理造成的损失由用户自行承担。</li>
        <li>连续 12 个月未登录的账号可能被回收，回收前会在站内公告。</li>
    </ul>

    <h2>三、内容规范</h2>
    <p>以下内容禁止发布，违者视情节警告、删帖或封号：</p>
    <ul>
        <li>色情、暴力、血腥、恐怖内容</li>
        <li>辱骂、人身攻击、校园欺凌、恶意曝光他人</li>
        <li>他人隐私：真实姓名、手机号、身份证号、家庭住址、班级座位等</li>
        <li>广告、刷单、代写代考、诈骗引流</li>
        <li>违反法律法规的内容</li>
        <li>无意义刷屏、恶意灌水</li>
    </ul>
    <div class="tos-note">
        <b>关于自动审核：</b>所有内容发布前会经过 AI 与关键词两层自动检查。
        被判为「待审」的内容不会立即发布，需管理员人工复核。
        自动审核可能误判 —— 如果确认自己的内容没问题，请发帖反馈或私信管理员。
    </div>

    <h2>四、我们收集什么</h2>
    <ul>
        <li><b>账号信息</b>：昵称、QQ 号、密码（加密存储）、注册时间</li>
        <li><b>内容数据</b>：你发布的帖子、评论、图片、投票</li>
        <li><b>行为数据</b>：登录时间、发帖频率、点赞收藏记录（用于推荐与风控）</li>
        <li><b>技术数据</b>：IP 地址、浏览器标识（用于安全防护与封禁滥用）</li>
    </ul>

    <h2>五、我们不做什么</h2>
    <ul>
        <li>不向任何第三方出售或共享你的个人信息</li>
        <li>不在站内投放第三方广告追踪</li>
        <li>不读取你设备上本站以外的数据（App 只访问自己的缓存与存储）</li>
    </ul>

    <h2>六、你的权利</h2>
    <ul>
        <li><b>导出</b>：可在「下载备份」页导出自己的全部内容</li>
        <li><b>删除</b>：可随时删除自己发布的帖子与评论</li>
        <li><b>注销</b>：联系管理员删除账号及全部关联数据</li>
    </ul>

    <h2>七、内容版权</h2>
    <p>
        你发布的内容版权仍归你所有。发布即授予本站非独占的展示权，
        用于在站内呈现与推荐。我们不会将你的内容用于商业用途。
    </p>

    <h2>八、免责</h2>
    <p>
        本站由学生业余维护，不保证服务不中断。因服务器故障、网络问题、
        不可抗力导致的数据丢失，我们会尽力恢复但不承担赔偿责任。
        请勿将本站作为唯一的数据存储地 —— 重要内容请自行备份。
    </p>

    <h2>九、条款变更</h2>
    <p>
        条款如有重大变更会在站内公告。继续使用即视为接受新条款。
    </p>

    <h2>十、联系我们</h2>
    <p>
        任何疑问、举报或数据请求，请发一条「网站反馈」分类的帖子，
        或站内私信管理员。我们通常在 48 小时内回复。
    </p>
</div>
</main>

<?php require __DIR__ . "/../includes/mobile_bottom_nav.php"; ?>
<div class="toast-container" id="toastContainer"></div>
</body>
</html>
