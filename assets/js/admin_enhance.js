
/**
 * 后台管理效率增强
 */
(function () {
    'use strict';
    if (window.__lwAdmin2) return;
    window.__lwAdmin2 = true;

    /* 36. 批量选择（表格多选） */
    function batchSelect() {
        document.querySelectorAll('table').forEach(function (table) {
            var tbody = table.querySelector('tbody');
            var thead = table.querySelector('thead tr');
            if (!tbody || !thead || tbody.rows.length < 2) return;
            if (table.dataset.batchReady) return;
            table.dataset.batchReady = '1';

            var th = document.createElement('th');
            th.style.width = '36px';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.title = '全选';
            th.appendChild(cb);
            thead.insertBefore(th, thead.firstChild);

            tbody.querySelectorAll('tr').forEach(function (tr) {
                var td = document.createElement('td');
                var c = document.createElement('input');
                c.type = 'checkbox';
                c.className = 'lw-row-check';
                td.appendChild(c);
                tr.insertBefore(td, tr.firstChild);
            });

            cb.addEventListener('change', function () {
                tbody.querySelectorAll('.lw-row-check').forEach(function (c) {
                    c.checked = cb.checked;
                });
                updateBar();
            });
            tbody.addEventListener('change', function (e) {
                if (e.target.classList.contains('lw-row-check')) updateBar();
            });

            // 批量操作条
            var bar = document.createElement('div');
            bar.className = 'lw-batch-bar';
            bar.style.cssText = 'display:none;padding:9px 14px;margin-bottom:10px;'
                + 'background:#EAF1FB;border:1px solid rgba(47,91,154,.22);border-radius:9px;'
                + 'font-size:13px;color:#2F5B9A;align-items:center;gap:12px;';
            bar.innerHTML = '<span class="lw-batch-count"></span>'
                + '<button type="button" class="btn btn-sm lw-batch-clear">取消选择</button>';
            table.parentNode.insertBefore(bar, table);

            bar.querySelector('.lw-batch-clear').addEventListener('click', function () {
                tbody.querySelectorAll('.lw-row-check').forEach(function (c) { c.checked = false; });
                cb.checked = false;
                updateBar();
            });

            function updateBar() {
                var n = tbody.querySelectorAll('.lw-row-check:checked').length;
                bar.style.display = n ? 'flex' : 'none';
                bar.querySelector('.lw-batch-count').textContent = '已选择 ' + n + ' 项';
            }
        });
    }

    /* 37. 表格列宽拖拽 —— 【已禁用】
     *
     * 它给每个 th 加 position:relative 和一个绝对定位的拖拽手柄。
     * 在异步渲染的表格上，thead 可能被替换，手柄就丢了；
     * 而且 position:relative 会改变某些布局行为。
     * 先关掉，等确认基础功能稳定再说。
     */
    function resizableColumns() {
        // 故意留空
    }

    /* 38. 快速搜索（Ctrl+F 增强） */
    function quickSearch() {
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
                var s = document.getElementById('filterKeyword')
                     || document.querySelector('.search-input, input[type="search"]');
                if (s) {
                    e.preventDefault();
                    s.focus();
                    s.select();
                }
            }
        });
    }

    /* 39. 表单字段快速跳转（Tab 增强 + 错误定位） */
    function formNav() {
        document.querySelectorAll('form').forEach(function (form) {
            var fields = form.querySelectorAll('input:not([type="hidden"]), textarea, select');
            if (fields.length < 3) return;
            // Enter 在单行输入框里跳到下一个
            fields.forEach(function (f, i) {
                if (f.tagName === 'TEXTAREA') return;
                f.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey && f.type !== 'submit') {
                        var next = fields[i + 1];
                        if (next) {
                            e.preventDefault();
                            next.focus();
                        }
                    }
                });
            });
        });
    }

    /* 40. 危险操作二次确认（统一） */
    function dangerousConfirm() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[onclick*="confirm"], .btn-danger');
            if (!btn || btn.dataset.confirmed) return;
            var txt = (btn.textContent || '').trim();
            if (/删除|封禁|清空|重置|作废|永久/.test(txt) && !btn.getAttribute('onclick')) {
                if (!confirm('确定要执行「' + txt + '」吗？此操作可能不可恢复。')) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            }
        }, true);
    }

    /* 41. 操作结果统一 Toast */
    function resultToast() {
        var origAlert = window.alert;
        window.alert = function (msg) {
            if (typeof msg === 'string' && /成功|已保存|已删除|已更新/.test(msg)) {
                if (window.showToast) { window.showToast(msg); return; }
            }
            return origAlert.apply(window, arguments);
        };
    }

    /* 42. 表格空状态 —— 【已移除】
     *
     * =====================================================================
     * 这段代码是个灾难，必须记下来
     * =====================================================================
     * 原来它做的是：tbody 为空时把整个 table 设为 display:none。
     * 想法是「空表格不如不显示」，但忽略了一个关键事实：
     *
     *   后台绝大多数表格是 **AJAX 异步填充**的。
     *   DOMContentLoaded 时 tbody 本来就是空的，
     *   于是表格被立即隐藏 —— 等数据回来时，
     *   它已经被 display:none 了，用户永远看不到。
     *
     * 表现就是「后台所有数据都不显示」：
     * 数据在库里、API 也返回了、DOM 里也有节点，
     * 但整张表被 CSS 藏起来了。
     *
     * 这个 bug 极难排查，因为它不报错、不打日志，
     * 看起来像后端挂了。教训：**永远不要在初始化阶段
     * 根据「当前是否为空」去隐藏容器** —— 异步内容还没到。
     *
     * 如果真需要空状态，应该由渲染方在拿到空数据时自己输出，
     * 而不是用一个全局的观察者在旁边猜。
     */
    function emptyStates() {
        // 故意留空：不做任何事。
        // 保留函数名是为了不破坏可能存在的调用点。
    }

    /* 43. 长文本单元格折叠 —— 【已禁用】
     *
     * 它会把长单元格的 innerHTML 换成「摘要 + 展开按钮」。
     * 风险：如果单元格里含事件绑定或表单元素，替换后就失效了。
     * 而且它跟异步渲染抢时序 —— 数据刚插进来就被改掉。
     * 收益不大，风险不小，直接关掉。
     */
    function foldLongCells() {
        // 故意留空
    }

    /* 44. 数据导出（表格 → CSV） */
    function exportTable() {
        document.querySelectorAll('table').forEach(function (table) {
            if (table.dataset.exportReady) return;
            var caption = table.closest('.section');
            if (!caption) return;
            table.dataset.exportReady = '1';

            var head = caption.querySelector('.section-header');
            if (!head) return;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm';
            btn.textContent = '导出 CSV';
            btn.style.marginLeft = 'auto';
            btn.addEventListener('click', function () {
                var rows = [];
                table.querySelectorAll('tr').forEach(function (tr) {
                    var cells = [];
                    tr.querySelectorAll('th, td').forEach(function (c) {
                        // 跳过 checkbox 列
                        if (c.querySelector('input[type="checkbox"]')) return;
                        var t = (c.textContent || '').trim().replace(/"/g, '""').replace(/\n/g, ' ');
                        cells.push('"' + t + '"');
                    });
                    if (cells.length) rows.push(cells.join(','));
                });
                var csv = '\ufeff' + rows.join('\r\n');
                var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'export_' + new Date().toISOString().slice(0, 10) + '.csv';
                a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
                if (window.lwToast) lwToast('已导出 ' + (rows.length - 1) + ' 行');
            });
            head.appendChild(btn);
        });
    }

    /* 45. 侧边栏当前页高亮滚动到可见 */
    function activeNavVisible() {
        var active = document.querySelector('.sidebar-nav a.active');
        if (active) {
            setTimeout(function () {
                active.scrollIntoView({ block: 'center' });
            }, 100);
        }
    }

    /* 46. 页面标题加计数徽标 */
    function titleBadge() {
        var badge = document.querySelector('.badge, .pending-count, [data-count]');
        if (!badge) return;
        var n = parseInt((badge.textContent || '').replace(/\D/g, ''), 10);
        if (n > 0 && document.title.indexOf('(' + n + ')') === -1) {
            document.title = '(' + n + ') ' + document.title;
        }
    }

    /* 47. 自动刷新数据（可选，默认关） */
    function autoRefresh() {
        if (!document.body.dataset.autorefresh) return;
        var sec = parseInt(document.body.dataset.autorefresh, 10) || 60;
        setTimeout(function () { location.reload(); }, sec * 1000);
    }

    /* 48. 复制单元格内容 */
    function copyCell() {
        document.querySelectorAll('table tbody td').forEach(function (td) {
            td.addEventListener('dblclick', function (e) {
                if (e.target.closest('button, a, input')) return;
                var t = (td.textContent || '').trim();
                if (!t || t.length > 500) return;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(t).then(function () {
                        if (window.lwToast) lwToast('已复制');
                    });
                }
            });
        });
    }

    /* 启动 */
    /* =====================================================================
     * 启动
     * =====================================================================
     * 【2026-10-31 事故记录：为什么这里只剩三个功能】
     *
     * 之前这里调用了 12 个「增强」功能，结果导致
     * 「后台所有数据都不显示」—— 排查了几个小时。
     *
     * 根因是 emptyStates()：它在 DOMContentLoaded 时检查
     * 「tbody 是否为空」，为空就把整个 table 设 display:none。
     * 但后台表格全是 AJAX 异步填充的，那一刻本来就是空的，
     * 于是表格被永久隐藏 —— 数据回来了也看不见。
     *
     * 教训：**任何在初始化阶段修改 DOM 结构的「增强」都是定时炸弹**，
     * 尤其当页面内容依赖异步加载时。
     *
     * 现在只保留零风险的三个：
     *   quickSearch      只绑快捷键
     *   dangerousConfirm 只在点击时拦截，不改 DOM
     *   activeNavVisible 只滚动侧边栏
     *
     * 其余全部禁用。如果将来要加回来，必须满足：
     *   1. 不在初始化阶段读「当前是否为空」
     *   2. 不修改表格结构（不插列、不换 innerHTML）
     *   3. 用 MutationObserver 感知异步渲染完成，而不是抢跑
     * ===================================================================== */
    function boot() {
        quickSearch();
        dangerousConfirm();
        activeNavVisible();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.LWAdmin2 = { refresh: boot };
})();
