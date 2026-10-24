<?php
/**
 * 邮件模板库 —— 站内所有「发给用户」的邮件唯一真源。
 * ---------------------------------------------------------------------------
 * 为什么要有这一层：
 *   原先 5 处发信各自在业务代码里拼 HTML（找回密码 / 备份验证码 / 注册欢迎 /
 *   改绑QQ通知 / 风控通知），版式、配色、字号各写各的，改一处就漏一处；
 *   后台想做「测试邮件」也只能测一种写死的内容。
 *   现在统一成注册表：新增邮件只需在这里加一项，真实发送与后台测试同时获得它。
 *
 * 约定：
 *   1) 一律浅色版式（白卡片 + 浅灰底 + 金褐强调），邮件客户端兼容性最好；
 *      全部内联样式 + table 布局，不依赖 <style> 与 class。
 *   2) `lwMailBuild($key, $ctx)` 返回 ['subject' => ..., 'html' => ...]，
 *      测试与真实发送调用的是同一个函数、同一份模板 —— 测试看到的就是用户收到的。
 *   3) 测试时传入 `$ctx['__test'] = true`，只在卡片顶部加一条细提示条，
 *      其余内容与真实邮件逐字一致。
 */

if (!function_exists('lwMailTypes')) {
    /**
     * 邮件类型注册表。
     * name    后台下拉里显示的名字
     * scene   触发场景（写清楚"什么时候会发给用户"，避免站长误以为是死模板）
     * sensitive 是否含验证码/链接等敏感内容（仅用于后台提示，不影响发送）
     */
    function lwMailTypes(): array
    {
        return [
            'verify_reset' => [
                'name' => '密码重置验证码',
                'scene' => '用户点「忘记密码」时发送，含重置链接与验证码',
                'sensitive' => true,
            ],
            'verify_backup' => [
                'name' => '数据下载验证码',
                'scene' => '用户申请下载「我的数据备份」时发送',
                'sensitive' => true,
            ],
            'welcome' => [
                'name' => '注册欢迎邮件',
                'scene' => '新用户注册成功后发送',
                'sensitive' => false,
            ],
            'password_changed' => [
                'name' => '密码修改成功提醒',
                'scene' => '用户修改密码成功后发送（安全提醒）',
                'sensitive' => false,
            ],
            'twofa_changed' => [
                'name' => '两步验证变更提醒',
                'scene' => '用户开启或关闭两步验证后发送',
                'sensitive' => false,
            ],
            'qq_changed' => [
                'name' => '绑定 QQ 变更通知',
                'scene' => '管理员在后台改绑 QQ 时，发给原 QQ 邮箱',
                'sensitive' => false,
            ],
            'ban_notice' => [
                'name' => '访问受限通知',
                'scene' => '登录用户的网络被风控临时拦截时发送',
                'sensitive' => false,
            ],
            'test_basic' => [
                'name' => '基础连通性测试',
                'scene' => '只验证 SMTP 通道是否可用，不含业务内容',
                'sensitive' => false,
            ],
        ];
    }
}

if (!function_exists('lwMailShell')) {
    /** 浅色外壳：外层浅灰底 + 白色卡片 + 顶部小标题 + 正文 */
    function lwMailShell(string $kicker, string $title, string $bodyHtml, bool $isTest = false): string
    {
        $testStrip = $isTest
            ? '<div style="margin:0 0 16px;padding:9px 12px;background:#fff8e6;border:1px solid #f0e2bd;'
              . 'border-radius:8px;font-size:12.5px;color:#8a6a24">'
              . '这是一封<b>测试邮件</b>，用于验证邮件模板与 SMTP 通道是否正常；'
              . '下面的内容与用户实际收到的一致。</div>'
            : '';
        return '<div style="margin:0;padding:26px 16px;background:#f4f6fa">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e6eaf2;'
            . 'border-radius:14px;padding:26px 24px;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Microsoft YaHei\',sans-serif">'
            . ($kicker !== ''
                ? '<p style="margin:0 0 8px;font-family:Consolas,Menlo,monospace;font-size:11px;'
                  . 'letter-spacing:1.6px;text-transform:uppercase;color:#9a7b32">' . $kicker . '</p>'
                : '')
            . '<h2 style="margin:0 0 14px;font-size:19px;color:#1d2735;letter-spacing:.3px">' . $title . '</h2>'
            . $testStrip
            . $bodyHtml
            . '</div></div>';
    }
}

