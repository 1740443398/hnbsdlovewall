<?php
/**
 * 富文本渲染：Markdown 子集 + 白名单 HTML
 *
 * ==========================================================================
 * 安全模型（先读这一节，改代码前必须理解）
 * ==========================================================================
 * 允许用户写 HTML 等于打开 XSS 大门。这里的做法是：
 *
 *   1. **先转义，再还原白名单** —— 不是「过滤黑名单」
 *      整段正文先 htmlspecialchars（此时所有 < > 都变成实体，无法执行），
 *      然后只把「我们认识的、安全的」标签从实体还原成真标签。
 *      没被还原的一律保持实体，天然免疫 <script>、onerror= 之类。
 *
 *   2. **标签级白名单** —— 只允许这些标签，其余全部当纯文本
 *      p br strong em del code pre blockquote ul ol li a img h1-h4
 *      table thead tbody tr th td hr
 *
 *   3. **属性级白名单** —— 每个标签只保留指定属性
 *      a:   href（只允许 http/https/站内相对路径）
 *      img: src（同上）、alt
 *      其余标签：不允许任何属性
 *      这直接排除了 onclick / onerror / style / srcset / formaction 等所有
 *      注入面 —— 它们不是被过滤，而是**根本没有被还原的机会**。
 *
 *   4. **协议白名单** —— 只允许 http:、https:、mailto: 和站内相对路径
 *      javascript: / data: / vbscript: 一律拒绝
 *
 *   5. **不在白名单里的 HTML 块原样显示**（作为文本）
 *      这比「静默丢弃」好：用户能看见自己写的 <video> 没生效，
 *      而不是以为发帖丢了内容。
 *
 * ==========================================================================
 * Markdown 支持范围
 * ==========================================================================
 * 支持（按使用频率排序）：
 *   # ## ### ####  标题
 *   **粗体**  *斜体*  ~~删除线~~  `代码`
 *   ```lang 代码块
 *   > 引用
 *   - / * / 1.  列表（支持嵌套一层）
 *   [文字](链接)  链接
 *   ![alt](地址)  图片
 *   ---  分隔线
 *   | a | b |  表格
 *
 * 不支持（刻意）：
 *   内联 HTML 混排（<span> 之类）—— 白名单里没有，会被当文本
 *   HTML 注释 —— 直接丢弃，避免藏东西
 *   脚注、目录、数学公式 —— 校园场景用不上，实现成本高
 * ==========================================================================
 */

