
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

    /* 37. 表格列宽拖拽 */
    function resizableColumns() {
        document.querySelectorAll('table').forEach(function (table) {
            var ths = table.querySelectorAll('thead th');
            if (ths.length < 2 || table.dataset.resizable) return;
            table.dataset.resizable = '1';
            ths.forEach(function (th) {
                if (th.querySelector('.lw-col-resize')) return;
                th.style.position = 'relative';
                var grip = document.createElement('div');
                grip.className = 'lw-col-resize';
                grip.style.cssText = 'position:absolute;right:0;top:0;bottom:0;width:5px;'
                    + 'cursor:col-resize;user-select:none;';
                th.appendChild(grip);

                var startX, startW;
                grip.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    startX = e.pageX;
                    startW = th.offsetWidth;
                    function onMove(ev) {
                        var w = Math.max(50, startW + ev.pageX - startX);
                        th.style.width = w + 'px';
                        th.style.minWidth = w + 'px';
                    }
                    function onUp() {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                    }
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
            });
        });
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

    /* 42. 表格空状态友好提示 */
    function emptyStates() {
        document.querySelectorAll('table tbody').forEach(function (tbody) {
            if (tbody.rows.length === 0 || (tbody.rows.length === 1 && tbody.rows[0].cells.length === 1)) {
                var table = tbody.closest('table');
                if (table) table.style.display = 'none';
            }
        });
    }

    /* 43. 长文本单元格折叠 */
    function foldLongCells() {
        document.querySelectorAll('table tbody td').forEach(function (td) {
            var txt = (td.textContent || '').trim();
            if (txt.length < 80 || td.querySelector('button, a')) return;
            if (td.dataset.folded) return;
            td.dataset.folded = '1';
            var full = td.innerHTML;
            var short = txt.slice(0, 60) + '…';
            td.innerHTML = '<span class="lw-cell-short">' + short + '</span>'
                + '<button type="button" class="lw-cell-more" style="margin-left:6px;font-size:11.5px;'
                + 'color:#2F5B9A;background:none;border:0;cursor:pointer;padding:0;">展开</button>';
            td.querySelector('.lw-cell-more').addEventListener('click', function () {
                var expanded = td.dataset.expanded === '1';
                if (expanded) {
                    td.innerHTML = '<span class="lw-cell-short">' + short + '</span>'
                        + '<button type="button" class="lw-cell-more" style="margin-left:6px;font-size:11.5px;'
                        + 'color:#2F5B9A;background:none;border:0;cursor:pointer;padding:0;">展开</button>';
                    td.dataset.expanded = '';
                } else {
                    td.innerHTML = full + '<button type="button" class="lw-cell-more" style="margin-left:6px;'
                        + 'font-size:11.5px;color:#2F5B9A;background:none;border:0;cursor:pointer;padding:0;">收起</button>';
                    td.dataset.expanded = '1';
                }
                bindMore(td);
            });
            bindMore(td);
        });
        function bindMore(td) {
            var b = td.querySelector('.lw-cell-more');
            if (!b) return;
            b.addEventListener('click', function () {
                var expanded = td.dataset.expanded === '1';
                var txt = td.textContent.replace(/展开|收起/g, '').trim();
                if (expanded) {
                    td.innerHTML = '<span class="lw-cell-short">' + txt.slice(0, 60) + '…</span>'
                        + '<button type="button" class="lw-cell-more" style="margin-left:6px;font-size:11.5px;'
                        + 'color:#2F5B9A;background:none;border:0;cursor:pointer;padding:0;">展开</button>';
                    td.dataset.expanded = '';
                } else {
                    td.innerHTML = txt + '<button type="button" class="lw-cell-more" style="margin-left:6px;'
                        + 'font-size:11.5px;color:#2F5B9A;background:none;border:0;cursor:pointer;padding:0;">收起</button>';
                    td.dataset.expanded = '1';
                }
                bindMore(td);
            });
        }
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
    function boot() {
        batchSelect();
        resizableColumns();
        quickSearch();
        formNav();
        dangerousConfirm();
        resultToast();
        emptyStates();
        exportTable();
        activeNavVisible();
        titleBadge();
        autoRefresh();
        copyCell();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.LWAdmin2 = { refresh: boot };
})();
