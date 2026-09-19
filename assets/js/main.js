(function () {
  'use strict';

  // 兼容两种声明方式：
  // 页面用 <script>const IS_LOGGED_IN=...</script> 时，const 只生成脚本级绑定，不会挂到 window 上，
  // 而其他脚本（如 enhancements.js）读取 window.IS_LOGGED_IN。因此这里显式把真实值同步到 window，
  // 确保工具脚本能拿到正确的登录态，避免“已登录仍提示未登录”。
  window.IS_LOGGED_IN = (typeof IS_LOGGED_IN !== 'undefined') ? !!IS_LOGGED_IN : false;
  window.USER_DATA = (typeof USER_DATA !== 'undefined') ? USER_DATA : null;
  window.CSRF_TOKEN = (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : '';
  window.USER_THEME = (typeof USER_THEME !== 'undefined') ? USER_THEME : '';
  window.SITE_URL = (typeof SITE_URL !== 'undefined') ? SITE_URL : '';

  async function fetchAPI(url, options = {}) {
    const defaultOptions = {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    };
    const mergedOptions = {
      ...defaultOptions,
      ...options,
      headers: { ...defaultOptions.headers, ...(options.headers || {}) },
    };

    const method = (mergedOptions.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
      if (!(mergedOptions.body instanceof FormData)) {
        mergedOptions.headers['Content-Type'] =
          mergedOptions.headers['Content-Type'] || 'application/x-www-form-urlencoded';
      }

      if (mergedOptions.body instanceof FormData) {
        if (!mergedOptions.body.has('csrf_token')) {
          const csrfToken = getCSRFToken();
          if (csrfToken) {
            mergedOptions.body.append('csrf_token', csrfToken);
          }
        }
      } else if (typeof mergedOptions.body === 'string') {
        const csrfToken = getCSRFToken();
        if (csrfToken && !mergedOptions.body.includes('csrf_token=')) {
          const separator = mergedOptions.body ? '&' : '';
          mergedOptions.body += separator + 'csrf_token=' + encodeURIComponent(csrfToken);
        }
      } else if (!mergedOptions.body) {
        const csrfToken = getCSRFToken();
        if (csrfToken) {
          mergedOptions.body = 'csrf_token=' + encodeURIComponent(csrfToken);
        }
      }
    }

    let response;
    try {
      response = await fetch(SITE_URL + url, mergedOptions);
    } catch (err) {
      showToast('网络错误，请检查网络连接', 'error');
      throw err;
    }

    if (!response.ok) {
      if (response.status === 401) {
        showToast('请先登录', 'warning');
        setTimeout(() => {
          window.location.href = SITE_URL + '/pages/login.php';
        }, 1000);
        throw new Error('Unauthorized');
      }
      if (response.status === 403) {
        showToast('权限不足', 'error');
        throw new Error('Forbidden');
      }
      if (response.status === 429) {
        showToast('操作过于频繁，请稍后再试', 'warning');
        throw new Error('Rate limited');
      }
    }

    let data;
    try {
      data = await response.json();
    } catch (err) {
      showToast('服务器响应异常', 'error');
      throw err;
    }

    if (!data.success) {
      throw new Error(data.message || '操作失败');
    }
    return data;
  }

  function getCSRFToken() {
    if (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) return CSRF_TOKEN;
    const input = document.querySelector('input[name="csrf_token"]');
    if (input) return input.value;
    return localStorage.getItem('csrf_token') || '';
  }

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func.apply(this, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  function throttle(func, wait) {
    let timeout = null;
    let previous = 0;
    return function (...args) {
      const now = Date.now();
      const remaining = wait - (now - previous);
      if (remaining <= 0 || remaining > wait) {
        if (timeout) {
          clearTimeout(timeout);
          timeout = null;
        }
        previous = now;
        func.apply(this, args);
      } else if (!timeout) {
        timeout = setTimeout(() => {
          previous = Date.now();
          timeout = null;
          func.apply(this, args);
        }, remaining);
      }
    };
  }

  function formatTime(dateString) {
    if (!dateString) return '';
    const now = new Date();
    const date = new Date(String(dateString).replace(/-/g, '/'));
    const diff = Math.floor((now - date) / 1000);
    if (isNaN(diff)) return dateString;
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 604800) return Math.floor(diff / 86400) + '天前';
    if (diff < 2592000) return Math.floor(diff / 604800) + '周前';
    if (diff < 31536000) return Math.floor(diff / 2592000) + '个月前';
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    const h = String(date.getHours()).padStart(2, '0');
    const min = String(date.getMinutes()).padStart(2, '0');
    return y + '-' + m + '-' + d + ' ' + h + ':' + min;
  }

  function escapeHtml(str) {
    if (str === null || str === undefined || str === '') return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
  }

  window.esc = escapeHtml;

  async function copyToClipboard(text) {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
      }
      showToast('已复制到剪贴板', 'success');
      return true;
    } catch (err) {
      showToast('复制失败', 'error');
      return false;
    }
  }

  let toastIdCounter = 0;

  function renderUserTitle(author) {
    if (!author || !author.title_text) return '';
    let style =
      'display:inline-block;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-left:6px;vertical-align:middle;';
    if (author.title_rainbow == 1) {
      const gs = author.title_gradient_start || '#ff4757';
      const ge = author.title_gradient_end || '#a55eea';
      style +=
        'background:linear-gradient(90deg,' + gs + ',' + ge + ',' + gs + ');background-size:200% 100%;animation:titleRainbow 2s linear infinite;color:' + (author.title_color || '#fff') + ';';
    } else {
      style +=
        'background:' + (author.title_bg_color || '#4A90D9') + ';color:' + (author.title_color || '#ffffff') + ';';
    }
    return '<span style="' + style + '">' + escapeHtml(author.title_text) + '</span>';
  }

  let _svgUid = 0;
  function svgIcon(name, size) {
    size = size || 16;
    _svgUid++;
    var uid = 'sg' + _svgUid;
    var icons = {
      check:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#34D058"/><stop offset="100%" stop-color="#28A745"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' +
        uid +
        ')"/><polyline points="7 12.5 10.5 16 17 8.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      x:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" fill="#f0f2f5"/><line x1="8" y1="8" x2="16" y2="16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><line x1="16" y1="8" x2="8" y2="16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>',
      alert:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#FF9800"/></linearGradient></defs><path d="M12 2 L22 20 L2 20 Z" fill="url(#' +
        uid +
        ')"/><line x1="12" y1="9" x2="12" y2="14" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="17" r="1.2" fill="#fff"/></svg>',
      info:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#2196F3"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' +
        uid +
        ')"/><circle cx="12" cy="7.5" r="1.4" fill="#fff"/><line x1="12" y1="11" x2="12" y2="17" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
      heart:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF6B9D"/><stop offset="100%" stop-color="#E91E63"/></linearGradient></defs><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="url(#' +
        uid +
        ')"/></svg>',
      heartOutline:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF6B9D"/><stop offset="100%" stop-color="#E91E63"/></linearGradient></defs><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linejoin="round"/></svg>',
      message:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#5BC0EB"/><stop offset="100%" stop-color="#3A8EE6"/></linearGradient></defs><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" fill="url(#' +
        uid +
        ')"/></svg>',
      star:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FFA000"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="url(#' +
        uid +
        ')" stroke="#FFA000" stroke-width="0.5" stroke-linejoin="round"/></svg>',
      starOutline:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FFA000"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linejoin="round"/></svg>',
      copy:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#A0AEC0"/><stop offset="100%" stop-color="#718096"/></linearGradient></defs><rect x="9" y="9" width="13" height="13" rx="2.5" fill="url(#' +
        uid +
        ')" stroke="#fff" stroke-width="0.5"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      pin:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF8A65"/><stop offset="100%" stop-color="#E64A19"/></linearGradient></defs><path d="M9 4h6v6l4 4H5l4-4V4z" fill="url(#' +
        uid +
        ')"/><line x1="12" y1="14" x2="12" y2="22" stroke="url(#' +
        uid +
        ')" stroke-width="2.5" stroke-linecap="round"/></svg>',
      eye:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="url(#' +
        uid +
        ')" opacity="0.15"/><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3.2" fill="url(#' +
        uid +
        ')"/></svg>',
      eyeOff:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#E57373"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/></svg>',
      sun:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FF9800"/></linearGradient><radialGradient id="' +
        uid +
        'r"><stop offset="0%" stop-color="#FFF59D"/><stop offset="100%" stop-color="#FFC107"/></radialGradient></defs><circle cx="12" cy="12" r="4.5" fill="url(#' +
        uid +
        'r)"/><g stroke="url(#' +
        uid +
        ')" stroke-width="2.2" stroke-linecap="round"><line x1="12" y1="1.5" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22.5"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1.5" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22.5" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></g></svg>',
      moon:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#90CAF9"/><stop offset="100%" stop-color="#3949AB"/></linearGradient></defs><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" fill="url(#' +
        uid +
        ')"/><circle cx="15" cy="9" r="0.8" fill="#fff" opacity="0.7"/><circle cx="17.5" cy="13" r="0.6" fill="#fff" opacity="0.5"/></svg>',
      inbox:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><polyline points="22 12 16 12 14 15 10 15 8 12 2 12" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      paperclip:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#81D4FA"/><stop offset="100%" stop-color="#0288D1"/></linearGradient></defs><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      alertCircle:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' +
        uid +
        ')"/><line x1="12" y1="7" x2="12" y2="13" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><circle cx="12" cy="16.5" r="1.2" fill="#fff"/></svg>',
      trash:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><polyline points="3 6 5 6 21 6" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      edit:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" fill="url(#' +
        uid +
        ')" opacity="0.85"/></svg>',
      user:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#7C8DA4"/><stop offset="100%" stop-color="#4A5568"/></linearGradient></defs><circle cx="12" cy="8" r="4.5" fill="url(#' +
        uid +
        ')"/><path d="M3.5 21c0-4.69 4.7-8 8.5-8s8.5 3.31 8.5 8" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/></svg>',
      search:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#5BC0EB"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="11" cy="11" r="7" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/><line x1="16.5" y1="16.5" x2="21" y2="21" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/></svg>',
      settings:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#90A4AE"/><stop offset="100%" stop-color="#455A64"/></linearGradient></defs><circle cx="12" cy="12" r="3" fill="url(#' +
        uid +
        ')"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      bell:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" fill="url(#' +
        uid +
        ')" opacity="0.9"/><path d="M13.73 21a2 2 0 0 1-3.46 0" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/></svg>',
      send:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><line x1="22" y1="2" x2="11" y2="13" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polygon points="22 2 15 22 11 13 2 9 22 2" fill="url(#' +
        uid +
        ')" opacity="0.9"/></svg>',
      image:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#81D4FA"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><rect x="3" y="3" width="18" height="18" rx="2" ry="2" fill="url(#' +
        uid +
        ')" opacity="0.2"/><circle cx="8.5" cy="8.5" r="1.8" fill="url(#' +
        uid +
        ')"/><polyline points="21 15 16 10 5 21" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="3" width="18" height="18" rx="2" ry="2" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2"/></svg>',
      logout:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="16 17 21 12 16 7" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="21" y1="12" x2="9" y2="12" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/></svg>',
      lock:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><rect x="3" y="11" width="18" height="11" rx="2.5" fill="url(#' +
        uid +
        ')"/><path d="M7 11V7a5 5 0 0 1 10 0v4" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/><circle cx="12" cy="16" r="1.5" fill="#fff"/></svg>',
      tag:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" fill="url(#' +
        uid +
        ')" opacity="0.9"/><line x1="7" y1="7" x2="7.01" y2="7" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg>',
      home:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="url(#' +
        uid +
        ')"/><polyline points="9 22 9 12 15 12 15 22" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      plus:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' +
        uid +
        ')"/><line x1="12" y1="7" x2="12" y2="17" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><line x1="7" y1="12" x2="17" y2="12" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/></svg>',
      back:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><line x1="12" y1="19" x2="12" y2="5" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round"/><polyline points="5 12 12 5 19 12" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      trophy:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FF8F00"/></linearGradient></defs><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18" fill="none" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/><path d="M6 4h12v6a6 6 0 0 1-12 0z" fill="url(#' +
        uid +
        ')"/><path d="M9 21h6" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/><path d="M12 17v4" stroke="url(#' +
        uid +
        ')" stroke-width="2" stroke-linecap="round"/></svg>',
      shield:
        '<svg width="' +
        size +
        '" height="' +
        size +
        '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' +
        uid +
        '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" fill="url(#' +
        uid +
        ')" opacity="0.9"/><polyline points="9 12 11 14 15 10" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      edit:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      arrowUp:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' + uid + ')"/><line x1="12" y1="17" x2="12" y2="7" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><polyline points="7 11 12 6 17 11" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      dice:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><rect x="3" y="3" width="18" height="18" rx="3" fill="url(#' + uid + ')"/><circle cx="8" cy="8" r="1.5" fill="#fff"/><circle cx="16" cy="16" r="1.5" fill="#fff"/><circle cx="8" cy="16" r="1.5" fill="#fff"/><circle cx="16" cy="8" r="1.5" fill="#fff"/></svg>',
      bookmark:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFD54F"/><stop offset="100%" stop-color="#FF8F00"/></linearGradient></defs><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      mic:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" fill="url(#' + uid + ')"/><path d="M19 10v2a7 7 0 0 1-14 0v-2" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="19" x2="12" y2="23" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      share:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="18" cy="5" r="3" fill="url(#' + uid + ')"/><circle cx="6" cy="12" r="3" fill="url(#' + uid + ')"/><circle cx="18" cy="19" r="3" fill="url(#' + uid + ')"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      users:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><circle cx="9" cy="7" r="4" fill="url(#' + uid + ')"/><path d="M23 21v-2a4 4 0 0 0-3-3.87" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><path d="M16 3.13a4 4 0 0 1 0 7.75" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      fire:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FF6B6B"/><stop offset="100%" stop-color="#E91E63"/></linearGradient></defs><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z" fill="url(#' + uid + ')"/></svg>',
      calendar:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><rect x="3" y="4" width="18" height="18" rx="2" fill="url(#' + uid + ')" opacity="0.9"/><line x1="16" y1="2" x2="16" y2="6" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="2" x2="8" y2="6" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="10" x2="21" y2="10" stroke="#fff" stroke-width="2"/><text x="12" y="17" text-anchor="middle" fill="#fff" font-size="6" font-weight="bold">签到</text></svg>',
      compass:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' + uid + ')" opacity="0.9"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76" fill="#fff"/><circle cx="12" cy="12" r="1" fill="url(#' + uid + ')"/></svg>',
      userPlus:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><circle cx="8.5" cy="7" r="4" fill="url(#' + uid + ')"/><line x1="20" y1="8" x2="20" y2="14" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="17" y1="11" x2="23" y2="11" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      refresh:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><polyline points="23 4 23 10 17 10" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="1 20 1 14 7 14" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      music:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><path d="M9 18V5l12-2v13" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><circle cx="6" cy="18" r="3" fill="url(#' + uid + ')"/><circle cx="18" cy="16" r="3" fill="url(#' + uid + ')"/></svg>',
      game:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><rect x="2" y="6" width="20" height="12" rx="2" fill="url(#' + uid + ')" opacity="0.9"/><line x1="6" y1="12" x2="10" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="10" x2="8" y2="14" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="15" cy="11" r="1.5" fill="#fff"/><circle cx="18" cy="10" r="1.5" fill="#fff"/><circle cx="15" cy="14" r="1.5" fill="#fff"/></svg>',
      film:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><rect x="2" y="2" width="20" height="20" rx="2" fill="url(#' + uid + ')" opacity="0.9"/><polygon points="10 8 16 12 10 16 10 8" fill="#fff"/></svg>',
      book:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" fill="url(#' + uid + ')" opacity="0.85"/></svg>',
      download:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="7 10 12 15 17 10" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="15" x2="12" y2="3" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      upload:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="17 8 12 3 7 8" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="3" x2="12" y2="15" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      clock:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' + uid + ')" opacity="0.9"/><polyline points="12 6 12 12 16 14" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
      settings:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><circle cx="12" cy="12" r="3" fill="url(#' + uid + ')"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" fill="none" stroke="url(#' + uid + ')" stroke-width="2"/></svg>',
      mail:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#FFB74D"/><stop offset="100%" stop-color="#F57C00"/></linearGradient></defs><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" fill="url(#' + uid + ')" opacity="0.9"/><polyline points="22 6 12 13 2 6" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>',
      mapPin:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#EF5350"/><stop offset="100%" stop-color="#C62828"/></linearGradient></defs><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" fill="url(#' + uid + ')"/><circle cx="12" cy="10" r="3" fill="#fff"/></svg>',
      link:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      phone:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#66BB6A"/><stop offset="100%" stop-color="#2E7D32"/></linearGradient></defs><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.08 4.18 2 2 0 0 1 4.08 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      code:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><polyline points="16 18 22 12 16 6" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="8 6 2 12 8 18" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      grid:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><rect x="3" y="3" width="7" height="7" rx="1" fill="url(#' + uid + ')"/><rect x="14" y="3" width="7" height="7" rx="1" fill="url(#' + uid + ')"/><rect x="3" y="14" width="7" height="7" rx="1" fill="url(#' + uid + ')"/><rect x="14" y="14" width="7" height="7" rx="1" fill="url(#' + uid + ')"/></svg>',
      fullscreen:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><polyline points="15 3 21 3 21 9" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><polyline points="9 21 3 21 3 15" fill="none" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="21" y1="3" x2="14" y2="10" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="3" y1="21" x2="10" y2="14" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      thumbsUp:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      filter:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#BA68C8"/><stop offset="100%" stop-color="#7B1FA2"/></linearGradient></defs><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" fill="url(#' + uid + ')" opacity="0.9"/></svg>',
      sort:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#B0BEC5"/><stop offset="100%" stop-color="#607D8B"/></linearGradient></defs><line x1="4" y1="6" x2="16" y2="6" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="4" y1="12" x2="12" y2="12" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/><line x1="4" y1="18" x2="20" y2="18" stroke="url(#' + uid + ')" stroke-width="2" stroke-linecap="round"/></svg>',
      globe:
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"><defs><linearGradient id="' + uid + '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#4FC3F7"/><stop offset="100%" stop-color="#1976D2"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#' + uid + ')" opacity="0.9"/><line x1="2" y1="12" x2="22" y2="12" stroke="#fff" stroke-width="1.5"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" fill="none" stroke="#fff" stroke-width="1.5"/></svg>',
    };
    return icons[name] || icons.info;
  }

  function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const id = 'toast-' + ++toastIdCounter;
    const iconMap = { success: 'check', error: 'x', warning: 'alert', info: 'info' };
    const iconSvg = svgIcon(iconMap[type] || 'info', 18);
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.id = id;
    toast.style.position = 'relative';
    toast.style.overflow = 'hidden';
    toast.innerHTML =
      '<span class="toast-icon">' + iconSvg + '</span><span class="toast-message">' + escapeHtml(message) + '</span>';
    const bar = document.createElement('div');
    bar.className = 'toast-progress';
    toast.appendChild(bar);
    container.appendChild(toast);
    const dismissTimeout = setTimeout(() => {
      dismissToast(toast);
    }, 3000);
    toast.addEventListener('click', () => {
      clearTimeout(dismissTimeout);
      dismissToast(toast);
    });
    return id;
  }

  function dismissToast(toast) {
    if (!toast || toast.classList.contains('toast-dismissing')) return;
    toast.classList.add('toast-dismissing');
    toast.addEventListener(
      'animationend',
      () => {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      },
      { once: true },
    );

    setTimeout(() => {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 350);
  }

  function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const theme = savedTheme || USER_THEME || 'light';
    applyTheme(theme);
  }

  function applyTheme(theme) {
    document.body.classList.add('theme-transitioning');
    document.body.classList.remove('light-theme', 'dark-theme');
    document.body.classList.add(theme + '-theme');
    localStorage.setItem('theme', theme);
    setTimeout(() => {
      document.body.classList.remove('theme-transitioning');
    }, 350);
  }

  function toggleTheme() {
    const current = document.body.classList.contains('dark-theme') ? 'dark' : 'light';
    const newTheme = current === 'dark' ? 'light' : 'dark';
    applyTheme(newTheme);
    if (IS_LOGGED_IN) {
      fetchAPI('/api/user/update_theme.php', { method: 'POST', body: 'theme=' + newTheme }).catch(() => {});
    }
  }

  function bindThemeToggle() {
    const btn = document.getElementById('themeToggle');
    if (btn) {
      btn.addEventListener('click', toggleTheme);
    }
  }

  function injectThemeToggle() {
    if (document.getElementById('themeToggle')) return;
    const dropdown = document.getElementById('userDropdown');
    if (!dropdown) return;
    if (document.getElementById('themeToggleMenuItem')) return;
    const themeItem = document.createElement('button');
    themeItem.className = 'dropdown-item';
    themeItem.id = 'themeToggleMenuItem';
    themeItem.innerHTML = svgIcon('sun', 16) + ' 切换主题';
    themeItem.addEventListener('click', toggleTheme);
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn && logoutBtn.parentNode) {
      logoutBtn.parentNode.insertBefore(themeItem, logoutBtn);
    } else {
      dropdown.appendChild(themeItem);
    }
  }

  const PostFeed = {
    container: null,
    loadMoreBtn: null,
    loadMoreWrap: null,
    noMoreEl: null,
    currentCategory: 'all',
    currentSort: 'latest',
    currentPage: 1,
    hasMore: true,
    isLoading: false,
    searchKeyword: '',
    _lastPollTime: null,
    _pollTimer: null,

    init() {
      this.container = document.getElementById('postsContainer');
      this.loadMoreBtn = document.getElementById('loadMoreBtn');
      this.loadMoreWrap = document.getElementById('loadMore');
      this.noMoreEl = document.getElementById('noMore');
      if (!this.container) return;

      const hash = window.location.hash.replace('#', '');
      if (hash) {
        this.currentCategory = hash;
        this.updateCategoryButtons();
      }
      this.bindCategoryFilters();
      this.bindSortTabs();
      this.bindLoadMore();
      this.bindInfiniteScroll();
      this.loadPosts(true);
      this.startPolling();
    },

    startPolling() {
      if (!this.container) return;
      this._lastPollTime = new Date().toISOString();
      this._pollTimer = setInterval(() => {
        this.doPoll();
      }, 30000);
    },

    getVisiblePostIds() {
      const ids = [];
      document.querySelectorAll('.post-card[data-post-id]').forEach((card) => {
        ids.push(card.getAttribute('data-post-id'));
      });
      return ids;
    },

    async doPoll() {
      const ids = this.getVisiblePostIds();
      if (ids.length === 0) return;
      try {
        const url = '/api/posts/poll.php?ids=' + ids.join(',') + '&since=' + encodeURIComponent(this._lastPollTime);
        const data = await fetchAPI(url);
        this._lastPollTime = new Date().toISOString();
        if (data.data && data.data.posts) {
          for (const [postId, stats] of Object.entries(data.data.posts)) {
            this.updatePostStats(postId, stats);
          }
        }
        if (data.data && data.data.new_comments) {
          for (const [postId, comments] of Object.entries(data.data.new_comments)) {
            this.addNewCommentsInline(postId, comments);
          }
        }
      } catch (err) {}
    },

    updatePostStats(postId, stats) {
      const card = this.container.querySelector('.post-card[data-post-id="' + postId + '"]');
      if (!card) return;
      const likeCount = card.querySelector('.like-btn .action-count');
      const commentCount = card.querySelector('.comment-btn .action-count');
      if (likeCount && stats.like_count !== undefined) {
        likeCount.textContent = stats.like_count;
      }
      if (commentCount && stats.comment_count !== undefined) {
        commentCount.textContent = stats.comment_count;
      }
    },

    addNewCommentsInline(postId, comments) {
      if (!comments || !comments.length) return;
      const card = this.container.querySelector('.post-card[data-post-id="' + postId + '"]');
      if (!card) return;
      let commentPanel = card.querySelector('.inline-comments-panel');
      if (!commentPanel) {
        commentPanel = document.createElement('div');
        commentPanel.className = 'inline-comments-panel';
        commentPanel.style.cssText =
          'margin-top:8px;padding-top:8px;border-top:1px solid var(--border);max-height:200px;overflow-y:auto;';
        card.appendChild(commentPanel);
      }
      comments.forEach((c) => {
        if (commentPanel.querySelector('.inline-comment[data-comment-id="' + c.id + '"]')) return;
        const authorName = c.is_anonymous ? '匿名用户' : c.author ? c.author.nickname : '匿名用户';
        const commentEl = document.createElement('div');
        commentEl.className = 'inline-comment';
        commentEl.setAttribute('data-comment-id', c.id);
        commentEl.style.cssText =
          'padding:6px 0;font-size:13px;border-bottom:1px solid var(--border-light);animation:fadeInUp 0.3s ease;';
        commentEl.innerHTML =
          '<span style="font-weight:600;color:var(--primary);">' +
          escapeHtml(authorName) +
          '</span>' +
          '<span style="color:var(--text-secondary);margin-left:8px;">' +
          escapeHtml(c.content) +
          '</span>' +
          '<span style="color:var(--text-muted);font-size:11px;float:right;">' +
          formatTime(c.created_at) +
          '</span>';
        commentPanel.appendChild(commentEl);
      });
    },

    bindCategoryFilters() {
      const buttons = document.querySelectorAll('.cat-filter');
      buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
          const cat = btn.dataset.cat;
          if (cat === this.currentCategory) return;
          this.currentCategory = cat;
          this.currentPage = 1;
          this.hasMore = true;
          window.location.hash = cat === 'all' ? '' : cat;
          this.updateCategoryButtons();
          this.loadPosts(true);
        });
      });
    },

    updateCategoryButtons() {
      document.querySelectorAll('.cat-filter').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.cat === this.currentCategory);
      });
    },

    bindSortTabs() {
      const tabs = document.querySelectorAll('.sort-tab');
      tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          const sort = tab.dataset.sort;
          if (sort === this.currentSort) return;
          this.currentSort = sort;
          this.currentPage = 1;
          this.hasMore = true;
          tabs.forEach((t) => t.classList.toggle('active', t.dataset.sort === sort));
          this.loadPosts(true);
        });
      });
    },

    bindLoadMore() {
      if (this.loadMoreBtn) {
        this.loadMoreBtn.addEventListener('click', () => {
          this.loadPosts(false);
        });
      }
    },

    bindInfiniteScroll() {
      const scrollHandler = throttle(() => {
        if (this.isLoading || !this.hasMore) return;
        const scrollTop = window.scrollY;
        const windowHeight = window.innerHeight;
        const documentHeight = document.documentElement.scrollHeight;
        if (scrollTop + windowHeight >= documentHeight - 300) {
          this.loadPosts(false);
        }
      }, 200);
      window.addEventListener('scroll', scrollHandler, { passive: true });
    },

    async loadPosts(reset) {
      if (!this.container || this.isLoading) return;
      if (reset) {
        this.currentPage = 1;
        this.hasMore = true;
      }
      if (!this.hasMore) return;
      this.isLoading = true;
      this.showLoading(reset);
      if (reset) {
        // 首次/筛选切换时用骨架屏占位，避免空白闪烁（enhancements.js 提供 SkeletonLoader）
        try {
          if (window.App && typeof App.SkeletonLoader !== 'undefined') {
            App.SkeletonLoader.show(this.container, 4);
          }
        } catch (e) { /* 忽略，无骨架屏时退化为空白 */ }
      }
      try {
        let url =
          '/api/posts/list.php?category=' +
          encodeURIComponent(this.currentCategory) +
          '&sort=' +
          encodeURIComponent(this.currentSort) +
          '&page=' +
          this.currentPage +
          '&limit=20';
        if (this.searchKeyword) {
          url += '&search=' + encodeURIComponent(this.searchKeyword);
        }
        const data = await fetchAPI(url);
        const posts = data.data.posts || [];
        const pagination = data.data.pagination || {};
        if (reset) {
          this.container.innerHTML = '';
        }
        if (posts.length === 0 && reset) {
          this.container.innerHTML =
            '<div class="empty-state"><div class="empty-icon">' + svgIcon('inbox', 48) + '</div><p>暂无帖子</p></div>';
          this.hasMore = false;
          this.hideLoading();
          return;
        }
        const frag = document.createDocumentFragment();
        posts.forEach((post) => {
          frag.appendChild(this.createPostCard(post));
        });
        // 一次性挂载，避免逐条插入触发多次回流
        this.container.appendChild(frag);
        this.hasMore = pagination.has_more || false;
        this.currentPage++;
        if (this.hasMore) {
          this.showLoadMore();
        } else {
          this.showNoMore();
        }
      } catch (err) {
        if (reset) {
          this.container.innerHTML =
            '<div class="empty-state"><div class="empty-icon">' +
            svgIcon('alertCircle', 48) +
            '</div><p>加载失败，请刷新重试</p></div>';
        } else {
          showToast('加载更多失败', 'error');
        }
      } finally {
        this.isLoading = false;
        this.hideLoading();
      }
    },

    showLoading(reset) {
      if (this.loadMoreBtn) {
        this.loadMoreBtn.disabled = true;
        this.loadMoreBtn.textContent = '加载中...';
      }
    },

    hideLoading() {
      if (this.loadMoreBtn) {
        this.loadMoreBtn.disabled = false;
        this.loadMoreBtn.textContent = '加载更多';
      }
    },

    showLoadMore() {
      if (this.loadMoreWrap) this.loadMoreWrap.style.display = 'block';
      if (this.noMoreEl) this.noMoreEl.style.display = 'none';
    },

    showNoMore() {
      if (this.loadMoreWrap) this.loadMoreWrap.style.display = 'none';
      if (this.noMoreEl) this.noMoreEl.style.display = 'block';
    },

    createPostCard(post) {
      const card = document.createElement('div');
      card.className = 'post-card';
      card.setAttribute('data-post-id', post.id);

      const categoryNames = {
        announcement: '全站公告',
        lost_found: '寻物/失物招领',
        study_help: '学习求助',
        social_chat: '交友闲聊',
        confession: '表白',
        school_info: '校园打听',
        other: '其他',
      };

      const categoryBadge =
        post.is_pinned || post.category === 'announcement'
          ? '<span class="category-badge cat-announcement">' +
            svgIcon('bell', 12) +
            ' ' +
            (post.category === 'announcement' ? '全站公告' : '置顶') +
            '</span>'
          : '<span class="category-badge cat-' +
            post.category +
            '">' +
            (categoryNames[post.category] || post.category) +
            '</span>';

      const anonBadge = post.is_anonymous ? '<span class="anon-badge">匿名</span>' : '';

      let imagesHtml = '';
      if (post.images && post.images.length > 0) {
        const gridClass = 'grid-' + Math.min(post.images.length, 4);
        imagesHtml = '<div class="post-card-images ' + gridClass + '">';
        post.images.forEach((img, idx) => {
          imagesHtml +=
            '<img src="' +
            escapeHtml(img) +
            '" alt="图片' +
            (idx + 1) +
            '" data-full="' +
            escapeHtml(img) +
            '" data-index="' +
            idx +
            '" loading="lazy" onerror="this.style.display=\'none\'">';
        });
        imagesHtml += '</div>';
      }

      const authorHtml = post.author
        ? '<div class="post-card-author">' +
          (post.author.avatar
            ? '<img src="' +
              escapeHtml(post.author.avatar) +
              '" class="author-avatar"' +
              (post.author.qq && post.author.id ? ' data-qq="' + escapeHtml(post.author.qq) + '" data-name="' + escapeHtml(post.author.nickname || '同学') + '" title="发送私信"' : '') +
              ' alt="头像" onerror="this.style.display=\'none\'">'
            : '') +
          '<span class="author-name">' +
          escapeHtml(post.author.nickname || '匿名用户') +
          '</span>' +
          (post.author.title_text ? renderUserTitle(post.author) : '') +
          '</div>'
        : '';

      const titleHtml = post.title
        ? '<h3 class="post-card-title" style="font-size:1.05rem;margin-bottom:8px;">' + escapeHtml(post.title) + '</h3>'
        : '';

      // 公告为官方通知，只读展示（不显示点赞/评论/收藏）
      const isAnnouncement = post.category === 'announcement';
      let actionsHtml = '<div class="post-card-actions">';
      if (!isAnnouncement) {
        actionsHtml +=
          '<button class="action-btn like-btn' +
          (post.is_liked ? ' liked' : '') +
          '" data-post-id="' +
          post.id +
          '" aria-label="点赞">' +
          '<span class="action-icon">' +
          (post.is_liked ? svgIcon('heart', 16) : svgIcon('heartOutline', 16)) +
          '</span>' +
          '<span class="action-count">' +
          (post.like_count || 0) +
          '</span>' +
          '</button>' +
          '<button class="action-btn comment-btn" data-post-id="' +
          post.id +
          '" aria-label="评论">' +
          '<span class="action-icon">' +
          svgIcon('message', 16) +
          '</span>' +
          '<span class="action-count">' +
          (post.comment_count || 0) +
          '</span>' +
          '</button>' +
          '<button class="action-btn favorite-btn' +
          (post.is_favorited ? ' favorited' : '') +
          '" data-post-id="' +
          post.id +
          '" aria-label="收藏">' +
          '<span class="action-icon">' +
          (post.is_favorited ? svgIcon('star', 16) : svgIcon('starOutline', 16)) +
          '</span>' +
          '</button>' +
          '<button class="action-btn share-btn" data-post-id="' +
          post.id +
          '" aria-label="分享">' +
          '<span class="action-icon">' +
          svgIcon('share', 16) +
          '</span>' +
          '</button>';
      }
      actionsHtml +=
        '<button class="action-btn copy-btn" data-content="' +
        escapeHtml(post.content) +
        '" aria-label="复制内容">' +
        '<span class="action-icon">' +
        svgIcon('copy', 16) +
        '</span>' +
        '</button>' +
        '</div>';

      const pollHtml = this.renderPollWidget(post);
      card.innerHTML =
        '<div class="post-card-header">' +
        categoryBadge +
        anonBadge +
        '<span class="post-time">' +
        escapeHtml(post.timeAgo || formatTime(post.created_at)) +
        '</span>' +
        '</div>' +
        titleHtml +
        '<div class="post-card-content">' +
        escapeHtml(post.content).replace(/\n/g, '<br>') +
        '</div>' +
        imagesHtml +
        (pollHtml ? '<div class="post-card-poll">' + pollHtml + '</div>' : '') +
        actionsHtml +
        authorHtml;

      card.addEventListener('click', (e) => {
        if (e.target.closest('button, img, .action-btn, .post-card-images')) return;
        window.location.href = '/pages/post_detail.php?id=' + post.id;
      });
      card.style.cursor = 'pointer';

      this.bindPostCardEvents(card, post);
      return card;
    },

    // 投票组件 HTML（结果在未投票时隐藏，投票后由 bindPoll 揭示）
    renderPollWidget(post) {
      const p = post.poll;
      if (!p || !Array.isArray(p.options) || p.options.length === 0) return '';
      const total = p.total || 0;
      const counts = p.counts || [];
      const voted = !!p.has_voted;
      const myVote = p.my_vote;
      let opts = '';
      p.options.forEach((opt, i) => {
        const c = counts[i] || 0;
        const pct = total > 0 ? Math.round((c / total) * 100) : 0;
        const isMine = voted && myVote === i;
        const showRes = voted;
        opts +=
          '<div class="poll-option' +
          (isMine ? ' selected' : '') +
          '" data-index="' +
          i +
          '">' +
          '<span class="poll-label">' +
          escapeHtml(opt) +
          '</span>' +
          (showRes
            ? '<span class="poll-pct">' + pct + '%</span>'
            : '<span class="poll-pct" style="display:none"></span>') +
          (showRes
            ? '<span class="poll-bar-wrap"><i class="poll-bar" style="width:' + pct + '%"></i></span>'
            : '<span class="poll-bar-wrap" style="display:none"><i class="poll-bar" style="width:0%"></i></span>') +
          '</div>';
      });
      return (
        '<div class="poll-widget' +
        (voted ? ' poll-voted' : '') +
        '" data-post-id="' +
        post.id +
        '">' +
        (p.question ? '<div class="poll-question">' + escapeHtml(p.question) + '</div>' : '') +
        '<div class="poll-results">' +
        opts +
        '</div>' +
        '<div class="poll-vote-actions">' +
        (voted
          ? '<span class="poll-voted-note">' + svgIcon('check', 14) + ' 已投票</span>'
          : '<button type="button" class="poll-vote-btn">投票</button>') +
        '<span class="poll-total">' +
        total +
        ' 人参与</span>' +
        '</div>' +
        '</div>'
      );
    },

    // 绑定投票交互
    bindPoll(card, post) {
      const poll = card.querySelector('.poll-widget');
      if (!poll) return;
      const optionEls = poll.querySelectorAll('.poll-option');
      optionEls.forEach((el) => {
        el.addEventListener('click', () => {
          if (poll.classList.contains('poll-voted')) return;
          optionEls.forEach((o) => o.classList.remove('selected'));
          el.classList.add('selected');
        });
      });
      const voteBtn = poll.querySelector('.poll-vote-btn');
      if (!voteBtn) return;
      voteBtn.addEventListener('click', async () => {
        if (poll.classList.contains('poll-voted')) return;
        if (!IS_LOGGED_IN) {
          showToast('请先登录后投票', 'warning');
          return;
        }
        const sel = poll.querySelector('.poll-option.selected');
        if (!sel) {
          showToast('请先选择一个选项', 'warning');
          return;
        }
        const postId = poll.getAttribute('data-post-id');
        const option = sel.getAttribute('data-index');
        try {
          const res = await fetchAPI('/api/posts/vote.php', {
            method: 'POST',
            body: 'post_id=' + postId + '&option=' + encodeURIComponent(option),
          });
          if (!res.success) {
            showToast(res.message || '投票失败', 'error');
            return;
          }
          poll.classList.add('poll-voted');
          const results = res.data || [];
          const total = results.reduce((s, r) => s + (r.count || 0), 0) || 1;
          optionEls.forEach((o, i) => {
            const c = results[i] ? results[i].count : 0;
            const pct = Math.round((c / total) * 100);
            const barWrap = o.querySelector('.poll-bar-wrap');
            const bar = o.querySelector('.poll-bar');
            const pctEl = o.querySelector('.poll-pct');
            if (barWrap) barWrap.style.display = '';
            if (bar) bar.style.width = pct + '%';
            if (pctEl) {
              pctEl.style.display = '';
              pctEl.textContent = pct + '%';
            }
          });
          if (voteBtn) {
            voteBtn.outerHTML = '<span class="poll-voted-note">' + svgIcon('check', 14) + ' 已投票</span>';
          }
          const totalEl = poll.querySelector('.poll-total');
          if (totalEl) totalEl.textContent = total + ' 人参与';
          showToast('投票成功', 'success');
        } catch (err) {
          showToast('网络错误，请重试', 'error');
        }
      });
    },

    bindPostCardEvents(card, post) {
      this.bindPoll(card, post);
      const likeBtn = card.querySelector('.like-btn');
      if (likeBtn) {
        likeBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!IS_LOGGED_IN) {
            showToast('请先登录', 'warning');
            setTimeout(() => {
              window.location.href = SITE_URL + '/pages/login.php';
            }, 1000);
            return;
          }
          try {
            const data = await fetchAPI('/api/posts/like.php', { method: 'POST', body: 'post_id=' + post.id });
            const icon = likeBtn.querySelector('.action-icon');
            const count = likeBtn.querySelector('.action-count');
            if (data.data.is_liked) {
              likeBtn.classList.add('liked');
              if (icon) icon.innerHTML = svgIcon('heart', 16);
            } else {
              likeBtn.classList.remove('liked');
              if (icon) icon.innerHTML = svgIcon('heartOutline', 16);
            }
            if (count) count.textContent = data.data.like_count;
          } catch (err) {
            showToast(err.message || '点赞失败，请重试', 'error');
          }
        });
      }

      const favBtn = card.querySelector('.favorite-btn');
      if (favBtn) {
        favBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          if (!IS_LOGGED_IN) {
            showToast('请先登录', 'warning');
            setTimeout(() => {
              window.location.href = SITE_URL + '/pages/login.php';
            }, 1000);
            return;
          }
          try {
            const data = await fetchAPI('/api/posts/favorite.php', { method: 'POST', body: 'post_id=' + post.id });
            const icon = favBtn.querySelector('.action-icon');
            if (data.data.is_favorited) {
              favBtn.classList.add('favorited');
              if (icon) icon.innerHTML = svgIcon('star', 16);
              showToast('已收藏', 'success');
            } else {
              favBtn.classList.remove('favorited');
              if (icon) icon.innerHTML = svgIcon('starOutline', 16);
              showToast('已取消收藏', 'info');
            }
          } catch (err) {
            showToast(err.message || '收藏操作失败，请重试', 'error');
          }
        });
      }

      const copyBtn = card.querySelector('.copy-btn');
      if (copyBtn) {
        copyBtn.addEventListener('click', (e) => {
          e.preventDefault();
          copyToClipboard(copyBtn.dataset.content);
        });
      }

      const shareBtn = card.querySelector('.share-btn');
      if (shareBtn) {
        shareBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          const link = SITE_URL + '/pages/post_detail.php?id=' + post.id;
          try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              await navigator.clipboard.writeText(link);
            } else {
              const textarea = document.createElement('textarea');
              textarea.value = link;
              textarea.style.position = 'fixed';
              textarea.style.opacity = '0';
              document.body.appendChild(textarea);
              textarea.select();
              document.execCommand('copy');
              document.body.removeChild(textarea);
            }
            showToast('链接已复制', 'success');
          } catch (err) {
            window.prompt('复制链接失败，请手动复制：', link);
          }
        });
      }

      const images = card.querySelectorAll('.post-card-images img');
      images.forEach((img) => {
        img.addEventListener('click', () => {
          Lightbox.open(img.dataset.full || img.src);
        });
      });

      const authorAvatar = card.querySelector('.author-avatar');
      if (authorAvatar && authorAvatar.dataset.qq && window.App && window.App.PrivateMessage) {
        authorAvatar.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          App.PrivateMessage.openWith(authorAvatar.dataset.qq, {
            qq: authorAvatar.dataset.qq,
            nickname: authorAvatar.dataset.name || '同学',
            avatar: authorAvatar.src
          });
        });
      }

      const commentBtn = card.querySelector('.comment-btn');
      if (commentBtn) {
        commentBtn.addEventListener('click', () => {
          window.location.href = SITE_URL + '/pages/post_detail.php?id=' + post.id;
        });
      }
    },
  };

  const Lightbox = {
    overlay: null,
    img: null,

    init() {
      this.overlay = document.createElement('div');
      this.overlay.className = 'lightbox-overlay';
      this.overlay.style.cssText =
        'display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,0.9);' +
        'align-items:center;justify-content:center;cursor:pointer;';
      this.img = document.createElement('img');
      this.img.style.cssText = 'max-width:90vw;max-height:90vh;object-fit:contain;border-radius:8px;';
      this.img.alt = '预览图片';
      const closeBtn = document.createElement('button');
      closeBtn.innerHTML = svgIcon('x', 20);
      closeBtn.style.cssText =
        'position:absolute;top:20px;right:20px;color:#fff;font-size:28px;background:none;border:none;cursor:pointer;width:48px;height:48px;display:flex;align-items:center;justify-content:center;z-index:1;';
      this.overlay.appendChild(closeBtn);
      this.overlay.appendChild(this.img);
      document.body.appendChild(this.overlay);
      this.overlay.addEventListener('click', (e) => {
        if (e.target === this.overlay || e.target === closeBtn) {
          this.close();
        }
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.overlay.style.display === 'flex') {
          this.close();
        }
      });
    },

    open(src) {
      if (!this.overlay) this.init();
      this.img.src = src;
      this.overlay.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    },

    close() {
      if (!this.overlay) return;
      this.overlay.style.display = 'none';
      this.img.src = '';
      document.body.style.overflow = '';
    },
  };

  const Search = {
    input: null,
    searchBtn: null,
    clearBtn: null,

    init() {
      this.input = document.getElementById('searchInput');
      this.searchBtn = document.getElementById('searchBtn');
      if (!this.input) return;

      this.clearBtn = document.createElement('button');
      this.clearBtn.className = 'search-clear-btn';
      this.clearBtn.innerHTML = svgIcon('x', 14);
      this.clearBtn.style.cssText =
        'display:none;position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px;';
      this.input.parentNode.style.position = 'relative';
      this.input.parentNode.appendChild(this.clearBtn);

      const debouncedSearch = debounce(() => {
        this.performSearch();
      }, 300);
      this.input.addEventListener('input', () => {
        const val = this.input.value.trim();
        this.clearBtn.style.display = val ? 'inline-flex' : 'none';
        debouncedSearch();
      });

      this.clearBtn.addEventListener('click', () => {
        this.input.value = '';
        this.clearBtn.style.display = 'none';
        PostFeed.searchKeyword = '';
        PostFeed.currentPage = 1;
        PostFeed.hasMore = true;
        PostFeed.loadPosts(true);
      });

      if (this.searchBtn) {
        this.searchBtn.addEventListener('click', () => {
          this.performSearch();
        });
      }

      this.input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          this.performSearch();
        }
      });
    },

    performSearch() {
      if (!this.input) return;
      const keyword = this.input.value.trim();
      PostFeed.searchKeyword = keyword;
      PostFeed.currentPage = 1;
      PostFeed.hasMore = true;
      PostFeed.loadPosts(true);
    },
  };

  const UserMenu = {
    btn: null,
    dropdown: null,

    init() {
      this.btn = document.getElementById('userMenuBtn');
      this.dropdown = document.getElementById('userDropdown');
      if (!this.btn || !this.dropdown) return;

      this.btn.addEventListener('click', (e) => {
        e.stopPropagation();
        this.toggle();
      });

      document.addEventListener('click', (e) => {
        if (!this.btn.contains(e.target) && !this.dropdown.contains(e.target)) {
          this.hide();
        }
      });

      const logoutBtn = document.getElementById('logoutBtn');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
          e.preventDefault();
          try {
            await fetchAPI('/api/auth/logout.php', { method: 'POST' });
            showToast('已退出登录', 'success');
            setTimeout(() => {
              window.location.href = SITE_URL + '/';
            }, 500);
          } catch (err) {
            window.location.href = SITE_URL + '/';
          }
        });
      }
    },

    toggle() {
      if (this.dropdown) this.dropdown.classList.toggle('show');
    },

    hide() {
      if (this.dropdown) this.dropdown.classList.remove('show');
    },
  };

  function initBackToTop() {
    const btn = document.getElementById('backToTop');
    if (!btn) return;
    const scrollHandler = throttle(() => {
      if (window.scrollY > 300) {
        btn.classList.add('show');
      } else {
        btn.classList.remove('show');
      }
    }, 100);
    window.addEventListener('scroll', scrollHandler, { passive: true });
    btn.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  function initBanBanner() {
    const countdownEl = document.querySelector('.ban-countdown');
    if (!countdownEl) return;
    const untilAttr = countdownEl.getAttribute('data-until');
    if (!untilAttr) return;

    function updateCountdown() {
      const now = new Date().getTime();
      const until = new Date(untilAttr.replace(/-/g, '/')).getTime();
      const remaining = Math.max(0, until - now);
      if (remaining <= 0) {
        countdownEl.textContent = '解封时间已到，请刷新页面';
        clearInterval(timer);
        return;
      }
      const days = Math.floor(remaining / 86400000);
      const hours = Math.floor((remaining % 86400000) / 3600000);
      const minutes = Math.floor((remaining % 3600000) / 60000);
      const seconds = Math.floor((remaining % 60000) / 1000);
      const parts = [];
      if (days > 0) parts.push(days + '天');
      if (hours > 0 || days > 0) parts.push(hours + '小时');
      if (minutes > 0 || hours > 0 || days > 0) parts.push(minutes + '分');
      parts.push(seconds + '秒');
      countdownEl.textContent = '剩余：' + parts.join('');
    }

    updateCountdown();
    const timer = setInterval(updateCountdown, 1000);
  }

  const FormHelpers = {
    init() {
      this.initQQValidation();
      this.initPasswordStrength();
      this.initPasswordToggle();
      this.initConfirmPassword();
      this.initFormSubmitDebounce();
    },

    initQQValidation() {
      const qqInput = document.querySelector('input[name="qq"], #qq');
      if (!qqInput) return;
      const qqError = document.getElementById('qqError');
      const qqErrorText = document.getElementById('qqErrorText');
      const qqAvatar = document.getElementById('qqAvatar');
      qqInput.addEventListener('input', function () {
        let val = this.value.replace(/\D/g, '');
        this.value = val;
        if (qqError) qqError.classList.remove('show');
        if (qqInput) qqInput.classList.remove('input-error');
        if (val.length === 0) {
          if (qqAvatar) qqAvatar.classList.remove('show');
          return;
        }

        if (val.length >= 1 && val[0] === '0') {
          if (qqErrorText) qqErrorText.textContent = 'QQ号不能以0开头';
          if (qqError) qqError.classList.add('show');
          if (qqInput) qqInput.classList.add('input-error');
          return;
        }

        if (val.length >= 5 && /^[1-9][0-9]{4,14}$/.test(val)) {
          if (qqAvatar) {
            qqAvatar.src = 'https://q.qlogo.cn/headimg_dl?dst_uin=' + val + '&spec=100';
            qqAvatar.classList.add('show');
          }
        } else {
          if (qqAvatar) qqAvatar.classList.remove('show');
        }
      });
    },

    initPasswordStrength() {
      const pwdInput = document.querySelector('input[name="password"], #password');
      if (!pwdInput) return;
      const strengthEl = document.getElementById('passwordStrength');
      if (!strengthEl) return;
      const bars = [
        document.getElementById('strengthBar1'),
        document.getElementById('strengthBar2'),
        document.getElementById('strengthBar3'),
      ];
      const textEl = document.getElementById('strengthText');
      pwdInput.addEventListener('input', function () {
        const pwd = this.value;
        const pwdError = document.getElementById('passwordError');
        if (pwdError) pwdError.classList.remove('show');
        if (pwd.length === 0) {
          strengthEl.style.display = 'none';
          return;
        }
        strengthEl.style.display = 'flex';
        let score = 0;
        if (pwd.length >= 8) score++;
        if (pwd.length >= 12) score++;
        if (/[a-z]/.test(pwd)) score++;
        if (/[A-Z]/.test(pwd)) score++;
        if (/[0-9]/.test(pwd)) score++;
        if (/[^a-zA-Z0-9]/.test(pwd)) score++;
        let level, colorClass;
        if (score <= 2) {
          level = '弱';
          colorClass = 'weak';
          bars.forEach((b, i) => {
            if (b) b.className = 'strength-bar' + (i === 0 ? ' weak' : '');
          });
        } else if (score <= 3) {
          level = '中等';
          colorClass = 'medium';
          bars.forEach((b, i) => {
            if (b) b.className = 'strength-bar' + (i <= 1 ? ' medium' : '');
          });
        } else {
          level = score >= 5 ? '非常强' : '强';
          colorClass = 'strong';
          bars.forEach((b) => {
            if (b) b.className = 'strength-bar strong';
          });
        }
        if (textEl) {
          textEl.textContent = level;
          textEl.className = 'strength-text ' + colorClass;
        }
      });
    },

    initPasswordToggle() {
      const toggleBtns = document.querySelectorAll('.input-icon-btn');
      toggleBtns.forEach((btn) => {
        const wrapper = btn.parentElement;
        if (!wrapper) return;
        const input = wrapper.querySelector('input');
        if (!input || (input.type !== 'password' && input.type !== 'text')) return;
        btn.addEventListener('click', () => {
          const isPassword = input.type === 'password';
          input.type = isPassword ? 'text' : 'password';
          btn.innerHTML = isPassword ? svgIcon('eyeOff', 16) : svgIcon('eye', 16);
        });
      });
    },

    initConfirmPassword() {
      const confirmInput = document.getElementById('confirmPassword');
      if (!confirmInput) return;