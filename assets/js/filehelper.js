/**
 * 文件传输助手 · 前端逻辑
 *
 * =====================================================================
 * 三条传输通道的自动选择
 * =====================================================================
 *
 *   ≤20MB        → 服务器中转（POST 到 api/filehelper.php?action=upload）
 *                   最稳，任何网络环境都能通
 *
 *   20MB ~ 1GB   → WebRTC DataChannel 直连（文件不经过服务器）
 *                   需要先交换 SDP/ICE，通过 action=signal 轮询中转
 *
 *   1GB ~ 3GB    → 云通道（当前降级为提示，见 pickChannel()）
 *
 * 为什么要自动选：用户不知道也不关心这些限制，他只想知道「传不传得动」。
 * =====================================================================
 */
(function () {
    'use strict';

    var CFG = window.FH || {};
    var $ = function (id) { return document.getElementById(id); };

    // ---------- 状态 ----------
    var state = {
        code: CFG.code || '',
        role: detectRole(),
        joined: false,
        pollTimer: null,
        seenIds: {},
        pc: null,
        channel: null,
        pendingFiles: {}
    };

    /* 角色只区分「发起方 a」和「加入方 b」，与设备类型无关。
     *
     * 旧版靠 UA 判断 pc / mobile，在平板、桌面浏览器、部分安卓机上会误判，
     * 而需求本身也不限制设备 —— 手机↔手机、电脑↔电脑都要能用。
     * 所以这里只看「这次是发起还是加入」。
     */
    function detectRole() {
        return 'a';
    }

    // =====================================================================
    // 工具
    // =====================================================================
    function api(action, opts) {
        opts = opts || {};
        var params = new URLSearchParams();
        params.set('action', action);
        params.set('code', opts.code !== undefined ? opts.code : state.code);
        params.set('role', opts.role || state.role);
        if (opts.extra) {
            Object.keys(opts.extra).forEach(function (k) { params.set(k, opts.extra[k]); });
        }

        var url = '/api/filehelper.php?' + params.toString();
        var init = { credentials: 'same-origin' };

        if (opts.form) {
            init.method = 'POST';
            init.body = opts.form;
        } else if (opts.method === 'POST') {
            init.method = 'POST';
            init.headers = { 'Content-Type': 'application/json' };
            init.body = JSON.stringify(opts.body || {});
        }

        return fetch(url, init).then(function (r) {
            if (r.status === 404 || r.status === 410 || r.status === 409) {
                return r.json().then(function (j) {
                    var e = new Error(j.message || '请求失败');
                    e.status = r.status;
                    throw e;
                });
            }
            return r.json();
        });
    }

    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }

    function fmtTime(ts) {
        var d = new Date(ts * 1000);
        return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    }

    function extOf(name) {
        var m = /\.([a-z0-9]+)$/i.exec(name || '');
        return m ? m[1].toUpperCase().slice(0, 4) : 'FILE';
    }

    /** 按扩展名给个色块，让列表一眼能区分文件类型。 */
    function colorOf(name) {
        var e = (extOf(name) || '').toLowerCase();
        if (/^(jpg|jpeg|png|gif|webp|bmp|svg|heic)$/.test(e)) return '#4F7FE0';
        if (/^(mp4|mov|avi|mkv|webm|flv|wmv|m4v)$/.test(e)) return '#E5484D';
        if (/^(mp3|wav|flac|aac|m4a|ogg)$/.test(e)) return '#BB6BD9';
        if (/^(pdf)$/.test(e)) return '#F2994A';
        if (/^(zip|rar|7z|tar|gz)$/.test(e)) return '#22B07D';
        if (/^(doc|docx|xls|xlsx|ppt|pptx)$/.test(e)) return '#2F5B9A';
        return '#6B7684';
    }

    function toast(msg) {
        // 极简提示，不引入组件
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;left:50%;bottom:80px;transform:translateX(-50%);' +
            'background:rgba(31,39,51,.92);color:#fff;padding:10px 18px;border-radius:10px;' +
            'font-size:13px;z-index:9999;pointer-events:none;transition:opacity .25s;max-width:80vw;';
        document.body.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; }, 1800);
        setTimeout(function () { t.remove(); }, 2200);
    }

    // =====================================================================
    // 配对：创建 / 加入
    // =====================================================================
    function createRoom() {
        var btn = $('fhCreateBtn');
        btn.disabled = true;
        btn.textContent = '生成中…';

        api('create', { role: 'pc' }).then(function (res) {
            if (!res.success) throw new Error(res.message);
            var d = res.data;
            state.code = d.code;
            state.role = 'a';
            state.joined = true;

            $('fhCodeText').textContent = d.code;
            $('fhCodeBox').hidden = false;
            btn.hidden = true;

            // 生成二维码（用本地库，不走外网）
            var qrBox = $('fhQrBox');
            qrBox.innerHTML = '';
            try {
                var qr = qrcode(0, 'M');
                qr.addData(d.join_url);
                qr.make();
                qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0 });
            } catch (e) {
                qrBox.textContent = d.join_url;
            }

            /* 【不要立刻 enterRoom】
             *
             * 电脑端生成配对码后应该停在配对界面，让用户看到二维码去扫。
             * 原来这里直接调 enterRoom()，而它第一行就把 #fhPairPanel 隐藏了，
             * 于是「二维码闪一下就消失」，用户根本没法扫。
             *
             * 正确做法：只启动轮询，等检测到手机加入再切进房间。
             */
            state.code = d.code;
            state.role = 'a';
            startWaitingForPeer(d.code);
        }).catch(function (e) {
            btn.disabled = false;
            btn.textContent = '生成配对码';
            toast(e.message || '生成失败');
        });
    }

    function joinRoom(code) {
        code = (code || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (code.length !== 6) { toast('配对码是 6 位'); return; }

        var btn = $('fhJoinBtn');
        btn.disabled = true;
        btn.textContent = '连接中…';

        /* 【关键】走「我是手机」这条路的，角色必须钉死为 mobile。
         *
         * 原来用 detectRole() 靠 UA 判断，有两个问题：
         *   1. 平板、桌面浏览器缩放、部分安卓机 UA 里没有 Mobile 字样，
         *      会被误判成 pc，于是手机自己占掉了「电脑」这个位置；
         *   2. 电脑端已经占了 pc 位，手机再用 pc 加入就会 409 房间已满。
         *
         * 用户点的是「我是手机 → 连接」，意图明确，直接按 mobile 处理。
         */
        state.role = 'b';

        api('join', { code: code, role: 'b' }).then(function (res) {
            if (!res.success) throw new Error(res.message);
            state.code = code;
            state.joined = true;
            enterRoom(code);
        }).catch(function (e) {
            btn.disabled = false;
            btn.textContent = '连接';
            toast(e.message || '连接失败');
        });
    }

    function enterRoom(code) {
        $('fhPairPanel').hidden = true;
        $('fhRoomPanel').hidden = false;
        $('fhRoomCode').textContent = code;
        // 只设一次 innerHTML（里面是圆点 + 文字），不要先 textContent 再 innerHTML
        $('fhPeerSelf').innerHTML = '<i></i>' + (state.role === 'a' ? '本机（发起方）' : '本机（加入方）');
        $('fhPeerSelf').classList.add('is-online');

        startPoll();
        // 更新地址栏，方便刷新后保持房间
        try {
            history.replaceState(null, '', '/filehelper?code=' + code);
        } catch (e) { }
    }

    // =====================================================================
    // 电脑端等待手机加入
    // =====================================================================
    /**
     * 停在配对界面，每隔 1.5 秒问一次「有人进来了吗」。
     * 有人进来才切到房间界面，这样二维码能一直显示直到被扫。
     */
    function startWaitingForPeer(code) {
        if (state.waitTimer) clearInterval(state.waitTimer);

        state.role = 'a';
        state.joined = false;   // 还没真正进房间

        var check = function () {
            api('poll', { code: code, role: 'a' }).then(function (res) {
                if (!res.success) return;
                var peers = res.data.peers || [];
                var hasOther = peers.some(function (p) { return p.role !== 'a'; });
                if (hasOther) {
                    clearInterval(state.waitTimer);
                    state.waitTimer = null;
                    state.joined = true;
                    enterRoom(code);
                }
            }).catch(function () { });
        };

        check();
        state.waitTimer = setInterval(check, 1500);
    }

    // =====================================================================
    // 轮询
    // =====================================================================
    function startPoll() {
        if (state.pollTimer) return;
        var tick = function () {
            api('poll').then(function (res) {
                if (!res.success) {
                    if (res.status === 404 || !res.success) {
                        // 房间没了
                        refreshHint('房间已过期，请重新生成配对码');
                        stopPoll();
                    }
                    return;
                }
                renderPeers(res.data.peers || []);
                renderItems(res.data.items || []);
            }).catch(function (e) {
                if (e.status === 404) {
                    refreshHint('房间已过期');
                    stopPoll();
                }
            });
        };
        tick();
        // 1.2 秒一次：比 1 秒省一半请求，比 2 秒响应更及时
        state.pollTimer = setInterval(tick, 1200);
    }

    function stopPoll() {
        if (state.pollTimer) {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }

    function refreshHint(msg) {
        toast(msg);
        $('fhPeerOther').innerHTML = '<i></i>' + msg;
        $('fhPeerOther').classList.remove('is-online');
    }

    function renderPeers(peers) {
        var el = $('fhPeerOther');
        if (!peers.length) {
            el.innerHTML = '<i></i>等待对方…';
            el.classList.remove('is-online');
            return;
        }
        var p = peers[0];
        if (p.online) {
            el.innerHTML = '<i></i>对方在线';
            el.classList.add('is-online');
        } else {
            el.innerHTML = '<i></i>对方已离线';
            el.classList.remove('is-online');
        }
    }

    // =====================================================================
    // 渲染传输列表
    // =====================================================================
    function renderItems(items) {
        var list = $('fhList');
        var changed = false;

        items.forEach(function (it) {
            if (state.seenIds[it.id]) return;
            state.seenIds[it.id] = true;
            changed = true;

            if (it.kind === 'text') {
                list.appendChild(buildTextItem(it));
            } else {
                list.appendChild(buildFileItem(it));
            }
        });

        $('fhEmpty').hidden = list.children.length > 0;

        // 新来的自动滚到底
        if (changed) {
            window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
        }
    }

    function buildTextItem(it) {
        var div = document.createElement('div');
        div.className = 'fh-item-text';
        div.textContent = it.text || '';
        var meta = document.createElement('div');
        meta.className = 'fh-text-meta';
        meta.textContent = (it.from === state.role ? '我' : '对方') + ' · ' + fmtTime(it.at);
        div.appendChild(meta);
        return div;
    }

    function buildFileItem(it) {
        var div = document.createElement('div');
        div.className = 'fh-item';

        var icon = document.createElement('div');
        icon.className = 'fh-item-icon';
        icon.style.background = colorOf(it.name);
        icon.textContent = extOf(it.name);
        div.appendChild(icon);

        var body = document.createElement('div');
        body.className = 'fh-item-body';

        var name = document.createElement('div');
        name.className = 'fh-item-name';
        name.textContent = it.name || '文件';
        name.title = it.name || '';
        body.appendChild(name);

        var meta = document.createElement('div');
        meta.className = 'fh-item-meta';
        var channelLabel = it.channel === 'relay' ? '服务器中转' : (it.channel === 'p2p' ? '点对点' : '云通道');
        meta.textContent = fmtSize(it.size || 0) + ' · ' + channelLabel + ' · ' +
            fmtTime(it.at) + (it.from === state.role ? ' · 我发的' : '');
        body.appendChild(meta);
        div.appendChild(body);

        var actions = document.createElement('div');
        actions.className = 'fh-item-actions';

        // 自己发的不用下载按钮（本机已有）；但中转文件可以给自己留个副本
        if (it.from !== state.role || it.channel === 'relay') {
            var btn = document.createElement('button');
            btn.className = 'fh-btn fh-btn-ghost fh-btn-sm';
            btn.textContent = '下载';

            /* 三种通道下载方式不同：
             *   relay → 问本站要令牌，再从本站拉（文件在我们服务器上，1 分钟删）
             *   cloud → 问本站要网盘直链，直接跳到网盘 CDN
             *   p2p   → 文件早已推送到本地，不需要下载
             */
            var action = it.channel === 'cloud' ? 'cloud_url' : 'pick';

            btn.addEventListener('click', function () {
                if (it.channel === 'p2p') {
                    toast('这个文件走点对点通道，已由对方直接推送');
                    return;
                }

                btn.disabled = true;
                btn.textContent = '准备中';

                api(action, { extra: { id: it.id } }).then(function (res) {
                    if (!res.success) throw new Error(res.message);

                    var a = document.createElement('a');
                    a.href = res.data.url;
                    a.download = res.data.name || it.name || 'file';
                    a.rel = 'noopener';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();

                    btn.textContent = '已开始';
                    setTimeout(function () {
                        btn.disabled = false;
                        btn.textContent = '下载';
                    }, 2500);
                }).catch(function (e) {
                    toast(e.message || '下载失败');
                    btn.disabled = false;
                    btn.textContent = '下载';
                });
            });
            actions.appendChild(btn);
        }
        div.appendChild(actions);

        // 中转文件显示倒计时
        if (it.channel === 'relay' && it.expire_at) {
            var prog = document.createElement('div');
            prog.className = 'fh-progress';
            var bar = document.createElement('i');
            prog.appendChild(bar);
            body.appendChild(prog);

            var update = function () {
                var left = Math.max(0, it.expire_at - Math.floor(Date.now() / 1000));
                var pct = Math.min(100, (left / 60) * 100);
                bar.style.width = pct + '%';
                if (left <= 0) {
                    meta.textContent = fmtSize(it.size || 0) + ' · 已过期';
                    prog.remove();
                    clearInterval(timer);
                } else {
                    meta.textContent = fmtSize(it.size || 0) + ' · 服务器中转 · ' +
                        left + ' 秒后自动删除';
                }
            };
            var timer = setInterval(update, 1000);
            update();
        }

        return div;
    }

    // =====================================================================
    // 发送文件
    // =====================================================================
    function sendFiles(files) {
        Array.prototype.forEach.call(files, function (f) {
            if (f.size > (CFG.maxFile || 3221225472)) {
                toast(f.name + ' 超过 3GB 上限');
                return;
            }

            /* 通道选择
             *
             *   ≤20MB        服务器中转（最稳）
             *   20MB~1GB     P2P 直连（文件不上服务器）
             *   1GB~3GB      网盘通道（走第三方）
             *
             * 为什么大文件要 P2P 和网盘两条路：
             *   P2P 不占服务器空间，但校园网 NAT 严格时连不上；
             *   网盘能兜底，但要多绕一跳。
             * 所以 20MB~1GB 优先试 P2P，1GB 以上直接走网盘。
             */
            /* 通道选择（与设备类型无关，只看文件大小）
             *
             *   ≤20MB        服务器中转（全能通，最快）
             *   20MB~1GB     P2P 直连（不占服务器空间）
             *   1GB~3GB      网盘通道（P2P 连不上时兜底）
             */
            if (f.size <= (CFG.relayMax || 20971520)) {
                sendViaRelay(f);
            } else if (f.size <= (CFG.p2pMax || 1073741824)) {
                sendViaP2P(f);
            } else {
                sendViaCloud(f);
            }
        });
    }

    /** 通道①：服务器中转 */
    function sendViaRelay(file) {
        var fd = new FormData();
        fd.append('file', file);

        // 先插一条占位，让用户立刻看到进度
        var placeholder = addPlaceholder(file.name, file.size, '服务器中转');

        api('upload', { form: fd }).then(function (res) {
            if (!res.success) throw new Error(res.message);
            placeholder.remove();
            // 自己发起的上传，主动写进 seenIds 避免轮询重复渲染
            state.seenIds[res.data.item.id] = true;
            $('fhList').appendChild(buildFileItem(res.data.item));
            $('fhEmpty').hidden = true;
        }).catch(function (e) {
            placeholder.remove();
            toast('上传失败：' + (e.message || '请重试'));
        });
    }

    /* 通道③：网盘中转（1GB~3GB）
     *
     * 【为什么必须是浏览器直传】
     * 服务器是 InfinityFree，upload_max_filesize 只有 20MB。
     * 超过 20MB 的 POST 会被 Apache 直接拦掉（403），
     * PHP 代码根本执行不到 —— 传多大都失败。
     *
     * 所以流程拆成两半：
     *   1. 我们服务器只发一张「七牛上传凭证」（几百字节）
     *   2. 浏览器拿着凭证把文件直接 POST 到七牛
     *   3. 浏览器回报七牛给的 token，我们查落库
     *
     * 好处不只是绕过限制：文件完全不经过我们的服务器，
     * 省流量也省磁盘，而且能拿到真实的上传进度。
     */
    function sendViaCloud(file) {
        if (!window.XMLHttpRequest) {
            toast('浏览器太旧，不支持大文件上传');
            return;
        }

        var placeholder = addPlaceholder(file.name, file.size, '网盘通道 · 准备中');
        var bar = placeholder.querySelector('.fh-progress i');

        var setProgress = function (pct, text) {
            if (bar) {
                bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
            }
            var meta = placeholder.querySelector('.fh-item-meta');
            if (meta) {
                meta.textContent = fmtSize(file.size) + ' · ' + text;
            }
        };

        var fail = function (msg) {
            placeholder.remove();
            toast(msg || '网盘上传失败');
        };

        // ---- 第一步：要凭证 ----
        /* md5 用于秒传判定，但大文件算 md5 很慢
         * （1GB 浏览器要跑几十秒，用户会以为卡死）。
         * 所以这里不传，服务端会自己补一个随机占位值 ——
         * 牺牲秒传，换取立刻开始传，对大文件是划算的。
         */
        api('big_prepare', {
            extra: { name: file.name, size: file.size, md5: '' }
        }).then(function (res) {
            if (!res.success) throw new Error(res.message);

            // 秒传命中：网盘已有同内容文件，一个字节都不用传
            if (res.data.fast) {
                placeholder.remove();
                state.seenIds[res.data.item.id] = true;
                $('fhList').appendChild(buildFileItem(res.data.item));
                $('fhEmpty').hidden = true;
                toast('网盘已有这个文件，秒传完成');
                return;
            }

            setProgress(0, '上传中 0%');
            uploadToQiniu(res.data, file, placeholder, setProgress, fail);
        }).catch(function (e) {
            fail(e.message);
        });
    }

    /** 拿凭证直接把文件 POST 到七牛，用 XHR 拿真实进度。 */
    function uploadToQiniu(prep, file, placeholder, setProgress, fail) {
        var fd = new FormData();
        fd.append('token', prep.token);
        fd.append('key', prep.key);
        fd.append('file', file, file.name);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', prep.uploadUrl, true);

        // 上传进度
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) {
                var pct = (e.loaded / e.total) * 100;
                setProgress(pct, '上传中 ' + pct.toFixed(0) + '%');
            }
        };

        xhr.onload = function () {
            var body = null;
            try { body = JSON.parse(xhr.responseText); } catch (e) { }

            if (!body || body.code !== 200) {
                var msg = (body && body.msg) ? body.msg : ('上传失败 HTTP ' + xhr.status);
                fail(msg);
                return;
            }

            var cbToken = body.token || '';
            if (!cbToken) {
                // 没回调 token 说明七牛没触发回调，也登记不了
                fail('七牛没有返回回调凭证');
                return;
            }

            setProgress(100, '入库中…');

            // ---- 第三步：回报落库 ----
            api('big_finish', {
                extra: { token: cbToken, name: file.name, size: file.size, key: prep.key }
            }).then(function (res2) {
                if (!res2.success) throw new Error(res2.message);
                placeholder.remove();
                state.seenIds[res2.data.item.id] = true;
                $('fhList').appendChild(buildFileItem(res2.data.item));
                $('fhEmpty').hidden = true;
                toast('已上传到网盘');
            }).catch(function (e) {
                fail(e.message);
            });
        };

        xhr.onerror = function () {
            fail('网络中断，上传失败');
        };

        xhr.ontimeout = function () {
            fail('上传超时');
        };

        xhr.send(fd);
    }

    /** 通道②：WebRTC 点对点 */
    function sendViaP2P(file) {
        if (!state.pc) {
            toast('点对点通道未就绪，稍等几秒再试');
            return;
        }
        if (state.channel && state.channel.readyState === 'open') {
            p2pSendFile(file);
        } else {
            toast('正在建立点对点连接…');
            state.pendingFiles[file.name] = file;
        }
    }

    function addPlaceholder(name, size, channel) {
        var div = document.createElement('div');
        div.className = 'fh-item';
        div.innerHTML =
            '<div class="fh-item-icon" style="background:' + colorOf(name) + '">' + extOf(name) + '</div>' +
            '<div class="fh-item-body">' +
            '<div class="fh-item-name"></div>' +
            '<div class="fh-item-meta">' + fmtSize(size) + ' · ' + channel + ' · 上传中…</div>' +
            '<div class="fh-progress"><i style="width:30%"></i></div>' +
            '</div>';
        div.querySelector('.fh-item-name').textContent = name;
        $('fhList').appendChild(div);
        $('fhEmpty').hidden = true;
        return div;
    }

    // =====================================================================
    // WebRTC 建立（P2P 通道）
    // =====================================================================
    function initP2P() {
        if (state.pc) return;
        var pc = new RTCPeerConnection({
            iceServers: [{ urls: 'stun:stun.l.google.com:19302' }]
        });
        state.pc = pc;

        pc.onicecandidate = function (e) {
            if (e.candidate) {
                api('signal', {
                    method: 'POST',
                    body: { type: 'ice', payload: e.candidate }
                }).catch(function () { });
            }
        };

        if (state.role === 'pc') {
            // 电脑端发起
            var dc = pc.createDataChannel('file', { ordered: true });
            setupChannel(dc);

            pc.createOffer().then(function (offer) {
                return pc.setLocalDescription(offer);
            }).then(function () {
                return api('signal', {
                    method: 'POST',
                    body: { type: 'offer', payload: pc.localDescription }
                });
            }).catch(function () { });
        } else {
            // 手机端接收
            pc.ondatachannel = function (e) { setupChannel(e.channel); };
        }

        // 轮询信令
        var polling = setInterval(function () {
            if (!state.joined) { clearInterval(polling); return; }
            api('signal', { method: 'GET' }).then(function (res) {
                if (!res.success) return;
                (res.data.messages || []).forEach(function (m) {
                    handleSignal(m);
                });
            }).catch(function () { });
        }, 1500);

        state.signalTimer = polling;
    }

    function handleSignal(msg) {
        var pc = state.pc;
        if (!pc) return;

        if (msg.type === 'offer' && state.role === 'mobile') {
            pc.setRemoteDescription(new RTCSessionDescription(msg.payload)).then(function () {
                return pc.createAnswer();
            }).then(function (answer) {
                return pc.setLocalDescription(answer);
            }).then(function () {
                return api('signal', {
                    method: 'POST',
                    body: { type: 'answer', payload: pc.localDescription }
                });
            }).catch(function () { });
        } else if (msg.type === 'answer' && state.role === 'pc') {
            if (pc.signalingState !== 'stable') {
                pc.setRemoteDescription(new RTCSessionDescription(msg.payload)).catch(function () { });
            }
        } else if (msg.type === 'ice') {
            try {
                pc.addIceCandidate(new RTCIceCandidate(msg.payload)).catch(function () { });
            } catch (e) { }
        }
    }

    function setupChannel(dc) {
        state.channel = dc;
        dc.binaryType = 'arraybuffer';

        dc.onopen = function () {
            toast('点对点通道已建立');
            // 有排队的文件就发出去
            Object.keys(state.pendingFiles).forEach(function (name) {
                p2pSendFile(state.pendingFiles[name]);
                delete state.pendingFiles[name];
            });
        };

        dc.onclose = function () {
            state.channel = null;
        };

        dc.onmessage = function (e) {
            try {
                var msg = JSON.parse(e.data);
                if (msg.type === 'meta') {
                    // 收到文件元信息，准备接收
                    state.recvBuf = { name: msg.name, size: msg.size, chunks: [], received: 0 };
                } else if (msg.type === 'done') {
                    // 接收完成，拼成 Blob 下载
                    var buf = state.recvBuf;
                    if (buf) {
                        var blob = new Blob(buf.chunks);
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url;
                        a.download = buf.name;
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
                        toast('已收到 ' + buf.name);
                        state.recvBuf = null;
                    }
                } else if (msg.type === 'chunk') {
                    // 分片消息用 arraybuffer，见下面 send
                }
            } catch (err) {
                // 二进制分片
                if (state.recvBuf) {
                    state.recvBuf.chunks.push(e.data);
                }
            }
        };
    }

    /** 分片发送，避免一次塞入超大 ArrayBuffer 导致内存爆掉。 */
    function p2pSendFile(file) {
        var dc = state.channel;
        if (!dc || dc.readyState !== 'open') {
            toast('点对点通道还没连上');
            return;
        }

        var CHUNK = 256 * 1024; // 256KB，兼顾速度和可靠性
        var sent = 0;
        var placeholder = addPlaceholder(file.name, file.size, '点对点');

        dc.send(JSON.stringify({ type: 'meta', name: file.name, size: file.size }));

        var reader = new FileReader();
        var offset = 0;

        var readNext = function () {
            if (offset >= file.size) {
                dc.send(JSON.stringify({ type: 'done' }));
                placeholder.remove();
                return;
            }
            // 背压：缓冲超过 8MB 就等
            if (dc.bufferedAmount > 8 * 1024 * 1024) {
                setTimeout(readNext, 80);
                return;
            }
            var slice = file.slice(offset, offset + CHUNK);
            reader.onload = function (ev) {
                dc.send(ev.target.result);
                offset += ev.target.result.byteLength;
                sent = offset;
                var pct = Math.min(99, (offset / file.size) * 100);
                placeholder.querySelector('.fh-progress > i').style.width = pct + '%';
                placeholder.querySelector('.fh-item-meta').textContent =
                    fmtSize(file.size) + ' · 点对点 · ' + Math.floor(pct) + '%';
                readNext();
            };
            reader.readAsArrayBuffer(slice);
        };
        readNext();
    }

    // =====================================================================
    // 事件绑定
    // =====================================================================
    function bind() {
        if ($('fhCreateBtn')) $('fhCreateBtn').addEventListener('click', createRoom);
        if ($('fhJoinBtn')) $('fhJoinBtn').addEventListener('click', function () {
            joinRoom($('fhJoinInput').value);
        });
        if ($('fhJoinInput')) $('fhJoinInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') joinRoom(this.value);
        });

        if ($('fhLeaveBtn')) $('fhLeaveBtn').addEventListener('click', function () {
            api('leave').catch(function () { });
            stopPoll();
            if (state.signalTimer) clearInterval(state.signalTimer);
            location.href = '/filehelper';
        });

        // 上传区
        var drop = $('fhDrop');
        var input = $('fhFileInput');
        if (drop && input) {
            drop.addEventListener('click', function () { input.click(); });
            input.addEventListener('change', function () {
                if (this.files.length) sendFiles(this.files);
                this.value = '';
            });
            ['dragenter', 'dragover'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) {
                    e.preventDefault();
                    drop.classList.add('is-over');
                });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                drop.addEventListener(ev, function (e) {
                    e.preventDefault();
                    drop.classList.remove('is-over');
                });
            });
            drop.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files.length) {
                    sendFiles(e.dataTransfer.files);
                }
            });
        }

        // 发文字
        var sendText = function () {
            var t = $('fhTextInput');
            var v = t.value.trim();
            if (!v) return;
            t.value = '';
            t.style.height = 'auto';
            api('text', { method: 'POST', body: { text: v } }).then(function (res) {
                if (!res.success) throw new Error(res.message);
                state.seenIds[res.data.item.id] = true;
                $('fhList').appendChild(buildTextItem(res.data.item));
                $('fhEmpty').hidden = true;
            }).catch(function (e) {
                toast(e.message || '发送失败');
            });
        };
        if ($('fhSendTextBtn')) $('fhSendTextBtn').addEventListener('click', sendText);
        if ($('fhTextInput')) {
            $('fhTextInput').addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendText();
                }
            });
            $('fhTextInput').addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = Math.min(120, this.scrollHeight) + 'px';
            });
        }

        // 说明弹窗
        if ($('fhHelpBtn')) {
            $('fhHelpBtn').addEventListener('click', function () {
                $('fhHelpModal').hidden = false;
            });
        }
        document.querySelectorAll('[data-close]').forEach(function (el) {
            el.addEventListener('click', function () {
                $('fhHelpModal').hidden = true;
            });
        });

        // Esc 关弹窗
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') $('fhHelpModal').hidden = true;
        });
    }

    // =====================================================================
    // 启动
    // =====================================================================
    function boot() {
        bind();

        // 带 code 进来 = 手机扫码，强制以手机身份加入
        if (state.code && state.code.length === 6) {
            state.role = 'b';
            joinRoom(state.code);
        }

        // 页面隐藏时停轮询，回到前台再开（省电也省请求）
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopPoll();
            } else if (state.joined) {
                startPoll();
            }
        });

        // 离开时通知服务器
        window.addEventListener('beforeunload', function () {
            if (!state.joined) return;
            var url = '/api/filehelper.php?action=leave&code=' + state.code + '&role=' + state.role;
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // 暴露给外部调试
    window.FHApp = { state: state, sendFiles: sendFiles };
})();
