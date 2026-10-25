<?php
/**
 * 帖子静默翻译
 *
 * =====================================================================
 * 【需求】发布时自动翻译，不提示用户
 * =====================================================================
 *
 * 场景：有外国同学/外语内容时，中文用户看不懂，反之亦然。
 * 做法：发布成功后，后台异步把标题+正文译成目标语言，存到帖子的
 * translation 字段里，详情页按需展示，**整个过程用户无感**。
 *
 * =====================================================================
 * 【为什么不阻塞发布】
 * =====================================================================
 * AI 调用要 800~1500ms。如果同步等它，用户点「发布」后要愣一秒多，
 * 这在移动端是非常明显的卡顿。所以：
 *   1. 先返回发布成功（用户立刻看到自己的帖子）
 *   2. 用 register_shutdown_function 在响应发出后继续跑翻译
 *   3. 翻译结果回写，下次打开详情就能看到
 *
 * 对 PHP 来说 shutdown function 里仍可继续执行（fastcgi_finish_request
 * 能让响应提前结束，但共享主机不一定有；没有的话最多让用户多等 1 秒，
 * 也比阻塞在关键路径上强）。
 *
 * =====================================================================
 * 【语言判定】
 * =====================================================================
 * 不调 AI 判语言（多一次请求），用字符统计：
 *   中文字符占比 > 30%  → 中文，译成英文
 *   拉丁字母占比 > 70%  → 英文，译成中文
 *   其余（日文/韩文/混合）→ 译成中文（本站主语言）
 */

