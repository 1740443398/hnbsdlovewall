<?php
/**
 * 后台：更新日志管理
 *
 * ==========================================================================
 * 为什么要在后台改
 * ==========================================================================
 * 每次发版都要改 includes/changelog_data.php 再上传 ——
 * 手机上根本做不了，而且改 PHP 数组容易漏引号导致整站白屏。
 *
 * 这个页面把更新日志变成「可编辑的文本」，保存后立刻生效，
 * 不用碰代码。
 *
 * 数据存在 settings 表的 changelog_json 键里（JSON 字符串）。
 * 读取方 includes/changelog_data.php 会优先用后台这份。
 * ==========================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (($admin['role'] ?? '') !== 'super_admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

$csrfToken = generateCSRFToken();
$msg = '';
$msgType = 'ok';

/** 读当前日志（后台值优先，否则默认值） */
function lwChangelogLoad(): array
{
    $raw = getSetting('changelog_json', '');
    if (is_string($raw) && $raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d) && !empty($d)) return $d;
    }
    // 回退到默认值
    $f = __DIR__ . '/../includes/changelog_data.php';
    if (is_file($f)) {
        $d = include $f;
        if (is_array($d)) return $d;
    }
    return [];
}

// ---- 保存 ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'save') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 校验失败，请刷新重试';
        $msgType = 'err';
    } else {
        $versions = $_POST['ver'] ?? [];
        $out = [];

        if (is_array($versions)) {
            foreach ($versions as $v) {
                if (!is_array($v)) continue;
                $ver = trim((string)($v['version'] ?? ''));
                if ($ver === '') continue;

                $items = [];
                $types = $v['item_type'] ?? [];
                $texts = $v['item_text'] ?? [];
                $links = $v['item_link'] ?? [];

                if (is_array($texts)) {
                    foreach ($texts as $i => $t) {
                        $t = trim((string)$t);
                        if ($t === '') continue;
                        $items[] = [
                            'type' => (string)($types[$i] ?? '新增'),
                            'text' => $t,
                            'link' => trim((string)($links[$i] ?? '')),
                        ];
                    }
                }

                if (!$items) continue;

                $out[] = [
                    'version' => $ver,
                    'date'    => trim((string)($v['date'] ?? date('Y-m-d'))),
                    'channel' => (string)($v['channel'] ?? 'both'),
                    'items'   => $items,
                ];
            }
        }

        if (!$out) {
            $msg = '至少要有一个版本且每个版本至少一条内容';
            $msgType = 'err';
        } else {
            $json = json_encode($out, JSON_UNESCAPED_UNICODE);
            if (updateSetting('changelog_json', $json)) {
                $total = 0;
                foreach ($out as $o) $total += count($o['items']);
                $msg = '已保存 ' . count($out) . ' 个版本，共 ' . $total . ' 条更新';
            } else {
                $msg = '保存失败，请检查 data 目录权限';
                $msgType = 'err';
            }
        }
    }
}

// ---- 恢复默认 ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'restore') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        updateSetting('changelog_json', '');
        $msg = '已恢复为内置默认日志';
    }
}

$versions = lwChangelogLoad();
$types = ['新增', '优化', '修复', '安全'];

adminHeader('更新日志管理', $admin, $csrfToken);
?>

