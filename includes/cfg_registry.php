<?php
/**
 * 全站配置注册表
 *
 * =====================================================================
 * 为什么要这张表
 * =====================================================================
 * 现在配置散在三个地方：
 *   1. config/constants.php —— 26 个 define，改一个要动代码、重新上传
 *   2. config/*.php        —— ai / app / feijipan / site_profile 各一个文件
 *   3. data/settings.json  —— 只有 6 个键，后台能改
 *
 * 结果就是：站长想调「上传大小」「每页条数」「注册开关」这类东西，
 * 必须改 PHP 文件再上传 —— 手机上根本做不了，还得找我。
 *
 * 这张表把**所有值得配的东西**登记下来，说明清楚：
 *   - 它是什么（label / hint）
 *   - 值域是什么（type / min / max / options）
 *   - 当前默认多少（default）
 *
 * 后台渲染、读取、校验全部由这张表驱动，不用一页页手写表单。
 *
 * =====================================================================
 * 怎么加新配置
 * =====================================================================
 * 在 GROUPS 里对应分组的 items 中加一条，然后取值处调用
 *   lwCfg('键名')      取当前值（后台改过就用后台的，否则用默认）
 * 就完了。不用改后台页面，它会自动出现。
 *
 * =====================================================================
 * 哪些不该放进来
 * =====================================================================
 *   - 数据库密码、网盘账号、AI 密钥：这些是「凭据」，单独在别的页面配，
 *     混进通用配置表容易被一起导出/截图泄露
 *   - 纯技术常量（BASE_PATH、SITE_URL）：不是给人调的
 * 其余凡是「站长可能想改」的，都应该在这张表里。
 * =====================================================================
 */

