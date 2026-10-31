<?php
/**
 * 后台：邮件设置与发送记录
 *
 * ==========================================================================
 * 为什么这个页面必须存在
 * ==========================================================================
 * 之前 SMTP 配置在 config/mail_config.php 里，改一个字符都要动代码、
 * 重新上传 —— 手机上根本做不了。而且发信记录完全不存在：
 * 用户说「我没收到验证邮件」，站长无从查起是没发、发失败、还是进垃圾箱了。
 *
 * 这个页面把三件事合在一处：
 *   1. SMTP 连接参数（可改可测）
 *   2. 一键发测试邮件（验证配置对不对）
 *   3. 发送记录（每一封的去向与失败原因）
 * ==========================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/mail_service.php';
require_once __DIR__ . '/layout.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (($admin['role'] ?? '') !== 'super_admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

$csrfToken = generateCSRFToken();
$msg = '';
$msgType = 'ok';

// ---- 保存 SMTP 配置 --------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'save') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败，请刷新重试';
        $msgType = 'err';
    } else {
        $host = trim((string)($_POST['host'] ?? ''));
        $port = (int)($_POST['port'] ?? 465);
        $user = trim((string)($_POST['user'] ?? ''));
        $pass = (string)($_POST['pass'] ?? '');
        $from = trim((string)($_POST['from'] ?? ''));
        $fromName = trim((string)($_POST['from_name'] ?? ''));

        if ($host === '' || $user === '') {
            $msg = 'SMTP 服务器和账号不能为空';
            $msgType = 'err';
        } else {
            if ($pass === '') {
                // 留空表示不修改密码
                $cur = is_file(__DIR__ . '/../config/mail_config.php')
                    ? (include __DIR__ . '/../config/mail_config.php')
                    : [];
                $pass = is_array($cur) ? (string)($cur['pass'] ?? '') : '';
            }
            $php = "<?php
"
                 . "// QQ邮箱 SMTP 配置（含授权码，较敏感：请勿上传公开仓库）
"
                 . "// 本机专属配置：已由根目录 .gitignore 忽略，绝不会进入 GitHub 开源版。
"
                 . "// 修改入口：后台 → 邮件设置（改这里会被覆盖，请优先用后台）
"
                 . "return [
"
                 . "    'host' => " . var_export($host, true) . ",
"
                 . "    'port' => " . var_export($port, true) . ",
"
                 . "    'user' => " . var_export($user, true) . ",
"
                 . "    'pass' => " . var_export($pass, true) . ",
"
                 . "    'from' => " . var_export($from !== '' ? $from : $user, true) . ",
"
                 . "    'from_name' => " . var_export($fromName !== '' ? $fromName : (defined('SITE_NAME') ? SITE_NAME : '校园交流墙') . '（站内通知）', true) . ",
"
                 . "];
";
            if (@file_put_contents(__DIR__ . '/../config/mail_config.php', $php, LOCK_EX) !== false) {
                $msg = 'SMTP 配置已保存';
            } else {
                $msg = '写入 config/mail_config.php 失败，请检查目录权限';
                $msgType = 'err';
            }
        }
    }
}

// ---- 发测试邮件 ------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'test') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败，请刷新重试';
        $msgType = 'err';
    } else {
        $to = trim((string)($_POST['test_to'] ?? ''));
        if ($to === '' || strpos($to, '@') === false) {
            $msg = '请填写有效的收件邮箱';
            $msgType = 'err';
        } else {
            $r = lwMailSendTest($to);
            $msg = (string)($r['message'] ?? '');
            $msgType = !empty($r['ok']) ? 'ok' : 'err';
        }
    }
}

// ---- 清空记录 --------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'clear') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        @file_put_contents(__DIR__ . '/../data/mail_log.json', '[]', LOCK_EX);
        $msg = '发送记录已清空';
    }
}

// ---- SMTP 连通性探测（不发信）-----------------------------------------------
$probe = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'probe') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败，请刷新重试';
        $msgType = 'err';
    } else {
        $probe = QQMailer::testConnection();
    }
}

// ---- 群发 ------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'broadcast') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败，请刷新重试';
        $msgType = 'err';
    } else {
        $subject  = trim((string)($_POST['subject'] ?? ''));
        $title    = trim((string)($_POST['title'] ?? ''));
        $linesRaw = (string)($_POST['lines'] ?? '');
        $link     = trim((string)($_POST['link'] ?? ''));
        $linkText = trim((string)($_POST['linkText'] ?? ''));
        $scope    = (string)($_POST['scope'] ?? 'all');
        $limit    = max(1, min(100, (int)($_POST['limit'] ?? 30)));

        if ($subject === '' || $title === '') {
            $msg = '主题和标题不能为空';
            $msgType = 'err';
        } else {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $linesRaw)), function ($x) {
                return $x !== '';
            }));

            /* 按范围挑收件人。
             *
             * 【字段名踩过的坑】
             * 原来读的是 last_login_at / last_active_at —— 这两个字段
             * 在 users 表里根本不存在（实际字段叫 last_visit）。
             * 结果「最近 30 天活跃」和「超 30 天未登录」都选不出任何人，
             * 群发等于没发。
             *
             * 现在用 last_visit；它为空的老账号按「从未访问」处理，
             * 归到「沉默用户」里 —— 这些人其实最需要被唤醒。
             */
            $fs2 = getFS();
            $allUsers = $fs2->read('users');
            $ids = [];
            $cut30 = time() - 30 * 86400;

            foreach ($allUsers as $u) {
                $uid = (int)($u['id'] ?? 0);
                if ($uid <= 0) continue;
                if (!empty($u['is_banned'])) continue;
                if (lwMailAddressOf($u) === '') continue;

                $last = trim((string)($u['last_visit'] ?? ''));
                $ts = $last !== '' ? (strtotime($last) ?: 0) : 0;

                if ($scope === 'all') {
                    $ids[] = $uid;
                } elseif ($scope === 'active') {
                    if ($ts >= $cut30) $ids[] = $uid;
                } elseif ($scope === 'inactive') {
                    // 含从未访问过的（$ts === 0）
                    if ($ts < $cut30) $ids[] = $uid;
                } elseif ($scope === 'owner') {
                    // 只发站长自己（用来先给自己试一封）
                    if ((string)($u['qq'] ?? '') === '1740443398') $ids[] = $uid;
                }
            }

            $r = lwMailBroadcast($ids, $subject, [
                'title'    => $title,
                'lines'    => $lines,
                'link'     => $link,
                'linkText' => $linkText !== '' ? $linkText : '打开网站',
            ], $limit);

            $msg = sprintf(
                '群发完成：成功 %d 封，失败 %d 封，跳过 %d 个（共 %d 个候选）',
                $r['sent'], $r['failed'], $r['skipped'], count($ids)
            );
            $msgType = ($r['sent'] > 0 || $r['failed'] === 0) ? 'ok' : 'err';

            if ($r['failed'] > 0) {
                $firstErr = '';
                foreach ($r['results'] as $rr) {
                    if (empty($rr['ok']) && $rr['err'] !== '') { $firstErr = $rr['err']; break; }
                }
                if ($firstErr !== '') {
                    $msg .= '（首个失败原因：' . $firstErr . '）';
                }
            }
        }
    }
}