<style>
.cl-wrap { max-width: 920px; }
.cl-msg { padding: 11px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
.cl-msg.ok  { background: #EAF7F1; color: #178A62; border: 1px solid #C5E9D8; }
.cl-msg.err { background: #FDECEC; color: #C0392B; border: 1px solid #F5C6C6; }

.cl-tip {
    padding: 12px 15px; border-radius: 10px; font-size: 12.5px; line-height: 1.8;
    background: #F0F6FF; border: 1px solid #D6E6FA; color: #2F5B9A; margin-bottom: 18px;
}
.cl-tip b { color: #1F3F6E; }

.cl-ver {
    border: 1px solid var(--border-color, #E4E9F0); border-radius: 12px;
    padding: 16px 18px; margin-bottom: 14px; background: #fff;
}
.cl-ver-head {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;
}
.cl-ver-head input {
    padding: 7px 11px; font-size: 13.5px; font-family: inherit;
    border: 1px solid var(--border-color, #E4E9F0); border-radius: 8px; outline: none;
}
.cl-ver-head input:focus { border-color: var(--primary, #2F5B9A); }

.cl-item {
    display: flex; gap: 7px; margin-bottom: 8px; align-items: flex-start;
}
.cl-item select {
    padding: 7px 9px; font-size: 12.5px; font-family: inherit;
    border: 1px solid var(--border-color, #E4E9F0); border-radius: 8px;
    background: #fff; outline: none; flex: 0 0 76px;
}
.cl-item input[type="text"] {
    flex: 1; padding: 7px 11px; font-size: 13px; font-family: inherit;
    border: 1px solid var(--border-color, #E4E9F0); border-radius: 8px; outline: none;
}
.cl-item input[type="text"]:focus { border-color: var(--primary, #2F5B9A); }
.cl-item .del {
    flex: 0 0 30px; height: 32px; border: 1px solid #F5C6C6; border-radius: 8px;
    background: #FEF6F6; color: #C0392B; cursor: pointer; font-size: 15px; line-height: 1;
}
.cl-item .del:hover { background: #C0392B; color: #fff; }

.cl-add {
    padding: 6px 13px; font-size: 12.5px; border-radius: 7px;
    border: 1px dashed var(--border-color, #D5DCE6); background: transparent;
    color: var(--text-secondary, #5A6472); cursor: pointer; font-family: inherit;
}
.cl-add:hover { border-color: var(--primary, #2F5B9A); color: var(--primary, #2F5B9A); }

.cl-actions { display: flex; gap: 10px; align-items: center; margin-top: 20px; flex-wrap: wrap; }
.cl-new-ver {
    padding: 9px 20px; font-size: 13.5px; font-weight: 600; font-family: inherit;
    color: #2F5B9A; background: #F0F6FF;
    border: 1px solid rgba(47,91,154,.3); border-radius: 9px; cursor: pointer;
    transition: background .15s, border-color .15s;
}
.cl-new-ver:hover { background: #E3EEFC; border-color: rgba(47,91,154,.5); }
</style>

<div class="cl-wrap">
    <?php if ($msg !== ''): ?>
    <div class="cl-msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="cl-tip">
        <b>写给用户看，不是写给开发者看。</b><br>
        · 说「你能感受到什么」，不说「代码里改了什么」<br>
        · 一条不超过 40 字，手机上才放得下<br>
        · 不写「全面升级」「大幅优化」这类没有信息量的词<br>
        · 有数字就说数字：<b>打开快约 1 秒</b> 比 <b>性能显著提升</b> 好
    </div>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="act" value="save">

        <?php foreach ($versions as $vi => $v): ?>
        <div class="cl-ver">
            <div class="cl-ver-head">
                <input type="text" name="ver[<?= $vi ?>][version]"
                       value="<?= htmlspecialchars((string)($v['version'] ?? '')) ?>"
                       placeholder="v2.4.3" style="width:110px;font-weight:600;">
                <input type="text" name="ver[<?= $vi ?>][date]"
                       value="<?= htmlspecialchars((string)($v['date'] ?? '')) ?>"
                       placeholder="2026-10-31" style="width:130px;">
                <select name="ver[<?= $vi ?>][channel]" style="padding:7px 9px;border-radius:8px;border:1px solid #E4E9F0;">
                    <?php foreach (['both' => '网页 + App', 'web' => '仅网页', 'app' => '仅 App'] as $cv => $cl): ?>
                    <option value="<?= $cv ?>" <?= ($v['channel'] ?? 'both') === $cv ? 'selected' : '' ?>>
                        <?= $cl ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cl-items" data-ver="<?= $vi ?>">
                <?php foreach (($v['items'] ?? []) as $ii => $it): ?>
                <div class="cl-item">
                    <select name="ver[<?= $vi ?>][item_type][]">
                        <?php foreach ($types as $t): ?>
                        <option value="<?= $t ?>" <?= ($it['type'] ?? '') === $t ? 'selected' : '' ?>>
                            <?= $t ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="ver[<?= $vi ?>][item_text][]"
                           value="<?= htmlspecialchars((string)($it['text'] ?? '')) ?>"
                           placeholder="一句话说清改了什么">
                    <input type="hidden" name="ver[<?= $vi ?>][item_link][]"
                           value="<?= htmlspecialchars((string)($it['link'] ?? '')) ?>">
                    <button type="button" class="del" onclick="this.parentNode.remove()">×</button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="cl-add" onclick="addItem(<?= $vi ?>)">+ 添加一条</button>
        </div>
        <?php endforeach; ?>

        <div class="cl-actions">
            <button type="submit" class="btn btn-primary">保存全部改动</button>
            <button type="button" class="cl-new-ver" onclick="addVersion()">
                + 发布新版本
            </button>
            <span style="font-size:12px;color:var(--text-muted,#98A2B3)">
                保存后立即生效，刷新 /changelog.php 就能看到
            </span>
        </div>
    </form>

    <form method="post" style="margin-top:16px;"
          onsubmit="return confirm('恢复为内置默认日志？后台的改动会丢失。');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="act" value="restore">
        <button type="submit" class="btn btn-sm">恢复默认日志</button>
    </form>
</div>

<script>
var CL_TYPES = <?= json_encode($types, JSON_UNESCAPED_UNICODE) ?>;

/* 发布新版本：在最前面插入一个空版本块。
 *
 * 【为什么要这个按钮】
 * 原来只能改现有版本 —— 想加 v2.4.4 得手动在文件里插数组，
 * 手机上根本做不到。这个按钮直接在页面顶部插一个新块，
 * 填完保存就上线。
 */
function addVersion() {
    var forms = document.querySelectorAll('form');
    var mainForm = null;
    for (var i = 0; i < forms.length; i++) {
        if (forms[i].querySelector('input[name="act"][value="save"]')) {
            mainForm = forms[i];
            break;
        }
    }
    if (!mainForm) return;

    // 现有版本数 → 新索引
    var existing = mainForm.querySelectorAll('.cl-ver');
    var newIdx = existing.length;

    // 自动推算下一个版本号
    var nextVer = 'v2.4.4';
    if (existing.length) {
        var firstVer = existing[0].querySelector('input[name$="[version]"]');
        if (firstVer) {
            var m = (firstVer.value || '').match(/v?(\d+)\.(\d+)\.(\d+)/);
            if (m) {
                nextVer = 'v' + m[1] + '.' + m[2] + '.' + (parseInt(m[3], 10) + 1);
            }
        }
    }

    var today = new Date();
    var dateStr = today.getFullYear() + '-'
        + String(today.getMonth() + 1).padStart(2, '0') + '-'
        + String(today.getDate()).padStart(2, '0');

    var div = document.createElement('div');
    div.className = 'cl-ver';
    div.style.borderColor = '#2F5B9A';
    div.style.background = '#F7FAFF';
    div.innerHTML =
        '<div class="cl-ver-head">'
        + '<input type="text" name="ver[' + newIdx + '][version]" value="' + nextVer
        + '" placeholder="v2.4.4" style="width:110px;font-weight:600;">'
        + '<input type="text" name="ver[' + newIdx + '][date]" value="' + dateStr
        + '" style="width:130px;">'
        + '<select name="ver[' + newIdx + '][channel]" style="padding:7px 9px;border-radius:8px;border:1px solid #E4E9F0;">'
        + '<option value="both">网页 + App</option>'
        + '<option value="web">仅网页</option>'
        + '<option value="app">仅 App</option>'
        + '</select>'
        + '<span style="font-size:12px;color:#2F5B9A;font-weight:600;">新版本</span>'
        + '</div>'
        + '<div class="cl-items" data-ver="' + newIdx + '"></div>'
        + '<button type="button" class="cl-add" onclick="addItem(' + newIdx + ')">+ 添加一条</button>';

    // 插到第一个版本之前
    var firstVer = mainForm.querySelector('.cl-ver');
    if (firstVer) {
        mainForm.insertBefore(div, firstVer);
    } else {
        mainForm.insertBefore(div, mainForm.querySelector('.cl-actions'));
    }

    // 默认给一条空行
    addItem(newIdx);

    div.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

function addItem(vi) {
    var wrap = document.querySelector('.cl-items[data-ver="' + vi + '"]');
    if (!wrap) return;

    var div = document.createElement('div');
    div.className = 'cl-item';

    var sel = document.createElement('select');
    sel.name = 'ver[' + vi + '][item_type][]';
    CL_TYPES.forEach(function (t) {
        var o = document.createElement('option');
        o.value = t; o.textContent = t;
        sel.appendChild(o);
    });

    var inp = document.createElement('input');
    inp.type = 'text';
    inp.name = 'ver[' + vi + '][item_text][]';
    inp.placeholder = '一句话说清改了什么';

    var hid = document.createElement('input');
    hid.type = 'hidden';
    hid.name = 'ver[' + vi + '][item_link][]';

    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'del';
    del.textContent = '×';
    del.onclick = function () { div.remove(); };

    div.appendChild(sel);
    div.appendChild(inp);
    div.appendChild(hid);
    div.appendChild(del);
    wrap.appendChild(div);
    inp.focus();
}
</script>

<?php adminFooter(); ?>
