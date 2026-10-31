<?php
/**
 * 后台「装扮管理」
 *   - 上架 / 下架 / 编辑 / 删除挂件（头像框 / 气泡 / 勋章）
 *   - **上传头像框图片**：选图后立刻在「头像取景框」里预览，
 *     管理员能直接看到「头像会不会被框挡住」，不用先上架再回前台验证
 *   - 逐项配置积分价格与有效时长（需求：有效时长支持后台动态配置）
 *   - 槽位只能从白名单选，从源头杜绝「塞到错误位置」导致错位
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/widgets.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_settings') && $adminUser['role'] !== 'super_admin') {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

/** 头像框的 CSS 样式库（与 assets/css/widgets.css 的 lw-frame-* 一一对应） */
$frameStyles = [
    ''              => '（用下方上传的图片，不用样式）',
    'lw-frame-gold'   => '金色 · 基础款',
    'lw-frame-silver' => '银色 · 基础款',
    'lw-frame-bronze' => '古铜 · 基础款',
    'lw-frame-neon'   => '霓虹青 · 发光',
    'lw-frame-rose'   => '玫瑰粉',
    'lw-frame-glass'  => '液态玻璃',
    'lw-frame-plain'  => '素圈 · 极简',
    'lw-frame-ink'    => '墨线 · 手绘旋纹',
    'lw-frame-gothic' => '哥特紫 · 暗黑',
    'lw-frame-crown'  => '皇冠 · 金色',
    'lw-frame-halo'   => '天使光环',
    'lw-frame-heart'  => '爱心 · 粉色',
    'lw-frame-star'   => '星辰 · 蓝色',
    'lw-frame-aurora' => '极光渐变 · 流光',
    'lw-frame-flame'  => '烈焰 · 旋转',
    'lw-frame-ocean'  => '深海 · 双环',
    'lw-frame-royal'  => '皇家金 · 厚边',
    'lw-frame-cat'    => '猫耳 · 软萌',
    'lw-frame-sakura' => '樱花 · 花瓣点点',
    'lw-frame-galaxy' => '星河 · 漩涡',
    'lw-frame-cyber'  => '赛博 · 双环脉冲',
    'lw-frame-mint'   => '薄荷 · 点线环',
    'lw-frame-cream'  => '奶油 · 暖白',
    'lw-frame-candy'  => '糖果 · 彩虹',
    'lw-frame-tuxedo' => '燕尾服 · 领结',
    'lw-frame-rainbow'   => '彩虹霓环',
    'lw-frame-moonlight' => '月光银辉',
    'lw-frame-petals'    => '樱花飘落 · 动效',
    'lw-frame-cloud'     => '云朵棉花糖',
    'lw-frame-matcha'    => '抹茶奶盖',
    'lw-frame-vinyl'     => '极简黑胶',
    'lw-frame-glitter'   => '金箔碎钻',
    'lw-frame-lime'      => '青柠苏打',
    'lw-frame-gear'      => '机械齿轮 · 刻度',
];
$bubbleStyles = [
    ''                 => '（默认）',
    'lw-bubble-default' => '默认',
    'lw-bubble-blue'    => '天蓝',
    'lw-bubble-pink'    => '樱粉',
    'lw-bubble-mint'    => '薄荷',
    'lw-bubble-dark'    => '深色',
    'lw-bubble-glass'   => '液态玻璃',
    'lw-bubble-cream'   => '奶油 · 治愈',
    'lw-bubble-sakura'  => '樱花 · 治愈',
    'lw-bubble-sky'     => '晴空',
    'lw-bubble-mint2'   => '青草',
    'lw-bubble-lilac'   => '薰衣草',
    'lw-bubble-ink'     => '墨黑 · 玄研',
    'lw-bubble-paper'   => '羊皮纸',
    'lw-bubble-neon'    => '霓虹 · 夜色',
    'lw-bubble-sunset'  => '落日渐变',
    'lw-bubble-ocean'   => '海雾渐变',
    'lw-bubble-forest'  => '森林渐变',
    'lw-bubble-latte'   => '拿铁暖调',
    'lw-bubble-night'   => '午夜深海',
    'lw-bubble-lemon'   => '柠檬气泡',
    'lw-bubble-galaxy'  => '星河渐变',
    'lw-bubble-peach'   => '蜜桃汽水',
    'lw-bubble-aqua'    => '海洋薄荷',
    'lw-bubble-lavender-night' => '薰衣草夜',
    'lw-bubble-film'    => '复古胶片',
    'lw-bubble-highlight' => '荧光便签',
    'lw-bubble-blackgold' => '黑金质感',
    'lw-bubble-cloudwhite' => '纯白云朵',
    'lw-bubble-rosegold' => '玫瑰金雾',
    'lw-bubble-terminal' => '终端绿码',
];