// ---- 计算各范围的实际收件人数（供表单显示）-----------------------------------
$scopeCounts = ['all' => 0, 'active' => 0, 'inactive' => 0, 'owner' => 0];
$cut30 = time() - 30 * 86400;
foreach ($fs->read('users') as $u) {
    $uid = (int)($u['id'] ?? 0);
    if ($uid <= 0) continue;
    if (!empty($u['is_banned'])) continue;
    if (lwMailAddressOf($u) === '') continue;

    $scopeCounts['all']++;
    $ts = trim((string)($u['last_visit'] ?? '')) !== ''
        ? (strtotime((string)$u['last_visit']) ?: 0) : 0;
    if ($ts >= $cut30) $scopeCounts['active']++;
    else $scopeCounts['inactive']++;
    if ((string)($u['qq'] ?? '') === '1740443398') $scopeCounts['owner']++;
}

// ---- 读当前配置 ------------------------------------------------------------
$curCfg = [];
if (is_file(__DIR__ . '/../config/mail_config.php')) {
    $tmp = include __DIR__ . '/../config/mail_config.php';
    if (is_array($tmp)) $curCfg = $tmp;
}

$configured = class_exists('QQMailer') && QQMailer::isConfigured();

// ---- 统计 ----------------------------------------------------------------
$log = lwMailLogRead();
$log = array_reverse($log);   // 最新的在前

