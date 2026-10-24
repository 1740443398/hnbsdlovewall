<?php
/**
 * 后台「自适应布局」配置（需求三、5）
 * ---------------------------------------------------------------------------
 * 「后台内置自定义配置项，支持自定义参数修改，页面自适应规则可配置调整」
 *
 * 这里暴露的是**响应式断点与容器宽度**这几个真正影响"页面长什么样"的参数：
 *   - 断点（mobile / tablet / desktop）决定导航形态、栅格列数；
 *   - 内容最大宽度决定阅读版心；
 *   - 字号缩放让低配设备/小屏能整体调大或调小。
 *
 * 实现方式：后台存 settings（键 layout_*），前台由 includes/layout_tokens.php
 * 输出成 CSS 变量 + 一条 @media，无需改任何 CSS 文件。
 *
 * 为什么不用图形化拖拽：那会把"样式"变成不可审计的数据。参数化断点是
 * 可读、可回滚、可校验的，改动一条就能解释清楚。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if ($adminUser['role'] !== 'super_admin') {
    http_response_code(403);
    die('403 Forbidden - 仅超级管理员可访问');
}

$fs = getFS();
$map = [];
foreach ((array)$fs->read('settings') as $s) {
    $map[(string)($s['setting_key'] ?? '')] = (string)($s['setting_value'] ?? '');
}

$defaults = [
    'layout_breakpoint_mobile'  => '640',
    'layout_breakpoint_tablet'  => '1024',
    'layout_content_max_width'  => '900',
    'layout_font_scale'         => '100',
    'layout_card_radius'        => '16',
    'layout_compact_mode'       => '0',
    'layout_space_scale'        => '75',
];
foreach ($defaults as $k => $v) {
    if (!isset($map[$k])) {
        $map[$k] = $v;
    }
}

adminHeader('自适应布局', $adminUser, $csrfToken);
?>

<div class="section">
    <div class="section-header"><h3>响应式断点</h3></div>
    <div class="section-body">
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px;">
            断点决定「手机 / 平板 / 桌面」三档布局的切换位置。改动后全站立即生效，无需改代码。
        </p>
        <div class="filter-row">
            <div class="form-group">
                <label>手机断点（px，建议 480-768）</label>
                <input type="number" id="bpMobile" min="360" max="1200" step="10" value="<?= (int)$map['layout_breakpoint_mobile'] ?>">
            </div>
            <div class="form-group">
                <label>平板断点（px，建议 768-1280）</label>
                <input type="number" id="bpTablet" min="640" max="1600" step="10" value="<?= (int)$map['layout_breakpoint_tablet'] ?>">
            </div>
            <button class="btn btn-primary btn-sm" onclick="saveLayout()">保存断点</button>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>版心与排版</h3></div>
    <div class="section-body">
        <div class="filter-row">
            <div class="form-group">
                <label>内容最大宽度（px，建议 760-1200）</label>
                <input type="number" id="contentMax" min="600" max="1600" step="20" value="<?= (int)$map['layout_content_max_width'] ?>">
            </div>
            <div class="form-group">
                <label>全局字号缩放（%，80-130）</label>
                <input type="number" id="fontScale" min="80" max="130" step="5" value="<?= (int)$map['layout_font_scale'] ?>">
            </div>
            <div class="form-group">
                <label>卡片圆角（px，0-28）</label>
                <input type="number" id="cardRadius" min="0" max="28" step="2" value="<?= (int)$map['layout_card_radius'] ?>">
            </div>
            <div class="form-group">
                <label>紧凑模式（低配设备推荐）</label>
                <select id="compactMode">
                    <option value="0" <?= $map['layout_compact_mode'] === '0' ? 'selected' : '' ?>>关闭</option>
                    <option value="1" <?= $map['layout_compact_mode'] === '1' ? 'selected' : '' ?>>开启（减少留白与阴影）</option>
                </select>
            </div>
            <div class="form-group">
                <label>紧凑留白比例（%，50-100，仅紧凑模式生效）</label>
                <input type="number" id="spaceScale" min="50" max="100" step="5" value="<?= (int)$map['layout_space_scale'] ?>">
            </div>
            <button class="btn btn-primary btn-sm" onclick="saveLayout()">保存排版</button>
        </div>
        <p style="font-size:12px;color:var(--text-secondary);margin-top:12px;">
            提示：字号缩放会等比影响全站文字；紧凑模式会收紧间距并关闭部分装饰阴影，
            适合低性能设备或小屏手机。
        </p>
    </div>
</div>

<script>
var KEYS = {
    layout_breakpoint_mobile: 'bpMobile',
    layout_breakpoint_tablet: 'bpTablet',
    layout_content_max_width: 'contentMax',
    layout_font_scale: 'fontScale',
    layout_card_radius: 'cardRadius',
    layout_compact_mode: 'compactMode',
    layout_space_scale: 'spaceScale'
};

function saveOne(k, v) {
    var body = new URLSearchParams({ csrf_token: CSRF_TOKEN, action: 'save', key: k, value: String(v) });
    return fetch(SITE_URL + '/api/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function (r) { return r.json(); });
}

function saveLayout() {
    var items = Object.keys(KEYS).map(function (k) {
        return { k: k, v: document.getElementById(KEYS[k]).value };
    });
    // 顺序保存，任一失败即提示（settings 接口是单键写入）
    (function next(i) {
        if (i >= items.length) {
            showToast('已保存，刷新页面生效', 'success');
            return;
        }
        saveOne(items[i].k, items[i].v).then(function (d) {
            if (!d.success) { showToast(d.message || '保存失败', 'error'); return; }
            next(i + 1);
        }).catch(function () { showToast('网络错误', 'error'); });
    })(0);
}
</script>

<?php adminFooter(); ?>
