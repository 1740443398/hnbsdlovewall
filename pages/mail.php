<?php
/**
 * 邮箱中心（用户端整页）
 *
 * 四个标签页：
 *   · 收件箱 —— 站内互发 + 已绑定邮箱 IMAP 拉取的来信
 *   · 已发送 —— 我发出去的每一封（含站内投递/网关中继标记）
 *   · 写信   —— 用站内地址或已绑定地址发送
 *   · 设置   —— 站内邮箱信息 + 绑定自有邮箱（邮箱+授权码）
 *
 * 注意：收件箱内容来自用户邮箱，渲染一律走 esc() 文本插入，绝不 innerHTML 原文。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/mail_center.php';

$user = requireLogin();
$user = checkBanned($user);
$pageTitle = '邮箱中心 · ' . SITE_NAME;
$csrfToken = generateCSRFToken();
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<?php require_once __DIR__ . '/../includes/theme_boot.php'; ?>
<title><?= htmlspecialchars($pageTitle) ?></title>
<?php require_once __DIR__ . '/../includes/pwa_head.php'; ?>
<style>
.mc-page{max-width:780px;margin:0 auto;padding:18px 14px 90px}
.mc-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.mc-head h1{font-size:19px;margin:0}
.mc-addr{font-size:12px;color:var(--text-muted);background:var(--card-bg);border:1px solid var(--border);border-radius:999px;padding:5px 12px}
.mc-tabs{display:flex;gap:8px;margin-bottom:14px;overflow-x:auto;padding-bottom:2px}
.mc-tab{flex:0 0 auto;padding:7px 16px;border-radius:999px;border:1px solid var(--border);background:var(--card-bg);color:var(--text-muted);font-size:13px;cursor:pointer}
.mc-tab.on{background:var(--primary);color:#fff;border-color:var(--primary)}
.mc-card{background:var(--card-bg);border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:10px}
.mc-list{display:flex;flex-direction:column;gap:8px}
.mc-item{display:flex;gap:10px;align-items:flex-start;background:var(--card-bg);border:1px solid var(--border);border-radius:14px;padding:12px 14px;cursor:pointer}
.mc-item .mc-badge{flex:0 0 auto;font-size:11px;border-radius:6px;padding:2px 7px;white-space:nowrap}
.mc-badge.in{color:#1E7A4C;background:rgba(30,122,76,.12)}
.mc-badge.out{color:#2F5B9A;background:rgba(47,91,154,.12)}
.mc-badge.risk{color:#B45309;background:rgba(180,83,9,.14)}
.mc-item .subj{font-size:13.5px;font-weight:600;color:var(--text);margin-bottom:3px;word-break:break-all}
.mc-item .meta{font-size:11.5px;color:var(--text-muted);word-break:break-all}
.mc-item .snip{font-size:12.5px;color:var(--text-muted);margin-top:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.mc-empty{text-align:center;color:var(--text-muted);padding:50px 16px;font-size:13.5px}
.mc-field{margin-bottom:12px}
.mc-field label{display:block;font-size:12.5px;color:var(--text-muted);margin-bottom:5px}
.mc-field input,.mc-field textarea{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;background:var(--card-bg);color:var(--text);padding:10px 12px;font-size:13.5px;font-family:inherit}
.mc-field textarea{min-height:160px;resize:vertical}
.mc-btn{display:inline-flex;align-items:center;gap:6px;background:var(--primary);color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:13.5px;font-weight:600;cursor:pointer}
.mc-btn.ghost{background:transparent;color:var(--primary);border:1px solid var(--primary)}
.mc-btn:disabled{opacity:.55;cursor:not-allowed}
.mc-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.mc-kv{font-size:13px;color:var(--text);line-height:1.9}
.mc-kv b{color:var(--text-muted);font-weight:400;font-size:12px;margin-right:6px}
.mc-hint{font-size:12px;color:var(--text-muted);line-height:1.7;margin-top:8px}
.mc-toast{position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:#1F2733;color:#fff;border-radius:10px;padding:10px 18px;font-size:13px;z-index:99;max-width:80vw}
.mc-sec{font-size:13px;font-weight:700;color:var(--text);margin:18px 0 10px}
.mc-ban{background:rgba(180,83,9,.10);border:1px solid rgba(180,83,9,.35);color:#B45309;border-radius:14px;padding:14px 16px;font-size:13px;margin-bottom:12px}
.sk{height:60px;border-radius:14px;background:linear-gradient(90deg,rgba(140,150,165,.14),rgba(140,150,165,.28),rgba(140,150,165,.14));background-size:200% 100%;animation:skm 1.2s infinite}
@keyframes skm{0%{background-position:200% 0}100%{background-position:-200% 0}}
@media (prefers-reduced-motion: reduce){.sk{animation:none}}
</style>
</head>
<body>
<?php require __DIR__ . '/../includes/site_header.php'; ?>
<main class="mc-page">
  <div class="mc-head">
    <h1>邮箱中心</h1>
    <span class="mc-addr" id="mcAddr">加载中…</span>
  </div>
  <div id="mcBan" class="mc-ban" style="display:none"></div>
  <div class="mc-tabs" id="mcTabs">
    <button class="mc-tab on" data-tab="inbox">📥 收件箱</button>
    <button class="mc-tab" data-tab="sent">📤 已发送</button>
    <button class="mc-tab" data-tab="compose">✍️ 写信</button>
    <button class="mc-tab" data-tab="settings">⚙️ 设置</button>
  </div>
  <div id="mcBody"></div>
</main>

<script>
var csrf = <?= json_encode($csrfToken) ?>;
var body = document.getElementById('mcBody');
var STATUS = null;
var curTab = 'inbox';

function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
function toast(m){
  var t=document.createElement('div');t.className='mc-toast';t.textContent=m;document.body.appendChild(t);
  setTimeout(function(){t.remove();},2600);
}
function api(payload){
  payload.csrf_token = csrf;
  return fetch('/api/mail_center.php', {method:'POST', body:(function(){var f=new FormData();for(var k in payload){f.append(k,payload[k]);}return f;})()})
    .then(function(r){return r.json();});
}
function skel(n){var h='';for(var i=0;i<(n||3);i++){h+='<div class="sk"></div>';}return h;}

function renderStatus(){
  var el = document.getElementById('mcAddr');
  if (!STATUS) return;
  el.textContent = STATUS.site_addr || '未开通';
  var ban = document.getElementById('mcBan');
  if (STATUS.banned) {
    ban.style.display = '';
    ban.textContent = '你的邮箱功能已被管理员停用' + (STATUS.ban_reason ? '：' + STATUS.ban_reason : '') + '。如有疑问请联系管理员。';
  } else { ban.style.display = 'none'; }
}

function viewInbox(){
  body.innerHTML = '<div class="mc-list" id="mcList">' + skel(3) + '</div>';
  api({action:'list_inbox'}).then(function(res){
    var list = (res.data && res.data.list) || [];
    var el = document.getElementById('mcList'); if(!el) return;
    if (!list.length) { el.innerHTML = '<div class="mc-empty">收件箱还是空的。<br>站内同学给你发信、或者你绑定的邮箱收到新信后，会出现在这里。</div>'; return; }
    el.innerHTML = list.map(function(m){
      return '<div class="mc-item" data-msgid="'+esc(m.msgid)+'">'
        + '<span class="mc-badge '+(m.transport&&m.transport.indexOf('imap')===0?'in':'in')+'">'+(m.transport==='internal'?'站内':(m.transport&&m.transport.indexOf('imap-bound')===0?'绑定邮箱':'站内'))+'</span>'
        + (m.risk && m.risk!=='none' ? '<span class="mc-badge risk">疑似违规</span>' : '')
        + '<div style="flex:1;min-width:0"><div class="subj">'+esc(m.subject||'(无主题)')+'</div>'
        + '<div class="meta">'+esc(m.from)+' → '+esc(m.to)+' · '+esc(m.ts)+'</div>'
        + '<div class="snip">'+esc((m.text||'').slice(0,120))+'</div></div></div>';
    }).join('');
    el.querySelectorAll('.mc-item').forEach(function(it){
      it.addEventListener('click', function(){ viewDetail(it.getAttribute('data-msgid')); });
    });
  }).catch(function(){ toast('加载失败'); });
}

function viewSent(){
  body.innerHTML = '<div class="mc-list" id="mcList">' + skel(2) + '</div>';
  api({action:'list_sent'}).then(function(res){
    var list = (res.data && res.data.list) || [];
    var el = document.getElementById('mcList'); if(!el) return;
    if (!list.length) { el.innerHTML = '<div class="mc-empty">还没有发送记录。</div>'; return; }
    el.innerHTML = list.map(function(m){
      var tmap = {internal:'站内投递', bound:'自有邮箱直发', gateway:'网关中继'};
      return '<div class="mc-item" data-msgid="'+esc(m.msgid)+'">'
        + '<span class="mc-badge out">'+esc(tmap[m.transport]||'已发送')+'</span>'
        + (m.risk && m.risk!=='none' ? '<span class="mc-badge risk">疑似违规</span>' : '')
        + '<div style="flex:1;min-width:0"><div class="subj">'+esc(m.subject||'(无主题)')+'</div>'
        + '<div class="meta">我 → '+esc(m.to)+' · '+esc(m.ts)+'</div>'
        + '<div class="snip">'+esc((m.text||'').slice(0,120))+'</div></div></div>';
    }).join('');
    el.querySelectorAll('.mc-item').forEach(function(it){
      it.addEventListener('click', function(){ viewDetail(it.getAttribute('data-msgid')); });
    });
  }).catch(function(){ toast('加载失败'); });
}

function viewDetail(msgid){
  api({action:'detail', msgid: msgid}).then(function(res){
    if (!res.success) { toast(res.message || '加载失败'); return; }
    var m = res.data.msg;
    body.innerHTML = '<div class="mc-card">'
      + '<div class="mc-kv"><b>主题</b>' + esc(m.subject) + '</div>'
      + '<div class="mc-kv"><b>发件人</b>' + esc(m.from || m.from_addr || '') + '</div>'
      + '<div class="mc-kv"><b>收件人</b>' + esc(m.to) + '</div>'
      + '<div class="mc-kv"><b>时间</b>' + esc(m.ts) + '</div>'
      + '<div class="mc-kv"><b>通道</b>' + esc(m.transport || '') + (m.risk && m.risk!=='none' ? ' · <span style="color:#B45309">疑似违规内容</span>' : '') + '</div>'
      + '<hr style="border:none;border-top:1px solid var(--border);margin:12px 0">'
      + '<div style="font-size:13.5px;line-height:1.8;white-space:pre-wrap;word-break:break-word">' + esc(m.text) + '</div>'
      + '<div class="mc-row" style="margin-top:14px"><button class="mc-btn ghost" onclick="switchTab(\'inbox\')">返回</button></div>'
      + '</div>';
  });
}

function viewCompose(){
  var fromOpts = '';
  if (STATUS && STATUS.site_addr) fromOpts += '<option value="site">' + esc(STATUS.site_addr) + '（站内地址）</option>';
  if (STATUS && STATUS.bound) fromOpts += '<option value="bound">' + esc(STATUS.bound.email) + '（自有邮箱）</option>';
  body.innerHTML = '<div class="mc-card">'
    + (fromOpts ? '<div class="mc-field"><label>发件身份</label><select id="cFrom" style="width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;background:var(--card-bg);color:var(--text);padding:10px 12px;font-size:13.5px">'+fromOpts+'</select></div>' : '')
    + '<div class="mc-field"><label>收件人邮箱</label><input id="cTo" placeholder="someone@qq.com，或站内同学的 '+esc((STATUS&&STATUS.mail_domain)||'bsd.ct.ws')+' 地址"></div>'
    + '<div class="mc-field"><label>主题</label><input id="cSubj" maxlength="120"></div>'
    + '<div class="mc-field"><label>正文（' + ((STATUS&&STATUS.quota&&STATUS.quota.limit)||20) + ' 封/日 · 已用 ' + ((STATUS&&STATUS.quota&&STATUS.quota.used)||0) + '）</label><textarea id="cText" maxlength="10000"></textarea></div>'
    + '<div class="mc-row"><button class="mc-btn" id="cSend">发送</button>'
    + '<span class="mc-hint">发往外部邮箱经站方通道中继；违规内容会被自动拦截并记录。</span></div>'
    + '</div>';
  var btn = document.getElementById('cSend');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var to = document.getElementById('cTo').value.trim();
    var subj = document.getElementById('cSubj').value.trim();
    var text = document.getElementById('cText').value.trim();
    var fromKind = (document.getElementById('cFrom')||{value:'site'}).value;
    if (!to || !subj || !text) { toast('请填完整再发送'); return; }
    btn.disabled = true; btn.textContent = '发送中…';
    api({action:'send', from_kind:fromKind, to:to, subject:subj, text:text}).then(function(res){
      btn.disabled = false; btn.textContent = '发送';
      if (res.success) { toast('发送成功'); switchTab('sent'); }
      else { toast(res.message || '发送失败'); }
    }).catch(function(){ btn.disabled=false; btn.textContent='发送'; toast('网络错误'); });
  });
}

function viewSettings(){
  body.innerHTML = '<div class="mc-card" id="mcSettings">' + skel(2) + '</div>';
  api({action:'status'}).then(function(res){
    var s = res.data || {};
    var bound = s.bound;
    var html = ''
      + '<div class="mc-sec">站内邮箱</div>'
      + '<div class="mc-kv"><b>地址</b>' + esc(s.site_addr || '未开通') + '</div>'
      + '<div class="mc-kv"><b>配额</b>' + (s.quota ? (s.quota.used + ' / ' + s.quota.limit + ' 封（今日）') : '-') + '</div>'
      + '<div class="mc-hint">站内互发即时送达；发往外部邮箱经站方通道中继。' + (s.catchall_enabled ? '外部来信已启用。' : '外部来信暂未启用（管理员未配置 MX/收信邮箱），目前站内邮箱可收站内互发。') + '</div>';
    html += '<div class="mc-sec">绑定自有邮箱</div>';
    if (bound) {
      html += '<div class="mc-kv"><b>邮箱</b>' + esc(bound.email) + '</div>'
        + '<div class="mc-kv"><b>SMTP</b>' + esc(bound.smtp) + '</div>'
        + '<div class="mc-kv"><b>IMAP</b>' + esc(bound.imap) + '</div>'
        + '<div class="mc-kv"><b>绑定时间</b>' + esc(bound.bound_at) + '</div>'
        + '<div class="mc-row" style="margin-top:10px">'
        + '<button class="mc-btn ghost" id="bTest">测试授权码</button>'
        + '<button class="mc-btn ghost" id="bFetch">拉取新邮件</button>'
        + '<button class="mc-btn ghost" id="bUnbind" style="color:#B45309;border-color:#B45309">解绑</button></div>';
    } else {
      html += '<div class="mc-hint" style="margin-bottom:10px">绑定你自己的邮箱后，可以直接用它收发邮件（收信走 IMAP、发信走你自己的 SMTP，不经站方）。QQ 邮箱请使用「授权码」而非登录密码。</div>'
        + '<div class="mc-field"><label>邮箱地址</label><input id="bEmail" placeholder="如 123456789@qq.com"></div>'
        + '<div class="mc-field"><label>授权码</label><input id="bPass" type="password" placeholder="邮箱设置里生成的授权码"></div>'
        + '<div class="mc-field"><label>显示名（可选）</label><input id="bName" placeholder="发信时显示的名字"></div>'
        + '<div class="mc-hint" style="margin-bottom:10px">SMTP/IMAP 服务器按邮箱域名自动识别；特殊服务商可手动填写。</div>'
        + '<div class="mc-field"><label>SMTP 服务器（可选）</label><input id="bSmtpHost" placeholder="如 smtp.qq.com"></div>'
        + '<div class="mc-field"><label>IMAP 服务器（可选）</label><input id="bImapHost" placeholder="如 imap.qq.com"></div>'
        + '<div class="mc-row"><button class="mc-btn" id="bSave">绑定</button></div>';
    }
    var el = document.getElementById('mcSettings'); if(!el){return;}
    el.innerHTML = html;

    if (bound) {
      document.getElementById('bTest').addEventListener('click', function(){
        toast('测试中…');
        api({action:'test_bind'}).then(function(r){ toast(r.success ? r.message : ('失败：' + r.message)); });
      });
      document.getElementById('bFetch').addEventListener('click', function(){
        toast('拉取中…');
        api({action:'fetch', kind:'bound'}).then(function(r){ toast(r.success ? r.message : ('失败：' + r.message)); });
      });
      document.getElementById('bUnbind').addEventListener('click', function(){
        api({action:'unbind'}).then(function(r){ toast(r.message || '已解绑'); loadStatus(); viewSettings(); });
      });
    } else {
      document.getElementById('bSave').addEventListener('click', function(){
        var email = document.getElementById('bEmail').value.trim();
        var pass = document.getElementById('bPass').value;
        if (!email || !pass) { toast('邮箱和授权码都要填'); return; }
        var btn = document.getElementById('bSave');
        btn.disabled = true; btn.textContent = '绑定中…';
        api({action:'bind', email:email, pass:pass,
          display_name: document.getElementById('bName').value.trim(),
          smtp_host: document.getElementById('bSmtpHost').value.trim(),
          imap_host: document.getElementById('bImapHost').value.trim()
        }).then(function(r){
          btn.disabled = false; btn.textContent = '绑定';
          toast(r.message || (r.success ? '绑定成功' : '绑定失败'));
          if (r.success) { loadStatus(); viewSettings(); }
        }).catch(function(){ btn.disabled=false; btn.textContent='绑定'; toast('网络错误'); });
      });
    }
  });
}

function switchTab(tab){
  curTab = tab;
  document.querySelectorAll('#mcTabs .mc-tab').forEach(function(b){
    b.classList.toggle('on', b.getAttribute('data-tab') === tab);
  });
  if (tab === 'inbox') viewInbox();
  else if (tab === 'sent') viewSent();
  else if (tab === 'compose') viewCompose();
  else viewSettings();
}
document.querySelectorAll('#mcTabs .mc-tab').forEach(function(b){
  b.addEventListener('click', function(){ switchTab(b.getAttribute('data-tab')); });
});

function loadStatus(){
  api({action:'status'}).then(function(res){
    STATUS = res.data || null;
    renderStatus();
  });
}
loadStatus();
switchTab('inbox');
</script>
</body>
</html>
