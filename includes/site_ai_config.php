<?php
/**
 * 站点默认 AI 配置（后台可改，config/ai_config.php 作兜底）
 * ---------------------------------------------------------------------------
 * 背景：原先站点 AI 的 Key / 模型只存在于 config/ai_config.php —— 该文件带密钥、
 * 已 .gitignore，改一次要登 FTP 手改。站长想换个模型得走文件，很不方便。
 *
 * 现在改成「三层取值」，优先级从高到低：
 *   1. data/site_ai_config.json —— 后台「AI 设置」页写入（本文件负责）
 *   2. config/ai_config.php      —— 部署时的初始配置 / 兜底
 *   3. 内置默认（智谱端点 + 空 Key，此时 AI 不可用但站点不炸）
 *
 * 安全口径：
 *   - API Key 在 data/site_ai_config.json 里是 **AES-256-CBC 密文**（复用
 *     user_ai_config.php 的加解密函数），接口回前端一律脱敏（只留末 4 位）。
 *   - 端点走与用户自带模型同一套 SSRF 白名单（lwUserAiValidateBaseUrl）。
 *   - 只有 super_admin 能读写；读不到/解不开时静默回退到 config 文件。
 */

require_once __DIR__ . '/user_ai_config.php';

if (!function_exists('lwSiteAiRow')) {
    /** 取后台配置的原始行（未解密）。 */
    function lwSiteAiRow(): ?array
    {
        try {
            return getFS()->findOne('site_ai_config', ['scope' => 'default']);
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('lwSiteAiFileConfig')) {
    /** 读 config/ai_config.php（兜底）。返回 [] 表示没有。 */
    function lwSiteAiFileConfig(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cfg = [];
        $f = dirname(__DIR__) . '/config/ai_config.php';
        if (is_file($f)) {
            $AICFG = [];
            require $f;
            if (is_array($AICFG)) {
                $cfg = $AICFG;
            }
        }
        $cache = $cfg;
        return $cache;
    }
}

if (!function_exists('lwSiteAiEffective')) {
    /**
     * 取「生效中」的站点 AI 配置（已解密 Key，**只在服务端使用，绝不外传**）。
     *
     * @return array{
     *   enabled:bool, source:string, provider:string, base_url:string,
     *   model:string, api_keys:string[], max_tokens:int
     * }
     */
    function lwSiteAiEffective(): array
    {
        $file = lwSiteAiFileConfig();
        $fileKeys = aiResolveApiKeys($file);
        $fileModel = trim((string)($file['model'] ?? ''));
        $fileBase = trim((string)($file['base_url'] ?? ''));

        // ③ 内置默认
        $out = [
            'enabled'   => true,
            'source'    => 'builtin',
            'provider'  => 'zhipu',
            'base_url'  => $fileBase !== '' ? $fileBase : 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
            'model'     => $fileModel,
            'api_keys'  => $fileKeys,
            'max_tokens'=> (int)($file['max_tokens'] ?? 0),
        ];

        // ② config 文件
        if ($fileKeys || $fileModel !== '') {
            $out['source'] = 'file';
        }

        // ① 后台配置（最高优先级，且按字段逐项覆盖：后台只改模型时，Key 仍用文件里的）
        $row = lwSiteAiRow();
        if ($row) {
            $out['source'] = 'admin';
            $out['enabled'] = !empty($row['enabled']);
            $out['provider'] = (string)($row['provider'] ?? $out['provider']);

            $adminKey = lwUserAiDecrypt((string)($row['api_key_enc'] ?? ''));
            if ($adminKey !== '') {
                // 备用 Key 在库里是「一行一个」的字符串，必须先拆成数组，
                // 否则整串会被当成一把无效 Key（曾导致只有主 Key 生效）。
                $extraLines = preg_split('/[\r\n]+/', (string)($row['api_key_extra'] ?? ''));
                $extraLines = array_values(array_filter(array_map('trim', $extraLines), function ($k) {
                    return $k !== '';
                }));
                $keys = array_merge([$adminKey], $extraLines);
                $out['api_keys'] = array_values(array_unique(array_filter($keys)));
            }
            $m = trim((string)($row['model'] ?? ''));
            if ($m !== '') {
                $out['model'] = $m;
            }
            $b = trim((string)($row['base_url'] ?? ''));
            if ($b !== '') {
                $out['base_url'] = $b;
            }
            if (isset($row['max_tokens'])) {
                $out['max_tokens'] = (int)$row['max_tokens'];
            }
        }

        return $out;
    }
}

if (!function_exists('lwSiteAiPublic')) {
    /** 脱敏后给后台页面展示：Key 只留末 4 位，多 Key 只报数量。 */
    function lwSiteAiPublic(): array
    {
        $cfg = lwSiteAiEffective();
        $keys = $cfg['api_keys'];
        $hint = '';
        if ($keys) {
            $first = (string)$keys[0];
            $hint = mb_strlen($first) > 4 ? '****' . mb_substr($first, -4) : '****';
            if (count($keys) > 1) {
                $hint .= ' 等 ' . count($keys) . ' 把';
            }
        }
        return [
            'enabled'     => $cfg['enabled'],
            'source'      => $cfg['source'],       // builtin | file | admin
            'provider'    => $cfg['provider'],
            'base_url'    => $cfg['base_url'],
            'model'       => $cfg['model'],
            'key_hint'    => $hint,
            'key_count'   => count($keys),
            'has_key'     => count($keys) > 0,
            'max_tokens'  => $cfg['max_tokens'],
            'ready'       => $cfg['enabled'] && count($keys) > 0 && $cfg['model'] !== '',
        ];
    }
}

if (!function_exists('lwSiteAiValidate')) {
    /**
     * 校验后台提交的配置。
     * @return array{ok:bool,error:string,cfg:array}
     */
    function lwSiteAiValidate(array $in, bool $requireKey): array
    {
        $providers = lwUserAiProviders();
        $provider = trim((string)($in['provider'] ?? 'custom'));
        if (!isset($providers[$provider])) {
            $provider = 'custom';
        }

        $baseUrl = lwUserAiValidateBaseUrl((string)($in['base_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'error' => '接口地址无效：必须是 https 的公网域名（不能是 IP、内网地址或非 443 端口）', 'cfg' => []];
        }

        $model = trim((string)($in['model'] ?? ''));
        if ($model === '' || mb_strlen($model) > 80 || preg_match('/[\x00-\x1F\x7F\s]/', $model)) {
            return ['ok' => false, 'error' => '模型名无效（1-80 字符，不能含空格或换行）', 'cfg' => []];
        }

        $apiKey = trim((string)($in['api_key'] ?? ''));
        if ($apiKey !== '' && (mb_strlen($apiKey) > 300 || preg_match('/[\x00-\x1F\x7F]/', $apiKey))) {
            return ['ok' => false, 'error' => 'API Key 格式无效', 'cfg' => []];
        }
        if ($apiKey === '' && $requireKey) {
            return ['ok' => false, 'error' => '请填写 API Key', 'cfg' => []];
        }

        // 备用 Key（可空）：一行一个，最多 5 把，用于轮换
        $extraRaw = (string)($in['api_key_extra'] ?? '');
        $extraKeys = [];
        foreach (preg_split('/[\r\n]+/', $extraRaw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (mb_strlen($line) > 300 || preg_match('/[\x00-\x1F\x7F]/', $line)) {
                return ['ok' => false, 'error' => '备用 Key 格式无效', 'cfg' => []];
            }
            $extraKeys[] = $line;
            if (count($extraKeys) > 5) {
                return ['ok' => false, 'error' => '备用 Key 最多 5 把', 'cfg' => []];
            }
        }

        $maxTokens = (int)($in['max_tokens'] ?? 0);
        if ($maxTokens < 0 || $maxTokens > 32000) {
            return ['ok' => false, 'error' => 'max_tokens 需在 0-32000 之间（0 = 不限）', 'cfg' => []];
        }

        return [
            'ok' => true,
            'error' => '',
            'cfg' => [
                'provider'      => $provider,
                'base_url'      => $baseUrl,
                'model'         => $model,
                'api_key'       => $apiKey,
                'api_key_extra' => implode("\n", $extraKeys),
                'max_tokens'    => $maxTokens,
            ],
        ];
    }
}

if (!function_exists('lwSiteAiSave')) {
    /**
     * 写入后台配置（仅 super_admin 调用）。
     * 编辑时留空 Key = 沿用已保存的那把。
     * @return array{ok:bool,error:string}
     */
    function lwSiteAiSave(array $in): array
    {
        $row = lwSiteAiRow();
        $existingKey = $row ? lwUserAiDecrypt((string)($row['api_key_enc'] ?? '')) : '';
        $needKey = $existingKey === '' && !aiResolveApiKeys(lwSiteAiFileConfig());

        $v = lwSiteAiValidate($in, $needKey);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => $v['error']];
        }
        $cfg = $v['cfg'];
        if ($cfg['api_key'] === '' && $existingKey !== '') {
            $cfg['api_key'] = $existingKey;
        }

        $enc = $cfg['api_key'] !== '' ? lwUserAiEncrypt($cfg['api_key']) : '';
        if ($cfg['api_key'] !== '' && $enc === '') {
            return ['ok' => false, 'error' => '密钥加密失败，请联系管理员'];
        }

        $data = [
            'scope'         => 'default',
            'enabled'       => !empty($in['enabled']) ? 1 : 0,
            'provider'      => $cfg['provider'],
            'base_url'      => $cfg['base_url'],
            'model'         => $cfg['model'],
            'api_key_enc'   => $enc,
            'api_key_extra' => $cfg['api_key_extra'],
            'max_tokens'    => $cfg['max_tokens'],
            'updated_at'    => time(),
        ];

        try {
            $fs = getFS();
            if ($row) {
                $fs->update('site_ai_config', (int)$row['id'], $data);
            } else {
                $fs->insert('site_ai_config', $data);
            }
        } catch (Exception $e) {
            error_log('[love_wall] 站点 AI 配置保存失败：' . $e->getMessage());
            return ['ok' => false, 'error' => '保存失败，请稍后重试'];
        }
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('lwSiteAiTest')) {
    /**
     * 用当前生效配置做一次最小连通性测试（1 token 级别）。
     * @return array{ok:bool,error:string,http:int,elapsed_ms:int,reply:string}
     */
    function lwSiteAiTest(): array
    {
        $cfg = lwSiteAiEffective();
        if (!$cfg['enabled']) {
            return ['ok' => false, 'error' => '站点 AI 当前处于关闭状态', 'http' => 0, 'elapsed_ms' => 0, 'reply' => ''];
        }
        if (!$cfg['api_keys']) {
            return ['ok' => false, 'error' => '未配置 API Key', 'http' => 0, 'elapsed_ms' => 0, 'reply' => ''];
        }
        if ($cfg['model'] === '') {
            return ['ok' => false, 'error' => '未配置模型名', 'http' => 0, 'elapsed_ms' => 0, 'reply' => ''];
        }

        $t0 = microtime(true);
        $cfgTest = [
            'api_keys'   => $cfg['api_keys'],
            'model'      => $cfg['model'],
            'base_url'   => $cfg['base_url'],
            'max_tokens' => 8,
        ];
        $r = aiChatCompletion($cfgTest, [['role' => 'user', 'content' => '回复"ok"两个字符即可']], 15);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => (string)($r['error'] ?? '请求失败'), 'http' => (int)($r['status'] ?? 0), 'elapsed_ms' => $ms, 'reply' => ''];
        }
        $resp = is_array($r['resp'] ?? null) ? $r['resp'] : [];
        $reply = (string)($resp['choices'][0]['message']['content'] ?? '');
        return [
            'ok' => true,
            'error' => '',
            'http' => 200,
            'elapsed_ms' => $ms,
            'reply' => mb_substr(trim($reply), 0, 60),
        ];
    }
}
