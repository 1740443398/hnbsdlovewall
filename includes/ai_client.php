<?php
/**
 * 智谱 AI 统一客户端
 * ---------------------------------------------------------------------------
 * 抽出来只为一件事：**多把 API Key 轮换**，避免单 Key 额度耗尽后整站 AI 一起瘫。
 *
 * 轮换策略（无状态，不依赖任何计数文件，共享主机上也安全）：
 *   1. 每次请求随机挑一个起点 —— 长期看流量天然摊到各把 Key 上，
 *      不会出现「总用 1 号 Key，先把它耗光」的偏斜；
 *   2. 从起点开始顺序尝试；只有遇到「Key 自身的问题」（401/402/429，
 *      或返回体里的认证类/额度类错误码）才换下一把；
 *   3. 遇到 400 这类「请求本身写错了」立刻停 —— 换 Key 也救不回来，只会白烧配额；
 *   4. 全部不可用才返回最后一次的错误，调用方照旧拿到可读的中文提示。
 *
 * 密钥只存在于服务端 config/ai_config.php（已 .gitignore）；本文件的所有日志
 * 都只写「第几把 Key」与原因摘要，绝不写密钥原文。
 */

/**
 * 取出去重后的可用 Key 列表。
 * 兼容两种写法：新的 'api_keys' => [...] 数组，以及旧的单个 'api_key' => '...'。
 */
function aiResolveApiKeys(array $cfg) {
    $keys = [];
    if (!empty($cfg['api_keys']) && is_array($cfg['api_keys'])) {
        foreach ($cfg['api_keys'] as $k) {
            $k = trim((string)$k);
            if ($k !== '') {
                $keys[] = $k;
            }
        }
    }
    if (!empty($cfg['api_key'])) {
        $k = trim((string)$cfg['api_key']);
        if ($k !== '') {
            $keys[] = $k;
        }
    }
    return array_values(array_unique($keys));
}

/**
 * 端点地址：默认是智谱；用户在「高级选项」里自带模型时换成他自己的地址。
 *
 * 安全前提：base_url 在保存时已经过 lwUserAiValidateBaseUrl() 校验
 * （仅 https 公网域名、禁止 IP 与内网地址、仅 443 端口），这里不再二次校验，
 * 但调用方必须保证传进来的 cfg 来自站点配置或已校验的用户配置。
 */
function aiEndpointUrl(array $cfg) {
    $base = trim((string)($cfg['base_url'] ?? ''));
    if ($base === '') {
        return 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
    }
    return $base;
}

/**
 * 给已编码的请求体补一个 max_tokens（仅当 cfg 里显式要求时）。
 * 正常对话不限制长度；只有连通性测试这种「只要证明能通」的调用才会带它。
 */
function aiAppendMaxTokens(string $payload, array $cfg) {
    $mt = (int)($cfg['max_tokens'] ?? 0);
    if ($mt <= 0) {
        return $payload;
    }
    $arr = json_decode($payload, true);
    if (!is_array($arr)) {
        return $payload;
    }
    $arr['max_tokens'] = $mt;
    $out = json_encode($arr, JSON_UNESCAPED_UNICODE);
    return $out === false ? $payload : $out;
}

/**
 * 这个失败是否值得换一把 Key 重试。
 * 只认「Key 的问题」：状态码 401/402/403/429，或智谱返回体里的认证/额度/限流错误码。
 */
function aiIsKeyRetryable($status, $resp) {
    if (in_array((int)$status, [401, 402, 403, 429], true)) {
        return true;
    }
    if (!is_array($resp) || !isset($resp['error']['code'])) {
        return false;
    }
    // 智谱常见错误码：1000/1001/1002/1003 认证失败，1113/1261 余额不足，1301/1302 限流
    return in_array((string)$resp['error']['code'], [
        '1000', '1001', '1002', '1003', '1113', '1261', '1301', '1302',
    ], true);
}

/**
 * 发一次 HTTP，优先 cURL，回退 file_get_contents。
 * 返回 [响应体(string|false), HTTP 状态码(int), 错误摘要(string)]。
 *
 * 证书校验保留：这道请求带着 API 密钥与完整对话，关掉校验等于把密钥交给中间人。
 * 仅当失败原因明确是「证书」时才降级重试一次 —— 少数共享主机没配 CA 证书包，
 * 否则 AI 会整体不可用；同时把告警写进日志，方便站长补齐证书。
 */
