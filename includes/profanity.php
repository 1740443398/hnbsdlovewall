<?php
/**
 * 敏感词审查（uapis.cn profanitycheck）
 * ===========================================================================
 * 【为什么换掉原来的 AI 审查】
 *   站长明确要求用这个接口替换 AI 审查步骤。对比下来它确实更适合本站：
 *     · AI 接口 1~3 秒，还要 key、要额度、会抽风 → 只能异步「捞漏网」
 *     · 这个接口**实测 0.4 秒**、无需 key、直接给命中词 → 可以当**同步门禁**
 *   而且它给的是确定性结论（命中/未命中 + 具体词），比 AI 的分数更好解释、
 *   也不会因为模型抽风把正常帖子打下去。
 *
 * 【策略】
 *   命中敏感词 → status = 'pending'（进后台审核队列，用户看到「审核中」）
 *   未命中     → published（正常发布）
 *   接口挂掉   → **放行**（fail-open）。理由：审核接口不可用不能等于全站禁言，
 *                否则接口一抖整个站就发不出帖；后台仍可在「待审」里人工抽查。
 *
 * 【适用范围】
 *   · 用户发帖（includes/actions/post_create.php）
 *   · 用户评论（includes/actions/post_comment.php）
 *   · QQ 动态导入（includes/qzone_posts.php）—— 站长要求「都要过这一步」
 */

if (!defined('LW_PROFANITY_LOADED')) {
    define('LW_PROFANITY_LOADED', 1);

    if (!function_exists('lwProfanityApiUrl')) {
        function lwProfanityApiUrl(): string
        {
            $u = function_exists('getSetting') ? (string) getSetting('profanity_api_url', '') : '';
            return $u !== '' ? $u : 'https://uapis.cn/api/v1/text/profanitycheck';
        }
    }

    if (!function_exists('lwProfanityEnabled')) {
        function lwProfanityEnabled(): bool
        {
            if (function_exists('getSetting')) {
                return getSetting('profanity_check_enabled', '1') !== '0';
            }
            return true;
        }
    }

    if (!function_exists('lwProfanityCheck')) {
        /**
         * 检查一段文本。返回：
         *   ['ok'=>bool, 'forbidden'=>bool, 'words'=>[], 'masked'=>'', 'err'=>'']
         * ok=false 表示接口不可用（调用方按 fail-open 处理）。
         */
        function lwProfanityCheck(string $text): array
        {
            $text = trim($text);
            if ($text === '') {
                return ['ok' => true, 'forbidden' => false, 'words' => [], 'masked' => '', 'err' => ''];
            }
            /* 接口上限 256KB；正常帖子远小于此，超长直接截断（只查前 20000 字） */
            if (mb_strlen($text) > 20000) {
                $text = mb_substr($text, 0, 20000);
            }

            $payload = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
            $ch = curl_init(lwProfanityApiUrl());
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = (string) curl_error($ch);
            curl_close($ch);

            if (!is_string($body) || $body === '' || $code !== 200) {
                return ['ok' => false, 'forbidden' => false, 'words' => [], 'masked' => '',
                        'err' => 'HTTP ' . $code . ' ' . $err];
            }
            $j = json_decode($body, true);
            if (!is_array($j)) {
                return ['ok' => false, 'forbidden' => false, 'words' => [], 'masked' => '', 'err' => '响应不是 JSON'];
            }
            $status = strtolower((string) ($j['status'] ?? ''));
            $words  = [];
            if (!empty($j['forbidden_words']) && is_array($j['forbidden_words'])) {
                foreach ($j['forbidden_words'] as $w) {
                    $w = trim((string) $w);
                    if ($w !== '') { $words[] = $w; }
                }
            }
            $forbidden = ($status === 'forbidden') || !empty($words);
            return [
                'ok'        => true,
                'forbidden' => $forbidden,
                'words'     => array_values(array_unique($words)),
                'masked'    => (string) ($j['masked_text'] ?? ''),
                'err'       => '',
            ];
        }
    }

    if (!function_exists('lwProfanityModerationFields')) {
        /** 命中时的审核字段（写进 posts/comments，供后台按类型筛选） */
        function lwProfanityModerationFields(array $res): array
        {
            if (empty($res['forbidden'])) { return []; }
            $words = (array) ($res['words'] ?? []);
            return [
                'mod_score'  => 100,
                'mod_types'  => ['profanity' => '敏感词'],
                'mod_reason' => '命中敏感词：' . implode('、', array_slice($words, 0, 10))
                              . (count($words) > 10 ? ' 等' : ''),
                'mod_words'  => $words,
                'mod_source' => 'profanity_api',
                'mod_at'     => date('Y-m-d H:i:s'),
            ];
        }
    }
}
