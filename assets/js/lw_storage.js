/**
 * 本地加密持久化（需求二、7）
 * ---------------------------------------------------------------------------
 * 需求原文：「本地存储规范：全部本地数据加密持久化；初始化阶段拉取服务端数据覆盖写入本地存储。」
 *
 * 设计取舍（重要，别当成"真加密"）：
 *   浏览器里的密钥**必然拿得到**（同源脚本可读），所以这里的加密目标是：
 *     ① 防止明文直接躺在 localStorage 里被随手看到/被插件扫；
 *     ② 防止同一浏览器多账号之间串数据（每个账号单独派生密钥）；
 *     ③ 提供统一的读写入口，避免各模块各自 localStorage 导致格式失控。
 *   它不是"防得住有代码执行权限的攻击者"的加密 —— 那在纯前端不可能做到。
 *
 * 加密算法：
 *   用 Web Crypto 的 AES-GCM（浏览器原生，无需第三方库）。
 *   密钥由「账号标识 + 站点固定盐」经 PBKDF2 派生，同一账号稳定可复现。
 *
 * 降级：
 *   非 HTTPS / 老浏览器没有 crypto.subtle 时，自动退回**前缀标记的 Base64 混淆**
 *   （至少不散落明文键值），并在数据里标注 `weak:1`，读取方知道强度等级。
 */