if (!defined('LW_TRANSLATE_LOADED')) {
    define('LW_TRANSLATE_LOADED', 1);

    /** 估算文本的主语言。返回 zh / en / other */
    function lwDetectLang(string $text): string
    {
        $text = trim($text);
        if ($text === "") {
            return "other";
        }
        $len = mb_strlen($text, "UTF-8");
        if ($len === 0) {
            return "other";
        }
        $cjk = preg_match_all("/[\x{4e00}-\x{9fff}]/u", $text);
        $latin = preg_match_all("/[a-zA-Z]/u", $text);
        $cjkRatio = $cjk / $len;
        $latinRatio = $latin / $len;
        if ($cjkRatio > 0.30) {
            return "zh";
        }
        if ($latinRatio > 0.70) {
            return "en";
        }
        return "other";
    }

    /**
     * 调用 AI 翻译一段文本。
     *
     * 失败返回 null（调用方静默放弃，不影响任何功能）。
     */
    function lwTranslateText(string $text, string $from, string $to): ?string
    {
        if ($text === "" || !function_exists("aiChatCompletion") || !function_exists("getAIConfig")) {
            return null;
        }
        try {
            $cfg = getAIConfig();
        } catch (Throwable $e) {
            return null;
        }
        if (empty($cfg["enabled"]) || empty($cfg["api_key"])) {
            return null;
        }

        $fromName = $from === "zh" ? "中文" : ($from === "en" ? "英文" : "原文");
        $toName = $to === "zh" ? "中文" : "英文";

        // 长文本截断：帖子正文上限 5000 字，全译会让 token 爆掉且慢
        $body = mb_substr($text, 0, 1200);

        $system = "你是翻译引擎。把用户给的" . $fromName . "翻译成" . $toName . "。"
            . "只输出译文本身，不要任何解释、不要引号、不要原文。"
            . "保留原有的换行与段落结构。专有名词（人名、地名、校名）音译或保留原文。";

        $messages = [
            ["role" => "system", "content" => $system],
            ["role" => "user", "content" => $body],
        ];

        try {
            $resp = aiChatCompletion($cfg, $messages, 20);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($resp)) {
            return null;
        }
        $out = trim((string)($resp["content"] ?? $resp["text"] ?? ""));
        // 去掉模型偶尔加的代码块包裹与 <think> 残留
        $out = preg_replace("/^```[a-z]*\s*|\s*```$/i", "", $out);
        $out = preg_replace("/<think>[\s\S]*?<\/think>/i", "", $out);
        $out = trim((string)$out);
        if ($out === "" || mb_strlen($out) < 2) {
            return null;
        }
        return $out;
    }

    /**
     * 为一条帖子生成翻译并回写。
     *
     * 设计成「可以被 shutdown function 延迟调用」，所以参数只传 id，
     * 函数内部重新读帖子（避免把整个数组序列化进闭包）。
     */
    function lwTranslatePost(int $postId): void
    {
        try {
            $fs = getFS();
            $post = $fs->findById("posts", $postId);
            if (!$post) {
                return;
            }
            // 已有翻译就不重复翻（省配额）
            if (!empty($post["translation"]["text"])) {
                return;
            }

            $title = (string)($post["title"] ?? "");
            $content = (string)($post["content"] ?? "");
            $joined = trim($title . "
" . $content);
            if (mb_strlen($joined) < 6) {
                return;
            }

            $from = lwDetectLang($joined);
            // 中文站：非中文译成中文，中文译成英文（给外国同学看）
            $to = ($from === "zh") ? "en" : "zh";

            $tTitle = $title !== "" ? lwTranslateText($title, $from, $to) : null;
            $tBody = lwTranslateText($content, $from, $to);
            if ($tBody === null && $tTitle === null) {
                return;   // 翻译失败，什么都不写
            }

            $fs->update("posts", $postId, [
                "translation" => [
                    "from" => $from,
                    "to" => $to,
                    "title" => $tTitle ?? "",
                    "text" => $tBody ?? "",
                    "at" => date("Y-m-d H:i:s"),
                ],
            ]);
        } catch (Throwable $e) {
            // 翻译是锦上添花，任何异常都必须静默吞掉
            error_log("lwTranslatePost: " . $e->getMessage());
        }
    }

    /* ==========================================================================
     * AI 静默主动翻译（多语言覆盖）
     * --------------------------------------------------------------------------
     * 【和原来的区别】
     * 原来只做「中 ↔ 英」对向翻译：中文帖译成英文，外文帖译成中文。
     * 但实际使用里还有两种情况：
     *   1. 繁体用户 —— 简体帖对他们是「能看懂但别扭」，也该给个繁中版
     *   2. 日韩同学 —— 英文未必比母语好懂
     *
     * 现在改成「常见语言全覆盖」：
     *   - 原文语言识别后，按需生成简体 / 繁体 / 英文 / 日文 / 韩文
     *   - **懒生成**：不是一次性全翻（那样 5 倍配额），而是谁真的切到那个语言才翻
     *   - 全都走异步队列，用户无感
     *
     * 【为什么不预生成全部】
     * GLM 一次翻译约 1~3 秒。一篇文章翻 5 种语言就是 5~15 秒配额。
     * 而绝大多数读者只用一种语言 —— 预生成等于给 80% 用不上的内容付费。
     * 懒生成把成本压到「实际被阅读的语言数」。
     * ==========================================================================
     */

    /** 支持的目标语言（顺序即优先级）。 */
    function lwTranslateLangs(): array
    {
        return [
            'zh_CN' => '简体中文',
            'zh_TW' => '繁體中文',
            'en'    => 'English',
            'ja'    => '日本語',
            'ko'    => '한국어',
        ];
    }

    /** 站点语言 → 翻译目标语言。 */
    function lwSiteLangToTarget(string $siteLang): string
    {
        $map = [
            'zh_CN' => 'zh_CN',
            'zh_TW' => 'zh_TW',
            'zh'    => 'zh_CN',
            'en'    => 'en',
            'ja'    => 'ja',
            'ko'    => 'ko',
        ];
        return $map[$siteLang] ?? 'zh_CN';
    }

    /**
     * 按需翻译成指定语言并缓存。
     *
     * @param int    $postId 帖子 ID
     * @param string $target 目标语言（zh_CN / zh_TW / en / ja / ko）
     * @return array|null 翻译结果，失败返回 null
     */
    function lwTranslatePostTo(int $postId, string $target): ?array
    {
        if ($postId <= 0) {
            return null;
        }
        $allowed = array_keys(lwTranslateLangs());
        if (!in_array($target, $allowed, true)) {
            return null;
        }

        try {
            $fs = getFS();
            $post = $fs->findById('posts', $postId);
            if (!$post) {
                return null;
            }

            // 已有该语言缓存 → 直接返回
            $cache = $post['translations'] ?? [];
            if (is_array($cache) && !empty($cache[$target]['text'])) {
                return $cache[$target];
            }

            $title   = (string)($post['title'] ?? '');
            $content = (string)($post['content'] ?? '');
            $joined  = trim($title . "\n" . $content);
            if (mb_strlen($joined) < 4) {
                return null;
            }

            $from = lwDetectLang($joined);

            // 原文就是目标语言 → 不需要翻译，直接当缓存写回
            $fromTarget = lwSiteLangToTarget($from === 'zh' ? 'zh_CN' : $from);
            if ($fromTarget === $target) {
                $self = [
                    'title' => $title,
                    'text'  => $content,
                    'from'  => $target,
                    'to'    => $target,
                    'at'    => date('Y-m-d H:i:s'),
                    'self'  => true,
                ];
                $cache[$target] = $self;
                $fs->update('posts', $postId, ['translations' => $cache]);
                return $self;
            }

            $tTitle = $title !== '' ? lwTranslateText($title, $from, $target) : null;
            $tBody  = lwTranslateText($content, $from, $target);
            if ($tBody === null && $tTitle === null) {
                return null;
            }

            $entry = [
                'title' => (string)($tTitle ?? ''),
                'text'  => (string)($tBody ?? ''),
                'from'  => $from,
                'to'    => $target,
                'at'    => date('Y-m-d H:i:s'),
            ];
            $cache[$target] = $entry;
            $fs->update('posts', $postId, ['translations' => $cache]);

            return $entry;
        } catch (Throwable $e) {
            error_log('lwTranslatePostTo #' . $postId . ' -> ' . $target . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 静默预翻「另一种中文」。
     *
     * 简体用户发的帖子，繁体用户点进来会读得别扭。
     * 中文↔中文互转很快（同语系），且几乎一半读者会受益，
     * 所以这一对值得预生成，其余的懒生成就行。
     */
    function lwQueueZhVariant(int $postId): void
    {
        static $queued = [];
        if ($postId <= 0 || isset($queued[$postId])) {
            return;
        }
        $queued[$postId] = true;

        register_shutdown_function(function () use ($postId) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @ignore_user_abort(true);
            @set_time_limit(25);

            try {
                $fs = getFS();
                $post = $fs->findById('posts', $postId);
                if (!$post) {
                    return;
                }
                $joined = trim((string)($post['title'] ?? '') . "\n" . (string)($post['content'] ?? ''));
                if (mb_strlen($joined) < 4) {
                    return;
                }
                $from = lwDetectLang($joined);
                if ($from !== 'zh') {
                    return;   // 只有中文帖才需要繁简互转
                }
                // 判断是简体还是繁体
                $hasTW = preg_match('/[體臺灣繁體們個時間說話來對這個]/u', $joined);
                $target = $hasTW ? 'zh_CN' : 'zh_TW';
                lwTranslatePostTo($postId, $target);
            } catch (Throwable $e) {
                error_log('lwQueueZhVariant #' . $postId . ': ' . $e->getMessage());
            }
        });
    }


    /**
     * 排一个延迟翻译任务（响应发出后执行）。
     *
     * 用 shutdown function 而不是队列：共享主机没有常驻进程，
     * 也没有 cron 权限。shutdown 是唯一「请求结束后还能干活」的时机。
     */
    function lwQueueTranslation(int $postId): void
    {
        if ($postId <= 0) {
            return;
        }
        static $queued = [];
        if (isset($queued[$postId])) {
            return;
        }
        $queued[$postId] = true;

        register_shutdown_function(function () use ($postId) {
            // fastcgi 环境下先让响应真正发出去，再跑翻译
            if (function_exists("fastcgi_finish_request")) {
                @fastcgi_finish_request();
            }
            lwTranslatePost($postId);
        });
    }

    /* ==========================================================================
     * 按用户当前语言取帖子内容（切换语言时自动翻译）
     * --------------------------------------------------------------------------
     * 场景：站点支持 zh_CN / zh_TW / en 三种界面语言。
     *       但帖子内容是用户写的，不会跟着界面语言变。
     *       英文用户点进来看到一片中文 —— 这就是要解决的问题。
     *
     * 做法：
     *   1. 帖子发布时已静默生成了一份「对向语言」翻译（zh<->en）
     *   2. 读者切换语言后，lwPostForLang() 按需返回对应语言的版本
     *   3. 若目标语言没有缓存，按需翻一次并写回（只翻一次，后续命中缓存）
     *
     * 语言对应关系：
     *   帖子原文 lang=zh  → zh_CN / zh_TW 直接显示原文，en 用翻译
     *   帖子原文 lang=en  → en 显示原文，zh_CN / zh_TW 用翻译
     *   其它（日文/混合）→ 一律用翻译
     * ========================================================================== */

    /** 把站点语言码映射成翻译用的粗语言（zh_CN/zh_TW → zh）。 */
    function lwLangBucket(string $siteLang): string
    {
        if (strpos($siteLang, "zh") === 0) {
            return "zh";
        }
        if ($siteLang === "en") {
            return "en";
        }
        return "other";
    }

    /**
     * 取一条帖子在指定语言下的标题与正文。
     *
     * @param array  $post     帖子记录
     * @param string $siteLang 当前界面语言（zh_CN / zh_TW / en）
     * @return array{title:string, content:string, translated:bool, from:string, to:string}
     */
    function lwPostForLang(array $post, string $siteLang): array
    {
        $title = (string)($post["title"] ?? "");
        $content = (string)($post["content"] ?? "");

        $orig = (string)($post["translation"]["from"] ?? lwDetectLang($title . "\n" . $content));
        $target = lwLangBucket($siteLang);

        // 原文语言与目标一致 → 直接用原文（不需要翻译）
        if ($orig === $target) {
            return ["title" => $title, "content" => $content,
                    "translated" => false, "from" => $orig, "to" => $target];
        }

        // 已有翻译且方向匹配
        $t = $post["translation"] ?? null;
        if (is_array($t) && !empty($t["text"]) && ($t["to"] ?? "") === $target) {
            return [
                "title" => ($t["title"] ?? "") !== "" ? $t["title"] : $title,
                "content" => $t["text"],
                "translated" => true,
                "from" => $orig,
                "to" => $target,
            ];
        }

        // 没有缓存：按需翻一次（同步。因为这是用户主动切换语言后的首次浏览，
        // 慢一点可以接受，且翻完就缓存了，后续都是瞬间返回）
        $toMap = ["zh" => "zh", "en" => "en", "other" => "zh"];
        $want = $toMap[$target] ?? "zh";
        $tTitle = $title !== "" ? lwTranslateText($title, $orig === "other" ? "zh" : $orig, $want) : null;
        $tBody = lwTranslateText($content, $orig === "other" ? "zh" : $orig, $want);
        if ($tBody === null && $tTitle === null) {
            return ["title" => $title, "content" => $content,
                    "translated" => false, "from" => $orig, "to" => $orig];
        }

        // 写回缓存
        try {
            getFS()->update("posts", (int)($post["id"] ?? 0), [
                "translation" => [
                    "from" => $orig,
                    "to" => $want,
                    "title" => $tTitle ?? "",
                    "text" => $tBody ?? "",
                    "at" => date("Y-m-d H:i:s"),
                ],
            ]);
        } catch (Throwable $e) {
        }

        return [
            "title" => $tTitle ?? $title,
            "content" => $tBody ?? $content,
            "translated" => true,
            "from" => $orig,
            "to" => $want,
        ];
    }

}
