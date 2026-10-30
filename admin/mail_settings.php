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

            // 按范围挑收件人
            $fs2 = getFS();
            $allUsers = $fs2->read('users');
            $ids = [];
            $cut30 = time() - 30 * 86400;

            foreach ($allUsers as $u) {
                $uid = (int)($u['id'] ?? 0);
                if ($uid <= 0) continue;
                if (!empty($u['is_banned'])) continue;
                if (lwMailAddressOf($u) === '') continue;

                if ($scope === 'all') {
                    $ids[] = $uid;
                } else {
                    $last = (string)($u['last_login_at'] ?? $u['last_active_at'] ?? '');
                    $ts = $last !== '' ? (strtotime($last) ?: 0) : 0;
                    if ($scope === 'active' && $ts >= $cut30) $ids[] = $uid;
                    if ($scope === 'inactive' && $ts < $cut30) $ids[] = $uid;
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
.mail-msg.ok  { background: #EAF7F1; color: #178A62; border: 1px solid #C5E9D8; }
.mail-msg.err { background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; }

.mail-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 600;
    margin-bottom: 16px;
}
.mail-badge.on  { background: #EAF7F1; color: #178A62; }
.mail-badge.off { background: #FEF3E7; color: #B4761F; }

.mail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 700px) { .mail-grid { grid-template-columns: 1fr; } }

.mail-field { margin-bottom: 14px; }
.mail-field label {
    display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;
    color: var(--text-primary, #1F2733);
}
.mail-field .hint { font-size: 12px; color: var(--text-muted, #98A2B3); margin-bottom: 6px; line-height: 1.6; }
.mail-input {
    width: 100%; padding: 9px 12px; font-size: 13.5px; font-family: inherit;
    border: 1px solid var(--border-color, #E4E9F0); border-radius: 9px;
    background: var(--bg-input, #fff); color: var(--text-primary, #1F2733); outline: none;
}
.mail-input:focus { border-color: var(--primary, #2F5B9A); }

.mail-stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.mail-stat {
    flex: 1 1 110px; min-width: 110px; padding: 14px 16px;
    background: var(--bg-secondary, #F7F9FC); border-radius: 11px;
    border: 1px solid var(--border-light, #EEF2F7);
}
.mail-stat b { display: block; font-size: 19px; font-weight: 700; font-variant-numeric: tabular-nums; }
.mail-stat span { font-size: 12px; color: var(--text-muted, #98A2B3); }

.mail-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mail-table th {
    text-align: left; padding: 9px 10px; font-size: 12px; font-weight: 600;
    color: var(--text-muted, #98A2B3); border-bottom: 1px solid var(--border-color, #E4E9F0);
    white-space: nowrap;
}
.mail-table td {
    padding: 10px; border-bottom: 1px solid var(--border-light, #F3F6FA);
    vertical-align: top; word-break: break-all;
}
.mail-ok   { color: #178A62; font-weight: 600; }
.mail-fail { color: #C0392B; font-weight: 600; }
.mail-skip { color: #98A2B3; }

.mail-pager { display: flex; gap: 8px; align-items: center; margin-top: 14px; font-size: 13px; }
.mail-pager a {
    padding: 5px 11px; border-radius: 7px; text-decoration: none;
    background: var(--bg-secondary, #F3F6FA); color: var(--text-secondary, #5A6472);
}
.mail-pager a:hover { background: var(--primary, #2F5B9A); color: #fff; }
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
                <div class="mail-field">
                    <label>SMTP 服务器</label>
                    <div class="hint">QQ 邮箱填 smtp.qq.com</div>
                    <input class="mail-input" name="host" value="<?= htmlspecialchars((string)($curCfg['host'] ?? 'smtp.qq.com')) ?>">
                </div>
                <div class="mail-field">
                    <label>端口</label>
                    <div class="hint">SSL 用 465，STARTTLS 用 587</div>
                    <input class="mail-input" name="port" type="number" value="<?= (int)($curCfg['port'] ?? 465) ?>">
                </div>
            </div>

            <div class="mail-field">
                <label>发件邮箱</label>
                <div class="hint">必须是邮箱完整地址，例如 123456@qq.com</div>
                <input class="mail-input" name="user" value="<?= htmlspecialchars((string)($curCfg['user'] ?? '')) ?>">
            </div>

            <div class="mail-field">
                <label>授权码</label>
                <div class="hint">
                    不是 QQ 密码，是「QQ 邮箱 → 设置 → 账户 → POP3/SMTP 服务」里生成的授权码。
                    留空表示不修改。
                </div>
                <input class="mail-input" name="pass" type="password" placeholder="留空则不修改">
            </div>

            <div class="mail-grid">
                <div class="mail-field">
                    <label>发件人地址</label>
                    <div class="hint">留空则与发件邮箱相同</div>
                    <input class="mail-input" name="from" value="<?= htmlspecialchars((string)($curCfg['from'] ?? '')) ?>">
                </div>
                <div class="mail-field">
                    <label>发件人显示名</label>
                    <div class="hint">收件人看到的发信人名字</div>
                    <input class="mail-input" name="from_name" value="<?= htmlspecialchars((string)($curCfg['from_name'] ?? '')) ?>">
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
                <input class="mail-input" name="test_to" style="flex:1 1 240px;"
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
    <form method="post" class="section" style="margin-top:16px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="act" value="broadcast">
        <input type="hidden" name="tpl" value="<?= htmlspecialchars($selKey) ?>">
        <div class="section-header"><h3>群发邮件</h3></div>
        <div class="section-body">
            <div class="hint" style="margin-bottom:12px;">
                仅后台可用，普通用户没有入口。发信按 QQ 号推导的邮箱，
                自动跳过被封禁与没有 QQ 号的账号。每封之间有间隔，避免被 QQ 邮箱限流。
            </div>

            <div class="mail-field">
                <label>选用模板</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                    <?php foreach ($templates as $k => $t): ?>
                        <a href="?tpl=<?= urlencode($k) ?>"
                           style="padding:6px 13px;border-radius:8px;font-size:12.5px;text-decoration:none;
                                  <?= $k === $selKey
                                      ? 'background:var(--primary,#2F5B9A);color:#fff;'
                                      : 'background:var(--bg-secondary,#F3F6FA);color:var(--text-secondary,#5A6472);' ?>">
                            <?= htmlspecialchars($t['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mail-field">
                <label>主题</label>
                <input class="mail-input" name="subject"
                       value="<?= htmlspecialchars((string)$sel['subject']) ?>">
            </div>

            <div class="mail-field">
                <label>标题</label>
                <input class="mail-input" name="title"
                       value="<?= htmlspecialchars((string)$sel['title']) ?>">
            </div>

            <div class="mail-field">
                <label>正文</label>
                <div class="hint">每行一段，空行会被忽略</div>
                <textarea class="mail-input" name="lines" rows="5"><?= htmlspecialchars((string)$sel['lines']) ?></textarea>
            </div>

            <div class="mail-grid">
                <div class="mail-field">
                    <label>按钮链接</label>
                    <input class="mail-input" name="link" value="<?= htmlspecialchars((string)$sel['link']) ?>">
                </div>
                <div class="mail-field">
                    <label>按钮文字</label>
                    <input class="mail-input" name="linkText" value="<?= htmlspecialchars((string)$sel['linkText']) ?>">
                </div>
            </div>

            <div class="mail-grid">
                <div class="mail-field">
                    <label>收件人范围</label>
                    <select class="mail-input" name="scope">
                        <option value="all">全部用户（<?= count($fs->read('users')) ?> 人）</option>
                        <option value="active">最近 30 天活跃</option>
                        <option value="inactive">超过 30 天未登录</option>
                    </select>
                </div>
                <div class="mail-field">
                    <label>本批最多发送</label>
                    <div class="hint">建议 30 封一批，多批发送</div>
                    <input class="mail-input" name="limit" type="number" value="30" min="1" max="100">
                </div>
            </div>

            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('确定按当前设置群发？');">开始群发</button>
            <span style="font-size:12px;color:var(--text-muted,#98A2B3);margin-left:10px;">
                发完请看下方记录
            </span>
        </div>
    </form>

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
                <table class="mail-table">
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