if (!function_exists('lwMailCodeBox')) {
    /** 验证码展示块：大号等宽数字 + 说明 */
    function lwMailCodeBox(string $code, string $unit = ''): string
    {
        return '<p style="margin:0 0 16px;text-align:center;background:#faf5e8;border:1px dashed #e8dcc0;'
            . 'border-radius:10px;padding:14px">'
            . '<span style="font-family:Consolas,Menlo,monospace;font-size:30px;letter-spacing:6px;'
            . 'font-weight:700;color:#8a6a24">' . $code . '</span>'
            . ($unit !== ''
                ? '<br><span style="font-size:12.5px;color:#7c8a9c">' . $unit . '</span>'
                : '')
            . '</p>';
    }
}

if (!function_exists('lwMailRow')) {
    /** 信息行（label + value），用于变更类通知 */
    function lwMailRow(string $label, string $value, bool $first = false): string
    {
        $b = $first ? '' : 'border-top:1px dashed #eef2f8;';
        return '<tr>'
            . '<td style="padding:9px 0;width:104px;vertical-align:top;font-size:13px;color:#7c8a9c;' . $b . '">' . $label . '</td>'
            . '<td style="padding:9px 0;vertical-align:top;font-size:13.5px;color:#1d2735;line-height:1.7;' . $b . '">' . $value . '</td>'
            . '</tr>';
    }
}

if (!function_exists('lwMailNote')) {
    /** 段落 */
    function lwMailNote(string $html, string $color = '#5b6879', string $size = '13.5px'): string
    {
        return '<p style="margin:0 0 12px;font-size:' . $size . ';line-height:1.85;color:' . $color . '">' . $html . '</p>';
    }
}

