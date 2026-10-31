<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/layout.php';

$adminUser = requireAdmin();
$adminUser = checkBanned($adminUser);
$csrfToken = generateCSRFToken();

if (!checkPermission($adminUser, 'view_users')) {
    http_response_code(403);
    die('403 Forbidden - 权限不足');
}

$isSuper = ($adminUser['role'] === 'super_admin');
$hasExportData = checkPermission($adminUser, 'export_data');

adminHeader('数据备份/恢复', $adminUser, $csrfToken);
?>

<style>
    .bk-tip { color: var(--text-muted, var(--text-muted)); font-size: 13px; line-height: 1.6; }
    .bk-box { border: 1px solid var(--border, #e2e5ea); border-radius: 12px; padding: 16px; margin-top: 12px; }
    .bk-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; align-items: center; }

    /* 备份文件选择区：点击或拖拽 */
    .bk-drop {
        margin-top: 12px;
        padding: 22px 16px;
        border: 2px dashed var(--border, #e2e5ea);
        border-radius: 12px;
        text-align: center;
        cursor: pointer;
        transition: border-color .18s ease, background .18s ease;
    }
    .bk-drop:hover, .bk-drop.dragging { border-color: var(--primary, var(--primary)); background: rgba(47, 91, 154, .05); }
    .bk-drop.has-file { border-style: solid; border-color: var(--success, var(--success)); }
    .bk-drop-ico { font-size: 28px; line-height: 1; margin-bottom: 6px; }
    .bk-drop-main { font-size: 14px; font-weight: 600; }
    .bk-drop-sub { font-size: 12px; color: var(--text-muted, var(--text-muted)); margin-top: 4px; }
    .bk-drop-file { font-size: 12.5px; color: var(--success, var(--success)); font-weight: 600; margin-top: 8px; word-break: break-all; }
</style>

<div class="section">
    <div class="section-header">
        <h3>数据备份 / 恢复</h3>
    </div>
    <div class="section-body">
        <div class="bk-box">
            <div style="font-weight:600;margin-bottom:4px;">下载完整备份（数据 + 图片 + 代码，ZIP）</div>
            <div class="bk-tip">
                把 <code>data/</code> 下全部数据表、<code>uploads/</code> 里的帖子图片，<br>
                <b>以及全站源代码</b>（<code>code/</code> 目录，已排除含密钥的 config.php），
                一起打包成一个 ZIP 并直接下载：每张表一个 <code>.json</code>，
                图片按原目录结构放在 <code>uploads/</code> 下，另有 <code>_meta.json</code>（导出信息 + 图片/代码清单）与还原说明。
                <b>一个包就是完整留档</b>。<br>
                <span style="color:#8a6d3b">注意：还原时<b>只恢复数据与图片</b>，不会写回代码 ——
                代码回滚请走 git，避免上传的包覆盖线上 PHP。</span>
            </div>
            <div class="bk-actions">
                <button class="btn btn-success" id="exportZipBtn" onclick="downloadDataZip()" <?= $hasExportData ? '' : 'disabled title="无导出权限"' ?>>下载完整备份（数据 + 图片 + 代码）</button>
                <span class="bk-tip" id="exportZipHint"></span>
            </div>
        </div>

        <div class="bk-box">
            <div style="font-weight:600;margin-bottom:4px;">公开下载完整备份（需邮箱验证码）</div>
            <div class="bk-tip">网站公开链接已对<b>所有已注册用户</b>开放：任何人申请下载时，系统会先向其注册邮箱发送验证码，验证通过后才能获取全站数据。申请记录在本页下方可见。</div>
            <div class="bk-actions">
                <a class="btn btn-primary" href="<?= SITE_URL ?>/pages/download_backup.php" target="_blank">前往公开申请下载</a>
            </div>
        </div>

        <?php if ($isSuper): ?>
        <div class="bk-box">
            <div style="font-weight:600;margin-bottom:4px;">受限下载完整备份（ZIP 包）</div>
            <div class="bk-tip">与上面的包内容完全一致（数据表 + uploads 图片），但因为是「带敏感数据外带」的场景，<b>仅超级管理员</b>可操作，下载前需再次输入当前登录密码二次校验身份。</div>
            <div class="bk-actions">
                <button class="btn btn-primary" id="downloadBackupBtn" onclick="openBackupModal('data')">下载完整备份（数据 + 图片）</button>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isSuper): ?>
        <div class="bk-box">
            <div style="font-weight:600;margin-bottom:4px;">从备份包恢复</div>
            <div class="bk-tip">
                选择由「下载完整备份」得到的 <code>.zip</code> 上传即可：系统会按表覆盖数据，并把包内
                <code>uploads/</code> 下的图片按原路径写回，<b>数据与图片一次还原</b>。
                也兼容旧的「仅数据 ZIP」「图片 ZIP」与单文件 <code>.json</code>。
                <b>此操作会覆盖现有数据、不可撤销</b>，请先导出一次当前数据。
            </div>
            <div class="bk-drop" id="bkDrop">
                <input type="file" id="bkFile" accept=".zip,.json,application/zip,application/json" hidden>
                <div class="bk-drop-ico">📦</div>
                <div class="bk-drop-main">点击选择备份包，或把文件拖到这里</div>
                <div class="bk-drop-sub">支持完整备份包 .zip（数据 + 图片）与 .json</div>
                <div class="bk-drop-file" id="bkFileName"></div>
            </div>
            <div class="bk-actions">
                <button class="btn btn-primary" id="bkImportBtn" onclick="doImportFile()">开始恢复</button>
                <button class="btn btn-outline" onclick="clearBackupFile()">清空选择</button>
            </div>
            <div class="bk-tip" style="margin-top:10px;">
                提示：单个备份包的上传上限受服务器 <code>post_max_size</code> 与本站设定（256MB）中较小者约束。
                图片非常多导致超限时，可在服务器调大 <code>post_max_size</code> / <code>upload_max_filesize</code>。
            </div>
        </div>
        <?php else: ?>
        <div class="bk-tip" style="margin-top:16px;">仅超级管理员可执行数据与图片导入（恢复）。如需导入，请使用超级管理员账号登录。</div>
        <?php endif; ?>
    </div>
</div>

<script>
    // CSRF_TOKEN 由布局页 adminHeader 以 const 全局声明，这里直接复用，避免重复声明报错

    // ===== 导出：下载完整备份 ZIP =====
    // 走隐藏表单提交而不是 fetch —— 下载是浏览器行为，fetch 拿不到 attachment。
    function downloadDataZip() {
        var btn = document.getElementById('exportZipBtn');
        var hint = document.getElementById('exportZipHint');
        if (btn) { btn.disabled = true; btn.textContent = '正在打包...'; }
        if (hint) hint.textContent = '数据与图片一起打包，图片多时需要一会儿，请勿关闭页面';
        var f = document.getElementById('bkExportForm');
        f.submit();
        setTimeout(function () {
            if (btn) { btn.disabled = false; btn.textContent = '下载完整备份（数据 + 图片）'; }
            if (hint) hint.textContent = '';
        }, 6000);
    }

    // ===== 导入：从备份文件恢复 =====
    var bkPickedFile = null;

    function bkSetFile(file) {
        bkPickedFile = file || null;
        var nameEl = document.getElementById('bkFileName');
        var drop = document.getElementById('bkDrop');
        if (!nameEl || !drop) return;
        if (bkPickedFile) {
            var kb = Math.max(1, Math.round(bkPickedFile.size / 1024));
            nameEl.textContent = '已选择：' + bkPickedFile.name + '（' + kb + ' KB）';
            drop.classList.add('has-file');
        } else {
            nameEl.textContent = '';
            drop.classList.remove('has-file');
        }
    }

    function clearBackupFile() {
        var input = document.getElementById('bkFile');
        if (input) input.value = '';
        bkSetFile(null);
    }

    /* 分卷上传阈值：超过这个大小就走切块。
     *
     * 实测线上网关在 1.5MB ~ 2MB 之间开始拒绝 POST 请求体，
     * 直接传 10MB 备份包会被返回纯文本 403，PHP 根本收不到。
     * 这里取 1MB 留足余量。
     */
    var BK_CHUNK_BYTES = 1048576;

    /* 上传单个分卷。带 XHR 头，避免被 WAF 二次拦截。 */
    function bkSendChunk(token, index, blob, name) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('action', 'chunk');
        fd.append('token', token);
        fd.append('index', String(index));
        fd.append('chunk', blob, (name || 'chunk') + '.' + index);
        return fetch('/api/admin/import.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); });
    }

    /* 分卷上传主流程：init -> 逐块 -> finish。 */
    function bkUploadChunked(file, onProgress) {
        var chunks = Math.ceil(file.size / BK_CHUNK_BYTES);
        var token = '';

        return fetch('/api/admin/import.php', {
            method: 'POST',
            body: (function () {
                var fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('action', 'init');
                fd.append('name', file.name);
                fd.append('size', String(file.size));
                fd.append('chunks', String(chunks));
                return fd;
            })(),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) {
                throw new Error((res && res.message) || '初始化分卷失败');
            }
            token = String((res.data && res.data.token) || '');
            if (!token) throw new Error('服务端没有返回上传凭证');

            // 逐块串行上传：并发会让网关更容易判定异常
            var chain = Promise.resolve();
            for (var i = 0; i < chunks; i++) {
                (function (idx) {
                    chain = chain.then(function () {
                        var start = idx * BK_CHUNK_BYTES;
                        var end = Math.min(start + BK_CHUNK_BYTES, file.size);
                        var blob = file.slice(start, end);
                        if (onProgress) onProgress(idx + 1, chunks);
                        return bkSendChunk(token, idx, blob, file.name).then(function (rr) {
                            if (!rr || !rr.success) {
                                throw new Error((rr && rr.message) || ('第 ' + (idx + 1) + ' 块上传失败'));
                            }
                        });
                    });
                })(i);
            }
            return chain;
        })
        .then(function () {
            return fetch('/api/admin/import.php', {
                method: 'POST',
                body: (function () {
                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'finish');
                    fd.append('token', token);
                    return fd;
                })(),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        })
        .then(function (r) { return r.json(); })
        .catch(function (err) {
            // 失败时通知服务端清理临时文件
            if (token) {
                try {
                    var fd = new FormData();
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('action', 'abort');
                    fd.append('token', token);
                    fetch('/api/admin/import.php', {
                        method: 'POST', body: fd, credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                } catch (e) {}
            }
            throw err;
        });
    }

    function doImportFile() {
        if (!bkPickedFile) { showToast('请先选择备份文件（.zip 或 .json）', 'warning'); return; }
        var btn = document.getElementById('bkImportBtn');
        confirmDialog(
            '即将用「' + bkPickedFile.name + '」覆盖现有数据。',
            '此操作不可撤销，请确认已导出当前数据作为退路。是否继续？',
            function () {
                if (btn) { btn.disabled = true; btn.textContent = '正在恢复...'; }

                /* 【大文件走分卷】
                 *
                 * 线上网关会拒绝约 1.5MB 以上的 POST 请求体，返回纯文本 403。
                 * 10MB 的备份包直传必然失败，而报错信息跟 CSRF 失败一模一样，
                 * 根本看不出是被网关拦的。所以超过阈值就自动切块。
                 */
                if (bkPickedFile.size > BK_CHUNK_BYTES) {
                    bkUploadChunked(bkPickedFile, function (done, total) {
                        if (btn) { btn.textContent = '正在恢复... ' + done + '/' + total; }
                    })
                    .then(function (res) {
                        showToast((res && res.message) || '恢复完成', (res && res.success) ? 'success' : 'error');
                        if (res && res.success) { clearBackupFile(); }
                    })
                    .catch(function (err) {
                        showToast('导入失败：' + ((err && err.message) || '网络异常'), 'error');
                    })
                    .then(function () {
                        if (btn) { btn.disabled = false; btn.textContent = '开始恢复'; }
                    });
                    return;
                }

                var fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('file', bkPickedFile, bkPickedFile.name);
                /* 【必须带 X-Requested-With】
                 *
                 * WAF 对 POST 的判定是：Referer 为空时，只有「XHR 头 或 CSRF token」
                 * 才算同站请求。这个上传走的是 FormData，某些情况下浏览器会把
                 * Referer 裁掉（跨协议跳转、隐私模式、扩展拦截都会），
                 * 于是请求被 WAF 当成外部提交直接返回纯文本 403 Forbidden。
                 *
                 * 症状极具误导性：控制台只看到 POST .../import.php 403，
                 * 页面弹「网络错误」，看起来像网络问题，其实是安全策略拦的。
                 * 显式声明自己是 XHR 就能稳定通过。
                 */
                fetch('/api/admin/import.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        showToast((res && res.message) || '恢复完成', (res && res.success) ? 'success' : 'error');
                        if (res && res.success) { clearBackupFile(); }
                    })
                    .catch(function () { showToast('导入失败（网络异常）', 'error'); })
                    .then(function () {
                        if (btn) { btn.disabled = false; btn.textContent = '开始恢复'; }
                    });
            }
        );
    }

    // 拖拽 + 点击选择
    (function bindBackupDrop() {
        function ready(fn) {
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn, { once: true });
            else fn();
        }
        ready(function () {
            var drop = document.getElementById('bkDrop');
            var input = document.getElementById('bkFile');
            if (!drop || !input) return;
            drop.addEventListener('click', function () { input.click(); });
            input.addEventListener('change', function () {
                bkSetFile(input.files && input.files[0] ? input.files[0] : null);
            });
            ['dragenter', 'dragover'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragging'); });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('dragging'); });
            });
            drop.addEventListener('drop', function (e) {
                var f = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files[0] : null;
                if (!f) return;
                bkSetFile(f);
                try { input.files = e.dataTransfer.files; } catch (err) { /* 部分浏览器只读，交给 bkPickedFile */ }
            });
        });
    })();

    // ===== 下载备份（二次校验身份）=====
    // type: 'data' = 仅 JSON 数据；'media' = 仅 uploads/ 图片 ZIP。两者都要密码 + 可选 2FA。
    var BACKUP_2FA = <?= !empty($adminUser['twofa_secret']) ? 'true' : 'false' ?>;
    var BK_ENDPOINT = {
        data: '/api/admin/backup_download.php',
        media: '/api/admin/backup_media.php'
    };
    var bkType = 'data';
    function openBackupModal(type) {
        bkType = (type === 'media') ? 'media' : 'data';
        var pwd = document.getElementById('bkPassword');
        var twofaRow = document.getElementById('bkTwofaRow');
        if (pwd) pwd.value = '';
        var twofa = document.getElementById('bkTwofa');
        if (twofa) twofa.value = '';
        if (twofaRow) twofaRow.style.display = BACKUP_2FA ? 'block' : 'none';
        var title = document.getElementById('bkModalTitle');
        if (title) title.textContent = (bkType === 'media') ? '下载图片备份（ZIP）' : '下载完整备份（数据 + 图片）';
        var desc = document.getElementById('bkModalDesc');
        if (desc) {
            desc.innerHTML = (bkType === 'media')
                ? '确认将打包 <code>uploads/</code> 下的全部帖子图片为 <code>ZIP</code>（内含 manifest.json 校验清单）。'
                : '为保护全站数据，下载前需再次验证站长身份。确认将下载包含用户、私信等全部数据、以及 <code>uploads/</code> 全部图片的完整 <code>ZIP</code> 备份包。';
        }
        var okBtn = document.getElementById('bkDownloadOk');
        if (okBtn) okBtn.textContent = (bkType === 'media') ? '确认并打包图片' : '确认并下载';
        openModal('backupModal');
        if (pwd) setTimeout(function(){ pwd.focus(); }, 60);
    }
    function submitBackupDownload() {
        var btn = document.getElementById('bkDownloadOk');
        var pwd = (document.getElementById('bkPassword').value || '').trim();
        if (!pwd) { showToast('请输入当前登录密码', 'warning'); return; }
        if (BACKUP_2FA) {
            var twofa = (document.getElementById('bkTwofa').value || '').trim();
            if (twofa.length !== 6) { showToast('请输入Authenticator的6位验证码', 'warning'); return; }
        }
        if (btn) { btn.disabled = true; btn.textContent = (bkType === 'media') ? '正在打包图片...' : '正在生成...'; }
        // 通过隐藏表单提交触发浏览器下载（避免 fetch 无法保存 attachment）
        var f = document.getElementById('bkDownloadForm');
        f.action = BK_ENDPOINT[bkType] || BK_ENDPOINT.data;
        document.getElementById('bkHiddenPwd').value = pwd;
        document.getElementById('bkHiddenTwofa').value = BACKUP_2FA ? document.getElementById('bkTwofa').value.trim() : '';
        f.submit();
        // 图片包可能较大，复位时间给长一点
        var wait = (bkType === 'media') ? 8000 : 2500;
        var resetText = (bkType === 'media') ? '确认并打包图片' : '确认并下载';
        setTimeout(function(){ if (btn) { btn.disabled = false; btn.textContent = resetText; } }, wait);
    }
    // ===== /下载备份 =====

    (function () {
        function runBkErrToast() {
            var params = new URLSearchParams(location.search);
            var errMsg = params.get('bkerr');
            if (errMsg) {
                if (typeof showToast === 'function') showToast(errMsg, 'error');
                // 清除地址栏参数，避免刷新后重复提示
                if (window.history && history.replaceState) history.replaceState(null, '', location.pathname);
                var btn = document.getElementById('downloadBackupBtn');
                if (btn) { btn.disabled = false; btn.textContent = '下载完整备份'; }
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', runBkErrToast);
        } else {
            runBkErrToast();
        }
    })();
</script>

<!-- 下载完整备份：二次校验身份弹窗 -->
<div class="modal-overlay" id="backupModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="bkModalTitle">下载完整备份（ZIP）</h3>
            <button type="button" class="modal-close" onclick="closeModal('backupModal')" aria-label="关闭">×</button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;" id="bkModalDesc">为保护全站数据，下载前需再次验证站长身份。确认将下载包含用户、私信等全部数据的 <code>ZIP</code> 备份包。</p>
            <div class="form-group">
                <label for="bkPassword">当前登录密码</label>
                <input type="password" id="bkPassword" class="form-control" placeholder="请输入当前登录密码">
            </div>
            <div class="form-group" id="bkTwofaRow" style="display:none;">
                <label for="bkTwofa">双重验证码</label>
                <input type="text" id="bkTwofa" class="form-control" maxlength="6" placeholder="Authenticator 6位验证码" inputmode="numeric" autocomplete="off">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('backupModal')">取消</button>
            <button type="button" class="btn btn-danger" id="bkDownloadOk" onclick="submitBackupDownload()">确认并下载</button>
        </div>
    </div>
</div>

<form id="bkExportForm" method="POST" action="/api/admin/backup_zip.php" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
</form>

<form id="bkDownloadForm" method="POST" action="/api/admin/backup_download.php" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="password" id="bkHiddenPwd" value="">
    <input type="hidden" name="twofa" id="bkHiddenTwofa" value="">
</form>

<?php
// ===== 备份下载申请记录（面向所有已注册用户公开，申请需邮箱验证码）=====
$fs = getFS();
$apps = $fs->orderBy('backup_applications', 'created_at', 'DESC');
$apps = array_slice($apps, 0, 100);
$userInfo = [];
foreach ($fs->getAll('users') as $u) { $userInfo[$u['id']] = $u; }
function appStatusBadge($s) {
    $map = [
        'pending'  => ['待验证', 'badge-warning'],
        'approved' => ['已通过', 'badge-success'],
        'expired'  => ['已过期', 'badge-info'],
    ];
    list($label, $cls) = isset($map[$s]) ? $map[$s] : [$s, 'badge-info'];
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}
?>
<div class="section">
    <div class="section-header"><h3>备份下载申请记录</h3></div>
    <div class="section-body">
        <p class="bk-tip">已注册用户申请下载完整数据时，会先向注册邮箱发送验证码，验证通过后方可下载。此处展示申请与验证情况，方便审计与追责。</p>
        <div class="filter-row" style="margin-top:14px;">
            <div class="form-group" style="min-width:240px;max-width:340px;">
                <label>按用户筛选</label>
                <div id="appFilter"></div>
            </div>
            <button type="button" class="btn btn-outline" onclick="resetAppFilter()" style="margin-bottom:2px;">显示全部</button>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr>
                    <th>ID</th><th>申请人</th><th>QQ / 邮箱</th><th>状态</th><th>申请时间</th><th>下载次数</th>
                </tr></thead>
                <tbody id="appTableBody">
                <?php if (!$apps): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--text-secondary);">暂无申请记录</td></tr>
                <?php else: foreach ($apps as $a): $u = $userInfo[$a['user_id']] ?? null; $nick = $u['nickname'] ?? ('用户#' . $a['user_id']); ?>
                    <tr data-qq="<?= xss_clean($a['qq']) ?>" data-nick="<?= xss_clean(strtolower($nick)) ?>">
                        <td><?= (int)$a['id'] ?></td>
                        <td><?= xss_clean($nick) ?></td>
                        <td><?= xss_clean($a['qq']) ?></td>
                        <td><?= appStatusBadge($a['status'] ?? '') ?></td>
                        <td><?= xss_clean($a['created_at'] ?? '') ?></td>
                        <td><?= (int)($a['download_count'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    var _$appRows = Array.prototype.slice.call(document.querySelectorAll('#appTableBody tr[data-qq]'));
    window.resetAppFilter = function () {
        var p = (window.UserPicker && UserPicker._inst) ? UserPicker._inst : null;
        if (p) p.clear();
        _$appRows.forEach(function (r) { r.style.display = ''; });
    };
    // UserPicker 由页脚的 admin.common.js 提供，比这段脚本晚执行；
    // 直接在这里 init 会抛 "UserPicker is not defined"，所以等 DOM 就绪（此时页脚脚本已跑完）再初始化。
    document.addEventListener('DOMContentLoaded', function () {
        if (!window.UserPicker) return;
        UserPicker.init('appFilter', {
            onSelect: function (u) {
                _$appRows.forEach(function (r) {
                    r.style.display = (r.getAttribute('data-qq') === u.qq) ? '' : 'none';
                });
            },
            onClear: function () {
                _$appRows.forEach(function (r) { r.style.display = ''; });
            }
        });
    });
</script>

<?php adminFooter(); ?>