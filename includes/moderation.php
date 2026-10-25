<?php
/**
 * 内容审核流水线（三层）
 * =====================================================================
 *
 * 【设计思路】
 *
 * 需求是「AI 先审 -> 不过进关键词 -> 再移交后台，并标明可能违规的类型」。
 * 如果严格串行（AI 挂掉就整条链卡住），线上会立刻出问题 —— 免费主机的
 * AI 接口经常超时。所以做成**并行打分 + 分级决策**：
 *
 *   1. AI 审阅    语义判断，能识别阴阳怪气与变体谐音
 *   2. 关键词规则 确定性匹配，硬违规零漏判
 *   3. 结构规则   格式与频率，AI 和关键词都做不了的判断
 *
 * 三者各自打分，合并后按阈值决定：放行 / 标记待查 / 待审 / 拒绝。
 *
 * 【为什么这样拆】
 *   - AI 慢（1~5 秒）且可能失败，但能理解语义；
 *   - 关键词快（<1ms）且确定性，对硬违规零漏判；
 *   - 两者互补：AI 挂了关键词照样兜住，关键词漏了 AI 补上。
 *
 * 【风险分级】
 *   score 0~100，越高越危险：
 *     0-29   pass     直接发布
 *     30-59  review   已发布，但标记待查（后台可见）
 *     60-84  pending  暂不发布，等人工
 *     85+    reject   直接拒绝（仅限确定性硬规则）
 *
 * 关键原则：**AI 永远没有「直接拒绝」的权力**。
 * 误杀一条真实帖子的代价远大于漏放一条 —— 漏放由关键词和人工补，
 * 误杀会让用户直接流失。所以 AI 只能把内容推到 review/pending。
 */

if (!defined('LW_MODERATION_LOADED')) {
    define('LW_MODERATION_LOADED', 1);

    /** 违规类型定义（后台与前端共用同一套标签）。 */
    function lwViolationTypes(): array
    {
        return [
            'porn'      => ['label' => '色情低俗', 'color' => '#d92633'],
            'violence'  => ['label' => '暴力血腥', 'color' => '#c0392b'],
            'politics'  => ['label' => '政治敏感', 'color' => '#8e44ad'],
            'abuse'     => ['label' => '辱骂攻击', 'color' => '#e67e22'],
            'ad'        => ['label' => '广告营销', 'color' => '#f39c12'],
            'fraud'     => ['label' => '诈骗欺诈', 'color' => '#c0392b'],
            'privacy'   => ['label' => '隐私泄露', 'color' => '#16a085'],
            'illegal'   => ['label' => '违法信息', 'color' => '#7f1d1d'],
            'self_harm' => ['label' => '自伤自杀', 'color' => '#d92633'],
            'bullying'  => ['label' => '校园欺凌', 'color' => '#e67e22'],
            'spam'      => ['label' => '灌水刷屏', 'color' => '#95a5a6'],
            'other'     => ['label' => '其他可疑', 'color' => '#718096'],
        ];
    }
}

/**
 * 第 2 层：关键词与正则规则。
 *
 * 每条规则给一个权重，命中后累加。这样「轻微脏话 + 广告链接」
 * 会叠加成高分，而单独一项轻微问题不会误杀。
 *
 * @return array{score:int, hits:array}
 */
