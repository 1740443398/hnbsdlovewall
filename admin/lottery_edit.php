<?php
/**
 * 后台：编辑单个抽奖配置
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/lottery.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();
if (!checkPermission($adminUser, 'manage_settings')) { http_response_code(403); die('403 Forbidden - 权限不足'); }

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$lot = $id > 0 ? lwLotteryGet($id) : null;
if (!$lot) { http_response_code(404); die('抽奖不存在'); }

$msg = ''; $msgType = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $msg = '令牌失效，请刷新后重试'; $msgType = 'error';
    } else {
        $__coverPath = '';
        if (!empty($_FILES['cover_image_file']['name'])) {
            $__f = $_FILES['cover_image_file'];
            $__ext = strtolower(pathinfo((string) $__f['name'], PATHINFO_EXTENSION));
            if (!in_array($__ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) || (int) ($__f['size'] ?? 0) > 3 * 1024 * 1024) {
                $msg = '封面图需为 jpg/png/gif/webp 且不超过 3MB'; $msgType = 'error';
            } else {
                $__uploadDir = (defined('UPLOAD_IMAGES_DIR') ? UPLOAD_IMAGES_DIR : (__DIR__ . '/../uploads/images'));
                $__uploadDir = rtrim($__uploadDir, '/') . '/lottery';
                if (!is_dir($__uploadDir)) { @mkdir($__uploadDir, 0755, true); }
                $__name = 'lot-' . date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $__ext;
                if (is_uploaded_file((string) $__f['tmp_name']) && @move_uploaded_file((string) $__f['tmp_name'], $__uploadDir . '/' . $__name)) {
                    $__coverPath = '/uploads/images/lottery/' . $__name;
                }
            }
        }
        if ($msg === '') {
            $r = lwLotteryUpdate($id, [
                'title'        => $_POST['title'] ?? '',
                'prize'        => $_POST['prize'] ?? '',
                'prize_count'  => $_POST['prize_count'] ?? 1,
                'description'  => $_POST['description'] ?? '',
                'draw_at'      => $_POST['draw_at'] ?? '',
                'rule_follow'  => $_POST['rule_follow'] ?? 0,
                'rule_like'    => $_POST['rule_like'] ?? 0,
                'rule_comment' => $_POST['rule_comment'] ?? 0,
                'rule_repost'  => $_POST['rule_repost'] ?? 0,
                'rule_custom'  => $_POST['rule_custom'] ?? '',
                'cover_image'  => $__coverPath,
            ]);
            if (empty($r['ok'])) { $msg = (string) ($r['error'] ?? '保存失败'); $msgType = 'error'; }
            else { $msg = '已保存'; }
            $lot = lwLotteryGet($id);
        }
    }
}

$rules = (array) ($lot['rules'] ?? []);
$custom = [];
foreach ($rules as $r) {
    if (mb_strpos((string) $r, '关注') === false && mb_strpos((string) $r, '点赞') === false
        && mb_strpos((string) $r, '评论') === false && mb_strpos((string) $r, '转发') === false) { $custom[] = (string) $r; }
}
$drawVal = date('Y-m-d\TH:i', strtotime((string) ($lot['draw_at'] ?? '')));
adminHeader('编辑抽奖 #' . $id, $adminUser, $csrfToken);
?>
<?php if ($msg): ?>
<div class="section" style="padding:12px 16px;margin-bottom:14px;border-left:4px solid <?= $msgType === 'error' ? 'var(--danger)' : 'var(--success)' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="section">
    <div class="section-header"><h3>编辑抽奖配置</h3></div>
    <div class="section-body">
        <form method="post" class="lot-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="lot-row">
                <div class="lot-field"><label>标题</label><input class="form-input" name="title" required value="<?= htmlspecialchars((string) ($lot['title'] ?? '')) ?>"></div>
                <div class="lot-field"><label>奖品</label><input class="form-input" name="prize" required value="<?= htmlspecialchars((string) ($lot['prize'] ?? '')) ?>"></div>
                <div class="lot-field"><label>中奖名额</label><input class="form-input" type="number" name="prize_count" min="1" max="100" value="<?= (int) ($lot['prize_count'] ?? 1) ?>"></div>
                <div class="lot-field"><label>开奖时间</label><input class="form-input" type="datetime-local" name="draw_at" value="<?= htmlspecialchars($drawVal) ?>"></div>
            </div>
            <div class="lot-field"><label>封面图（可选，不选则保留原封面）</label>
                <?php if (!empty($lot['cover_image'])): ?><div style="margin-bottom:6px"><img src="<?= htmlspecialchars((string) $lot['cover_image']) ?>" alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover"></div><?php endif; ?>
                <input class="form-input" type="file" name="cover_image_file" accept="image/*">
            </div>
            <div class="lot-field"><label>说明</label><textarea class="form-input" name="description" rows="3"><?= htmlspecialchars((string) ($lot['description'] ?? '')) ?></textarea></div>
            <div class="lot-field">
                <label>参与方式</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_follow" value="1" <?= in_array('关注', $rules, true) ? 'checked' : '' ?>> 关注发起人</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_like" value="1" <?= in_array('点赞', $rules, true) ? 'checked' : '' ?>> 点赞</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_comment" value="1" <?= in_array('评论', $rules, true) ? 'checked' : '' ?>> 评论</label>
                <label style="font-weight:400;margin-right:14px"><input type="checkbox" name="rule_repost" value="1" <?= in_array('转发', $rules, true) ? 'checked' : '' ?>> 转发</label>
                <div style="margin-top:6px"><input class="form-input" name="rule_custom" value="<?= htmlspecialchars(implode('、', $custom)) ?>" placeholder="自定义提示条款"></div>
            </div>
            <div style="display:flex;gap:10px"><button class="btn btn-primary" type="submit">保存修改</button><a class="btn" href="/admin/lottery.php">返回列表</a></div>
        </form>
    </div>
</div>
<?php adminFooter(); ?>
