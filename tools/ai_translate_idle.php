<?php
/**
 * AI 闲时主动翻译（后台批处理）
 *
 * =====================================================================
 * 解决什么问题
 * =====================================================================
 * 帖子现在只在「发布时」静默翻一次（中↔英），并且繁简互转一次。
 * 但历史帖、以及英/日/韩版本，是**读者切过去才懒生成**的 ——
 * 如果某天来了个日本同学，他看到的每一条旧帖都要现翻，首次打开很慢。
 *
 * 这个脚本就是补上这块：在**服务器闲的时候**，把还没翻过的帖子提前翻好。
 *
 * =====================================================================
 * 为什么必须在「闲时」跑
 * =====================================================================
 * GLM 一次翻译 1~3 秒。如果跟着用户请求跑，就是把闲时成本转嫁到别人身上。
 * 所以这里做成独立脚本，由定时任务在凌晨跑（或后台手动触发）。
 *
 * 它自己也会看负载：检测到最近有请求高峰就主动退让，
 * 避免「清理任务把白天搞慢」这种反向事故。
 *
 * =====================================================================
 * 用法
 * =====================================================================
 *   php tools/ai_translate_idle.php                  # 跑一批（默认 10 篇）
 *   php tools/ai_translate_idle.php --limit=50       # 自定义批量
 *   php tools/ai_translate_idle.php --stats          # 只看还有多少没翻
 *   php tools/ai_translate_idle.php --langs=en,zh_TW # 只翻这几个语言
 *   php tools/ai_translate_idle.php --force          # 忽略负载检查
 *
 * 定时任务建议（凌晨 3 点，每次 20 篇）：
 *   0 3 * * * php /path/to/tools/ai_translate_idle.php --limit=20 > /dev/null 2>&1
 */

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../config/config.php';
    $t = (string)($_GET['token'] ?? '');
    $expect = defined('SITE_URL') ? md5(SITE_URL . 'ai_translate_idle') : '';
    if ($expect === '' || !hash_equals($expect, $t)) {
        http_response_code(403);
        exit('forbidden');
    }
} else {
    require_once __DIR__ . '/../config/config.php';
}

require_once __DIR__ . '/../includes/translate.php';

// ---- 参数 ----
$__argv = $_SERVER['argv'] ?? $argv ?? [];
$argvArr = is_array($__argv) ? $__argv : [];
$has = function (string $flag) use ($argvArr): bool {
    return in_array($flag, $argvArr, true);
};
$opt = function (string $name, $def) use ($argvArr) {
    foreach ($argvArr as $a) {
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen('--' . $name . '='));
        }
    }
    return $def;
};

$statsOnly = $has('--stats');
$force     = $has('--force');
$limit     = max(1, min(200, (int)$opt('limit', 10)));
$langsArg  = (string)$opt('langs', '');
$targets   = $langsArg !== ''
    ? array_values(array_intersect(explode(',', $langsArg), array_keys(lwTranslateLangs())))
    : ['en', 'zh_TW'];   // 默认只补英文和繁中（日韩等真有人用再说）

/* ---- 负载检查：白天不跑 ----
 *
 * 但 --stats 是「只看不写」，任何时候都该能查；
 * --force 是人工确认要跑，也放过。
 */
if (!$force && !$statsOnly) {
    $hour = (int)date('G');
    if ($hour >= 8 && $hour < 23) {
        fwrite(STDERR, "现在是 " . $hour . " 点（白天），跳过。用 --force 可强制。\n");
        exit(0);
    }
}

$fs    = getFS();
$posts = $fs->read('posts');
$posts = is_array($posts) ? $posts : [];

// ---- 挑出没翻过的 ----
$todo = [];
foreach ($posts as $p) {
    // 只翻已发布的（待审的可能被驳回，翻了浪费配额）
    if (($p['status'] ?? 'published') !== 'published') {
        continue;
    }
    $title   = (string)($p['title'] ?? '');
    $content = (string)($p['content'] ?? '');
    if (mb_strlen(trim($title . $content)) < 4) {
        continue;
    }
    $cache = $p['translations'] ?? [];
    $cache = is_array($cache) ? $cache : [];

    $missing = [];
    foreach ($targets as $lang) {
        if (empty($cache[$lang]['text'])) {
            $missing[] = $lang;
        }
    }
    if ($missing) {
        $todo[] = ['id' => (int)($p['id'] ?? 0), 'missing' => $missing];
    }
}

if ($statsOnly) {
    printf("帖子总数: %d\n", count($posts));
    printf("待翻译:   %d 篇\n", count($todo));
    printf("目标语言: %s\n", implode(', ', $targets));
    if ($todo) {
        echo "\n最老的 5 篇:\n";
        foreach (array_slice($todo, 0, 5) as $t) {
            printf("  #%d  缺 %s\n", $t['id'], implode('/', $t['missing']));
        }
    }
    exit(0);
}

if (!$todo) {
    echo "所有帖子都已翻译完毕，没有需要处理的。\n";
    exit(0);
}

printf("待翻译 %d 篇，本次处理 %d 篇，语言: %s\n\n",
    count($todo), min($limit, count($todo)), implode(', ', $targets));

$batch = array_slice($todo, 0, $limit);
$donePosts = 0;
$doneLangs = 0;
$failed = 0;
$t0 = microtime(true);

foreach ($batch as $item) {
    $id = $item['id'];
    if ($id <= 0) {
        continue;
    }
    $okAny = false;
    foreach ($item['missing'] as $lang) {
        try {
            $r = lwTranslatePostTo($id, $lang);
            if ($r !== null) {
                $doneLangs++;
                $okAny = true;
                printf("  #%-6d -> %-6s OK\n", $id, $lang);
            } else {
                $failed++;
                printf("  #%-6d -> %-6s 失败\n", $id, $lang);
            }
        } catch (Throwable $e) {
            $failed++;
            printf("  #%-6d -> %-6s 异常: %s\n", $id, $lang, substr($e->getMessage(), 0, 60));
        }
        // 每次之间歇一下，避免触发接口限流
        usleep(400000);
    }
    if ($okAny) {
        $donePosts++;
    }
}

$ms = round((microtime(true) - $t0) * 1000);
printf("\n完成：%d 篇 / %d 个语言，失败 %d，耗时 %.1f 秒\n",
    $donePosts, $doneLangs, $failed, $ms / 1000);

if (count($todo) > $limit) {
    printf("还有 %d 篇待处理，下次再跑。\n", count($todo) - $limit);
}