function lwKeywordScan(string $text): array
{
    $text = mb_strtolower($text, 'UTF-8');
    $hits = [];
    $score = 0;

    /* 权重设计：
     *   60+   单个命中就足以拦下（硬违规）
     *   25~40 需要叠加
     *   <20   仅作参考，累计才生效
     */
    $rules = [
        ['/(约炮|一夜情|包养|楼凤|上门服务|特殊服务|全套服务)/u', 'porn', 70, '色情交易'],
        ['/(裸聊|裸照|炮友|性服务|成人片|黄色网站)/u', 'porn', 60, '色情内容'],
        ['/(刷单|返利|日赚|躺赚|稳赚|包赔|带你赚钱|投资群)/u', 'fraud', 70, '诈骗话术'],
        ['/(加.{0,4}(微信|qq|vx|v信).{0,6}(赚|兼职|日结))/u', 'fraud', 65, '引流诈骗'],
        ['/(点击链接.{0,6}(领|取|兑|奖)|扫码.{0,4}(领|送))/u', 'fraud', 55, '钓鱼引流'],
        ['/(代考|替考|枪手|答案出售|真题出售)/u', 'illegal', 70, '作弊服务'],
        ['/(代写|代做|论文代)/u', 'illegal', 45, '代写代做'],
        ['/(出售|贩卖).{0,6}(账号|身份|身份证|银行卡|手机卡)/u', 'illegal', 75, '倒卖证件'],
        ['/1[3-9]\d{9}/', 'privacy', 40, '手机号'],
        ['/\d{17}[\dXx]/', 'privacy', 50, '身份证号'],
        ['/(家庭住址|家住|住在).{0,20}(小区|路|街|号)/u', 'privacy', 30, '住址'],
        ['/(傻逼|智障|脑残|废物|去死|滚出去|垃圾人)/u', 'abuse', 35, '侮辱性词汇'],
        ['/(死全家|全家死|你妈死|nmsl)/u', 'abuse', 65, '极端辱骂'],
        ['/(孤立|排挤|欺负|打他|堵他|扒光)/u', 'bullying', 30, '欺凌暗示'],
        ['/(自杀|轻生|跳楼|割腕|不想活|结束生命)/u', 'self_harm', 55, '自伤倾向'],
        ['/(加我|私聊|私信).{0,8}(微信|qq|vx)/u', 'ad', 35, '导流私域'],
        ['/(代购|微商|招代理|一件代发|货源)/u', 'ad', 30, '微商广告'],
        ['/https?:\/\/[^\s]{0,60}(shop|taobao|tmall|jd|pdd|weidian)/i', 'ad', 40, '电商链接'],
        ['/([!?？！。，,.])\1{5,}/u', 'spam', 15, '重复标点'],
        ['/(.)\1{9,}/u', 'spam', 20, '重复字符'],
    ];

    foreach ($rules as $r) {
        if (preg_match($r[0], $text, $m)) {
            $hits[] = ['type' => $r[1], 'weight' => $r[2], 'word' => mb_substr($m[0], 0, 20), 'label' => $r[3]];
            $score += $r[2];
        }
    }

    // 后台维护的敏感词表，单条命中给 50 分
    if (function_exists('checkSensitiveWords')) {
        $found = checkSensitiveWords($text);
        if ($found) {
            $word = is_string($found) ? $found : (is_array($found) ? (string)reset($found) : '');
            $hits[] = ['type' => 'abuse', 'weight' => 50, 'word' => mb_substr((string)$word, 0, 20), 'label' => '敏感词表'];
            $score += 50;
        }
    }

    $linkCount = preg_match_all('/https?:\/\//i', $text);
    if ($linkCount >= 4) {
        $hits[] = ['type' => 'spam', 'weight' => 25, 'word' => $linkCount . ' 个链接', 'label' => '链接过多'];
        $score += 25;
    }

    return ['score' => min(100, $score), 'hits' => $hits];
}

/**
 * 第 3 层：结构规则（不看内容，只看行为模式）。
 *
 *   同一用户 10 分钟内发 8 条 -> 刷屏
 *   正文 90% 是表情/符号      -> 无意义内容
 *   正文重复同一句 5 次       -> 灌水
 */
