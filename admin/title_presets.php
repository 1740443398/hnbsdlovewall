<?php
/**
 * D28 头衔体系配置 —— 预设头衔列表
 * 管理员在此维护一份「预设头衔」库（文案 / 文字色 / 背景色 / 彩虹渐变），
 * 给用户设置头衔时可直接套用，避免每次手填颜色。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_user_title')) {
    http_response_code(403);
    die('403 Forbidden');
}

// 处理提交
$okCode = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $err = 'CSRF 验证失败';
    } else {
        $presets = json_decode(getSetting('title_presets', '[]'), true);
        if (!is_array($presets)) { $presets = []; }
        $op = $_POST['op'] ?? '';
        if ($op === 'add' || $op === 'edit') {
            $text = trim($_POST['text'] ?? '');
            $color = trim($_POST['color'] ?? '#ffffff');
            $bg = trim($_POST['bg_color'] ?? '#e74c3c');
            $rainbow = isset($_POST['rainbow']) ? 1 : 0;
            if ($text === '') {
                $err = '头衔文案不能为空';
            } else {
                $item = ['text' => $text, 'color' => $color, 'bg_color' => $bg, 'rainbow' => $rainbow];
                if ($op === 'add') {
                    $presets[] = $item;
                } else {
                    $idx = (int) ($_POST['idx'] ?? -1);
                    if (isset($presets[$idx])) { $presets[$idx] = $item; }
                }
                updateSetting('title_presets', json_encode($presets, JSON_UNESCAPED_UNICODE));
                $okCode = 'saved';
            }
        } elseif ($op === 'del') {
            $idx = (int) ($_POST['idx'] ?? -1);
            if (isset($presets[$idx])) { array_splice($presets, $idx, 1); updateSetting('title_presets', json_encode($presets, JSON_UNESCAPED_UNICODE)); $okCode = 'deleted'; }
        }
    }
    // PRG：保存/删除成功后 302 回本页。直接在 POST 响应里渲染的话，
    // 用户按 F5 会被浏览器问「要重新提交表单吗」，体感很像「没提交成功」。
    if ($okCode !== '' && empty($err)) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?done=' . $okCode);
        exit;
    }
}

// 提示文案由白名单映射，绝不直接回显 URL 参数
$doneMap = ['saved' => '已保存', 'deleted' => '已删除'];
if (isset($_GET['done']) && isset($doneMap[$_GET['done']])) {
    $ok = $doneMap[$_GET['done']];
}

$presets = json_decode(getSetting('title_presets', '[]'), true);
if (!is_array($presets)) { $presets = []; }

adminHeader('头衔预设', $adminUser, $csrfToken);
?>
<style>
.tp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
.tp-item { border: 1px solid var(--border); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.tp-preview { padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; text-align: center; }
.tp-actions { display: flex; gap: 6px; }
.tp-form { background: var(--bg); border-radius: 12px; padding: 16px; }
.tp-form .form-group { margin-bottom: 12px; }
.tp-color { display: flex; gap: 12px; flex-wrap: wrap; }
.tp-color label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-secondary); }
</style>

<?php if (!empty($ok)): ?><div class="toast toast-success" style="position:static;margin-bottom:12px;"><?= xss_clean($ok) ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="toast toast-error" style="position:static;margin-bottom:12px;"><?= xss_clean($err) ?></div><?php endif; ?>

<div class="section">
    <div class="section-header"><h3>预设头衔库（<?= count($presets) ?>）</h3></div>
    <div class="section-body">
        <?php if (empty($presets)): ?><div class="empty-state"><div class="empty-icon">🏷️</div>暂无预设头衔，请在下方添加</div>
        <?php else: ?>
        <div class="tp-grid">
            <?php foreach ($presets as $i => $p): ?>
            <div class="tp-item">
                <div class="tp-preview" style="color:<?= xss_clean($p['color'] ?? '#fff') ?>;background:<?= xss_clean($p['bg_color'] ?? '#e74c3c') ?>;<?= !empty($p['rainbow']) ? 'background:linear-gradient(90deg,#ff47e7,#d1eb0f);' : '' ?>"><?= xss_clean($p['text']) ?></div>
                <div class="tp-actions">
                    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="op" value="del"><input type="hidden" name="idx" value="<?= $i ?>"><button class="btn btn-sm btn-outline" type="submit">删除</button></form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="tp-form">
            <h4 style="margin-bottom:12px;color:var(--text);">添加预设头衔</h4>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="op" value="add">
                <div class="form-group">
                    <label>头衔文案</label>
                    <input type="text" name="text" maxlength="20" placeholder="如：高一四班班长" required>
                </div>
                <div class="tp-color">
                    <label>文字色 <input type="color" name="color" value="#ffffff"></label>
                    <label>背景色 <input type="color" name="bg_color" value="#e74c3c"></label>
                    <label><input type="checkbox" name="rainbow"> 彩虹渐变</label>
                </div>
                <button class="btn btn-primary btn-sm" type="submit" style="margin-top:12px;">保存预设</button>
            </form>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
