<?php
/**
 * 语言 UI（JS 全局 + 语言切换器）。在页面底部 <body> 关闭前引入：
 *   <?php require_once __DIR__ . '/../includes/lang_ui.php'; ?>
 * 需要当前页面已 require config.php（从而已加载 i18n.php），并在 <head> 提供 $LANG_CODE / $LANG。
 */
?>
<script>
(function () {
  var current = '<?= isset($LANG_CODE) ? $LANG_CODE : 'zh_CN' ?>';
  var dict = <?= isset($LANG) ? json_encode($LANG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}' ?>;
  window.PAGE_LANG = current;
  window.LANG_DICT = dict;
  window.__t = function (k) { return dict[k] || k; };
  if (document.getElementById('langSwitchMounted')) return;
  var host = document.querySelector('.header-actions');
  if (!host) return;
  var langs = [
    { code: 'zh_CN', label: __t('lang.simplified') },
    { code: 'zh_TW', label: __t('lang.traditional') },
    { code: 'en',    label: __t('lang.english') }
  ];
  var wrap = document.createElement('div');
  wrap.className = 'lang-switch';
  wrap.id = 'langSwitchMounted';
  wrap.innerHTML = '<button type="button" class="lang-btn" aria-label="' + __t('lang.switch') + '">'
    + '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>'
    + '<span class="lang-label"></span></button>'
    + '<div class="lang-menu"></div>';
  var label = wrap.querySelector('.lang-label');
  var menu = wrap.querySelector('.lang-menu');
  function render() {
    label.textContent = langs.reduce(function (a, x) { return x.code === current ? x.label : a; }, '');
    menu.innerHTML = '';
    langs.forEach(function (l) {
      var it = document.createElement('a');
      it.href = '#';
      it.textContent = l.label + (l.code === current ? ' ·' : '');
      it.addEventListener('click', function (e) {
        e.preventDefault();
        if (l.code === current) return;
        document.cookie = 'lovewall_lang=' + l.code + ';path=/;max-age=31536000';
        location.reload();
      });
      menu.appendChild(it);
    });
  }
  wrap.querySelector('.lang-btn').addEventListener('click', function (e) {
    e.stopPropagation();
    menu.classList.toggle('open');
  });
  document.addEventListener('click', function () { menu.classList.remove('open'); });
  render();
  host.appendChild(wrap);
})();
</script>