function lwStructuralScan(string $text, ?array $user): array
{
    $hits = [];
    $score = 0;
    $len = mb_strlen(trim($text), 'UTF-8');

    if ($len >= 10) {
        $meaningful = preg_match_all('/[\p{L}\p{N}]/u', $text);
        $ratio = $meaningful / max(1, $len);
        if ($ratio < 0.35) {
            $hits[] = ['type' => 'spam', 'weight' => 30, 'word' => '有效字符 ' . round($ratio * 100) . '%', 'label' => '内容无意义'];
            $score += 30;
        }
    }

    $sentences = preg_split('/[。！？.!?\n]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($sentences) >= 4) {
        $uniq = count(array_unique($sentences));
        if ($uniq / count($sentences) < 0.5) {
            $hits[] = ['type' => 'spam', 'weight' => 35, 'word' => count($sentences) . ' 句中仅 ' . $uniq . ' 种', 'label' => '重复灌水'];
            $score += 35;
        }
    }

    if ($user && isset($user['id'])) {
        $uid = (int)$user['id'];
        $recent = 0;
        $since = time() - 600;
        try {
            foreach (getFS()->read('posts') as $p) {
                if ((int)($p['user_id'] ?? 0) !== $uid) continue;
                $ts = strtotime((string)($p['created_at'] ?? '')) ?: 0;
                if ($ts >= $since) $recent++;
            }
        } catch (Throwable $e) {
        }
        if ($recent >= 8) {
            $hits[] = ['type' => 'spam', 'weight' => 40, 'word' => '10 分钟 ' . $recent . ' 条', 'label' => '发帖过频'];
            $score += 40;
        }
    }

    return ['score' => min(100, $score), 'hits' => $hits];
}

/**
 * 第 1 层：AI 语义审阅。
 *
 * 关键设计：
 *   - 超时严格（8 秒），失败**不阻断**，只是这一层不给分；
 *   - 要求模型返回严格 JSON，解析失败就当没审（score=0）；
 *   - 给模型明确「宁可放过」的指令，避免过度敏感；
 *   - 只允许它标记，不允许它决定「拒绝」。
 */
function lwAiScan(string $title, string $content): array
{
    $fail = ['ok' => false, 'score' => 0, 'types' => [], 'reason' => '', 'raw' => ''];

    if (!function_exists('aiChatCompletion') || !function_exists('getAIConfig')) {
        return $fail;
    }
    try {
        $cfg = getAIConfig();
    } catch (Throwable $e) {
        return $fail;
    }
    if (empty($cfg['enabled']) || empty($cfg['api_key'])) {
        return $fail;
    }

    $body = mb_substr($title . "\n" . $content, 0, 1500);
    $types = implode('、', array_keys(lwViolationTypes()));

    $system = '你是校园论坛的内容审核助手。判断用户投稿是否有违规风险。'
        . '只输出 JSON，不要解释文字、不要 Markdown 代码块。'
        . '格式：{"score":0到100的整数,"types":["类型key"],"reason":"一句话中文说明"}'
        . '类型 key 必须是：' . $types . '。'
        . '评分：0-29 完全正常；30-59 轻微问题；60-84 明显不当；85-100 严重违规。'
        . '重要：这是高中校园论坛，学生用词口语化、玩笑夸张都正常。'
        . '宁可给低分也不要把正常内容标成违规 —— 误杀真实用户比漏放更糟。'
        . '正常内容返回 {"score":0,"types":[],"reason":"正常"}。';

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => "待审内容：\n\n" . $body],
    ];

    try {
        $resp = aiChatCompletion($cfg, $messages, 8);
    } catch (Throwable $e) {
        return $fail;
    }
    if (!is_array($resp)) {
        return $fail;
    }
    $text = (string)($resp['content'] ?? $resp['text'] ?? '');
    if ($text === '') {
        return $fail;
    }

    $json = $text;
    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $json = $m[0];
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['ok' => false, 'score' => 0, 'types' => [], 'reason' => '', 'raw' => mb_substr($text, 0, 200)];
    }

    $score = max(0, min(100, (int)($data['score'] ?? 0)));
    $valid = array_keys(lwViolationTypes());
    $gotTypes = [];
    foreach ((array)($data['types'] ?? []) as $t) {
        $t = (string)$t;
        if (in_array($t, $valid, true)) {
            $gotTypes[] = $t;
        }
    }

    return [
        'ok' => true,
        'score' => $score,
        'types' => array_values(array_unique($gotTypes)),
        'reason' => mb_substr((string)($data['reason'] ?? ''), 0, 100),
        'raw' => '',
    ];
}

/**
 * 完整审核：三层合并 + 分级决策。
 */
