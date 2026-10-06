<?php
require_once __DIR__ . '/../config/config.php';

$fs = getFS();

$maintenanceMode = getSetting('maintenance_mode', '0') == '1';
$maintenanceMsg = getSetting('maintenance_message', '网站正在维护中，请稍后再来。');
if ($maintenanceMode) {
    $user = getCurrentUser();
    $isAdmin = $user && in_array($user['role'], ['admin', 'super_admin']);
    if (!$isAdmin) {
        require_once __DIR__ . '/maintenance.php';
        exit();
    }
}

// 已登录用户正常访问；游客模式可只读使用；其余跳登录页
$user = requireLoginOrGuest();
if ($user) {
    $user = checkBanned($user);
}
$isGuest = ($user === null);
$isSponsor = false;
if ($user && isset($user['qq'])) {
    $sponsors = $fs->read('sponsors');
    $sponsorQQs = array_column($sponsors, 'qq');
    $isSponsor = in_array($user['qq'], $sponsorQQs);
}
$bannedMsg = '';
if ($user && $user['is_banned']) {
    $bannedMsg = '账号已被封禁：' . ($user['ban_reason'] ?? '');
    if ($user['ban_until']) {
        $bannedMsg .= '（解封时间：' . $user['ban_until'] . '）';
    }
}

