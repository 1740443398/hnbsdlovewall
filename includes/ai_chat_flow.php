<?php
/**
 * AI 对话流程：非流式与流式两个端点共用的「准备」与「收尾」
 * ---------------------------------------------------------------------------
 * 以前只有 api/ai_assistant.php 一个入口，鉴权、限流、消息校验、上下文拼装
 * 都写在它里面。新增流式端点后如果照抄一份，两边迟早走偏（改了非流式忘改流式）。
 *
 * 这里把整个链路切成两段：
 *   aiChatPrepare()  —— 鉴权 + 限流 + 消息校验 + 系统提示词 + 站点上下文
 *   aiChatFinalize() —— 审计落库 + 确认卡片 + 输出清理
 * 两个端点只负责「怎么把结果送出去」（一次性 JSON / 流式 SSE）。
 *
 * 注意：aiChatPrepare() 内部会用 jsonError() 直接终止请求，调用方无需再判断。
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/ai_site_data.php';
require_once __DIR__ . '/ai_actions.php';
require_once __DIR__ . '/ai_persona.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/user_ai_config.php';

/**
 * @return array 成功时 ok=true 并带齐上下文；失败时函数内部已 jsonError 终止。
 */
function aiChatPrepare() {
    $AICFG = [];
    $aiCfgFile = dirname(__DIR__) . '/config/ai_config.php';
    if (is_file($aiCfgFile)) {
        require $aiCfgFile;
    }
    $apiKeys = aiResolveApiKeys($AICFG);
    $model   = isset($AICFG['model']) ? trim((string)$AICFG['model']) : '';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('仅支持 POST 请求', 405);
    }

    // ---- 同源校验（防第三方站点盗用会话消耗 AI 配额）----
    // HTTP_HOST 带端口而 parse_url 出来的 host 不带端口，两边都要归一化到协议默认端口再比，
    // 否则本地开发（127.0.0.1:8123）会把同源请求判成跨源。
    $reqHost = $_SERVER['HTTP_HOST'] ?? '';
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $reqHostOnly = parse_url('http://' . $reqHost, PHP_URL_HOST);
        $reqPort     = parse_url('http://' . $reqHost, PHP_URL_PORT);
        $originDefaultPort = (parse_url($origin, PHP_URL_SCHEME) === 'https') ? 443 : 80;
        $originPort = $originPort ?: $originDefaultPort;
        $reqPort    = $reqPort ?: $originDefaultPort;
        if ($originHost === false || $originHost !== $reqHostOnly || $originPort !== $reqPort) {
            error_log('AI 助手：拒绝跨源请求，origin=' . $origin);
            jsonError('请求来源不合法', 403);
        }
    }

    // ---- CSRF ----
    // 对话接口是 POST + JSON 体，令牌放在 body 的 csrf_token 字段里（与表单同一个规则）。
    // 光有上面的 Origin 比对不够：Origin 只在跨域时由浏览器附带，同策略的老 WebView
    // 与部分内嵌容器不会发，外站页面就能拿访客的浏览器当跳板白烧 AI 额度。
    // php://input 只安全读一次，这里读完连同解析结果一起存下来给下面复用。
    $lwRawBody = (string)file_get_contents('php://input');
    $lwBody    = json_decode($lwRawBody, true);
    if (!verifyCSRFTokenEnhanced(is_array($lwBody) ? (string)($lwBody['csrf_token'] ?? '') : '')) {
        jsonError('页面已过期，请刷新后重试', 403);
    }

    $user = requireLoginOrGuest();
    $isGuest = ($user === null);
    if ($user) {
        $user = checkBanned($user);
        if ($user['is_banned']) {
            jsonError('账号已被封禁，无法使用 AI 助手', 403);
        }
    }

    // ---- 用户自带的模型（AI 助手「高级选项」）----
    // 自带模型走的是用户自己的额度，站点既不掏钱也不消耗站点 Key，
    // 所以这里把整份 $AICFG 换成他自己的：端点、Key、模型名全部覆盖。
    // 权限/审计/确认卡片规则完全不变 —— 不因为「是你自己的 AI」就绕过任何一道闸。
    $ownCfg   = null;
    $keyOwner = 'site';
    if ($user) {
        $ownCfg = lwUserAiConfig((int)$user['id']);
        if ($ownCfg && !empty($ownCfg['enabled'])) {
            $keyOwner = 'user';
            $AICFG['base_url'] = $ownCfg['base_url'];
            $AICFG['api_keys'] = [$ownCfg['api_key']];
            unset($AICFG['api_key']);
            $AICFG['model']    = $ownCfg['model'];
            $apiKeys = aiResolveApiKeys($AICFG);
            $model   = $ownCfg['model'];
        }
    }

    // 限流：校园网整栋楼共用一个出口 IP，只用 IP 维度会让一个班的同学互相拖累，
    // 所以游客主约束放在会话键上，IP 键放宽。
    // 自带模型时额度是用户自己的，站点侧只保留「防滥用」这一层，配额相应放宽。
    if ($isGuest) {
        if (!checkRateLimit('sess_' . session_id(), 'ai_chat_guest', 12, 60)) {
            jsonError('提问有点频繁，稍等一会儿再试；登录后配额更宽裕', 429);
        }
        if (!checkRateLimit(getClientIP(), 'ai_chat_guest_ip', 60, 60)) {
            jsonError('当前网络提问较多，请稍后再试', 429);
        }
    } elseif ($keyOwner === 'user') {
        if (!checkRateLimit('u' . (int)$user['id'], 'ai_chat_own', 60, 60)) {
            jsonError('提问有点频繁，稍等一会儿再试', 429);
        }
    } else {
        if (!checkRateLimit(getClientIP(), 'ai_chat', 30, 60)) {
            jsonError('操作过于频繁，请稍后再试', 429);
        }
    }

    if (!$apiKeys || $model === '') {
        if ($keyOwner === 'user') {
            // 自带模型却解析不出 Key：通常是配置损坏，引导用户去「高级选项」重填
            jsonError('你的自定义 AI 配置无效，请到「高级选项」里重新填写或一键还原', 503);
        }
        error_log('AI 助手：config/ai_config.php 未配置 api_keys / model');
        jsonError('AI 服务未配置，请联系管理员', 503);
    }

    // ---- 消息校验 ----
    // 请求体已在上面的 CSRF 段读过一次，这里直接用，不再读 php://input
    $data = $lwBody;
    if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
        jsonError('请求参数缺失：messages', 400);
    }

    $messages = [];
    $totalLen = 0;
    foreach ($data['messages'] as $m) {
        if (!is_array($m) || count($messages) >= 50) {
            continue;
        }
        $role = isset($m['role']) ? trim((string)$m['role']) : '';
        if (!in_array($role, ['system', 'user', 'assistant'], true)) {
            jsonError('消息角色不合法', 400);
        }
        // 身份只由服务端拼装：浏览器传来的 system 一律丢弃，防伪造身份
        if ($role === 'system') {
            continue;
        }
        $content = isset($m['content']) ? trim((string)$m['content']) : '';
        if ($content === '') {
            jsonError('消息内容不能为空', 400);
        }
        if (mb_strlen($content) > 4000) {
            jsonError('单条消息过长（最多 4000 字）', 400);
        }
        // 用户消息里混进的 <lw-action> 先中和：只有 assistant 的最终回复才允许触发卡片
        if ($role === 'user') {
            $content = str_replace(['<lw-action>', '</lw-action>'], '［lw-action］', $content);
        }
        $totalLen += mb_strlen($content);
        $messages[] = ['role' => $role, 'content' => $content];
    }
    if (empty($messages)) {
        jsonError('没有可发送的消息', 400);
    }
    if ($totalLen > 20000) {
        jsonError('消息总长度超限（最多 20000 字）', 400);
    }

    // ---- 服务端注入身份 ----
    $siteName  = getSetting('site_name', SITE_NAME);
    $roleNames = ['super_admin' => '超级管理员', 'admin' => '管理员', 'user' => '普通用户'];
    $roleName  = $user ? ($roleNames[$user['role'] ?? 'user'] ?? '普通用户') : '游客';
    $isAdmin   = $user && in_array($user['role'] ?? 'user', ['admin', 'super_admin'], true);
    $nickName  = $user ? trim((string)($user['nickname'] ?? '')) : '';
    if ($user && $nickName === '') {
        $nickName = '用户' . $user['id'];
    }

    // 当前页面：白名单化，避免被当作注入通道
    $currentPage = '/';
    if (isset($data['page']) && is_string($data['page'])) {
        $p = trim($data['page']);
        if ($p !== '' && mb_strlen($p) <= 120 && preg_match('#^/[A-Za-z0-9_\-./?=&%]*$#', $p)) {
            $currentPage = $p;
        }
    }

    // ---- 站点实时数据：按意图取数，再沙箱化防注入 ----
    $lastUserText = '';
    foreach ($messages as $m) {
        if ($m['role'] === 'user') {
            $lastUserText = $m['content'];
        }
    }
    $siteCtx    = aiCollectContext($lastUserText, $user);
    $safeBlocks = $siteCtx['blocks'] ? aiSanitizeDataBlocks($siteCtx['blocks']) : [];

    $systemPrompt = aiBuildSystemPrompt([
        'site_name'    => $siteName,
        'user'         => $user,
        'nickname'     => $nickName,
        'role_name'    => $roleName,
        'is_admin'     => $isAdmin,
        'current_page' => $currentPage,
        'data_blocks'  => $safeBlocks,
        'sources'      => $siteCtx['sources'],
    ]);

    $messagesWithSystem = $messages;
    array_unshift($messagesWithSystem, ['role' => 'system', 'content' => $systemPrompt]);

    return [
        'ok'           => true,
        'cfg'          => $AICFG,
        'apiKeys'      => $apiKeys,
        'model'        => $model,
        // 'site' = 消耗站点配额；'user' = 用户自带模型（站点零成本）
        'key_owner'    => $keyOwner,
        'ownCfg'       => $ownCfg,
        'user'         => $user,
        'isGuest'      => $isGuest,
        'nickname'     => $nickName,
        'messages'     => $messagesWithSystem,
        'siteCtx'      => $siteCtx,
        'currentPage'  => $currentPage,
        'lastUserText' => $lastUserText,
    ];
}

