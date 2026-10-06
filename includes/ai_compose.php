<?php
/**
 * 发帖「智能成稿」——提示词与结果解析的唯一来源。
 *
 * 单独成文件的理由同 ai_persona.php：提示词是最需要被单测的东西
 * （分类有没有注入、字数与格式约束有没有写全、禁止项有没有漏），抽成纯函数后可直接断言。
 *
 * 安全前提：本文件的**所有**输入都来自服务端白名单（分类名、站点名），
 * 用户提供的只是「素材」文本，且会在进模型前做长度截断与标签中和。
 */

if (!defined('LW_COMPOSE_TITLE_MAX')) {
    define('LW_COMPOSE_TITLE_MAX', 24);   // 期望标题长度（给模型的软约束）
}
if (!defined('LW_COMPOSE_MIN_CHARS')) {
    define('LW_COMPOSE_MIN_CHARS', 120);  // 正文下限
}
if (!defined('LW_COMPOSE_MAX_CHARS')) {
    define('LW_COMPOSE_MAX_CHARS', 400);  // 正文上限（表单允许 5000，这里只求够用）
}

/**
 * 站点支持的帖子分类（与服务端 posts.category 白名单一致）。
 * 刻意不在本文件依赖 pages/post.php，避免把页面逻辑带进 API。
 */
function aiComposeCategories(): array {
    return [
        'lost_found'  => '寻物/失物招领',
        'study_help'  => '学习求助',
        'social_chat' => '交友闲聊',
        'confession'  => '表白',
        'school_info' => '校园打听',
        'other'       => '其他',
    ];
}

/**
 * 拼装「成稿」系统提示词。
 *
 * @param array $ctx [
 *   'site_name'    => string
 *   'category_key' => string  已白名单化的分类 key
 *   'nickname'     => string
 * ]
 */
function aiComposeSystemPrompt(array $ctx): string {
    $siteName = trim((string)($ctx['site_name'] ?? '')) ?: '校园交流墙';
    $catKey = (string)($ctx['category_key'] ?? '');
    $cats = aiComposeCategories();
    $catName = $cats[$catKey] ?? '';
    $nickname = trim((string)($ctx['nickname'] ?? ''));

    $L = [];
    $L[] = '你是「' . $siteName . '」的站内写作助手，只做一件事：把同学给的零散素材，整理成一条可以直接发布的帖子。';
    $L[] = '你服务的是在校学生，说话像靠谱的学长学姐，不端架子、不讨好、不煽情。';
    $L[] = '';
    $L[] = '【硬性要求】';
    $L[] = '- 只输出一个 JSON 对象，不要 Markdown 代码围栏，不要任何解释文字。格式：{"title":"...","content":"..."}';
    $L[] = '- title：不超过 ' . LW_COMPOSE_TITLE_MAX . ' 个字，具体、有信息量，不要用「关于……的通知」这类空壳标题，不要书名号。';
    $L[] = '- content：' . LW_COMPOSE_MIN_CHARS . '～' . LW_COMPOSE_MAX_CHARS . ' 字，纯文本，允许自然换行分段。';
    $L[] = '- 禁止出现：Markdown 标记（#、**、>）、HTML 标签、链接地址、表情符号堆砌。';
    $L[] = '- 禁止出现：任何「作为 AI」「根据你的要求」「以下是我为你写的」这类自称与交代。';
    $L[] = '- 禁止编造：地点、时间、金额、联系方式、姓名、学号。素材里没有的信息，要么留空让人自己填（写成「在XX楼」这种占位提示），要么不提。';
    $L[] = '- 保留素材里的关键事实（物品、时间、地点、需求），只做条理化和口语化整理，不要改变原意。';
    $L[] = '';
    $L[] = '【写作方式】';
    $L[] = '- 按分类调整语气：';
    $L[] = '  · 寻物/失物招领：说清物品特征、丢失/拾获的时间地点、怎么联系，语气着急但有条理。';
    $L[] = '  · 学习求助：说清卡在哪、已经试过什么、具体需要哪一步的讲解。';
    $L[] = '  · 交友闲聊：轻松自然，像跟同学说话，不要用力找人。';
    $L[] = '  · 表白：真诚克制，不油腻不堆砌形容词；不写具体的姓名与班级，用「你」即可。';
    $L[] = '  · 校园打听：把问题问具体，说明为什么要问、希望得到哪类信息。';
    $L[] = '  · 其他：按素材本身的调性来。';
    $L[] = '- 开头直接说事，不要「大家好」之类的铺垫；结尾自然收束，不要喊口号。';
    $L[] = '- 不要写标题党的夸张表达，不要用「震惊」「必看」「速转」。';
    $L[] = '- 不写广告、不拉人进群、不出现违规内容（代考、代写、买卖账号、攻击他人等）；如果素材本身是这个方向，就把内容改写成正当表达（例如把「代写作业」改成「求学习经验分享」）。';
    $L[] = '';
    $L[] = '【本次上下文】';
    $L[] = '- 站点：' . $siteName;
    if ($catName !== '') {
        $L[] = '- 已选分类：' . $catName . '（成稿必须贴合这个分类）';
    } else {
        $L[] = '- 未选分类：请让语气保持中性，适合任何分类。';
    }
    if ($nickname !== '') {
        $L[] = '- 发帖人昵称：' . $nickname . '（正文中不要自称这个昵称，除非素材里明确要求署名）';
    }

    return implode("\n", $L);
}

