<?php
/**
 * 封禁提示页 —— 被 WAF 临时拦截时展示的独立页面。
 * ---------------------------------------------------------------------------
 * 为什么单独做一页而不是 `die('Access Denied')`：
 *   原来被拦截的访客只看到一行英文，既不知道发生了什么、也不知道什么时候能恢复、
 *   更不知道该找谁 —— 连站长自己被误封时都一头雾水。
 *   现在给出：命中策略（带代号 + 专业表述 + 通俗说明）、事件编号、受限的出口 IP、
 *   **精确解封时间**与实时倒计时、站长 QQ。
 *
 * 文案分三层，兼顾"专业"与"看得懂"：
 *   1) 策略代号   RATE-ANOMALY                —— 一眼看出性质，便于沟通与检索
 *   2) 专业表述   「访问密度异常：单位统计周期内的页面请求分布超出正常浏览行为基线」
 *   3) 通俗说明   「短时间内连续打开了过多页面」—— 大字专业、小字解释，不牺牲可读性
 *
 * 约束：
 *   1) 本文件在 WAF 最早期被调用，此刻应用可能尚未初始化完 —— 只能用 PHP 内置函数，
 *      不 require 任何其它文件、不依赖 site.css / 任何静态资源，样式全部内联。
 *   2) 只输出「访客自己的信息」（自己的 IP、自己命中的策略、解封时间），不外泄任何站点数据。
 *   3) 供 `WAF::rejectBanned()` 与 `config/config.php` 的 antiCrawlerCheck() 共用。
 */

if (!defined('LW_BAN_CONTACT_QQ')) {
    define('LW_BAN_CONTACT_QQ', '1740443398');
}

if (!function_exists('lwBanStrategy')) {
    /**
     * 策略表：reason 代号 → [代号标签, 专业表述, 通俗说明]
     * 新增风控规则时在这里补一行即可，页面与邮件共用同一份文案。
     */
    function lwBanStrategy(string $reason): array
    {
        $r = strtolower(trim($reason));

        $map = [
            'content_scraping' => [
                'RATE-ANOMALY', '访问密度异常',
                '单位统计周期内的页面请求分布超出正常浏览行为基线',
                '短时间内连续打开了过多页面',
            ],
            'ip_rotation' => [
                'SESSION-DRIFT', '会话网络漂移',
                '同一已认证会话的网络出口在短时间内发生多次迁移，超出会话连续性预期',
                '登录后网络出口地址连续变化（如校园网多出口或移动网络切换）',
            ],
            'scanner_detected' => [
                'SCANNER-FP', '自动化扫描特征命中',
                '请求头与访问序列与已知自动化扫描工具的指纹高度相似',
                '访问方式与扫描工具的特征相符',
            ],
            'rate_limit_flood' => [
                'RATE-LIMIT', '请求速率越限',
                '单位时间内的请求总量超出站点防护策略设定的上限',
                '单位时间内请求次数过多',
            ],
            'api_rate_limit_flood' => [
                'API-RATE', '接口调用速率越限',
                '单位时间内的接口调用量超出防护策略设定的上限',
                '短时间内调用了太多次接口',
            ],
            'ddos_protection' => [
                'SESSION-FLOOD', '会话请求密度越限',
                '同一会话在统计窗口内的请求频次超出防护策略设定的上限',
                '同一个会话短时间内发送了过多请求',
            ],
            'traversal_scan' => [
                'ID-ENUM', '资源枚举特征',
                '短时间内连续请求大量离散资源标识，呈现枚举式遍历特征',
                '短时间内连续访问了大量不同的内容编号',
            ],
            'fingerprint_anomaly' => [
                'FP-JITTER', '客户端指纹抖动',
                '客户端特征标识在极短时间窗内反复变化，不符合单一设备的稳定特征',
                '浏览器特征在极短时间内反复变化',
            ],
            'path_traversal' => [
                'PATH-TRAV', '越权路径片段',
                '请求路径中包含指向父级目录的跳转片段，命中路径安全校验',
                '请求地址中包含异常的路径片段',
            ],
            'sensitive_file_probe' => [
                'PROBE-DENY', '非公开路径探测',
                '请求目标命中站点未对外开放的路径清单，触发敏感资源访问拦截',
                '访问了站点不对外开放的路径',
            ],
            'sql_injection_attempt' => [
                'INJ-SQL', '注入特征命中',
                '请求参数命中注入攻击特征库',
                '请求中带有疑似注入的片段',
            ],
            'xss_attempt' => [
                'INJ-XSS', '脚本注入特征命中',
                '请求参数命中跨站脚本攻击特征库',
                '请求中带有疑似脚本注入的片段',
            ],
            'command_injection_attempt' => [
                'INJ-CMD', '命令注入特征命中',
                '请求参数命中系统命令注入攻击特征库',
                '请求中带有疑似命令注入的片段',
            ],
            'malicious_ua' => [
                'UA-BLACKLIST', '客户端标识命中黑名单',
                '客户端标识命中已知恶意采集与攻击工具的特征库',
                '浏览器标识与已知的恶意采集工具相符',
            ],
            'empty_user_agent' => [
                'UA-MISSING', '客户端标识缺失',
                '请求未携带客户端标识，不符合正常浏览器的行为特征',
                '请求没有携带浏览器标识',
            ],
            'empty_referer_post' => [
                'ORIGIN-CHECK', '来源校验未通过',
                '表单提交缺少合法来源标识，未通过来源一致性校验',
                '提交表单时缺少必要的来源信息',
            ],
            'cross_origin_referer_admin' => [
                'ADMIN-CORS', '管理端点跨站访问',
                '检测到跨站来源直接访问站点管理端点',
                '从站外地址直接访问了管理后台',
            ],
            'blocked_method' => [
                'METHOD-DENY', '请求方法被拒',
                '请求方法命中站点方法白名单策略之外，已被拒绝',
                '使用了站点不允许的请求方式',
            ],
            'unknown_method' => [
                'METHOD-UNKNOWN', '请求方法无法识别',
                '请求方法无法被识别，命中方法校验策略',
                '使用了无法识别的请求方式',
            ],
            'url_too_long' => [
                'URI-LENGTH', '请求地址超长',
                '请求地址长度超出防护策略设定的上限',
                '请求地址过长',
            ],
            'post_too_large' => [
                'BODY-SIZE', '请求体超限',
                '请求体体积超出防护策略设定的上限',
                '一次提交的数据量过大',
            ],
            'ua_too_long' => [
                'UA-LENGTH', '客户端标识异常',
                '客户端标识长度异常，命中异常特征校验',
                '浏览器标识异常过长',
            ],
        ];

        if (isset($map[$r])) {
            return $map[$r];
        }

        // repeated_xxx：同一策略在统计窗口内被重复命中，触发累计升级
        if (strpos($r, 'repeated_') === 0) {
            $inner = substr($r, 9);
            $innerName = isset($map[$inner]) ? $map[$inner][1] : '同类防护策略';
            return [
                'REPEAT-UPGRADE', '累计命中升级',
                '同一防护策略在统计窗口内被重复命中并达到升级阈值（来源策略：' . $innerName . '）',
                '同类规则被反复触发，因此提升了处理等级',
            ];
        }

        return [
            'POLICY-HIT', '防护策略命中',
            '本次访问命中站点自动防护策略，已被临时拦截',
            '触发了站点的自动访问限制规则',
        ];
    }
}