adminHeader('装扮管理', $adminUser, $csrfToken);
?>

<style>
    .wg-flex { display: flex; gap: 18px; flex-wrap: wrap; align-items: flex-start; }
    .wg-panel { flex: 1 1 320px; min-width: 280px; }
    .wg-preview-box {
        flex: 0 0 300px; display: flex; flex-direction: column; align-items: center; gap: 10px;
        padding: 20px; border: 1px dashed var(--border-color, #DDE3EC); border-radius: 14px;
        background: var(--bg-secondary, var(--bg));
    }
    /* 预览用的「头像取景框」：结构与前台 lwAvatarComposite 完全一致，
       管理员看到的就是上线后的真实效果 —— 所见即所得，不靠想象。 */
    .wg-stage { position: relative; display: inline-flex; align-items: center; justify-content: center;
        width: 128px; height: 128px; border-radius: 50%; isolation: isolate; }
    .wg-stage img { position: absolute; inset: 6%; width: 88%; height: 88%; border-radius: 50%; object-fit: cover; z-index: 1; }
    .wg-stage .wg-frame { position: absolute; inset: 0; border-radius: 50%; z-index: 2; pointer-events: none; }
    .wg-stage .wg-ring { position: absolute; inset: 0; z-index: 3; color: var(--primary); }
    .wg-stage .wg-ring svg { width: 100%; height: 100%; display: block; }
    .wg-tip { font-size: 12px; color: var(--text-secondary); line-height: 1.7; margin: 0; }
    .wg-thumb { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; background: var(--bg-secondary);
        border: 1px solid var(--border-color, #DDE3EC); }
    .wg-file { display: none; }
    .wg-upload-btn { display: inline-flex; align-items: center; gap: 6px; }
</style>

<div class="section">
    <div class="section-header"><h3>已上架装扮</h3></div>
    <div class="section-body">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>预览</th>
                        <th>ID</th>
                        <th>名称</th>
                        <th>槽位</th>
                        <th>样式 / 图片</th>
                        <th>价格（积分）</th>
                        <th>有效天数</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="widgetBody">
                    <tr><td colspan="9" class="loading-spinner">加载中</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>上架新装扮</h3></div>
    <div class="section-body">
        <div class="wg-flex">
            <div class="wg-panel">
                <div class="form-group">
                    <label>名称</label>
                    <input type="text" id="wName" placeholder="如：无畏头像框" maxlength="40">
                </div>
                <div class="form-group">
                    <label>槽位（决定渲染位置）</label>
                    <select id="wSlot" onchange="onSlotChange()">
                        <option value="avatar_frame">头像框（环绕头像）</option>
                        <option value="bubble">气泡（消息配色）</option>
                        <option value="title">自定义头衔（出售槽位 · 可设永久）</option>
                        <?php /* 勋章不再作为商品出售：它属于成长体系的成就（includes/achievements.php）。
                                 历史 badge 商品仍留在数据里但不可购买、不展示，故下拉不再提供该选项。 */ ?>
                    </select>
                </div>
                <div class="form-group" id="groupCss">
                    <label>样式（CSS 预设）</label>
                    <select id="wCss"></select>
                </div>
                <div class="form-group" id="groupAsset">
                    <label>头像框图片（可选，上传后优先于样式）</label>
                    <input type="file" id="wFile" class="wg-file" accept="image/png,image/webp,image/jpeg,image/gif">
                    <div style="display:flex;gap:10px;align-items:center;">
                        <button type="button" class="btn btn-outline btn-sm wg-upload-btn" onclick="document.getElementById('wFile').click()">
                            选择图片…
                        </button>
                        <img id="wThumb" class="wg-thumb" alt="" style="display:none;">
                        <button type="button" class="btn btn-danger btn-sm" id="wClearImg" style="display:none;" onclick="clearImage()">移除</button>
                    </div>
                    <input type="hidden" id="wAsset" value="">
                    <p class="wg-tip">
                        建议尺寸 <b>512×512 透明 PNG</b>。系统会把图片铺满整个头像槽位，
                        <b>中间留给头像</b>，所以图里不要画人头，只画外圈。
                        上传后右侧预览会立刻显示「头像被框成什么样」。
                    </p>
                </div>
                <div class="filter-row">
                    <div class="form-group">
                        <label>价格（积分）</label>
                        <input type="number" id="wPrice" value="100" min="0" max="100000">
                    </div>
                    <div class="form-group">
                        <label>有效天数</label>
                        <input type="number" id="wDays" value="30" min="1" max="3650">
                    </div>
                    <div class="form-group">
                        <label>排序（大在前）</label>
                        <input type="number" id="wSort" value="0" min="0" max="9999">
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="addWidget()">上架</button>
                <p class="wg-tip" style="margin-top:10px;">
                    管理员在商城对所有装扮免积分解锁，不受价格影响。
                    槽位固定为三类，挂件只会在对应槽位内渲染，<strong>不会撑破页面布局</strong>。
                </p>
            </div>

            <div class="wg-preview-box">
                <div style="font-size:13px;font-weight:700;color:var(--text);">头像取景预览</div>
                <?php /* 预览结构与前台完全一致：头像在下、框在中、等级环在上 */ ?>
                <div class="wg-stage" id="wgStage">
                    <img src="/assets/images/default-avatar.svg" alt="">
                    <span class="wg-frame" id="wgFrame" style="display:none;"></span>
                    <span class="wg-ring" id="wgRing">
                        <svg viewBox="0 0 128 128">
                            <circle cx="64" cy="64" r="60.5" fill="none" stroke="currentColor" stroke-opacity=".14" stroke-width="3"/>
                            <circle cx="64" cy="64" r="60.5" fill="none" stroke="currentColor" stroke-width="3"
                                    stroke-linecap="round" stroke-dasharray="266 114" transform="rotate(-90 64 64)"/>
                        </svg>
                    </span>
                </div>
                <p class="wg-tip" style="text-align:center;">
                    蓝环 = 等级进度环（前台会自动叠加）<br>
                    框必须画在圆环外沿，中间镂空才不会被头像盖住
                </p>
            </div>
        </div>
    </div>
</div>

<script>
var SLOT_LABEL = { avatar_frame: '头像框', bubble: '气泡', badge: '勋章', title: '自定义头衔' };
var FRAME_STYLES = <?= json_encode($frameStyles, JSON_UNESCAPED_UNICODE) ?>;
var BUBBLE_STYLES = <?= json_encode($bubbleStyles, JSON_UNESCAPED_UNICODE) ?>;
var CATALOG = [];

/** 转义：列表里的名称/路径都来自管理员输入，拼进 innerHTML 前必须先过一遍 */
function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function post(action, extra) {
    var body = new URLSearchParams({ csrf_token: CSRF_TOKEN, action: action });
    Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
    return fetch(SITE_URL + '/api/admin/widgets.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).then(function (r) { return r.json(); });
}

/* ---------- 样式下拉：按槽位换选项 ---------- */
function onSlotChange() {
    var slot = document.getElementById('wSlot').value;
    var sel = document.getElementById('wCss');
    var dict = slot === 'avatar_frame' ? FRAME_STYLES
             : (slot === 'bubble' ? BUBBLE_STYLES
             : { 'lw-title-custom': '（头衔槽位，不需要样式）' });
    sel.innerHTML = Object.keys(dict).map(function (k) {
        return '<option value="' + k + '">' + dict[k] + '</option>';
    }).join('');
    // 只有头像框支持上传图片
    document.getElementById('groupAsset').style.display = slot === 'avatar_frame' ? '' : 'none';
    document.getElementById('groupCss').style.display = (slot === 'title') ? 'none' : '';
    renderPreview();
}

/* ---------- 预览 ---------- */
function renderPreview() {
    var slot = document.getElementById('wSlot').value;
    var frame = document.getElementById('wgFrame');
    var ring = document.getElementById('wgRing');
    var asset = document.getElementById('wAsset').value;
    var css = document.getElementById('wCss').value;

    if (slot !== 'avatar_frame') {
        frame.style.display = 'none';
        ring.style.display = 'none';
        return;
    }
    ring.style.display = '';
    if (asset) {
        frame.style.display = '';
        frame.className = 'wg-frame lw-widget-frame lw-frame-img';
        frame.style.backgroundImage = 'url("' + asset + '")';
    } else if (css) {
        frame.style.display = '';
        frame.className = 'wg-frame lw-widget-frame ' + css;
        frame.style.backgroundImage = '';
    } else {
        frame.style.display = 'none';
        frame.style.backgroundImage = '';
    }
}

/* ---------- 上传 ---------- */
document.getElementById('wCss').addEventListener('change', renderPreview);

document.getElementById('wFile').addEventListener('change', function () {
    var file = this.files && this.files[0];
    if (!file) return;
    if (file.size > 4 * 1024 * 1024) { showToast('图片需小于 4MB', 'error'); return; }

    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action', 'upload_asset');
    fd.append('file', file);

    fetch(SITE_URL + '/api/admin/widgets.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    }).then(function (r) { return r.json(); })
      .then(function (d) {
          if (!d.success) { showToast(d.message || '上传失败', 'error'); return; }
          document.getElementById('wAsset').value = d.data.asset;
          var thumb = document.getElementById('wThumb');
          thumb.src = d.data.asset;
          thumb.style.display = '';
          document.getElementById('wClearImg').style.display = '';
          showToast('图片已上传', 'success');
          renderPreview();
      })
      .catch(function () { showToast('上传失败，请检查网络或权限', 'error'); });
});

function clearImage() {
    document.getElementById('wAsset').value = '';
    document.getElementById('wThumb').style.display = 'none';
    document.getElementById('wClearImg').style.display = 'none';
    document.getElementById('wFile').value = '';
    renderPreview();
}

/* ---------- 列表 ---------- */
function previewCell(w) {
    if (w.slot === 'avatar_frame') {
        var inner = w.asset
            ? '<span class="lw-widget-frame lw-frame-img" style="position:absolute;inset:0;border-radius:50%;background:url(\'' + esc(w.asset) + '\') center/100% 100% no-repeat;"></span>'
            : (w.css_class ? '<span class="lw-widget-frame ' + esc(w.css_class) + '" style="position:absolute;inset:0;border-radius:50%;"></span>' : '');
        return '<div style="position:relative;width:36px;height:36px;border-radius:50%;isolation:isolate;">' +
               '<img src="/assets/images/default-avatar.svg" style="position:absolute;inset:6%;width:88%;height:88%;border-radius:50%;z-index:1;">' +
               inner + '</div>';
    }
    if (w.slot === 'bubble') {
        return '<span class="lw-bubble-slot ' + esc(w.css_class || 'lw-bubble-default') + '" style="font-size:11px;padding:3px 8px;border-radius:10px;display:inline-block;">你好</span>';
    }
    if (w.slot === 'title') {
        return '<span class="user-title" style="--ut-bg:#4A90D9;--ut-fg:#fff;">头衔</span>';
    }
    return '<span class="lw-badge-slot">' + esc(w.name) + '</span>';
}

function loadWidgets() {
    post('list_widgets', {}).then(function (d) {
        var tbody = document.getElementById('widgetBody');
        if (!d.success) { tbody.innerHTML = '<tr><td colspan="9">加载失败</td></tr>'; return; }
        var list = d.data.widgets || [];
        CATALOG = list;
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text-secondary);">还没有上架任何装扮</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(function (w) {
            var styleTxt = w.asset
                ? '<img src="' + esc(w.asset) + '" class="wg-thumb" alt="" style="width:28px;height:28px;">'
                : '<code>' + esc(w.css_class || '-') + '</code>';
            return '<tr>' +
                '<td>' + previewCell(w) + '</td>' +
                '<td>' + w.id + '</td>' +
                '<td>' + esc(w.name) + '</td>' +
                '<td>' + esc(SLOT_LABEL[w.slot] || w.slot) + '</td>' +
                '<td>' + styleTxt + '</td>' +
                '<td>' + (w.price === 0 ? '<span style="color:#2E8B57;">免费</span>' : w.price) + '</td>' +
                '<td>' + w.days + '</td>' +
                '<td>' + (w.active ? '<span class="badge badge-success">上架</span>' : '<span class="badge">下架</span>') + '</td>' +
                '<td>' +
                  '<button class="btn btn-outline btn-sm" onclick="toggleWidget(' + w.id + ',' + (w.active ? 0 : 1) + ')">' + (w.active ? '下架' : '上架') + '</button> ' +
                  '<button class="btn btn-outline btn-sm" onclick="editWidget(' + w.id + ')">编辑</button> ' +
                  '<button class="btn btn-danger btn-sm" onclick="delWidget(' + w.id + ')">删除</button>' +
                '</td>' +
                '</tr>';
        }).join('');
    }).catch(function () {
        document.getElementById('widgetBody').innerHTML = '<tr><td colspan="9">网络错误</td></tr>';
    });
}

function addWidget() {
    var asset = document.getElementById('wAsset').value;
    var css = document.getElementById('wCss').value;
    post('save_widget', {
        name: document.getElementById('wName').value.trim(),
        slot: document.getElementById('wSlot').value,
        css_class: css,
        asset: asset,
        price: document.getElementById('wPrice').value || 0,
        days: document.getElementById('wDays').value || 30,
        sort: document.getElementById('wSort').value || 0,
        active: '1'
    }).then(function (d) {
        if (!d.success) { showToast(d.message || '上架失败', 'error'); return; }
        showToast('已上架', 'success');
        document.getElementById('wName').value = '';
        clearImage();
        loadWidgets();
    }).catch(function () { showToast('网络错误', 'error'); });
}

function toggleWidget(id, active) {
    post('toggle_widget', { id: id, active: active }).then(function (d) {
        if (!d.success) { showToast(d.message || '操作失败', 'error'); return; }
        loadWidgets();
    });
}

function editWidget(id) {
    var w = CATALOG.filter(function (x) { return x.id === id; })[0];
    if (!w) return;
    var name = prompt('新的名称', w.name);
    if (name === null) return;
    var price = prompt('价格（积分）', w.price);
    if (price === null) return;
    var days = prompt('有效天数', w.days);
    if (days === null) return;
    post('save_widget', { id: id, name: name, price: price, days: days }).then(function (d) {
        if (!d.success) { showToast(d.message || '保存失败', 'error'); return; }
        showToast('已保存', 'success');
        loadWidgets();
    });
}

function delWidget(id) {
    if (!confirm('确定删除这个装扮吗？已购买的用户记录会保留但不再显示。')) return;
    post('del_widget', { id: id }).then(function (d) {
        if (!d.success) { showToast(d.message || '删除失败', 'error'); return; }
        showToast('已删除', 'success');
        loadWidgets();
    });
}

onSlotChange();
loadWidgets();
</script>

<?php adminFooter(); ?>
