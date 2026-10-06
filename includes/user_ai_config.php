<?php
/**
 * 用户自带的 AI 接入配置（AI 助手「高级选项」）
 * ---------------------------------------------------------------------------
 * 目标：让愿意自己出钱/出额度的同学把**自己的**模型接进站内小助手，
 * 站长侧零成本（不动站点的 Key、不消耗站点配额），同时保证：
 *
 *   1. **密钥只进服务端、且落盘是加密的**。
 *      存 data/user_ai_config.json 的是 AES-256-CBC 密文，密钥由
 *      config/sync_config.php 的 secret_key（该文件已 .gitignore）派生。
 *      接口返回给前端的一律是脱敏值（只留末 4 位）。
 *   2. **防 SSRF**。用户填的 base_url 会被我们的服务器去请求，
 *      若不限制，等于给了内网探测通道（如 169.254.169.254 元数据服务）。
 *      所以强制 https、禁止 IP 直连、禁止内网域名、禁止非 443 端口。
 *   3. **一键还原**。删除自己的配置即刻回到站点默认模型，不留残留。
 *   4. **权限与站点模型完全一致**：自带模型同样只能走确认卡片，
 *      不会因为它「是你自己的 AI」就获得绕过确认的特权。
 */

if (!function_exists('lwUserAiSecret')) {
    /** 派生加密密钥（32 字节）。sync_config.php 缺失时退化为固定盐，保证不炸。 */
    function lwUserAiSecret(): string
    {
        static $k = null;
        if ($k !== null) {
            return $k;
        }
        $seed = '';
        $f = dirname(__DIR__) . '/config/sync_config.php';
        if (is_file($f)) {
            $c = @include $f;
            if (is_array($c) && !empty($c['secret_key'])) {
                $seed = (string)$c['secret_key'];
            }
        }
        // 没有 sync_config 时不报错：功能仍可用，只是密文强度依赖固定盐（本地开发可接受）
        $k = hash('sha256', $seed . '|user_ai_config|v1', true);
        return $k;
    }
}

if (!function_exists('lwUserAiEncrypt')) {
    function lwUserAiEncrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(16);
        $c = openssl_encrypt($plain, 'aes-256-cbc', lwUserAiSecret(), OPENSSL_RAW_DATA, $iv);
        if ($c === false) {
            return '';
        }
        return base64_encode($iv . $c);
    }
}

if (!function_exists('lwUserAiDecrypt')) {
    function lwUserAiDecrypt(string $blob): string
    {
        if ($blob === '') {
            return '';
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $p = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', lwUserAiSecret(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $p === false ? '' : $p;
    }
}

if (!function_exists('lwUserAiProviders')) {
    /**
     * 预置服务商。base_url 是 OpenAI 兼容的 /chat/completions 端点。
     * 只保留「站长零成本」的类别：用户自己注册、自己付费、自己承担额度。
     */
    function lwUserAiProviders(): array
    {
        return [
            'zhipu'   => [
                'label'    => '智谱 GLM（OpenAI 兼容）',
                'base_url' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
                'model'    => 'glm-4.1v-thinking-flash',
                'hint'     => 'open.bigmodel.cn 控制台拿 API Key',
            ],
            'deepseek' => [
                'label'    => 'DeepSeek',
                'base_url' => 'https://api.deepseek.com/chat/completions',
                'model'    => 'deepseek-chat',
                'hint'     => 'platform.deepseek.com 创建 API Key',
            ],
            'moonshot' => [
                'label'    => 'Moonshot Kimi',
                'base_url' => 'https://api.moonshot.cn/v1/chat/completions',
                'model'    => 'moonshot-v1-8k',
                'hint'     => 'platform.moonshot.cn 控制台',
            ],
            'siliconflow' => [
                'label'    => '硅基流动 SiliconFlow',
                'base_url' => 'https://api.siliconflow.cn/v1/chat/completions',
                'model'    => 'Qwen/Qwen2.5-7B-Instruct',
                'hint'     => 'cloud.siliconflow.cn，常有免费额度模型',
            ],
            'custom'  => [
                'label'    => '自定义（任意 OpenAI 兼容端点）',
                'base_url' => '',
                'model'    => '',
                'hint'     => '必须是 https 的 /chat/completions 地址',
            ],
        ];
    }
}

if (!function_exists('lwUserAiValidateBaseUrl')) {
    /**
     * 只接受「公网 https 域名」的 OpenAI 兼容端点。
     * 拒绝 http、IP 直连、内网/回环/链路本地地址、非 443 端口、带用户名的 URL。
     */
    function lwUserAiValidateBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 300) {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F\s]/', $url)) {
            return '';
        }
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        if (strtolower($p['scheme']) !== 'https') {
            return '';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return '';
        }
        if (isset($p['port']) && (int)$p['port'] !== 443) {
            return '';
        }
        $host = strtolower($p['host']);
        // 禁止 IP 直连（含十进制/十六进制写法），从根上掐掉内网探测
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }
        // 必须是像域名一样的字符串，且含点
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $host)) {
            return '';
        }
        // 兜底：解析出的 IP 若在私有/保留段内，仍然拒绝（防 DNS Rebinding 到内网）
        $ips = @gethostbynamel($host);
        if (is_array($ips)) {
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return '';
                }
            }
        }
        return $url;
    }
}

