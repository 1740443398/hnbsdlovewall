<?php
/**
 * 后台：邮箱中心 —— 全站收发审计 / 封禁 / 配置
 *
 * ==========================================================================
 * 为什么单独做一个页面（而不是塞进「邮件系统」）
 * ==========================================================================
 * 「邮件系统」管的是站长自己发出去的系统通知；这里管的是**用户邮箱功能**：
 * 每个用户注册即拥有 <QQ号>@<域名> 站内邮箱，还能绑定自有邮箱收发。
 * 站方必须能看到每一封经站点中继/落库的邮件，发现违规才能精准停用。
 *
 * 三块功能：
 *   1. 审计流水 —— 全站收发逐条记录，可按方向/关键词筛选，可人工标记违规
 *   2. 邮箱账号 —— 全部站内邮箱一览，一键停用/恢复某用户的邮箱功能
 *   3. 站点配置 —— 站内邮箱域名、每日发送配额、外部来信 catch-all
 * ==========================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/mail_center.php';
require_once __DIR__ . '/layout.php';

$admin = requireAdmin();
$admin = checkBanned($admin);
if (($admin['role'] ?? '') !== 'super_admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

$csrfToken = generateCSRFToken();
$cfg = lwMcCfg();

adminHeader('邮箱中心', $admin, $csrfToken);
?>

<div class="page-header">
  <h1>邮箱中心</h1>
  <p class="page-desc">用户站内邮箱收发审计 · 违规处置 · 站点邮箱配置</p>
</div>

<div class="stats-grid" id="mcStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:18px">
  <div class="card" style="padding:14px"><div style="font-size:12px;color:#98A2B3">本月邮件</div><div style="font-size:22px;font-weight:700" id="stTotal">–</div></div>
  <div class="card" style="padding:14px"><div style="font-size:12px;color:#98A2B3">外发</div><div style="font-size:22px;font-weight:700" id="stOut">–</div></div>
  <div class="card" style="padding:14px"><div style="font-size:12px;color:#98A2B3">收信</div><div style="font-size:22px;font-weight:700" id="stIn">–</div></div>
  <div class="card" style="padding:14px"><div style="font-size:12px;color:#98A2B3">风控拦截</div><div style="font-size:22px;font-weight:700;color:#B45309" id="stBlocked">–</div></div>
  <div class="card" style="padding:14px"><div style="font-size:12px;color:#98A2B3">疑似违规</div><div style="font-size:22px;font-weight:700;color:#B45309" id="stFlagged">–</div></div>
  <div class="card" style="padding:14px"><div style="font-size:12px;color:#98A2B3">站内邮箱数</div><div style="font-size:22px;font-weight:700" id="stAccts">–</div></div>
</div>

<div class="card" style="padding:16px;margin-bottom:16px">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <button class="btn" id="tabAudit" style="background:#2F5B9A;color:#fff">审计流水</button>
    <button class="btn" id="tabAccts">邮箱账号</button>
    <button class="btn" id="tabCfg">站点配置</button>
  </div>

  <!-- 审计流水 -->
  <div id="paneAudit">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
      <select id="fDir" style="padding:6px 10px;border:1px solid #E2E8F0;border-radius:8px">
        <option value="">全部方向</option>
        <option value="out">外发</option>
        <option value="in">收信</option>
        <option value="sys">系统事件</option>
      </select>
      <input id="fQ" placeholder="搜主题/地址/正文…" style="padding:6px 10px;border:1px solid #E2E8F0;border-radius:8px;min-width:220px">
      <button class="btn" id="fGo">查询</button>
      <span id="fInfo" style="font-size:12px;color:#98A2B3;align-self:center"></span>
    </div>
    <div style="overflow-x:auto">
      <table style="width:100%;font-size:12.5px;border-collapse:collapse" id="auditTable">
        <thead><tr style="text-align:left;color:#98A2B3">
          <th style="padding:6px 8px">时间</th><th style="padding:6px 8px">用户</th><th style="padding:6px 8px">方向</th>
          <th style="padding:6px 8px">发件人</th><th style="padding:6px 8px">收件人</th><th style="padding:6px 8px">主题</th>
          <th style="padding:6px 8px">状态</th><th style="padding:6px 8px">风控</th><th style="padding:6px 8px">操作</th>
        </tr></thead>
        <tbody id="auditBody"><tr><td colspan="9" style="padding:20px;text-align:center;color:#98A2B3">加载中…</td></tr></tbody>
      </table>
    </div>
    <div id="auditPager" style="margin-top:10px;display:flex;gap:6px;justify-content:center"></div>
  </div>

  <!-- 邮箱账号 -->
  <div id="paneAccts" style="display:none">
    <div style="overflow-x:auto">
      <table style="width:100%;font-size:12.5px;border-collapse:collapse">
        <thead><tr style="text-align:left;color:#98A2B3">
          <th style="padding:6px 8px">站内邮箱</th><th style="padding:6px 8px">用户</th><th style="padding:6px 8px">QQ</th>
          <th style="padding:6px 8px">绑定邮箱</th><th style="padding:6px 8px">开通时间</th><th style="padding:6px 8px">状态</th><th style="padding:6px 8px">操作</th>
        </tr></thead>
        <tbody id="acctBody"><tr><td colspan="7" style="padding:20px;text-align:center;color:#98A2B3">加载中…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- 站点配置 -->
  <div id="paneCfg" style="display:none;max-width:560px">
    <h3 style="margin:0 0 12px;font-size:14px">站内邮箱</h3>
    <div style="margin-bottom:10px">
      <label style="font-size:12.5px;color:#64748B;display:block;margin-bottom:4px">邮箱域名（站内邮箱 = QQ号@域名）</label>
      <input id="cfgDomain" value="<?= htmlspecialchars($cfg['mail_domain']) ?>" style="width:100%;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px">
    </div>
    <div style="margin-bottom:14px">
      <label style="font-size:12.5px;color:#64748B;display:block;margin-bottom:4px">每用户每日外发上限</label>
      <input id="cfgLimit" type="number" min="1" max="200" value="<?= (int)$cfg['daily_limit'] ?>" style="width:120px;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px">
    </div>
    <h3 style="margin:18px 0 12px;font-size:14px">外部来信接收（catch-all）</h3>
    <div style="font-size:12px;color:#98A2B3;line-height:1.7;margin-bottom:10px">
      需要先在域名 DNS 上为 <b><?= htmlspecialchars($cfg['mail_domain']) ?></b> 添加 MX 记录指向你使用的邮箱服务商
      （如腾讯企业邮箱），并把 catch-all 收信邮箱配置到这里。之后站内邮箱即可收到外部来信（IMAP 定时拉取分发）。
    </div>
    <div style="margin-bottom:10px">
      <label style="font-size:12.5px;color:#64748B;display:inline-flex;align-items:center;gap:6px">
        <input type="checkbox" id="cfgCaEnabled" <?= !empty($cfg['catchall']['enabled']) ? 'checked' : '' ?>> 启用外部来信拉取
      </label>
    </div>
    <div style="margin-bottom:10px">
      <label style="font-size:12.5px;color:#64748B;display:block;margin-bottom:4px">IMAP 服务器</label>
      <input id="cfgCaHost" value="<?= htmlspecialchars($cfg['catchall']['imap_host'] ?? '') ?>" placeholder="如 imap.exmail.qq.com" style="width:100%;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px">
    </div>
    <div style="margin-bottom:10px">
      <label style="font-size:12.5px;color:#64748B;display:block;margin-bottom:4px">Catch-all 邮箱账号</label>
      <input id="cfgCaUser" value="<?= htmlspecialchars($cfg['catchall']['imap_user'] ?? '') ?>" style="width:100%;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px">
    </div>
    <div style="margin-bottom:14px">
      <label style="font-size:12.5px;color:#64748B;display:block;margin-bottom:4px">授权码（留空 = 不修改）</label>
      <input id="cfgCaPass" type="password" placeholder="••••••" style="width:100%;padding:8px 12px;border:1px solid #E2E8F0;border-radius:8px">
    </div>
    <button class="btn" id="cfgSave" style="background:#2F5B9A;color:#fff">保存配置</button>
  </div>
</div>

<div class="card" id="mcDetail" style="display:none;padding:16px;margin-top:16px">
  <h3 style="margin:0 0 10px;font-size:14px" id="dTitle">邮件详情</h3>
  <div id="dBody" style="font-size:13px;line-height:1.9;white-space:pre-wrap;word-break:break-word;color:#1F2733"></div>
  <button class="btn" id="dClose" style="margin-top:10px">收起</button>
</div>

<script>
var csrf = <?= json_encode($csrfToken) ?>;
var curMonth = <?= json_encode(date('Ym')) ?>;
var curPage = 1;

function api(payload){
  payload.csrf_token = csrf;
  var f = new FormData();
  for (var k in payload) { f.append(k, payload[k]); }
  return fetch('/api/admin/mail_center.php', {method:'POST', body: f})
    .then(function(r){ return r.json(); });
}
function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
function toast(m){var t=document.createElement('div');t.textContent=m;t.style.cssText='position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#1F2733;color:#fff;padding:9px 16px;border-radius:9px;font-size:13px;z-index:99';document.body.appendChild(t);setTimeout(function(){t.remove()},2400);}

function pane(name){
  document.getElementById('paneAudit').style.display = name==='audit'?'':'none';
  document.getElementById('paneAccts').style.display = name==='accts'?'':'none';
  document.getElementById('paneCfg').style.display = name==='cfg'?'':'none';
  document.getElementById('tabAudit').style.background = name==='audit'?'#2F5B9A':'';
  document.getElementById('tabAudit').style.color = name==='audit'?'#fff':'';
  document.getElementById('tabAccts').style.background = name==='accts'?'#2F5B9A':'';
  document.getElementById('tabAccts').style.color = name==='accts'?'#fff':'';
  document.getElementById('tabCfg').style.background = name==='cfg'?'#2F5B9A':'';
  document.getElementById('tabCfg').style.color = name==='cfg'?'#fff':'';
  if (name==='accts') loadAccts();
}
document.getElementById('tabAudit').onclick = function(){pane('audit');};
document.getElementById('tabAccts').onclick = function(){pane('accts');};
document.getElementById('tabCfg').onclick = function(){pane('cfg');};
document.getElementById('fGo').onclick = function(){curPage=1;loadAudit();};
document.getElementById('fQ').addEventListener('keydown', function(e){ if(e.key==='Enter'){curPage=1;loadAudit();} });

function loadStats(){
  api({action:'stats'}).then(function(res){
    if(!res.success) return;
    var s = res.data.stats;
    document.getElementById('stTotal').textContent = s.total;
    document.getElementById('stOut').textContent = s.out;
    document.getElementById('stIn').textContent = s.in;
    document.getElementById('stBlocked').textContent = s.blocked;
    document.getElementById('stFlagged').textContent = s.flagged;
    document.getElementById('stAccts').textContent = s.accounts;
  });
}

function dirLabel(d){ return d==='out'?'外发':(d==='in'?'收信':'系统'); }
function statusLabel(r){
  var st = r.status||'';
  if (st==='ok') return '<span style="color:#1E7A4C">成功</span>';
  if (st==='failed') return '<span style="color:#B45309">失败</span>';
  if (st==='blocked') return '<span style="color:#DC2626">已拦截</span>';
  return esc(st);
}

function loadAudit(){
  var tb = document.getElementById('auditBody');
  tb.innerHTML = '<tr><td colspan="9" style="padding:20px;text-align:center;color:#98A2B3">加载中…</td></tr>';
  api({action:'list', dir:document.getElementById('fDir').value, q:document.getElementById('fQ').value, page:curPage, month:curMonth}).then(function(res){
    if (!res.success) { tb.innerHTML='<tr><td colspan="9" style="padding:16px;color:#B45309">'+esc(res.message)+'</td></tr>'; return; }
    var list = res.data.list||[];
    document.getElementById('fInfo').textContent = '共 ' + res.data.total + ' 条 · 第 ' + res.data.page + '/' + res.data.pages + ' 页';
    if (!list.length) { tb.innerHTML='<tr><td colspan="9" style="padding:20px;text-align:center;color:#98A2B3">没有记录</td></tr>'; document.getElementById('auditPager').innerHTML=''; return; }
    tb.innerHTML = list.map(function(r){
      var key = r.ts + '-' + (r.uid||0) + '-' + (r.dir||'');
      return '<tr style="border-top:1px solid #F1F5F9">'
        + '<td style="padding:6px 8px;white-space:nowrap">' + esc(new Date((r.ts||0)*1000).toLocaleString('zh-CN')) + '</td>'
        + '<td style="padding:6px 8px;white-space:nowrap">' + esc(r.nickname||('uid'+r.uid)) + '</td>'
        + '<td style="padding:6px 8px">' + esc(dirLabel(r.dir)) + '</td>'
        + '<td style="padding:6px 8px;word-break:break-all">' + esc(r.from||r.addr||'-') + '</td>'
        + '<td style="padding:6px 8px;word-break:break-all">' + esc(r.to||'-') + '</td>'
        + '<td style="padding:6px 8px;word-break:break-all"><a href="#" class="subj-link" data-key="'+esc(key)+'" data-row="'+esc(JSON.stringify(r))+'">' + esc(r.subject||'(无主题)') + '</a></td>'
        + '<td style="padding:6px 8px">' + statusLabel(r) + '</td>'
        + '<td style="padding:6px 8px">' + (r.risk && r.risk!=='none' ? '<span style="color:#B45309">疑似</span>' : '-') + '</td>'
        + '<td style="padding:6px 8px;white-space:nowrap">'
        + '<button class="flagBtn btn" data-key="'+esc(key)+'" style="font-size:11px;padding:3px 8px;margin-right:4px;' + (r.flagged?'background:#B45309;color:#fff':'') + '">' + (r.flagged?'已标记':'标违规') + '</button>'
        + (r.dir==='out' && r.uid ? '<button class="banBtn btn" data-uid="'+r.uid+'" style="font-size:11px;padding:3px 8px">封邮箱</button>' : '')
        + '</td></tr>';
    }).join('');
    bindAuditRows();
    var pager = '';
    if (res.data.pages > 1) {
      for (var p=1; p<=res.data.pages; p++) {
        pager += '<button class="btn pgBtn" data-p="'+p+'" style="font-size:12px;padding:4px 10px;' + (p===res.data.page?'background:#2F5B9A;color:#fff':'') + '">' + p + '</button>';
      }
    }
    document.getElementById('auditPager').innerHTML = pager;
    document.querySelectorAll('.pgBtn').forEach(function(b){
      b.onclick = function(){ curPage = parseInt(b.getAttribute('data-p')); loadAudit(); };
    });
  });
}

function bindAuditRows(){
  document.querySelectorAll('.subj-link').forEach(function(a){
    a.onclick = function(e){
      e.preventDefault();
      var r = JSON.parse(a.getAttribute('data-row'));
      document.getElementById('mcDetail').style.display='';
      document.getElementById('dTitle').textContent = r.subject || '(无主题)';
      var d = document.getElementById('dBody');
      d.textContent = '';
      [['用户', (r.nickname||'uid'+r.uid)+'（'+r.addr+'）'],
       ['方向', dirLabel(r.dir)+' · 通道 '+ (r.transport||'-')],
       ['发件人', r.from||'-'], ['收件人', r.to||'-'],
       ['状态', r.status + (r.reason? '（'+r.reason+'）':'')],
       ['风控命中', (r.risk==='none'?'无':r.risk)]
      ].forEach(function(kv){
        var p = document.createElement('div');
        p.style.cssText='color:#64748B;font-size:12px';
        p.textContent = kv[0] + '：' + kv[1];
        d.appendChild(p);
      });
      if (r.text) { var hr=document.createElement('hr'); hr.style.cssText='border:none;border-top:1px solid #E2E8F0;margin:10px 0'; d.appendChild(hr);
        var txt=document.createElement('div'); txt.textContent=r.text; d.appendChild(txt); }
      document.getElementById('mcDetail').scrollIntoView({behavior:'smooth'});
    };
  });
  document.querySelectorAll('.flagBtn').forEach(function(b){
    b.onclick = function(){
      api({action:'flag', key:b.getAttribute('data-key')}).then(function(r){ toast(r.message||'已更新'); loadAudit(); loadStats(); });
    };
  });
  document.querySelectorAll('.banBtn').forEach(function(b){
    b.onclick = function(){
      var reason = prompt('停用该用户邮箱功能的理由（将展示给用户）：');
      if (reason === null) return;
      api({action:'ban', uid:b.getAttribute('data-uid'), reason:reason||''}).then(function(r){ toast(r.message||'已处理'); loadAccts(); });
    };
  });
}

function loadAccts(){
  var tb = document.getElementById('acctBody');
  tb.innerHTML = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#98A2B3">加载中…</td></tr>';
  api({action:'accounts'}).then(function(res){
    if (!res.success) { tb.innerHTML='<tr><td colspan="7" style="padding:16px;color:#B45309">'+esc(res.message)+'</td></tr>'; return; }
    var list = res.data.list||[];
    if (!list.length) { tb.innerHTML='<tr><td colspan="7" style="padding:20px;text-align:center;color:#98A2B3">还没有用户开通站内邮箱（新注册用户自动开通）</td></tr>'; return; }
    tb.innerHTML = list.map(function(a){
      return '<tr style="border-top:1px solid #F1F5F9">'
        + '<td style="padding:6px 8px;white-space:nowrap">' + esc(a.site_addr) + '</td>'
        + '<td style="padding:6px 8px;white-space:nowrap">' + esc(a.nickname) + '</td>'
        + '<td style="padding:6px 8px">' + esc(a.qq) + '</td>'
        + '<td style="padding:6px 8px;word-break:break-all">' + (a.bound_email ? esc(a.bound_email) : '<span style="color:#98A2B3">未绑定</span>') + '</td>'
        + '<td style="padding:6px 8px;white-space:nowrap">' + esc(a.created_at) + '</td>'
        + '<td style="padding:6px 8px">' + (a.banned ? '<span style="color:#DC2626">已停用'+(a.ban_reason?'（'+esc(a.ban_reason)+'）':'')+'</span>' : '<span style="color:#1E7A4C">正常</span>') + '</td>'
        + '<td style="padding:6px 8px"><button class="acctBanBtn btn" data-uid="'+a.uid+'" data-banned="'+(a.banned?1:0)+'" style="font-size:11px;padding:3px 8px;' + (a.banned?'color:#1E7A4C;border-color:#1E7A4C':'color:#DC2626;border-color:#DC2626') + '">' + (a.banned?'恢复':'停用') + '</button></td></tr>';
    }).join('');
    document.querySelectorAll('.acctBanBtn').forEach(function(b){
      b.onclick = function(){
        var banned = b.getAttribute('data-banned')==='1';
        if (!banned) {
          var reason = prompt('停用理由（将展示给用户）：');
          if (reason === null) return;
          api({action:'ban', uid:b.getAttribute('data-uid'), reason:reason||''}).then(function(r){ toast(r.message||'已处理'); loadAccts(); loadStats(); });
        } else {
          if (!confirm('确认恢复该用户的邮箱功能？')) return;
          api({action:'unban', uid:b.getAttribute('data-uid')}).then(function(r){ toast(r.message||'已处理'); loadAccts(); });
        }
      };
    });
  });
}

document.getElementById('cfgSave').onclick = function(){
  api({action:'save_cfg',
    mail_domain: document.getElementById('cfgDomain').value.trim(),
    daily_limit: document.getElementById('cfgLimit').value,
    catchall_enabled: document.getElementById('cfgCaEnabled').checked?'1':'0',
    catchall_imap_host: document.getElementById('cfgCaHost').value.trim(),
    catchall_imap_user: document.getElementById('cfgCaUser').value.trim(),
    catchall_imap_pass: document.getElementById('cfgCaPass').value
  }).then(function(r){ toast(r.message||'已保存'); });
};

document.getElementById('dClose').onclick = function(){ document.getElementById('mcDetail').style.display='none'; };

loadStats();
loadAudit();
</script>

<?php adminFooter(); ?>
