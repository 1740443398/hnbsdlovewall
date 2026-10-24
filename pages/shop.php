<?php
/**
 * 装扮商城页面（需求二、2 / 二、3）
 *
 * - 积分兑换（管理员免费，界面明确标注）
 * - 支持头像框 / 气泡 / 勋章三类
 * - 预览区固定尺寸，保证视觉一致（不错位的一部分）
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/widgets.php';
require_once __DIR__ . '/../includes/widget_render.php';

$user = requireMember('装扮商城需要注册账号后才能使用');
$user = checkBanned($user);
if (!empty($user['is_banned'])) {
    header('Location: ' . SITE_URL . '/');
    exit();
}

$csrfToken = generateCSRFToken();
$uid = (int)$user['id'];
$isAdmin = in_array($user['role'] ?? '', ['admin', 'super_admin'], true);
$points = lwUserPoints($uid);
$equipped = lwUserWidgets($uid);

$pageTitle = '装扮商城 - ' . SITE_NAME;
?>
<!DOCTYPE html>
<html lang="<?= $LANG_CODE ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
    <link rel="icon" href="/icon.ico" type="image/x-icon">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <?php require __DIR__ . '/../includes/seo_meta.php'; ?>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>?v=<?= asset_ver('/assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/assets/css/widgets.css') ?>?v=<?= asset_ver('/assets/css/widgets.css') ?>">
    <?php /* 本站 shop-wrap 自带 1rem 内边距，U 端副顶栏若也贴边会重复留白，故单独收窄 */ ?>
    <style>.u-appbar{padding-left:1rem;padding-right:1rem;}</style>
    <script>
        const SITE_URL = '<?= SITE_URL ?>';
        const IS_LOGGED_IN = true;
        const USER_DATA = <?= json_encode(['id' => $uid, 'qq' => $user['qq'], 'nickname' => $user['nickname'], 'avatar' => $user['avatar'] ?? '', 'role' => $user['role']], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
        window.IS_LOGGED_IN = true;
        window.USER_DATA = USER_DATA;
    </script>
    <style>
        .shop-wrap{max-width:1000px;margin:0 auto;padding:1.25rem 1rem 3rem;}
        .shop-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:1.125rem 1.25rem;border-radius:1rem;background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);box-shadow:var(--shadow-sm);margin-bottom:1.125rem;}
        .shop-hero h1{font-size:1.25rem;font-weight:800;color:var(--text);margin:0 0 .25rem;display:flex;align-items:center;gap:.5rem;}
        .shop-hero p{font-size:.8rem;color:var(--text-secondary);margin:0;line-height:1.6;}
        .shop-points{display:flex;flex-direction:column;align-items:flex-end;gap:.25rem;}
        .shop-points-num{font-size:1.5rem;font-weight:800;color:var(--primary);line-height:1;}
        .shop-points-label{font-size:.72rem;color:var(--text-secondary);}
        .shop-admin-tip{display:inline-flex;align-items:center;gap:.375rem;margin-top:.375rem;padding:.25rem .625rem;border-radius:9999px;background:rgba(46,139,87,.1);color:#2E8B57;font-size:.72rem;font-weight:600;}
        .shop-section{margin-bottom:1.5rem;}
        .shop-section-head{display:flex;align-items:baseline;gap:.5rem;margin-bottom:.75rem;}
        .shop-section-head h2{font-size:.95rem;font-weight:700;color:var(--text);margin:0;}
        .shop-section-head span{font-size:.74rem;color:var(--text-secondary);}
        .shop-empty{padding:2rem;text-align:center;color:var(--text-secondary);font-size:.85rem;background:var(--surface,#fff);border:1px dashed var(--border-light,#E4E9F0);border-radius:.875rem;}
        .shop-card-btn{width:100%;padding:.5rem;border:none;border-radius:.5rem;background:var(--primary);color:#fff;font-size:.8rem;font-weight:600;font-family:inherit;cursor:pointer;transition:filter .18s;}
        .shop-card-btn:hover{filter:brightness(1.07);}
        .shop-card-btn:disabled{opacity:.5;cursor:not-allowed;}
        .shop-card-btn.owned{background:var(--bg-secondary);color:var(--text-secondary);}
        .shop-card-btn.equipped{background:#2E8B57;}
        .shop-badge{position:absolute;top:.5rem;right:.5rem;padding:.125rem .4375rem;border-radius:9999px;background:#2E8B57;color:#fff;font-size:.625rem;font-weight:700;}
        .shop-card-rel{position:relative;}
        /* 预览：固定盒子里居中，三类挂件视觉尺寸一致 */
        .shop-preview-frame{width:3.5rem;height:3.5rem;}
        .shop-preview-bubble{max-width:7rem;font-size:.75rem;line-height:1.4;}
        .shop-preview-badge{justify-self:center;}
        /* 分类胶囊：对齐参考稿的「更多推荐 / 简约 / 软萌可爱 / 治愈 / 萌宠 / 玄幻」 */
        .shop-chips{display:flex;gap:.4rem;overflow-x:auto;padding:.125rem 0 .75rem;scrollbar-width:none;}
        .shop-chips::-webkit-scrollbar{display:none;}
        .shop-chip{flex:0 0 auto;padding:.3rem .8rem;border-radius:9999px;border:1px solid var(--border-light,#E4E9F0);
            background:var(--surface,#fff);color:var(--text-secondary);font-size:.78rem;font-weight:600;
            font-family:inherit;cursor:pointer;transition:background .16s,color .16s,border-color .16s;}
        .shop-chip.on{background:var(--text,#1F2733);color:#fff;border-color:transparent;}
        /* 价格角标：SVIP 免费那套换成「免积分」 */
        .shop-price-tag{position:absolute;left:.5rem;bottom:3.6rem;padding:.1rem .4rem;border-radius:6px;
            background:rgba(20,27,38,.72);color:#fff;font-size:.625rem;font-weight:700;letter-spacing:.02em;}
        .shop-price-tag.free{background:rgba(46,139,87,.85);}
        /* 商城卡片加高预览区，四列更紧凑 */
        .lw-shop-card{padding:.75rem;}
        .lw-shop-preview{height:5.5rem;background:linear-gradient(180deg,var(--bg-secondary,#F7F9FC),var(--surface,#fff));}
        .shop-frame-preview{display:flex;align-items:center;justify-content:center;}
        .shop-frame-preview .lw-av{--lw-av:3.5rem;}
        /* 成就勋章板：只读展示，没有购买按钮 */
        .shop-medal-panel{background:var(--surface,#fff);border:1px solid var(--border-light,#E4E9F0);
            border-radius:.875rem;padding:.875rem 1rem 1rem;}
        .shop-medal-head{font-size:.82rem;color:var(--text-secondary);margin-bottom:.75rem;}
        .shop-medal-head b{color:var(--primary);font-size:1rem;}
        .shop-medal-head span{color:var(--text-secondary);opacity:.8;}
        .shop-medal-grid{display:flex;flex-wrap:wrap;gap:.5rem;}
        .shop-medal{display:inline-flex;align-items:center;gap:.375rem;padding:.375rem .625rem;border-radius:9999px;
            background:var(--bg-secondary,#F4F7FA);border:1px solid var(--border-light,#E4E9F0);
            font-size:.76rem;color:var(--text);line-height:1;max-width:100%;}
        .shop-medal.got{background:linear-gradient(135deg,rgba(255,214,120,.22),rgba(255,180,80,.12));
            border-color:rgba(214,158,46,.45);}
        .shop-medal.locked{opacity:.5;filter:grayscale(1);}
        .shop-medal-icon{font-size:.95rem;line-height:1;}
        .shop-medal-name{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .shop-medal-prog{font-size:.68rem;color:var(--text-secondary);font-variant-numeric:tabular-nums;}
    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/site_header.php'; ?>

<div class="shop-wrap">
    <div class="shop-hero">
        <div>
            <h1>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                装扮商城
            </h1>
            <p>用积分兑换个性装扮。挂件按槽位固定渲染，永远不会撑坏页面布局。</p>
            <?php if ($isAdmin): ?>
            <span class="shop-admin-tip">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                管理员权限：全部装扮免积分解锁
            </span>
            <?php endif; ?>
        </div>
        <div class="shop-points">
            <div class="shop-points-num" id="shopPoints"><?= (int)$points ?></div>
            <div class="shop-points-label">我的积分</div>
        </div>
    </div>

    <?php /* 积分兑换码（需求二、5）：输入框 + 兑换按钮，成功后即时刷新余额 */ ?>
    <div class="shop-hero" style="display:block;">
        <div style="font-size:.9rem;font-weight:700;color:var(--text);margin-bottom:.5rem;">积分兑换码</div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <input type="text" id="codeInput" placeholder="输入兑换码，如 LWABCD2345EFGH"
                   maxlength="24" autocomplete="off"
                   style="flex:1 1 14rem;min-width:0;padding:.5rem .75rem;border:1px solid var(--border,#E3E7EC);border-radius:.625rem;font-size:.85rem;font-family:ui-monospace,monospace;letter-spacing:.04em;text-transform:uppercase;color:var(--text);background:var(--bg,#fff);">
            <button type="button" id="codeBtn"
                    style="padding:.5rem 1.125rem;border:none;border-radius:.625rem;background:var(--primary);color:#fff;font-weight:600;font-size:.85rem;font-family:inherit;cursor:pointer;">
                兑换
            </button>
        </div>
        <p style="font-size:.72rem;color:var(--text-secondary);margin:.5rem 0 0;line-height:1.6;">
            兑换码由管理员发放，每个只能用一次。兑换成功后积分立即到账。
        </p>
    </div>

    <div class="shop-section" id="secFrame">
        <div class="shop-section-head"><h2>头像框</h2><span>环绕头像的固定边框</span></div>
        <div class="shop-chips" id="chipsFrame"></div>
        <div class="lw-shop-grid" id="gridFrame"><div class="shop-empty">加载中…</div></div>
    </div>

    <div class="shop-section" id="secBubble">
        <div class="shop-section-head"><h2>聊天气泡</h2><span>消息气泡的配色与质感</span></div>
        <div class="shop-chips" id="chipsBubble"></div>
        <div class="lw-shop-grid" id="gridBubble"><div class="shop-empty">加载中…</div></div>
    </div>

    <div class="shop-section" id="secTitle">
        <div class="shop-section-head"><h2>自定义头衔</h2><span>购买后在个人中心解锁头衔输入框，<b>永久有效</b></span></div>
        <div class="lw-shop-grid" id="gridTitle"><div class="shop-empty">加载中…</div></div>
    </div>

    <?php /* 勋章不进商城：它是成长体系的成就，只能靠真实行为解锁。
             这里改成一块「我的成就勋章」展示板 —— 用户看得到自己挣了什么，
             想解锁更多就知道去发帖 / 签到 / 被赞，而不是去花钱。 */ ?>
    <div class="shop-section" id="secMedal">
        <div class="shop-section-head">
            <h2>我的成就勋章</h2>
            <span>靠发帖、签到、被赞等真实行为解锁，<b>不出售</b></span>
        </div>
        <div class="shop-medal-panel" id="medalPanel"><div class="shop-empty">加载中…</div></div>
    </div>
</div>

<?php require __DIR__ . '/../includes/mobile_bottom_nav.php'; ?>
<script>
(function () {
    'use strict';
    var csrf = <?= json_encode($csrfToken) ?>;
    var isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    var catalog = [];
    var owned = {};
    var equipped = {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function toast(msg, type) {
        if (window.App && App.showToast) App.showToast(msg, type || 'info');
        else alert(msg);
    }
    function daysLeft(ts) {
        if (!ts || ts <= 0) return '永久';
        var d = Math.ceil((ts * 1000 - Date.now()) / 86400000);
        return d > 0 ? (d + ' 天') : '已过期';
    }

    /** 商品有效期文案：days<=0 是永久，别再显示「有效期 0 天」 */
    function lifeText(w) {
        return (!w.days || w.days <= 0) ? '永久有效' : ('有效期 ' + w.days + ' 天');
    }

    /**
     * 成就勋章展示板。
     *
     * 勋章不再是商品：数据来自 user_achievements（lwUserBadges），
     * 由 /api/user/achievements.php 下发。这里只做展示，没有购买/装备按钮。
     */
    function renderMedals() {
        var box = document.getElementById('medalPanel');
        if (!box) return;
        fetch(SITE_URL + '/api/user/achievements.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store'
        }).then(function (r) { return r.json(); })
          .then(function (res) {
              var d = (res && res.data) || {};
              var list = d.items || [];
              if (!list.length) {
                  box.innerHTML = '<div class="shop-empty">还没有成就定义。发帖、签到、被点赞都会解锁勋章。</div>';
                  return;
              }
              // 已解锁的排前面（用户最想看到自己挣到了什么），未解锁的显示进度
              var got = list.filter(function (a) { return a.unlocked; });
              var rest = list.filter(function (a) { return !a.unlocked; });
              var head = '<div class="shop-medal-head">已解锁 <b>' + got.length + '</b> / ' + list.length +
                         ' 枚<span>（继续发帖、签到、被点赞就能点亮更多）</span></div>';
              function card(a) {
                  var locked = !a.unlocked;
                  var prog = (a.need > 0) ? (' · ' + Math.min(a.current || 0, a.need) + '/' + a.need) : '';
                  return '<div class="shop-medal' + (locked ? ' locked' : ' got') + '" title="' + esc(a.desc || '') + '">' +
                      '<span class="shop-medal-icon">' + esc(a.icon || '🏅') + '</span>' +
                      '<span class="shop-medal-name">' + esc(a.name || a.key || '成就') + '</span>' +
                      (locked ? '<span class="shop-medal-prog">' + esc(prog.replace(/^ · /, '')) + '</span>' : '') +
                      '</div>';
              }
              box.innerHTML = head + '<div class="shop-medal-grid">' +
                  got.map(card).join('') + rest.map(card).join('') + '</div>';
          })
          .catch(function () {
              box.innerHTML = '<div class="shop-empty">成就加载失败，刷新重试。</div>';
          });
    }

    function previewHtml(w) {
        if (w.slot === 'avatar_frame') {
            // 预览用自己的头像 + 试穿这个框：所见即所得。
            // 图片型框（asset）是「图案围着人像」的构图，头像要按同样口径内缩，
            // 否则预览里脸被图案压住 —— 与 avatar.php 的 0.17 内缩保持一致。
            var isImg = !!(w.asset && String(w.asset).trim());
            var inset = isImg ? 11 : 3;
            var dia = 56 - inset * 2;
            var frame = isImg
                ? '<span class="lw-widget lw-widget-frame lw-frame-img" style="background-image:url(\'' + esc(w.asset) + '\')"></span>'
                : (w.css_class ? '<span class="lw-widget lw-widget-frame ' + esc(w.css_class) + '"></span>' : '');
            return '<span class="lw-av shop-preview-frame' + (isImg ? ' has-img-frame' : '') + '" style="--lw-av:3.5rem;width:3.5rem;height:3.5rem;position:relative;display:inline-flex;align-items:center;justify-content:center;border-radius:50%">' + frame +
                   '<img class="lw-av-img" width="' + dia + '" height="' + dia + '" style="position:absolute;left:' + inset + 'px;top:' + inset + 'px;width:' + dia + 'px;height:' + dia + 'px;border-radius:50%;object-fit:cover" src="' + esc(USER_DATA.avatar || (SITE_URL + '/assets/images/default-avatar.svg')) + '" alt="">' +
                   '</span>';
        }
        if (w.slot === 'bubble') {
            return '<span class="lw-slot lw-bubble-slot shop-preview-bubble ' + esc(w.css_class) + '">你好呀</span>';
        }
        if (w.slot === 'title') {
            // 头衔卖的是「槽位」不是文案，预览用一个示意标签
            return '<span class="user-title shop-preview-badge" style="--ut-bg:#4A90D9;--ut-fg:#fff;">我的头衔</span>';
        }
        return '<span class="lw-badge-slot shop-preview-badge ' + esc(w.css_class) + '">' + esc(w.name) + '</span>';
    }

    /** 当前选中的分类（'' = 全部） */
    var chips = { avatar_frame: '', bubble: '' };

    /** 从 desc 里取分类标签（后台/种库里写的是「简约」「治愈」这类词） */
    function tagOf(w) {
        return (w.desc || '').trim() || '推荐';
    }

    function renderChips(slot, boxId) {
        var box = document.getElementById(boxId);
        var items = catalog.filter(function (w) { return w.slot === slot; });
        var tags = [];
        items.forEach(function (w) {
            var t = tagOf(w);
            if (tags.indexOf(t) < 0) tags.push(t);
        });
        // 「全部」永远第一，其余按出现顺序，避免每次刷新顺序跳动
        var all = [''].concat(tags);
        box.innerHTML = all.map(function (t) {
            var label = t === '' ? '全部' : t;
            return '<button type="button" class="shop-chip' + (chips[slot] === t ? ' on' : '') +
                   '" data-slot="' + slot + '" data-tag="' + esc(t) + '">' + esc(label) + '</button>';
        }).join('');
        box.querySelectorAll('.shop-chip').forEach(function (btn) {
            btn.addEventListener('click', function () {
                chips[slot] = btn.dataset.tag;
                renderChips(slot, boxId);
                renderGrid(slot, slot === 'avatar_frame' ? 'gridFrame' : 'gridBubble');
            });
        });
    }

    function renderGrid(slot, gridId) {
        var grid = document.getElementById(gridId);
        var items = catalog.filter(function (w) {
            // 坑：chips 只为「有分类胶囊」的槽位初始化（avatar_frame / bubble）。
            // 头衔这类没有胶囊的槽位 chips[slot] 是 undefined，
            // 用 chips[slot] === '' 判断会把整个品类过滤成空 —— 表现就是
            // 「自定义头衔」区块永远显示「暂无该品类商品」。
            var tag = chips[slot];
            if (tag === undefined || tag === '') return w.slot === slot;
            return w.slot === slot && tagOf(w) === tag;
        });
        if (!items.length) {
            grid.innerHTML = '<div class="shop-empty">暂无该品类商品</div>';
            return;
        }
        grid.innerHTML = items.map(function (w) {
            var o = owned[w.id];
            var isEquipped = equipped[slot] && equipped[slot].id === w.id;
            var price = isAdmin ? 0 : w.price;
            var btnText, btnClass, disabled = '';
            if (slot === 'title') {
                // 头衔槽位是可叠加的消耗品：没有「装备/已装备」概念，只有「再买一个」
                btnText = (price === 0 ? '免费领取槽位' : ('购买槽位 ' + price + ' 积分'));
                btnClass = '';
            } else if (isEquipped) {
                btnText = '已装备'; btnClass = ' equipped'; disabled = ' disabled';
            } else if (o && !o.expired) {
                btnText = '装备'; btnClass = ' owned';
            } else if (o && o.expired) {
                btnText = '续期 ' + price + ' 积分'; btnClass = '';
            } else {
                btnText = (price === 0 ? '免费领取' : (price + ' 积分')); btnClass = '';
            }
            return '<div class="lw-shop-card shop-card-rel">' +
                (isEquipped ? '<span class="shop-badge">使用中</span>' : '') +
                '<div class="lw-shop-preview shop-frame-preview">' + previewHtml(w) + '</div>' +
                '<span class="shop-price-tag' + (price === 0 ? ' free' : '') + '">' + (price === 0 ? '免积分' : price + ' 积分') + '</span>' +
                '<div class="lw-shop-name">' + esc(w.name) + '</div>' +
                '<div class="lw-shop-meta"><span>' + esc(w.slot_label) + '</span><span>' +
                    (o && !o.expired
                        ? ((Number(o.expires_at) > 0 ? '剩 ' + daysLeft(o.expires_at) : '永久有效'))
                        : lifeText(w)) +
                '</span></div>' +
                '<button type="button" class="shop-card-btn' + btnClass + '" data-wid="' + w.id + '" data-action="' +
                    (slot === 'title' ? 'buy' : (o && !o.expired ? 'equip' : 'buy')) + '"' + disabled + '>' + esc(btnText) + '</button>' +
                '</div>';
        }).join('');

        grid.querySelectorAll('button[data-wid]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wid = Number(btn.dataset.wid);
                var act = btn.dataset.action;
                // 头衔按钮要把人送到「能真正设置头衔」的地方，而不是原地刷新
                var willBuyTitle = (act === 'buy') && items.some(function (x) {
                    return x.id === wid && x.slot === 'title';
                });
                btn.disabled = true;
                var body = new URLSearchParams();
                body.append('csrf_token', csrf);
                body.append('action', act);
                body.append('widget_id', String(wid));
                if (act === 'equip') body.append('equip', '1');
                fetch(SITE_URL + '/api/widgets.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body
                }).then(function (r) { return r.json(); })
                  .then(function (res) {
                      btn.disabled = false;
                      if (!res.success) { toast(res.message || '操作失败', 'error'); return; }
                      toast(res.message || '成功', 'success');
                      // 买完头衔直接跳去「个人中心 → 资料 → 个性头衔」，
                      // 输入框就在那，省得用户再自己找一遍入口。
                      if (willBuyTitle) {
                          setTimeout(function () {
                              location.href = SITE_URL + '/pages/user_center.php?tab=profile#titleText';
                          }, 600);
                          return;
                      }
                      load();
                  })
                  .catch(function () { btn.disabled = false; toast('网络错误', 'error'); });
            });
        });
    }

    function load() {
        fetch(SITE_URL + '/api/widgets.php?action=catalog', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { toast('加载失败', 'error'); return; }
                catalog = res.data.widgets || [];
                owned = res.data.owned || {};
                equipped = res.data.equipped || {};
                document.getElementById('shopPoints').textContent = res.data.points;
                renderChips('avatar_frame', 'chipsFrame');
                renderChips('bubble', 'chipsBubble');
                renderGrid('avatar_frame', 'gridFrame');
                renderGrid('bubble', 'gridBubble');
                renderGrid('title', 'gridTitle');
                renderMedals();
            })
            .catch(function () { toast('加载失败', 'error'); });
    }

    load();

    // 兑换码
    var codeBtn = document.getElementById('codeBtn');
    var codeInput = document.getElementById('codeInput');
    if (codeBtn && codeInput) {
        codeBtn.addEventListener('click', function () {
            var code = (codeInput.value || '').trim();
            if (!code) { toast('请输入兑换码', 'info'); return; }
            codeBtn.disabled = true;
            var body = new URLSearchParams();
            body.append('csrf_token', csrf);
            body.append('action', 'redeem');
            body.append('code', code);
            fetch(SITE_URL + '/api/points_code.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (r) { return r.json(); })
              .then(function (res) {
                  codeBtn.disabled = false;
                  if (!res.success) { toast(res.message || '兑换失败', 'error'); return; }
                  codeInput.value = '';
                  document.getElementById('shopPoints').textContent = res.data.balance;
                  toast(res.message || '兑换成功', 'success');
                  load();
              })
              .catch(function () { codeBtn.disabled = false; toast('网络错误', 'error'); });
        });
        codeInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); codeBtn.click(); }
        });
    }
})();
</script>
</body>
</html>