/**
 * 拼装用户消息：把用户的零散素材包成一段，并中和掉可能干扰解析的标签。
 */
function aiComposeUserMessage(string $idea): string {
    $idea = trim($idea);
    // 防注入：素材里若混入动作标签或伪系统指令分隔符，先中和
    $idea = str_replace(['<lw-action>', '</lw-action>', 'LW_DATA_START', 'LW_DATA_END'], '［已屏蔽］', $idea);
    return "请把下面的素材整理成一条帖子：\n---\n" . $idea . "\n---";
}

/**
 * 解析模型输出为 ['title'=>..., 'content'=>...]，失败返回 null。
 * 宽容处理：去掉代码围栏、抽取第一个 { ... } 块、兼容 title/content 的常见别名。
 */
function aiParseComposeResult(string $raw): ?array {
    $t = trim($raw);
    if ($t === '') {
        return null;
    }
    // 去掉 ```json ... ``` 围栏
    $t = preg_replace('/^```[a-zA-Z]*\s*/', '', $t);
    $t = preg_replace('/\s*```$/', '', (string)$t);
    $t = trim((string)$t);

    $data = json_decode($t, true);
    if (!is_array($data)) {
        // 兜底：截取第一个花括号块（模型偶尔会在前后带一句客套话）
        $s = strpos($t, '{');
        $e = strrpos($t, '}');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($t, $s, $e - $s + 1), true);
        }
    }
    if (!is_array($data)) {
        return null;
    }

    $title = '';
    foreach (['title', '标题'] as $k) {
        if (isset($data[$k]) && is_string($data[$k])) { $title = $data[$k]; break; }
    }
    $content = '';
    foreach (['content', '正文', 'body', 'text'] as $k) {
        if (isset($data[$k]) && is_string($data[$k])) { $content = $data[$k]; break; }
    }

    $title = aiComposeCleanText($title, 200);
    $content = aiComposeCleanText($content, 5000);
    if ($title === '' && $content === '') {
        return null;
    }
    return ['title' => $title, 'content' => $content];
}

/**
 * 清理成稿文本：去标签、去代码标记、压缩空行、限长。
 * 说明：这里只做「减法」，不信任模型输出的任何格式——正文最终仍由前端 esc 后展示。
 */
function aiComposeCleanText(string $s, int $maxChars): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = strip_tags($s);
    $s = str_replace(['<lw-action>', '</lw-action>'], '', $s);
    // 去掉常见的 Markdown 强调与标题标记（保留正文文字）
    $s = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/su', '$1', $s);
    $s = preg_replace('/^>\s?/mu', '', $s);
    // 去掉链接语法，只留文字：[文字](url) -> 文字
    $s = preg_replace('/\[([^\]]*)\]\((?:[^)]*)\)/u', '$1', $s);
    // 连续超过两个换行压成一个空行，首尾空白去掉
    $s = preg_replace('/\n{3,}/u', "\n\n", $s);
    $s = preg_replace('/[ \t]+$/mu', '', $s);
    $s = trim($s);
    if (mb_strlen($s) > $maxChars) {
        $s = mb_substr($s, 0, $maxChars);
    }
    return $s;
}
