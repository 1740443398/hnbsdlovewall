<?php
/**
 * QQ 空间转发 —— 后台配置
 * ---------------------------------------------------------------------------
 * 把某个 QQ 号的空间说说镜像到本站（只读：站内不反向点赞/评论）。
 * QQ 空间必须登录才能读，所以这里保存的是**管理员自己的登录 Cookie**，
 * 落盘加密（AES-256-CBC），页面只显示「有没有关键字段」，不显示任何值。
 *
 * 页面上的每个字段都对应 includes/qzone_sync.php 的 lwQzoneDefaults()，
 * 逻辑里没有写死的值 —— 想改行为改这里就够。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/qzone_sync.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_settings')) {
    http_response_code(403);
    die('403 Forbidden');
}

$self = '/admin/qzone.php';
$redirect = function (string $q) use ($self) {
    header('Location: ' . $self . '?' . $q);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $redirect('err=' . urlencode('CSRF 验证失败，请重试'));
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        lwQzoneSaveConfig([
            'enabled'        => isset($_POST['enabled']) ? 1 : 0,
            'uin'            => preg_replace('/\D/', '', (string) ($_POST['uin'] ?? '')),
            'page_size'      => max(1, min(50, (int) ($_POST['page_size'] ?? 20))),
            'max_items'      => max(10, min(1000, (int) ($_POST['max_items'] ?? 200))),
            'sync_comments'  => isset($_POST['sync_comments']) ? 1 : 0,
            'comments_per'   => max(0, min(50, (int) ($_POST['comments_per'] ?? 30))),
            'sync_likes'     => isset($_POST['sync_likes']) ? 1 : 0,
            'show_home'      => isset($_POST['show_home']) ? 1 : 0,
            'home_count'     => max(-1, min(100, (int) ($_POST['home_count'] ?? 3))),
            'auto_sync'      => isset($_POST['auto_sync']) ? 1 : 0,
            'sync_interval'  => max(60, min(86400, (int) ($_POST['sync_interval'] ?? 600))),
            'image_max_width'  => max(320, min(2400, (int) ($_POST['image_max_width'] ?? 1080))),
            'image_quality'    => max(50, min(95, (int) ($_POST['image_quality'] ?? 82))),
            'prefetch_per_run' => max(0, min(30, (int) ($_POST['prefetch_per_run'] ?? 8))),
        ]);
        $redirect('msg=' . urlencode('配置已保存'));
    }

    if ($action === 'save_cookie') {
        $raw = trim((string) ($_POST['cookie'] ?? ''));
        if ($raw === '') {
            $redirect('err=' . urlencode('Cookie 不能为空（想清除请用「清除 Cookie」）'));
        }
        lwQzoneSetCookie($raw);
        $hint = lwQzoneCookieHint();
        $miss = [];
        if (!$hint['p_skey']) { $miss[] = 'p_skey'; }
        if (!$hint['skey'])   { $miss[] = 'skey'; }
        if (!$hint['uin'])    { $miss[] = 'uin'; }
        if ($miss) {
            $redirect('err=' . urlencode('Cookie 已保存，但缺少关键字段：' . implode(' / ', $miss) . '（同步会失败，请确认复制的是空间页面的完整 Cookie）'));
        }
        $redirect('msg=' . urlencode('Cookie 已保存，关键字段齐全（已加密落盘）'));
    }

    if ($action === 'clear_cookie') {
        lwQzoneSetCookie('');
        lwQzoneSaveConfig(['last_state' => 'idle', 'last_error' => '']);
        $redirect('msg=' . urlencode('Cookie 已清除'));
    }

    if ($action === 'import') {
        $raw = trim((string) ($_POST['import_json'] ?? ''));
        if ($raw === '') {
            $redirect('err=' . urlencode('请粘贴要导入的 JSON'));
        }
        $bundle = json_decode($raw, true);
        if (!is_array($bundle)) {
            $redirect('err=' . urlencode('JSON 解析失败（请确认是本地 tools/qzone_scrape.php 生成的文件内容）'));
        }
        $r = lwQzoneImportData($bundle);
        if (!$r['ok']) {
            $redirect('err=' . urlencode('导入失败：' . $r['err']));
        }
        $redirect('msg=' . urlencode("导入完成：新增 {$r['added']} 条，更新 {$r['updated']} 条"));
    }

    if ($action === 'regen_token') {
        lwQzoneSaveConfig(['cron_token' => bin2hex(random_bytes(16))]);
        $redirect('msg=' . urlencode('定时触发令牌已重置（旧 URL 立即失效）'));
    }

    if ($action === 'test') {
        $r = lwQzoneFetchFeeds(0, 3);
        if ($r['ok']) {
            $n = count($r['items']);
            $sample = $n ? mb_substr((string) $r['items'][0]['content'], 0, 40) : '';
            lwQzoneSaveConfig(['last_state' => 'ok', 'last_error' => '']);
            $redirect('msg=' . urlencode("Cookie 有效：拉到 {$n} 条" . ($sample !== '' ? "，最新一条「{$sample}…」" : '')));
        }
        lwQzoneSaveConfig(['last_state' => $r['state'], 'last_error' => $r['err']]);
        $label = $r['state'] === 'cookie_expired' ? 'Cookie 已失效，请重新粘贴' : '抓取失败';
        $redirect('err=' . urlencode($label . '：' . $r['err']));
    }

    if ($action === 'sync') {
        $res = lwQzoneSync(['pages' => 1]);
        if ($res['ok']) {
            $redirect('msg=' . urlencode("同步完成：新增 {$res['added']} 条，更新 {$res['updated']} 条"));
        }
        $label = $res['state'] === 'cookie_expired' ? 'Cookie 已失效，请重新粘贴' : '同步失败';
        $redirect('err=' . urlencode($label . '：' . $res['err']));
    }

    $redirect('err=' . urlencode('未知操作'));
}

$cfg  = lwQzoneConfig();
$hint = lwQzoneCookieHint();
if (empty($cfg['cron_token'])) {
    $cfg = lwQzoneSaveConfig(['cron_token' => bin2hex(random_bytes(16))]);
}
$cronUrl = (function_exists('SITE_URL') ? SITE_URL : '') . '/api/qzone/sync.php?token=' . urlencode((string) $cfg['cron_token']);

$stateLabels = [
    'idle'           => ['未运行', 'secondary'],
    'ok'             => ['正常', 'success'],
    'cookie_expired' => ['Cookie 失效', 'danger'],
    'error'          => ['出错', 'warning'],
];
$st = $stateLabels[$cfg['last_state']] ?? $stateLabels['idle'];

/* 导出：把当前镜像数据原样下载成 JSON（供备份 / 迁移 / 本地比对） */
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $bundle = lwQzoneExportData();
    $json = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="qzone-export-' . date('Ymd-His') . '.json"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

