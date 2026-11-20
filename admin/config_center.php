<?php
/**
 * 后台：全站配置
 *
 * 这个页面完全由 includes/cfg_registry.php 驱动 —— 加一个配置项
 * 只需要在注册表里写一行，表单会自动出现，不用改这个文件。
 *
 * 设计上的几个取舍：
 *   - 分组折叠，一次只看一组。50 多个配置摊开来会让人放弃修改
 *   - 每项下面一行灰字说明「改了会怎样」，而不是只给个输入框
 *   - 保存按钮放在每组底部，改完就地保存，不用滚回顶部
 *   - 数字项直接标出取值范围，避免填完才报错
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (!checkPermission($admin, 'manage_settings')) {
    header('Location: /admin/dashboard.php');
    exit;
}

$csrfToken = generateCSRFToken();
$msg = '';
$msgType = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败，请刷新页面重试';
        $msgType = 'err';
    } else {
        $group = (string)($_POST['group'] ?? '');
        $values = $_POST['cfg'] ?? [];
        if (!is_array($values)) $values = [];

        $saved = 0;
        $errs = [];
        $fs = getFS();
        foreach ($values as $key => $raw) {
            $r = lwCfgNormalize((string)$key, $raw);
            if (empty($r['ok'])) {
                $errs[] = (string)($r['message'] ?? $key);
                continue;
            }
            if (updateSetting((string)$key, (string)$r['value'])) {
                $saved++;
            } else {
                $errs[] = '保存失败：' . $key;
            }
        }

        // 开关没被提交（未勾选）时浏览器不会发字段，这里补 0
        foreach (lwCfgGroups() as $g) {
            foreach ($g['items'] as $key => $def) {
                if (($def['type'] ?? '') !== 'switch') continue;
                if (isset($values[$key])) continue;
                // 只处理当前分组里「本该有这个开关」的情况
                if ($group !== '' && !isset(lwCfgGroups()[$group]['items'][$key])) continue;
                updateSetting($key, '0');
                $saved++;
            }
        }

        if ($errs) {
            $msg = '部分未保存：' . implode('；', array_slice($errs, 0, 3));
            $msgType = 'err';
        } elseif ($saved > 0) {
            $msg = '已保存 ' . $saved . ' 项配置';
            $msgType = 'ok';
        } else {
            $msg = '没有需要保存的改动';
            $msgType = 'ok';
        }
    }
}

$groups = lwCfgCurrent();
adminHeader('全站配置', $admin, $csrfToken);
?>

<style>
.cfg-wrap { max-width: 900px; }
.cfg-msg {
    padding: 11px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px;
}
.cfg-msg.ok  { background: #EAF7F1; color: #178A62; border: 1px solid #C5E9D8; }
.cfg-msg.err { background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; }

.cfg-tabs {
    display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 18px;
    border-bottom: 1px solid var(--border-color, #E4E9F0); padding-bottom: 10px;
}
.cfg-tab {
    padding: 7px 14px; border-radius: 9px; font-size: 13px; font-weight: 600;
    background: transparent; border: 0; cursor: pointer; color: var(--text-secondary, #5A6472);
    font-family: inherit; transition: background .15s, color .15s;
}
.cfg-tab:hover { background: var(--bg-hover, #F0F3F8); }
.cfg-tab.active { background: var(--primary, #2F5B9A); color: #fff; }

.cfg-group { display: none; }
.cfg-group.active { display: block; }

.cfg-item {
    padding: 16px 0; border-bottom: 1px solid var(--border-light, #F0F3F8);
}
.cfg-item:last-child { border-bottom: 0; }
.cfg-label {
    font-size: 14px; font-weight: 600; color: var(--text-primary, #1F2733);
    display: flex; align-items: center; gap: 8px; margin-bottom: 5px;
}
.cfg-hint {
    font-size: 12.5px; color: var(--text-muted, #98A2B3); line-height: 1.6; margin-bottom: 8px;
}
.cfg-input, .cfg-textarea, .cfg-select {
    width: 100%; padding: 9px 12px; font-size: 13.5px; font-family: inherit;
    border: 1px solid var(--border-color, #E4E9F0); border-radius: 9px;
    background: var(--bg-input, #fff); color: var(--text-primary, #1F2733);
    outline: none; transition: border-color .15s;
}
.cfg-input:focus, .cfg-textarea:focus, .cfg-select:focus {
    border-color: var(--primary, #2F5B9A);
}
.cfg-textarea { min-height: 78px; resize: vertical; line-height: 1.65; }

.cfg-switch { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
.cfg-switch input { display: none; }
.cfg-switch-track {
    width: 44px; height: 25px; border-radius: 99px; background: #D5DCE6;
    position: relative; transition: background .18s;
}
.cfg-switch-track::after {
    content: ""; position: absolute; top: 2.5px; left: 2.5px;
    width: 20px; height: 20px; border-radius: 50%; background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.18); transition: transform .18s;
}
.cfg-switch input:checked + .cfg-switch-track { background: var(--primary, #2F5B9A); }
.cfg-switch input:checked + .cfg-switch-track::after { transform: translateX(19px); }
.cfg-switch-text { font-size: 13px; color: var(--text-secondary, #5A6472); }

.cfg-range { font-size: 11.5px; color: var(--text-muted, #98A2B3); margin-left: 6px; font-weight: 400; }

.cfg-actions {
    margin-top: 20px; display: flex; gap: 10px; align-items: center;
}
</style>

<div class="cfg-wrap">
    <div class="cfg-msg <?= $msgType ?>" <?= $msg === '' ? 'style="display:none"' : '' ?>>
        <?= htmlspecialchars($msg) ?>
    </div>

    <div class="cfg-tabs">
        <?php $first = true; foreach ($groups as $gkey => $g): ?>
            <button type="button" class="cfg-tab <?= $first ? 'active' : '' ?>"
                    data-tab="<?= htmlspecialchars($gkey) ?>">
                <?= htmlspecialchars($g['label']) ?>
            </button>
        <?php $first = false; endforeach; ?>
    </div>

    <?php $first = true; foreach ($groups as $gkey => $g): ?>
    <form method="post" class="cfg-group <?= $first ? 'active' : '' ?>"
          data-group="<?= htmlspecialchars($gkey) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="group" value="<?= htmlspecialchars($gkey) ?>">

        <div class="section">
            <div class="section-header"><h3><?= htmlspecialchars($g['label']) ?></h3></div>
            <div class="section-body">
                <?php foreach ($g['items'] as $key => $it):
                    $type = $it['type'] ?? 'text';
                    $val  = (string)($it['value'] ?? '');
                    $name = 'cfg[' . $key . ']';
                ?>
                <div class="cfg-item">
                    <div class="cfg-label">
                        <?= htmlspecialchars($it['label'] ?? $key) ?>
                        <?php if ($type === 'number' && (isset($it['min']) || isset($it['max']))): ?>
                            <span class="cfg-range">
                                <?= isset($it['min']) ? (int)$it['min'] : '−∞' ?>
                                ~
                                <?= isset($it['max']) ? (int)$it['max'] : '+∞' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($it['hint'])): ?>
                        <div class="cfg-hint"><?= htmlspecialchars($it['hint']) ?></div>
                    <?php endif; ?>

                    <?php if ($type === 'switch'): ?>
                        <label class="cfg-switch">
                            <input type="checkbox" name="<?= htmlspecialchars($name) ?>" value="1"
                                   <?= $val === '1' ? 'checked' : '' ?>>
                            <span class="cfg-switch-track"></span>
                            <span class="cfg-switch-text"><?= $val === '1' ? '已开启' : '已关闭' ?></span>
                        </label>

                    <?php elseif ($type === 'textarea'): ?>
                        <textarea class="cfg-textarea" name="<?= htmlspecialchars($name) ?>"
                                  placeholder="<?= htmlspecialchars($it['hint'] ?? '') ?>"><?= htmlspecialchars($val) ?></textarea>

                    <?php elseif ($type === 'number'): ?>
                        <input type="number" class="cfg-input" name="<?= htmlspecialchars($name) ?>"
                               value="<?= htmlspecialchars($val) ?>"
                               <?= isset($it['min']) ? 'min="' . (int)$it['min'] . '"' : '' ?>
                               <?= isset($it['max']) ? 'max="' . (int)$it['max'] . '"' : '' ?>>

                    <?php elseif ($type === 'select'): ?>
                        <select class="cfg-select" name="<?= htmlspecialchars($name) ?>">
                            <?php foreach (($it['options'] ?? []) as $ov => $ol): ?>
                                <option value="<?= htmlspecialchars((string)$ov) ?>"
                                        <?= $val === (string)$ov ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$ol) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php else: ?>
                        <input type="text" class="cfg-input" name="<?= htmlspecialchars($name) ?>"
                               value="<?= htmlspecialchars($val) ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div class="cfg-actions">
                    <button type="submit" class="btn btn-primary">保存「<?= htmlspecialchars($g['label']) ?>」</button>
                    <span style="font-size:12px;color:var(--text-muted,#98A2B3)">改完点这里，立刻生效</span>
                </div>
            </div>
        </div>
    </form>
    <?php $first = false; endforeach; ?>
</div>

<script>
(function () {
    var tabs = document.querySelectorAll('.cfg-tab');
    var groups = document.querySelectorAll('.cfg-group');

    for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function () {
            var key = this.getAttribute('data-tab');
            for (var k = 0; k < tabs.length; k++) tabs[k].classList.remove('active');
            this.classList.add('active');
            for (var j = 0; j < groups.length; j++) {
                groups[j].classList.toggle('active', groups[j].getAttribute('data-group') === key);
            }
            try { history.replaceState(null, '', '#' + key); } catch (e) {}
        });
    }

    // 支持从 URL hash 直接定位
    var h = (location.hash || '').replace('#', '');
    if (h) {
        for (var i2 = 0; i2 < tabs.length; i2++) {
            if (tabs[i2].getAttribute('data-tab') === h) { tabs[i2].click(); break; }
        }
    }

    // 开关文字跟着状态变
    var sws = document.querySelectorAll('.cfg-switch input[type="checkbox"]');
    for (var s = 0; s < sws.length; s++) {
        sws[s].addEventListener('change', function () {
            var txt = this.parentNode.querySelector('.cfg-switch-text');
            if (txt) txt.textContent = this.checked ? '已开启' : '已关闭';
        });
    }
})();
</script>

<?php adminFooter(); ?>