$announcement = getSetting('announcement', '');
$siteName = getSetting('site_name', '淮南北师大实验中学高中部校园交流墙');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG_CODE) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= t('tools.name_rollcall') ?> - <?= htmlspecialchars($siteName) ?></title>
    <meta name="description" content="<?= htmlspecialchars(t('rollcall.page_desc')) ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/enhancements.css') ?>?v=<?= asset_ver('/assets/css/enhancements.css') ?>">
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
        const USER_DATA = <?= $user ? json_encode(['id' => $user['id'], 'qq' => $user['qq'], 'uuid' => $user['uuid'] ?? '', 'nickname' => $user['nickname'], 'avatar' => $user['avatar'], 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) : 'null' ?>;
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        const IS_SPONSOR = <?= $isSponsor ? 'true' : 'false' ?>;
    </script>
    <style>
        /* 随机点名（独立页）——全部尺寸用 rem，跟随主题里的字号设置等比缩放 */
        .rcx-main {
            padding: var(--space-md) 0 var(--space-xl);
        }
        .rcx-container {
            max-width: 46rem;
            margin: 0 auto;
            padding: 0 var(--space-md);
        }
        .rcx-header {
            text-align: center;
            padding: var(--space-lg) var(--space-md) var(--space-md);
        }
        .rcx-header h1 {
            font-size: 1.9rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: var(--space-xs);
            letter-spacing: 0.04em;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
        }
        .rcx-header h1 svg { width: 2rem; height: 2rem; }
        .rcx-header .subtitle {
            font-size: 0.95rem;
            color: var(--text-secondary);
        }
        .rcx-panel {
            background: var(--card-bg);
            border: 1px solid var(--border-glass);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow);
            backdrop-filter: blur(var(--glass-blur)) saturate(160%);
            -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(160%);
            padding: var(--space-lg);
        }
        .rc-file {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: var(--space-md) 0.875rem;
            text-align: center;
            background: var(--bg);
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        .rc-file.dragover { border-color: var(--primary); background: var(--primary-light); }
        .rc-file-text { font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 0.625rem; }
        .rc-file-name { display: block; margin-top: 0.5rem; font-size: 0.78rem; color: var(--text-muted); }
        .rc-input-label {
            display: block;
            margin: var(--space-md) 0 0.375rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .rc-textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--text);
            font-family: inherit;
            font-size: 0.95rem;
            line-height: 1.7;
            padding: 0.75rem 0.875rem;
            resize: vertical;
            transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
        }
        .rc-textarea:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: var(--shadow-focus);
        }
        .rc-stage {
            text-align: center;
            padding: var(--space-md) 0.625rem 0.25rem;
        }
        .rc-name {
            font-size: 2.6rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: 0.05em;
            line-height: 1.35;
            min-height: 3.6rem;
            word-break: break-all;
            transition: transform 0.2s ease;
        }
        .rc-name.pop { transform: scale(1.12); }
        .rc-meta { margin-top: 0.25rem; font-size: 0.82rem; color: var(--text-muted); }
        .rc-btn-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: var(--space-md);
        }
        .rc-history-head {
            margin: var(--space-md) 0 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text);
        }
        .rc-history {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 0.375rem;
            max-height: 9.4rem;
            overflow-y: auto;
        }
        .rc-history li {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 0.3rem 0.625rem;
            font-size: 0.88rem;
            color: var(--text);
        }
        .rc-history li .rc-idx {
            font-family: 'Courier New', monospace;
            font-size: 0.72rem;
            color: var(--text-muted);
        }
        .rc-history li.rc-empty { border-style: dashed; color: var(--text-muted); font-size: 0.82rem; }
        .rc-toast {
            position: fixed;
            bottom: 1.875rem;
            left: 50%;
            transform: translateX(-50%);
            background: var(--text);
            color: var(--text-inverse);
            padding: 0.625rem 1.375rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            z-index: 9999;
            animation: rcToastIn 0.3s ease, rcToastOut 0.3s ease 1.7s forwards;
            max-width: 88vw;
            text-align: center;
            box-shadow: var(--shadow-md);
        }
        @keyframes rcToastIn { from { opacity: 0; transform: translateX(-50%) translateY(1rem); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }
        @keyframes rcToastOut { from { opacity: 1; } to { opacity: 0; } }
        @media (max-width: 480px) {
            .rcx-panel { padding: var(--space-md) 0.875rem; }
            .rc-name { font-size: 2.2rem; }
        }
    </style>
</head>
<body>
    <?php if ($bannedMsg): ?>
    <div class="ban-banner">
        <div class="container">
            <span class="ban-text"><?= htmlspecialchars($bannedMsg) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $headerGuestCta = 'login';
    $headerBackHref = SITE_URL . '/pages/tools.php';
    $headerBackText = t('rollcall.back_tools');
    require __DIR__ . '/../includes/site_header.php';
    ?>

    <?php require __DIR__ . '/../includes/ai_widget.php'; ?>

    <?php if ($isGuest): ?>
    <div class="guest-bar">
        <div class="container">
            <span class="guest-bar-text">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= t('guest.banner') ?>
            </span>
            <a href="<?= SITE_URL ?>/pages/register.php" class="guest-bar-btn"><?= t('guest.register_cta') ?></a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($announcement): ?>
    <div class="announcement-bar">
        <div class="container">
            <div class="announcement-scroll">
                <span><?= htmlspecialchars($announcement) ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <main class="rcx-main">
        <div class="rcx-container">
            <div class="rcx-header">
                <h1>
                    <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <defs><linearGradient id="rcxGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#f6e58d"/><stop offset="100%" stop-color="#e1b12c"/></linearGradient></defs>
                        <rect x="12" y="8" width="40" height="48" rx="6" fill="url(#rcxGrad)"/>
                        <circle cx="24" cy="23" r="4" fill="#fff" opacity="0.85"/>
                        <circle cx="24" cy="33" r="4" fill="#fff" opacity="0.65"/>
                        <circle cx="24" cy="43" r="4" fill="#fff" opacity="0.45"/>
                        <line x1="33" y1="23" x2="44" y2="23" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.75"/>
                        <line x1="33" y1="33" x2="44" y2="33" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.55"/>
                        <line x1="33" y1="43" x2="40" y2="43" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.4"/>
                    </svg>
                    <?= t('tools.name_rollcall') ?>
                </h1>
                <p class="subtitle"><?= t('rollcall.page_desc') ?></p>
            </div>

            <section class="rcx-panel">
                <div class="rc-file" id="rc-file">
                    <p class="rc-file-text"><?= t('tools.rc_file_hint') ?></p>
                    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('rc-file-input').click()"><?= t('tools.rc_pick_file') ?></button>
                    <input type="file" id="rc-file-input" accept=".txt,.csv,.xlsx,.xls,text/plain,text/csv" style="display:none" onchange="rollCallFilePicked(this)">
                    <span class="rc-file-name" id="rc-file-name"></span>
                </div>

                <label class="rc-input-label" for="rc-input"><?= t('rollcall.list_label') ?></label>
                <textarea class="rc-textarea" id="rc-input" rows="5" placeholder="<?= t('tools.rc_ph') ?>" oninput="rollCallNamesChanged()"></textarea>

                <div class="rc-stage">
                    <div class="rc-name" id="rc-name">—</div>
                    <div class="rc-meta" id="rc-meta"></div>
                </div>
                <div class="rc-btn-row">
                    <button class="btn btn-primary btn-lg" id="rc-start" onclick="rollCallToggle()"><?= t('tools.rc_start') ?></button>
                    <button class="btn btn-outline" onclick="rollCallReset()"><?= t('tools.rc_reset') ?></button>
                </div>

                <div class="rc-history-head"><?= t('tools.rc_history') ?></div>
                <ol class="rc-history" id="rc-history"></ol>
            </section>
        </div>
    </main>

    <?php /* 「回到顶部」已并入右下的统一浮动按钮（polish.js 的 .lw-fab），
             此处不再单独渲染静态按钮。 */ ?>


    <?php
    $mobileNavActive = 'me';
    require __DIR__ . '/../includes/mobile_bottom_nav.php';
    ?>

    <footer class="site-footer">
        <div class="container">
            <p><?= t('footer.disclaimer') ?></p>
            <p>&copy; 2026 <?= htmlspecialchars($siteName) ?> · <?= t('tools.copyright') ?></p>
            <p><?= t('footer.open_source') ?><a href="<?= htmlspecialchars(GITHUB_REPO_URL) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(GITHUB_REPO_NAME) ?></a></p>
        </div>
    </footer>

    <?php // E18 xlsx 体积 929KB，仅在点名页「导入 Excel」时才按需加载（见 rollCallReadExcel 的动态注入），不再随页面常驻。 ?>
    <script src="<?= asset_url('/assets/js/main.js') ?>?v=<?= asset_ver('/assets/js/main.js') ?>" defer></script>
    <?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
    <script>
    /* ===== 随机点名（独立页） ===== */
    var rcAll = [], rcPool = [], rcPicked = [], rcRolling = false, rcTimer = null, rcCurrent = '';

    function showToast(msg) {
        var t = document.createElement('div');
        t.className = 'rc-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 2200);
    }

    function rollCallInit() {
        var saved = null;
        try { saved = JSON.parse(localStorage.getItem('tools_rollcall') || 'null'); } catch (e) { saved = null; }
        if (saved && saved.all && saved.all.length) {
            rcAll = saved.all;
            rcPool = Array.isArray(saved.pool) ? saved.pool : saved.all.slice();
            rcPicked = saved.picked || [];
            var ta = document.getElementById('rc-input');
            if (ta) ta.value = rcAll.join('\n');
        }
        var area = document.getElementById('rc-file');
        var nameEl = document.getElementById('rc-name');
        if (nameEl && rcPicked.length) nameEl.textContent = rcPicked[rcPicked.length - 1];
        if (area) {
            ['dragenter', 'dragover'].forEach(function(ev) {
                area.addEventListener(ev, function(e) { e.preventDefault(); area.classList.add('dragover'); });
            });
            ['dragleave', 'drop'].forEach(function(ev) {
                area.addEventListener(ev, function(e) { e.preventDefault(); area.classList.remove('dragover'); });
            });
            area.addEventListener('drop', function(e) {
                var file = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files[0] : null;
                if (file) rollCallReadFile(file);
            });
            area.addEventListener('click', function(e) {
                if (e.target.tagName === 'BUTTON' || e.target.id === 'rc-file-input') return;
                document.getElementById('rc-file-input').click();
            });
        }
        rollCallRender();
    }

    // 每行一个姓名；从表格粘贴时按制表符、逗号分格
    function rollCallParse(text) {
        var names = [];
        (text || '').replace(/^\uFEFF/, '').split(/\r?\n/).forEach(function(line) {
            line.split(/[\t,]/).forEach(function(cell) {
                var v = cell.trim();
                if (v) names.push(v);
            });
        });
        return names;
    }

    function rollCallPersist() {
        try {
            localStorage.setItem('tools_rollcall', JSON.stringify({ all: rcAll, pool: rcPool, picked: rcPicked }));
        } catch (e) {}
    }

    function rollCallApply(names) {
        rcAll = names;
        rcPool = names.slice();
        rcPicked = [];
        rcCurrent = '';
        rollCallCancelRoll();
        var nameEl = document.getElementById('rc-name');
        if (nameEl) nameEl.textContent = '—';
        rollCallPersist();
        rollCallRender();
    }

    function rollCallNamesChanged() {
        var ta = document.getElementById('rc-input');
        if (ta) rollCallApply(rollCallParse(ta.value));
    }

    function rollCallFilePicked(input) {
        if (input.files && input.files[0]) rollCallReadFile(input.files[0]);
        input.value = '';
    }

    function rollCallReadFile(file) {
        if (file.size > 10 * 1024 * 1024) { showToast(__t('tools.rc_file_big')); return; }
        if (/\.(xlsx|xls)$/i.test(file.name)) { rollCallReadExcel(file); return; }
        if (!/\.(txt|csv)$/i.test(file.name)) { showToast(__t('tools.rc_bad_type')); return; }
        var reader = new FileReader();
        reader.onload = function(e) {
            rollCallUseNames(rollCallParse(e.target.result), file.name);
        };
        reader.readAsText(file);
    }

    // Excel：读取首个工作表，逐格取文本内容（纯数字与表头词自动跳过，兼容「序号 + 姓名」两列）
    function rollCallReadExcel(file) {
        if (typeof XLSX === 'undefined') {
            // E18 按需动态加载 929KB 的 xlsx 库（仅首次导入 Excel 时），避免常驻拖慢点名页。
            var s = document.createElement('script');
            s.src = "<?= asset_url('/assets/js/vendor/xlsx.full.min.js') ?>?v=<?= asset_ver('/assets/js/vendor/xlsx.full.min.js') ?>";
            s.onload = function () { rollCallReadExcel(file); };
            s.onerror = function () { showToast(__t('rollcall.xlsx_missing')); };
            document.head.appendChild(s);
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            var names = [];
            try {
                var wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
                var sheet = wb.Sheets[wb.SheetNames[0]];
                var rows = XLSX.utils.sheet_to_json(sheet, { header: 1, blankrows: false, defval: '' });
                rows.forEach(function(row) {
                    (row || []).forEach(function(cell) {
                        var v = (cell === null || cell === undefined) ? '' : String(cell).trim();
                        if (!v) return;
                        if (/^\d+(\.\d+)?$/.test(v)) return;
                        if (/^(序号|编号|学号|姓名|名字|名单|no\.?|#)$/i.test(v)) return;
                        names.push(v);
                    });
                });
            } catch (err) {
                showToast(__t('rollcall.read_fail'));
                return;
            }
            if (!names.length) { showToast(__t('tools.rc_empty_file')); return; }
            rollCallUseNames(names, file.name);
        };
        reader.readAsArrayBuffer(file);
    }

    function rollCallUseNames(names, fileName) {
        if (!names.length) { showToast(__t('tools.rc_empty_file')); return; }
        var ta = document.getElementById('rc-input');
        if (ta) ta.value = names.join('\n');
        rollCallApply(names);
        var fn = document.getElementById('rc-file-name');
        if (fn) fn.textContent = fileName + ' · ' + names.length;
    }

    function rollCallToggle() {
        if (rcRolling) rollCallStop(); else rollCallStart();
    }

    function rollCallStart() {
        if (!rcAll.length) { showToast(__t('tools.rc_no_names')); return; }
        if (!rcPool.length) { showToast(__t('tools.rc_done')); return; }
        rcRolling = true;
        var btn = document.getElementById('rc-start');
        if (btn) { btn.textContent = __t('tools.rc_stop'); btn.className = 'btn btn-danger btn-lg'; }
        rcTimer = setInterval(function() {
            var nameEl = document.getElementById('rc-name');
            if (!nameEl || !rcPool.length) return;
            rcCurrent = rcPool[Math.floor(Math.random() * rcPool.length)];
            nameEl.textContent = rcCurrent;
        }, 50);
    }

    function rollCallStop() {
        if (!rcRolling) return;
        rollCallCancelRoll();
        if (rcCurrent) {
            var idx = rcPool.indexOf(rcCurrent);
            if (idx !== -1) rcPool.splice(idx, 1);
            rcPicked.push(rcCurrent);
            rollCallPersist();
        }
        var nameEl = document.getElementById('rc-name');
        if (nameEl) {
            nameEl.classList.add('pop');
            setTimeout(function() { nameEl.classList.remove('pop'); }, 300);
        }
        rollCallRender();
    }

    // 只中断滚动，不记录结果（重置时使用）
    function rollCallCancelRoll() {
        if (rcTimer) { clearInterval(rcTimer); rcTimer = null; }
        rcRolling = false;
        var btn = document.getElementById('rc-start');
        if (btn) { btn.textContent = __t('tools.rc_start'); btn.className = 'btn btn-primary btn-lg'; }
    }

    function rollCallReset() {
        if (!rcPicked.length && !rcRolling) { showToast(__t('tools.rc_empty')); return; }
        if (!confirm(__t('tools.rc_reset_confirm'))) return;
        rcPool = rcAll.slice();
        rcPicked = [];
        rcCurrent = '';
        rollCallCancelRoll();
        var nameEl = document.getElementById('rc-name');
        if (nameEl) nameEl.textContent = '—';
        rollCallPersist();
        rollCallRender();
    }

    function rollCallRender() {
        var meta = document.getElementById('rc-meta');
        if (meta) meta.textContent = __t('tools.rc_remaining').replace('{n}', rcPool.length);
        var list = document.getElementById('rc-history');
        if (!list) return;
        if (!rcPicked.length) {
            list.innerHTML = '<li class="rc-empty">' + __t('tools.rc_empty') + '</li>';
            return;
        }
        list.innerHTML = rcPicked.map(function(name, i) {
            return '<li><span class="rc-idx">' + (i + 1) + '</span>' + rollCallEsc(name) + '</li>';
        }).join('');
    }

    function rollCallEsc(s) {
        return String(s).replace(/[&<>"]/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    (function() {
        rollCallInit();
        // #backToTop 已移除，回顶改由 polish.js 的统一浮动按钮提供
        var navItems = document.querySelectorAll('.mobile-nav-item');
        var currentPath = window.location.pathname;
        navItems.forEach(function(item) {
            var href = item.getAttribute('href');
            if (href && (currentPath === href || (href !== '/' && currentPath.indexOf(href.split('#')[0]) === 0))) {
                navItems.forEach(function(n) { n.classList.remove('active'); });
                item.classList.add('active');
            }
        });
    })();
    </script>
    <script src="<?= asset_url('/assets/js/enhancements.js') ?>?v=<?= asset_ver('/assets/js/enhancements.js') ?>" defer></script>
</body>
</html>