function aiSendOnce($url, $payload, $apiKey, $timeout) {
    if (function_exists('curl_init')) {
        $do = function ($verify) use ($url, $payload, $apiKey, $timeout) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            return [$body, $code, $err];
        };

        list($body, $code, $err) = $do(true);
        if ($body === false && stripos((string)$err, 'certificate') !== false) {
            error_log('[love_wall] AI 客户端：HTTPS 证书校验失败（主机可能缺少 CA 证书包），本次降级重试：' . $err);
            list($body, $code, $err) = $do(false);
        }
        return [$body, $code, $err];
    }

    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer " . $apiKey . "\r\nContent-Type: application/json\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => $timeout,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0], $mm)) {
        $code = (int)$mm[1];
    }
    return [$body, $code, ($body === false ? '请求失败' : '')];
}

/**
 * 流式发起一次对话补全（SSE），用于「思考过程实时同步」。
 *
 * 与 aiChatCompletion 的区别：上游返回的不是一整个 JSON，而是一串
 *     data: {"choices":[{"delta":{"reasoning_content":"…","content":"…"}}]}
 *     data: [DONE]
 * 这里边收边把增量片段交给 $onDelta，调用方（api/ai_stream.php）立刻推给浏览器。
 *
 * 多 Key 轮换的取舍：**一旦开始往外吐字就不能再换 Key 了**（换 Key 等于重发一次请求，
 * 用户会看到两段重复的回答）。所以只有「还没吐出任何内容就失败」时才允许换下一把 Key。
 *
 * @param callable $onDelta function(string $type, string $text) $type 为 'reasoning' | 'content'
 * @return array{ok:bool,status:int,error:string,key_tries:int,content:string,reasoning:string}
 */
function aiStreamCompletion(array $cfg, array $messages, $timeout, callable $onDelta) {
    $keys  = aiResolveApiKeys($cfg);
    $model = trim((string)($cfg['model'] ?? ''));

    if (!$keys || $model === '') {
        return ['ok' => false, 'status' => 0, 'error' => '', 'key_tries' => 0,
                'content' => '', 'reasoning' => ''];
    }

    $url     = aiEndpointUrl($cfg);
    $payload = json_encode([
        'model'    => $model,
        'messages' => $messages,
        'stream'   => true,
    ], JSON_UNESCAPED_UNICODE);
    // 仅连通性测试这类场景会带 max_tokens：少生成一点，测得快也省额度
    $payload = aiAppendMaxTokens($payload, $cfg);

    $count = count($keys);
    $start = $count > 1 ? mt_rand(0, $count - 1) : 0;
    $last  = ['ok' => false, 'status' => 0, 'error' => '', 'key_tries' => 0,
              'content' => '', 'reasoning' => ''];

    for ($i = 0; $i < $count; $i++) {
        $idx = ($start + $i) % $count;
        $acc = ['content' => '', 'reasoning' => ''];
        $started = false;   // 是否已经开始吐内容
        $status  = 0;
        $errBody = '';

        $do = function ($verify) use ($url, $payload, $keys, $idx, $timeout, &$acc, &$started, &$status, &$errBody, $onDelta) {
            $buf = '';
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $keys[$idx],
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                ],
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_ENCODING       => '',           // 让 cURL 自动解 gzip，我们不自己解
                CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buf, &$acc, &$started, &$errBody, $onDelta) {
                    $buf .= $chunk;
                    // 只处理完整的行，跨 chunk 的半行留在 $buf 里等下一块
                    while (($pos = strpos($buf, "\n")) !== false) {
                        $line = substr($buf, 0, $pos);
                        $buf  = substr($buf, $pos + 1);
                        $line = trim($line);
                        if ($line === '' || strpos($line, 'data:') !== 0) {
                            continue;
                        }
                        $json = trim(substr($line, 5));
                        if ($json === '' || $json === '[DONE]') {
                            continue;
                        }
                        $d = json_decode($json, true);
                        if (!is_array($d)) {
                            continue;
                        }
                        // 上游把错误也用 SSE 帧下发（例如 429 限流）
                        if (isset($d['error'])) {
                            $errBody = json_encode($d, JSON_UNESCAPED_UNICODE);
                            continue;
                        }
                        $delta = $d['choices'][0]['delta'] ?? null;
                        if (!is_array($delta)) {
                            continue;
                        }
                        if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                            $acc['reasoning'] .= $delta['reasoning_content'];
                            $started = true;
                            $onDelta('reasoning', $delta['reasoning_content']);
                        }
                        if (isset($delta['content']) && $delta['content'] !== '') {
                            $acc['content'] .= $delta['content'];
                            $started = true;
                            $onDelta('content', $delta['content']);
                        }
                    }
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err    = curl_error($ch);
            curl_close($ch);
            return $err;
        };

        $err = $do(true);
        if ($status === 0 && stripos((string)$err, 'certificate') !== false) {
            error_log('[love_wall] AI 流式客户端：HTTPS 证书校验失败，降级重试：' . $err);
            $acc = ['content' => '', 'reasoning' => '']; // 丢弃首次降级可能已经吐出的半截内容
            $started = false;
            $err = $do(false);
        }

        $last['key_tries'] = $i + 1;
        $last['status']    = $status;

        $resp = ($errBody !== '') ? json_decode($errBody, true) : null;

        if ($status === 200 && $errBody === '') {
            $last['ok']        = true;
            $last['content']   = $acc['content'];
            $last['reasoning'] = $acc['reasoning'];
            $last['error']     = '';
            return $last;
        }

        $errMsg = (is_array($resp) && isset($resp['error']['message']))
            ? trim((string)$resp['error']['message'])
            : (string)$err;
        $errMsg = str_replace($keys, '***', $errMsg);
        $last['error']    = $errMsg;
        $last['content']  = $acc['content'];
        $last['reasoning'] = $acc['reasoning'];

        // 已经开始吐字了就不能再换 Key（会导致回答重复），直接结束
        if ($started) {
            $last['ok'] = ($acc['content'] !== '' || $acc['reasoning'] !== '');
            return $last;
        }
        if (!aiIsKeyRetryable($status, $resp)) {
            break;
        }
    }

    return $last;
}