if (!function_exists('lwBanReasonCode')) {
    /** 策略代号，如 RATE-ANOMALY */
    function lwBanReasonCode(string $reason): string
    {
        return lwBanStrategy($reason)[0];
    }
}

if (!function_exists('lwBanReasonLabel')) {
    /** 只要策略的简短名（如「访问密度异常」），用于接口提示这种一行字的场景 */
    function lwBanReasonLabel(string $reason): string
    {
        return lwBanStrategy($reason)[1];
    }
}

if (!function_exists('lwBanReasonText')) {
    /** 专业表述（页面与邮件共用） */
    function lwBanReasonText(string $reason): string
    {
        $s = lwBanStrategy($reason);
        return $s[1] . '：' . $s[2];
    }
}

if (!function_exists('lwBanReasonHint')) {
    /** 通俗说明：给非技术访客看的一句话解释 */
    function lwBanReasonHint(string $reason): string
    {
        return lwBanStrategy($reason)[3];
    }
}

if (!function_exists('lwBanRefId')) {
    /**
     * 事件编号：由「IP + 封禁到期时间」派生，同一次封禁稳定不变。
     * 访客联系站长时报上这个编号，站长能在 waf_logs / ip_blacklist 里快速定位。
     * 只取 8 位大写十六进制，便于口头传达。
     */
    function lwBanRefId(string $ip, int $expireAt): string
    {
        return 'LW-' . strtoupper(substr(md5($ip . '|' . $expireAt), 0, 8));
    }
}

if (!function_exists('lwBanFormatDuration')) {
    /** 把秒数写成「X 分钟」/「X 小时 Y 分钟」 */
    function lwBanFormatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '已到期';
        }
        if ($seconds < 60) {
            return $seconds . ' 秒';
        }
        $m = intdiv($seconds, 60);
        if ($m < 60) {
            return $m . ' 分钟';
        }
        $h = intdiv($m, 60);
        $rest = $m % 60;
        return $rest > 0 ? ($h . ' 小时 ' . $rest . ' 分钟') : ($h . ' 小时');
    }
}