if (!function_exists('lwMailSendToUser')) {
    /**
     * 给指定用户发一封模板邮件。
     *
     * 收件地址按本站既有约定推导：`QQ号@qq.com`（users 表没有 email 字段，
     * 找回密码 / 备份验证码 / 换绑通知都用的这个约定）。
     * 失败只写 error_log、不抛异常 —— 安全提醒类邮件不应该因为 SMTP 不通而打断主业务。
     *
     * @param array  $user 用户行（需含 qq / nickname）
     * @param string $type lwMailTypes() 里的 key
     * @param array  $ctx  模板上下文（nick 会自动补全）
     * @return bool 是否发送成功
     */
    function lwMailSendToUser(array $user, string $type, array $ctx = []): bool
    {
        try {
            if (!class_exists('QQMailer') || !QQMailer::isConfigured()) {
                return false;
            }
            $qq = preg_replace('/\D/', '', (string)($user['qq'] ?? ''));
            if ($qq === '') {
                return false;
            }
            if (!isset($ctx['nick'])) {
                $ctx['nick'] = (string)($user['nickname'] ?? '同学');
            }
            $mail = lwMailBuild($type, $ctx);
            $ok = QQMailer::send($qq . '@qq.com', (string)$mail['subject'], (string)$mail['html']);
            if (!$ok) {
                error_log('lwMailSendToUser: 发送失败 type=' . $type . ' to=' . $qq . '@qq.com');
            }
            return (bool)$ok;
        } catch (\Throwable $e) {
            error_log('lwMailSendToUser failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lwMailSampleCtx')) {
    /**
     * 测试用的示例上下文：让后台点一下就能看到真实观感，不必手填内容。
     * 字段与真实调用一一对应。
     */
    function lwMailSampleCtx(string $key): array
    {
        $siteName = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://hnbsd.ct.ws';
        $nick = '同学';
        switch ($key) {
            case 'verify_reset':
                return [
                    'qq' => '123456789',
                    'code' => 'A7K2D9',
                    'reset_url' => $siteUrl . '/pages/forgot_password.php?token=SAMPLE-TOKEN-9f3c2a7b',
                    '__test' => true,
                ];
            case 'verify_backup':
                return ['code' => '483920', '__test' => true];
            case 'welcome':
                return ['nick' => $nick, 'qq' => '123456789', '__test' => true];
            case 'password_changed':
                return ['nick' => $nick, '__test' => true];
            case 'twofa_changed':
                return ['nick' => $nick, 'action' => '关闭', '__test' => true];
            case 'qq_changed':
                return ['nick' => $nick, 'old_qq' => '123456789', 'new_qq' => '987654321', '__test' => true];
            case 'ban_notice':
                return [
                    'ip' => '203.0.113.9',
                    'reason' => 'content_scraping',
                    'expire_at' => time() + 1500,
                    'nick' => $nick,
                    '__test' => true,
                ];
            case 'test_basic':
            default:
                return ['sent_at' => date('Y-m-d H:i:s'), '__test' => true];
        }
    }
}

if (!function_exists('lwMailBuild')) {
    /**
     * 生成邮件：返回 ['subject' => 主题, 'html' => 正文]。
     * 未知 key 会优雅退化为基础测试邮件，不抛异常、不影响调用方。
     */
    function lwMailBuild(string $key, array $ctx = []): array
    {
        $siteName = defined('SITE_NAME') ? SITE_NAME : '校园交流墙';
        $siteUrl  = defined('SITE_URL') ? SITE_URL : 'https://hnbsd.ct.ws';
        $isTest   = !empty($ctx['__test']);
        $esc      = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $nick     = $esc($ctx['nick'] ?? '同学');

        switch ($key) {
            case 'verify_reset':
                $qq  = $ctx['qq'] ?? '';
                $code = $esc($ctx['code'] ?? '');
                $url = $esc($ctx['reset_url'] ?? $siteUrl . '/pages/forgot_password.php');
                $body = lwMailNote('您好：')
                    . lwMailNote('您正在为账号' . ($qq !== '' ? '（QQ：' . $esc($qq) . '）' : '') . '申请重置密码。'
                        . '点击下面的按钮或链接前往重置页面：')
                    . '<p style="margin:0 0 16px"><a href="' . $url . '" style="display:inline-block;'
                    . 'padding:11px 22px;background:#2F5B9A;color:#ffffff;border-radius:999px;'
                    . 'font-size:13.5px;font-weight:700;text-decoration:none">前往重置密码</a></p>'
                    . '<p style="margin:0 0 16px;font-size:12.5px;line-height:1.8;color:#7c8a9c;'
                    . 'word-break:break-all">链接打不开时，请手动复制：<br>' . $url . '</p>'
                    . lwMailCodeBox($code, '如无法点击链接，可在重置页手动填写此验证码')
                    . lwMailNote('该验证码 <b>5 分钟内有效</b>，重置链接 <b>1 小时内有效</b>，请尽快完成设置。')
                    . lwMailNote('若非您本人操作，请忽略此邮件，您的账号不会受到任何影响。', '#7c8a9c', '12.5px');
                return [
                    'subject' => '【' . $siteName . '】密码重置验证码',
                    'html' => lwMailShell('Account · Password Reset', '密码重置验证码', $body, $isTest),
                ];

            case 'verify_backup':
                $code = $esc($ctx['code'] ?? '');
                $body = lwMailNote('您好：')
                    . lwMailNote('您正在申请下载本站的「我的数据备份（ZIP）」。该备份仅包含您本人的数据，'
                        . '为验证身份，请在下载页输入以下验证码：')
                    . lwMailCodeBox($code, '10 分钟内有效')
                    . lwMailNote('若您未申请过下载，请忽略此邮件。', '#7c8a9c', '12.5px');
                return [
                    'subject' => '【' . $siteName . '】数据下载验证码',
                    'html' => lwMailShell('Data Export · Verification', '数据下载验证码', $body, $isTest),
                ];

            case 'welcome':
                // 这封邮件承担「防钓鱼说明」的职责：新用户看到免费主机域名容易怀疑是诈骗站，
                // 所以把「非官方 / 免费主机 / 完全开源可审计 / 绝不索要密码 / 站长担保」讲清楚。
                // 站长担保里的后台数据片段由调用方以 dev_snippet_html 传入（测试时不传，展示简版）。
                $qq      = (string)($ctx['qq'] ?? '');
                $repoUrl = defined('GITHUB_REPO_URL') ? GITHUB_REPO_URL : $siteUrl;
                $repoName = defined('GITHUB_REPO_NAME') ? GITHUB_REPO_NAME : 'GitHub 仓库';
                $devSnippetHtml = (string)($ctx['dev_snippet_html'] ?? '');
                $devName = trim((string)($ctx['dev_name'] ?? ''));
                $devWho = $devName !== ''
                    ? '我是本站站长 <b>' . $esc($devName) . '</b>（QQ ' . (defined('LW_BAN_CONTACT_QQ') ? LW_BAN_CONTACT_QQ : '1740443398') . '），'
                    : '本站站长';
                $devBlock = $devSnippetHtml !== ''
                    ? '<li style="margin-bottom:8px"><b>站长担保：</b>' . $devWho . '本人实名注册并使用本站。'
                      . '为防止「盗号 / 诈骗」的疑虑，下面是在后台数据库里的<b>完整账号数据（真实存储样式，敏感字段已打码）</b>，'
                      . '可供懂编程的同学核对存储格式：'
                      . '<pre style="background:#f6f8fa;border:1px solid #e2e8f0;border-radius:6px;padding:10px;'
                      . 'font-size:11px;line-height:1.5;max-height:260px;overflow:auto;margin:8px 0">'
                      . $devSnippetHtml . '</pre>'
                      . '任何关于密码或账号的可疑情况，都可随时找站长本人核实。</li>'
                    : '<li style="margin-bottom:8px"><b>站长担保：</b>本站站长本人实名注册并使用本站，'
                      . '遇到任何密码或账号的可疑情况，都可联系站长核实（QQ ' . (defined('LW_BAN_CONTACT_QQ') ? LW_BAN_CONTACT_QQ : '1740443398') . '）。</li>';
                $body = lwMailNote('您好，' . $nick . ($qq !== '' ? '（QQ：' . $esc($qq) . '）' : '') . '：')
                    . lwMailNote('欢迎加入「' . $esc($siteName) . '」！')
                    . lwMailNote('在开始之前，有几件事想郑重说明，请务必留意：')
                    . '<p style="margin:0 0 14px;padding:12px 14px;background:#fdf2f3;border:1px solid #f6d9dd;'
                    . 'border-radius:10px;font-size:13.5px;line-height:1.85;color:#8a4a52">'
                    . '<b>这不是盗号或诈骗网站。</b>本平台是学生自发搭建的校园交流平台（<b>非官方</b>）。'
                    . '网站托管在免费主机服务商提供的空间上，因此网址看起来可能不像学校官方域名，属正常现象。</p>'
                    . '<ul style="margin:0 0 14px;padding-left:20px;font-size:13.5px;line-height:1.85;color:#5b6879">'
                    . '<li style="margin-bottom:8px"><b>完全开源：</b>本站全部代码公开可审计 —— '
                    . '<a href="' . $esc($repoUrl) . '" style="color:#2F5B9A;text-decoration:none">' . $esc($repoName) . '</a>。'
                    . '你（或任何懂技术的同学）都可以亲自核对代码，确认它是否安全。</li>'
                    . $devBlock
                    . '<li style="margin-bottom:8px"><b>安全感：</b>本站<b>绝不会索取</b>你的 QQ 登录密码、短信验证码或邮箱验证码。'
                    . '也请切勿向任何个人或所谓「客服」透露自己的密码。</li>'
                    . '</ul>'
                    . '<p style="margin:0 0 16px"><a href="' . $esc($siteUrl) . '" style="display:inline-block;'
                    . 'padding:11px 22px;background:#2F5B9A;color:#ffffff;border-radius:999px;'
                    . 'font-size:13.5px;font-weight:700;text-decoration:none">进入校园交流墙</a></p>'
                    . '<table style="width:100%;border-collapse:collapse;margin:4px 0 16px">'
                    . lwMailRow('签到打卡', '每天点一下，连续签到有额外经验', true)
                    . lwMailRow('发动态', '支持匿名、图片与投票，实名匿名随心切换')
                    . lwMailRow('成长体系', '积累经验升级、解锁成就与头衔')
                    . lwMailRow('个人数据', '随时可导出自己的全部数据')
                    . '</table>'
                    . lwMailNote('发帖前请先看一眼社区规范：文明交流，不发布辱骂、引战与泄露他人隐私的内容。',
                        '#7c8a9c', '12.5px');
                return [
                    'subject' => '欢迎加入' . $siteName,
                    'html' => lwMailShell('Welcome Aboard', '欢迎加入「' . $esc($siteName) . '」', $body, $isTest),
                ];

            case 'password_changed':
                $body = lwMailNote('您好，' . $nick . '：')
                    . lwMailNote('您的账号密码已于 <b style="color:#1d2735">' . date('Y-m-d H:i:s')
                        . '</b> 修改成功。为保证安全，其他设备上的登录状态已全部失效，'
                        . '请使用新密码重新登录（若你使用了「记住登录」，需要重新输入一次）。')
                    . '<p style="margin:0 0 16px;padding:11px 14px;background:#fff8e6;border:1px solid #f0e2bd;'
                    . 'border-radius:10px;font-size:13px;line-height:1.8;color:#8a6a24">'
                    . '<b>如果这不是你本人操作</b>，说明密码可能已泄露：请立即用当前密码重新修改一次，'
                    . '并联系站长处理。</p>'
                    . lwMailNote('本邮件由系统在密码变更后自动发送，无需回复。', '#7c8a9c', '12.5px');
                return [
                    'subject' => '【' . $siteName . '】密码已修改',
                    'html' => lwMailShell('Security · Password Changed', '密码已修改', $body, $isTest),
                ];

            case 'twofa_changed':
                $action = ($ctx['action'] ?? '变更') === '开启' ? '开启' : '关闭';
                $body = lwMailNote('您好，' . $nick . '：')
                    . lwMailNote('你的账号两步验证（2FA）已于 <b style="color:#1d2735">' . date('Y-m-d H:i:s')
                        . '</b> <b style="color:#1d2735">' . $esc($action) . '</b>。')
                    . '<table style="width:100%;border-collapse:collapse;margin:4px 0 16px">'
                    . lwMailRow('操作类型', $esc($action) . '两步验证', true)
                    . lwMailRow('影响', $action === '开启' ? '下次登录需额外输入验证器 6 位动态码' : '登录只需密码，安全性降低')
                    . '</table>'
                    . '<p style="margin:0 0 16px;padding:11px 14px;background:#fff8e6;border:1px solid #f0e2bd;'
                    . 'border-radius:10px;font-size:13px;line-height:1.8;color:#8a6a24">'
                    . '若不是你本人操作，请立即修改密码并联系站长，谨防账号被他人接管。</p>';
                return [
                    'subject' => '【' . $siteName . '】两步验证已' . $action,
                    'html' => lwMailShell('Security · Two-Factor', '两步验证已' . $action, $body, $isTest),
                ];

            case 'qq_changed':
                $body = lwMailNote('您好，' . $nick . '：')
                    . lwMailNote('你的账号绑定 QQ 已由管理端变更，详情如下：')
                    . '<table style="width:100%;border-collapse:collapse;margin:4px 0 16px">'
                    . lwMailRow('原绑定 QQ', '<span style="font-family:Consolas,Menlo,monospace">' . $esc($ctx['old_qq'] ?? '') . '</span>', true)
                    . lwMailRow('新绑定 QQ', '<span style="font-family:Consolas,Menlo,monospace;font-weight:700">' . $esc($ctx['new_qq'] ?? '') . '</span>')
                    . '</table>'
                    . '<p style="margin:0 0 16px;padding:11px 14px;background:#fff8e6;border:1px solid #f0e2bd;'
                    . 'border-radius:10px;font-size:13px;line-height:1.8;color:#8a6a24">'
                    . '<b>如非本人申请</b>，请立即联系站长核实处理；换绑后原登录状态已失效，需用新的 QQ 号重新登录。</p>';
                return [
                    'subject' => '【' . $siteName . '】账号绑定 QQ 变更通知',
                    'html' => lwMailShell('Account · QQ Binding Changed', '账号绑定 QQ 已变更', $body, $isTest),
                ];

            case 'ban_notice':
                // 与封禁页共用同一套策略文案，避免两处口径不一致
                require_once __DIR__ . '/ban_page.php';
                $ip = (string)($ctx['ip'] ?? '');
                $reason = (string)($ctx['reason'] ?? '');
                $expireAt = (int)($ctx['expire_at'] ?? 0);
                $permanent = $expireAt <= 0;
                $code = lwBanReasonCode($reason);
                $expireTxt = $permanent
                    ? '需站长人工解除'
                    : date('Y-m-d H:i:s', $expireAt) . '（' . date('P', $expireAt) . '）';
                $remainTxt = $permanent ? '不确定' : lwBanFormatDuration(max(0, $expireAt - time()));
                $refId = lwBanRefId($ip, $expireAt);
                $body = lwMailNote($nick . '，你好：')
                    . lwMailNote('系统检测到来自你当前网络的访问命中了站点的自动防护策略，已对该地址实施'
                        . '<b style="color:#1d2735">' . ($permanent ? '限制' : '临时拦截') . '</b>。')
                    . lwMailNote('这不是账号封禁 —— 你的账号、动态与数据均完好无损。', '#8a6a24')
                    . '<table style="width:100%;border-collapse:collapse;margin:4px 0 16px">'
                    . lwMailRow('命中策略', '<span style="display:inline-block;font-family:Consolas,Menlo,monospace;'
                        . 'font-size:12px;font-weight:700;color:#8a6a24;background:#faf5e8;border:1px solid #e8dcc0;'
                        . 'border-radius:6px;padding:2px 8px;margin-bottom:6px">' . $esc($code) . '</span><br>'
                        . $esc(lwBanReasonText($reason))
                        . '<br><span style="color:#7c8a9c;font-size:12.5px">通俗说明：' . $esc(lwBanReasonHint($reason)) . '</span>', true)
                    . lwMailRow('受限网络', '<span style="font-family:Consolas,Menlo,monospace">' . $esc($ip) . '</span>')
                    . lwMailRow('解封时间', '<span style="color:#8a6a24;font-weight:700">' . $esc($expireTxt) . '</span>')
                    . lwMailRow('剩余时长', '约 ' . $esc($remainTxt))
                    . lwMailRow('事件编号', '<span style="font-family:Consolas,Menlo,monospace">' . $esc($refId) . '</span>')
                    . '</table>'
                    . lwMailNote($permanent
                        ? '该限制需要站长人工解除，请通过下方方式联系站长。'
                        : '无需任何操作，到达解封时间后自动恢复，届时刷新页面即可继续使用。')
                    . lwMailNote('认为属于误判（例如校园网 / 运营商共享出口，或短时间内自己连续翻看了几页）？'
                        . '请联系站长 <b style="color:#8a6a24">QQ ' . (defined('LW_BAN_CONTACT_QQ') ? LW_BAN_CONTACT_QQ : '1740443398')
                        . '</b>，并附上上面的「事件编号」与「受限网络」，站长可据此快速定位并处理。');
                return [
                    'subject' => '【' . $siteName . '】访问已被临时限制（' . $remainTxt . '后自动恢复）',
                    'html' => lwMailShell('Security Gate · Access Restricted', '访问已被临时限制', $body, $isTest),
                ];

            case 'test_basic':
            default:
                $body = lwMailNote('这是一封来自「' . $esc($siteName) . '」后台的邮件服务测试。')
                    . lwMailNote('如果你收到了这封邮件，说明站点的 SMTP 邮件发送通道工作正常。')
                    . '<table style="width:100%;border-collapse:collapse;margin:4px 0 16px">'
                    . lwMailRow('发送时间', $esc($ctx['sent_at'] ?? date('Y-m-d H:i:s')), true)
                    . lwMailRow('站点地址', '<a href="' . $esc($siteUrl) . '" style="color:#2F5B9A;text-decoration:none">' . $esc($siteUrl) . '</a>')
                    . '</table>';
                return [
                    'subject' => '【' . $siteName . '】邮件发送测试',
                    'html' => lwMailShell('System · SMTP Test', '邮件服务测试', $body, $isTest),
                ];
        }
    }
}