(function () {
    'use strict';

    if (window.LWStorage) return;

    var PREFIX = 'lwenc1:';        // 强加密前缀
    var PREFIX_WEAK = 'lwenc0:';   // 弱混淆前缀
    var SALT = 'lovewall-local-v1';

    function b64encode(buf) {
        var bytes = new Uint8Array(buf);
        var s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s);
    }

    function b64decode(str) {
        var bin = atob(str);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return bytes;
    }

    function canStrong() {
        return !!(window.crypto && window.crypto.subtle && window.crypto.subtle.importKey);
    }

    // 同一账号的密钥在会话内只派生一次
    var keyCache = {};

    // 同步读取的进程内缓存（异步解密结果回填到这里）。
    // 必须声明在 LWStorage 之前：get() 里同步读它，靠 var 提升虽然也能跑，
    // 但声明位置在下文会让维护者误以为它是"后定义的"。
    var memCache = {};

    function deriveKey(scope) {
        if (keyCache[scope]) return keyCache[scope];
        var enc = new TextEncoder().encode(SALT + '|' + scope);
        var p = window.crypto.subtle.importKey('raw', enc, { name: 'PBKDF2' }, false, ['deriveKey'])
            .then(function (baseKey) {
                return window.crypto.subtle.deriveKey(
                    { name: 'PBKDF2', salt: new TextEncoder().encode(SALT), iterations: 60000, hash: 'SHA-256' },
                    baseKey,
                    { name: 'AES-GCM', length: 256 },
                    false,
                    ['encrypt', 'decrypt']
                );
            });
        keyCache[scope] = p;
        return p;
    }

    function weakEncrypt(text) {
        // 仅混淆：反转 + Base64，明确标注强度弱
        var rev = text.split('').reverse().join('');
        return PREFIX_WEAK + btoa(unescape(encodeURIComponent(rev)));
    }

    function weakDecrypt(payload) {
        try {
            var rev = decodeURIComponent(escape(atob(payload)));
            return rev.split('').reverse().join('');
        } catch (e) {
            return null;
        }
    }

    var LWStorage = {
        /** 当前账号作用域（登录后由后端注入；游客用 guest） */
        scope: 'guest',

        setScope: function (s) {
            this.scope = String(s || 'guest');
        },

        /**
         * 写：自动加密。
         *
         * 时序说明（容易踩坑）：AES-GCM 是异步 API，`set()` 不可能同步写完。
         * 为了保证「立刻读得到」，这里采用**双写**：
         *   ① 先同步写一份弱混淆版本 —— 保证同一 tick 内 get() 能拿到值；
         *   ② 异步用 AES-GCM 覆盖成强加密版本。
         * 代价是极短暂的双写窗口，但它消除了「写完立刻读为空」的竞态，
         * 对草稿自动保存这类场景非常关键。
         */
        set: function (key, value) {
            var json;
            try { json = JSON.stringify(value); } catch (e) { return false; }

            // ① 同步落地（弱混淆），保证立刻可读
            try {
                localStorage.setItem(key, weakEncrypt(json));
                memCache[key] = value;
            } catch (e) {
                return false;
            }

            // 非 HTTPS 环境没法用 subtle，弱混淆就是最终形态
            if (!canStrong()) return true;

            // ② 异步升级为 AES-GCM
            var scope = this.scope;
            var store = localStorage;
            deriveKey(scope).then(function (k) {
                var iv = window.crypto.getRandomValues(new Uint8Array(12));
                return window.crypto.subtle.encrypt(
                    { name: 'AES-GCM', iv: iv },
                    k,
                    new TextEncoder().encode(json)
                ).then(function (cipher) {
                    store.setItem(key, PREFIX + b64encode(iv) + '.' + b64encode(cipher));
                });
            }).catch(function () {
                // 升级失败保留弱混淆版本，不影响可用性
            });
            return true;
        },

        /**
         * 读：自动解密。
         * 兼容老数据：没有 lwenc 前缀的按明文 JSON 解析（迁移期不丢用户草稿）。
         */
        get: function (key, fallback) {
            var raw;
            try { raw = localStorage.getItem(key); } catch (e) { return fallback; }
            if (raw === null) return fallback;

            // 弱加密
            if (raw.indexOf(PREFIX_WEAK) === 0) {
                var plain = weakDecrypt(raw.slice(PREFIX_WEAK.length));
                try { return JSON.parse(plain); } catch (e) { return fallback; }
            }
            // 强加密：异步解密，这里只能同步返回缓存值；首次读取触发后台解密并回调
            if (raw.indexOf(PREFIX) === 0) {
                var mem = memCache[key];
                if (mem !== undefined) return mem;
                this._decryptAsync(key, raw);
                return fallback;
            }
            // 明文老数据：解析后顺手迁移成加密格式
            try {
                var obj = JSON.parse(raw);
                this.set(key, obj);
                return obj;
            } catch (e) {
                return fallback;
            }
        },

        /** 异步读取（推荐用这个，能保证拿到解密后的值） */
        getAsync: function (key, fallback) {
            var raw;
            try { raw = localStorage.getItem(key); } catch (e) { return Promise.resolve(fallback); }
            if (raw === null) return Promise.resolve(fallback);
            if (raw.indexOf(PREFIX_WEAK) === 0) {
                var plain = weakDecrypt(raw.slice(PREFIX_WEAK.length));
                try { return Promise.resolve(JSON.parse(plain)); } catch (e) { return Promise.resolve(fallback); }
            }
            if (raw.indexOf(PREFIX) === 0) {
                return this._decryptAsync(key, raw).then(function (v) {
                    return v === undefined ? fallback : v;
                });
            }
            try {
                var obj = JSON.parse(raw);
                this.set(key, obj);
                return Promise.resolve(obj);
            } catch (e) {
                return Promise.resolve(fallback);
            }
        },

        _decryptAsync: function (key, raw) {
            var scope = this.scope;
            var store = localStorage;
            var rest = raw.slice(PREFIX.length);
            var dot = rest.indexOf('.');
            if (dot <= 0) return Promise.resolve(undefined);
            var ivB64 = rest.slice(0, dot);
            var dataB64 = rest.slice(dot + 1);
            return deriveKey(scope).then(function (k) {
                return window.crypto.subtle.decrypt(
                    { name: 'AES-GCM', iv: b64decode(ivB64) },
                    k,
                    b64decode(dataB64)
                );
            }).then(function (buf) {
                var text = new TextDecoder().decode(buf);
                var obj = JSON.parse(text);
                memCache[key] = obj;
                return obj;
            }).catch(function () {
                // 解不开（换号/换盐）：丢掉，避免读到别人的数据
                try { store.removeItem(key); } catch (e) {}
                return undefined;
            });
        },

        remove: function (key) {
            try { localStorage.removeItem(key); delete memCache[key]; } catch (e) {}
        },

        /** 清空本账号作用域下的所有加密数据 */
        clearScope: function () {
            try {
                var keys = [];
                for (var i = 0; i < localStorage.length; i++) {
                    var k = localStorage.key(i);
                    if (k && (k.indexOf('lw_') === 0)) keys.push(k);
                }
                keys.forEach(function (k) {
                    var v = localStorage.getItem(k);
                    if (v && (v.indexOf(PREFIX) === 0 || v.indexOf(PREFIX_WEAK) === 0)) {
                        localStorage.removeItem(k);
                    }
                });
            } catch (e) {}
        },

        /**
         * 初始化阶段：拉服务端数据覆盖写入本地（需求二、7 后半句）。
         * @returns Promise
         */
        hydrate: function (opts) {
            opts = opts || {};
            var self = this;
            var url = opts.url || '/api/local_state.php';
            return fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (!res || !res.success) return null;
                var state = res.data || {};
                // 服务端为准：逐键覆盖写入（加密）
                Object.keys(state).forEach(function (k) {
                    self.set(k, state[k]);
                });
                return state;
            }).catch(function () { return null; });
        }
    };

    window.LWStorage = LWStorage;
})();