if (!defined('LW_RICHTEXT_LOADED')) {
    define('LW_RICHTEXT_LOADED', 1);

    /** 允许的标签 */
    function lwRtAllowedTags(): array
    {
        return [
            'p', 'br', 'hr',
            'strong', 'b', 'em', 'i', 'del', 's', 'u', 'mark',
            'code', 'pre', 'blockquote',
            'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4',
            'a', 'img',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ];
    }

    /** 允许的属性（标签 => 属性列表） */
    function lwRtAllowedAttrs(): array
    {
        return [
            'a'   => ['href', 'title'],
            'img' => ['src', 'alt'],
            'code' => ['class'],   // 只允许 language-xxx
            'pre'  => ['class'],
        ];
    }

    /**
     * 检查 URL 是否安全。
     *
     * 只允许：http(s)、mailto、站内相对路径（/ 开头）、锚点（# 开头）。
     * 其余（javascript:、data:、vbscript:、file:）一律拒绝。
     */
    function lwRtSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        // 去掉控制字符与空白（防止 java\nscript: 这类绕过）
        $clean = preg_replace('/[\x00-\x20\x7F]+/', '', $url);

        // 站内相对路径
        if ($clean[0] === '/' && (strlen($clean) < 2 || $clean[1] !== '/')) {
            return true;
        }
        // 锚点
        if ($clean[0] === '#') {
            return true;
        }
        // 绝对 URL：只认这几种协议
        if (preg_match('#^(https?://|mailto:)#i', $clean)) {
            return true;
        }
        return false;
    }

    /**
     * 渲染一段正文。
     *
     * @param string $raw     用户原始输入
     * @param bool   $mentions 是否解析 @提及
     * @param bool   $allowHtml 是否允许白名单 HTML 块（评论可关掉）
     */
    function lwRichRender(?string $raw, bool $mentions = true, bool $allowHtml = true): string
    {
        $raw = (string)$raw;
        if ($raw === '') {
            return '';
        }

        // ── 第一步：抽出代码块与行内代码，用占位符保护 ──
        // 它们里面的内容**绝不能**被 Markdown 或 HTML 处理，
        // 否则 `<script>` 这种示例会被当成真标签。
        $codeBlocks = [];
        $raw = preg_replace_callback('/^```([a-zA-Z0-9_+-]*)\s*\n([\s\S]*?)^```\s*$/m', function ($m) use (&$codeBlocks) {
            $idx = count($codeBlocks);
            $lang = preg_replace('/[^a-zA-Z0-9_+-]/', '', $m[1]);
            $codeBlocks[$idx] = ['lang' => $lang, 'code' => $m[2]];
            return "\x01CODEBLOCK{$idx}\x01";
        }, $raw);

        $inlineCodes = [];
        $raw = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$inlineCodes) {
            $idx = count($inlineCodes);
            $inlineCodes[$idx] = $m[1];
            return "\x01INLINECODE{$idx}\x01";
        }, $raw);

        // ── 第二步：整体转义 ──
        // 此时 < > & " ' 全部变成实体，任何标签都失去执行能力。
        $html = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // ── 第三步：白名单 HTML 还原 ──
        if ($allowHtml) {
            $html = lwRtRestoreHtml($html);
        }

        // ── 第四步：Markdown 语法 ──
        $html = lwRtMarkdown($html);

        // ── 第五步：@提及 与 #话题（复用现有逻辑）──
        if (function_exists('lwLinkifyEscaped')) {
            $html = lwLinkifyEscaped($html, $mentions);
        }

        // ── 第六步：还原代码 ──
        $html = preg_replace_callback('/\x01INLINECODE(\d+)\x01/', function ($m) use ($inlineCodes) {
            $i = (int)$m[1];
            return isset($inlineCodes[$i])
                ? '<code class="lw-code-inline">' . htmlspecialchars($inlineCodes[$i], ENT_QUOTES, 'UTF-8') . '</code>'
                : '';
        }, $html);

        /* 还原代码块。
         *
         * 注意：代码内容里的换行**不能**被后面的 nl2br 变成 <br>，
         * 否则代码块里每行都会多一个 <br>，而且缩进全乱。
         * 这里把换行换成占位符 \x02BR\x02，等 nl2br 之后再换回来。
         */
        $html = preg_replace_callback('/\x01CODEBLOCK(\d+)\x01/', function ($m) use ($codeBlocks) {
            $i = (int)$m[1];
            if (!isset($codeBlocks[$i])) return '';
            $b = $codeBlocks[$i];
            $cls = $b['lang'] !== '' ? ' class="language-' . htmlspecialchars($b['lang'], ENT_QUOTES) . '"' : '';
            $code = htmlspecialchars($b['code'], ENT_QUOTES, 'UTF-8');
            return '<pre class="lw-code-block"><code' . $cls . '>' . $code . '</code></pre>';
        }, $html);

        // ── 第七步：换行 ──
        // 块级标签内部的换行不该再变 <br>，所以只在「行尾不是块级标签」时补。
        $html = lwRtSmartBr($html);

        return $html;
    }

    /**
     * 从已转义的文本里还原白名单标签。
     *
     * 输入是 htmlspecialchars 之后的结果，形如：
     *   &lt;p&gt;hello&lt;/p&gt;
     * 输出：
     *   <p>hello</p>
     *
     * 关键点：只处理「成对出现且标签名在白名单里」的，
     * 属性一律按白名单过滤，不在白名单的属性直接丢掉。
     */
    function lwRtRestoreHtml(string $escaped): string
    {
        $allowed = lwRtAllowedTags();
        $attrs = lwRtAllowedAttrs();

        /* 匹配 &lt;tag attrs&gt; / &lt;/tag&gt; / &lt;tag/&gt;
         *
         * 【这里踩过一个坑】
         * 原来属性部分写的是 [^&]*? —— 也就是「不能含 & 字符」。
         * 但转义之后，属性值里的引号变成了 &quot;，里面就有 &。
         * 结果 <a href="/x"> 变成 &lt;a href=&quot;/x&quot;&gt; 后
         * 匹配不上，标签还原失败，用户看到的是裸露的实体文本。
         *
         * 现在改成「匹配到 &gt; 之前的所有内容」，用懒惰量词：
         *   (?:(?!&gt;).)*?  —— 任意字符，但不能是 &gt; 序列
         * 这样 &quot; &#39; 等实体都能正确穿过。
         */
        return preg_replace_callback(
            '/&lt;(\/?)([a-zA-Z][a-zA-Z0-9]*)((?:\s+(?:(?!&gt;).)*?)?)(\s*\/?)\s*&gt;/s',
            function ($m) use ($allowed, $attrs) {
                $closing = $m[1] === '/';
                $tag = strtolower($m[2]);
                $rawAttrs = $m[3];
                $selfClose = $m[4] === '/';

                if (!in_array($tag, $allowed, true)) {
                    return $m[0];   // 不在白名单：原样保留实体（显示为文本）
                }

                if ($closing) {
                    return '</' . $tag . '>';
                }

                // 自闭合标签
                $void = ['br', 'hr', 'img'];
                if (in_array($tag, $void, true)) {
                    return '<' . $tag . lwRtBuildAttrs($tag, $rawAttrs, $attrs) . '>';
                }

                return '<' . $tag . lwRtBuildAttrs($tag, $rawAttrs, $attrs) . '>';
            },
            $escaped
        );
    }

    /** 构造过滤后的属性串。 */
    function lwRtBuildAttrs(string $tag, string $rawAttrs, array $allowedMap): string
    {
        if (trim($rawAttrs) === '') {
            return '';
        }
        if (!isset($allowedMap[$tag])) {
            return '';   // 这个标签不允许任何属性
        }

        $allow = $allowedMap[$tag];

        /* 【关键】先把属性串里的实体解码回真字符。
         *
         * 走到这里时，整段 HTML 已经被 htmlspecialchars 过，
         * 所以 href="/x" 实际是 href=&quot;/x&quot;。
         * 如果不先解码，正则里的引号永远匹配不上，属性就全丢了 ——
         * 这正是「<a href="/x"> 变成 <a> 什么都不剩」的原因。
         *
         * 解码后重新做白名单判断，判断通过的值再转义回去输出。
         */
        $decoded = html_entity_decode($rawAttrs, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $out = '';

        // 匹配 name="value" / name='value' / name=value（未加引号）
        if (preg_match_all(
            '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/',
            $decoded,
            $mm,
            PREG_SET_ORDER
        )) {
            foreach ($mm as $a) {
                $name = strtolower($a[1]);
                $val = isset($a[2]) && $a[2] !== ''
                    ? $a[2]
                    : (isset($a[3]) && $a[3] !== '' ? $a[3] : ($a[4] ?? ''));

                if (!in_array($name, $allow, true)) {
                    continue;   // 属性不在白名单：丢掉
                }

                // class 只允许 language-xxx（代码高亮用）
                if ($name === 'class' && !preg_match('/^language-[a-zA-Z0-9_+-]+$/', $val)) {
                    continue;
                }

                // href / src 做协议白名单
                if ($name === 'href' || $name === 'src') {
                    if (!lwRtSafeUrl($val)) {
                        continue;
                    }
                }

                // 输出时重新转义，防止值里藏引号破坏标签结构
                $out .= ' ' . $name . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return $out;
    }

    /**
     * Markdown 语法转换。
     *
     * 注意：输入已经是「转义过、白名单标签已还原」的 HTML，
     * 所以这里只需要处理 Markdown 标记，不用再考虑 XSS。
     */
    function lwRtMarkdown(string $html): string
    {
        // ── 分隔线 ──
        $html = preg_replace('/^\s*(?:---|\*\*\*|___)\s*$/m', '<hr>', $html);

        // ── 标题（# 到 ####）──
        $html = preg_replace_callback('/^\s{0,3}(#{1,4})\s+(.+?)\s*#*\s*$/m', function ($m) {
            $level = strlen($m[1]);
            $text = trim($m[2]);
            return '<h' . $level . '>' . $text . '</h' . $level . '>';
        }, $html);

        // ── 引用（> 开头，连续多行合并）──
        $html = preg_replace_callback('/(?:^&gt;\s?.+(?:\n|$))+/m', function ($m) {
            $body = preg_replace('/^&gt;\s?/m', '', trim($m[0]));
            return '<blockquote>' . $body . '</blockquote>';
        }, $html);

        // ── 无序列表 ──
        $html = preg_replace_callback('/(?:^\s*[-*+]\s+.+(?:\n|$))+/m', function ($m) {
            $items = preg_split('/\n/', trim($m[0]));
            $out = '<ul>';
            foreach ($items as $it) {
                $it = preg_replace('/^\s*[-*+]\s+/', '', $it);
                if (trim($it) !== '') $out .= '<li>' . $it . '</li>';
            }
            return $out . '</ul>';
        }, $html);

        // ── 有序列表 ──
        $html = preg_replace_callback('/(?:^\s*\d+\.\s+.+(?:\n|$))+/m', function ($m) {
            $items = preg_split('/\n/', trim($m[0]));
            $out = '<ol>';
            foreach ($items as $it) {
                $it = preg_replace('/^\s*\d+\.\s+/', '', $it);
                if (trim($it) !== '') $out .= '<li>' . $it . '</li>';
            }
            return $out . '</ol>';
        }, $html);

        // ── 表格 ──
        $html = preg_replace_callback(
            '/^\s*\|(.+)\|\s*\n\s*\|[\s:|-]+\|\s*\n((?:\s*\|.+\|\s*\n?)+)/m',
            function ($m) {
                $headerCells = array_map('trim', explode('|', trim($m[1])));
                $out = '<table><thead><tr>';
                foreach ($headerCells as $c) $out .= '<th>' . $c . '</th>';
                $out .= '</tr></thead><tbody>';

                foreach (preg_split('/\n/', trim($m[2])) as $row) {
                    $cells = array_map('trim', explode('|', trim(trim($row), '|')));
                    if (!array_filter($cells)) continue;
                    $out .= '<tr>';
                    foreach ($cells as $c) $out .= '<td>' . $c . '</td>';
                    $out .= '</tr>';
                }
                return $out . '</tbody></table>';
            },
            $html
        );

        // ── 行内代码已在前面抽出，这里处理其余行内语法 ──

        // 粗体 **text** 或 __text__
        $html = preg_replace('/\*\*(?!\s)(.+?)(?<!\s)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/__(?!\s)(.+?)(?<!\s)__/s', '<strong>$1</strong>', $html);

        // 斜体 *text* 或 _text_（避开已处理的粗体）
        $html = preg_replace('/(?<!\*)\*(?!\s|\*)(.+?)(?<!\s|\*)\*(?!\*)/s', '<em>$1</em>', $html);
        $html = preg_replace('/(?<![_\w])_(?!\s)(.+?)(?<!\s)_(?![_\w])/s', '<em>$1</em>', $html);

        // 删除线 ~~text~~
        $html = preg_replace('/~~(?!\s)(.+?)(?<!\s)~~/s', '<del>$1</del>', $html);

        // 图片 ![alt](url)  —— 必须在链接之前
        $html = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
            $alt = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!lwRtSafeUrl($url)) {
                return $m[0];
            }
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                 . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8')
                 . '" class="lw-md-img" loading="lazy">';
        }, $html);

        // 链接 [text](url)
        $html = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ($m) {
            $text = $m[1];
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!lwRtSafeUrl($url)) {
                return $m[0];
            }
            $title = isset($m[3]) && $m[3] !== ''
                ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') . '"'
                : '';
            // 站外链接加 noopener，防止 window.opener 劫持
            $rel = preg_match('#^https?://#i', $url) ? ' rel="noopener nofollow" target="_blank"' : '';
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $title . $rel . '>'
                 . $text . '</a>';
        }, $html);

        return $html;
    }

    /**
     * 智能换行：块级标签前后不补 <br>，其余换行转 <br>。
     *
     * 原来直接 nl2br 会把 <h1>标题</h1>
正文 变成
     * <h1>标题</h1><br>正文，标题和正文之间多出一条空行。
     */
    function lwRtSmartBr(string $html): string
    {
        $blocks = 'p|div|h1|h2|h3|h4|h5|h6|blockquote|pre|table|thead|tbody|tr|ul|ol|li|hr';

        /* 第一步：把 <pre>...</pre> 整块抽出来保护。
         *
         * 【为什么必须整块保护】
         * 代码块里的换行是「代码的换行」，不是「排版的换行」。
         * 如果让 nl2br 处理它们，每行代码后面会多一个 <br>，
         * 而且复制代码时会把 <br> 一起复制走 —— 完全不能用。
         *
         * 之前试过只替换内容里的换行，但 nl2br 是在整段 HTML 上跑的，
         * 它不认识占位符，照样会处理 <pre> 标签内的其他换行。
         * 所以改成把整块先拿出来，nl2br 跑完再放回去。
         */
        $preBlocks = [];
        $html = preg_replace_callback('/<pre[^>]*>[\s\S]*?<\/pre>/i', function ($m) use (&$preBlocks) {
            $idx = count($preBlocks);
            $preBlocks[$idx] = $m[0];
            return "\x03PRE{$idx}\x03";
        }, $html);

        // 块级标签后紧跟的换行：删掉
        $html = preg_replace('/<\/(' . $blocks . ')>\s*\n+/i', '</$1>', $html);
        // 块级标签前紧挨的换行：删掉
        $html = preg_replace('/\n+\s*(<' . $blocks . ')/i', '$1', $html);

        // 剩余换行 → <br>
        $html = nl2br($html);

        // 放回 <pre> 块
        $html = preg_replace_callback('/\x03PRE(\d+)\x03/', function ($m) use ($preBlocks) {
            $i = (int)$m[1];
            return $preBlocks[$i] ?? '';
        }, $html);

        return $html;
    }

    /**
     * 提取纯文本摘要（用于列表页、搜索、通知预览）。
     * 剥掉所有标签与 Markdown 标记。
     */
    function lwRichPlain(?string $raw, int $limit = 120): string
    {
        $s = (string)$raw;
        if ($s === '') return '';

        // 去掉代码块（摘要里不需要）
        $s = preg_replace('/^```[\s\S]*?^```\s*$/m', ' ', $s);
        // 图片 / 链接：只留文字
        $s = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $s);
        $s = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $s);
        // HTML 标签
        $s = preg_replace('/<[^>]*>/', ' ', $s);
        // Markdown 标记
        $s = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $s);
        $s = preg_replace('/[*_~`>|]/', '', $s);
        // 压缩空白
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);

        if (mb_strlen($s) > $limit) {
            $s = mb_substr($s, 0, $limit) . '…';
        }
        return $s;
    }

    /**
     * 统计正文字数（不含 Markdown 标记与 HTML 标签）。
     * 用于发帖时的字数提示与「字数上限」校验。
     */
    function lwRichWordCount(?string $raw): int
    {
        return mb_strlen(lwRichPlain($raw, PHP_INT_MAX), 'UTF-8');
    }

    /**
     * 判断一段正文是否包含 Markdown 或 HTML 语法。
     * 用于在编辑框上方提示「已检测到 Markdown 语法」。
     */
    function lwRichHasMarkup(?string $raw): bool
    {
        $s = (string)$raw;
        if ($s === '') return false;
        return (bool)preg_match(
            '/(^#{1,6}\s)|(\*\*[^*]+\*\*)|(^\s*[-*+]\s)|(^\s*\d+\.\s)|'
            . '(^&gt;\s)|(<\/?[a-z][a-z0-9]*[^>]*>)|(^\|.+\|)/m',
            $s
        );
    }

    /**
     * 提取正文里的第一张图片 URL（用于列表页缩略图）。
     * 支持 Markdown 图片语法和 <img> 标签。
     */
    function lwRichFirstImage(?string $raw): string
    {
        $s = (string)$raw;
        if ($s === '') return '';

        // Markdown 图片
        if (preg_match('/!\[[^\]]*\]\(([^)\s]+)/', $s, $m)) {
            $url = $m[1];
            if (lwRtSafeUrl($url) && strpos($url, '/uploads/') !== false) {
                return $url;
            }
        }
        // <img src="...">
        if (preg_match('/<img[^>]+src=["\']([^"\']+)/i', $s, $m)) {
            $url = $m[1];
            if (lwRtSafeUrl($url) && strpos($url, '/uploads/') !== false) {
                return $url;
            }
        }
        return '';
    }

    /**
     * 正文长度校验（服务端权威）。
     *
     * 与 lwRichWordCount 不同：这里按「原始长度」算，
     * 因为 Markdown 标记也是用户敲出来的，不该完全不算数。
     */
    function lwRichLengthOk(?string $raw, int $min, int $max): array
    {
        $len = mb_strlen((string)$raw, 'UTF-8');
        if ($len < $min) {
            return ['ok' => false, 'message' => '内容至少需要 ' . $min . ' 个字（当前 ' . $len . '）'];
        }
        if ($len > $max) {
            return ['ok' => false, 'message' => '内容最多 ' . $max . ' 个字（当前 ' . $len . '）'];
        }
        return ['ok' => true, 'message' => ''];
    }
}
