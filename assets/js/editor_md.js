
/**
 * 发帖编辑器增强
 *
 * 支持 Markdown 与白名单 HTML 之后，编辑体验也得跟上：
 * 光给个 textarea 让人手打 ## 和 ** 是不现实的。
 */
(function () {
    'use strict';
    if (window.__lwEditor) return;

    function findEditor() {
        return document.getElementById('postContent')
            || document.querySelector('textarea[name="content"]')
            || document.querySelector('.post-form textarea');
    }

    /* ── 1-6. 工具栏：粗体/斜体/标题/列表/代码/引用 ────────── */
    function wrapSel(ta, before, after, placeholder) {
        var s = ta.selectionStart, e = ta.selectionEnd;
        var sel = ta.value.slice(s, e) || placeholder || '';
        var out = before + sel + (after || '');
        ta.value = ta.value.slice(0, s) + out + ta.value.slice(e);
        ta.selectionStart = s + before.length;
        ta.selectionEnd = s + before.length + sel.length;
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function linePrefix(ta, prefix) {
        var s = ta.selectionStart, e = ta.selectionEnd;
        var start = ta.value.lastIndexOf('\n', s - 1) + 1;
        var end = ta.value.indexOf('\n', e);
        if (end === -1) end = ta.value.length;
        var block = ta.value.slice(start, end);
        var lines = block.split('\n').map(function (l) {
            return l.startsWith(prefix) ? l.slice(prefix.length) : prefix + l;
        });
        ta.value = ta.value.slice(0, start) + lines.join('\n') + ta.value.slice(end);
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    var TOOLS = [
        { k: 'bold',   label: 'B',  title: '粗体 (Ctrl+B)',  fn: function (t) { wrapSel(t, '**', '**', '粗体'); } },
        { k: 'italic', label: 'I',  title: '斜体 (Ctrl+I)',  fn: function (t) { wrapSel(t, '*', '*', '斜体'); } },
        { k: 'h2',     label: 'H',  title: '标题',           fn: function (t) { linePrefix(t, '## '); } },
        { k: 'list',   label: '•',  title: '无序列表',       fn: function (t) { linePrefix(t, '- '); } },
        { k: 'olist',  label: '1.', title: '有序列表',       fn: function (t) { linePrefix(t, '1. '); } },
        { k: 'quote',  label: '❝',  title: '引用',           fn: function (t) { linePrefix(t, '> '); } },
        { k: 'code',   label: '</>', title: '代码块',        fn: function (t) { wrapSel(t, '\n\`\`\`\n', '\n\`\`\`\n', 'code'); } },
        { k: 'link',   label: '🔗', title: '链接',           fn: function (t) { wrapSel(t, '[', '](https://)', '文字'); } },
        { k: 'img',    label: '🖼', title: '图片',           fn: function (t) { wrapSel(t, '![', '](/uploads/images/)', 'alt'); } },
        { k: 'table',  label: '⊞',  title: '表格',           fn: function (t) {
            var tpl = '\n| 列1 | 列2 |\n|------|------|\n| 内容 | 内容 |\n';
            var s = t.selectionStart;
            t.value = t.value.slice(0, s) + tpl + t.value.slice(s);
            t.focus();
            t.dispatchEvent(new Event('input', { bubbles: true }));
        } },
    ];

    function buildToolbar(ta) {
        var bar = document.createElement('div');
        bar.className = 'lw-md-toolbar';
        TOOLS.forEach(function (tool) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'lw-md-btn';
            b.textContent = tool.label;
            b.title = tool.title;
            b.addEventListener('click', function (e) {
                e.preventDefault();
                tool.fn(ta);
            });
            bar.appendChild(b);
        });
        return bar;
    }

    /* ── 7. 实时预览 ──────────────────────────────────────── */
    function buildPreview(ta) {
        var wrap = document.createElement('div');
        wrap.className = 'lw-md-preview-wrap';
        wrap.style.display = 'none';

        var head = document.createElement('div');
        head.className = 'lw-md-preview-head';
        head.textContent = '预览（Markdown / HTML 白名单）';

        var body = document.createElement('div');
        body.className = 'lw-md-preview-body';

        wrap.appendChild(head);
        wrap.appendChild(body);
        return { wrap: wrap, body: body };
    }

    // 简易客户端 Markdown 预览（服务端才是权威，这里只为即时反馈）
    function clientRender(md) {
        var h = md
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        // 代码块
        h = h.replace(/```(\w*)\n([\s\S]*?)```/g, function (_, lang, code) {
            return '<pre class="lw-code-block"><code>' + code + '</code></pre>';
        });
        // 行内代码
        h = h.replace(/`([^`\n]+)`/g, '<code class="lw-code-inline">$1</code>');
        // 标题
        h = h.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
        h = h.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        h = h.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        h = h.replace(/^# (.+)$/gm, '<h1>$1</h1>');
        // 引用
        h = h.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
        // 列表
        h = h.replace(/^[-*+] (.+)$/gm, '<li>$1</li>');
        h = h.replace(/(<li>.*?<\/li>)(?!\s*<li>)/gs, '<ul>$1</ul>');
        // 粗体/斜体/删除线
        h = h.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        h = h.replace(/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/g, '<em>$1</em>');
        h = h.replace(/~~(.+?)~~/g, '<del>$1</del>');
        // 链接/图片
        h = h.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<img src="$2" alt="$1" class="lw-md-img">');
        h = h.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
        // 换行
        h = h.replace(/\n/g, '<br>');

        return h || '<span style="color:#B6BFCB;">（空）</span>';
    }

    /* ── 8-10. 快捷键 / 自动列表续行 / Tab 缩进 ────────────── */
    function bindKeys(ta) {
        ta.addEventListener('keydown', function (e) {
            // Ctrl+B 粗体 / Ctrl+I 斜体
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                wrapSel(ta, '**', '**', '粗体');
            }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'i') {
                e.preventDefault();
                wrapSel(ta, '*', '*', '斜体');
            }
            // Enter 自动续列表
            if (e.key === 'Enter' && !e.shiftKey) {
                var s = ta.selectionStart;
                var lineStart = ta.value.lastIndexOf('\n', s - 1) + 1;
                var line = ta.value.slice(lineStart, s);
                var m = line.match(/^(\s*)([-*+]|\d+\.)\s+(.*)$/);
                if (m) {
                    // 空列表项 → 结束列表
                    if (m[3].trim() === '') {
                        e.preventDefault();
                        ta.value = ta.value.slice(0, lineStart) + ta.value.slice(s);
                        ta.selectionStart = ta.selectionEnd = lineStart;
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                        return;
                    }
                    e.preventDefault();
                    var mark = /^\d+$/.test(m[2].replace('.', ''))
                        ? (parseInt(m[2], 10) + 1) + '. '
                        : m[2] + ' ';
                    var ins = '\n' + m[1] + mark;
                    ta.value = ta.value.slice(0, s) + ins + ta.value.slice(s);
                    ta.selectionStart = ta.selectionEnd = s + ins.length;
                    ta.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
            // Tab 缩进 / Shift+Tab 反缩进
            if (e.key === 'Tab') {
                e.preventDefault();
                var st = ta.selectionStart, en = ta.selectionEnd;
                if (e.shiftKey) {
                    var ls = ta.value.lastIndexOf('\n', st - 1) + 1;
                    if (ta.value.slice(ls, ls + 2) === '  ') {
                        ta.value = ta.value.slice(0, ls) + ta.value.slice(ls + 2);
                        ta.selectionStart = st - 2; ta.selectionEnd = en - 2;
                    }
                } else {
                    ta.value = ta.value.slice(0, st) + '  ' + ta.value.slice(en);
                    ta.selectionStart = ta.selectionEnd = st + 2;
                }
                ta.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }

    /* ── 11. 图片拖入 / 粘贴上传提示 ──────────────────────── */
    function bindDrop(ta) {
        ['dragover', 'drop'].forEach(function (ev) {
            ta.addEventListener(ev, function (e) {
                e.preventDefault();
                if (ev === 'drop') {
                    var files = e.dataTransfer && e.dataTransfer.files;
                    if (files && files.length) {
                        var s = ta.selectionStart;
                        var tpl = '\n![拖入的图片需手动上传](/uploads/images/)\n';
                        ta.value = ta.value.slice(0, s) + tpl + ta.value.slice(s);
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            });
        });
    }

    /* ── 12. 字数与行数统计 ───────────────────────────────── */
    function buildCounter(ta) {
        var c = document.createElement('div');
        c.className = 'lw-md-counter';
        function upd() {
            var v = ta.value || '';
            var chars = v.length;
            var lines = v.split('\n').length;
            var words = v.replace(/\s+/g, '').length;
            c.textContent = words + ' 字 · ' + lines + ' 行';
        }
        ta.addEventListener('input', upd);
        upd();
        return c;
    }

    /* ── 13. 草稿自动保存 ─────────────────────────────────── */
    function bindDraft(ta) {
        var KEY = 'lw_post_draft_v1';
        try {
            var saved = localStorage.getItem(KEY);
            if (saved && !ta.value.trim()) {
                var d = JSON.parse(saved);
                if (d && d.text && Date.now() - d.at < 86400000) {
                    if (confirm('检测到未提交的草稿（' + new Date(d.at).toLocaleString() + '），要恢复吗？')) {
                        ta.value = d.text;
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                    } else {
                        localStorage.removeItem(KEY);
                    }
                }
            }
        } catch (e) {}

        var timer = null;
        ta.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                try {
                    if (ta.value.trim()) {
                        localStorage.setItem(KEY, JSON.stringify({ text: ta.value, at: Date.now() }));
                    } else {
                        localStorage.removeItem(KEY);
                    }
                } catch (e) {}
            }, 1200);
        });

        // 提交成功后清草稿
        var form = ta.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                try { localStorage.removeItem(KEY); } catch (e) {}
            });
        }
    }

    /* ── 启动 ─────────────────────────────────────────────── */
    function init() {
        var ta = findEditor();
        if (!ta || ta.dataset.lwMdReady) return;
        ta.dataset.lwMdReady = '1';

        // 工具栏插在 textarea 之前
        var bar = buildToolbar(ta);
        ta.parentNode.insertBefore(bar, ta);

        // 预览区插在 textarea 之后
        var pv = buildPreview(ta);
        ta.parentNode.insertBefore(pv.wrap, ta.nextSibling);

        // 预览切换按钮
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'lw-md-btn lw-md-toggle';
        toggle.textContent = '预览';
        toggle.title = '切换 Markdown 预览';
        toggle.addEventListener('click', function () {
            var showing = pv.wrap.style.display !== 'none';
            if (showing) {
                pv.wrap.style.display = 'none';
                toggle.textContent = '预览';
                ta.style.display = '';
            } else {
                pv.body.innerHTML = clientRender(ta.value);
                pv.wrap.style.display = 'block';
                toggle.textContent = '编辑';
                ta.style.display = 'none';
            }
        });
        bar.appendChild(toggle);

        // 计数器
        var counter = buildCounter(ta);
        pv.wrap.appendChild(counter);

        bindKeys(ta);
        bindDrop(ta);
        bindDraft(ta);

        // 输入时若在预览态，实时更新
        ta.addEventListener('input', function () {
            if (pv.wrap.style.display !== 'none') {
                pv.body.innerHTML = clientRender(ta.value);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    window.LWEditor = { init: init, render: clientRender };
})();
