<?php
/**
 * AI 回复缓存（输入 → 输出 的命中层）
 * =====================================================================
 *
 * 为什么需要它
 * ------------
 * 站内 AI 助手每问一句都要付费打一次模型。实际流量里有一大块是**完全重复的问题**：
 * 「怎么发帖」「怎么改头像」「签到有什么奖励」这类站务问答，再加上用户手滑连点、
 * 或者两个同学问了同一句话。这些完全相同的问题每次都要重新取站点上下文 →
 * 拼 system 提示词 → 等模型吐字 → 付费。
 *
 * 本层把「同一份输入 → 同一份输出」直接存下来，命中时省掉三样东西：
 * 模型调用（钱 + 秒级延迟）、站点上下文取数（好几个 JSON 表的读）、提示词拼装。
 *
 * 三条硬约束（等于安全边界，改动前务必读懂）
 * ------------------------------------------
 *   1) **命中也要落审计**：命中时照样写一条 ai_logs（标记 cached=1），
 *      否则后台用量统计会突然「少了一大截」，看起来像接口挂了。
 *   2) **指纹必须覆盖一切影响输出的输入**：模型名、整段 system 提示词
 *      （含身份/权限/站点名/数据块）、全部对话历史、最后一条的用户正文。
 *      少算任何一项都可能把「管理员的回答」发给普通用户 —— 那是权限事故，不是 bug。
 *   3) **只读本地文件、按 TTL 过期、写失败当未命中**：不引入外部依赖，
 *      绝不因为缓存坏了把接口弄成 500。
 *
 * 为什么只缓存「纯问答」：带 <lw-action> 的回复会发确认卡片，卡片 token 写进会话、
 * 一次性、且与具体用户绑定。这种回复既不能给别的用户复用（token 已失效），
 * 缓存了也没意义，所以只在「没有卡片」时才写入。
 */

/** 可通过 config/ai_config.php 覆盖：0 表示关闭 AI 回复缓存 */
if (!defined('LW_AI_CACHE_TTL')) {
    define('LW_AI_CACHE_TTL', 7 * 24 * 3600);
}

/** 缓存文件目录（挂在 data/ 下，随 data/ 一起备份、一起排除） */
function lwAiCacheDir()
{
    return __DIR__ . '/../data/ai_cache';
}

/**
 * 由 aiChatPrepare() 的完整上下文算出缓存指纹。
 *
 * $ctx['modelMessages'] 第一条是 system 提示词（服务端拼的），后面是 user/assistant 历史 ——
 * 整段参与摘要，不做「只取最后一句」的偷懒：「那怎么办」这种追问，答案取决于上文。
 *
 * @param array $ctx aiChatPrepare() 的返回值
 * @return string 64 位十六进制；不可缓存时返回空串
 */
function lwAiCacheKey(array $ctx)
{
    if (LW_AI_CACHE_TTL <= 0) {
        return '';
    }
    // 自带模型的回答取决于用户自己的 Key / 端点 / 模型：不进入共享缓存池，
    // 否则会拿「别人用他自己的模型得到的回答」来回答你。
    if (($ctx['key_owner'] ?? 'site') !== 'site') {
        return '';
    }
    $modelMessages = $ctx['modelMessages'] ?? [];
    if (!is_array($modelMessages) || !$modelMessages) {
        return '';
    }
    // 多模态消息的 content 是分段数组：输出取决于图片内容，而图片不在指纹里
    // （只有 URL，且上传文件可能被清理）—— 一律不缓存带图的提问。
    foreach ($modelMessages as $m) {
        if (is_array($m) && isset($m['content']) && is_array($m['content'])) {
            return '';
        }
    }
    $payload = [
        // 指纹算法版本：改了取数/拼装逻辑就 +1，旧缓存自然失效，不用手工清目录
        'v'     => 2,
        'model' => (string)($ctx['model'] ?? ''),
        'msgs'  => $modelMessages,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '';
    }
    return hash('sha256', $json);
}

/** 缓存文件路径（两级分桶：避免一个目录堆几万个文件拖慢 readdir）。 */
function lwAiCachePath($key)
{
    if (!preg_match('/^[0-9a-f]{64}$/', $key)) {
        return '';
    }
    return lwAiCacheDir() . '/' . substr($key, 0, 2) . '/' . $key . '.json';
}

/**
 * 读缓存。未命中返回 null。
 *
 * 命中**不会**跳过权限/封禁/限流 —— 那些检查在 aiChatPrepare() 里已经跑完了，
 * 本函数只在全部检查通过之后被调用。
 *
 * @return array|null ['reply'=>string,'reasoning'=>?string,'sources'=>array]
 */
function lwAiCacheGet($key)
{
    if ($key === '') {
        return null;
    }
    $path = lwAiCachePath($key);
    if ($path === '' || !is_file($path)) {
        return null;
    }
    // 用 mtime 判过期：TTL 调小后下一次访问就失效，不需要遍历清理
    $mtime = @filemtime($path);
    if (!$mtime || (time() - $mtime) > LW_AI_CACHE_TTL) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['reply']) || !is_string($data['reply']) || $data['reply'] === '') {
        return null;
    }
    return [
        'reply'     => $data['reply'],
        'reasoning' => isset($data['reasoning']) && is_string($data['reasoning']) ? $data['reasoning'] : null,
        'sources'   => isset($data['sources']) && is_array($data['sources']) ? $data['sources'] : [],
    ];
}

/**
 * 写缓存。任何失败都静默吞掉（缓存失效不是错误）。
 *
 * @return bool 是否真的写入
 */
function lwAiCachePut($key, $reply, $reasoning = '', $sources = [])
{
    if ($key === '' || $reply === '') {
        return false;
    }
    $path = lwAiCachePath($key);
    if ($path === '') {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode([
        'reply'     => (string)$reply,
        'reasoning' => (string)$reasoning,
        'sources'   => is_array($sources) ? $sources : [],
        // 只留诊断用时间戳，不留用户身份：这份缓存是全站共享的
        'cached_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    // 原子写：先写临时文件再 rename，并发读者不会读到半截 JSON
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $json) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * 概率性清理过期缓存（1/50 的请求触发一次，单次最多删 20 个）。
 *
 * 为什么不每次都清：目录遍历在共享主机上不便宜，而过期本身不影响正确性
 * （读的时候有 mtime 判断），清理只是回收磁盘。
 */
function lwAiCacheGc()
{
    if (LW_AI_CACHE_TTL <= 0 || random_int(1, 50) !== 1) {
        return;
    }
    $dir = lwAiCacheDir();
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    $removed = 0;
    $buckets = @scandir($dir);
    if (!is_array($buckets)) {
        return;
    }
    foreach ($buckets as $bucket) {
        if ($removed >= 20) {
            break;
        }
        if ($bucket === '.' || $bucket === '..') {
            continue;
        }
        $bd = $dir . '/' . $bucket;
        if (!is_dir($bd)) {
            // 顶层不该有文件：有就是上次临时文件残留
            if (is_file($bd) && ($now - (int)@filemtime($bd)) > 3600) {
                @unlink($bd);
            }
            continue;
        }
        $files = @scandir($bd);
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $f) {
            if ($removed >= 20) {
                break;
            }
            if ($f === '.' || $f === '..') {
                continue;
            }
            $fp = $bd . '/' . $f;
            if (is_file($fp) && ($now - (int)@filemtime($fp)) > LW_AI_CACHE_TTL) {
                if (@unlink($fp)) {
                    $removed++;
                }
            }
        }
    }
}
