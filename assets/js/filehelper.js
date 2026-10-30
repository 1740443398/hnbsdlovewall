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

        /* 进房间才建 WebRTC —— 之前忘了调这个函数，
         * 导致 state.pc 永远是 null，一直提示「通道未就绪」。
         */
        state.p2pFailed = false;
        state.p2pRebuilding = false;
        initP2P();
        startP2PWatchdog();

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

        /* 记住「这个码是本机生成的」。
         * enterRoom 会用 history.replaceState 把地址改成 ?code=xxx，
         * 刷新后 boot() 靠这一条判断自己还是发起方而不是加入方。
         */
        try { sessionStorage.setItem('fh_my_room', code); } catch (e) { }

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

    /** 通道②：WebRTC 点对点（连不上自动降级到网盘） */
    function sendViaP2P(file) {
        // 已明确失败过，直接走网盘，别再让用户等
        if (state.p2pFailed) {
            sendViaCloud(file);
            return;
        }

        if (state.channel && state.channel.readyState === 'open') {
            p2pSendFile(file);
            return;
        }

        if (!state.pc) {
            // 通道还没建（不该发生，但兜一下）
            initP2P();
        }

        var placeholder = addPlaceholder(file.name, file.size, '点对点 · 建立连接');
        state.pendingFiles[file.name] = file;
        state.pendingEls = state.pendingEls || {};
        state.pendingEls[file.name] = placeholder;

        /* 最多等 12 秒。
         * 局域网直连通常 1~2 秒就通；要打洞的话 3~8 秒；
         * 超过 12 秒基本可以判定这条路走不通，直接改走网盘，
         * 比让用户干等一两分钟体验好得多。
         */
        var name = file.name;
        setTimeout(function () {
            if (state.channel && state.channel.readyState === 'open') return;
            if (!state.pendingFiles[name]) return;   // 已经发走了

            delete state.pendingFiles[name];
            if (state.pendingEls[name]) {
                state.pendingEls[name].remove();
                delete state.pendingEls[name];
            }
            toast('点对点连不上，改走网盘通道');
            sendViaCloud(file);
        }, (typeof window.LW_FH_P2P_TIMEOUT === 'number' && window.LW_FH_P2P_TIMEOUT > 0)
            ? window.LW_FH_P2P_TIMEOUT
            : 12000);
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
    /* =====================================================================
     * WebRTC 点对点通道
     * =====================================================================
     *
     * 【为什么之前一直「未就绪」】
     *   1. initP2P() 从来没被调用 —— state.pc 永远是 null
     *   2. 角色判断还写着 'pc' / 'mobile'，但角色早就改成 'a' / 'b' 了，
     *      所以即使调用了，发起方和接收方也永远匹配不上
     *   3. 只配了 STUN，校园网 NAT 严格时打洞失败，没有 TURN 兜底
     *
     * 【局域网优先】
     *   同一 WiFi 下两台设备能直接通过 mDNS 主机名 + 内网 IP 连上，
     *   走的是本地链路，速度是路由器带宽（几百 Mbps），完全不经过外网。
     *
     *   为了确保这种情况被优先选中，我们把 iceCandidatePoolSize 设大，
     *   让浏览器提前收集候选；并且在选路时统计 host 类型候选 ——
     *   有 host 候选就说明能直连，不需要中继。
     */
    function initP2P() {
        /* 【守卫必须只挡「建 PC」，不能连轮询一起挡掉】
         *
         * 原来这里一句 if (state.pc) return; 直接早退。
         * 发起方的流程是：
         *   1. 生成配对码 → startWaitingForPeer（此时还没进房间）
         *   2. 检测到对方进来 → enterRoom → initP2P（第二次）
         * 如果第一次已经把 pc 建好了，第二次就会早退，
         * 于是信令轮询永远没启动 —— offer 发出去没人收，
         * answer 回来也没人读，连接卡死在 checking。
         *
         * 现在把两件事拆开：pc 已存在就跳过建连，但轮询照样保证在跑。
         */
        if (state.pc) {
            ensureSignalPolling();
            return;
        }

        var pc = new RTCPeerConnection({
            iceServers: [
                // 局域网 + 公网直连靠 STUN
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
                // 校园网/运营商 NAT 严格时兜底（开源 TURN，带宽有限但能通）
                {
                    urls: 'turn:openrelay.metered.ca:80',
                    username: 'openrelayproject',
                    credential: 'openrelayproject'
                },
                {
                    urls: 'turn:openrelay.metered.ca:443',
                    username: 'openrelayproject',
                    credential: 'openrelayproject'
                }
            ],
            iceCandidatePoolSize: 10
        });
        state.pc = pc;
        state.iceHost = 0;      // host 类型候选数（局域网直连的能力）
        state.iceRelay = 0;     // relay 类型候选数（走 TURN 中继）
        state.localIps = [];

        pc.onicecandidate = function (e) {
            if (!e.candidate) {
                // 收集结束，判断能不能直连
                reportLinkQuality();
                return;
            }
            var c = e.candidate;
            if (c.type === 'host') {
                state.iceHost++;
                // 从候选里抠出内网 IP，用于判断是否同网段
                var m = /(\d+\.\d+\.\d+\.\d+)/.exec(c.candidate || '');
                if (m && state.localIps.indexOf(m[1]) < 0) {
                    state.localIps.push(m[1]);
                }
            } else if (c.type === 'relay') {
                state.iceRelay++;
            }
            api('signal', {
                method: 'POST',
                body: { type: 'ice', payload: c }
            }).catch(function () { });
        };

        pc.onconnectionstatechange = function () {
            if (pc.connectionState === 'connected') {
                reportLinkQuality();
            } else if (pc.connectionState === 'failed') {
                // 连不上就降级，别让用户干等
                toast('点对点连不上，这个文件会改走网盘');
                state.p2pFailed = true;
            }
        };

        /* 发起方 = a（谁先开房间谁是 a）。
         * 注意：不再固定「电脑发起」，手机开房间时它也是 a。
         */
        if (state.role === 'a') {
            var dc = pc.createDataChannel('file', { ordered: true });
            setupChannel(dc);

            /* 【发 offer 的时机很关键】
             *
             * 原来 createOffer 之后立刻 setLocalDescription 就发出去。
             * 这时候 ICE 候选往往还没收集完 —— offer 里没有候选，
             * 对方收到后只能干等，而候选又是分多条后面才发出的，
             * 一旦顺序错乱或丢一条，整轮协商就废掉。
             *
             * 正确做法：等 icegatheringstate 变成 complete 再发，
             * 或者最多等 1.5 秒（有些网络下 host 候选出得慢，
             * 但也不能无限等）。
             */
            var sent = false;
            var sendOffer = function () {
                if (sent) return;
                sent = true;
                pc.createOffer().then(function (offer) {
                    return pc.setLocalDescription(offer);
                }).then(function () {
                    return api('signal', {
                        method: 'POST',
                        body: { type: 'offer', payload: pc.localDescription }
                    });
                }).catch(function (e) {
                    console.warn('createOffer 失败', e);
                });
            };

            // ICE 收集完成 → 立刻发
            pc.onicegatheringstatechange = function () {
                if (pc.iceGatheringState === 'complete') {
                    sendOffer();
                }
            };

            // 兜底：最多等 1.5 秒，避免慢网络下无限等待
            setTimeout(sendOffer, 1500);
        } else {
            pc.ondatachannel = function (e) { setupChannel(e.channel); };
        }

        ensureSignalPolling();
    }

    /**
     * 确保信令轮询在跑（幂等）。
     *
     * 抽出来的原因：initP2P 可能被调用多次（等待阶段一次、进房间一次），
     * 每次都要保证轮询存在，但不能重复建定时器。
     */
    function ensureSignalPolling() {
        if (state.signalTimer) return;   // 已经在跑

        state.signalTimer = setInterval(function () {
            if (!state.joined) return;   // 没进房间就跳过这一轮，但保留定时器
            api('signal', { method: 'GET' }).then(function (res) {
                if (!res.success) return;
                (res.data.messages || []).forEach(function (m) {
                    handleSignal(m);
                });
            }).catch(function () { });
        }, 1200);
    }

    /**
     * 重建 P2P 连接。
     *
     * 【为什么要重建】
     * WebRTC 的 offer 是一次性的：打洞失败之后，
     * 那条 PC 就废了，继续等只会一直停在 checking。
     * 所以超时之后必须关掉旧的、建新的、重新走一遍信令。
     *
     * 失败最常见的原因：
     *   1. 对方 tab 在后台被浏览器限流，轮询停了几秒
     *   2. 两端刚加进来时 ICE 候选还没收集完，offer 就发出去了
     *   3. 网络切换（WiFi ↔ 4G）
     * 这些都能靠重建解决，重建一次通常就通了。
     */
    function rebuildP2P(reason) {
        if (state.p2pRebuilding) return;
        state.p2pRebuilding = true;

        try { if (state.pc) state.pc.close(); } catch (e) { }
        state.pc = null;
        state.channel = null;

        // 清掉残留信令，避免旧的 offer/answer 干扰新一轮
        api('signal').catch(function () { });

        setTimeout(function () {
            state.p2pRebuilding = false;
            if (!state.joined) return;
            initP2P();
        }, 600);

        if (reason) {
            // 重建是正常容错行为，不打扰控制台
        }
    }

    /**
     * 启动连接看门狗。
     *
     * 进房间后每 3 秒检查一次：如果还没连上，
     * 且已经等超过 8 秒，就重建一次；最多重建 3 次。
     * 这样成功率从「看运气」变成「基本都能连上」。
     */
    function startP2PWatchdog() {
        if (state.p2pWatch) clearInterval(state.p2pWatch);

        var tries = 0;
        var started = Date.now();

        state.p2pWatch = setInterval(function () {
            if (!state.joined) {
                clearInterval(state.p2pWatch);
                state.p2pWatch = null;
                return;
            }

            // 已经连上就不管了
            if (state.channel && state.channel.readyState === 'open') {
                clearInterval(state.p2pWatch);
                state.p2pWatch = null;
                return;
            }

            var waited = Date.now() - started;
            if (waited < 8000) return;      // 8 秒内是正常打洞时间，别急

            if (tries >= 3) {
                // 试了 3 次还不行，标记失败，之后直接走网盘
                clearInterval(state.p2pWatch);
                state.p2pWatch = null;
                state.p2pFailed = true;
                console.warn('[P2P] 多次尝试仍失败，后续走网盘通道');
                return;
            }

            tries++;
            started = Date.now();
            rebuildP2P('第 ' + tries + ' 次超时重建');
        }, 3000);
    }

    /** 判断当前链路是局域网直连还是走了中继。 */
    function reportLinkQuality() {
        var pc = state.pc;
        if (!pc) return;

        // 优先读 ICE 选中的候选对（最准确）
        if (pc.getStats) {
            pc.getStats(null).then(function (stats) {
                stats.forEach(function (r) {
                    if (r.type === 'candidate-pair' && r.state === 'succeeded' && r.nominated !== false) {
                        stats.forEach(function (c) {
                            if (c.id === r.localCandidateId) {
                                state.linkType = (c.candidateType === 'host' || c.candidateType === 'srflx')
                                    ? 'lan' : 'relay';
                                state.linkLocalIp = c.address || '';
                            }
                        });
                    }
                });
                updateLinkBadge();
            }).catch(function () { });
        } else {
            state.linkType = state.iceHost > 0 ? 'lan' : (state.iceRelay > 0 ? 'relay' : 'unknown');
            updateLinkBadge();
        }
    }

    /** 在界面上标出「局域网直连」还是「中继」。 */
    function updateLinkBadge() {
        var el = document.getElementById('fhLinkBadge');
        if (!el) return;
        if (!state.channel || state.channel.readyState !== 'open') {
            el.hidden = true;
            return;
        }
        var lan = state.linkType === 'lan';
        el.hidden = false;
        el.textContent = lan ? '局域网直连' : (state.linkType === 'relay' ? '中继连接' : '点对点连接');
        el.className = 'fh-link-badge ' + (lan ? 'is-lan' : 'is-relay');
        el.title = lan
            ? '两台设备在同一局域网，文件走本地链路，不经过外网'
            : '两台设备不在同一局域网，走了中继服务器';
    }

    function handleSignal(msg) {
        var pc = state.pc;
        if (!pc) return;

        if (msg.type === 'offer' && state.role === 'b') {
            pc.setRemoteDescription(new RTCSessionDescription(msg.payload)).then(function () {
                return pc.createAnswer();
            }).then(function (answer) {
                return pc.setLocalDescription(answer);
            }).then(function () {
                return api('signal', {
                    method: 'POST',
                    body: { type: 'answer', payload: pc.localDescription }
                });
            }).catch(function (e) {
                console.warn('应答失败', e);
            });
        } else if (msg.type === 'answer' && state.role === 'a') {
            if (pc.signalingState === 'have-local-offer') {
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

            /* 通道一开就刷新一次链路徽标。
             *
             * 加入方（b）自己没有 createOffer，ICE 收集完的时机
             * 和发起方不一样，只靠 onicecandidate 里那次 reportLinkQuality()
             * 有时来不及（候选还没选中）。channel open 是最可靠的时机，
             * 两端都在这里补一次，保证徽标一定显示。
             */
            setTimeout(function () { reportLinkQuality(); }, 500);
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
            if (state.p2pWatch) clearInterval(state.p2pWatch);
            // 断开就清掉「本机房间」标记，否则下次带同一个码进来会误认成发起方
            try { sessionStorage.removeItem('fh_my_room'); } catch (e) { }
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

        /* 带 code 进来要区分两种人：
         *
         *   ① 自己刚点了「生成配对码」的发起方
         *   ② 别人扫码/手输配对码过来的加入方
         *
         * 原来这里无条件 state.role = 'b'，结果发起方一改变地址栏
         * （enterRoom 里 replaceState 会跳成 ?code=xxx）
         * 就把自己也变成了「加入方」。两端全是 b，
         * 谁都不发 offer —— P2P 永远连不上，这是「一直显示通道未就绪」的真正根源。
         *
         * 判定方式：sessionStorage 里记了「这个码是我生成的」→ 我是发起方。
         */
        if (state.code && state.code.length === 6) {
            var myCode = '';
            try { myCode = sessionStorage.getItem('fh_my_room') || ''; } catch (e) { }

            if (myCode === state.code) {
                /* 本机建的房间 → 我是发起方。
                 *
                 * 【为什么要先标 joined】
                 * enterRoom 里会建 WebRTC 并启动信令轮询，
                 * 而轮询体第一行是 if (!state.joined) return。
                 * 之前这里没设 joined=true，直接进 enterRoom，
                 * 结果定时器建好了、也一直在 tick，
                 * 但每一轮都被这行挡掉 —— 一次信令都没取过。
                 *
                 * 「自己是 room 的创建者」就意味着房间已经存在、
                 * 自己当然算已进房间，所以这里必须置 true。
                 */
                state.role = 'a';
                state.joined = true;
                enterRoom(state.code);
            } else {
                state.role = 'b';       // 别人的房间 = 加入方
                joinRoom(state.code);
            }
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
