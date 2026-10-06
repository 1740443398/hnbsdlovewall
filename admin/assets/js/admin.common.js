/* =========================================================
 * 后台通用交互工具：统一二次确认弹窗 / 防抖 / 简单 Toast 包装
 * 依赖 adminHeader（layout.php 的 <head>）注入的 showToast / openModal / closeModal
 * （原先定义在页尾 adminFooter，会因 Promise 微任务先于文档解析完成而「未定义」，已前移）
 * 请勿重复定义 CSRF_TOKEN；此处不引用 token，仅复用全局 const。
 * ========================================================= */
(function () {
    'use strict';

    // 统一二次确认弹窗（替代散落的 window.confirm），自动按需创建并复用
    function ensureConfirmEl() {
        var el = document.getElementById('adminConfirmModal');
        if (el) return el;
        el = document.createElement('div');
        el.className = 'modal-overlay';
        el.id = 'adminConfirmModal';
        el.innerHTML =
            '<div class="modal">' +
            '<div class="modal-header"><h3 id="adminConfirmTitle">确认操作</h3>' +
            '<button type="button" class="modal-close" data-admin-close aria-label="关闭">&times;</button></div>' +
            '<div class="modal-body"><p id="adminConfirmMsg"></p></div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-outline" data-admin-close>取消</button>' +
            '<button type="button" class="btn btn-danger" id="adminConfirmOk">确认</button>' +
            '</div></div>';
        document.body.appendChild(el);
        el.querySelectorAll('[data-admin-close]').forEach(function (b) {
            b.addEventListener('click', function () {
                el.classList.remove('show');
                document.body.style.overflow = '';
            });
        });
        el.addEventListener('click', function (e) {
            if (e.target === el) {
                el.classList.remove('show');
                document.body.style.overflow = '';
            }
        });
        return el;
    }

    // confirmDialog(title, msg, callback)
    window.confirmDialog = function (title, msg, cb) {
        var el = ensureConfirmEl();
        el.querySelector('#adminConfirmTitle').textContent = title || '确认操作';
        el.querySelector('#adminConfirmMsg').textContent = msg || '';
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
        var ok = el.querySelector('#adminConfirmOk');
        if (ok._acHandler) ok.removeEventListener('click', ok._acHandler);
        var handler = function () {
            el.classList.remove('show');
            document.body.style.overflow = '';
            if (cb) cb();
        };
        ok._acHandler = handler;
        ok.addEventListener('click', handler);
    };

    // ── 危险操作「二次密码确认」（F11）────────────────────────────
    // 破坏性后台操作要求管理员重新输入登录密码，防止会话被劫持时被一键清库。
    function ensurePwdEl() {
        var el = document.getElementById('adminPwdModal');
        if (el) return el;
        el = document.createElement('div');
        el.className = 'modal-overlay';
        el.id = 'adminPwdModal';
        el.innerHTML =
            '<div class="modal">' +
            '<div class="modal-header"><h3 id="adminPwdTitle">安全验证</h3>' +
            '<button type="button" class="modal-close" data-pwd-close aria-label="关闭">&times;</button></div>' +
            '<div class="modal-body">' +
            '<p id="adminPwdMsg" style="margin:0 0 12px;white-space:pre-line;"></p>' +
            '<input type="password" id="adminPwdInput" class="form-input" autocomplete="current-password" ' +
            'placeholder="请输入你的登录密码" style="width:100%;box-sizing:border-box;padding:10px 12px;border-radius:10px;border:1px solid var(--border,#e2e8f0);">' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-outline" data-pwd-close>取消</button>' +
            '<button type="button" class="btn btn-danger" id="adminPwdOk">确认</button>' +
            '</div></div>';
        document.body.appendChild(el);
        el.querySelectorAll('[data-pwd-close]').forEach(function (b) {
            b.addEventListener('click', function () { hidePwd(); });
        });
        el.addEventListener('click', function (e) { if (e.target === el) hidePwd(); });
        el.querySelector('#adminPwdInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); el.querySelector('#adminPwdOk').click(); }
        });
        return el;
    }

    function hidePwd() {
        var el = document.getElementById('adminPwdModal');
        if (el) { el.classList.remove('show'); document.body.style.overflow = ''; }
    }

    // askAdminPassword(message, cb)：弹出密码框，cb(password)（取消时 cb(null)）
    window.askAdminPassword = function (message, cb) {
        var el = ensurePwdEl();
        el.querySelector('#adminPwdMsg').textContent = message || '请输入你的登录密码以继续';
        var input = el.querySelector('#adminPwdInput');
        input.value = '';
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { input.focus(); }, 60);
        var ok = el.querySelector('#adminPwdOk');
        if (ok._pwdHandler) ok.removeEventListener('click', ok._pwdHandler);
        var handler = function () {
            var v = input.value;
            hidePwd();
            if (cb) cb(v ? v : null);
        };
        ok._pwdHandler = handler;
        ok.addEventListener('click', handler);
    };

    /**
     * adminSecurePost(url, params, opts)：危险操作安全提交。
     * 流程：弹密码框 → 携带 admin_password 提交 → 若服务端返回 reauth_required（密码错误）则重新弹框重试。
     * opts: { message, onSuccess(data), onError(msg) }
     */
    window.adminSecurePost = function (url, params, opts) {
        opts = opts || {};
        function attempt() {
            window.askAdminPassword(opts.message, function (pwd) {
                if (!pwd) return; // 用户取消
                var token = (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : '';
                var payload = {};
                Object.keys(params || {}).forEach(function (k) { payload[k] = params[k]; });
                payload.csrf_token = token;
                payload.admin_password = pwd;
                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body: new URLSearchParams(payload)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        if (opts.onSuccess) opts.onSuccess(data);
                    } else if (data && data.reauth_required) {
                        if (window.showToast) showToast(data.message || '请重新输入密码', 'error');
                        attempt(); // 密码错误：再次弹框
                    } else {
                        var m = (data && data.message) || '操作失败';
                        if (opts.onError) opts.onError(m);
                        else if (window.showToast) showToast(m, 'error');
                    }
                })
                .catch(function () {
                    if (opts.onError) opts.onError('网络错误');
                    else if (window.showToast) showToast('网络错误', 'error');
                });
            });
        }
        attempt();
    };

    // debounce(fn, wait)
    window.debounce = function (fn, wait) {
        var t;
        return function () {
            var args = arguments;
            var ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait || 300);
        };
    };

    // 人性化时间：xx分钟前 / 今天 HH:mm / 昨天 / YYYY-MM-DD
    window.humanTime = function (val) {
        if (!val) return '';
        var d = new Date(String(val).replace(/-/g, '/'));
        if (isNaN(d.getTime())) return val;
        var now = new Date();
        var diff = (now - d) / 1000;
        if (diff < 60) return '刚刚';
        if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
        function pad(n) { return n < 10 ? '0' + n : n; }
        var hh = pad(d.getHours()), mm = pad(d.getMinutes());
        if (d.toDateString() === now.toDateString()) return '今天 ' + hh + ':' + mm;
        var yest = new Date(now); yest.setDate(now.getDate() - 1);
        if (d.toDateString() === yest.toDateString()) return '昨天 ' + hh + ':' + mm;
        return pad(d.getFullYear()) + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    };

    /**
     * 通用「搜索 + 下拉选目标用户」选择器。
     * 解决后台需要指定目标用户时手打 QQ 号不方便的问题。
     *
     * 用法：
     *   UserPicker.init('containerId', {
     *     endpoint: '/api/user/list.php',   // 默认；后端需登录。
     *     onSelect: function(user) { ... }  // user: { id, qq, nickname, avatar, role }
     *   });
     * 选择后组件会在输入框展示选中用户；再次清除可重新搜索选择。
     */
    function ensurePickerStyles() {
        if (document.getElementById('up-style')) return;
        var st = document.createElement('style');
        st.id = 'up-style';
        st.textContent =
            '.user-picker{position:relative;min-width:220px;font-size:13px;}' +
            '.up-input{width:100%;padding:9px 30px 9px 12px;border:1.5px solid transparent;border-radius:12px;font-size:13px;color:var(--text,#1e293b);background:var(--input-bg,#f4f4f5);outline:none;transition:all 0.25s cubic-bezier(0.34,1.56,0.64,1);}' +
            '.up-input:focus{border-color:var(--primary,#111827);background:var(--input-focus,#fff);box-shadow:0 0 0 4px rgba(17,24,39,0.04);}' +
            '.up-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:none;font-size:15px;color:var(--text-secondary,#64748b);cursor:pointer;line-height:1;padding:2px;}' +
            '.up-clear:hover{color:var(--danger,#ef4444);}' +
            '.up-list{position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:400;max-height:260px;overflow-y:auto;background:var(--card-bg,#fff);border:1px solid var(--border,#e2e8f0);border-radius:16px;box-shadow:0 12px 48px rgba(0,0,0,0.08);display:none;margin:0;padding:6px;list-style:none;}' +
            '.up-list.show{display:block;}' +
            '.up-list li{padding:8px 10px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all 0.25s cubic-bezier(0.34,1.56,0.64,1);}' +
            '.up-list li:hover{background:var(--input-bg,#f4f4f5);color:var(--text,#1e293b);}' +
            '.up-list .up-ava{width:28px;height:28px;border-radius:50%;flex:0 0 auto;}' +
            '.up-list .up-nick{font-weight:600;}' +
            '.up-list .up-qq{color:var(--text-secondary,#64748b);font-size:12px;}' +
            '.up-empty{padding:10px;color:var(--text-secondary,#64748b);text-align:center;}' +
            '.up-loading{padding:10px;color:var(--text-secondary,#64748b);text-align:center;}';
        (document.head || document.documentElement).appendChild(st);
    }

    window.UserPicker = {
        init: function (rootId, opts) {
            var opts = opts || {};
            var endpoint = opts.endpoint || '/api/user/list.php';
            var root = document.getElementById(rootId);
            if (!root) return null;
            root.classList.add('user-picker');
            ensurePickerStyles();
            root.innerHTML =
                '<input type="text" class="up-input" placeholder="搜索用户名 / QQ号..." autocomplete="off">' +
                '<button type="button" class="up-clear" title="清除上次选择" style="display:none;">&times;</button>' +
                '<ul class="up-list"></ul>';

            var input = root.querySelector('.up-input');
            var clear = root.querySelector('.up-clear');
            var list = root.querySelector('.up-list');
            var current = null;

            var close = function () { list.classList.remove('show'); };

            var render = function (users) {
                list.innerHTML = '';
                if (!users.length) {
                    var li = document.createElement('li');
                    li.className = 'up-empty';
                    li.textContent = '无匹配用户';
                    list.appendChild(li);
                }
                users.forEach(function (u) {
                    var li = document.createElement('li');
                    var img = document.createElement('img');
                    img.className = 'up-ava';
                    img.src = u.avatar || '';
                    img.onerror = function () { this.style.visibility = 'hidden'; };
                    var nick = document.createElement('span');
                    nick.className = 'up-nick';
                    nick.textContent = u.nickname || ('QQ:' + u.qq);
                    var qq = document.createElement('span');
                    qq.className = 'up-qq';
                    qq.textContent = (u.qq || '') + (u.role === 'super_admin' ? ' · 站长' : (u.role === 'admin' ? ' · 管理员' : ''));
                    li.appendChild(img);
                    li.appendChild(nick);
                    li.appendChild(qq);
                    li.addEventListener('click', function () {
                        current = u;
                        input.value = (u.nickname || '') + '（' + (u.qq || '') + '）';
                        clear.style.display = 'block';
                        close();
                        if (opts.onSelect) opts.onSelect(u);
                    });
                    list.appendChild(li);
                });
                list.classList.add('show');
            };

            var search = debounce(function () {
                var kw = input.value.trim();
                if (!kw) { list.innerHTML = ''; return; }
                var li = document.createElement('li');
                li.className = 'up-loading';
                li.textContent = '搜索中...';
                list.innerHTML = '';
                list.appendChild(li);
                list.classList.add('show');
                fetch(endpoint + '?search=' + encodeURIComponent(kw) + '&limit=20', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success && res.data && res.data.users) {
                            render(res.data.users);
                        } else {
                            var li2 = document.createElement('li');
                            li2.className = 'up-empty';
                            li2.textContent = ((res && res.message) || '搜索失败');
                            list.innerHTML = '';
                            list.appendChild(li2);
                            list.classList.add('show');
                        }
                    })
                    .catch(function () {
                        var li2 = document.createElement('li');
                        li2.className = 'up-empty';
                        li2.textContent = '网络异常，搜索失败';
                        list.innerHTML = '';
                        list.appendChild(li2);
                        list.classList.add('show');
                    });
            }, 320);

            input.addEventListener('input', function () {
                clear.style.display = current ? 'block' : 'none';
                search();
            });
            input.addEventListener('focus', function () {
                if (input.value.trim()) search();
            });
            clear.addEventListener('click', function () {
                input.value = '';
                current = null;
                clear.style.display = 'none';
                list.innerHTML = '';
                close();
                if (opts.onClear) opts.onClear();
            });
            document.addEventListener('click', function (e) {
                if (!root.contains(e.target)) close();
            });

            var inst = {
                getValue: function () { return current; },
                clear: function () { clear.dispatchEvent(new Event('click')); return false; },
                setValue: function (u) {
                    if (!u) return false;
                    current = u;
                    input.value = (u.nickname || '') + '（' + (u.qq || '') + '）';
                    clear.style.display = 'block';
                    if (opts.onSelect) opts.onSelect(u);
                    return true;
                }
            };
            window.UserPicker._inst = inst;
            return inst;
        }
    };
})();