/**
 * 收尾：审计落库 + 确认卡片 + 输出清理。
 * 流式与非流式共用，保证「不管怎么送达，规则与审计完全一致」。
 *
 * @param array  $ctx       aiChatPrepare() 的返回值
 * @param string $content   完整正文
 * @param string $reasoning 完整思考过程
 * @param array  $aiResult  aiChatCompletion / aiStreamCompletion 的返回
 * @param int    $elapsedMs 耗时
 * @param bool   $canIssueCard 会话是否仍可写。流式端点为释放锁会提前关闭会话，
 *                             结束后才重新打开；万一重开失败（首访无 Cookie）必须传 false，
 *                             否则卡片 token 写不进会话，前端会出现点了必然失效的卡。
 * @return array{reply:string,reasoning:?string,sources:array,action:?array,guest:bool,notice:?string}
 */
function aiChatFinalize(array $ctx, $content, $reasoning, array $aiResult, $elapsedMs, $canIssueCard = true) {
    $user         = $ctx['user'];
    $isGuest      = $ctx['isGuest'];
    $siteCtx      = $ctx['siteCtx'];
    $messages     = $ctx['messages'];

    logAiCall([
        'user_id'     => $user ? (int)$user['id'] : 0,
        'nickname'    => $user ? $ctx['nickname'] : '',
        'is_guest'    => $isGuest,
        'ip'          => getClientIP(),
        'success'     => !empty($aiResult['ok']),
        'http_status' => (int)($aiResult['status'] ?? 0),
        'error'       => $aiResult['ok'] ? '' : (string)$aiResult['error'],
        'elapsed_ms'  => $elapsedMs,
        'key_tries'   => (int)($aiResult['key_tries'] ?? 0),
        'key_owner'   => $ctx['key_owner'] ?? 'site',
        'sources'     => $siteCtx['sources'],
        'intent'      => implode(',', $siteCtx['intents'] ?? []),
        'question'    => $ctx['lastUserText'],
        'page'        => $ctx['currentPage'],
    ]);

    // error_log 只记状态、条数与失败原因（更完整的审计已由上面的 logAiCall 落库）。
    // 免费主机上这是排查「AI 调不通」的唯一抓手，成功与失败两路都要有。
    if (!$aiResult['ok']) {
        error_log('AI 助手：请求失败，HTTP ' . ($aiResult['status'] ?? 0) . '，消息条数 ' . count($messages)
            . '，已尝试 ' . ($aiResult['key_tries'] ?? 0) . ' 把 Key，错误：'
            . mb_substr((string)$aiResult['error'], 0, 120));
    } else {
        error_log('AI 助手：成功，消息条数 ' . count($messages) . '，游客=' . ($isGuest ? '1' : '0')
            . '，耗时 ' . $elapsedMs . 'ms');
    }

    if (!$aiResult['ok']) {
        $errMsg = trim((string)$aiResult['error']);
        // 自带模型失败时把原因回写到用户配置里，「高级选项」面板就能显示「上次失败原因」，
        // 否则用户只看到一句 502，根本不知道是 Key 错了还是模型名写错了。
        if (($ctx['key_owner'] ?? 'site') === 'user' && $user && $errMsg !== '') {
            lwUserAiNoteError((int)$user['id'], $errMsg);
        }
        $userMsg = $errMsg !== ''
            ? 'AI 服务返回错误：' . mb_substr($errMsg, 0, 150)
            : 'AI 服务暂时不可用，请稍后再试';
        return ['error' => $userMsg, 'code' => 502];
    }

    $content   = trim((string)$content);
    $reasoning = trim((string)$reasoning);

    // ---- 确认卡片：严格解析，失败即丢弃（不做兜底猜测）----
    // 注意：动作块无论能不能发卡都要从正文里剥掉，否则用户会看到 <lw-action> 原始标签。
    $actionCard = null;
    if (!$isGuest && $content !== '') {
        $block = aiExtractActionBlock($content);
        if ($block !== null && $canIssueCard) {
            // 自带模型不烧站点额度，允许更密集地发卡（每张卡仍要用户点确认才执行）
            $maxCards = (($ctx['key_owner'] ?? 'site') === 'user') ? 10 : AI_CARD_ISSUE_PER_MIN;
            $actionCard = aiIssueActionToken($user, $block['action'], $block['params'], $maxCards);
            if ($actionCard === null) {
                error_log('[love_wall] AI 卡片：拒绝发卡 action=' . mb_substr($block['action'], 0, 40));
            }
        }
        $content = aiStripActionBlock($content);
    }

    if ($content === '') {
        $content = $reasoning !== ''
            ? '（模型未返回正式内容，请换个问法试试）'
            : '（AI 未返回有效内容，请换个问法试试）';
    }
    if (mb_strlen($content) > 8000) {
        $content = mb_substr($content, 0, 8000) . '…（内容过长已截断）';
    }
    if (mb_strlen($reasoning) > 4000) {
        $reasoning = mb_substr($reasoning, 0, 4000) . '…';
    }

    $notice = null;
    if ($isGuest) {
        $notice = '当前是游客模式，只能浏览公开内容。注册登录后可以用「我的帖子 / 收藏 / 通知 / 签到」并让小助手帮你办事。';
    }

    return [
        'reply'     => $content,
        'reasoning' => $reasoning !== '' ? $reasoning : null,
        'sources'   => $siteCtx['sources'],
        'action'    => $actionCard,
        'guest'     => $isGuest,
        'notice'    => $notice,
    ];
}