if (!function_exists('lwUserAiValidate')) {
    /**
     * 校验并规范化一份用户配置。
     * @return array{ok:bool,error:string,cfg:array}
     */
    function lwUserAiValidate(array $in, bool $requireKey): array
    {
        $providers = lwUserAiProviders();
        $provider = trim((string)($in['provider'] ?? ''));
        if (!isset($providers[$provider])) {
            return ['ok' => false, 'error' => '服务商选择无效', 'cfg' => []];
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
        if ($apiKey === '') {
            if ($requireKey) {
                return ['ok' => false, 'error' => '请填写 API Key', 'cfg' => []];
            }
        } else {
            if (mb_strlen($apiKey) > 300 || preg_match('/[\x00-\x1F\x7F]/', $apiKey)) {
                return ['ok' => false, 'error' => 'API Key 格式无效', 'cfg' => []];
            }
        }

        return [
            'ok'   => true,
            'error' => '',
            'cfg'  => [
                'provider' => $provider,
                'base_url' => $baseUrl,
                'model'    => $model,
                'api_key'  => $apiKey,
            ],
        ];
    }
}

if (!function_exists('lwUserAiRow')) {
    /** 取原始记录（未解密）。 */
    function lwUserAiRow(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        try {
            $row = getFS()->findOne('user_ai_config', ['user_id' => $userId]);
        } catch (Exception $e) {
            return null;
        }
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('lwUserAiConfig')) {
    /**
     * 取解密后的配置（**只在服务端使用，绝不外传**）。
     * @return array{user_id:int,enabled:bool,provider:string,base_url:string,model:string,api_key:string,updated_at:int}|null
     */
    function lwUserAiConfig(int $userId): ?array
    {
        $row = lwUserAiRow($userId);
        if (!$row) {
            return null;
        }
        $key = lwUserAiDecrypt((string)($row['api_key_enc'] ?? ''));
        if ($key === '') {
            return null; // 密钥解不出来（换了 secret_key）就当没配，避免拿空 Key 去请求
        }
        return [
            'user_id'    => (int)$row['user_id'],
            'enabled'    => !empty($row['enabled']),
            'provider'   => (string)($row['provider'] ?? 'custom'),
            'base_url'   => (string)($row['base_url'] ?? ''),
            'model'      => (string)($row['model'] ?? ''),
            'api_key'    => $key,
            'updated_at' => (int)($row['updated_at'] ?? 0),
            'last_error' => (string)($row['last_error'] ?? ''),
        ];
    }
}

if (!function_exists('lwUserAiPublic')) {
    /** 脱敏后给前端：API Key 只保留末 4 位。 */
    function lwUserAiPublic(?array $cfg): array
    {
        if (!$cfg) {
            return ['has' => false, 'enabled' => false];
        }
        $key = (string)$cfg['api_key'];
        return [
            'has'       => true,
            'enabled'   => !empty($cfg['enabled']),
            'provider'  => $cfg['provider'],
            'base_url'  => $cfg['base_url'],
            'model'     => $cfg['model'],
            'key_hint'  => $key !== '' ? '****' . mb_substr($key, -4) : '',
            'updated_at'=> (int)$cfg['updated_at'],
            'last_error'=> (string)($cfg['last_error'] ?? ''),
        ];
    }
}

if (!function_exists('lwUserAiSave')) {
    /**
     * 写入/更新一份配置。
     * @param array $in provider/base_url/model/api_key/enabled
     * @return array{ok:bool,error:string}
     */
    function lwUserAiSave(int $userId, array $in): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => '请先登录'];
        }
        $row = lwUserAiRow($userId);
        // 编辑时允许留空 Key = 沿用已保存的那把（前端用占位符表示未修改）
        $needKey = !$row || lwUserAiDecrypt((string)($row['api_key_enc'] ?? '')) === '';
        $v = lwUserAiValidate($in, $needKey);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => $v['error']];
        }
        $cfg = $v['cfg'];
        if ($cfg['api_key'] === '' && $row) {
            $cfg['api_key'] = lwUserAiDecrypt((string)($row['api_key_enc'] ?? ''));
        }

        $enc = lwUserAiEncrypt($cfg['api_key']);
        if ($enc === '') {
            return ['ok' => false, 'error' => '密钥加密失败，请联系管理员'];
        }

        $data = [
            'user_id'    => $userId,
            'enabled'    => !empty($in['enabled']) ? 1 : 0,
            'provider'   => $cfg['provider'],
            'base_url'   => $cfg['base_url'],
            'model'      => $cfg['model'],
            'api_key_enc'=> $enc,
            'updated_at' => time(),
            'last_error' => '',
        ];

        try {
            $fs = getFS();
            if ($row) {
                $fs->update('user_ai_config', (int)$row['id'], $data);
            } else {
                $fs->insert('user_ai_config', $data);
            }
        } catch (Exception $e) {
            error_log('[love_wall] 用户 AI 配置保存失败 uid=' . $userId . '：' . $e->getMessage());
            return ['ok' => false, 'error' => '保存失败，请稍后重试'];
        }
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('lwUserAiReset')) {
    /** 一键还原：删掉自己的配置，回到站点默认模型。 */
    function lwUserAiReset(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $row = lwUserAiRow($userId);
        if (!$row) {
            return true;
        }
        try {
            return (bool)getFS()->delete('user_ai_config', (int)$row['id']);
        } catch (Exception $e) {
            error_log('[love_wall] 用户 AI 配置还原失败 uid=' . $userId . '：' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('lwUserAiNoteError')) {
    /** 记一次调用失败，供前端「高级选项」里显示原因。只存摘要，不存密钥。 */
    function lwUserAiNoteError(int $userId, string $err): void
    {
        $row = lwUserAiRow($userId);
        if (!$row) {
            return;
        }
        try {
            getFS()->update('user_ai_config', (int)$row['id'], [
                'last_error' => mb_substr(trim($err), 0, 200),
            ]);
        } catch (Exception $e) {
            // 记录失败不影响主流程
        }
    }
}
