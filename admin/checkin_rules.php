<?php
/**
 * D30 签到规则配置
 * 控制每日签到的基础经验、连续签到额外奖励、连签封顶等。
 * 经验发放统一走 includes/level.php 的 lwGrowthAward（成长体系为旁路，异常不拖垮主业务）。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'manage_growth')) {
    http_response_code(403);
    die('403 Forbidden');
}

$defaults = [
    'enabled'        => 1,
    'daily_exp'      => 5,
    'streak_step'    => 3,   // 每连续 N 天额外 +1 档奖励
    'streak_bonus'   => 2,   // 每档额外经验
    'max_streak_bonus' => 20, // 连签奖励封顶
    'daily_cap'      => 200, // 每日经验上限（与 level_daily_cap 对齐）
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $err = 'CSRF 验证失败';
    } else {
        $rules = $defaults;
        $rules['enabled']   = isset($_POST['enabled']) ? 1 : 0;
        $rules['daily_exp'] = max(0, (int) ($_POST['daily_exp'] ?? 5));
        $rules['streak_step'] = max(1, (int) ($_POST['streak_step'] ?? 3));
        $rules['streak_bonus'] = max(0, (int) ($_POST['streak_bonus'] ?? 2));
        $rules['max_streak_bonus'] = max(0, (int) ($_POST['max_streak_bonus'] ?? 20));
        $rules['daily_cap'] = max(0, (int) ($_POST['daily_cap'] ?? 200));
        updateSetting('checkin_rules', json_encode($rules, JSON_UNESCAPED_UNICODE));
        // PRG（Post-Redirect-Get）：保存后 302 回本页。
        // 直接在 POST 响应里渲染页面的话，用户按 F5 会被浏览器拦下问「要重新提交表单吗」，
        // 体验上很像「表单没提交成功」。重定向后刷新就是一次干净的 GET。
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?saved=1');
        exit;
    }
}

if (isset($_GET['saved'])) {
    $ok = '签到规则已保存';
}

$rules = json_decode(getSetting('checkin_rules', ''), true);
if (!is_array($rules)) { $rules = $defaults; }
$rules = array_merge($defaults, $rules);

adminHeader('签到规则', $adminUser, $csrfToken);
?>
<style>
.ck-row { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.ck-row label { width: 180px; font-size: 14px; color: var(--text); }
.ck-row input[type=number] { width: 120px; }
.ck-hint { font-size: 12px; color: var(--text-secondary); }
</style>

<?php if (!empty($ok)): ?><div class="toast toast-success" style="position:static;margin-bottom:12px;"><?= xss_clean($ok) ?></div><?php endif; ?>
<?php if (!empty($err)): ?><div class="toast toast-error" style="position:static;margin-bottom:12px;"><?= xss_clean($err) ?></div><?php endif; ?>

<div class="section">
    <div class="section-header"><h3>签到规则配置</h3></div>
    <div class="section-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="ck-row">
                <label>开启签到</label>
                <label style="width:auto;"><input type="checkbox" name="enabled" <?= $rules['enabled'] ? 'checked' : '' ?>> 启用每日签到功能</label>
            </div>
            <div class="ck-row">
                <label>每日基础经验</label>
                <input type="number" name="daily_exp" value="<?= (int) $rules['daily_exp'] ?>" min="0">
                <span class="ck-hint">每次签到固定获得</span>
            </div>
            <div class="ck-row">
                <label>连签档位步长</label>
                <input type="number" name="streak_step" value="<?= (int) $rules['streak_step'] ?>" min="1">
                <span class="ck-hint">每连续签到 N 天升一档</span>
            </div>
            <div class="ck-row">
                <label>每档额外经验</label>
                <input type="number" name="streak_bonus" value="<?= (int) $rules['streak_bonus'] ?>" min="0">
                <span class="ck-hint">每升一档额外 +N 经验</span>
            </div>
            <div class="ck-row">
                <label>连签奖励封顶</label>
                <input type="number" name="max_streak_bonus" value="<?= (int) $rules['max_streak_bonus'] ?>" min="0">
                <span class="ck-hint">连签额外经验上限</span>
            </div>
            <div class="ck-row">
                <label>每日经验上限</label>
                <input type="number" name="daily_cap" value="<?= (int) $rules['daily_cap'] ?>" min="0">
                <span class="ck-hint">与等级每日上限对齐，防刷</span>
            </div>
            <button class="btn btn-primary" type="submit">保存规则</button>
        </form>
    </div>
</div>

<?php adminFooter(); ?>
