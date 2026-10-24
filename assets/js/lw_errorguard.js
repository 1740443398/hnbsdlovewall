/**
 * 全局运行时报错捕获（需求四、2：捕获并处理控制台报错）
 * ---------------------------------------------------------------------------
 * 目标不是「屏蔽报错」，而是：
 *   ① 不让一条非致命异常打断后续脚本（尤其低端机上某个扩展报错会连带页面卡死）；
 *   ② 把报错摘要聚合成一条，避免刷屏（同一错误只记一次）；
 *   ③ 提供一个可查询的窗口对象，便于探针断言「本页没有未被处理的异常」。
 *
 * 明确不做的事：
 *   - 不上报服务端。校园内网 + 免费主机，上报只会增加 502 风险与隐私面。
 *   - 不吞掉真正的语法错误（resource 加载失败也不算 JS 异常，这里只记不拦）。
 */
(function () {
    'use strict';
    if (window.__lwErrorGuard) return;
    window.__lwErrorGuard = true;

    var errors = [];
    var seen = {};
    var MAX = 40;

    function record(kind, message, source, line, col) {
        var key = kind + '|' + String(message).slice(0, 180) + '|' + (source || '');
        if (seen[key]) {
            seen[key].count++;
            return;
        }
        var item = {
            kind: kind,
            message: String(message || '').slice(0, 300),
            source: String(source || '').slice(0, 200),
            line: line || 0,
            col: col || 0,
            count: 1,
            ts: Date.now()
        };
        seen[key] = item;
        if (errors.length < MAX) errors.push(item);
    }

    // 同步异常
    window.addEventListener('error', function (e) {
        // 资源加载失败（img/script）也走这里，但没有 message 字段
        if (e && e.message) {
            record('error', e.message, e.filename, e.lineno, e.colno);
        } else if (e && e.target && e.target.tagName) {
            // 资源 404：只记标签与 src，不当作 JS 异常
            var t = e.target;
            record('resource', t.tagName + ' 加载失败', t.src || t.href || '');
        }
    }, true);

    // Promise 未捕获拒绝
    window.addEventListener('unhandledrejection', function (e) {
        var r = e && e.reason;
        var msg = (r && (r.message || r.toString)) ? (r.message || String(r)) : 'unhandled rejection';
        record('rejection', msg, '', 0, 0);
    });

    // 对外只读接口：探针与调试用
    window.__lwErrors = {
        list: function () { return errors.slice(); },
        count: function () { return errors.length; },
        /** 是否只有「资源加载失败」这类非逻辑错误 */
        onlyResourceErrors: function () {
            return errors.every(function (e) { return e.kind === 'resource'; });
        },
        clear: function () { errors = []; seen = {}; }
    };
})();
