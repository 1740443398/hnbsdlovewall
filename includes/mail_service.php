<?php
/**
 * 站内邮件通知服务
 *
 * ==========================================================================
 * 这个文件解决什么问题
 * ==========================================================================
 * includes/qqmail.php 是「能发信的底层」：给收件人、标题、正文就发出去。
 * 但它不知道：谁该收到什么信、发过什么、成没成功、用户想不想收。
 *
 * 这些都在这一层解决：统一入口、写发送记录、限频、按偏好过滤。
 *
 * ==========================================================================
 * 三条设计原则
 * ==========================================================================
 * 1. 邮件永远不能影响主流程
 *    发信失败只记日志，绝不抛异常、绝不阻断发帖/注册。
 *
 * 2. 邮箱是可选的
 *    没填邮箱就静默跳过，不报错也不写记录。
 *
 * 3. 每一封都留痕
 *    落到 data/mail_log.json，后台「邮件设置」页能看到发给谁、
 *    什么类型、成功与否、失败原因（SMTP 认证失败 / 连接超时 / 被限频）。
 *    这是排查「用户说没收到」的唯一依据。
 * ==========================================================================
 */

if (!defined('LW_MAIL_SERVICE_LOADED')) {
    define('LW_MAIL_SERVICE_LOADED', 1);

    /** 邮件类型 -> 中文名 */
    function lwMailTypes(): array
    {
        return [
            'register'      => '注册欢迎',
            'invite'        => '邀请注册',
            'comment'       => '收到评论',
            'reply'         => '收到回复',
            'like'          => '被点赞',
            'follow'        => '被关注',
            'mention'       => '被提及',
            'pm'            => '收到私信',
            'post_approved' => '帖子过审',
            'post_rejected' => '帖子未过审',
            'ban'           => '账号封禁',
            'unban'         => '账号解封',
            'login_alert'   => '异地登录提醒',
            'password'      => '密码变更',
            'points'        => '积分变动',
            'system'        => '系统通知',
            'test'          => '测试邮件',
        ];
    }

    /**
     * 推导用户的收信地址。
     *
     * =====================================================================
     * 站点的约定：用户邮箱默认就是「QQ号@qq.com」
     * =====================================================================
     * 本站注册必须填 QQ 号，所以每个人都有 QQQQQQ@qq.com 这个地址，
     * 不需要再让用户单独填一遍邮箱 —— 少一个字段，注册快一步。
     *
     * 如果用户后来在个人中心主动填了别的邮箱，就用他自己填的。
     * 顺序：自定义 email > QQ号@qq.com > 空（不发）
     * =====================================================================
     */
    function lwMailAddressOf(array $user): string
    {
        // 1. 用户主动填的邮箱优先
        $custom = trim((string)($user['email'] ?? ''));
        if ($custom !== '' && strpos($custom, '@') !== false) {
            return $custom;
        }

        // 2. 用 QQ 号推导
        $qq = trim((string)($user['qq'] ?? ''));
        // QQ 号是 5~12 位纯数字；不是这个形状就不猜（避免发到不存在的地址）
        if ($qq !== '' && preg_match('/^[1-9][0-9]{4,11}$/', $qq)) {
            return $qq . '@qq.com';
        }

        return '';
    }

    /** 用户是否愿意收这类邮件。安全类强制发，其余可在个人中心关掉。 */
    function lwMailWants(int $userId, string $type): bool
    {
        $forced = ['ban', 'unban', 'password', 'login_alert'];
        if (in_array($type, $forced, true)) {
            return true;
        }
        try {
            $fs = getFS();
            $u = $fs->findById('users', $userId);
            if (!$u) return false;
            if (lwMailAddressOf($u) === '') {
                return false;
            }
            $prefs = [];
            if (!empty($u['mail_prefs'])) {
                $decoded = json_decode((string)$u['mail_prefs'], true);
                if (is_array($decoded)) $prefs = $decoded;
            }
            if (array_key_exists($type, $prefs)) {
                return (bool)$prefs[$type];
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 读发送记录 */
    function lwMailLogRead(): array
    {
        $f = dirname(__DIR__) . '/data/mail_log.json';
        if (!is_file($f)) return [];
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') return [];
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    /** 写一条记录（保留最近 500 条） */
    function lwMailLogAppend(array $row): void
    {
        try {
            $f = dirname(__DIR__) . '/data/mail_log.json';
            $rows = lwMailLogRead();
            $row['id'] = (int)(microtime(true) * 1000);
            $rows[] = $row;
            if (count($rows) > 500) {
                $rows = array_slice($rows, -500);
            }
            @file_put_contents(
                $f,
                json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        } catch (Throwable $e) {
        }
    }

    /** 同收件人同类型 5 分钟内是否已发过 */
    function lwMailRecentlySent(string $to, string $type): bool
    {
        $rows = lwMailLogRead();
        $cut = time() - 300;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $r = $rows[$i];
            if (($r['to'] ?? '') !== $to) continue;
            if (($r['type'] ?? '') !== $type) continue;
            if ((int)($r['ts'] ?? 0) < $cut) break;
            if (!empty($r['ok'])) return true;
        }
        return false;
    }

    /** 渲染邮件正文（内联样式 —— QQ/163/Gmail 会剔除 <style>） */
    function lwMailRender(string $type, array $user, array $data): string
    {
        $site = function_exists('lwCfg') ? (string)lwCfg('site_name', SITE_NAME) : (defined('SITE_NAME') ? SITE_NAME : '校园交流墙');
        $title = (string)($data['title'] ?? (lwMailTypes()[$type] ?? '站内通知'));
        $lines = $data['lines'] ?? [];
        if (!is_array($lines)) $lines = [(string)$lines];
        $link = (string)($data['link'] ?? '');
        $linkText = (string)($data['linkText'] ?? '打开网站');
        $nick = (string)($user['nickname'] ?? '同学');

        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

        $body = '';
        foreach ($lines as $ln) {
            $body .= '<p style="margin:0 0 12px;font-size:14px;line-height:1.8;color:#3A4351;">'
                   . nl2br($e($ln)) . '</p>';
        }

        $btn = '';
        if ($link !== '') {
            $btn = '<div style="margin:22px 0 6px;"><a href="' . $e($link) . '" '
                 . 'style="display:inline-block;padding:11px 22px;background:#2F5B9A;color:#fff;'
                 . 'text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;">'
                 . $e($linkText) . '</a></div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
            . '<body style="margin:0;padding:24px 12px;background:#F5F7FA;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">'
            . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #E7ECF3;">'
            . '<div style="padding:20px 24px;border-bottom:1px solid #EEF2F7;background:#FAFBFD;">'
            . '<div style="font-size:15px;font-weight:700;color:#1F2733;">' . $e($site) . '</div>'
            . '<div style="font-size:12px;color:#98A2B3;margin-top:4px;">' . $e(lwMailTypes()[$type] ?? '通知') . '</div>'
            . '</div><div style="padding:22px 24px;">'
            . '<p style="margin:0 0 14px;font-size:15px;font-weight:600;color:#1F2733;">' . $e($nick) . '，你好</p>'
            . '<p style="margin:0 0 16px;font-size:14px;line-height:1.8;color:#3A4351;">' . $e($title) . '</p>'
            . $body . $btn
            . '</div><div style="padding:16px 24px;border-top:1px solid #EEF2F7;background:#FAFBFD;font-size:12px;color:#98A2B3;line-height:1.7;">'
            . '这封邮件由系统自动发出，请勿直接回复。<br>'
            . '不想再收到这类通知？到「个人中心 → 通知设置」里关掉即可。'
            . '<br><br>'
            . '<span style="color:#B6BFCB;">发送时间：' . date('Y-m-d H:i:s')
            . ' · 类型：' . $e(lwMailTypes()[$type] ?? $type)
            . ' · 收件人：' . $e((string)($user['nickname'] ?? ''))
            . '(' . $e(lwMailAddressOf($user)) . ')'
            . '</span>'
            . '</div></div></body></html>';
    }

    /**
     * 统一邮件通知入口。
     *
     * @param string $type   类型（见 lwMailTypes）
     * @param int    $userId 收件人用户 ID
     * @param array  $data   ['subject'=>..., 'title'=>..., 'lines'=>[...], 'link'=>..., 'linkText'=>...]
     * @return bool 是否真的发出去了
     */
    function lwMailNotify(string $type, int $userId, array $data = []): bool
    {
        try {
            if (!class_exists('QQMailer') || !QQMailer::isConfigured()) {
                return false;
            }
            $fs = getFS();
            $u = $fs->findById('users', $userId);
            if (!$u) return false;

            $to = lwMailAddressOf($u);
            if ($to === '') {
                return false;
            }

            if (!lwMailWants($userId, $type)) {
                lwMailLogAppend([
                    'to' => $to, 'toUserId' => $userId, 'type' => $type,
                    'subject' => '', 'ok' => false, 'skipped' => true,
                    'reason' => '用户已关闭此类通知', 'ts' => time(),
                ]);
                return false;
            }

            if (lwMailRecentlySent($to, $type)) {
                lwMailLogAppend([
                    'to' => $to, 'toUserId' => $userId, 'type' => $type,
                    'subject' => '', 'ok' => false, 'skipped' => true,
                    'reason' => '5 分钟内已发过同类邮件', 'ts' => time(),
                ]);
                return false;
            }

            $subject = (string)($data['subject'] ?? (lwMailTypes()[$type] ?? '站内通知'));
            $html = lwMailRender($type, $u, $data);

            $ok = false;
            $err = '';
            try {
                $ok = (bool)QQMailer::send($to, $subject, $html);
                if (!$ok) {
                    $err = method_exists('QQMailer', 'lastError') ? (string)QQMailer::lastError() : 'SMTP 发送失败';
                }
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }

            lwMailLogAppend([
                'to' => $to, 'toUserId' => $userId, 'type' => $type,
                'subject' => $subject, 'ok' => $ok, 'skipped' => false,
                'reason' => $ok ? '' : ($err !== '' ? $err : '发送失败'),
                'ts' => time(),
            ]);

            return $ok;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 后台群发。
     *
     * =====================================================================
     * 为什么必须限速 + 分批
     * =====================================================================
     * QQ 邮箱对「同一账号短时间大量发信」是有限制的：超了会临时拒发，
     * 严重的会封掉 SMTP 权限。所以这里：
     *   - 每次最多发 $limit 封（由调用方分批调用）
     *   - 每封之间 sleep 一点，避免被判定为机器群发
     *   - 逐封记录结果，中途失败不影响已发的
     *
     * 调用方（后台页面）应该按批循环，每批之间给用户看进度。
     * =====================================================================
     *
     * @param array  $userIds 收件人用户 ID 列表
     * @param string $subject 主题
     * @param array  $data    ['title'=>..., 'lines'=>[...], 'link'=>..., 'linkText'=>...]
     * @param int    $limit   本批最多发几封（0 = 不限）
     * @param int    $gapMs   每封之间间隔毫秒
     * @return array ['sent'=>int, 'failed'=>int, 'skipped'=>int, 'results'=>[...]]
     */
    function lwMailBroadcast(array $userIds, string $subject, array $data, int $limit = 30, int $gapMs = 350): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];

        if (!class_exists('QQMailer') || !QQMailer::isConfigured()) {
            return $out;
        }

        $fs = getFS();
        $n = 0;

        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) continue;

            if ($limit > 0 && $n >= $limit) {
                break;
            }

            $u = $fs->findById('users', $uid);
            if (!$u) {
                $out['skipped']++;
                continue;
            }

            // 被封禁的、没 QQ 的跳过
            if (!empty($u['is_banned'])) {
                $out['skipped']++;
                continue;
            }
            $to = lwMailAddressOf($u);
            if ($to === '') {
                $out['skipped']++;
                continue;
            }

            $html = lwMailRender('system', $u, $data);
            $ok = false;
            $err = '';
            try {
                $ok = (bool)QQMailer::send($to, $subject, $html);
                if (!$ok) {
                    $err = method_exists('QQMailer', 'lastError') ? (string)QQMailer::lastError() : '发送失败';
                }
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }

            lwMailLogAppend([
                'to' => $to, 'toUserId' => $uid, 'type' => 'system',
                'subject' => $subject, 'ok' => $ok, 'skipped' => false,
                'reason' => $ok ? '' : $err, 'ts' => time(),
                'broadcast' => true,
            ]);

            if ($ok) { $out['sent']++; } else { $out['failed']++; }
            $out['results'][] = ['uid' => $uid, 'to' => $to, 'ok' => $ok, 'err' => $err];
            $n++;

            if ($gapMs > 0) {
                usleep($gapMs * 1000);
            }
        }

        return $out;
    }

    /**
     * 邮件模板（后台可编辑，存在 data/mail_templates.json）。
     *
     * 内置三套常用模板，站长可以改文案但不能改结构 ——
     * 结构固定是为了保证在 QQ/163/Gmail 里都能正常显示。
     */
    function lwMailTemplates(): array
    {
        $defaults = [
            'announce' => [
                'name'    => '站点公告',
                'subject' => '【' . (defined('SITE_NAME') ? SITE_NAME : '校园交流墙') . '】站点公告',
                'title'   => '有一条新的站点公告',
                'lines'   => "请到网站首页查看最新公告。
如有疑问，可以在站内私信管理员。",
                'link'    => defined('SITE_URL') ? SITE_URL . '/' : '',
                'linkText'=> '查看公告',
            ],
            'maintain' => [
                'name'    => '维护通知',
                'subject' => '【维护】网站将短暂停止服务',
                'title'   => '网站即将进行维护',
                'lines'   => "维护期间网站无法访问，预计很快恢复。
给你带来不便，敬请谅解。",
                'link'    => '',
                'linkText'=> '',
            ],
            'welcome_back' => [
                'name'    => '回归邀请',
                'subject' => '好久不见，回来看看？',
                'title'   => '有一阵子没见你上站了',
                'lines'   => "最近站里多了不少新内容。
有空回来看看，也许能碰到熟人。",
                'link'    => defined('SITE_URL') ? SITE_URL . '/' : '',
                'linkText'=> '去看看',
            ],
        ];

        $f = dirname(__DIR__) . '/data/mail_templates.json';
        if (is_file($f)) {
            $raw = @file_get_contents($f);
            $j = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($j)) {
                foreach ($j as $k => $v) {
                    if (isset($defaults[$k]) && is_array($v)) {
                        $defaults[$k] = array_merge($defaults[$k], $v);
                    }
                }
            }
        }
        return $defaults;
    }

    /** 保存模板。 */
    function lwMailTemplateSave(array $templates): bool
    {
        try {
            $f = dirname(__DIR__) . '/data/mail_templates.json';
            return @file_put_contents(
                $f,
                json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            ) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 手动发测试邮件（后台用） */
    function lwMailSendTest(string $to): array
    {
        if (!class_exists('QQMailer') || !QQMailer::isConfigured()) {
            return ['ok' => false, 'message' => 'SMTP 尚未配置（config/mail_config.php）'];
        }
        try {
            $html = lwMailRender('test', ['nickname' => '站长'], [
                'title' => '这是一封测试邮件',
                'lines' => [
                    '如果你看到这封邮件，说明 SMTP 配置正确，站内通知可以正常工作。',
                    '发送时间：' . date('Y-m-d H:i:s'),
                ],
            ]);
            $ok = (bool)QQMailer::send($to, '【测试】站内邮件通知', $html);
            $err = $ok ? '' : (method_exists('QQMailer', 'lastError') ? (string)QQMailer::lastError() : '发送失败');

            lwMailLogAppend([
                'to' => $to, 'toUserId' => 0, 'type' => 'test',
                'subject' => '【测试】站内邮件通知', 'ok' => $ok, 'skipped' => false,
                'reason' => $err, 'ts' => time(),
            ]);

            return ['ok' => $ok, 'message' => $ok ? '测试邮件已发送，请查收（若没收到请检查垃圾箱）' : ('发送失败：' . $err)];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => '发送异常：' . $e->getMessage()];
        }
    }
}