/**
 * 发起一次对话补全（含多 Key 轮换）。
 *
 * @param array $cfg      config/ai_config.php 返回的配置
 * @param array $messages 已由服务端拼装、校验过的消息数组
 * @param int   $timeout  单次请求超时（秒）
 * @return array{ok:bool,status:int,resp:?array,error:string,unconfigured:bool,key_tries:int}
 */
function aiChatCompletion(array $cfg, array $messages, $timeout = 60) {
    $keys  = aiResolveApiKeys($cfg);
    $model = trim((string)($cfg['model'] ?? ''));

    if (!$keys || $model === '') {
        return ['ok' => false, 'status' => 0, 'resp' => null, 'error' => '',
                'unconfigured' => true, 'key_tries' => 0];
    }

    $url     = aiEndpointUrl($cfg);
    $payload = json_encode(['model' => $model, 'messages' => $messages], JSON_UNESCAPED_UNICODE);
    $payload = aiAppendMaxTokens($payload, $cfg);

    $count = count($keys);
    // 随机起点：多把 Key 时把请求摊开，避免热度集中在同一把上
    $start = $count > 1 ? mt_rand(0, $count - 1) : 0;

    $last = ['ok' => false, 'status' => 0, 'resp' => null, 'error' => '',
             'unconfigured' => false, 'key_tries' => 0];

    for ($i = 0; $i < $count; $i++) {
        $idx = ($start + $i) % $count;
        list($body, $status, $err) = aiSendOnce($url, $payload, $keys[$idx], $timeout);
        $last['key_tries'] = $i + 1;

        $resp = (is_string($body) && $body !== '') ? json_decode($body, true) : null;

        if ((int)$status === 200 && is_array($resp)) {
            if ($i > 0) {
                error_log('[love_wall] AI 客户端：第 ' . ($i + 1) . ' 把 Key 才成功（前 ' . $i . ' 把不可用，共 ' . $count . ' 把）');
            }
            return ['ok' => true, 'status' => 200, 'resp' => $resp, 'error' => '',
                    'unconfigured' => false, 'key_tries' => $i + 1];
        }

        $errMsg = (is_array($resp) && isset($resp['error']['message']))
            ? trim((string)$resp['error']['message'])
            : (string)$err;
        $errMsg = str_replace($keys, '***', $errMsg); // 兜底：绝不透出密钥

        $last['ok']     = false;
        $last['status'] = (int)$status;
        $last['resp']   = $resp;
        $last['error']  = $errMsg;

        if (!aiIsKeyRetryable($status, $resp)) {
            break; // 请求本身不合法，换 Key 无意义
        }
        if ($i < $count - 1) {
            error_log('[love_wall] AI 客户端：第 ' . ($i + 1) . ' 把 Key 不可用（HTTP ' . (int)$status . '）'
                . ($errMsg !== '' ? '，原因：' . mb_substr($errMsg, 0, 120) : '') . '，切换下一把');
        }
    }

    return $last;
}