function lwModerateContent(string $title, string $content, ?array $user = null, array $opts = []): array
{
    $text = trim($title . "\n" . $content);
    $skipAi = !empty($opts['skip_ai']);

    $ai = $skipAi
        ? ['ok' => false, 'score' => 0, 'types' => [], 'reason' => '已跳过', 'raw' => '']
        : lwAiScan($title, $content);
    $kw = lwKeywordScan($text);
    $st = lwStructuralScan($text, $user);

    /* 合并策略：不简单相加（会爆表），而是取「最高层 + 其余层的部分贡献」。
     *
     * 理由：关键词命中 70 分（色情交易）时，AI 再给 40 分，
     * 相加 110 顶到上限，但实际风险并没有比 70 高多少。
     * 用 max + 0.35 * others 能保留「多信号叠加更可信」的语义，
     * 又不会让轻微问题堆成高危。 */
    $scores = [$kw['score'], $st['score']];
    if ($ai['ok']) {
        $scores[] = $ai['score'];
    }
    $primary = max($scores);
    $others = array_sum($scores) - $primary;
    $total = (int)round(min(100, $primary + $others * 0.35));

    $typeSet = [];
    $reasons = [];
    foreach ($kw['hits'] as $h) {
        $typeSet[$h['type']] = true;
        $reasons[] = ['layer' => 'keyword', 'type' => $h['type'], 'label' => $h['label'], 'word' => $h['word'], 'weight' => $h['weight']];
    }
    foreach ($st['hits'] as $h) {
        $typeSet[$h['type']] = true;
        $reasons[] = ['layer' => 'structural', 'type' => $h['type'], 'label' => $h['label'], 'word' => $h['word'], 'weight' => $h['weight']];
    }
    if ($ai['ok']) {
        foreach ($ai['types'] as $t) {
            $typeSet[$t] = true;
        }
        if ($ai['reason'] !== '' && $ai['reason'] !== '正常') {
            $reasons[] = ['layer' => 'ai', 'type' => (string)($ai['types'][0] ?? 'other'), 'label' => $ai['reason'], 'word' => '', 'weight' => $ai['score']];
        }
    }

    $defs = lwViolationTypes();
    $types = [];
    foreach (array_keys($typeSet) as $t) {
        $types[$t] = $defs[$t] ?? $defs['other'];
    }

    /* 决策阈值。
     *
     * 【关键约束】AI 单独不能把内容判到 reject。
     * 只有关键词/结构层（确定性证据）达到 85 才允许直接拒绝。
     * 否则 AI 误判会让用户发不出任何东西。 */
    $hasDeterministic = ($kw['score'] >= 85 || $st['score'] >= 85);

    if ($total >= 85 && $hasDeterministic) {
        $action = 'reject';
        $verdict = '已拒绝';
    } elseif ($total >= 60) {
        $action = 'pending';
        $verdict = '待审核';
    } elseif ($total >= 30) {
        $action = 'review';
        $verdict = '已发布（标记待查）';
    } else {
        $action = 'pass';
        $verdict = '通过';
    }

    return [
        'verdict' => $verdict,
        'action' => $action,
        'score' => $total,
        'types' => $types,
        'reasons' => $reasons,
        'layers' => [
            'ai' => ['ok' => $ai['ok'], 'score' => $ai['score'], 'types' => $ai['types'], 'reason' => $ai['reason']],
            'keyword' => ['score' => $kw['score'], 'hits' => $kw['hits']],
            'structural' => ['score' => $st['score'], 'hits' => $st['hits']],
        ],
    ];
}

/** 把审核结果写进内容记录的字段（供后台与前端展示）。 */
function lwModerationFields(array $result): array
{
    return [
        'mod_status'      => $result['action'],
        'mod_score'       => $result['score'],
        'mod_types'       => array_keys($result['types']),
        'mod_types_label' => array_values(array_map(static function ($t) {
            return $t['label'];
        }, $result['types'])),
        'mod_reasons'     => $result['reasons'],
        'mod_layers'      => $result['layers'],
        'mod_at'          => date('Y-m-d H:i:s'),
    ];


}