if (!function_exists('lwRenderBanPage')) {
    /**
     * 输出封禁页并结束请求。
     *
     * @param array  $entry 黑名单条目（含 reason / expire_at），可为空数组
     * @param string $ip    访客 IP（展示给访客自己）
     */
    function lwRenderBanPage(array $entry = [], string $ip = ''): void
    {
        $reason    = (string)($entry['reason'] ?? '');
        $expireAt  = (int)($entry['expire_at'] ?? 0);
        $code      = lwBanReasonCode($reason);
        $reasonTxt = lwBanReasonText($reason);
        $hintTxt   = lwBanReasonHint($reason);
        $now       = time();
        $remain    = $expireAt > $now ? ($expireAt - $now) : 0;

        // 永久封禁（expire_at 为 0）与临时封禁分开表达，不要把 0 当成"已过期"
        $isPermanent = ($expireAt <= 0);
        $expireText  = $isPermanent
            ? '需站长人工解除'
            : date('Y-m-d H:i:s', $expireAt) . '（' . date('P', $expireAt) . '）';
        $remainText  = $isPermanent ? '不确定' : lwBanFormatDuration($remain);
        $refId       = lwBanRefId($ip, $expireAt);

        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow');
            if (!$isPermanent) {
                header('Retry-After: ' . max(1, $remain));
            }
        }

        $qq = LW_BAN_CONTACT_QQ;
        $e  = function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>访问已被临时限制</title>
<style>
:root{color-scheme:light dark}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
 font-family:'Microsoft YaHei','PingFang SC','Noto Sans SC',system-ui,sans-serif;
 background:linear-gradient(160deg,#080D19 0%,#101a2e 55%,#16294a 100%);color:#E8EDF7}
.card{width:100%;max-width:580px;background:rgba(23,32,50,.94);border:1px solid rgba(201,169,110,.28);
 border-radius:18px;padding:32px 30px 26px;box-shadow:0 24px 60px -24px rgba(0,0,0,.75)}
.shield{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;margin-bottom:16px;
 background:linear-gradient(135deg,rgba(201,169,110,.22),rgba(201,169,110,.06));border:1px solid rgba(201,169,110,.35)}
.shield svg{width:24px;height:24px;stroke:#C9A96E}
.kicker{font-family:Consolas,'JetBrains Mono',monospace;font-size:.68rem;letter-spacing:1.6px;
 text-transform:uppercase;color:#C9A96E;margin:0 0 8px}
h1{margin:0 0 12px;font-size:1.36rem;letter-spacing:.4px;color:#fff;font-weight:700}
.lead{margin:0 0 6px;font-size:.9rem;line-height:1.8;color:#9AA7B8}
.lead b{color:#E8EDF7;font-weight:600}
.assure{margin:0 0 20px;font-size:.86rem;line-height:1.7;color:#E8D5A3}
.rows{border-top:1px solid rgba(255,255,255,.09);border-bottom:1px solid rgba(255,255,255,.09);
 padding:4px 0;margin:0 0 18px}
.row{display:flex;gap:16px;align-items:flex-start;padding:10px 0;font-size:.86rem}
.row+.row{border-top:1px dashed rgba(255,255,255,.07)}
.row .k{flex:0 0 76px;color:#7f8ea3;padding-top:1px}
.row .v{flex:1 1 auto;color:#E8EDF7;word-break:break-word;line-height:1.7}
.row .v.hl{color:#E8D5A3;font-weight:700}
.row .v.mono,.code{font-family:Consolas,'JetBrains Mono',monospace;letter-spacing:.4px}
.code{display:inline-block;font-size:.74rem;font-weight:700;color:#E8D5A3;
 background:rgba(201,169,110,.13);border:1px solid rgba(201,169,110,.32);
 border-radius:6px;padding:2px 8px;margin-bottom:6px}
.hint{display:block;margin-top:4px;font-size:.79rem;color:#7f8ea3}
.tip{margin:0 0 10px;font-size:.82rem;line-height:1.85;color:#9AA7B8}
.tip:last-child{margin-bottom:0}
.tip a{color:#C9A96E;font-weight:700;text-decoration:none;border-bottom:1px dashed rgba(201,169,110,.5)}
.actions{margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
button{font:inherit;font-size:.85rem;font-weight:600;color:#0b1220;cursor:pointer;
 background:linear-gradient(135deg,#E8D5A3,#C9A96E);border:none;border-radius:999px;padding:10px 20px;
 transition:transform .16s ease,box-shadow .16s ease}
button:hover{transform:translateY(-1px);box-shadow:0 8px 20px -8px rgba(201,169,110,.7)}
button:active{transform:scale(.97)}
.cd{font-size:.82rem;color:#9AA7B8}
.cd b{color:#E8D5A3;font-variant-numeric:tabular-nums}
@media (prefers-color-scheme:light){
 body{background:linear-gradient(160deg,#eef2f9 0%,#e6ecf6 60%,#dfe7f3 100%);color:#1d2735}
 .card{background:#fff;border-color:#e2e8f2;box-shadow:0 20px 50px -26px rgba(16,24,40,.35)}
 .shield{background:linear-gradient(135deg,rgba(154,123,50,.14),rgba(154,123,50,.04));border-color:rgba(154,123,50,.3)}
 .shield svg{stroke:#9a7b32}
 .kicker{color:#9a7b32}
 h1{color:#1d2735}
 .lead,.tip,.cd{color:#5b6879}
 .lead b{color:#1d2735}
 .assure{color:#8a6a24}
 .row .k{color:#7c8a9c}
 .row .v{color:#1d2735}
 .row .v.hl{color:#8a6a24}
 .rows{border-color:#eaeff6}
 .row+.row{border-top-color:#eef2f8}
 .code{color:#8a6a24;background:#faf5e8;border-color:#e8dcc0}
 .hint{color:#7c8a9c}
 .tip a{color:#8a6a24;border-bottom-color:rgba(138,106,36,.45)}
}
</style>
</head>
<body>
<main class="card">
  <div class="shield" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 8v4"/><path d="M12 16h.01"/>
    </svg>
  </div>

  <p class="kicker">Security Gate · Access Restricted</p>
  <h1>访问已被临时限制</h1>
  <p class="lead">
    系统检测到来自你当前网络的访问命中了站点的自动防护策略，已对该地址实施
    <b><?= $isPermanent ? '限制' : '临时拦截' ?></b>。
  </p>
  <p class="assure">这不是账号封禁 —— 你的账号、动态与数据均完好无损。</p>

  <div class="rows">
    <div class="row">
      <span class="k">命中策略</span>
      <span class="v">
        <span class="code"><?= $e($code) ?></span><br><?= $e($reasonTxt) ?>
        <span class="hint">通俗说明：<?= $e($hintTxt) ?></span>
      </span>
    </div>
    <div class="row"><span class="k">受限网络</span><span class="v mono"><?= $e($ip) ?></span></div>
    <div class="row"><span class="k">解封时间</span><span class="v hl"><?= $e($expireText) ?></span></div>
    <div class="row"><span class="k">剩余时长</span><span class="v cd" id="cd">约 <?= $e($remainText) ?></span></div>
    <div class="row"><span class="k">事件编号</span><span class="v mono"><?= $e($refId) ?></span></div>
  </div>

  <?php if (!$isPermanent): ?>
  <p class="tip">无需任何操作，到达解封时间后自动恢复。若你正开着页面，等到时间后刷新即可继续使用。</p>
  <?php else: ?>
  <p class="tip">该限制需要站长人工解除，请通过下方方式联系站长。</p>
  <?php endif; ?>

  <p class="tip">
    认为属于误判（例如校园网 / 运营商共享出口，或短时间内自己连续翻看了几页）？请联系站长
    <a href="tencent://message/?uin=<?= $e($qq) ?>&amp;site=qq&amp;menu=yes" rel="noopener">QQ <?= $e($qq) ?></a>，
    并附上上面的「事件编号」与「受限网络」，站长可据此快速定位并处理。
  </p>

  <div class="actions">
    <button type="button" onclick="location.reload()">刷新页面</button>
    <span class="cd" id="cd2"></span>
  </div>
</main>
<?php if (!$isPermanent): ?>
<script>
(function () {
  var left = <?= (int)$remain ?>;
  var a = document.getElementById('cd');
  var b = document.getElementById('cd2');
  function fmt(s) {
    if (s <= 0) return '已到期，刷新即可';
    var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), ss = s % 60;
    var out = [];
    if (d) out.push(d + ' 天');
    if (h) out.push(h + ' 小时');
    if (m) out.push(m + ' 分');
    out.push(ss + ' 秒');
    return '约 ' + out.join(' ');
  }
  function tick() {
    if (a) a.textContent = fmt(left);
    if (b) b.textContent = fmt(left);
    if (left > 0) { left--; }
    else { clearInterval(t);
      if (b) b.textContent = '已到期，点左侧按钮刷新';
    }
  }
  tick();
  var t = setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
        <?php
        exit;
    }
}
