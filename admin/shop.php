<?php
/**
 * 后台「商城管理」
 * ---------------------------------------------------------------------------
 * 和「装扮管理」的分工：
 *   - 装扮管理：管**外观**。新建装扮、挑样式类、上传头像框图片、看取景预览。
 *   - 商城管理（本页）：管**运营**。存量商品的批量上下架、调价、改有效期、删除。
 *
 * 为什么要单独一页：商城里现在有 70+ 件商品。做一次活动（全场八折、把 30 天
 * 统一改成 90 天、节日前先把一批旧款下架）如果只能一件件点，站长根本没法运营。
 * 这一页的核心就是「勾选 → 批量执行」，同时给出类目统计，一眼看出商城健不健康。
 *
 * 权限与装扮管理一致（manage_settings 或超管），不额外发明权限位 ——
 * 能改商品外观的人，当然也能改商品价格。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/widgets.php';
// 列表缩略图要用 lwDefaultAvatar()，它定义在 widget_render.php 里。
// 少这一行会让整页在渲染到第 157 行时直接 500（未定义函数是致命错误）。
require_once __DIR__ . '/../includes/widget_render.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_settings') && $adminUser['role'] !== 'super_admin') {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

// 全量商品（含下架）——运营视角必须看得到「已经下架的」，
// 否则会出现「明明下架了却在列表里找不到、想重新上架都做不到」。
$all = lwWidgetCatalog(false);
// badge 已停售（勋章改为成长成就，见 includes/widgets.php 的 LW_WIDGET_SLOTS 注释），
// 这里保留标签只为让历史数据在后台列表里还能认出来，不再出现在商城。
$slotLabel = ['avatar_frame' => '头像框', 'bubble' => '聊天气泡', 'badge' => '勋章（已停售）', 'title' => '自定义头衔'];
$stat = ['avatar_frame' => 0, 'bubble' => 0, 'badge' => 0, 'title' => 0];
$offCount = 0;
$freeCount = 0;
foreach ($all as $w) {
    $s = (string)($w['slot'] ?? '');
    if (isset($stat[$s])) {
        $stat[$s]++;
    }
    if (empty($w['active'])) {
        $offCount++;
    }
    if ((int)($w['price'] ?? 0) === 0) {
        $freeCount++;
    }
}

adminHeader('商城管理', $adminUser, $csrfToken);
?>

<style>
    .sm-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 18px; }
    .sm-stat {
        padding: 14px 16px; border-radius: 12px; background: var(--bg-secondary, #F7F9FC);
        border: 1px solid var(--border-color, #E6EAF0);
    }
    .sm-stat b { display: block; font-size: 1.4rem; line-height: 1.2; color: var(--text-primary); }
    .sm-stat span { font-size: 12px; color: var(--text-secondary); }

    /* 批量操作条：吸顶，勾选后不用滚回顶部 */
    .sm-bulk {
        position: sticky; top: 0; z-index: 20; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
        padding: 10px 12px; margin-bottom: 12px; border-radius: 12px;
        background: var(--card-bg, #fff); border: 1px solid var(--border-color, #E6EAF0);
        box-shadow: 0 6px 18px -12px rgba(16,24,40,.35);
    }
    .sm-bulk .sm-sel { font-size: 13px; color: var(--text-secondary); margin-right: 4px; }
    .sm-bulk input[type="number"] { width: 92px; }

    .sm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .sm-table th, .sm-table td { padding: 9px 10px; border-bottom: 1px solid var(--border-color, #EEF1F5); text-align: left; }
    .sm-table th { font-size: 12px; color: var(--text-secondary); font-weight: 600; white-space: nowrap; }
    .sm-table tbody tr:hover { background: var(--bg-secondary, #F7F9FC); }
    .sm-thumb { width: 34px; height: 34px; border-radius: 50%; display: inline-block; vertical-align: middle;
        background: #EEF2F7; border: 1px solid var(--border-color, #E1E7EF); position: relative; overflow: hidden; }
    .sm-thumb img { position: absolute; inset: 6%; width: 88%; height: 88%; border-radius: 50%; object-fit: cover; }
    .sm-off { color: #B03A35; font-size: 12px; }
    .sm-on  { color: #2E8B57; font-size: 12px; }
    .sm-tag { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 11px;
        background: var(--bg-secondary, #F1F4F8); color: var(--text-secondary); }
</style>

<div class="section">
    <div class="section-header">
        <h3>商城概览</h3>
        <div class="section-actions">
            <a class="btn btn-outline btn-sm" href="/admin/widgets.php">去「装扮管理」新建装扮</a>
        </div>
    </div>
    <div class="section-body">
        <div class="sm-stats">
            <div class="sm-stat"><b><?= count($all) ?></b><span>商品总数</span></div>
            <div class="sm-stat"><b><?= $stat['avatar_frame'] ?></b><span>头像框</span></div>
            <div class="sm-stat"><b><?= $stat['bubble'] ?></b><span>聊天气泡</span></div>
            <div class="sm-stat"><b><?= $stat['title'] ?></b><span>自定义头衔</span></div>
            <div class="sm-stat"><b><?= $stat['badge'] ?></b><span>勋章</span></div>
            <div class="sm-stat"><b><?= $offCount ?></b><span>已下架</span></div>
            <div class="sm-stat"><b><?= $freeCount ?></b><span>免费商品</span></div>
        </div>
        <p style="font-size:12px;color:var(--text-secondary);line-height:1.8;margin:0;">
            提示：头像框与气泡的**样式**来自 <code>assets/css/widgets.css</code>，
            新建时请在「装扮管理」里选一个已定义的样式类；留空且不上传图片的商品，
            前台会看不见框 —— 这一页的「样式缺失」标签会帮你标出来。
        </p>
    </div>
</div>

<div class="section">
    <div class="section-header"><h3>商品运营</h3></div>
    <div class="section-body">

        <div class="sm-bulk">
            <span class="sm-sel">已选 <strong id="smCount">0</strong> 件</span>
            <button class="btn btn-sm btn-outline" id="smSelAll">全选本页</button>
            <button class="btn btn-sm btn-outline" id="smSelNone">清空</button>
            <span style="width:1px;height:22px;background:var(--border-color,#E6EAF0);"></span>
            <button class="btn btn-sm" data-op="on">批量上架</button>
            <button class="btn btn-sm btn-outline" data-op="off">批量下架</button>
            <input type="number" class="form-control" id="smPrice" placeholder="积分" min="0" max="100000">
            <button class="btn btn-sm btn-outline" data-op="price">批量改价</button>
            <input type="number" class="form-control" id="smDays" placeholder="天数" min="1" max="3650">
            <button class="btn btn-sm btn-outline" data-op="days">批量改有效期</button>
            <button class="btn btn-sm btn-danger" data-op="del">删除</button>
        </div>

        <div class="table-wrapper">
            <table class="sm-table">
                <thead>
                <tr>
                    <th style="width:34px;"><input type="checkbox" id="smAll"></th>
                    <th style="width:44px;">预览</th>
                    <th>名称</th>
                    <th>槽位</th>
                    <th>价格</th>
                    <th>有效期</th>
                    <th>状态</th>
                    <th>排序</th>
                </tr>
                </thead>
                <tbody id="smBody">
                <?php foreach ($all as $w):
                    $slot = (string)($w['slot'] ?? '');
                    $cssCls = trim((string)($w['css_class'] ?? ''));
                    $asset = trim((string)($w['asset'] ?? ''));
                    $active = !empty($w['active']);
                ?>
                    <tr data-id="<?= (int)$w['id'] ?>"
                        data-slot="<?= htmlspecialchars($slot) ?>"
                        data-css="<?= htmlspecialchars($cssCls) ?>">
                        <td><input type="checkbox" class="sm-pick"></td>
                        <td>
                            <span class="sm-thumb <?= $asset === '' ? htmlspecialchars($cssCls) : '' ?>"
                                  <?= $asset !== '' ? 'style="background-image:url(\'' . htmlspecialchars($asset) . '\');background-size:cover;"' : '' ?>>
                                <?php if ($asset === ''): ?>
                                <img src="<?= htmlspecialchars(lwDefaultAvatar()) ?>" alt="">
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <?= htmlspecialchars((string)$w['name']) ?>
                            <?php if ($cssCls === '' && $asset === '' && $slot !== 'title'): ?>
                                <span class="sm-tag" style="background:#FDECEA;color:#B03A35;margin-left:6px;">样式缺失</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($slotLabel[$slot] ?? $slot) ?></td>
                        <td><?= (int)($w['price'] ?? 0) === 0 ? '<span class="sm-on">免费</span>' : (int)$w['price'] . ' 积分' ?></td>
                        <td><?= (int)($w['days'] ?? 0) ?> 天</td>
                        <td><?= $active ? '<span class="sm-on">已上架</span>' : '<span class="sm-off">已下架</span>' ?></td>
                        <td><?= (int)($w['sort'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$all): ?>
            <p style="text-align:center;color:var(--text-secondary);padding:24px 0;">商城还没有商品，先去「装扮管理」上架第一件吧。</p>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var CSRF = <?= json_encode($csrfToken) ?>;
    var body = document.getElementById('smBody');
    var countEl = document.getElementById('smCount');
    var allBox = document.getElementById('smAll');

    function picks() {
        return Array.prototype.slice.call(document.querySelectorAll('.sm-pick:checked'));
    }
    function refresh() {
        var n = picks().length;
        countEl.textContent = n;
        var total = document.querySelectorAll('.sm-pick').length;
        if (allBox) {
            allBox.checked = total > 0 && n === total;
            allBox.indeterminate = n > 0 && n < total;
        }
    }
    if (body) {
        body.addEventListener('change', function (e) {
            if (e.target.classList.contains('sm-pick')) refresh();
        });
    }
    if (allBox) {
        allBox.addEventListener('change', function () {
            document.querySelectorAll('.sm-pick').forEach(function (c) { c.checked = allBox.checked; });
            refresh();
        });
    }
    document.getElementById('smSelAll').addEventListener('click', function () {
        document.querySelectorAll('.sm-pick').forEach(function (c) { c.checked = true; });
        refresh();
    });
    document.getElementById('smSelNone').addEventListener('click', function () {
        document.querySelectorAll('.sm-pick').forEach(function (c) { c.checked = false; });
        refresh();
    });

    function toast(msg, type) {
        if (window.showToast) showToast(msg, type || 'success');
        else alert(msg);
    }

    document.querySelectorAll('.sm-bulk [data-op]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var op = btn.getAttribute('data-op');
            var ids = picks().map(function (c) { return c.closest('tr').getAttribute('data-id'); });
            if (!ids.length) return toast('请先勾选商品', 'error');

            var fd = new FormData();
            fd.append('action', 'bulk_widgets');
            fd.append('csrf_token', CSRF);
            fd.append('op', op);
            fd.append('ids', ids.join(','));

            if (op === 'price') {
                var p = document.getElementById('smPrice').value.trim();
                if (p === '') return toast('请先填写要改成的积分', 'error');
                fd.append('price', p);
            }
            if (op === 'days') {
                var d = document.getElementById('smDays').value.trim();
                if (d === '') return toast('请先填写要改成多少天', 'error');
                fd.append('days', d);
            }
            if (op === 'del' && !confirm('确定删除选中的 ' + ids.length + ' 件商品吗？\n已购买该商品的用户记录会保留，但其装扮将无法再续期。')) {
                return;
            }

            btn.disabled = true;
            fetch('/api/admin/widgets.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).then(function (r) { return r.json(); }).then(function (res) {
                btn.disabled = false;
                if (res && res.success) {
                    toast(res.message || '操作成功');
                    // 直接重载：这一页的表就是数据本身，局部更新反而容易和统计数不一致
                    setTimeout(function () { location.reload(); }, 500);
                } else {
                    toast((res && res.message) || '操作失败', 'error');
                }
            }).catch(function () {
                btn.disabled = false;
                toast('网络异常，请稍后重试', 'error');
            });
        });
    });

    refresh();
})();
</script>

<?php adminFooter(); ?>