$msg = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
$err = isset($_GET['err']) ? (string) $_GET['err'] : '';

adminHeader('QQ 空间转发', $adminUser, $csrfToken);
?>
<style>
.qz-row { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.qz-row > label.qz-label { width: 190px; font-size: 14px; color: var(--text); }
.qz-hint { font-size: 12px; color: var(--text-secondary); line-height: 1.6; }
.qz-input { width: 140px; }
.qz-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.qz-badge-success { background: var(--success-light, #E6F5EC); color: var(--success, #1E7A4C); }
.qz-badge-danger  { background: var(--danger-light, #FBE9E8); color: var(--danger, #A9312B); }
.qz-badge-warning { background: var(--warning-light, #FBF1E0); color: var(--warning, #8A5A0B); }
.qz-badge-secondary { background: var(--bg-secondary, #EEF2F8); color: var(--text-secondary, #5A6B82); }
.qz-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 24px; }
.qz-mono { font-family: ui-monospace, Consolas, monospace; font-size: 12px; word-break: break-all; }
</style>

<?php if ($msg !== ''): ?><div class="toast toast-success" style="position:static;margin-bottom:12px;"><?= xss_clean($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="toast toast-error" style="position:static;margin-bottom:12px;"><?= xss_clean($err) ?></div><?php endif; ?>

<div class="section">
    <div class="section-header"><h3>运行状态</h3></div>
    <div class="section-body">
        <div class="qz-grid">
            <div>状态：<span class="qz-badge qz-badge-<?= xss_clean($st[1]) ?>"><?= xss_clean($st[0]) ?></span></div>
            <div>上次成功同步：<strong><?= $cfg['last_sync_at'] ? date('Y-m-d H:i:s', (int) $cfg['last_sync_at']) : '—' ?></strong></div>
            <div>上次新增：<strong><?= (int) $cfg['last_count'] ?></strong> 条</div>
            <div>上次预热图片：<strong><?= (int) $cfg['last_images'] ?></strong> 张</div>
            <div>Cookie：<strong><?= $hint['present'] ? ($hint['p_skey'] && $hint['skey'] && $hint['uin'] ? '关键字段齐全' : '字段不全') : '未配置' ?></strong>
                <?php if ($hint['present']): ?><span class="qz-hint">（长度 <?= (int) $hint['len'] ?>，更新于 <?= $hint['updated'] ? date('m-d H:i', (int) $hint['updated']) : '—' ?>）</span><?php endif; ?>
            </div>
        </div>
        <?php $mailReady = lwQzoneMailReady(); ?>
        <p class="qz-hint" style="margin-top:8px;">
            失败邮件告警：<?= $mailReady
                ? '<b style="color:var(--success,#1E7A4C)">SMTP 已配置</b>（配置了站长邮箱后，失败/Cookie 失效会发信）'
                : '<b style="color:var(--warning,#8A5A0B)">SMTP 未配置</b>（需要先在「邮件通知」页配好 SMTP，否则失败时不会发信）' ?>
        </p>
        <?php if ($cfg['last_error'] !== ''): ?>
        <p class="qz-hint" style="margin-top:10px;color:var(--danger,#A9312B);">最近错误：<?= xss_clean($cfg['last_error']) ?></p>
        <?php endif; ?>
        <p class="qz-hint" style="margin-top:10px;">
            说明：本站只做<b>只读镜像</b> —— 站内用户的点赞/评论不会回写到 QQ 空间（回写等于用站长账号去操作，既有风控风险，语义也不对）。
        </p>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>扫码登录（自动获取 Cookie）</h3></div>
    <div class="section-body">
        <p class="qz-hint" style="margin-bottom:10px;">
            用手机 QQ 扫下面的码，授权后会自动把 Cookie 存进来，不用再去 F12 里复制。
            <b>扫码也可能失败</b>（腾讯对服务器 IP 的风控、二次验证等），失败就回退到下面的「手动粘贴」。
        </p>
        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;">
            <div id="qzQrBox" style="width:180px;height:180px;border:1px solid var(--border-glass);border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--bg-secondary);">
                <span class="qz-hint">还没生成</span>
            </div>
            <div style="flex:1;min-width:220px;">
                <button class="btn btn-primary" type="button" id="qzQrBtn">显示二维码</button>
                <p class="qz-hint" id="qzQrMsg" style="margin-top:10px;">点按钮后出现二维码，用手机 QQ 扫。</p>
            </div>
        </div>
        <p class="qz-hint" style="margin-top:10px;">
            说明：扫码成功后 Cookie 会自动加密保存；二维码 5 分钟过期；本站不会保存你的 QQ 密码。
        </p>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>登录 Cookie</h3></div>
    <div class="section-body">
        <p class="qz-hint" style="margin-bottom:10px;">
            获取方法：电脑浏览器登录 QQ 空间 → 打开 <code>user.qzone.qq.com/<?= xss_clean((string) $cfg['uin'] ?: '<对方QQ>') ?></code>
            → F12 打开开发者工具 → Network 里点任意请求 → 复制请求头 <code>Cookie</code> 整行 →
            粘贴到下面。支持三种：纯 <code>a=b; c=d</code>、整行 <code>Cookie: ...</code>、或
            插件导出的 <code>{"cookie": "..."}</code> JSON。
        </p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="save_cookie">
            <div class="qz-row">
                <label class="qz-label">Cookie</label>
                <textarea name="cookie" rows="4" style="flex:1;min-width:280px;font-family:ui-monospace,monospace;font-size:12px;" placeholder="uin=o03893174881; skey=...; p_skey=..."></textarea>
            </div>
            <div class="qz-row" style="gap:10px;">
                <button class="btn btn-primary" type="submit">保存 Cookie</button>
                <button class="btn" type="submit" name="action" value="test" formnovalidate>测试 Cookie</button>
                <button class="btn" type="submit" name="action" value="clear_cookie" onclick="return confirm('确定清除已保存的 Cookie？');">清除 Cookie</button>
                <button class="btn" type="submit" name="action" value="sync">立即同步一次</button>
            </div>
        </form>
        <p class="qz-hint" style="margin-top:6px;">Cookie 会加密后存在本地数据里，页面只显示是否齐全，不回显内容。QQ 空间 Cookie 一般能维持数天到数周，失效后这里会显示「Cookie 失效」，重新粘贴即可。</p>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>转发配置</h3></div>
    <div class="section-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="save">

            <div class="qz-row">
                <label class="qz-label">启用同步</label>
                <label style="width:auto;"><input type="checkbox" name="enabled" <?= $cfg['enabled'] ? 'checked' : '' ?>></label>
                <span class="qz-hint">关闭后不再抓取，已同步的内容仍会保留展示</span>
            </div>
            <div class="qz-row">
                <label class="qz-label">对方 QQ 号</label>
                <input class="qz-input" type="text" name="uin" value="<?= xss_clean((string) $cfg['uin']) ?>" placeholder="3893174881" inputmode="numeric">
                <span class="qz-hint">要镜像的空间的主人 QQ 号（即链接里的那串数字）</span>
            </div>
            <div class="qz-row">
                <label class="qz-label">每次抓取条数</label>
                <input class="qz-input" type="number" name="page_size" value="<?= (int) $cfg['page_size'] ?>" min="1" max="50">
                <span class="qz-hint">1–50，越大越慢；免费主机建议 20</span>
            </div>
            <div class="qz-row">
                <label class="qz-label">本地最多保留</label>
                <input class="qz-input" type="number" name="max_items" value="<?= (int) $cfg['max_items'] ?>" min="10" max="1000">
                <span class="qz-hint">超出后按对方发布时间从旧到新删除</span>
            </div>

            <div class="qz-row">
                <label class="qz-label">同步评论</label>
                <label style="width:auto;"><input type="checkbox" name="sync_comments" <?= $cfg['sync_comments'] ? 'checked' : '' ?>></label>
                <span class="qz-hint">同步评论人和评论内容（只读镜像，站内不能在此回复）</span>
            </div>
            <div class="qz-row">
                <label class="qz-label">每条取多少评论</label>
                <input class="qz-input" type="number" name="comments_per" value="<?= (int) $cfg['comments_per'] ?>" min="0" max="50">
                <span class="qz-hint">0 = 只取评论数量不取内容</span>
            </div>
            <div class="qz-row">
                <label class="qz-label">同步点赞数</label>
                <label style="width:auto;"><input type="checkbox" name="sync_likes" <?= $cfg['sync_likes'] ? 'checked' : '' ?>></label>
                <span class="qz-hint">显示对方空间的点赞数（只读，站内不可点）</span>
            </div>

            <div class="qz-row">
                <label class="qz-label">混入首页</label>
                <label style="width:auto;"><input type="checkbox" name="show_home" <?= $cfg['show_home'] ? 'checked' : '' ?>></label>
                <input class="qz-input" type="number" name="home_count" value="<?= (int) $cfg['home_count'] ?>" min="-1" max="100">
                <span class="qz-hint"><b>-1</b> = 全部并进首页信息流；<b>0</b> = 首页不显示；<b>1~100</b> = 并进最新 N 条</span>
            </div>

            <div class="qz-row">
                <label class="qz-label">站长邮箱（失败告警）</label>
                <input class="qz-input" style="width:260px;" type="email" name="notify_email" value="<?= xss_clean((string) $cfg['notify_email']) ?>" placeholder="you@qq.com">
                <label style="width:auto;"><input type="checkbox" name="notify_on_error" <?= $cfg['notify_on_error'] ? 'checked' : '' ?>> 失败时发邮件</label>
                <span class="qz-hint">同步失败或 Cookie 失效时发到这里（同类失败 6 小时内只发一封，防刷屏）</span>
            </div>

            <div class="qz-row">
                <label class="qz-label">访问触发自动同步</label>
                <label style="width:auto;"><input type="checkbox" name="auto_sync" <?= $cfg['auto_sync'] ? 'checked' : '' ?>></label>
                <span class="qz-hint">有人访问时顺带检查一次（免费主机没有真 cron，用这个兜底）</span>
            </div>
            <div class="qz-row">
                <label class="qz-label">最小同步间隔</label>
                <input class="qz-input" type="number" name="sync_interval" value="<?= (int) $cfg['sync_interval'] ?>" min="60" max="86400">
                <span class="qz-hint">秒。600 = 10 分钟一次；太频繁会拖慢访问也更容易触发对方风控</span>
            </div>

            <button class="btn btn-primary" type="submit">保存配置</button>
        </form>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>数据导入 / 导出</h3></div>
    <div class="section-body">
        <p class="qz-hint" style="margin-bottom:10px;">
            用途：先在本地用 <code>tools/qzone_scrape.php</code> 抓一遍（需要同一次 Cookie），
            再把生成的 JSON 粘到下面导入。这样服务器只需要低频抓取（默认 24 小时一次），
            部署时不必把整份历史都压在共享主机上抓。
        </p>
        <p><a class="btn" href="/admin/qzone.php?export=1">导出当前镜像数据（JSON）</a></p>
        <form method="post" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="import">
            <div class="qz-row" style="align-items:flex-start;">
                <label class="qz-label">导入 JSON</label>
                <textarea name="import_json" rows="5" style="flex:1;min-width:280px;font-family:ui-monospace,monospace;font-size:12px;" placeholder='{"version":1,"items":[...],"comments":{...}}'></textarea>
            </div>
            <button class="btn btn-primary" type="submit" onclick="return confirm('导入会按 tid 合并：已存在的更新点赞/评论数，不存在的追加。继续？');">导入</button>
        </form>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>定时触发（外部 cron）</h3></div>
    <div class="section-body">
        <p class="qz-hint" style="margin-bottom:10px;">
            免费主机没有真正的 cron。把下面这个带令牌的 URL 填到外部定时服务
            （如 cron-job.org，免费 5 分钟一次），就能在没人访问时也保持同步。
            令牌泄露等于把「触发同步」的权限给了别人（只能触发，不能读 Cookie）。
        </p>
        <p class="qz-mono"><?= xss_clean($cronUrl) ?></p>
        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="regen_token">
            <button class="btn" type="submit" onclick="return confirm('重置令牌后旧 URL 立即失效，确定？');">重置令牌</button>
        </form>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('qzQrBtn');
    var box = document.getElementById('qzQrBox');
    var msg = document.getElementById('qzQrMsg');
    if (!btn) return;
    var timer = null;
    var CSRF = <?= json_encode($csrfToken) ?>;

    function setMsg(t, color) {
        msg.textContent = t;
        msg.style.color = color || '';
    }
    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
    }
    function poll() {
        fetch('/api/qzone/qr_poll.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.state === 'success') {
                    stop();
                    setMsg('已保存 Cookie，正在刷新…', 'var(--success,#1E7A4C)');
                    setTimeout(function () { location.reload(); }, 800);
                    return;
                }
                if (d.state === 'expired' || d.state === 'none' || d.state === 'error') {
                    stop();
                    setMsg(d.msg || '扫码失败，请重试或改用手动粘贴', 'var(--danger,#A9312B)');
                    return;
                }
                setMsg(d.msg || '等待扫码…');
            })
            .catch(function () { stop(); setMsg('轮询失败，请重试', 'var(--danger,#A9312B)'); });
    }
    btn.addEventListener('click', function () {
        stop();
        btn.disabled = true;
        setMsg('正在生成二维码…');
        fetch('/api/qzone/qr_start.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'csrf_token=' + encodeURIComponent(CSRF)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false;
                if (!d.ok) { setMsg(d.err || '生成二维码失败', 'var(--danger,#A9312B)'); return; }
                box.innerHTML = '<img alt="QQ 登录二维码" style="width:170px;height:170px;" src="data:image/png;base64,' + d.png + '">';
                setMsg('等待扫码…');
                timer = setInterval(poll, 2000);
            })
            .catch(function () {
                btn.disabled = false;
                setMsg('请求失败，请重试', 'var(--danger,#A9312B)');
            });
    });
})();
</script>

<?php adminFooter(); ?>
