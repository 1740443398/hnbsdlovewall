<?php
/**
 * 富文本轻量标记 —— 唯一来源
 *
 * 把用户正文里的 `#话题` 与 `@昵称` 变成可点击链接。帖子详情、评论、话题页、用户主页
 * 以及前端动态渲染的内容都复用同一套规则（前端对应实现见
 * assets/js/enhancements.js 的 RichText，正则与跳转形式保持一致）。
 *
 * 为什么不在前端用 innerHTML 替换（旧版做法，已废弃）：
 *   旧实现把渲染好的 HTML 整串拿出来做正则替换再写回，会命中正文里已经存在的
 *   `<a href="...">`、`<img src="...">` 里的字符，把标签切坏（比如把图片地址里的
 *   `#` 当成话题）。这里改成**先在纯文本上转义、再插入自己生成的 <a>**，
 *   正文里的任何内容都只可能是文本，结构上不可能被用户输入破坏。
 *
 * 链接形式统一为 `/pages/u.php?nick=<昵称>`（页面自行解析昵称）：
 *   - 服务端与前端因此可以共用同一条规则，前端不必额外拉一份「昵称 => ID」映射表，
 *     省掉一次 JSON 全表遍历和一次接口往返；
 *   - 昵称在站内本来就是公开信息（帖子作者、评论作者本来就显示昵称），不涉及额外暴露。
 */

if (!defined('LW_RICH_TEXT_LOADED')) {
    define('LW_RICH_TEXT_LOADED', 1);

    /** 话题名允许的字符：中文、字母、数字、下划线，长度 1-30 */
    define('LW_TAG_PATTERN', '[\p{Han}A-Za-z0-9_]{1,30}');

    /** @提及允许的字符：中文、字母、数字、下划线、点、连字符，长度 1-24。
     *  站点昵称里出现 `.` / `-` 很常见（如「泽.」），不放进来会导致这类昵称无法被提及。 */
    define('LW_MENTION_PATTERN', '[\p{Han}A-Za-z0-9_.\-]{1,24}');

    /** 话题链接地址 */
    function lwTopicUrl(string $tag): string
    {
        return '/pages/topic.php?tag=' . rawurlencode($tag);
    }

    /** 用户主页地址（按昵称解析） */
    function lwUserUrl(string $nickname): string
    {
        return '/pages/u.php?nick=' . rawurlencode($nickname);
    }

    /**
     * 在**已转义**的 HTML 文本上把 #话题 / @昵称 包成链接。
     *
     * 注意：入参必须是 htmlspecialchars() 之后的字符串。函数只会插入
     * class 固定的 <a>，不会引入任何来自入参的标签或属性。
     *
     * @param string $escaped 已转义的文本
     * @param bool   $mentions 是否解析 @提及（摘要等场景可关掉，避免把邮箱段落连成一片）
     */
    function lwLinkifyEscaped(string $escaped, bool $mentions = true): string
    {
        if ($escaped === '') {
            return $escaped;
        }

        // 话题：前面不能是字母/数字/下划线/#/&（& 用于挡 &#39; 这类转义实体里的 #）
        $escaped = preg_replace_callback(
            '/(?<![\p{L}\p{N}_#&])#(' . LW_TAG_PATTERN . ')/u',
            function ($m) {
                $tag = $m[1];
                return '<a href="' . htmlspecialchars(lwTopicUrl($tag), ENT_QUOTES)
                    . '" class="hashtag-link" data-tag="' . htmlspecialchars($tag, ENT_QUOTES) . '">#'
                    . $tag . '</a>';
            },
            $escaped
        );

        if ($mentions) {
            // 前面不能是字母/数字/下划线/@/&（挡邮箱 a@b.com 与转义实体）
            $escaped = preg_replace_callback(
                '/(?<![\p{L}\p{N}_@&])@(' . LW_MENTION_PATTERN . ')/u',
                function ($m) {
                    $nick = $m[1];
                    return '<a href="' . htmlspecialchars(lwUserUrl($nick), ENT_QUOTES)
                        . '" class="mention-link" data-nick="' . htmlspecialchars($nick, ENT_QUOTES) . '">@'
                        . $nick . '</a>';
                },
                $escaped
            );
        }

        return $escaped;
    }

    /**
     * 正文渲染（帖子内容 / 评论内容 / 简介）：转义 + 加链接 + 保留换行。
     * 与旧代码 `nl2br(htmlspecialchars($x))` 等价且多了 #/@ 支持。
     */
    function lwRichText(?string $raw, bool $mentions = true): string
    {
        return nl2br(lwLinkifyEscaped(htmlspecialchars((string)$raw), $mentions));
    }

    /**
     * 从正文里提取话题（用于话题页统计与「相关话题」）。
     * 返回 [tag => 出现次数]，排序由调用方处理。
     */
    function lwExtractTags(?string $raw): array
    {
        $raw = (string)$raw;
        if ($raw === '' || mb_stripos($raw, '#') === false) {
            return [];
        }
        if (!preg_match_all('/(?<![\p{L}\p{N}_#&])#(' . LW_TAG_PATTERN . ')/u', $raw, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $tag) {
            $out[$tag] = ($out[$tag] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * 从正文里提取 @提及（用于「被提到时发站内通知」）。
     * 返回 [昵称 => 出现次数]，规则与 lwLinkifyEscaped 的提及正则完全一致，
     * 保证「页面上能点到的」和「会收到通知的」是同一批人。
     */
    function lwExtractMentions(?string $raw): array
    {
        $raw = (string)$raw;
        if ($raw === '' || strpos($raw, '@') === false) {
            return [];
        }
        if (!preg_match_all('/(?<![\p{L}\p{N}_@&])@(' . LW_MENTION_PATTERN . ')/u', $raw, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $nick) {
            $out[$nick] = ($out[$nick] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * 归一化请求里的话题参数（去 #、去首尾空白、长度/字符集校验）。
     * 不合法时返回空字符串，调用方据此提示「话题不存在」。
     */
    function lwNormalizeTag(?string $tag): string
    {
        $tag = trim((string)$tag);
        if ($tag === '') {
            return '';
        }
        $tag = ltrim($tag, '#');
        if ($tag === '' || !preg_match('/^' . LW_TAG_PATTERN . '$/u', $tag)) {
            return '';
        }
        return $tag;
    }
}