$stats = ['total' => 0, 'ok' => 0, 'fail' => 0, 'skip' => 0];
$byType = [];
foreach ($log as $r) {
    $stats['total']++;
    if (!empty($r['skipped'])) $stats['skip']++;
    elseif (!empty($r['ok'])) $stats['ok']++;
    else $stats['fail']++;

    $t = (string)($r['type'] ?? '?');
    $byType[$t] = ($byType[$t] ?? 0) + 1;
}
arsort($byType);

// 分页
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$totalRows = count($log);
$maxPage = max(1, (int)ceil($totalRows / $perPage));
$pageNo = min($pageNo, $maxPage);
$rows = array_slice($log, ($pageNo - 1) * $perPage, $perPage);

$types = lwMailTypes();
adminHeader('邮件设置', $admin, $csrfToken);
?>

<style>
.mail-wrap { max-width: 980px; }
.mail-msg { padding: 11px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
.mail-msg.ok  { background: var(--success-light); color: var(--success); border: 1px solid var(--success-light); }
.mail-msg.err { background: var(--danger-light); color: var(--danger); border: 1px solid var(--danger-light); }
.mail-badge.on  { background: var(--success-light); color: var(--success); }
.mail-badge.off { background: var(--warning-light); color: var(--warning); }
@media (max-width: 700px) { }
.mail-field label {
    display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;
    color: var(--text-primary, var(--text));
}
.mail-field .hint { font-size: 12px; color: var(--text-muted, var(--text-muted)); margin-bottom: 6px; line-height: 1.6; }
.mail-stat b { display: block; font-size: 19px; font-weight: 700; font-variant-numeric: tabular-nums; }
.mail-stat span { font-size: 12px; color: var(--text-muted, var(--text-muted)); }
.mail-table th {
    text-align: left; padding: 9px 10px; font-size: 12px; font-weight: 600;
    color: var(--text-muted, var(--text-muted)); border-bottom: 1px solid var(--border-color, var(--border));
    white-space: nowrap;
}
.mail-table td {
    padding: 10px; border-bottom: 1px solid var(--border-light, var(--bg-secondary));
    vertical-align: top; word-break: break-all;
}
.mail-ok   { color: var(--success); font-weight: 600; }
.mail-fail { color: var(--danger); font-weight: 600; }
.mail-skip { color: var(--text-muted); }

.mail-pager { display: flex; gap: 8px; align-items: center; margin-top: 14px; font-size: 13px; }
.mail-pager a {
    padding: 5px 11px; border-radius: 7px; text-decoration: none;
    background: var(--bg-secondary, var(--bg-secondary)); color: var(--text-secondary, var(--text-secondary));
}
.mail-pager a:hover { background: var(--primary, var(--primary)); color: var(--card-bg); }
</style>

<div class="mail-wrap">
    <?php if ($msg !== ''): ?>
    <div class="mail-msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="mail-badge <?= $configured ? 'on' : 'off' ?>">
        <?= $configured ? '● SMTP 已配置' : '○ SMTP 未配置' ?>
    </div>

    <!-- ============ SMTP 配置 ============ -->
    <form method="post" class="section">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="act" value="save">
        <div class="section-header"><h3>SMTP 连接参数</h3></div>
        <div class="section-body">
            <div class="mail-grid">
                <div class="adm-field">
                    <label>SMTP 服务器</label>
                    <div class="hint">QQ 邮箱填 smtp.qq.com</div>
                    <input class="adm-input" name="host" value="<?= htmlspecialchars((string)($curCfg['host'] ?? 'smtp.qq.com')) ?>">
                </div>
                <div class="adm-field">
                    <label>端口</label>
                    <div class="hint">SSL 用 465，STARTTLS 用 587</div>
                    <input class="adm-input" name="port" type="number" value="<?= (int)($curCfg['port'] ?? 465) ?>">
                </div>
            </div>

            <div class="adm-field">
                <label>发件邮箱</label>
                <div class="hint">必须是邮箱完整地址，例如 123456@qq.com</div>
                <input class="adm-input" name="user" value="<?= htmlspecialchars((string)($curCfg['user'] ?? '')) ?>">
            </div>

            <div class="adm-field">
                <label>授权码</label>
                <div class="hint">
                    不是 QQ 密码，是「QQ 邮箱 → 设置 → 账户 → POP3/SMTP 服务」里生成的授权码。
                    留空表示不修改。
                    <?php if ($configured && !empty($curCfg['pass'])): ?>
                    <br><span style="color:#178A62;font-weight:600;">
                        当前已设置：<?= str_repeat('·', 16) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <input class="adm-input" name="pass" type="password"
                       placeholder="<?= $configured && !empty($curCfg['pass']) ? '已设置 ················（留空则不修改）' : '未设置，请填写授权码' ?>"
                       autocomplete="new-password">
            </div>

            <div class="mail-grid">
                <div class="adm-field">
                    <label>发件人地址</label>
                    <div class="hint">留空则与发件邮箱相同</div>
                    <input class="adm-input" name="from" value="<?= htmlspecialchars((string)($curCfg['from'] ?? '')) ?>">
                </div>
                <div class="adm-field">
                    <label>发件人显示名</label>
                    <div class="hint">收件人看到的发信人名字</div>
                    <input class="adm-input" name="from_name" value="<?= htmlspecialchars((string)($curCfg['from_name'] ?? '')) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">保存 SMTP 配置</button>
        </div>
    </form>

    <!-- ============ 测试发送 ============ -->
    <form method="post" class="section" style="margin-top:16px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="act" value="test">
        <div class="section-header"><h3>发送测试邮件</h3></div>
        <div class="section-body">
            <div class="hint" style="margin-bottom:10px;">
                改完配置先发一封测试信。收不到就说明配置有问题，
                下面的发送记录里会写明具体原因（认证失败 / 连接超时 / 被拒收）。
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <input class="adm-input" name="test_to" style="flex:1 1 240px;"
                       placeholder="收件邮箱，例如 1740443398@qq.com">
                <button type="submit" class="btn btn-primary">发送测试</button>
            </div>
        </div>
    </form>

    <!-- ============ 邮件模板 + 群发 ============ -->
    <?php
    $templates = lwMailTemplates();
    $selKey = (string)($_GET['tpl'] ?? 'announce');
    if (!isset($templates[$selKey])) $selKey = 'announce';
    $sel = $templates[$selKey];
    ?>
    <form method="post" class="section" style="margin-top:16px;" id="broadcastForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="act" value="broadcast">
        <input type="hidden" name="tpl" value="<?= htmlspecialchars($selKey) ?>">

        <div class="section-header">
            <h3>群发邮件</h3>
            <span style="font-size:12px;color:var(--text-muted,#98A2B3);">
                仅后台可用 · 自动跳过封禁与无 QQ 号账号
            </span>
        </div>

        <div class="section-body">
            <!-- 模板选择 -->
            <div class="adm-field">
                <label>选用模板</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <?php foreach ($templates as $k => $t): ?>
                        <button type="button"
                                class="tpl-btn <?= $k === $selKey ? 'active' : '' ?>"
                                data-tpl="<?= htmlspecialchars($k) ?>"
                                data-subject="<?= htmlspecialchars((string)$t['subject']) ?>"
                                data-title="<?= htmlspecialchars((string)$t['title']) ?>"
                                data-lines="<?= htmlspecialchars((string)$t['lines']) ?>"
                                data-link="<?= htmlspecialchars((string)$t['link']) ?>"
                                data-linktext="<?= htmlspecialchars((string)$t['linkText']) ?>">
                            <?= htmlspecialchars($t['name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 收件范围 + 数量（放在最上面，因为这是最关键的决策）-->
            <div class="adm-card-body">
                <div class="bs-scope-title">发给谁</div>
                <div class="bs-scope-grid">
                    <?php
                    $scopes = [
                        'all'      => ['全部用户', $scopeCounts['all'], '所有能收信的账号'],
                        'active'   => ['最近 30 天活跃', $scopeCounts['active'], '30 天内访问过站点'],
                        'inactive' => ['沉默用户', $scopeCounts['inactive'], '超 30 天未访问或从未访问'],
                        'owner'    => ['只发给自己', $scopeCounts['owner'], '先试一封确认效果'],
                    ];
                    foreach ($scopes as $sk => $sinfo):
                    ?>
                    <label class="bs-scope-opt">
                        <input type="radio" name="scope" value="<?= $sk ?>"
                               <?= $sk === 'all' ? 'checked' : '' ?>>
                        <span class="bs-scope-name"><?= htmlspecialchars($sinfo[0]) ?></span>
                        <span class="bs-scope-count"><?= (int)$sinfo[1] ?> 人</span>
                        <span class="bs-scope-desc"><?= htmlspecialchars($sinfo[2]) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 主题 -->
            <div class="adm-field">
                <label>邮件主题</label>
                <input class="adm-input" name="subject"
                       value="<?= htmlspecialchars((string)$sel['subject']) ?>">
            </div>

            <!-- 正文标题 -->
            <div class="adm-field">
                <label>正文标题</label>
                <input class="adm-input" name="title"
                       value="<?= htmlspecialchars((string)$sel['title']) ?>">
            </div>

            <!-- 正文内容 -->
            <div class="adm-field">
                <label>正文内容</label>
                <div class="hint">每行一段，空行会被忽略</div>
                <textarea class="adm-input" name="lines" rows="5"><?= htmlspecialchars((string)$sel['lines']) ?></textarea>
            </div>

            <!-- 按钮 -->
            <div class="mail-grid">
                <div class="adm-field">
                    <label>按钮链接（可留空）</label>
                    <input class="adm-input" name="link" value="<?= htmlspecialchars((string)$sel['link']) ?>">
                </div>
                <div class="adm-field">
                    <label>按钮文字</label>
                    <input class="adm-input" name="linkText" value="<?= htmlspecialchars((string)$sel['linkText']) ?>">
                </div>
            </div>

            <!-- 批量大小 -->
            <div class="adm-field">
                <label>本批最多发送</label>
                <div class="hint">
                    QQ 邮箱对短时间大量发信有限制。建议 30 封一批，
                    发完看下方记录，再点一次继续发下一批。
                </div>
                <input class="adm-input" name="limit" type="number" value="30" min="1" max="100"
                       style="max-width:140px;">
            </div>

            <!-- 发送按钮（醒目）-->
            <div class="bs-send-bar">
                <button type="submit" class="bs-send-btn" id="bsSendBtn">
                    开始群发
                </button>
                <span class="bs-send-hint" id="bsSendHint">
                    将发送给 <b id="bsCount"><?= $scopeCounts['all'] ?></b> 人
                </span>
            </div>
        </div>
    </form>

    <style>
    .tpl-btn {
        padding: 6px 13px; border-radius: 8px; font-size: 12.5px; font-family: inherit;
        border: 1px solid transparent; cursor: pointer;
        background: var(--bg-secondary, var(--bg-secondary)); color: var(--text-secondary, var(--text-secondary));
        transition: background .15s, color .15s;
    }
    .tpl-btn:hover { background: #E8EDF5; }
    .tpl-btn.active { background: var(--primary, var(--primary)); color: var(--card-bg); }
    </style>

    <script>
    (function () {
        var form = document.getElementById('broadcastForm');
        if (!form) return;

        var counts = <?= json_encode($scopeCounts) ?>;
        var countEl = document.getElementById('bsCount');
        var btn = document.getElementById('bsSendBtn');
        var hint = document.getElementById('bsSendHint');
        var limitInput = form.querySelector('input[name="limit"]');

        function update() {
            var sel = form.querySelector('input[name="scope"]:checked');
            var scope = sel ? sel.value : 'all';
            var total = counts[scope] || 0;
            var limit = parseInt(limitInput.value, 10) || 30;
            var actual = Math.min(total, limit);

            countEl.textContent = total;
            btn.textContent = '开始群发（' + actual + ' 封）';

            if (total === 0) {
                btn.disabled = true;
                hint.innerHTML = '<span style="color:#C0392B;">该范围内没有可发送的收件人</span>';
            } else if (actual < total) {
                btn.disabled = false;
                hint.innerHTML = '将发送给 <b>' + actual + '</b> 人'
                    + '（该范围共 ' + total + ' 人，剩余 ' + (total - actual) + ' 人需再发一批）';
            } else {
                btn.disabled = false;
                hint.innerHTML = '将发送给 <b>' + total + '</b> 人';
            }
        }

        form.querySelectorAll('input[name="scope"]').forEach(function (r) {
            r.addEventListener('change', update);
        });
        limitInput.addEventListener('input', update);

        form.addEventListener('submit', function (e) {
            var sel = form.querySelector('input[name="scope"]:checked');
            var scope = sel ? sel.value : 'all';
            var total = counts[scope] || 0;
            var limit = parseInt(limitInput.value, 10) || 30;
            var actual = Math.min(total, limit);

            if (total === 0) {
                e.preventDefault();
                alert('该范围内没有可发送的收件人');
                return;
            }
            if (!confirm('确定向 ' + actual + ' 位用户群发邮件？\n\n主题：'
                    + form.querySelector('input[name="subject"]').value)) {
                e.preventDefault();
                return;
            }
            btn.disabled = true;
            btn.textContent = '正在发送，请稍候…';
        });

        // ---- 模板切换（不刷新页面）----
        var tplBtns = form.querySelectorAll('.tpl-btn');
        var subjectInput = form.querySelector('input[name="subject"]');
        var titleInput = form.querySelector('input[name="title"]');
        var linesInput = form.querySelector('textarea[name="lines"]');
        var linkInput = form.querySelector('input[name="link"]');
        var linkTextInput = form.querySelector('input[name="linkText"]');
        var tplHidden = form.querySelector('input[name="tpl"]');

        tplBtns.forEach(function (b) {
            b.addEventListener('click', function () {
                tplBtns.forEach(function (x) { x.classList.remove('active'); });
                this.classList.add('active');

                subjectInput.value = this.dataset.subject || '';
                titleInput.value = this.dataset.title || '';
                linesInput.value = this.dataset.lines || '';
                linkInput.value = this.dataset.link || '';
                linkTextInput.value = this.dataset.linktext || '';
                if (tplHidden) tplHidden.value = this.dataset.tpl || '';

                update();
            });
        });

        update();
    })();
    </script>

    <style>
    .bs-scope-opt:has(input:checked) {
        border-color: var(--primary); background: var(--info-light);
    }
    .bs-scope-opt input { margin-right: 6px; }
    .bs-send-btn:hover:not(:disabled) { background: var(--primary-hover); }
    .bs-send-btn:active:not(:disabled) { transform: scale(.98); }
    .bs-send-hint b { font-size: 15px; }
    </style>

    <!-- ============ 发送记录 ============ -->
    <div class="section" style="margin-top:16px;">
        <div class="section-header">
            <h3>发送记录</h3>
            <?php if ($stats['total'] > 0): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('确定清空全部发送记录？');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="act" value="clear">
                <button type="submit" class="btn btn-sm">清空记录</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="section-body">
            <div class="mail-stats">
                <div class="mail-stat"><b><?= $stats['total'] ?></b><span>总计</span></div>
                <div class="mail-stat"><b style="color:#178A62;"><?= $stats['ok'] ?></b><span>成功</span></div>
                <div class="mail-stat"><b style="color:#C0392B;"><?= $stats['fail'] ?></b><span>失败</span></div>
                <div class="mail-stat"><b style="color:#98A2B3;"><?= $stats['skip'] ?></b><span>已跳过</span></div>
            </div>

            <?php if ($byType): ?>
            <div style="margin-bottom:16px;font-size:12.5px;color:var(--text-muted,#98A2B3);line-height:1.9;">
                按类型：
                <?php foreach ($byType as $t => $c): ?>
                    <span style="display:inline-block;margin-right:12px;">
                        <?= htmlspecialchars($types[$t] ?? $t) ?> <b style="color:var(--text-primary,#1F2733);"><?= $c ?></b>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!$rows): ?>
                <div style="padding:32px 0;text-align:center;color:var(--text-muted,#98A2B3);font-size:13px;">
                    还没有发送记录。发一封测试邮件试试。
                </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>收件人</th>
                            <th>类型</th>
                            <th>主题</th>
                            <th>结果</th>
                            <th>说明</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $ok = !empty($r['ok']);
                        $skip = !empty($r['skipped']);
                    ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= date('m-d H:i', (int)($r['ts'] ?? 0)) ?></td>
                            <td><?= htmlspecialchars((string)($r['to'] ?? '')) ?></td>
                            <td style="white-space:nowrap;"><?= htmlspecialchars($types[(string)($r['type'] ?? '')] ?? (string)($r['type'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($r['subject'] ?? '—')) ?></td>
                            <td style="white-space:nowrap;">
                                <?php if ($skip): ?><span class="mail-skip">跳过</span>
                                <?php elseif ($ok): ?><span class="mail-ok">成功</span>
                                <?php else: ?><span class="mail-fail">失败</span><?php endif; ?>
                            </td>
                            <td style="font-size:12.5px;color:var(--text-secondary,#5A6472);">
                                <?= htmlspecialchars((string)($r['reason'] ?? '')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($maxPage > 1): ?>
            <div class="mail-pager">
                <?php if ($pageNo > 1): ?>
                    <a href="?p=<?= $pageNo - 1 ?>">上一页</a>
                <?php endif; ?>
                <span>第 <?= $pageNo ?> / <?= $maxPage ?> 页（共 <?= $totalRows ?> 条）</span>
                <?php if ($pageNo < $maxPage): ?>
                    <a href="?p=<?= $pageNo + 1 ?>">下一页</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
