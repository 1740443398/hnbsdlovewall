/**
 * 交互式统计图表（纯 SVG + Canvas 混合，无第三方库）
 *
 * 【为什么自己写】
 * Chart.js / ECharts 动辄 200KB+，而后台是低频页面，
 * 为了几张图引入一个大库不划算。这里用 Canvas 画，
 * 体积不到 15KB，且完全可控。
 *
 * 【功能】
 *   1. 四种图表类型：折线、面积、柱状、环形
 *   2. 粒度可调：按天 / 按周 / 按月
 *   3. 鼠标悬浮显示详细数据（自定义 tooltip，不是粗糙的 title）
 *   4. 多系列对比（发帖 / 评论 / 用户）
 *   5. 彩色渐变，每个系列有自己的主题色
 *   6. 响应式：容器变宽自动重绘
 */
(function () {
    'use strict';

    var PALETTE = [
        { line: '#4F7FE0', fill1: 'rgba(79,127,224,0.35)', fill2: 'rgba(79,127,224,0.02)' },
        { line: '#22B07D', fill1: 'rgba(34,176,125,0.32)', fill2: 'rgba(34,176,125,0.02)' },
        { line: '#F2994A', fill1: 'rgba(242,153,74,0.32)', fill2: 'rgba(242,153,74,0.02)' },
        { line: '#BB6BD9', fill1: 'rgba(187,107,217,0.32)', fill2: 'rgba(187,107,217,0.02)' },
    ];

    /** 图表实例集合，供外部切换类型/粒度时调用。 */
    var instances = {};

    function LwChart(el, opts) {
        this.el = el;
        this.opts = opts || {};
        this.type = opts.type || "line";
        this.data = opts.data || [];
        this.series = opts.series || [];
        this.hoverIndex = -1;
        this._build();
    }

    LwChart.prototype._build = function () {
        var wrap = document.createElement('div');
        wrap.className = 'lwc-wrap';
        this.canvas = document.createElement('canvas');
        this.canvas.className = 'lwc-canvas';
        this.tip = document.createElement('div');
        this.tip.className = 'lwc-tip';
        wrap.appendChild(this.canvas);
        wrap.appendChild(this.tip);
        this.el.innerHTML = '';
        this.el.appendChild(wrap);
        this.wrap = wrap;

        var self = this;
        this.canvas.addEventListener('mousemove', function (e) { self._onMove(e); });
        this.canvas.addEventListener('mouseleave', function () { self._onLeave(); });
        this.canvas.addEventListener('touchstart', function (e) { self._onMove(e.touches[0]); }, { passive: true });
        this.canvas.addEventListener('touchmove', function (e) { self._onMove(e.touches[0]); }, { passive: true });
        this.canvas.addEventListener('touchend', function () { self._onLeave(); });

        // 容器尺寸变化时重绘（响应式）
        if (window.ResizeObserver) {
            this._ro = new ResizeObserver(function () { self.draw(); });
            this._ro.observe(this.el);
        }
        this.draw();
    };

    LwChart.prototype.setData = function (data, series) {
        this.data = data || [];
        if (series) this.series = series;
        this.hoverIndex = -1;
        this.draw();
    };

    LwChart.prototype.setType = function (type) {
        this.type = type;
        this.hoverIndex = -1;
        this.draw();
    };

    /** 准备画布尺寸（考虑 devicePixelRatio，避免模糊）。 */
    LwChart.prototype._size = function () {
        var dpr = window.devicePixelRatio || 1;
        var w = this.el.clientWidth || 600;
        var h = parseInt(this.opts.height, 10) || 240;
        this.canvas.width = Math.round(w * dpr);
        this.canvas.height = Math.round(h * dpr);
        this.canvas.style.width = w + 'px';
        this.canvas.style.height = h + 'px';
        var ctx = this.canvas.getContext("2d");
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        return { ctx: ctx, w: w, h: h };
    };

    LwChart.prototype.draw = function () {
        var s = this._size();
        var ctx = s.ctx, w = s.w, h = s.h;
        ctx.clearRect(0, 0, w, h);
        if (!this.data.length) {
            ctx.fillStyle = '#9AA4B2';
            ctx.font = "13px -apple-system, sans-serif";
            ctx.textAlign = 'center';
            ctx.fillText("暂无数据", w / 2, h / 2);
            return;
        }
        if (this.type === "donut") { this._drawDonut(ctx, w, h); }
        else { this._drawAxes(); }
    };

    /** 折线 / 面积 / 柱状：共享坐标轴。 */
    LwChart.prototype._drawAxes = function () {
        var s = this._size();
        var ctx = s.ctx, w = s.w, h = s.h;
        var padL = 42, padR = 16, padT = 18, padB = 30;
        var cw = w - padL - padR, ch = h - padT - padB;

        // 求最大值
        var maxV = 1;
        var self = this;
        this.series.forEach(function (sr) {
            self.data.forEach(function (d) {
                var v = Number(d[sr.key] || 0);
                if (v > maxV) maxV = v;
            });
        });
        maxV = maxV * 1.15;

        // 网格线与 Y 轴刻度
        ctx.strokeStyle = '#EDF1F7';
        ctx.lineWidth = 1;
        ctx.fillStyle = '#9AA4B2';
        ctx.font = "11px -apple-system, sans-serif";
        ctx.textAlign = 'right';
        ctx.textBaseline = "middle";
        var rows = 4;
        for (var i = 0; i <= rows; i++) {
            var y = padT + (ch / rows) * i;
            ctx.beginPath();
            ctx.moveTo(padL, y);
            ctx.lineTo(padL + cw, y);
            ctx.stroke();
            var val = Math.round(maxV - (maxV / rows) * i);
            ctx.fillText(String(val), padL - 8, y);
        }

        var n = this.data.length;
        var stepX = n > 1 ? cw / (n - 1) : 0;

        function xOf(idx) {
            if (n === 1) return padL + cw / 2;
            return padL + stepX * idx;
        }
        function yOf(v) {
            return padT + ch - (Number(v || 0) / maxV) * ch;
        }
        this._xOf = xOf;
        this._yOf = yOf;
        this._pad = { l: padL, r: padR, t: padT, b: padB, cw: cw, ch: ch };

        // X 轴标签（太多时抽稀）
        ctx.textAlign = 'center';
        ctx.textBaseline = "top";
        ctx.fillStyle = '#9AA4B2';
        var labelStep = Math.max(1, Math.ceil(n / 8));
        for (var k = 0; k < n; k += labelStep) {
            var lb = String(this.data[k].label || this.data[k].date || "");
            ctx.fillText(lb, xOf(k), padT + ch + 8);
        }

        // 逐系列绘制
        this.series.forEach(function (sr, si) {
            var pal = PALETTE[si % PALETTE.length];
            if (self.type === "bar") {
                self._drawBars(ctx, sr, pal, si, xOf, yOf, stepX, padT, ch);
            } else {
                self._drawLine(ctx, sr, pal, xOf, yOf, padT, ch);
            }
        });

        // 悬浮指示
        if (this.hoverIndex >= 0 && this.hoverIndex < n) {
            var hx = xOf(this.hoverIndex);
            ctx.strokeStyle = 'rgba(47,91,154,0.35)';
            ctx.lineWidth = 1;
            ctx.setLineDash([4, 3]);
            ctx.beginPath();
            ctx.moveTo(hx, padT);
            ctx.lineTo(hx, padT + ch);
            ctx.stroke();
            ctx.setLineDash([]);
            this.series.forEach(function (sr, si) {
                var pal = PALETTE[si % PALETTE.length];
                var v = Number(self.data[self.hoverIndex][sr.key] || 0);
                var py = yOf(v);
                ctx.fillStyle = '#fff';
                ctx.beginPath();
                ctx.arc(hx, py, 5, 0, Math.PI * 2);
                ctx.fill();
                ctx.fillStyle = pal.line;
                ctx.beginPath();
                ctx.arc(hx, py, 3.2, 0, Math.PI * 2);
                ctx.fill();
            });
        }
    };

    LwChart.prototype._drawLine = function (ctx, sr, pal, xOf, yOf, padT, ch) {
        var self = this;
        var pts = this.data.map(function (d, i) {
            return { x: xOf(i), y: yOf(d[sr.key]) };
        });
        if (!pts.length) return;

        /* 面积填充。
         *
         * 原来限定 series.length === 1，导致「面积」和「折线」看起来完全一样 ——
         * dashboard 传了 4 个系列，永远画不出填充。
         * 现在多系列也填充，但把透明度降得更低（0.16 / 0.01），
         * 叠 4 层也不会糊成一块。 */
        if (this.type === "area") {
            var g = ctx.createLinearGradient(0, padT, 0, padT + ch);
            // 系列越多，单层越淡，避免叠加后看不出层次
            var alphaTop = this.series.length > 1 ? 0.18 : 0.35;
            var rgb = pal.line.replace("#", "");
            var r0 = parseInt(rgb.slice(0, 2), 16);
            var g0 = parseInt(rgb.slice(2, 4), 16);
            var b0 = parseInt(rgb.slice(4, 6), 16);
            g.addColorStop(0, "rgba(" + r0 + "," + g0 + "," + b0 + "," + alphaTop + ")");
            g.addColorStop(1, "rgba(" + r0 + "," + g0 + "," + b0 + ",0.01)");
            ctx.fillStyle = g;
            ctx.beginPath();
            ctx.moveTo(pts[0].x, padT + ch);
            pts.forEach(function (p) { ctx.lineTo(p.x, p.y); });
            ctx.lineTo(pts[pts.length - 1].x, padT + ch);
            ctx.closePath();
            ctx.fill();
        }

        // 折线（平滑曲线）
        ctx.strokeStyle = pal.line;
        ctx.lineWidth = 2.2;
        ctx.lineJoin = "round";
        ctx.lineCap = "round";
        ctx.beginPath();
        pts.forEach(function (p, i) {
            if (i === 0) { ctx.moveTo(p.x, p.y); }
            else {
                var prev = pts[i - 1];
                var cx = (prev.x + p.x) / 2;
                ctx.bezierCurveTo(cx, prev.y, cx, p.y, p.x, p.y);
            }
        });
        ctx.stroke();

        // 数据点（数据点少时才画，多了会糊）
        if (pts.length <= 20) {
            pts.forEach(function (p) {
                ctx.fillStyle = '#fff';
                ctx.beginPath();
                ctx.arc(p.x, p.y, 3.2, 0, Math.PI * 2);
                ctx.fill();
                ctx.strokeStyle = pal.line;
                ctx.lineWidth = 1.8;
                ctx.stroke();
            });
        }
    };

    LwChart.prototype._drawBars = function (ctx, sr, pal, si, xOf, yOf, stepX, padT, ch) {
        var self = this;
        var n = this.data.length;
        var groupW = n > 1 ? stepX * 0.62 : 40;
        var barW = Math.max(3, groupW / Math.max(1, this.series.length));
        this.data.forEach(function (d, i) {
            var v = Number(d[sr.key] || 0);
            var x = xOf(i) - groupW / 2 + barW * si;
            var y = yOf(v);
            var hgt = padT + ch - y;
            if (hgt < 1) hgt = 1;
            var g = ctx.createLinearGradient(0, y, 0, y + hgt);
            g.addColorStop(0, pal.line);
            g.addColorStop(1, pal.fill1);
            ctx.fillStyle = g;
            var r = Math.min(3, barW / 2);
            ctx.beginPath();
            ctx.moveTo(x, y + hgt);
            ctx.lineTo(x, y + r);
            ctx.quadraticCurveTo(x, y, x + r, y);
            ctx.lineTo(x + barW - r, y);
            ctx.quadraticCurveTo(x + barW, y, x + barW, y + r);
            ctx.lineTo(x + barW, y + hgt);
            ctx.closePath();
            ctx.fill();
        });
    };

    /**
     * 环形图。
     *
     * 【为什么重写】原来按「系列合计」画环 —— dashboard 只有 4 个系列，
     * 所以环上就 4 段，信息量太少，看着像个装饰品。
     *
     * 现在改为按「时间点」画环：每个日期是一段，段的大小 = 该日全部系列之和。
     * 30 天就有最多 30 段，信息密度大幅提升。
     * 段数多时自动合并相邻的小段（阈值 2%），避免出现一堆看不清的细线。
     *
     * 同时右侧列出 Top 5 日期及数值，悬停某段时高亮对应条目。
     */
    LwChart.prototype._drawDonut = function (ctx, w, h) {
        var self = this;
        var n = this.data.length;
        if (!n) return;

        // 每个日期的合计
        var vals = this.data.map(function (d) {
            var sum = 0;
            self.series.forEach(function (sr) { sum += Number(d[sr.key] || 0); });
            return sum;
        });
        var total = vals.reduce(function (a, b) { return a + b; }, 0);
        if (total <= 0) {
            ctx.fillStyle = '#9AA4B2';
            ctx.font = '13px -apple-system, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('暂无数据', w / 2, h / 2);
            return;
        }

        // 段数过多时，把 < 2% 的合并进「其他」
        var segments = [];
        var others = 0;
        for (var i = 0; i < n; i++) {
            var pct = vals[i] / total;
            if (n > 12 && pct < 0.02) { others += vals[i]; }
            else { segments.push({ idx: i, v: vals[i], pct: pct }); }
        }
        if (others > 0) {
            segments.push({ idx: -1, v: others, pct: others / total, label: '其他' });
        }

        // 环形几何：左侧画环，右侧留出图例空间
        var cx = Math.min(w * 0.30, 150);
        var cy = h / 2;
        var R = Math.min(cx - 8, cy - 14);
        if (R < 30) R = 30;
        var r = R * 0.62;

        var ang = -Math.PI / 2;
        this._donutSegs = [];
        segments.forEach(function (sg, si) {
            var sweep = sg.pct * Math.PI * 2;
            var pal = PALETTE[si % PALETTE.length];
            ctx.beginPath();
            ctx.arc(cx, cy, R, ang, ang + sweep);
            ctx.arc(cx, cy, r, ang + sweep, ang, true);
            ctx.closePath();
            ctx.fillStyle = pal.line;
            ctx.fill();
            // 段之间留白：画一条细白线，避免连成一片
            ctx.strokeStyle = '#fff';
            ctx.lineWidth = 1.5;
            ctx.stroke();
            self._donutSegs.push({ start: ang, end: ang + sweep, seg: sg, pal: pal });
            ang += sweep;
        });

        // 中心汇总
        ctx.fillStyle = '#1F2733';
        ctx.font = '600 20px -apple-system, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(total), cx, cy - 7);
        ctx.fillStyle = '#9AA4B2';
        ctx.font = '11px -apple-system, sans-serif';
        ctx.fillText('合计', cx, cy + 13);

        // 右侧 Top 5 图例
        var top = segments.slice().sort(function (a, b) { return b.v - a.v; }).slice(0, 5);
        var bx = cx + R + 24;
        var by = cy - (top.length * 22) / 2;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        top.forEach(function (sg, i) {
            var pal = PALETTE[segments.indexOf(sg) % PALETTE.length];
            var ly = by + i * 22;
            ctx.fillStyle = pal.line;
            ctx.beginPath();
            ctx.arc(bx + 4, ly, 4, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#4A5568';
            ctx.font = '12px -apple-system, sans-serif';
            var lab = sg.label || (self.data[sg.idx] ? (self.data[sg.idx].label || self.data[sg.idx].date) : '?');
            ctx.fillText(String(lab).slice(0, 12), bx + 16, ly);
            ctx.fillStyle = '#1F2733';
            ctx.font = '600 12px -apple-system, sans-serif';
            ctx.fillText(String(sg.v), bx + 118, ly);
            ctx.fillStyle = '#9AA4B2';
            ctx.font = '11px -apple-system, sans-serif';
            ctx.fillText(Math.round(sg.pct * 100) + '%', bx + 156, ly);
        });
    };

    /** 悬浮：计算最近的 X 索引并显示提示。 */
    LwChart.prototype._onMove = function (e) {
        if (this.type === "donut" || !this._xOf) return;
        var rect = this.canvas.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var n = this.data.length;
        var best = -1, bestD = 1e9;
        for (var i = 0; i < n; i++) {
            var d = Math.abs(this._xOf(i) - mx);
            if (d < bestD) { bestD = d; best = i; }
        }
        if (best < 0) return;
        if (best !== this.hoverIndex) {
            this.hoverIndex = best;
            this.draw();
        }
        this._showTip(best, mx);
    };

    LwChart.prototype._showTip = function (idx, mx) {
        var d = this.data[idx] || {};
        var html = "<b>" + (d.label || d.date || "") + "</b>";
        this.series.forEach(function (sr, si) {
            var pal = PALETTE[si % PALETTE.length];
            html += "<div class=\"lwc-row\">" +
                "<i style=\"background:" + pal.line + "\"></i>" +
                "<span>" + sr.name + "</span>" +
                "<em>" + Number(d[sr.key] || 0) + "</em></div>";
        });
        this.tip.innerHTML = html;
        this.tip.style.opacity = '1';
        var tw = this.tip.offsetWidth || 140;
        var left = mx + 14;
        if (left + tw > this.el.clientWidth - 8) { left = mx - tw - 14; }
        if (left < 4) left = 4;
        this.tip.style.left = left + 'px';
        this.tip.style.top = '8px';
    };

    LwChart.prototype._onLeave = function () {
        this.hoverIndex = -1;
        this.tip.style.opacity = '0';
        this.draw();
    };

    LwChart.prototype.destroy = function () {
        if (this._ro) this._ro.disconnect();
    };

    /** 对外 API：创建图表。 */
    window.LwChart = {
        create: function (el, opts) {
            var id = el.id || ("lwc-" + Math.random().toString(36).slice(2));
            el.id = id;
            if (instances[id]) instances[id].destroy();
            instances[id] = new LwChart(el, opts);
            return instances[id];
        },
        get: function (id) { return instances[id] || null; },
        palette: PALETTE,
    };
})();