if (!defined('LW_CFG_REGISTRY_LOADED')) {
    define('LW_CFG_REGISTRY_LOADED', 1);

    /**
     * 配置清单。
     *
     * type 支持：text / textarea / number / switch / select / color
     */
    function lwCfgGroups(): array
    {
        return [

            'site' => [
                'label' => '站点信息',
                'icon'  => 'home',
                'items' => [
                    'site_name' => [
                        'label' => '站点名称', 'type' => 'text', 'default' => '淮南北师大实验中学高中部校园交流墙',
                        'hint'  => '显示在浏览器标题、顶栏和页脚。改完刷新即生效。',
                    ],
                    'site_slogan' => [
                        'label' => '一句话简介', 'type' => 'text', 'default' => '本校师生的交流空间',
                        'hint'  => '用于 SEO 描述和分享卡片。',
                    ],
                    'announcement' => [
                        'label' => '首页公告', 'type' => 'textarea', 'default' => '',
                        'hint'  => '留空则不显示公告条。支持换行。',
                    ],
                    'community_rules' => [
                        'label' => '社区规范', 'type' => 'textarea', 'default' => '',
                        'hint'  => '注册页和发帖页会引用这份规范。',
                    ],
                    'footer_extra' => [
                        'label' => '页脚补充说明', 'type' => 'text', 'default' => '',
                        'hint'  => '例如备案号、主办单位。留空不显示。',
                    ],
                    'profile_cover_default' => [
                        'label' => '个人中心默认头图', 'type' => 'text', 'default' => '',
                        'hint'  => '没上传头图的用户显示这张。留空则用渐变色背景。',
                    ],
                    'enable_profile_page' => [
                        'label' => '启用个人主页', 'type' => 'switch', 'default' => '1',
                        'hint'  => '每位用户一个独立主页（/u/昵称），类似 B 站个人空间。',
                    ],
                ],
            ],

            'access' => [
                'label' => '访问与注册',
                'icon'  => 'users',
                'items' => [
                    'register_enabled' => [
                        'label' => '开放注册', 'type' => 'switch', 'default' => '1',
                        'hint'  => '关闭后新用户无法注册，老用户不受影响。',
                    ],
                    'register_gate' => [
                        'label' => '启用新生验证题', 'type' => 'switch', 'default' => '1',
                        'hint'  => '开启后注册需答校训或校长姓名；填有效邀请码可跳过。',
                    ],
                    'register_daily_limit' => [
                        'label' => '同一网络每日注册上限', 'type' => 'number', 'default' => '20',
                        'min'   => 1, 'max' => 500,
                        'hint'  => '校园网全校共用一个出口 IP，这个值按「全校一天能进多少人」来设。',
                    ],
                    'invite_enabled' => [
                        'label' => '启用邀请码', 'type' => 'switch', 'default' => '1',
                        'hint'  => '关闭后邀请码不再免验证、也不再发奖励。',
                    ],
                    'guest_visible_posts' => [
                        'label' => '游客可见帖子数', 'type' => 'number', 'default' => '5',
                        'min'   => 0, 'max' => 50,
                        'hint'  => '未注册访客在首页能看到的条数。设 0 则完全要求登录。',
                    ],
                    'maintenance_mode' => [
                        'label' => '维护模式', 'type' => 'switch', 'default' => '0',
                        'hint'  => '开启后全站显示维护提示，仅管理员可访问。',
                    ],
                    'maintenance_message' => [
                        'label' => '维护提示文案', 'type' => 'textarea', 'default' => '正在维护，请稍后再来。',
                        'hint'  => '维护模式下显示给用户的话。',
                    ],
                ],
            ],

            'content' => [
                'label' => '内容与发帖',
                'icon'  => 'edit',
                'items' => [
                    'items_per_page' => [
                        'label' => '每页帖子数', 'type' => 'number', 'default' => '20',
                        'min'   => 5, 'max' => 100,
                        'hint'  => '列表页每页加载多少条。调小加载快，调大翻页少。',
                    ],
                    'post_min_length' => [
                        'label' => '正文最少字数', 'type' => 'number', 'default' => '5',
                        'min'   => 1, 'max' => 100,
                    ],
                    'post_max_length' => [
                        'label' => '正文最多字数', 'type' => 'number', 'default' => '5000',
                        'min'   => 100, 'max' => 50000,
                    ],
                    'max_images_per_post' => [
                        'label' => '单帖最多图片数', 'type' => 'number', 'default' => '9',
                        'min'   => 0, 'max' => 30,
                    ],
                    'max_single_image_mb' => [
                        'label' => '单张图片上限 (MB)', 'type' => 'number', 'default' => '5',
                        'min'   => 1, 'max' => 20,
                        'hint'  => '不能超过服务器 upload_max_filesize。',
                    ],
                    'post_rate_limit' => [
                        'label' => '发帖频率上限', 'type' => 'number', 'default' => '20',
                        'min'   => 1, 'max' => 200,
                        'hint'  => '每 5 分钟最多发几条。',
                    ],
                    'enable_poll' => [
                        'label' => '允许发起投票', 'type' => 'switch', 'default' => '1',
                    ],
                    'enable_anonymous' => [
                        'label' => '允许匿名发帖', 'type' => 'switch', 'default' => '1',
                    ],
                    'pm_rate_limit' => [
                        'label' => '私信频率上限（条/分钟）', 'type' => 'number', 'default' => '60',
                        'min'   => 5, 'max' => 500,
                        'hint'  => '按账号算，不是按 IP —— 校园网全校共用一个出口 IP，按 IP 限会误伤别人。',
                    ],
                    'post_comment_rate_limit' => [
                        'label' => '评论频率上限（条/分钟）', 'type' => 'number', 'default' => '30',
                        'min'   => 1, 'max' => 300,
                    ],
                ],
            ],

            'moderation' => [
                'label' => '审核',
                'icon'  => 'shield',
                'items' => [
                    'ai_moderation_enabled' => [
                        'label' => '启用 AI 审核', 'type' => 'switch', 'default' => '1',
                        'hint'  => '关闭后只跑关键词与结构层。AI 永远只能降级为待审，不能直接拒绝。',
                    ],
                    'ai_review_threshold' => [
                        'label' => 'AI 降级分数线', 'type' => 'number', 'default' => '60',
                        'min'   => 0, 'max' => 100,
                        'hint'  => 'AI 评分达到此值才把帖子转成待审。调高更宽松。',
                    ],
                    'keyword_block_action' => [
                        'label' => '命中敏感词的处理', 'type' => 'select', 'default' => 'pending',
                        'options' => ['pending' => '转待审', 'reject' => '直接拒绝', 'review' => '发布并标记'],
                    ],
                    'ai_daily_quota' => [
                        'label' => 'AI 每日总调用上限', 'type' => 'number', 'default' => '2000',
                        'min'   => 0, 'max' => 100000,
                        'hint'  => '全站所有 AI 调用（审核/翻译/助手）加起来每天最多几次。0 表示不限制。' .
                                   '这是防止「脚本把额度烧光」的最后一道闸门，建议保留。',
                    ],
                ],
            ],

            'level' => [
                'label' => '等级与成长',
                'icon'  => 'trending',
                'items' => [
                    'level_enabled' => [
                        'label' => '启用等级系统', 'type' => 'switch', 'default' => '1',
                    ],
                    'level_daily_cap' => [
                        'label' => '每日经验上限', 'type' => 'number', 'default' => '100',
                        'min'   => 0, 'max' => 10000,
                        'hint'  => '防止刷分，0 表示不限制。',
                    ],
                    'checkin_enabled' => [
                        'label' => '启用签到', 'type' => 'switch', 'default' => '1',
                    ],
                ],
            ],

            'app' => [
                'label' => '安卓客户端',
                'icon'  => 'smartphone',
                'items' => [
                    'app_latest_version_code' => [
                        'label' => '最新版本号 (数字)', 'type' => 'number', 'default' => '20402',
                        'min'   => 1, 'max' => 999999,
                        'hint'  => '必须比旧版本大，App 靠它判断要不要提示更新。',
                    ],
                    'app_latest_version_name' => [
                        'label' => '最新版本名', 'type' => 'text', 'default' => '2.4.2',
                    ],
                    'app_apk_file' => [
                        'label' => 'APK 文件名', 'type' => 'text', 'default' => 'lovewall-v2.4.2.apk',
                        'hint'  => '放在 /download/ 目录下的文件名。',
                    ],
                    'app_update_notes' => [
                        'label' => '更新说明', 'type' => 'textarea', 'default' => '',
                        'hint'  => 'App 内更新弹窗会显示这段文字。',
                    ],
                    'app_force_update' => [
                        'label' => '强制更新', 'type' => 'switch', 'default' => '0',
                        'hint'  => '开启后旧版本 App 必须更新才能继续使用。',
                    ],
                ],
            ],

            'performance' => [
                'label' => '性能与缓存',
                'icon'  => 'zap',
                'items' => [
                    'cache_ttl_feed' => [
                        'label' => '信息流缓存 (秒)', 'type' => 'number', 'default' => '120',
                        'min'   => 0, 'max' => 3600,
                        'hint'  => '0 表示不缓存。校园网慢，建议保留 60~300。',
                    ],
                    'cache_ttl_tags' => [
                        'label' => '标签云缓存 (秒)', 'type' => 'number', 'default' => '3600',
                        'min'   => 0, 'max' => 86400,
                    ],
                    'enable_bundle' => [
                        'label' => '启用资源合并', 'type' => 'switch', 'default' => '1',
                        'hint'  => '关掉会退回逐个加载（请求数从 4 个变回 17 个，首页会慢十几秒）。',
                    ],
                    'enable_disk_cache' => [
                        'label' => '启用磁盘缓存', 'type' => 'switch', 'default' => '1',
                    ],
                    'analytics_max_days' => [
                        'label' => '数据统计最长时段（天）', 'type' => 'number', 'default' => '730',
                        'min'   => 30, 'max' => 3650,
                        'hint'  => '后台数据看板最多能看多少天。超过 120 天按周聚合，超过 365 天按月聚合。',
                    ],
                    'api_timeout_hint' => [
                        'label' => '慢请求提示阈值（毫秒）', 'type' => 'number', 'default' => '3000',
                        'min'   => 500, 'max' => 30000,
                        'hint'  => '前端加载超过这个时间就提示「网络较慢，正在重试」。',
                    ],
                ],
            ],

            'filehelper' => [
                'label' => '文件传输',
                'icon'  => 'send',
                'items' => [
                    'fh_enabled' => [
                        'label' => '启用文件传输', 'type' => 'switch', 'default' => '1',
                    ],
                    'fh_relay_max_mb' => [
                        'label' => '服务器中转上限 (MB)', 'type' => 'number', 'default' => '20',
                        'min'   => 1, 'max' => 20,
                        'hint'  => '超过此大小改走点对点直传，不经过服务器。受服务器上传限制约束。',
                    ],
                    'fh_relay_ttl_min' => [
                        'label' => '中转文件保留 (分钟)', 'type' => 'number', 'default' => '1',
                        'min'   => 1, 'max' => 1440,
                        'hint'  => '过期自动删除，避免占满空间。',
                    ],
                    'fh_p2p_fallback_sec' => [
                        'label' => '点对点超时降级 (秒)', 'type' => 'number', 'default' => '12',
                        'min'   => 3, 'max' => 120,
                        'hint'  => '连不上就自动改用网盘通道，不让用户干等。',
                    ],
                ],
            ],

            'egg' => [
                'label' => '彩蛋',
                'icon'  => 'gift',
                'items' => [
                    'egg_enabled' => [
                        'label' => '启用彩蛋', 'type' => 'switch', 'default' => '1',
                    ],
                    'egg_url' => [
                        'label' => '彩蛋跳转地址', 'type' => 'text', 'default' => 'https://www.bilibili.com/video/BV1GJ411x7h7/',
                        'hint'  => '连点站名 5 次或输入密令后，彩带放完会跳到这里。',
                    ],
                    'egg_taps_needed' => [
                        'label' => '连点次数', 'type' => 'number', 'default' => '5',
                        'min'   => 3, 'max' => 20,
                    ],
                    'egg_anim_ms' => [
                        'label' => '彩带时长 (毫秒)', 'type' => 'number', 'default' => '2500',
                        'min'   => 500, 'max' => 10000,
                        'hint'  => '彩带放完才跳转，太短会显得突兀。',
                    ],
                ],
            ],

            'security' => [
                'label' => '安全',
                'icon'  => 'lock',
                'items' => [
                    'session_lifetime_hours' => [
                        'label' => '登录有效期 (小时)', 'type' => 'number', 'default' => '24',
                        'min'   => 1, 'max' => 720,
                    ],
                    'remember_days' => [
                        'label' => '记住登录天数', 'type' => 'number', 'default' => '15',
                        'min'   => 1, 'max' => 365,
                    ],
                    'twofa_lockout_attempts' => [
                        'label' => '两步验证锁定次数', 'type' => 'number', 'default' => '5',
                        'min'   => 3, 'max' => 20,
                    ],
                    'twofa_lockout_minutes' => [
                        'label' => '两步验证锁定时长 (分钟)', 'type' => 'number', 'default' => '10',
                        'min'   => 1, 'max' => 1440,
                    ],
                    'waf_enabled' => [
                        'label' => '启用 WAF', 'type' => 'switch', 'default' => '1',
                        'hint'  => '拦截恶意请求。关掉会明显降低防护，除非排查误拦否则不要关。',
                    ],
                    'selfheal_enabled' => [
                        'label' => '启用自恢复守护', 'type' => 'switch', 'default' => '1',
                    ],
                ],
            ],
        ];
    }

    /**
     * 取一个配置项的定义。
     */
    function lwCfgDef(string $key): ?array
    {
        foreach (lwCfgGroups() as $group) {
            if (isset($group['items'][$key])) {
                return $group['items'][$key];
            }
        }
        return null;
    }

    /**
     * 取配置值。
     *
     * 优先级：后台设置 > 默认值。
     * 找不到定义时返回 $fallback，便于逐步迁移（老代码继续能用常量）。
     */
    function lwCfg(string $key, $fallback = null)
    {
        $def = lwCfgDef($key);
        $default = $def !== null ? ($def['default'] ?? '') : $fallback;

        if (!function_exists('getSetting')) {
            return $default;
        }
        $v = getSetting($key, null);
        if ($v === null || $v === '') {
            return $default;
        }
        return $v;
    }

    /** 取整数配置。 */
    function lwCfgInt(string $key, int $fallback = 0): int
    {
        $v = lwCfg($key, $fallback);
        return is_numeric($v) ? (int)$v : $fallback;
    }

    /** 取布尔配置（'1'/'0'）。 */
    function lwCfgBool(string $key, bool $fallback = true): bool
    {
        $v = lwCfg($key, $fallback ? '1' : '0');
        return (string)$v === '1';
    }

    /**
     * 校验并规整一个提交上来的值。
     *
     * @return array{ok:bool,value?:string,message?:string}
     */
    function lwCfgNormalize(string $key, $raw): array
    {
        $def = lwCfgDef($key);
        if ($def === null) {
            return ['ok' => false, 'message' => '未知的配置项'];
        }
        $type = $def['type'] ?? 'text';
        $val  = is_scalar($raw) ? (string)$raw : '';

        switch ($type) {
            case 'switch':
                $val = ($val === '1' || $val === 'true' || $val === 'on') ? '1' : '0';
                break;

            case 'number':
                if ($val === '') {
                    $val = (string)($def['default'] ?? '0');
                }
                if (!is_numeric($val)) {
                    return ['ok' => false, 'message' => ($def['label'] ?? $key) . ' 必须是数字'];
                }
                $n = $val + 0;
                $min = $def['min'] ?? null;
                $max = $def['max'] ?? null;
                if ($min !== null && $n < $min) {
                    return ['ok' => false, 'message' => ($def['label'] ?? $key) . " 不能小于 $min"];
                }
                if ($max !== null && $n > $max) {
                    return ['ok' => false, 'message' => ($def['label'] ?? $key) . " 不能大于 $max"];
                }
                $val = (string)(int)$n;
                break;

            case 'select':
                $opts = array_keys($def['options'] ?? []);
                if (!in_array($val, $opts, true)) {
                    $val = (string)($def['default'] ?? ($opts[0] ?? ''));
                }
                break;

            case 'textarea':
                // 保留换行，去掉控制字符，限制长度防止把 JSON 撑爆
                $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $val);
                if (function_exists('mb_substr')) {
                    $val = mb_substr($val, 0, 8000);
                }
                break;

            default: // text / color
                $val = preg_replace('/[\x00-\x1F\x7F]/u', '', $val);
                if (function_exists('mb_substr')) {
                    $val = mb_substr($val, 0, 500);
                }
                break;
        }

        return ['ok' => true, 'value' => $val];
    }

    /**
     * 按分组整理「当前值」，供后台渲染。
     */
    function lwCfgCurrent(): array
    {
        $out = [];
        foreach (lwCfgGroups() as $gkey => $group) {
            $items = [];
            foreach ($group['items'] as $key => $def) {
                $items[$key] = array_merge($def, [
                    'key'   => $key,
                    'value' => lwCfg($key, $def['default'] ?? ''),
                ]);
            }
            $out[$gkey] = array_merge($group, ['items' => $items]);
        }
        return $out;
    }
}
