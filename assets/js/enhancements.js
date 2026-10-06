
(function () {
  'use strict';

  if (window.__enhancements_initialized) return;
  window.__enhancements_initialized = true;

  var App = window.App;
  if (!App) {
    var _retry = 0;
    var _waitApp = setInterval(function () {
      if (window.App) {
        App = window.App;
        clearInterval(_waitApp);
        initAll();
      }
      if (++_retry > 10) { clearInterval(_waitApp); }
    }, 200);
    return;
  }

  var fetchAPI = App.fetchAPI;
  var showToast = App.showToast;
  var svgIcon = App.svgIcon;
  var formatTime = App.formatTime;
  var escapeHtml = App.escapeHtml;
  var debounce = App.debounce;
  var throttle = App.throttle;
  var Modal = App.Modal;
  var closeSponsorModal = App.closeSponsorModal;

  // ---------------------------------------------------------------------------
  // 顶栏次要按钮的归置（与 main.js 里同名实现保持一致，两文件互不依赖，故各留一份）
  // 桌面端留在 .header-actions（与改动前一致）；手机端搬进 [data-mount="me-panel"]，
  // 即用户面板底部弹层里的「快捷操作」区。断点变化时重新归置。
  // ---------------------------------------------------------------------------
  var _secondaryMounts = [];
  var _secondaryMq = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;

  function _placeSecondary(entry) {
    var isMobile = !!(_secondaryMq && _secondaryMq.matches);

    // 手机端但没有可挂载的面板时，必须隐藏而不是留在顶栏：
    // 这些次要按钮（私信/签到/草稿/关注…）都被定义为「手机端搬进用户面板」，
    // 而 includes/user_dropdown.php 在**未登录时不输出任何内容**，
    // 所以游客态下 [data-mount="me-panel"] 根本不存在。
    // 此时若把它们留在 .header-actions（不换行的 flex 行）里，
    // 顶栏会被挤出视口，造成整页横向滚动/缩放（实测 tools 溢出 38px、rollcall 71px）。
    // 这些按钮本身也都是登录后才用得上的功能，游客态隐藏即可。
    if (isMobile && !entry.mobileTarget) {
      entry.el.style.display = 'none';
      return;
    }
    entry.el.style.display = '';

    var useMobile = !!(entry.mobileTarget && isMobile);
    var target = useMobile ? entry.mobileTarget : entry.desktopTarget;
    if (target && entry.el.parentNode !== target) {
      target.appendChild(entry.el);
    }
  }

  function mountSecondary(el, mobileName) {
    var desktopTarget = document.querySelector('.header-actions');
    if (!desktopTarget) { return false; }
    var entry = {
      el: el,
      desktopTarget: desktopTarget,
      mobileTarget: mobileName ? document.querySelector('[data-mount="' + mobileName + '"]') : null
    };
    _secondaryMounts.push(entry);
    _placeSecondary(entry);
    return true;
  }

  if (_secondaryMq) {
    var _onSecondaryMq = function () { _secondaryMounts.forEach(_placeSecondary); };
    if (_secondaryMq.addEventListener) { _secondaryMq.addEventListener('change', _onSecondaryMq); }
    else if (_secondaryMq.addListener) { _secondaryMq.addListener(_onSecondaryMq); }
  }

  var SkeletonLoader = {

    show: function (container, count) {
      count = count || 4;
      var el = typeof container === 'string' ? document.querySelector(container) : container;
      if (!el) return;

      var html = '';
      for (var i = 0; i < count; i++) {
        html +=
          '<div class="skeleton-card" style="' +
          'background:var(--card-bg);border-radius:12px;padding:16px;margin-bottom:12px;' +
          'border:1px solid var(--border);overflow:hidden;' +
          '">' +
          '<div class="skeleton-line skeleton-shimmer" style="width:30%;height:14px;margin-bottom:12px;border-radius:4px;background:var(--skeleton,#e0e0e0);"></div>' +
          '<div class="skeleton-line skeleton-shimmer" style="width:100%;height:16px;margin-bottom:8px;border-radius:4px;background:var(--skeleton,#e0e0e0);"></div>' +
          '<div class="skeleton-line skeleton-shimmer" style="width:80%;height:16px;margin-bottom:8px;border-radius:4px;background:var(--skeleton,#e0e0e0);"></div>' +
          '<div class="skeleton-line skeleton-shimmer" style="width:60%;height:16px;margin-bottom:16px;border-radius:4px;background:var(--skeleton,#e0e0e0);"></div>' +
          '<div class="skeleton-line skeleton-shimmer" style="width:120px;height:32px;border-radius:6px;background:var(--skeleton,#e0e0e0);"></div>' +
          '</div>';
      }
      el.innerHTML = html;
    },

    hide: function (container) {
      var el = typeof container === 'string' ? document.querySelector(container) : container;
      if (!el) return;
      var skeletons = el.querySelectorAll('.skeleton-card');
      skeletons.forEach(function (s) { s.remove(); });
    }
  };

  var CharCounter = {
    init: function () {
      var self = this;

      document.querySelectorAll('textarea[maxlength], input[maxlength][type="text"]').forEach(function (el) {
        self._attachTo(el);
      });

      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType === 1) {
              if (node.matches && node.matches('textarea[maxlength], input[maxlength][type="text"]')) {
                self._attachTo(node);
              }
              if (node.querySelectorAll) {
                node.querySelectorAll('textarea[maxlength], input[maxlength][type="text"]').forEach(function (child) {
                  self._attachTo(child);
                });
              }
            }
          });
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    },

    _attachTo: function (input) {
      if (input.dataset.charCounterAttached) return;
      input.dataset.charCounterAttached = '1';

      var max = parseInt(input.getAttribute('maxlength')) || 500;
      var wrapper = input.parentNode;

      var counter = document.createElement('div');
      counter.className = 'char-counter';
      counter.style.cssText =
        'text-align:right;font-size:12px;color:var(--text-muted,#999);margin-top:4px;transition:color 0.2s;';
      counter.textContent = '0/' + max;

      if (input.nextSibling) {
        wrapper.insertBefore(counter, input.nextSibling);
      } else {
        wrapper.appendChild(counter);
      }

      input.addEventListener('input', function () {
        var len = input.value.length;
        counter.textContent = len + '/' + max;
        if (len >= max * 0.9) {
          counter.style.color = '#e74c3c';
          counter.style.fontWeight = '600';
        } else if (len >= max * 0.7) {
          counter.style.color = '#f39c12';
          counter.style.fontWeight = '400';
        } else {
          counter.style.color = '';
          counter.style.fontWeight = '';
        }
      });
    }
  };

  var InfiniteScroll = {
    _observer: null,
    _sentry: null,
    _callback: null,
    _loading: false,
    _loaderEl: null,

    init: function (onLoadMore, container) {
      this._callback = onLoadMore;
      var self = this;

      this._sentry = document.createElement('div');
      this._sentry.id = 'infiniteScrollSentry';
      this._sentry.style.cssText = 'height:1px;width:100%;';
      var target = container || document.getElementById('postsContainer') || document.body;
      target.appendChild(this._sentry);

      this._loaderEl = document.createElement('div');
      this._loaderEl.className = 'infinite-scroll-loader';
      this._loaderEl.style.cssText =
        'display:none;text-align:center;padding:16px;color:var(--text-muted);font-size:14px;';
      this._loaderEl.innerHTML =
        '<span class="loader-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--primary);margin:0 4px;animation:loaderBounce 0.6s infinite alternate;"></span>' +
        '<span class="loader-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--primary);margin:0 4px;animation:loaderBounce 0.6s 0.2s infinite alternate;"></span>' +
        '<span class="loader-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--primary);margin:0 4px;animation:loaderBounce 0.6s 0.4s infinite alternate;"></span>';
      target.appendChild(this._loaderEl);

      if (window.IntersectionObserver) {
        this._observer = new IntersectionObserver(
          function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting && !self._loading) {
                self._triggerLoad();
              }
            });
          },
          { rootMargin: '200px' }
        );
        this._observer.observe(this._sentry);
      } else {

        var scrollHandler = throttle(function () {
          if (self._loading) return;
          var rect = self._sentry.getBoundingClientRect();
          if (rect.top < window.innerHeight + 300) {
            self._triggerLoad();
          }
        }, 200);
        window.addEventListener('scroll', scrollHandler, { passive: true });
      }
    },

    _triggerLoad: function () {
      if (this._loading) return;
      this._loading = true;
      this._loaderEl.style.display = 'block';
      var self = this;
      if (typeof this._callback === 'function') {
        Promise.resolve(this._callback()).finally(function () {
          self._loading = false;
          self._loaderEl.style.display = 'none';
        });
      }
    },

    loadMore: function () {
      this._triggerLoad();
    },

    destroy: function () {
      if (this._observer) {
        this._observer.disconnect();
        this._observer = null;
      }
      if (this._sentry && this._sentry.parentNode) {
        this._sentry.parentNode.removeChild(this._sentry);
      }
      if (this._loaderEl && this._loaderEl.parentNode) {
        this._loaderEl.parentNode.removeChild(this._loaderEl);
      }
    }
  };

  var DraftManager = {
    _key: function () {
      return 'post_draft_' + (window.IS_LOGGED_IN && window.USER_DATA ? window.USER_DATA.id : 'guest');
    },
    _timer: null,
    _lastSaved: '',

    init: function () {
      var self = this;
      var titleInput = document.querySelector('input[name="title"], #title');
      var contentInput = document.querySelector('textarea[name="content"], #content');
      if (!titleInput && !contentInput) return;

      this._restore();

      this._timer = setInterval(function () {
        self._save();
      }, 10000);

      var debouncedSave = debounce(function () {
        self._save();
      }, 2000);
      if (titleInput) titleInput.addEventListener('input', debouncedSave);
      if (contentInput) contentInput.addEventListener('input', debouncedSave);

      var form = document.querySelector('form');
      if (form) {
        form.addEventListener('submit', function () {
          setTimeout(function () {
            self.clear();
          }, 1500);
        });
      }

      this._bindPostForm();
    },

    _bindPostForm: function () {
      var self = this;

      document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.querySelector('textarea[name="content"]')) {
          setTimeout(function () {
            self.clear();
          }, 1500);
        }
      });
    },

    _save: function () {
      var titleInput = document.querySelector('input[name="title"], #title');
      var contentInput = document.querySelector('textarea[name="content"], #content');

      var data = {
        title: titleInput ? titleInput.value : '',
        content: contentInput ? contentInput.value : '',
        savedAt: new Date().toISOString()
      };
      var json = JSON.stringify(data);
      if (json === this._lastSaved) return;
      this._lastSaved = json;

      try {
        localStorage.setItem(this._key(), json);
      } catch (e) {}
    },

    _restore: function () {
      try {
        var saved = localStorage.getItem(this._key());
        if (!saved) return;
        var data = JSON.parse(saved);
        if (!data.content && !data.title) return;

        var titleInput = document.querySelector('input[name="title"], #title');
        var contentInput = document.querySelector('textarea[name="content"], #content');

        if (titleInput && data.title) titleInput.value = data.title;
        if (contentInput && data.content) contentInput.value = data.content;

        showToast('已恢复未保存的草稿 (' + formatTime(data.savedAt) + ')', 'info');
      } catch (e) {}
    },

    get: function () {
      try {
        var saved = localStorage.getItem(this._key());
        return saved ? JSON.parse(saved) : null;
      } catch (e) {
        return null;
      }
    },

    clear: function () {
      localStorage.removeItem(this._key());
      this._lastSaved = '';
    },

    getAll: function () {
      var drafts = [];
      for (var i = 0; i < localStorage.length; i++) {
        var key = localStorage.key(i);
        if (key && key.indexOf('post_draft_') === 0) {
          try {
            var data = JSON.parse(localStorage.getItem(key));
            if (data && (data.content || data.title)) {
              data._key = key;
              drafts.push(data);
            }
          } catch (e) {}
        }
      }
      drafts.sort(function (a, b) {
        return new Date(b.savedAt) - new Date(a.savedAt);
      });
      return drafts;
    },

    remove: function (key) {
      localStorage.removeItem(key);
      if (key === this._key()) {
        this._lastSaved = '';
      }
    }
  };

  var ConfirmBeforeLeave = {
    _dirty: false,
    _message: '您有未保存的内容，确定要离开吗？',

    init: function () {
      var self = this;

      var contentInput = document.querySelector('textarea[name="content"], #content, #commentInput');
      var titleInput = document.querySelector('input[name="title"], #title');

      // 内容清空（发布成功、手动删光）就不再算「有未保存内容」
      function track(el) {
        if (!el) return;
        el.addEventListener('input', function () {
          self._syncDirty(el);
        });
        el.addEventListener('change', function () {
          self._syncDirty(el);
        });
      }
      track(contentInput);
      track(titleInput);

      window.addEventListener('beforeunload', function (e) {
        if (self._dirty) {
          e.preventDefault();
          e.returnValue = self._message;
          return self._message;
        }
      });

      document.addEventListener('submit', function () {
        setTimeout(function () { self._dirty = false; }, 500);
      });

      // 站内「发表/修改/删除」全部走 AJAX（fetch），原生 submit 事件不会触发，
      // 于是内容其实已经成功落库、页面接着跳转时，浏览器仍然弹「更改可能不会保存」。
      // 这里统一观察站内写请求：只要有任何一次写操作返回 success，就解除离开提醒。
      if (!window.__lwLeaveGuardFetchHooked) {
        window.__lwLeaveGuardFetchHooked = true;
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
          var url = (typeof input === 'string') ? input : ((input && input.url) || '');
          var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
          var p = origFetch.apply(this, arguments);
          if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return p;
          if (url.indexOf('/api/') === -1) return p;
          return p.then(function (res) {
            try {
              res.clone().json().then(function (d) {
                if (d && d.success) {
                  window.__lwMarkContentSaved();
                }
              }).catch(function () {});
            } catch (err) {}
            return res;
          });
        };
      }
    },

    /** 按当前输入框内容重新判定脏标记（清空即视为已保存） */
    _syncDirty: function (el) {
      if (el && el.value.trim().length > 0) {
        this._dirty = true;
        return;
      }
      var contentInput = document.querySelector('textarea[name="content"], #content, #commentInput');
      var titleInput = document.querySelector('input[name="title"], #title');
      var hasText = [contentInput, titleInput].some(function (n) {
        return n && n.value && n.value.trim().length > 0;
      });
      if (!hasText) this._dirty = false;
    },

    markClean: function () {
      this._dirty = false;
    },

    isDirty: function () {
      return this._dirty;
    }
  };

  // 全站可用的「内容已保存」信号：任何写接口返回 success 后调用，解除离开提醒。
  // 站在模块外定义是为了让其它模块（草稿、发帖页自研流程）也能主动清标记。
  if (!window.__lwMarkContentSaved) {
    window.__lwMarkContentSaved = function () {
      try {
        if (window.App && window.App.ConfirmBeforeLeave) {
          window.App.ConfirmBeforeLeave.markClean();
        } else {
          var inst = ConfirmBeforeLeave;
          if (inst) inst._dirty = false;
        }
      } catch (e) {
        if (typeof ConfirmBeforeLeave !== 'undefined' && ConfirmBeforeLeave) {
          ConfirmBeforeLeave._dirty = false;
        }
      }
    };
  }

  var AutoResizeTextarea = {
    init: function () {
      var self = this;
      document.querySelectorAll('textarea').forEach(function (textarea) {
        self._attachTo(textarea);
      });

      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType === 1) {
              if (node.matches && node.matches('textarea')) {
                self._attachTo(node);
              }
              if (node.querySelectorAll) {
                node.querySelectorAll('textarea').forEach(function (child) {
                  self._attachTo(child);
                });
              }
            }
          });
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    },

    _attachTo: function (textarea) {
      if (textarea.dataset.autoResizeAttached) return;
      textarea.dataset.autoResizeAttached = '1';

      textarea.style.resize = 'vertical';
      textarea.style.overflow = 'hidden';
      textarea.style.minHeight = '40px';
      textarea.style.transition = 'height 0.1s ease';

      var self = this;
      textarea.addEventListener('input', function () {
        self._resize(textarea);
      });

      this._resize(textarea);
    },

    _resize: function (textarea) {
      textarea.style.height = 'auto';
      var newHeight = Math.max(40, textarea.scrollHeight);
      textarea.style.height = newHeight + 'px';
    }
  };

  var SmoothScroll = {
    init: function () {
      var self = this;
      document.addEventListener('click', function (e) {
        var target = e.target;

        while (target && target !== document) {
          if (target.tagName === 'A' && target.getAttribute('href')) {
            var href = target.getAttribute('href');
            if (href && href.charAt(0) === '#' && href.length > 1) {
              e.preventDefault();
              self._scrollTo(href.substring(1));
            }
            return;
          }
          target = target.parentNode;
        }
      });
    },

    _scrollTo: function (id) {
      var target = document.getElementById(id);
      if (!target) {

        target = document.querySelector('[data-anchor="' + id + '"]');
      }
      if (!target) return;

      var headerHeight = 60;
      var targetTop = target.getBoundingClientRect().top + window.pageYOffset - headerHeight;
      window.scrollTo({ top: targetTop, behavior: 'smooth' });
    }
  };

  var TouchOptimize = {
    init: function () {

      if (!('ontouchstart' in window)) return;

      document.addEventListener('touchstart', function () {}, { passive: true });

      var self = this;
      var longPressTimer = null;
      var longPressTarget = null;

      document.addEventListener('touchstart', function (e) {
        var target = e.target;

        var card = target.closest('.post-card');
        if (!card) return;

        longPressTarget = card;
        longPressTimer = setTimeout(function () {
          self._showLongPressMenu(card, e);
        }, 600);
      }, { passive: true });

      document.addEventListener('touchend', function () {
        clearTimeout(longPressTimer);
        longPressTimer = null;
        longPressTarget = null;
      });

      document.addEventListener('touchmove', function () {
        clearTimeout(longPressTimer);
        longPressTimer = null;
        longPressTarget = null;
      });
    },

    _showLongPressMenu: function (card, e) {
      var postId = card.getAttribute('data-post-id');
      if (!postId) return;

      var content = card.querySelector('.post-card-content');
      var text = content ? content.textContent.trim() : '';

      var menu = document.createElement('div');
      menu.className = 'long-press-menu';
      menu.style.cssText =
        'position:fixed;z-index:2000;background:var(--card-bg);border:1px solid var(--border);' +
        'border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.15);padding:4px 0;' +
        'min-width:140px;';

      var items = [
        { text: '复制内容', action: function () { App.copyToClipboard(text); } },
        { text: '复制链接', action: function () {
          var url = (window.SITE_URL || '') + '/pages/post_detail.php?id=' + postId;
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () { showToast('链接已复制', 'success'); });
          }
        }},
        { text: '查看详情', action: function () {
          window.location.href = (window.SITE_URL || '') + '/pages/post_detail.php?id=' + postId;
        }}
      ];

      items.forEach(function (item) {
        var el = document.createElement('div');
        el.className = 'long-press-menu-item';
        el.textContent = item.text;
        el.style.cssText =
          'padding:10px 16px;cursor:pointer;font-size:14px;color:var(--text);' +
          'transition:background 0.15s;white-space:nowrap;';
        el.addEventListener('mouseenter', function () { el.style.background = 'var(--bg-hover,#f5f5f5)'; });
        el.addEventListener('mouseleave', function () { el.style.background = ''; });
        el.addEventListener('click', function () {
          item.action();
          document.body.removeChild(menu);
        });
        el.addEventListener('touchend', function (ev) {
          ev.preventDefault();
          item.action();
          document.body.removeChild(menu);
        });
        menu.appendChild(el);
      });

      var touch = e.touches ? e.touches[0] : e.changedTouches[0];
      var x = touch.clientX;
      var y = touch.clientY;

      if (x + 150 > window.innerWidth) x = window.innerWidth - 160;
      if (y + menu.offsetHeight > window.innerHeight) y = y - menu.offsetHeight;

      menu.style.left = x + 'px';
      menu.style.top = y + 'px';
      document.body.appendChild(menu);

      setTimeout(function () {
        var closeHandler = function (ev) {
          if (!menu.contains(ev.target)) {
            if (menu.parentNode) document.body.removeChild(menu);
            document.removeEventListener('click', closeHandler);
            document.removeEventListener('touchstart', closeHandler);
          }
        };
        document.addEventListener('click', closeHandler);
        document.addEventListener('touchstart', closeHandler);
      }, 0);
    }
  };

  var TitleNotification = {
    _originalTitle: '',
    _timer: null,
    _flashing: false,
    _unreadCount: 0,

    init: function () {
      this._originalTitle = document.title;

      var self = this;
      var checkInterval = setInterval(function () {

        if (window.App && window.App.NotificationSystem && window.App.NotificationSystem.unreadCount !== undefined) {
          self.setUnread(window.App.NotificationSystem.unreadCount);
        }
      }, 5000);

      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          self._stopFlash();
          document.title = self._originalTitle;
        }
      });
    },

    setUnread: function (count) {
      this._unreadCount = count;
      if (count > 0) {
        document.title = '(' + (count > 99 ? '99+' : count) + ') ' + this._originalTitle;
        if (document.hidden) {
          this._startFlash();
        }
      } else {
        this._stopFlash();
        document.title = this._originalTitle;
      }
    },

    _startFlash: function () {
      if (this._flashing) return;
      this._flashing = true;
      var self = this;
      var show = true;
      this._timer = setInterval(function () {
        document.title = show
          ? '【新消息】' + self._originalTitle
          : '(' + (self._unreadCount > 99 ? '99+' : self._unreadCount) + ') ' + self._originalTitle;
        show = !show;
      }, 1500);
    },

    _stopFlash: function () {
      if (this._timer) {
        clearInterval(this._timer);
        this._timer = null;
      }
      this._flashing = false;
    }
  };

  var BreadcrumbNav = {
    init: function () {
      var self = this;
      var pathname = window.location.pathname;

      if (pathname === '/' || pathname === '/index.php' || pathname === '') return;

      var container = document.querySelector('.breadcrumb-container');
      if (!container) {

        var mainContent = document.querySelector('.site-main .container, .page-content, main');
        if (!mainContent) return;

        container = document.createElement('nav');
        container.className = 'breadcrumb-nav';
        container.setAttribute('aria-label', '面包屑导航');
        container.style.cssText =
          'padding:8px 0;font-size:13px;color:var(--text-muted);display:flex;align-items:center;flex-wrap:wrap;gap:4px;';
        mainContent.insertBefore(container, mainContent.firstChild);
      }

      var breadcrumbs = this._buildBreadcrumbs(pathname);
      container.innerHTML = breadcrumbs
        .map(function (item, index) {
          if (index < breadcrumbs.length - 1) {
            return '<a href="' + escapeHtml(item.url) + '" style="color:var(--text-muted);text-decoration:none;">' +
              escapeHtml(item.name) + '</a>' +
              '<span style="margin:0 4px;color:var(--border);">/</span>';
          } else {
            return '<span style="color:var(--text);font-weight:500;">' + escapeHtml(item.name) + '</span>';
          }
        })
        .join('');
    },

    _buildBreadcrumbs: function (pathname) {
      var items = [{ name: '首页', url: (window.SITE_URL || '') + '/' }];

      if (pathname === '/' || pathname === '/index.php') {
        return items;
      }

      var pageMap = {
        '/pages/post_detail.php': '帖子详情',
        '/pages/login.php': '登录',
        '/pages/register.php': '注册',
        '/pages/forgot_password.php': '找回密码',
        '/pages/user_center.php': '个人中心',
        '/pages/2fa_setup.php': '两步验证设置',
        '/admin/index.php': '管理后台',
        '/admin/dashboard.php': '仪表盘',
        '/admin/posts.php': '帖子管理',
        '/admin/users.php': '用户管理',
        '/admin/comments.php': '评论管理',
        '/admin/settings.php': '系统设置',
        '/admin/announcements.php': '公告管理',
        '/admin/admins.php': '管理员管理',
        '/admin/operation_logs.php': '操作日志',
        '/admin/ip_blacklist.php': 'IP黑名单',
        '/admin/sponsor.php': '赞助管理'
      };

      for (var pattern in pageMap) {
        if (pathname.indexOf(pattern) !== -1) {

          if (pattern.indexOf('/admin/') === 0) {
            items.push({ name: '管理后台', url: (window.SITE_URL || '') + '/admin/index.php' });
          }
          items.push({ name: pageMap[pattern], url: '' });
          return items;
        }
      }

      items.push({ name: document.title || '当前页面', url: '' });
      return items;
    }
  };

  var TimestampTooltip = {
    _tooltip: null,

    init: function () {
      var self = this;

      this._tooltip = document.createElement('div');
      this._tooltip.className = 'timestamp-tooltip';
      this._tooltip.style.cssText =
        'display:none;position:fixed;z-index:2000;background:rgba(0,0,0,0.85);color:#fff;' +
        'padding:6px 10px;border-radius:6px;font-size:12px;pointer-events:none;' +
        'white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,0.3);';
      document.body.appendChild(this._tooltip);

      document.addEventListener('mouseover', function (e) {
        var target = e.target;
        var timeEl = target.closest('.post-time, .comment-time, [data-timestamp]');
        if (!timeEl) return;

        var timestamp = timeEl.getAttribute('data-timestamp') || timeEl.getAttribute('title');
        if (!timestamp) {

          var card = timeEl.closest('[data-created-at]');
          if (card) timestamp = card.getAttribute('data-created-at');
        }
        if (!timestamp) return;

        self._tooltip.textContent = timestamp;
        self._tooltip.style.display = 'block';
      });

      document.addEventListener('mousemove', function (e) {
        if (self._tooltip.style.display === 'block') {
          var x = e.clientX + 12;
          var y = e.clientY + 12;

          if (x + self._tooltip.offsetWidth > window.innerWidth) {
            x = e.clientX - self._tooltip.offsetWidth - 12;
          }
          if (y + self._tooltip.offsetHeight > window.innerHeight) {
            y = e.clientY - self._tooltip.offsetHeight - 12;
          }
          self._tooltip.style.left = x + 'px';
          self._tooltip.style.top = y + 'px';
        }
      });

      document.addEventListener('mouseout', function (e) {
        var target = e.target;
        var timeEl = target.closest('.post-time, .comment-time, [data-timestamp]');
        if (timeEl) {
          self._tooltip.style.display = 'none';
        }
      });
    }
  };

  var SearchHighlight = {

    highlight: function (keyword, container) {
      if (!keyword) return;
      var el = typeof container === 'string' ? document.querySelector(container) : container;
      if (!el) return;

      this.clear(el);

      this._highlightNode(el, keyword);
    },

    _highlightNode: function (node, keyword) {
      if (node.nodeType === 3) {

        var text = node.textContent;
        var regex = new RegExp('(' + this._escapeRegExp(keyword) + ')', 'gi');
        if (regex.test(text)) {
          regex.lastIndex = 0;
          var fragment = document.createDocumentFragment();
          var lastIndex = 0;
          var match;
          while ((match = regex.exec(text)) !== null) {
            if (match.index > lastIndex) {
              fragment.appendChild(document.createTextNode(text.substring(lastIndex, match.index)));
            }
            var mark = document.createElement('mark');
            mark.className = 'search-highlight';
            mark.style.cssText =
              'background:#fff3cd;color:#856404;padding:1px 2px;border-radius:2px;font-weight:500;';
            mark.textContent = match[0];
            fragment.appendChild(mark);
            lastIndex = match.index + match[0].length;
          }
          if (lastIndex < text.length) {
            fragment.appendChild(document.createTextNode(text.substring(lastIndex)));
          }
          node.parentNode.replaceChild(fragment, node);
        }
      } else if (node.nodeType === 1) {

        var tag = node.tagName.toLowerCase();
        if (tag === 'script' || tag === 'style' || tag === 'mark' || tag === 'textarea' || tag === 'input') {
          return;
        }

        var children = Array.from(node.childNodes);
        var self = this;
        children.forEach(function (child) {
          self._highlightNode(child, keyword);
        });
      }
    },

    clear: function (container) {
      var el = typeof container === 'string' ? document.querySelector(container) : container;
      if (!el) return;
      el.querySelectorAll('mark.search-highlight').forEach(function (mark) {
        var parent = mark.parentNode;
        parent.replaceChild(document.createTextNode(mark.textContent), mark);

        parent.normalize();
      });
    },

    _escapeRegExp: function (str) {
      return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
  };

  // 字体大小：12–48px 无极调节，设置统一在此管理
  // （个人中心「主题设置」提供滑块 UI，键盘快捷键 +/=、- 按 1px 微调）。
  // 顶栏齿轮按钮不再循环字体，改为弹出电脑端点头像弹出的同一个用户下拉菜单。
  var FontSizeToggle = {
    _min: 12,
    _max: 48,
    _default: 16,
    // 旧版三档值，读了旧 localStorage 的用户自动换算
    _legacy: { small: 14, medium: 16, large: 18 },
    _current: 16,

    _clamp: function (value) {
      var px = parseInt(value, 10);
      if (isNaN(px)) px = this._default;
      return Math.min(this._max, Math.max(this._min, px));
    },

    apply: function (px) {
      px = this._clamp(px);
      this._current = px;
      document.documentElement.style.setProperty('--font-size-base', px + 'px');
      document.documentElement.style.fontSize = px + 'px';
      localStorage.setItem('font_size', String(px));
    },
    increase: function () {
      this.apply(this._current + 1);
    },
    decrease: function () {
      this.apply(this._current - 1);
    },

    init: function () {
      var raw = localStorage.getItem('font_size');
      this._current = this._legacy[raw] || this._clamp(raw);
      this.apply(this._current);
      // 这里原先会往顶栏注入一个齿轮「设置」按钮，但它的点击只是转发给 userMenuBtn，
      // 与头像菜单功能完全重复，却占掉了手机端顶栏宝贵的一位。已移除。
      // 字号仍可通过「我的」页主题设置与键盘快捷键调整。
    }
  };

  var EmptyState = {

    show: function (container, options) {
      options = options || {};
      var el = typeof container === 'string' ? document.querySelector(container) : container;
      if (!el) return;

      var icon = options.icon || 'inbox';
      var title = options.title || '暂无内容';
      var message = options.message || '';
      var actionText = options.actionText || '';
      var action = options.action || null;

      var html =
        '<div class="empty-state" style="text-align:center;padding:48px 20px;">' +
        '<div class="empty-state-icon" style="margin-bottom:16px;color:var(--text-muted);opacity:0.5;">' +
        svgIcon(icon, 64) +
        '</div>' +
        '<h3 style="margin:0 0 8px;font-size:16px;color:var(--text-secondary);">' + escapeHtml(title) + '</h3>';

      if (message) {
        html += '<p style="margin:0 0 16px;font-size:14px;color:var(--text-muted);">' + escapeHtml(message) + '</p>';
      }

      if (actionText && action) {
        html +=
          '<button class="empty-state-action btn btn-primary" style="margin-top:8px;">' +
          escapeHtml(actionText) +
          '</button>';
      }

      html += '</div>';

      el.innerHTML = html;

      if (actionText && action) {
        var btn = el.querySelector('.empty-state-action');
        if (btn) {
          btn.addEventListener('click', action);
        }
      }
    }
  };

  var PullToRefresh = {
    _indicator: null,
    _pulling: false,
    _startY: 0,
    _pullDist: 0,
    _threshold: 80,
    _refreshing: false,
    _callback: null,

    init: function (onRefresh, container) {
      if (!('ontouchstart' in window)) return;
      this._callback = onRefresh;

      var self = this;
      var target = container || document;

      this._indicator = document.createElement('div');
      this._indicator.className = 'pull-to-refresh';
      this._indicator.style.cssText =
        'position:fixed;top:0;left:0;right:0;z-index:999;height:0;' +
        'display:flex;align-items:center;justify-content:center;' +
        'background:var(--primary,#4A90D9);color:#fff;font-size:14px;' +
        'overflow:hidden;transition:height 0.2s ease;';
      this._indicator.innerHTML = '<span>↓ 下拉刷新</span>';
      document.body.insertBefore(this._indicator, document.body.firstChild);

      target.addEventListener('touchstart', function (e) {
        if (window.scrollY > 10 || self._refreshing) return;
        self._startY = e.touches[0].clientY;
        self._pulling = true;
      }, { passive: true });

      target.addEventListener('touchmove', function (e) {
        if (!self._pulling || self._refreshing) return;
        var currentY = e.touches[0].clientY;
        self._pullDist = currentY - self._startY;
        if (self._pullDist > 0 && window.scrollY <= 0) {
          var height = Math.min(self._pullDist * 0.5, 100);
          self._indicator.style.height = height + 'px';
          if (self._pullDist >= self._threshold) {
            self._indicator.innerHTML = '<span>释放刷新</span>';
          } else {
            self._indicator.innerHTML = '<span>↓ 下拉刷新</span>';
          }
        }
      }, { passive: true });

      target.addEventListener('touchend', function () {
        if (!self._pulling) return;
        self._pulling = false;
        if (self._pullDist >= self._threshold && !self._refreshing) {
          self._doRefresh();
        } else {
          self._reset();
        }
        self._pullDist = 0;
      });
    },

    _doRefresh: function () {
      this._refreshing = true;
      this._indicator.style.height = '50px';
      this._indicator.innerHTML =
        '<span style="display:flex;align-items:center;gap:8px;">' +
        '<span class="refresh-spinner" style="display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,0.4);border-top-color:#fff;border-radius:50%;animation:spin 0.6s linear infinite;"></span>' +
        '刷新中...</span>';

      var self = this;
      if (typeof this._callback === 'function') {
        Promise.resolve(this._callback()).finally(function () {
          self._refreshing = false;
          self._reset();
          showToast('刷新完成', 'success');
        });
      } else {

        setTimeout(function () {
          window.location.reload();
        }, 300);
      }
    },

    _reset: function () {
      this._indicator.style.height = '0';
      this._indicator.innerHTML = '<span>↓ 下拉刷新</span>';
    }
  };

  var DoubleClickLike = {
    _lastClick: 0,
    _lastTarget: null,

    init: function () {
      var self = this;

      document.addEventListener('click', function (e) {
        var card = e.target.closest('.post-card');
        if (!card) return;

        if (e.target.closest('button, a, input, textarea')) return;

        var now = Date.now();
        if (self._lastTarget === card && now - self._lastClick < 400) {

          e.preventDefault();
          self._triggerLike(card);
          self._showHeartAnimation(card, e);
          self._lastClick = 0;
          self._lastTarget = null;
        } else {
          self._lastClick = now;
          self._lastTarget = card;
        }
      });
    },

    _triggerLike: function (card) {
      var likeBtn = card.querySelector('.like-btn');
      if (!likeBtn) return;

      if (likeBtn.classList.contains('liked')) return;
      likeBtn.click();
    },

    _showHeartAnimation: function (card, e) {
      var heart = document.createElement('div');
      heart.className = 'double-click-heart';
      heart.innerHTML = svgIcon('heart', 32);
      heart.style.cssText =
        'position:absolute;z-index:100;pointer-events:none;' +
        'animation:heartFloat 1s ease-out forwards;' +
        'opacity:0;';

      var rect = card.getBoundingClientRect();
      var x = e.clientX - rect.left - 16;
      var y = e.clientY - rect.top - 16;
      heart.style.left = x + 'px';
      heart.style.top = y + 'px';

      card.style.position = card.style.position || 'relative';
      card.appendChild(heart);

      heart.addEventListener('animationend', function () {
        if (heart.parentNode) heart.parentNode.removeChild(heart);
      });
    }
  };

  var PostDrafts = {
    init: function () {
      var self = this;

      var draftBtn = document.createElement('button');
      draftBtn.id = 'draftBoxBtn';
      draftBtn.className = 'draft-box-btn';
      draftBtn.innerHTML = svgIcon('edit', 14) + ' 草稿箱';
      // 外观交给 CSS（.draft-box-btn）：写死在这里的行内样式会盖掉用户面板里的整行排版

      draftBtn.addEventListener('click', function () {
        self._openDraftBox();
      });

      var postForm = document.querySelector('.post-form, #postForm');
      if (postForm) {
        var actions = postForm.querySelector('.form-actions');
        if (actions) {
          actions.insertBefore(draftBtn, actions.firstChild);
        } else {
          postForm.appendChild(draftBtn);
        }
      } else {
        mountSecondary(draftBtn, 'me-panel');
      }
    },

    _openDraftBox: function () {
      var drafts = DraftManager.getAll();
      var self = this;

      if (drafts.length === 0) {
        Modal.open({
          title: '草稿箱',
          content: '<div style="text-align:center;padding:24px;color:var(--text-muted);">' +
            svgIcon('inbox', 32) + '<p style="margin-top:8px;">暂无草稿</p></div>',
          showCancel: false,
          confirmText: '关闭'
        });
        return;
      }

      var content = document.createElement('div');
      content.style.cssText = 'max-height:400px;overflow-y:auto;';

      drafts.forEach(function (draft, index) {
        var item = document.createElement('div');
        item.className = 'draft-item';
        item.style.cssText =
          'padding:12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;' +
          'transition:background 0.2s;';

        var preview = (draft.title || draft.content || '无内容').substring(0, 80);
        var savedTime = formatTime(draft.savedAt);

        item.innerHTML =
          '<div style="display:flex;justify-content:space-between;align-items:flex-start;">' +
          '<div style="flex:1;min-width:0;">' +
          '<div style="font-weight:500;color:var(--text);margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
          escapeHtml(preview) +
          '</div>' +
          '<div style="font-size:12px;color:var(--text-muted);">保存于 ' + savedTime + '</div>' +
          '</div>' +
          '<div style="display:flex;gap:8px;margin-left:12px;flex-shrink:0;">' +
          '<button class="draft-edit-btn btn btn-sm" data-idx="' + index + '" style="padding:4px 8px;font-size:12px;">编辑</button>' +
          '<button class="draft-delete-btn btn btn-sm btn-outline" data-idx="' + index + '" style="padding:4px 8px;font-size:12px;color:#e74c3c;">删除</button>' +
          '</div>' +
          '</div>';

        content.appendChild(item);
      });

      Modal.open({
        title: '草稿箱 (' + drafts.length + '个草稿)',
        content: content,
        confirmText: '关闭',
        showCancel: false,
        size: 'md'
      });

      content.querySelectorAll('.draft-edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var idx = parseInt(this.getAttribute('data-idx'));
          var draft = drafts[idx];
          if (draft) {
            self._editDraft(draft);
          }
        });
      });

      content.querySelectorAll('.draft-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var idx = parseInt(this.getAttribute('data-idx'));
          var draft = drafts[idx];
          if (draft) {
            Modal.confirm('确定要删除这个草稿吗？', function () {
              DraftManager.remove(draft._key);
              showToast('草稿已删除', 'success');

              if (Modal.stack.length > 0) {
                var last = Modal.stack[Modal.stack.length - 1];
                Modal._close(last.overlay, false);
              }
              setTimeout(function () {
                self._openDraftBox();
              }, 300);
            }, { confirmText: '删除', danger: true });
          }
        });
      });
    },

    _editDraft: function (draft) {
      var titleInput = document.querySelector('input[name="title"], #title');
      var contentInput = document.querySelector('textarea[name="content"], #content');

      if (contentInput) {
        contentInput.value = draft.content || '';
        contentInput.focus();
      }
      if (titleInput && draft.title) {
        titleInput.value = draft.title;
      }

      showToast('已加载草稿', 'info');

      if (Modal.stack.length > 0) {
        var last = Modal.stack[Modal.stack.length - 1];
        Modal._close(last.overlay, false);
      }
    }
  };

  var PostTags = {
    _tags: [],

    init: function () {
      var self = this;

      var tagInput = document.getElementById('postTagsInput');
      if (!tagInput) {

        var contentInput = document.querySelector('textarea[name="content"], #content');
        if (!contentInput) return;

        var form = contentInput.closest('form');
        if (!form) return;

        var tagArea = document.createElement('div');
        tagArea.className = 'post-tags-area';
        tagArea.style.cssText = 'margin-top:8px;';

        tagArea.innerHTML =
          '<div class="post-tags-input-wrap" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;' +
          'padding:6px 8px;border:1px solid var(--border);border-radius:6px;min-height:36px;cursor:text;">' +
          '<div class="post-tags-list" style="display:flex;flex-wrap:wrap;gap:4px;"></div>' +
          '<input type="text" id="postTagsInput" placeholder="添加标签（回车确认）" ' +
          'style="border:none;outline:none;flex:1;min-width:120px;font-size:13px;background:transparent;color:var(--text);">' +
          '</div>' +
          '<input type="hidden" name="tags" id="postTagsHidden">';

        contentInput.parentNode.insertBefore(tagArea, contentInput.nextSibling);

        tagInput = document.getElementById('postTagsInput');
      }

      if (!tagInput) return;

      var tagList = tagInput.parentNode.querySelector('.post-tags-list');
      var hiddenInput = document.getElementById('postTagsHidden');

      tagInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',') {
          e.preventDefault();
          var tag = tagInput.value.trim().replace(/,/g, '');
          if (tag && tag.length <= 20) {
            self._addTag(tag, tagList, hiddenInput);
            tagInput.value = '';
          }
        }

        if (e.key === 'Backspace' && tagInput.value === '' && self._tags.length > 0) {
          self._tags.pop();
          self._renderTags(tagList, hiddenInput);
        }
      });

      tagInput.parentNode.addEventListener('click', function () {
        tagInput.focus();
      });

      this._renderTags(tagList, hiddenInput);
    },

    _addTag: function (tag, tagList, hiddenInput) {
      if (this._tags.length >= 5) {
        showToast('最多添加5个标签', 'warning');
        return;
      }
      if (this._tags.indexOf(tag) !== -1) {
        showToast('标签已存在', 'warning');
        return;
      }
      this._tags.push(tag);
      this._renderTags(tagList, hiddenInput);
    },

    _renderTags: function (tagList, hiddenInput) {
      var self = this;
      if (!tagList) return;

      tagList.innerHTML = this._tags.map(function (tag, index) {
        return (
          '<span class="post-tag" style="display:inline-flex;align-items:center;gap:4px;' +
          'padding:2px 8px;background:var(--primary,#4A90D9);color:#fff;border-radius:4px;font-size:12px;">' +
          escapeHtml(tag) +
          '<button type="button" data-tag-idx="' + index + '" ' +
          'style="background:none;border:none;color:rgba(255,255,255,0.7);cursor:pointer;padding:0;font-size:14px;line-height:1;" ' +
          'aria-label="删除标签">&times;</button>' +
          '</span>'
        );
      }).join('');

      if (hiddenInput) {
        hiddenInput.value = this._tags.join(',');
      }

      tagList.querySelectorAll('button[data-tag-idx]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          var idx = parseInt(this.getAttribute('data-tag-idx'));
          self._tags.splice(idx, 1);
          self._renderTags(tagList, hiddenInput);
        });
      });
    },

    renderPostTags: function (container) {
      var el = typeof container === 'string' ? document.querySelector(container) : container;
      if (!el) return;

      el.querySelectorAll('.post-card').forEach(function (card) {
        var tagsAttr = card.getAttribute('data-tags');
        if (!tagsAttr) return;
        try {
          var tags = JSON.parse(tagsAttr);
          if (!Array.isArray(tags) || tags.length === 0) return;

          var header = card.querySelector('.post-card-header');
          if (!header) return;

          var tagsHtml = tags.map(function (tag) {
            return '<span class="post-tag-badge" style="display:inline-block;padding:1px 6px;' +
              'background:var(--tag-bg,rgba(74,144,217,0.1));color:var(--primary,#4A90D9);' +
              'border-radius:3px;font-size:11px;margin-left:4px;">' + escapeHtml(tag) + '</span>';
          }).join('');

          var tagsEl = document.createElement('span');
          tagsEl.className = 'post-tags-inline';
          tagsEl.innerHTML = tagsHtml;
          header.appendChild(tagsEl);
        } catch (e) {}
      });
    }
  };

  var AchievementBadges = {
    _badges: [
      { id: 'post_master', name: '发帖达人', icon: 'edit', desc: '发布超过10篇帖子', threshold: 10, type: 'posts' },
      { id: 'post_king', name: '帖子之王', icon: 'star', desc: '发布超过50篇帖子', threshold: 50, type: 'posts' },
      { id: 'comment_star', name: '评论之星', icon: 'message', desc: '评论超过20条', threshold: 20, type: 'comments' },
      { id: 'comment_master', name: '评论达人', icon: 'message', desc: '评论超过100条', threshold: 100, type: 'comments' },
      { id: 'like_king', name: '点赞王', icon: 'heart', desc: '获得超过50个赞', threshold: 50, type: 'likes' },
      { id: 'like_superstar', name: '人气之星', icon: 'heart', desc: '获得超过200个赞', threshold: 200, type: 'likes' },
      { id: 'early_bird', name: '早起鸟', icon: 'sun', desc: '注册超过30天', threshold: 30, type: 'days' },
      { id: 'veteran', name: '资深用户', icon: 'shield', desc: '注册超过365天', threshold: 365, type: 'days' },
      { id: 'collector', name: '收藏家', icon: 'star', desc: '收藏超过10篇帖子', threshold: 10, type: 'favorites' }
    ],

    init: function () {

      if (window.location.pathname.indexOf('user_center') === -1) return;

      var self = this;
      var container = document.querySelector('.user-profile, .profile-section');
      if (!container) return;

      if (document.querySelector('.achievement-badges-section')) return;

      this._loadUserStats(function (stats) {
        self._renderBadges(container, stats);
      });
    },

    _loadUserStats: function (callback) {

      fetchAPI('/api/user/profile.php')
        .then(function (data) {
          var user = data.data && data.data.user ? data.data.user : {};
          var stats = {
            posts: parseInt(user.post_count || user.total_posts || 0),
            comments: parseInt(user.comment_count || user.total_comments || 0),
            likes: parseInt(user.like_count || user.total_likes || 0),
            favorites: parseInt(user.favorite_count || 0),
            days: 0
          };

          if (user.created_at) {
            var created = new Date(user.created_at.replace(/-/g, '/'));
            var now = new Date();
            stats.days = Math.floor((now - created) / (1000 * 60 * 60 * 24));
          }

          callback(stats);
        })
        .catch(function () {

          var stats = { posts: 0, comments: 0, likes: 0, favorites: 0, days: 0 };

          var postsEl = document.querySelector('.stat-posts .stat-value, [data-stat="posts"]');
          if (postsEl) stats.posts = parseInt(postsEl.textContent) || 0;

          var commentsEl = document.querySelector('.stat-comments .stat-value, [data-stat="comments"]');
          if (commentsEl) stats.comments = parseInt(commentsEl.textContent) || 0;

          var likesEl = document.querySelector('.stat-likes .stat-value, [data-stat="likes"]');
          if (likesEl) stats.likes = parseInt(likesEl.textContent) || 0;

          callback(stats);
        });
    },

    _renderBadges: function (container, stats) {
      var self = this;
      var earned = this._badges.filter(function (badge) {
        return stats[badge.type] >= badge.threshold;
      });

      var section = document.createElement('div');
      section.className = 'achievement-badges-section';
      section.style.cssText =
        'margin-top:20px;padding:16px;background:var(--card-bg);border:1px solid var(--border);border-radius:12px;';

      var title = document.createElement('h3');
      title.style.cssText = 'margin:0 0 12px;font-size:15px;color:var(--text);display:flex;align-items:center;gap:6px;';
      title.innerHTML = svgIcon('trophy', 18) + ' 成就徽章';
      section.appendChild(title);

      if (earned.length === 0) {
        var empty = document.createElement('div');
        empty.style.cssText = 'text-align:center;padding:16px;color:var(--text-muted);font-size:13px;';
        empty.textContent = '还没有获得任何成就徽章，继续加油！';
        section.appendChild(empty);
      } else {
        var badgesGrid = document.createElement('div');
        badgesGrid.style.cssText =
          'display:flex;flex-wrap:wrap;gap:10px;';

        earned.forEach(function (badge) {
          var badgeEl = document.createElement('div');
          badgeEl.className = 'achievement-badge';
          badgeEl.title = badge.desc;
          badgeEl.style.cssText =
            'display:flex;align-items:center;gap:6px;padding:6px 12px;' +
            'background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);color:#fff;' +
            'border-radius:20px;font-size:12px;font-weight:500;' +
            'box-shadow:0 2px 8px rgba(102,126,234,0.3);' +
            'animation:badgeBounceIn 0.4s ease;';
          badgeEl.innerHTML = svgIcon(badge.icon, 14) + ' ' + badge.name;
          section.appendChild(badgeEl);
        });
      }

      var unearned = this._badges.filter(function (badge) {
        return stats[badge.type] < badge.threshold;
      });

      if (unearned.length > 0) {
        var lockedTitle = document.createElement('h4');
        lockedTitle.style.cssText = 'margin:16px 0 8px;font-size:13px;color:var(--text-muted);';
        lockedTitle.textContent = '未获得的徽章';
        section.appendChild(lockedTitle);

        var lockedGrid = document.createElement('div');
        lockedGrid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;';

        unearned.forEach(function (badge) {
          var lockedEl = document.createElement('div');
          lockedEl.className = 'achievement-badge locked';
          lockedEl.title = badge.desc;
          lockedEl.style.cssText =
            'display:flex;align-items:center;gap:4px;padding:4px 10px;' +
            'background:var(--bg-secondary,#f5f5f5);color:var(--text-muted);' +
            'border:1px dashed var(--border);border-radius:20px;font-size:11px;opacity:0.6;';
          lockedEl.innerHTML = svgIcon(badge.icon, 12) + ' ' + badge.name +
            ' <span style="font-size:10px;">(' + stats[badge.type] + '/' + badge.threshold + ')</span>';
          lockedGrid.appendChild(lockedEl);
        });
        section.appendChild(lockedGrid);
      }

      container.appendChild(section);
    }
  };

  var ViewCounter = {
    init: function () {

      var postId = this._getPostId();
      if (!postId) return;

      this._recordView(postId);

      this._displayViewCount(postId);
    },

    _getPostId: function () {
      var params = new URLSearchParams(window.location.search);
      var id = params.get('id');
      if (!id) {
        var match = window.location.pathname.match(/\/post_detail\.php\?id=(\d+)/);
        if (match) id = match[1];
      }
      return id;
    },

    _recordView: function (postId) {

      var viewedKey = 'viewed_post_' + postId;
      if (sessionStorage.getItem(viewedKey)) return;
      sessionStorage.setItem(viewedKey, '1');

      fetchAPI('/api/posts/view.php', {
        method: 'POST',
        body: 'post_id=' + postId
      }).catch(function () {

      });
    },

    _displayViewCount: function (postId) {

      if (document.querySelector('.view-count-badge')) return;

      var viewsEl = document.querySelector('[data-views]');
      var views = viewsEl ? parseInt(viewsEl.getAttribute('data-views')) : 0;

      var detailMeta = document.querySelector('.post-detail-meta');
      if (!detailMeta) return;

      var badge = document.createElement('span');
      badge.className = 'view-count-badge';
      badge.style.cssText =
        'display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--text-muted);margin-left:12px;';
      badge.innerHTML = svgIcon('eye', 12) + ' <span>' + views + '</span> 次浏览';
      detailMeta.appendChild(badge);
    }
  };

  var RelatedPosts = {
    init: function () {

      var postId = this._getPostId();
      if (!postId) return;

      var self = this;

      var category = document.querySelector('[data-category]');
      var catValue = category ? category.getAttribute('data-category') : '';

      if (!catValue) {

        var params = new URLSearchParams(window.location.search);
        catValue = params.get('category') || '';
      }

      if (!catValue) return;

      this._loadRelated(catValue, postId);
    },

    _getPostId: function () {
      var params = new URLSearchParams(window.location.search);
      return params.get('id');
    },

    _loadRelated: function (category, currentPostId) {
      var self = this;
      // 分类走 URL 别名（social_chat → social），避免 URL 含 chat 被主机 403；
      // 映射表与说明见 assets/js/main.js 的 categoryUrlKey。
      var catKey = (window.App && App.categoryUrlKey) ? App.categoryUrlKey(category) : category;
      fetchAPI('/api/posts/list.php?category=' + encodeURIComponent(catKey) + '&sort=hot&limit=5')
        .then(function (data) {
          var posts = (data.data && data.data.posts) ? data.data.posts : [];

          posts = posts.filter(function (p) { return String(p.id) !== String(currentPostId); });
          if (posts.length === 0) return;

          self._renderRelated(posts);
        })
        .catch(function () {

        });
    },

    _renderRelated: function (posts) {

      var commentsSection = document.querySelector('.comments-section');
      if (!commentsSection) {

        var postDetail = document.querySelector('.post-detail');
        if (!postDetail) return;
        commentsSection = postDetail;
      }

      var section = document.createElement('div');
      section.className = 'related-posts-section';
      section.style.cssText =
        'margin-top:24px;padding:16px;background:var(--card-bg);border:1px solid var(--border);border-radius:12px;';

      var title = document.createElement('h3');
      title.style.cssText = 'margin:0 0 12px;font-size:15px;color:var(--text);display:flex;align-items:center;gap:6px;';
      title.innerHTML = svgIcon('tag', 16) + ' 相关推荐';
      section.appendChild(title);

      var list = document.createElement('div');
      list.style.cssText = 'display:flex;flex-direction:column;gap:8px;';

      posts.forEach(function (post) {
        var item = document.createElement('a');
        item.href = (window.SITE_URL || '') + '/pages/post_detail.php?id=' + post.id;
        item.style.cssText =
          'display:flex;align-items:center;justify-content:space-between;padding:8px 12px;' +
          'border-radius:6px;text-decoration:none;color:var(--text);transition:background 0.15s;' +
          'border:1px solid transparent;';

        item.addEventListener('mouseenter', function () {
          item.style.background = 'var(--bg-hover,#f5f5f5)';
          item.style.borderColor = 'var(--border)';
        });
        item.addEventListener('mouseleave', function () {
          item.style.background = '';
          item.style.borderColor = 'transparent';
        });

        var titleText = post.title || (post.content || '').substring(0, 50);
        item.innerHTML =
          '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;">' +
          escapeHtml(titleText) +
          '</span>' +
          '<span style="display:flex;align-items:center;gap:12px;margin-left:12px;font-size:12px;color:var(--text-muted);flex-shrink:0;">' +
          '<span style="display:inline-flex;align-items:center;gap:3px;">' + svgIcon('heart', 12) + (post.like_count || 0) + '</span>' +
          '<span style="display:inline-flex;align-items:center;gap:3px;">' + svgIcon('message', 12) + (post.comment_count || 0) + '</span>' +
          '</span>';

        list.appendChild(item);
      });

      section.appendChild(list);

      commentsSection.parentNode.insertBefore(section, commentsSection.nextSibling);
    }
  };

  function initImageLightboxDelegation() {
    document.addEventListener('click', function (e) {
      var img = e.target.closest('.post-card-images img, .post-detail-content img');
      if (!img) return;

      if (App.Lightbox) {
        App.Lightbox.open(img.getAttribute('data-full') || img.src);
      }
    });
  }

  function initCopyLinkDelegation() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.copy-link-btn');
      if (!btn) return;

      var card = btn.closest('.post-card');
      if (!card) return;

      var postId = card.getAttribute('data-post-id');
      if (!postId) return;

      var url = (window.SITE_URL || '') + '/pages/post_detail.php?id=' + postId;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () {
          showToast('链接已复制', 'success');
        });
      } else {
        var textarea = document.createElement('textarea');
        textarea.value = url;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        showToast('链接已复制', 'success');
      }
    });
  }

  function injectAnimations() {
    if (document.getElementById('enhancements-animations')) return;

    var style = document.createElement('style');
    style.id = 'enhancements-animations';
    style.textContent =
      '@keyframes loaderBounce {' +
      '  from { transform: translateY(0); opacity: 1; }' +
      '  to { transform: translateY(-8px); opacity: 0.4; }' +
      '}' +
      '@keyframes heartFloat {' +
      '  0% { opacity: 1; transform: scale(0.5) translateY(0); }' +
      '  50% { opacity: 1; transform: scale(1.2) translateY(-30px); }' +
      '  100% { opacity: 0; transform: scale(1) translateY(-60px); }' +
      '}' +
      '@keyframes badgeBounceIn {' +
      '  0% { opacity: 0; transform: scale(0.3); }' +
      '  50% { transform: scale(1.1); }' +
      '  100% { opacity: 1; transform: scale(1); }' +
      '}' +
      '@keyframes titleRainbow {' +
      '  0% { background-position: 0% 50%; }' +
      '  100% { background-position: 300% 50%; }' +
      '}' +
      '@keyframes skeletonShimmer {' +
      '  0% { background-position: -200% 0; }' +
      '  100% { background-position: 200% 0; }' +
      '}' +
      '.skeleton-shimmer {' +
      '  background: linear-gradient(90deg, var(--skeleton,#e0e0e0) 25%, var(--skeleton-light,#f0f0f0) 50%, var(--skeleton,#e0e0e0) 75%);' +
      '  background-size: 200% 100%;' +
      '  animation: skeletonShimmer 1.5s ease-in-out infinite;' +
      '}' +
      '@keyframes spin {' +
      '  from { transform: rotate(0deg); }' +
      '  to { transform: rotate(360deg); }' +
      '}';

    document.head.appendChild(style);
  }

  function initAll() {
    injectAnimations();

    CharCounter.init();

    if (App.PostFeed && document.getElementById('postsContainer')) {
      InfiniteScroll.init(function () {
        if (App.PostFeed && !App.PostFeed.isLoading && App.PostFeed.hasMore) {
          return App.PostFeed.loadPosts(false);
        }
        return Promise.resolve();
      });
    }
    DraftManager.init();
    ConfirmBeforeLeave.init();
    AutoResizeTextarea.init();
    SmoothScroll.init();
    TouchOptimize.init();
    TitleNotification.init();
    BreadcrumbNav.init();
    TimestampTooltip.init();

    FontSizeToggle.init();

    // 已停用：顶部蓝色「↓ 下拉刷新」横条，与手机原生下拉刷新手势体验重复
    // if (App.PostFeed && document.getElementById('postsContainer')) {
    //   PullToRefresh.init(function () {
    //     if (App.PostFeed && !App.PostFeed.isLoading) {
    //       return App.PostFeed.loadPosts(true);
    //     }
    //     return Promise.resolve();
    //   });
    // }
    DoubleClickLike.init();

    PostDrafts.init();
    PostTags.init();
    AchievementBadges.init();
    ViewCounter.init();
    RelatedPosts.init();

    initImageLightboxDelegation();
    initCopyLinkDelegation();

    PostTags.renderPostTags(document.getElementById('postsContainer'));
  }

  // 保持与原逻辑一致的 DOMContentLoaded 立即初始化时机，确保按钮交互第一时间可用；
  // 仅增加 try/catch 隔离，单个模块异常不影响整体
  function safeInitAll() {
    try { initAll(); } catch (e) { if (typeof console !== 'undefined' && console.warn) console.warn('[enhancements] initAll skipped:', e); }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', safeInitAll);
  } else {
    safeInitAll();
  }

  if (window.App) {
    window.App.SkeletonLoader = SkeletonLoader;
    window.App.EmptyState = EmptyState;
    window.App.DraftManager = DraftManager;
    window.App.PostDrafts = PostDrafts;
    window.App.SearchHighlight = SearchHighlight;
    window.App.InfiniteScroll = InfiniteScroll;
    window.App.RelatedPosts = RelatedPosts;
    window.App.PostTags = PostTags;
    window.App.AchievementBadges = AchievementBadges;
    window.App.ViewCounter = ViewCounter;
    window.App.PullToRefresh = PullToRefresh;
    window.App.FontSizeToggle = FontSizeToggle;
    window.App.TouchOptimize = TouchOptimize;
    window.App.BreadcrumbNav = BreadcrumbNav;
    window.App.DoubleClickLike = DoubleClickLike;
    window.App.ConfirmBeforeLeave = ConfirmBeforeLeave;
    window.App.CharCounter = CharCounter;
    window.App.TimestampTooltip = TimestampTooltip;
    window.App.TitleNotification = TitleNotification;
    window.App.SmoothScroll = SmoothScroll;
    window.App.AutoResizeTextarea = AutoResizeTextarea;
  }

  var ReadingProgress = {
    _bar: null,
    init: function () {
      this._bar = document.createElement('div');
      this._bar.className = 'reading-progress-bar';
      this._bar.id = 'readingProgressBar';
      document.body.prepend(this._bar);
      var self = this;
      window.addEventListener('scroll', throttle(function () {
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var docHeight = document.documentElement.scrollHeight - window.innerHeight;
        var progress = docHeight > 0 ? Math.min((scrollTop / docHeight) * 100, 100) : 0;
        self._bar.style.width = progress + '%';
      }, 50), { passive: true });
    }
  };

  var KeyboardShortcuts = {
    _panel: null,
    init: function () {
      var self = this;
      document.addEventListener('keydown', function (e) {
        if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey) {
          if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA')) return;
          e.preventDefault();
          self.toggle();
        }
        if (e.key === 'Escape' && self._panel && self._panel.style.display === 'flex') {
          self.hide();
        }
      });
    },
    _create: function () {
      if (this._panel) return;
      this._panel = document.createElement('div');
      this._panel.className = 'shortcuts-panel-overlay';
      this._panel.innerHTML = '<div class="shortcuts-panel">' +
        '<div class="shortcuts-header"><h3>键盘快捷键</h3><button class="shortcuts-close" onclick="document.querySelector(\'.shortcuts-panel-overlay\').style.display=\'none\'">&times;</button></div>' +
        '<div class="shortcuts-body">' +
        '<div class="shortcut-group"><h4>导航</h4>' +
        '<div class="shortcut-item"><kbd>G</kbd><kbd>H</kbd><span>返回首页</span></div>' +
        '<div class="shortcut-item"><kbd>G</kbd><kbd>P</kbd><span>发帖</span></div>' +
        '<div class="shortcut-item"><kbd>G</kbd><kbd>U</kbd><span>个人中心</span></div>' +
        '<div class="shortcut-item"><kbd>Esc</kbd><span>关闭弹窗</span></div>' +
        '</div>' +
        '<div class="shortcut-group"><h4>操作</h4>' +
        '<div class="shortcut-item"><kbd>Ctrl</kbd>+<kbd>Enter</kbd><span>快速发送评论</span></div>' +
        '<div class="shortcut-item"><kbd>Ctrl</kbd>+<kbd>F</kbd><span>聚焦搜索</span></div>' +
        '<div class="shortcut-item"><kbd>R</kbd><span>随机帖子</span></div>' +
        '<div class="shortcut-item"><kbd>?</kbd><span>显示/隐藏此面板</span></div>' +
        '</div>' +
        '<div class="shortcut-group"><h4>主题</h4>' +
        '<div class="shortcut-item"><kbd>T</kbd><span>切换深色/浅色主题</span></div>' +
        '<div class="shortcut-item"><kbd>+</kbd> / <kbd>-</kbd><span>调整字体大小</span></div>' +
        '</div>' +
        '</div></div>';
      document.body.appendChild(this._panel);
      this._panel.addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
      });
    },
    toggle: function () {
      this._create();
      this._panel.style.display = this._panel.style.display === 'flex' ? 'none' : 'flex';
    },
    hide: function () {
      if (this._panel) this._panel.style.display = 'none';
    }
  };

  var GlyphShortcuts = {
    _keys: {},
    init: function () {
      var self = this;
      document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA' || document.activeElement.isContentEditable)) return;
        var k = e.key.toLowerCase();
        if (k === 'g') { self._keys.g = true; return; }
        if (self._keys.g) {
          self._keys.g = false;
          if (k === 'h') { window.location.href = '/'; return; }
          if (k === 'p') { window.location.href = '/pages/post.php'; return; }
          if (k === 'u') { window.location.href = '/pages/user_center.php'; return; }
        }
        if (k === 'r' && !self._keys.g) {
          if (App.PostFeed && App.PostFeed.container) {
            App.PostFeed.loadPosts(true);
          }
          return;
        }
        if (k === 't') {
          if (typeof App.toggleTheme === 'function') App.toggleTheme();
          return;
        }
        if (k === '+' || k === '=') {
          if (FontSizeToggle) FontSizeToggle.increase();
          return;
        }
        if (k === '-') {
          if (FontSizeToggle) FontSizeToggle.decrease();
          return;
        }
      });
      document.addEventListener('keyup', function (e) {
        if (e.key.toLowerCase() === 'g') self._keys.g = false;
      });
    }
  };

  var SearchHistory = {
    _max: 5,
    _key: 'searchHistory',
    init: function () {
      var searchInput = document.querySelector('.search-input, #searchInput');
      if (!searchInput) return;
      var self = this;
      var dropdown = document.createElement('div');
      dropdown.className = 'search-history-dropdown';
      dropdown.style.display = 'none';
      searchInput.parentNode.style.position = 'relative';
      searchInput.parentNode.appendChild(dropdown);

      searchInput.addEventListener('focus', function () { self._show(searchInput, dropdown); });
      searchInput.addEventListener('blur', function () { setTimeout(function () { dropdown.style.display = 'none'; }, 200); });
      searchInput.addEventListener('input', function () { dropdown.style.display = 'none'; });

      dropdown.addEventListener('click', function (e) {
        var item = e.target.closest('.search-history-item');
        if (item) {
          searchInput.value = item.textContent.trim();
          searchInput.dispatchEvent(new Event('input', { bubbles: true }));
          dropdown.style.display = 'none';
        }
      });
    },
    _get: function () {
      try { return JSON.parse(localStorage.getItem(this._key)) || []; } catch (e) { return []; }
    },
    _save: function (term) {
      if (!term) return;
      var list = this._get().filter(function (t) { return t !== term; });
      list.unshift(term);
      if (list.length > this._max) list.pop();
      localStorage.setItem(this._key, JSON.stringify(list));
    },
    _show: function (input, dropdown) {
      var list = this._get();
      if (list.length === 0) { dropdown.style.display = 'none'; return; }
      dropdown.innerHTML = '<div class="search-history-header">最近搜索</div>' +
        list.map(function (t) { return '<div class="search-history-item">' + escapeHtml(t) + '</div>'; }).join('') +
        '<div class="search-history-clear" onclick="localStorage.removeItem(\'searchHistory\');this.parentElement.style.display=\'none\'">清除历史</div>';
      dropdown.style.display = 'block';
    },
    add: function (term) { this._save(term); }
  };

  var SmartTimeDisplay = {
    init: function () {
      document.addEventListener('mouseover', function (e) {
        var el = e.target.closest('.post-time, .comment-time');
        if (!el) return;
        var full = el.getAttribute('data-full-time');
        if (!full) {
          full = el.getAttribute('title') || el.textContent;
          el.setAttribute('data-full-time', full);
        }
        el.setAttribute('title', full);
      });
    }
  };

  var CommentFloor = {
    init: function () {
      var comments = document.querySelectorAll('.comment-item');
      if (comments.length === 0) return;
      comments.forEach(function (c, i) {
        if (!c.querySelector('.comment-floor')) {
          var floor = document.createElement('span');
          floor.className = 'comment-floor';
          floor.textContent = '#' + (i + 1);
          floor.style.cssText = 'font-size:11px;color:var(--text-muted);margin-right:6px;cursor:pointer;user-select:none;';
          floor.title = '点击复制楼层链接';
          floor.addEventListener('click', function (e) {
            e.stopPropagation();
            var id = c.id || ('comment-' + (i + 1));
            var url = window.location.origin + window.location.pathname + '#' + id;
            if (navigator.clipboard) {
              navigator.clipboard.writeText(url).then(function () {
                if (App.showToast) App.showToast('楼层链接已复制', 'success');
              });
            }
          });
          var header = c.querySelector('.comment-header');
          if (header) header.insertBefore(floor, header.firstChild);
        }
      });
    }
  };

  var QuickReplyTemplates = {
    _templates: ['👍 支持！', '❤️ 感谢分享', '😂 哈哈', '🤔 有道理', '👏 说得好', '💪 加油', '🌟 学到了', '🙏 谢谢'],
    init: function () {
      var textarea = document.getElementById('commentInput');
      if (!textarea) return;
      var container = document.createElement('div');
      container.className = 'quick-reply-templates';
      var self = this;
      this._templates.forEach(function (t) {
        var btn = document.createElement('button');
        btn.className = 'quick-reply-btn';
        btn.textContent = t;
        btn.type = 'button';
        btn.addEventListener('click', function () {
          textarea.value = (textarea.value ? textarea.value + ' ' : '') + t;
          textarea.focus();
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });
        container.appendChild(btn);
      });
      // 必须插进 textarea 的**直接父节点**：insertBefore 要求 refChild 是调用者的子节点，
      // 若用 closest('.comment-input-wrap') 而 textarea 又被包了一层（见 style.css 的
      // .comment-input-fields），就会抛 NotFoundError 把整个快捷栏渲染打断。
      var holder = textarea.parentNode || textarea.closest('.comment-input-wrap');
      if (holder) holder.insertBefore(container, textarea);
    }
  };

  var SelectionShare = {
    // 提取选区文本：剔除头衔徽章、楼层号、时间、按钮等噪音，并压缩空白
    extractText: function (range, fallback) {
      var text;
      try {
        var frag = range.cloneContents();
        var junk = frag.querySelectorAll('.user-title,.comment-floor,.comment-time,.comment-replies-toggle,.comment-actions,.selection-share-popup,.comment-avatar');
        for (var i = 0; i < junk.length; i++) {
          if (junk[i].parentNode) junk[i].parentNode.removeChild(junk[i]);
        }
        var holder = document.createElement('div');
        holder.appendChild(frag);
        text = holder.textContent || '';
      } catch (err) {
        text = fallback || '';
      }
      return text.replace(/[ \t\u00a0]+/g, ' ')
                 .split('\n')
                 .map(function (l) { return l.trim(); })
                 .filter(function (l) { return l.length > 0; })
                 .join('\n')
                 .trim();
    },
    init: function () {
      var popup = document.createElement('div');
      popup.className = 'selection-share-popup';
      popup.style.display = 'none';
      popup.innerHTML = '<button class="sel-share-btn" data-action="copy">复制</button><button class="sel-share-btn" data-action="quote">引用回复</button>';
      document.body.appendChild(popup);
      var self = this;
      document.addEventListener('mouseup', function (e) {
        setTimeout(function () {
          var sel = window.getSelection();
          var raw = (sel || '').toString().trim();
          if (!raw || raw.length < 3) { popup.style.display = 'none'; return; }
          var range = sel.getRangeAt(0);
          var text = self.extractText(range, raw);
          if (!text) { popup.style.display = 'none'; return; }
          var rect = range.getBoundingClientRect();
          popup.style.display = 'flex';
          popup.style.top = (rect.top + window.scrollY - 40) + 'px';
          popup.style.left = Math.min(rect.left + (rect.width / 2) - 50, window.innerWidth - 120) + 'px';
          popup.setAttribute('data-text', text);
        }, 10);
      });
      popup.addEventListener('click', function (e) {
        var btn = e.target.closest('.sel-share-btn');
        if (!btn) return;
        var text = popup.getAttribute('data-text');
        if (btn.dataset.action === 'copy') {
          navigator.clipboard.writeText(text).then(function () {
            if (App.showToast) App.showToast('已复制', 'success');
          });
        } else if (btn.dataset.action === 'quote') {
          var ta = document.getElementById('commentInput');
          if (ta) {
            ta.value = '> ' + text + '\n';
            ta.focus();
            window.scrollTo({ top: ta.getBoundingClientRect().top + window.scrollY - 200, behavior: 'smooth' });
          }
        }
        popup.style.display = 'none';
      });
      document.addEventListener('mousedown', function (e) {
        if (!popup.contains(e.target)) popup.style.display = 'none';
      });
    }
  };

  var RippleEffect = {
    init: function () {
      document.addEventListener('click', function (e) {
        var target = e.target.closest('.post-card, .btn, .stat-card, .widget');
        if (!target) return;
        if (target.querySelector('.ripple-effect')) return;
        var ripple = document.createElement('span');
        ripple.className = 'ripple-effect';
        var rect = target.getBoundingClientRect();
        var size = Math.max(rect.width, rect.height);
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
        target.style.position = target.style.position || 'relative';
        target.style.overflow = 'hidden';
        target.appendChild(ripple);
        setTimeout(function () { ripple.remove(); }, 600);
      });
    }
  };

  // 签到状态存服务端（api/checkin.php + data/checkins.json），不再使用 localStorage，
  // 因此同一账号在任何设备、任何页面看到的签到状态都一致。
  var DailyCheckIn = {
    _btn: null,
    _state: null,
    init: function () {
      // 未登录/游客不显示签到按钮：签到依赖服务端账号，避免出现点了没反应的按钮
      if (!App.IS_LOGGED_IN) return;
      var self = this;
      var btn = document.createElement('button');
      btn.className = 'checkin-btn';
      btn.id = 'checkinBtn';
      btn.innerHTML = '<span class="checkin-icon">🎯</span><span class="checkin-text">签到</span>';
      this._btn = btn;
      // 手机端收进用户面板弹层的「快捷操作」区，桌面端仍在顶栏
      if (!mountSecondary(btn, 'me-panel')) return;
      btn.addEventListener('click', function () { self._doCheckIn(); });

      this.refresh();
      // 切回本页时重新拉取，保证在别的标签页/别的设备签到后这里同步
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) self.refresh();
      });
    },

    /** 从服务端拉取签到状态并刷新按钮 */
    refresh: function () {
      var self = this;
      return App.fetchAPI('/api/checkin.php', { method: 'POST', body: 'action=status' })
        .then(function (res) { self._render((res && res.data) || {}); })
        .catch(function () {});
    },

    _render: function (d) {
      this._state = d;
      var btn = this._btn || document.getElementById('checkinBtn');
      if (!btn) return;
      var checked = !!d.checked_today;
      var icon = btn.querySelector('.checkin-icon');
      var text = btn.querySelector('.checkin-text');
      btn.classList.toggle('checked', checked);
      btn.title = '已连续签到 ' + (d.streak || 0) + ' 天，累计 ' + (d.total || 0) + ' 次';
      if (icon) icon.textContent = checked ? '✅' : '🎯';
      if (text) text.textContent = checked ? '已签到' : '签到';
    },

    _doCheckIn: function () {
      var self = this;
      var btn = this._btn || document.getElementById('checkinBtn');
      if (!btn || btn.disabled) return;
      if (this._state && this._state.checked_today) {
        App.showToast('今日已签到（连续 ' + (this._state.streak || 0) + ' 天）', 'warning');
        return;
      }
      btn.disabled = true;
      App.fetchAPI('/api/checkin.php', { method: 'POST', body: 'action=checkin' })
        .then(function (res) {
          btn.disabled = false;
          var d = (res && res.data) || {};
          self._render(d);
          if (!d.checked_today) return;
          var msg = '签到成功！连续 ' + (d.streak || 0) + ' 天';
          if (d.streak >= 30) msg += ' 🏆';
          else if (d.streak >= 7) msg += ' 🔥';
          App.showToast(msg, 'success');
          if ((d.streak || 0) % 7 === 0) self._celebrate();
        })
        .catch(function () { btn.disabled = false; });
    },
    _celebrate: function () {
      for (var i = 0; i < 12; i++) {
        setTimeout(function () {
          var confetti = document.createElement('div');
          confetti.className = 'confetti';
          confetti.style.cssText = 'position:fixed;z-index:9999;width:8px;height:8px;border-radius:50%;pointer-events:none;' +
            'left:' + Math.random() * 100 + 'vw;top:-10px;background:hsl(' + Math.random() * 360 + ',80%,60%);' +
            'animation:confettiFall ' + (1 + Math.random() * 2) + 's ease-in forwards;';
          document.body.appendChild(confetti);
          setTimeout(function () { confetti.remove(); }, 3000);
        }, i * 80);
      }
    }
  };

  var RandomPost = {
    init: function () {
      var btn = document.createElement('button');
      btn.className = 'btn btn-outline btn-sm random-post-btn';
      btn.innerHTML = '🎲 随便看看';
      btn.title = '随机查看一篇帖子 (快捷键: R)';
      if (!mountSecondary(btn, 'me-panel')) return;
      var self = this;
      btn.addEventListener('click', function () { self._go(); });
    },
    async _go() {
      try {
        var data = await App.fetchAPI('/api/posts/list.php?category=all&sort=latest&page=1&limit=100');
        var posts = (data.data && data.data.posts) || [];
        if (posts.length === 0) { App.showToast('暂无帖子', 'warning'); return; }
        var post = posts[Math.floor(Math.random() * posts.length)];
        window.location.href = '/pages/post_detail.php?id=' + post.id;
      } catch (e) { App.showToast('加载失败', 'error'); }
    }
  };

  var FunEmptyState = {
    _messages: [
      { icon: '📭', text: '这里空空如也，快来发第一帖吧！' },
      { icon: '🕳️', text: '掉进了一个黑洞...什么都没有' },
      { icon: '🌱', text: '等待种子发芽，就像等待第一个帖子' },
      { icon: '🎈', text: '气球飞走了，帖子也还没有' },
      { icon: '🍃', text: '风吹过，什么都没留下' },
      { icon: '✨', text: '期待你的第一条帖子点亮这里' },
      { icon: '🦋', text: '蝴蝶飞过，帖子还没来' },
      { icon: '💤', text: '大家都在睡觉，没人发帖' }
    ],
    init: function () {
      var el = document.querySelector('.empty-state p');
      if (!el) return;
      var msg = this._messages[Math.floor(Math.random() * this._messages.length)];
      el.innerHTML = '<span style="font-size:1.5em;display:block;margin-bottom:8px;">' + msg.icon + '</span>' + msg.text;
    }
  };

  var TypewriterAnnouncement = {
    init: function () {
      var bar = document.querySelector('.announcement-bar');
      if (!bar) return;
      var content = bar.querySelector('.announcement-text');
      if (!content) return;
      var text = content.textContent.trim();
      if (!text) return;
      content.textContent = '';
      content.style.borderRight = '2px solid var(--primary)';
      var i = 0;
      var self = this;
      var timer = setInterval(function () {
        if (i < text.length) {
          content.textContent += text[i];
          i++;
        } else {
          clearInterval(timer);
          content.style.borderRight = 'none';
        }
      }, 60);
    }
  };

  var StickyHeaderShadow = {
    init: function () {
      var header = document.querySelector('.site-header');
      if (!header) return;
      window.addEventListener('scroll', throttle(function () {
        if (window.scrollY > 10) header.classList.add('header-scrolled');
        else header.classList.remove('header-scrolled');
      }, 50), { passive: true });
    }
  };

  var DynamicFooterYear = {
    init: function () {
      var year = new Date().getFullYear();
      document.querySelectorAll('.site-footer, footer').forEach(function (f) {
        var p = f.querySelector('p');
        if (p && p.textContent.indexOf('202') >= 0) {
          p.textContent = p.textContent.replace(/\d{4}/, year);
        }
      });
    }
  };

  var DoubleTapToTop = {
    _lastTap: 0,
    init: function () {
      var self = this;
      document.addEventListener('click', function (e) {
        if (e.target.closest('button, a, input, textarea, .post-card, .modal')) return;
        var now = Date.now();
        if (now - self._lastTap < 400) {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        self._lastTap = now;
      });
    }
  };

  var NewCommentSlide = {
    init: function () {
      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType === 1 && node.classList.contains('comment-item')) {
              node.style.animation = 'commentSlideIn 0.4s ease';
            }
          });
        });
      });
      var list = document.querySelector('.comments-list');
      if (list) observer.observe(list, { childList: true });
    }
  };

  var OfflineIndicator = {
    _bar: null,
    init: function () {
      this._bar = document.createElement('div');
      this._bar.className = 'offline-bar';
      this._bar.textContent = '网络连接已断开，部分功能不可用';
      this._bar.style.display = 'none';
      document.body.prepend(this._bar);
      var self = this;
      window.addEventListener('online', function () {
        self._bar.style.display = 'none';
        self._bar.textContent = '网络连接已恢复';
        self._bar.classList.add('online');
        setTimeout(function () { self._bar.style.display = 'none'; }, 2000);
      });
      window.addEventListener('offline', function () {
        self._bar.style.display = 'block';
        self._bar.classList.remove('online');
      });
    }
  };

  var KonamiCode = {
    _seq: [38, 38, 40, 40, 37, 39, 37, 39, 66, 65],
    _pos: 0,
    init: function () {
      var self = this;
      document.addEventListener('keydown', function (e) {
        if (e.keyCode === self._seq[self._pos]) {
          self._pos++;
          if (self._pos === self._seq.length) {
            self._pos = 0;
            self._trigger();
          }
        } else {
          self._pos = 0;
        }
      });
    },
    _trigger: function () {
      document.body.style.transition = 'transform 0.5s';
      document.body.style.transform = 'rotate(360deg)';
      setTimeout(function () { document.body.style.transform = ''; }, 500);
      App.showToast('🎉 你发现了彩蛋！', 'success');
      var colors = ['#ff6b6b', '#ffd93d', '#6bcb77', '#4d96ff', '#ff6b9d', '#c44dff'];
      for (var i = 0; i < 30; i++) {
        setTimeout(function () {
          var spark = document.createElement('div');
          spark.style.cssText = 'position:fixed;z-index:99999;pointer-events:none;width:6px;height:6px;border-radius:50%;' +
            'left:' + Math.random() * 100 + 'vw;top:' + Math.random() * 100 + 'vh;' +
            'background:' + colors[Math.floor(Math.random() * colors.length)] + ';' +
            'animation:confettiFall 2s ease-out forwards;';
          document.body.appendChild(spark);
          setTimeout(function () { spark.remove(); }, 2000);
        }, i * 30);
      }
    }
  };

  var SwipeCategories = {
    init: function () {
      var container = document.querySelector('.category-filters');
      if (!container) return;
      var startX = 0, startY = 0;
      container.addEventListener('touchstart', function (e) {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
      }, { passive: true });
      container.addEventListener('touchend', function (e) {
        var dx = (e.changedTouches[0] || {}).clientX - startX;
        var dy = (e.changedTouches[0] || {}).clientY - startY;
        if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return;
        var btns = container.querySelectorAll('.category-btn');
        var active = container.querySelector('.category-btn.active');
        if (!active) return;
        var idx = Array.from(btns).indexOf(active);
        if (dx < 0 && idx < btns.length - 1) btns[idx + 1].click();
        if (dx > 0 && idx > 0) btns[idx - 1].click();
      });
    }
  };

  var CommentCharCounter = {
    init: function () {
      var ta = document.getElementById('commentInput');
      if (!ta) return;
      var counter = document.createElement('span');
      counter.className = 'comment-char-count';
      counter.style.cssText = 'font-size:11px;color:var(--text-muted);float:right;margin-top:4px;';
      var wrap = ta.closest('.comment-input-wrap');
      if (wrap) wrap.appendChild(counter);
      ta.addEventListener('input', function () {
        var len = ta.value.length;
        counter.textContent = len + '/500';
        if (len > 490) counter.style.color = 'var(--danger)';
        else if (len > 450) counter.style.color = 'var(--warning)';
        else counter.style.color = 'var(--text-muted)';
      });
    }
  };

  var EmojiReactions = {
    _emojis: ['👍', '❤️', '😂', '😮', '😢', '😡'],
    init: function () {
      if (!window.IS_LOGGED_IN) return;
      var self = this;
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.emoji-reaction-btn');
        if (!btn) return;
        var postId = btn.dataset.postId;
        var emoji = btn.dataset.emoji;
        self._toggle(postId, emoji, btn);
      });
      this._injectButtons();
      this._observe();
    },
    _toggle: async function (postId, emoji, btn) {
      try {
        var data = await App.fetchAPI('/api/posts/like.php', { method: 'POST', body: 'post_id=' + postId });
        btn.classList.toggle('active');
        var count = parseInt(btn.querySelector('.emoji-count').textContent) || 0;
        btn.querySelector('.emoji-count').textContent = btn.classList.contains('active') ? count + 1 : count - 1;
      } catch (e) { App.showToast('操作失败', 'error'); }
    },
    _injectButtons: function () {
      var self = this;
      document.querySelectorAll('.post-card').forEach(function (card) {
        if (card.querySelector('.emoji-reactions')) return;
        var postId = card.dataset.postId;
        if (!postId) return;
        var actions = card.querySelector('.post-card-actions');
        if (!actions) return;
        var container = document.createElement('div');
        container.className = 'emoji-reactions';
        self._emojis.forEach(function (emoji) {
          var btn = document.createElement('button');
          btn.className = 'emoji-reaction-btn';
          btn.dataset.postId = postId;
          btn.dataset.emoji = emoji;
          btn.innerHTML = emoji + ' <span class="emoji-count">0</span>';
          btn.title = '表达' + emoji;
          container.appendChild(btn);
        });
        actions.appendChild(container);
      });
    },
    _observe: function () {
      var self = this;
      var observer = new MutationObserver(function () {
        self._injectButtons();
      });
      var container = document.getElementById('postsContainer');
      if (container) observer.observe(container, { childList: true, subtree: true });
    }
  };

  var ImageLazyLoad = {
    init: function () {
      if (!window.IntersectionObserver) return;
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            var img = entry.target;
            if (img.dataset.src) {
              img.src = img.dataset.src;
              img.removeAttribute('data-src');
            }
            img.classList.add('lazy-loaded');
            observer.unobserve(img);
          }
        });
      }, { rootMargin: '200px' });
      document.querySelectorAll('img[loading="lazy"], img[data-src]').forEach(function (img) {
        observer.observe(img);
      });
    }
  };

  var CardHoverTilt = {
    init: function () {
      var self = this;
      document.addEventListener('mouseover', function (e) {
        var card = e.target.closest('.post-card');
        if (!card || card.classList.contains('tilt-active')) return;
        card.classList.add('tilt-active');
        card.addEventListener('mousemove', self._tilt);
        card.addEventListener('mouseleave', self._reset);
      });
    },
    _tilt: function (e) {
      var rect = this.getBoundingClientRect();
      var x = (e.clientX - rect.left) / rect.width;
      var y = (e.clientY - rect.top) / rect.height;
      var rotateX = (y - 0.5) * -4;
      var rotateY = (x - 0.5) * 4;
      this.style.transform = 'perspective(600px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg) translateY(-2px)';
    },
    _reset: function () {
      this.style.transform = '';
      this.classList.remove('tilt-active');
      this.removeEventListener('mousemove', CardHoverTilt._tilt);
      this.removeEventListener('mouseleave', CardHoverTilt._reset);
    }
  };

  var TitleUnreadCount = {
    _original: document.title,
    _count: 0,
    _timer: null,
    init: function () {
      this._original = document.title;
      var self = this;
      this._timer = setInterval(function () {
        if (self._count > 0 && !document.hidden) {
          self._count = 0;
          document.title = self._original;
        }
      }, 2000);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          self._count = 0;
          document.title = self._original;
        }
      });
    },
    increment: function () {
      this._count++;
      document.title = '(' + this._count + ') ' + this._original;
    },
    flash: function (msg) {
      var self = this;
      var i = 0;
      var timer = setInterval(function () {
        document.title = i % 2 === 0 ? msg : self._original;
        i++;
        if (i >= 6) { clearInterval(timer); document.title = self._original; }
      }, 800);
    }
  };

  var LongPressPreview = {
    _timer: null,
    init: function () {
      var self = this;
      document.addEventListener('touchstart', function (e) {
        var card = e.target.closest('.post-card');
        if (!card) return;
        self._timer = setTimeout(function () {
          var rect = card.getBoundingClientRect();
          var tooltip = document.createElement('div');
          tooltip.className = 'long-press-tooltip';
          tooltip.textContent = '松开发帖人主页';
          tooltip.style.cssText = 'position:fixed;z-index:999;background:var(--card-bg);border:1px solid var(--border);' +
            'border-radius:8px;padding:8px 16px;font-size:13px;box-shadow:0 4px 20px rgba(0,0,0,0.15);' +
            'top:' + (rect.top - 45) + 'px;left:' + (rect.left + rect.width / 2) + 'px;transform:translateX(-50%);pointer-events:none;';
          document.body.appendChild(tooltip);
          setTimeout(function () { tooltip.remove(); }, 1500);
        }, 600);
      }, { passive: true });
      document.addEventListener('touchend', function () { clearTimeout(self._timer); });
      document.addEventListener('touchmove', function () { clearTimeout(self._timer); });
    }
  };

  var FullscreenToggle = {
    _active: false,
    _bar: null,
    init: function () {
      var btn = document.createElement('button');
      btn.className = 'fullscreen-toggle-btn';
      btn.innerHTML = '⛶';
      btn.title = '沉浸式阅读';
      btn.style.cssText = 'position:fixed;bottom:80px;right:20px;z-index:100;width:40px;height:40px;border-radius:50%;' +
        'background:var(--card-bg);border:1px solid var(--border);cursor:pointer;font-size:18px;' +
        'box-shadow:0 2px 8px rgba(0,0,0,0.1);display:flex;align-items:center;justify-content:center;';
      document.body.appendChild(btn);
      var self = this;
      btn.addEventListener('click', function () { self.toggle(); });
    },
    toggle: function () {
      this._active = !this._active;
      var self = this;
      var els = document.querySelectorAll('.site-header, .site-footer, .sidebar, .widget, .mobile-bottom-nav');
      els.forEach(function (el) {
        el.style.display = self._active ? 'none' : '';
      });
      var contentEl = document.querySelector('.main-content, .page-content');
      if (contentEl) contentEl.style.maxWidth = this._active ? '900px' : '';
      App.showToast(this._active ? '沉浸式阅读模式' : '已退出沉浸式阅读', 'info');
    }
  };

  var LiveTimestamp = {
    _timer: null,
    init: function () {
      var self = this;
      this._timer = setInterval(function () { self._update(); }, 30000);
      this._update();
    },
    _update: function () {
      document.querySelectorAll('.post-time, .comment-time, .live-timestamp').forEach(function (el) {
        var ts = el.getAttribute('data-timestamp');
        if (!ts) return;
        var diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
        var text;
        if (diff < 60) text = '刚刚';
        else if (diff < 3600) text = Math.floor(diff / 60) + '分钟前';
        else if (diff < 86400) text = Math.floor(diff / 3600) + '小时前';
        else if (diff < 604800) text = Math.floor(diff / 86400) + '天前';
        else text = el.getAttribute('data-full-time') || el.textContent;
        el.textContent = text;
      });
    }
  };

  var HoverCard = {
    _card: null,
    _timer: null,
    init: function () {
      var self = this;
      document.addEventListener('mouseover', function (e) {
        var el = e.target.closest('.author-name, .comment-author');
        if (!el) { self._hide(); return; }
        var userId = el.getAttribute('data-user-id');
        if (!userId) return;
        clearTimeout(self._timer);
        self._timer = setTimeout(function () { self._show(el, userId); }, 500);
      });
      document.addEventListener('mouseout', function (e) {
        var el = e.target.closest('.author-name, .comment-author');
        if (el) { clearTimeout(self._timer); self._hide(); }
      });
    },
    _show: function (anchor, userId) {
      var self = this;
      if (!this._card) {
        this._card = document.createElement('div');
        this._card.className = 'hover-card';
        this._card.innerHTML = '<div class="hover-card-loading">加载中...</div>';
        document.body.appendChild(this._card);
      }
      var rect = anchor.getBoundingClientRect();
      this._card.style.top = (rect.bottom + window.scrollY + 8) + 'px';
      this._card.style.left = Math.min(rect.left + window.scrollX, window.innerWidth - 220) + 'px';
      this._card.style.display = 'block';
      App.fetchAPI('/api/user/profile.php?id=' + userId).then(function (data) {
        if (data.data) {
          var u = data.data;
          self._card.innerHTML = '<div class="hover-card-content">' +
            '<img src="' + App.esc(u.avatar || '/assets/images/default-avatar.svg') + '" class="hover-card-avatar" onerror="this.src=\'/assets/images/default-avatar.svg\'">' +
            '<div class="hover-card-info">' +
            '<div class="hover-card-name">' + App.esc(u.nickname || '用户') + '</div>' +
            '<div class="hover-card-stats">' + (u.post_count || 0) + ' 帖子 · ' + (u.comment_count || 0) + ' 评论</div>' +
            (u.bio ? '<div class="hover-card-bio">' + App.esc(u.bio) + '</div>' : '') +
            '</div></div>';
        }
      }).catch(function () { self._hide(); });
    },
    _hide: function () {
      if (this._card) this._card.style.display = 'none';
    }
  };

  /**
   * 帖子预览卡（桌面端 hover）
   * ---------------------------------------------------------------------------
   * **真正可达的落点是 `pages/topic.php` 的话题聚合项**（`.topic-item`）：
   * 那里的正文被 **服务端** `topicExcerpt()` 截到 120 字，全文根本不在 DOM 里，
   * 所以只能取接口 —— 这也正是「hover 摘要」有价值的地方。该页引了 main.js，
   * 增强模块正常初始化。
   *
   * 另一个选择器 `.uc-data-title`（用户中心列表）**目前不会生效** ——
   * `pages/user_center.php` 故意不引 main.js，enhancements.js 在 `!window.App` 时
   * 直接 return，整个模块集都不初始化。留着它只是为将来该页引入 main.js 时自动生效。
   *
   * **信息流卡片刻意不在范围内** —— 它本来就完整渲染标题与正文（`api/posts/list.php`
   * 返回的 content 不截断），再弹一层预览只是把同样的内容显示两遍。
   *
   * 数据源用 `/api/posts/detail.php?preview=1`（preview=1 会跳过整张 comments 表）：
   * 复用它而不是在 DOM 里拼摘要，是为了让预览同样受「状态 + 可见权限」约束 ——
   * 非 published / visible_to / exclude_to 都会被接口挡掉，预览不会变成绕过可见性的旁路。
   */
  var PostPreviewCard = {
    _card: null,
    _timer: null,
    _hideTimer: null,
    _cache: {},
    _postIdOf: function (el) {
      // .topic-item 与 .uc-data-title 都是指向帖子详情的 <a>，id 从 href 取
      var m = /[?&]id=(\d+)/.exec(el.getAttribute('href') || '');
      if (m) return m[1];
      var box = el.closest('[data-post-id]');
      return box ? box.getAttribute('data-post-id') : '';
    },
    /** 桌面端才启用：触屏没有 hover，长按由 LongPressPreview 负责 */
    _hoverable: function () {
      return !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
    },
    /**
     * 取数：优先复用 main.js 的 App.fetchAPI，没有则退回原生 fetch。
     * 兜底不是多余的防御：enhancements.js 的 App 取自 window.App，
     * 而 pages/user_center.php 这类页面**故意不引 main.js**（自带 loadList 与请求helper），
     * 直接写 App.fetchAPI 会在那些页面抛 TypeError。
     * 返回形状两者一致（都是解析后的 JSON），调用方无需区分。
     */
    _api: function (url) {
      if (App && typeof App.fetchAPI === 'function') return App.fetchAPI(url);
      return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); });
    },
    init: function () {
      var self = this;
      if (!this._hoverable()) return;
      document.addEventListener('mouseover', function (e) {
        if (!e.target || !e.target.closest) return;
        var anchor = e.target.closest('.topic-item, .uc-data-title');
        if (!anchor) return;
        var postId = self._postIdOf(anchor);
        if (!postId) return;
        clearTimeout(self._timer);
        self._timer = setTimeout(function () { self._show(anchor, postId); }, 420);
      });
      document.addEventListener('mouseout', function (e) {
        if (e.target && e.target.closest && e.target.closest('.topic-item, .uc-data-title')) {
          clearTimeout(self._timer);
          self._hideSoon();
        }
      });
      // 滚动/改窗口大小时收起：浮层用的是绝对定位 + 当时算出的坐标，不跟着视口走
      window.addEventListener('scroll', function () { self._hide(); }, { passive: true });
      window.addEventListener('resize', function () { self._hide(); });
    },
    _ensureCard: function () {
      if (this._card) return;
      var self = this;
      // **必须挂 body**：目标的祖先带 content-visibility:auto，会形成包含块与裁切边界，
      // 浮层放进卡片内部会被裁掉或错位（与 .hover-card 同一处理）。
      this._card = document.createElement('div');
      this._card.className = 'post-preview-card';
      document.body.appendChild(this._card);
      // 鼠标移到浮层上不收回，方便滚动长正文
      this._card.addEventListener('mouseenter', function () { clearTimeout(self._hideTimer); });
      this._card.addEventListener('mouseleave', function () { self._hide(); });
    },
    _show: function (anchor, postId) {
      var self = this;
      // 详情接口要求登录（requireMember）；游客不弹，避免刷出一串红色 toast
      if (!window.IS_LOGGED_IN) return;
      this._ensureCard();
      var rect = anchor.getBoundingClientRect();
      var card = this._card;
      card.style.display = 'block';
      card.style.top = (rect.bottom + window.scrollY + 8) + 'px';
      // 以锚点左缘起算，但不许越过视口右缘（宽度是 min(24rem, 100vw-2rem)）
      var maxLeft = window.innerWidth - Math.min(384, window.innerWidth - 32) - 12;
      card.style.left = Math.max(12, Math.min(rect.left + window.scrollX, maxLeft + window.scrollX)) + 'px';
      if (this._cache[postId]) {
        card.innerHTML = this._render(this._cache[postId]);
        return;
      }
      card.innerHTML = '<div class="ppc-loading">加载中…</div>';
      this._api('/api/posts/detail.php?id=' + encodeURIComponent(postId) + '&preview=1')
        .then(function (res) {
          var post = res && res.data ? res.data.post : null;
          if (!post) { self._hide(); return; }
          self._cache[postId] = post;
          // 请求返回时鼠标可能已经移开，此时不要再亮出来
          if (card.style.display === 'block') card.innerHTML = self._render(post);
        })
        .catch(function () { self._hide(); });
    },
    _render: function (post) {
      var esc = (window.App && (window.App.esc || window.App.escapeHtml)) || window.esc;
      if (typeof esc !== 'function') { esc = function (x) { return String(x == null ? '' : x); }; }
      var names = {
        announcement: '全站公告', lost_found: '寻物/失物招领', study_help: '学习求助',
        social_chat: '交友闲聊', confession: '表白', school_info: '校园打听', other: '其他'
      };
      var cat = post.category || 'other';
      var head = '<div class="ppc-head">' +
        '<span class="category-badge cat-' + esc(cat) + '">' + esc(names[cat] || cat) + '</span>' +
        (post.is_anonymous ? '<span class="anon-badge">匿名</span>' : '') +
        '<span class="ppc-time">' + esc(App.formatTime ? App.formatTime(post.created_at) : (post.created_at || '')) + '</span>' +
        '</div>';
      var title = post.title ? '<h4 class="ppc-title">' + esc(post.title) + '</h4>' : '';
      var body = post.content
        ? '<div class="ppc-body">' + esc(post.content).replace(/\n/g, '<br>') + '</div>'
        : '<div class="ppc-body ppc-empty">（无正文）</div>';
      var imgs = '';
      if (post.images && post.images.length) {
        imgs = '<div class="ppc-images">' + post.images.slice(0, 3).map(function (src) {
          return '<img src="' + esc(src) + '" alt="" loading="lazy" decoding="async" onerror="this.style.display=\'none\'">';
        }).join('') + '</div>';
      }
      var foot = '<div class="ppc-foot">' +
        '<span>' + esc(post.is_anonymous ? '匿名用户' : (post.author_nickname || '匿名用户')) + '</span>' +
        '<span>' + (post.likes || 0) + ' 赞</span>' +
        '<span>' + (post.comments || 0) + ' 评论</span>' +
        '<span class="ppc-hint">点击查看详情</span>' +
        '</div>';
      return head + title + body + imgs + foot;
    },
    /** 延迟收起：给鼠标一点时间从锚点移到浮层上 */
    _hideSoon: function () {
      var self = this;
      clearTimeout(this._hideTimer);
      this._hideTimer = setTimeout(function () { self._hide(); }, 240);
    },
    _hide: function () {
      clearTimeout(this._timer);
      if (this._card) this._card.style.display = 'none';
    }
  };

  /**
   * 富文本标记渲染（#话题 / @提及 / 裸链接）—— 全站唯一实现。
   *
   * 取代了原先四份各自为政、且都已经坏掉的实现：
   *   AtMentionHighlight（把 @xx 包成不可点的 <span>）、AutoLinkify、
   *   HashtagSystem（链到无人处理的 /index.php?tag=，是死链）、
   *   UserMention（@(\w+) 匹配不了中文昵称，且指向无人处理的 ?user=）。
   *
   * 关键差别：它们都是 `el.innerHTML = el.innerHTML.replace(...)`，会把正文里
   * 已经存在的 <a>/<img> 标签一起拿去正则匹配，把标签属性切坏（例如图片地址里的 # 被
   * 当成话题）。本实现改为**只遍历文本节点**，跳过 <a>/<code>/<pre> 内部，
   * 不触碰任何已有标签，因此对服务端已渲染好的内容重复执行也是安全的。
   *
   * 与服务端 includes/text_linkify.php 的字符集、跳转地址保持一一对应；
   * 服务端负责首屏（帖子详情/评论/话题页/用户主页），本模块负责动态插入的内容
   * （信息流卡片、AJAX 加载的回复、刚发布的评论）。
   */
  var RichText = {
    SELECTOR: '.post-card-content, .post-detail-content, .comment-text, .topic-item-body, .u-post-body, .u-bio',
    _TAG_RE: /#([0-9A-Za-z_\u4e00-\u9fff]{1,30})/g,
    _MENTION_RE: /@([0-9A-Za-z_\u4e00-\u9fff.\-]{1,24})/g,
    _URL_RE: /(https?:\/\/[^\s<]+)/g,
    _BOUNDARY_RE: /[0-9A-Za-z_\u4e00-\u9fff#@&]/,

    init: function () {
      this.scan(document.body);
      this._observe();
    },

    /** 扫描一个子树（已经处理过的容器会被 data-richtext 标记跳过） */
    scan: function (root) {
      if (!root) return;
      var self = this;
      var targets = [];
      if (root.nodeType === 1 && root.matches && root.matches(this.SELECTOR)) {
        targets.push(root);
      }
      if (root.querySelectorAll) {
        Array.prototype.forEach.call(root.querySelectorAll(this.SELECTOR), function (el) {
          targets.push(el);
        });
      }
      targets.forEach(function (el) { self._process(el); });
    },

    _process: function (el) {
      if (el.getAttribute('data-richtext') === '1') return;
      el.setAttribute('data-richtext', '1');

      var walker = document.createTreeWalker(el, 4 /* SHOW_TEXT */, null, false);
      var nodes = [];
      var n;
      while ((n = walker.nextNode())) {
        var v = n.nodeValue;
        if (!v || (v.indexOf('#') < 0 && v.indexOf('@') < 0 && v.indexOf('http') < 0)) continue;
        // 已有链接、代码块里的内容不动，避免把标签结构或代码示例改形
        if (n.parentNode && n.parentNode.closest && n.parentNode.closest('a, code, pre')) continue;
        nodes.push(n);
      }
      for (var i = 0; i < nodes.length; i++) {
        this._replace(nodes[i]);
      }
    },

    /** 文本节点 => 文本 + <a> 片段；没有任何可替换内容时保持原节点不动 */
    _replace: function (node) {
      var text = node.nodeValue;
      var frag = document.createDocumentFragment();
      var last = 0;

      var emit = function (link) {
        if (link.index > last) {
          frag.appendChild(document.createTextNode(text.slice(last, link.index)));
        }
        frag.appendChild(link.node);
        last = link.index + link.length;
      };

      var marks = [];
      var collect = function (re, order) {
        re.lastIndex = 0;
        var m;
        while ((m = re.exec(text)) !== null) {
          if (m.index > 0 && RichText._BOUNDARY_RE.test(text.charAt(m.index - 1))) continue;
          marks.push({ index: m.index, length: m[0].length, raw: m[1] || m[0], order: order });
        }
      };
      collect(this._TAG_RE, 0);      // 话题
      collect(this._MENTION_RE, 1);  // 提及
      collect(this._URL_RE, 2);      // 裸链接

      if (!marks.length) return;

      // 同一位置多种规则命中时只保留优先级最高的一个（话题 > 提及 > 链接），
      // 并按出现顺序排列，重叠的后者直接丢弃，避免嵌套生成非法结构。
      marks.sort(function (a, b) {
        return a.index - b.index || a.order - b.order;
      });

      for (var i = 0; i < marks.length; i++) {
        var mk = marks[i];
        if (mk.index < last) continue; // 与前一个标记重叠，跳过
        var a = document.createElement('a');
        if (mk.order === 0) {
          a.className = 'hashtag-link';
          a.setAttribute('data-tag', mk.raw);
          a.href = '/pages/topic.php?tag=' + encodeURIComponent(mk.raw);
          a.textContent = '#' + mk.raw;
        } else if (mk.order === 1) {
          a.className = 'mention-link';
          a.setAttribute('data-nick', mk.raw);
          a.href = '/pages/u.php?nick=' + encodeURIComponent(mk.raw);
          a.textContent = '@' + mk.raw;
        } else {
          var clean = mk.raw.replace(/[.,;:!?」』）)]+$/, '');
          if (!clean) continue;
          a.className = 'auto-link';
          a.href = clean;
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          a.textContent = clean;
          mk.length = clean.length;
        }
        emit(mk);
      }

      if (last === 0) return; // 一个都没替换成功
      if (last < text.length) {
        frag.appendChild(document.createTextNode(text.slice(last)));
      }
      node.parentNode.replaceChild(frag, node);
    },

    _observe: function () {
      var self = this;
      var queued = [];
      var scheduled = false;
      var flush = function () {
        scheduled = false;
        var batch = queued;
        queued = [];
        batch.forEach(function (node) { self.scan(node); });
      };
      var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var added = mutations[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            if (added[j].nodeType === 1) queued.push(added[j]);
          }
        }
        if (!queued.length || scheduled) return;
        scheduled = true;
        // 信息流一次会插入很多卡片，合帧处理，避免逐条触发重排
        if (window.requestAnimationFrame) requestAnimationFrame(flush);
        else setTimeout(flush, 16);
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  };

  var PostShare = {
    init: function () {
      var self = this;
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.share-btn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        self._open(btn.dataset.postId || btn.dataset.url || window.location.href);
      });
    },
    _open: function (ref) {
      // 卡片分享按钮只带 data-post-id（纯数字），需还原成完整详情链接；
      // 详情页内若直接传 URL 则原样使用。
      var url = ref ? String(ref) : '';
      if (!url || /^\d+$/.test(url)) {
        var id = url || (document.querySelector('.post-detail-card') ? document.querySelector('.post-detail-card').getAttribute('data-post-id') : '');
        url = id ? (window.SITE_URL || '') + '/pages/post_detail.php?id=' + id : window.location.href;
      }
      var panel = document.createElement('div');
      panel.className = 'share-panel-overlay';
      panel.innerHTML = '<div class="share-panel">' +
        '<div class="share-panel-header"><h3>分享</h3><button class="share-panel-close">&times;</button></div>' +
        '<div class="share-panel-body">' +
        '<div class="share-input-row"><input type="text" value="' + App.esc(url) + '" readonly class="share-url-input" id="shareUrlInput"><button class="btn btn-sm btn-primary share-copy-btn">复制</button></div>' +
        '<div class="share-platforms">' +
        '<button class="share-platform-btn" data-platform="weibo" title="分享到微博">微博</button>' +
        '<button class="share-platform-btn" data-platform="qq" title="分享到QQ">QQ</button>' +
        '<button class="share-platform-btn" data-platform="wechat" title="微信分享">微信</button>' +
        '<button class="share-platform-btn" data-platform="copy" title="复制链接">复制</button>' +
        '</div></div></div>';
      document.body.appendChild(panel);
      var self = this;
      panel.addEventListener('click', function (e) {
        if (e.target === panel || e.target.classList.contains('share-panel-close')) {
          panel.remove();
        }
      });
      panel.querySelector('.share-copy-btn').addEventListener('click', function () {
        var input = document.getElementById('shareUrlInput');
        input.select();
        document.execCommand('copy');
        App.showToast('链接已复制', 'success');
      });
      panel.querySelectorAll('.share-platform-btn').forEach(function (b) {
        b.addEventListener('click', function () {
          var p = this.dataset.platform;
          var title = document.title;
          if (p === 'weibo') window.open('https://service.weibo.com/share/share.php?url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(title));
          else if (p === 'qq') window.open('https://connect.qq.com/widget/shareqq/index.html?url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(title));
          else if (p === 'copy') {
            document.getElementById('shareUrlInput').select();
            document.execCommand('copy');
            App.showToast('链接已复制', 'success');
          }
        });
      });
    }
  };

  var DragDropUpload = {
    init: function () {
      var zones = document.querySelectorAll('.upload-area, .post-form, #imagePreviewContainer');
      if (zones.length === 0) return;
      var self = this;
      zones.forEach(function (zone) {
        zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('drag-over'); });
        zone.addEventListener('dragleave', function () { zone.classList.remove('drag-over'); });
        zone.addEventListener('drop', function (e) {
          e.preventDefault();
          zone.classList.remove('drag-over');
          var files = e.dataTransfer.files;
          var fileInput = document.querySelector('input[type="file"][accept*="image"]');
          if (fileInput && files.length > 0) {
            var dt = new DataTransfer();
            for (var i = 0; i < files.length; i++) { dt.items.add(files[i]); }
            fileInput.files = dt.files;
            fileInput.dispatchEvent(new Event('change', { bubbles: true }));
          }
        });
      });
      document.addEventListener('dragover', function (e) { e.preventDefault(); });
      document.addEventListener('drop', function (e) { e.preventDefault(); });
    }
  };

  var PostCollapse = {
    _maxHeight: 300,
    init: function () {
      var self = this;
      document.querySelectorAll('.post-card-content, .post-detail-content').forEach(function (el) {
        if (el.scrollHeight <= self._maxHeight + 20) return;
        el.classList.add('collapsed-content');
        el.style.maxHeight = self._maxHeight + 'px';
        el.style.overflow = 'hidden';
        var btn = document.createElement('button');
        btn.className = 'collapse-toggle-btn';
        btn.textContent = '展开全文';
        btn.addEventListener('click', function () {
          if (el.classList.contains('collapsed-content')) {
            el.classList.remove('collapsed-content');
            el.style.maxHeight = '';
            btn.textContent = '收起';
          } else {
            el.classList.add('collapsed-content');
            el.style.maxHeight = self._maxHeight + 'px';
            btn.textContent = '展开全文';
          }
        });
        el.parentNode.insertBefore(btn, el.nextSibling);
      });
    }
  };

  var ContextMenu = {
    _menu: null,
    init: function () {
      var self = this;
      this._menu = document.createElement('div');
      this._menu.className = 'context-menu';
      this._menu.style.display = 'none';
      document.body.appendChild(this._menu);
      document.addEventListener('contextmenu', function (e) {
        var card = e.target.closest('.post-card');
        if (!card) { self._menu.style.display = 'none'; return; }
        e.preventDefault();
        self._menu.innerHTML = '';
        self._menu.style.display = 'block';
        self._menu.style.top = e.pageY + 'px';
        self._menu.style.left = Math.min(e.pageX, window.innerWidth - 180) + 'px';
        var postId = card.dataset.postId;
        var items = [
          { label: '在新标签页打开', action: function () { window.open('/pages/post_detail.php?id=' + postId); } },
          { label: '复制链接', action: function () { navigator.clipboard.writeText(window.location.origin + '/pages/post_detail.php?id=' + postId); App.showToast('已复制', 'success'); } },
          { label: '复制内容', action: function () { var c = card.querySelector('.post-card-content'); if (c) navigator.clipboard.writeText(c.textContent); App.showToast('已复制', 'success'); } },
          { label: '举报', action: function () { App.showToast('举报功能开发中', 'info'); } }
        ];
        items.forEach(function (item) {
          var div = document.createElement('div');
          div.className = 'context-menu-item';
          div.textContent = item.label;
          div.addEventListener('click', function () { item.action(); self._menu.style.display = 'none'; });
          self._menu.appendChild(div);
        });
      });
      document.addEventListener('click', function () { self._menu.style.display = 'none'; });
    }
  };

  var ScrollMemory = {
    _key: 'scrollPositions',
    init: function () {
      var self = this;
      var path = window.location.pathname + window.location.search;
      var saved = this._get();
      // 只在「浏览器后退/前进」时还原滚动位置。
      // 全新进入（从列表点开帖子、点链接跳转）必须停在页面顶部 —— 否则只要该地址
      // 之前被访问过，一进详情页就会被拉到上次读到的位置（表现为「点开帖子直接掉到最下面」）。
      if (this._isBackForward() && saved[path] && !window.location.hash) {
        this._restoreWhenReady(saved[path]);
      } else if (!this._isBackForward() && saved[path]) {
        // 前向进入时清掉旧记录，避免它一直留着影响后续后退
        delete saved[path];
        this._set(saved);
      }
      var remember = function () {
        var keep = self._get();
        keep[path] = window.pageYOffset || document.documentElement.scrollTop;
        self._set(keep);
      };
      // pagehide 比 beforeunload 可靠：进入 bfcache、移动端切后台都不会漏记。
      window.addEventListener('pagehide', remember);
      window.addEventListener('beforeunload', remember);
      // 兜底：App WebView / 部分移动浏览器在「切后台后被杀掉」时不一定发 pagehide，
      // 但一定会走 visibilitychange → hidden。少存一次顶多丢最后几屏，不存就必然回顶部。
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') remember();
      });
    },
    /** 本次加载是否来自后退/前进（Navigation Timing 标准字段） */
    _isBackForward: function () {
      try {
        var entries = window.performance && performance.getEntriesByType
          ? performance.getEntriesByType('navigation')
          : null;
        if (entries && entries[0] && entries[0].type === 'back_forward') return true;
        // 老实现（performance.navigation.type === 2 = back_forward）作为兜底：
        // 个别 WebView 不填 Navigation Timing 的新字段，但填这个。
        var legacy = window.performance && window.performance.navigation;
        return !!(legacy && legacy.type === 2);
      } catch (e) {
        return false;
      }
    },
    /**
     * 还原滚动位置 —— 不是「滚一次就完事」，而是「滚到真到位为止」。
     *
     * 为什么不能只滚一次：还原的唯一时机是「信息流首屏卡片刚插入」，
     * 而这一刻页面往往还很矮 —— 图片没解码、content-visibility 的卡片还是
     * contain-intrinsic-size 占位（160px / 移动端 140px）。此时
     * window.scrollTo(0, y) 会被浏览器**静默截断**到当时的 maxScroll，
     * 之后页面长高了也没人再滚一次。
     * 实测：离开首页时 y=2423，返回后停在 1872（差 551px）；用户滚得越深、
     * 设备越慢，截断越狠，看起来就是「从详情出来后回到顶部」。
     *
     * 所以改成自我校正：
     *   1. 够高就直接对位；
     *   2. 不够高就先贴到底，并借「加载更多」把后面的页拉上来（main.js 的
     *      无限滚动靠 scroll 事件，贴到已到底的位置不会再触发，必须主动点）；
     *   3. 每 80ms 复查一次，直到「到位」或「高度连续 1.5s 不再增长（真的拉不动了）」。
     * 用户一旦有滚轮 / 触摸 / 按键 / 鼠标动作立刻收手 —— 绝不跟真人抢滚动条。
     */
    _restoreWhenReady: function (y) {
      var target = Number(y) || 0;
      if (target <= 0) return;
      var finished = false;
      var timer = null;
      var lastHeight = 0;
      var lastGrow = Date.now();
      var startedAt = 0;
      var cleanups = [];
      // 诊断口：e2e 探针靠它判断「到底有没有跑到位、是被谁停下的」。
      // 只读、无副作用，留着比每次出问题都加日志再上线划算。
      var dbg = { target: target, tries: 0, stoppedBy: '', y: 0, max: 0, h: 0, loads: 0 };
      try { window.__lwScrollRestore = dbg; } catch (e) {}

      var stop = function (why) {
        if (finished) return;
        finished = true;
        dbg.stoppedBy = why || 'done';
        if (timer) { clearInterval(timer); timer = null; }
        for (var i = 0; i < cleanups.length; i++) { try { cleanups[i](); } catch (e) {} }
        cleanups = [];
      };

      var onUser = function () { stop('user'); };
      ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(function (ev) {
        window.addEventListener(ev, onUser, { passive: true, capture: true });
        cleanups.push(function () { window.removeEventListener(ev, onUser, { capture: true }); });
      });

      var step = function () {
        if (finished) return;
        if (!startedAt) startedAt = Date.now();
        // 安全阀：无论高度怎么长，绝不无限期地跟用户抢滚动条
        if (Date.now() - startedAt > 25000) { stop('timeout'); return; }
        dbg.tries++;
        var max = document.documentElement.scrollHeight - window.innerHeight;
        dbg.max = max;
        var now = window.pageYOffset || document.documentElement.scrollTop || 0;
        dbg.y = now;
        dbg.h = document.documentElement.scrollHeight;
        if (max >= target - 2) {
          if (now !== target) window.scrollTo(0, target);
          dbg.y = window.pageYOffset || 0;
          if (Math.abs(dbg.y - target) <= 2) { stop('reached'); return; }
        } else {
          // 页面还不够高：先贴到底，再主动点「加载更多」补页
          window.scrollTo(0, max > 0 ? max : 0);
          var btn = document.getElementById('loadMoreBtn');
          if (btn && !btn.disabled && btn.offsetParent !== null) {
            btn.click();
            dbg.loads++;
            // 这次高度增长是我们自己发起的加载，给足它往返时间，别在等网络时就判「拉不动了」
            lastGrow = Date.now();
          }
        }
        var h = document.documentElement.scrollHeight;
        if (h > lastHeight) { lastHeight = h; lastGrow = Date.now(); }
        else if (Date.now() - lastGrow > 2000) { stop('stalled'); }
      };

      var begin = function () {
        // 交给我们的还原逻辑独家负责滚动，否则浏览器自己的历史滚动还原会和我们抢，
        // 两边各滚一次、后一次被前一次截断（实测差 786px 就是这么来的）。
        try { if ('scrollRestoration' in history) history.scrollRestoration = 'manual'; } catch (e) {}

        // ★ 关键：style.css 里有 html { scroll-behavior: smooth }，
        //   它会把 window.scrollTo 变成**异步平滑动画**，于是
        //   ① 紧跟着读 pageYOffset 还是旧值 —— 循环会误判「没滚成功」；
        //   ② 动画真正执行时页面若还矮，目标就被静默截断，之后没人补正。
        //   还原期间临时压成 auto（内联样式优先于样式表），收尾时原样还原。
        var rootStyle = document.documentElement.style;
        var prevBehavior = rootStyle.scrollBehavior;
        rootStyle.scrollBehavior = 'auto';
        cleanups.push(function () { rootStyle.scrollBehavior = prevBehavior || ''; });

        lastHeight = document.documentElement.scrollHeight;
        lastGrow = Date.now();
        // ⚠️ 不要用 requestAnimationFrame 当启动器：页面不被合成（后台标签页、WebView 不可见、
        //    无头浏览器某些时刻）时 rAF 会被节流甚至根本不触发，循环就一次都不跑 ——
        //    表现和「完全没做还原」一模一样（实测在 .min 产物上抓到 tries:0）。
        //    这里直接开跑，早一帧滚也没关系：循环每 80ms 复查，会被后续渲染覆盖就再滚一次。
        step();
        if (!finished) timer = setInterval(step, 80);
      };

      var container = document.getElementById('postsContainer');
      // 非信息流页面（个人中心、工具页等）：没有异步列表，直接还原
      if (!container || container.querySelector('.post-card') || !window.MutationObserver) {
        setTimeout(begin, 80);
        return;
      }
      var observer = new MutationObserver(function () {
        if (!container.querySelector('.post-card')) return;
        observer.disconnect();
        begin();
      });
      observer.observe(container, { childList: true, subtree: true });
      cleanups.push(function () { observer.disconnect(); });
    },

    _get: function () {
      try { return JSON.parse(sessionStorage.getItem(this._key)) || {}; } catch (e) { return {}; }
    },
    _set: function (data) {
      try { sessionStorage.setItem(this._key, JSON.stringify(data)); } catch (e) {}
    }
  };

  var NotificationSound = {
    _enabled: true,
    _ctx: null,
    init: function () {
      this._enabled = localStorage.getItem('notifSound') !== '0';
      try { this._ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
    },
    play: function (type) {
      if (!this._enabled || !this._ctx) return;
      var ctx = this._ctx;
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      if (type === 'success') { osc.frequency.value = 880; gain.gain.value = 0.1; }
      else if (type === 'error') { osc.frequency.value = 220; gain.gain.value = 0.1; }
      else { osc.frequency.value = 660; gain.gain.value = 0.08; }
      osc.start();
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
      osc.stop(ctx.currentTime + 0.3);
    },
    toggle: function () {
      this._enabled = !this._enabled;
      localStorage.setItem('notifSound', this._enabled ? '1' : '0');
    }
  };

  var PostHistory = {
    _key: 'postHistory',
    _max: 20,
    init: function () {
      var postId = new URLSearchParams(window.location.search).get('id');
      if (postId && window.location.pathname.includes('post_detail')) {
        this._add(postId, document.title);
      }
    },
    _add: function (id, title) {
      var list = this._get();
      list = list.filter(function (h) { return h.id !== id; });
      list.unshift({ id: id, title: title, time: Date.now() });
      if (list.length > this._max) list.pop();
      localStorage.setItem(this._key, JSON.stringify(list));
    },
    _get: function () {
      try { return JSON.parse(localStorage.getItem(this._key)) || []; } catch (e) { return []; }
    },
    getList: function () { return this._get(); },
    clear: function () { localStorage.removeItem(this._key); }
  };

  var AutoRefresh = {
    _timer: null,
    _interval: 120000,
    init: function () {
      if (!App.PostFeed || !document.getElementById('postsContainer')) return;
      var self = this;
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          self._start();
        } else {
          self._stop();
        }
      });
      this._start();
    },
    _start: function () {
      var self = this;
      this._stop();
      this._timer = setInterval(function () {
        if (App.PostFeed && App.PostFeed.currentPage === 1) {
          App.PostFeed.loadPosts(true);
        }
      }, this._interval);
    },
    _stop: function () {
      if (this._timer) { clearInterval(this._timer); this._timer = null; }
    }
  };

  var PageTransition = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '.page-transition-enter { animation: pageFadeIn 0.3s ease; }' +
        '@keyframes pageFadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }';
      document.head.appendChild(style);
      document.querySelector('.site-main, .page-content') && document.querySelector('.site-main, .page-content').classList.add('page-transition-enter');
    }
  };

  var FocusMode = {
    _active: false,
    _overlay: null,
    init: function () {
      var ta = document.querySelector('textarea[name="content"], #content');
      if (!ta) return;
      var self = this;
      var btn = document.createElement('button');
      btn.className = 'focus-mode-btn';
      btn.innerHTML = '✎ 专注模式';
      btn.type = 'button';
      btn.style.cssText = 'font-size:12px;padding:4px 10px;border:1px solid var(--border);border-radius:4px;background:var(--card-bg);cursor:pointer;color:var(--text-secondary);margin-left:8px;';
      btn.addEventListener('click', function () { self.toggle(ta); });
      var label = ta.closest('.form-group') || ta.parentNode;
      if (label) {
        var labelEl = label.querySelector('label');
        if (labelEl) labelEl.appendChild(btn);
        else label.insertBefore(btn, ta);
      }
      ta.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && self._active) { self.toggle(ta); }
      });
    },
    toggle: function (ta) {
      this._active = !this._active;
      if (this._active) {
        if (!this._overlay) {
          this._overlay = document.createElement('div');
          this._overlay.className = 'focus-mode-overlay';
          document.body.appendChild(this._overlay);
        }
        this._overlay.style.display = 'block';
        ta.style.position = 'relative';
        ta.style.zIndex = '300';
        ta.style.fontSize = '18px';
        ta.style.lineHeight = '1.8';
        ta.style.padding = '24px';
        ta.style.minHeight = '60vh';
        ta.focus();
        document.body.style.overflow = 'hidden';
      } else {
        if (this._overlay) this._overlay.style.display = 'none';
        ta.style.position = '';
        ta.style.zIndex = '';
        ta.style.fontSize = '';
        ta.style.lineHeight = '';
        ta.style.padding = '';
        ta.style.minHeight = '';
        document.body.style.overflow = '';
      }
    }
  };

  var PostWatermark = {
    init: function () {
      if (!window.USER_DATA) return;
      var username = (window.USER_DATA && window.USER_DATA.nickname) || '用户';
      var canvas = document.createElement('canvas');
      canvas.width = 200;
      canvas.height = 100;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = 'rgba(0,0,0,0.04)';
      ctx.font = '14px sans-serif';
      ctx.rotate(-0.3);
      ctx.fillText(username, 10, 50);
      var bg = 'url(' + canvas.toDataURL() + ')';
      var style = document.createElement('style');
      style.textContent = '.post-detail-content::before { content:"";position:absolute;inset:0;background:' + bg + ';pointer-events:none;z-index:0; }';
      document.head.appendChild(style);
    }
  };

  var EmojiPicker = {
    _emojis: ['😀','😂','🤣','😊','😍','🤩','😎','🥳','😢','😡','👍','👎','❤️','💔','🔥','⭐','🎉','💯','🙏','🤝','👀','💪','✨','🌟','🎵','📚','✍️','🎯','🏆','💡','🌈','🎈','🍀','☕','🎂','🍕','🚀','💻','📱','🎮','😴'],
    init: function () {
      var self = this;
      document.querySelectorAll('textarea').forEach(function (ta) {
        if (ta.closest('.emoji-picker-wrap')) return;
        var wrap = document.createElement('div');
        wrap.className = 'emoji-picker-wrap';
        wrap.style.position = 'relative';
        ta.parentNode.insertBefore(wrap, ta);
        wrap.appendChild(ta);
        var btn = document.createElement('button');
        btn.className = 'emoji-picker-btn';
        btn.textContent = '😊';
        btn.type = 'button';
        btn.title = '表情';
        btn.style.cssText = 'position:absolute;right:8px;bottom:8px;z-index:2;';
        wrap.appendChild(btn);
        var panel = document.createElement('div');
        panel.className = 'emoji-panel';
        panel.style.display = 'none';
        self._emojis.forEach(function (emoji) {
          var span = document.createElement('span');
          span.className = 'emoji-item';
          span.textContent = emoji;
          span.style.cssText = 'cursor:pointer;';
          span.addEventListener('click', function () {
            var start = ta.selectionStart;
            ta.value = ta.value.slice(0, start) + emoji + ta.value.slice(ta.selectionEnd);
            ta.focus();
            ta.setSelectionRange(start + emoji.length, start + emoji.length);
            panel.style.display = 'none';
          });
          panel.appendChild(span);
        });
        wrap.appendChild(panel);
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          panel.style.display = panel.style.display === 'none' ? 'grid' : 'none';
        });
        document.addEventListener('click', function (e) {
          if (!wrap.contains(e.target)) panel.style.display = 'none';
        });
      });
    }
  };

  var CommentSort = {
    _order: 'newest',
    init: function () {
      var container = document.querySelector('.comments-list');
      if (!container) return;
      var header = document.querySelector('.comments-section h3') || document.querySelector('.comments-title');
      if (!header) return;
      var self = this;
      var sortBar = document.createElement('div');
      sortBar.className = 'comment-sort-bar';
      sortBar.innerHTML = '<button class="comment-sort-btn active" data-sort="newest">最新</button>' +
        '<button class="comment-sort-btn" data-sort="oldest">最早</button>' +
        '<button class="comment-sort-btn" data-sort="hot">最热</button>';
      header.parentNode.insertBefore(sortBar, header.nextSibling);
      sortBar.addEventListener('click', function (e) {
        var btn = e.target.closest('.comment-sort-btn');
        if (!btn) return;
        self._order = btn.dataset.sort;
        sortBar.querySelectorAll('.comment-sort-btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        self._sort();
      });
    },
    _sort: function () {
      var container = document.querySelector('.comments-list');
      if (!container) return;
      var items = Array.from(container.querySelectorAll('.comment-item'));
      if (this._order === 'newest') items.reverse();
      else if (this._order === 'hot') items.sort(function (a, b) { return (parseInt(b.dataset.likes || 0) - parseInt(a.dataset.likes || 0)); });
      items.forEach(function (item) { container.appendChild(item); });
    }
  };

  var TagCloud = {
    init: function () {
      var sidebar = document.querySelector('.sidebar');
      if (!sidebar) return;
      var tags = ['失物招领', '学习求助', '交友闲聊', '表白墙', '校园打听', '活动通知', '二手交易', '求助', '分享', '讨论'];
      var widget = document.createElement('div');
      widget.className = 'widget tag-cloud-widget';
      widget.innerHTML = '<h3 class="widget-title">热门标签</h3><div class="tag-cloud">' +
        tags.map(function (t, i) {
          var size = 12 + Math.floor(Math.random() * 6);
          return '<a href="/?category=' + encodeURIComponent(t) + '" class="tag-cloud-item" style="font-size:' + size + 'px;" data-tag="' + t + '">' + t + '</a>';
        }).join('') + '</div>';
      var existing = sidebar.querySelector('.tag-cloud-widget');
      if (existing) existing.remove();
      sidebar.appendChild(widget);
      widget.querySelectorAll('.tag-cloud-item').forEach(function (tag) {
        tag.addEventListener('click', function (e) {
          e.preventDefault();
          var cat = this.dataset.tag;
          var btn = document.querySelector('.category-btn[data-category="' + cat + '"]');
          if (btn) btn.click();
          else {
            var allBtn = document.querySelector('.category-btn[data-category="all"]');
            if (allBtn) allBtn.click();
          }
        });
      });
    }
  };

  var UserStatus = {
    init: function () {
      document.querySelectorAll('.author-name, .comment-author').forEach(function (el) {
        var online = el.getAttribute('data-online');
        if (online === '1') {
          var dot = document.createElement('span');
          dot.className = 'online-dot';
          dot.title = '在线';
          el.appendChild(dot);
        }
      });
    }
  };

  var ScrollToComment = {
    init: function () {
      if (window.location.hash && window.location.hash.startsWith('#comment-')) {
        var target = document.querySelector(window.location.hash);
        if (target) {
          setTimeout(function () {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.classList.add('comment-highlight');
            setTimeout(function () { target.classList.remove('comment-highlight'); }, 2000);
          }, 300);
        }
      }
    }
  };

  var CapsLockWarning = {
    init: function () {
      document.querySelectorAll('input[type="password"]').forEach(function (input) {
        var warning = document.createElement('span');
        warning.className = 'capslock-warning';
        warning.textContent = '大写锁定已开启';
        warning.style.display = 'none';
        input.parentNode.appendChild(warning);
        input.addEventListener('keyup', function (e) {
          warning.style.display = e.getModifierState && e.getModifierState('CapsLock') ? 'block' : 'none';
        });
      });
    }
  };

  var ImageZoom = {
    _lens: null,
    init: function () {
      var self = this;
      this._lens = document.createElement('div');
      this._lens.className = 'image-zoom-lens';
      this._lens.style.display = 'none';
      document.body.appendChild(this._lens);
      document.addEventListener('mouseover', function (e) {
        var img = e.target.closest('.post-card-images img, .post-detail-images img');
        if (!img) { self._lens.style.display = 'none'; return; }
        self._lens.style.display = 'block';
        self._lens.style.backgroundImage = 'url(' + img.src + ')';
        self._lens.style.backgroundSize = (img.naturalWidth * 2) + 'px ' + (img.naturalHeight * 2) + 'px';
        img.addEventListener('mousemove', self._move);
        img.addEventListener('mouseleave', function () { self._lens.style.display = 'none'; });
      });
    },
    _move: function (e) {
      var img = e.target;
      var rect = img.getBoundingClientRect();
      var x = ((e.clientX - rect.left) / rect.width) * 100;
      var y = ((e.clientY - rect.top) / rect.height) * 100;
      ImageZoom._lens.style.backgroundPosition = x + '% ' + y + '%';
      ImageZoom._lens.style.top = (e.clientY - 100) + 'px';
      ImageZoom._lens.style.left = (e.clientX + 20) + 'px';
    }
  };

  var PostStatistics = {
    init: function () {
      var meta = document.querySelector('.post-detail-meta');
      if (!meta) return;
      var stats = document.createElement('div');
      stats.className = 'post-stats-bar';
      var likeCount = document.getElementById('likeCount');
      var commentCount = document.querySelector('.comment-total');
      var viewCount = document.getElementById('viewCount');
      var likes = (likeCount && likeCount.textContent) || '0';
      var comments = (commentCount && commentCount.textContent.replace(/[()]/g, '')) || '0';
      var views = (viewCount && viewCount.textContent) || '0';
      stats.innerHTML = '<span class="stat-item">❤️ ' + likes + ' 点赞</span>' +
        '<span class="stat-item">💬 ' + comments + ' 评论</span>' +
        '<span class="stat-item">👁️ ' + views + ' 浏览</span>';
      meta.appendChild(stats);
    }
  };

  var TextExpander = {
    _shortcuts: {
      'omw': 'On my way!',
      'brb': 'Be right back',
      'ttyl': 'Talk to you later',
      'btw': 'By the way',
      'imo': 'In my opinion'
    },
    init: function () {
      var self = this;
      document.querySelectorAll('textarea').forEach(function (ta) {
        ta.addEventListener('keydown', function (e) {
          if (e.key === 'Tab') {
            var val = ta.value;
            var pos = ta.selectionStart;
            var before = val.slice(0, pos);
            var match = before.match(/(\S+)$/);
            if (match && self._shortcuts[match[1]]) {
              e.preventDefault();
              var expanded = self._shortcuts[match[1]];
              ta.value = before.slice(0, before.length - match[1].length) + expanded + val.slice(pos);
              ta.setSelectionRange(pos - match[1].length + expanded.length, pos - match[1].length + expanded.length);
            }
          }
        });
      });
    }
  };

  var PostBookmark = {
    _key: 'readingBookmarks',
    init: function () {
      var postId = new URLSearchParams(window.location.search).get('id');
      if (!postId) return;
      var self = this;
      var btn = document.createElement('button');
      btn.className = 'bookmark-position-btn';
      btn.innerHTML = '🔖 标记阅读位置';
      btn.title = '保存当前阅读位置';
      var content = document.querySelector('.post-detail-content');
      if (content) {
        content.parentNode.insertBefore(btn, content.nextSibling);
        var saved = this._get(postId);
        if (saved !== null) {
          var restore = document.createElement('button');
          restore.className = 'bookmark-position-btn restore';
          restore.innerHTML = '📍 恢复阅读位置 (' + Math.round(saved / (content.scrollHeight || 1) * 100) + '%)';
          restore.addEventListener('click', function () {
            content.scrollTo({ top: saved, behavior: 'smooth' });
          });
          btn.parentNode.insertBefore(restore, btn.nextSibling);
        }
        btn.addEventListener('click', function () {
          self._save(postId, content.scrollTop);
          App.showToast('阅读位置已保存', 'success');
        });
      }
    },
    _get: function (postId) {
      try {
        var data = JSON.parse(localStorage.getItem(this._key)) || {};
        return data[postId] !== undefined ? data[postId] : null;
      } catch (e) { return null; }
    },
    _save: function (postId, pos) {
      try {
        var data = JSON.parse(localStorage.getItem(this._key)) || {};
        data[postId] = pos;
        localStorage.setItem(this._key, JSON.stringify(data));
      } catch (e) {}
    }
  };

  // StickyToolbar（右侧 5 图标竖条：首页/发帖/回顶/主题/随机）已移除：
  // 这 5 个动作分别由顶栏 logo、顶栏发帖按钮、用户下拉里的主题切换，
  // 以及 polish.js 的统一浮动按钮（回到顶部 / 随便看看）覆盖，属于纯重复入口。


  var PostFilter = {
    init: function () {
      var filters = document.querySelector('.category-filters');
      if (!filters) return;
      var advanced = document.createElement('div');
      advanced.className = 'advanced-filters';
      advanced.style.cssText = 'display:none;padding:12px;margin-top:8px;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);';
      advanced.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">' +
        '<label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" id="filterHasImage" style="margin:0;"> 有图片</label>' +
        '<label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" id="filterHot" style="margin:0;"> 热门帖子</label>' +
        '<label style="font-size:13px;display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="checkbox" id="filterAnon" style="margin:0;"> 匿名帖子</label>' +
        '<select id="filterSort" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:13px;background:var(--card-bg);color:var(--text);">' +
        '<option value="latest">最新</option><option value="oldest">最早</option><option value="popular">最多点赞</option><option value="comments">最多评论</option></select>' +
        '</div>';
      filters.parentNode.insertBefore(advanced, filters.nextSibling);
      var toggle = document.createElement('button');
      toggle.className = 'filter-toggle-btn';
      toggle.textContent = '高级筛选';
      toggle.style.cssText = 'font-size:12px;padding:4px 10px;border:1px solid var(--border);border-radius:4px;background:var(--card-bg);cursor:pointer;color:var(--text-secondary);margin-left:8px;';
      toggle.addEventListener('click', function () {
        advanced.style.display = advanced.style.display === 'none' ? 'block' : 'none';
      });
      filters.appendChild(toggle);
      ['filterHasImage', 'filterHot', 'filterAnon', 'filterSort'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', function () {
          if (App.PostFeed) App.PostFeed.loadPosts(true);
        });
      });
    }
  };

  var MarkdownPreview = {
    init: function () {
      var ta = document.querySelector('textarea[name="content"], #content');
      if (!ta) return;
      var self = this;
      var preview = document.createElement('div');
      preview.className = 'md-preview';
      preview.style.cssText = 'display:none;padding:16px;margin-top:8px;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);min-height:100px;line-height:1.8;';
      var toggle = document.createElement('button');
      toggle.className = 'md-preview-toggle';
      toggle.textContent = '预览';
      toggle.type = 'button';
      toggle.style.cssText = 'font-size:12px;padding:4px 10px;border:1px solid var(--border);border-radius:4px;background:var(--card-bg);cursor:pointer;color:var(--text-secondary);margin-left:8px;';
      ta.parentNode.insertBefore(preview, ta.nextSibling);
      var label = ta.closest('.form-group');
      if (label) {
        var labelEl = label.querySelector('label');
        if (labelEl) labelEl.appendChild(toggle);
      }
      toggle.addEventListener('click', function () {
        if (preview.style.display === 'none') {
          preview.style.display = 'block';
          preview.innerHTML = self._render(ta.value);
          toggle.textContent = '编辑';
        } else {
          preview.style.display = 'none';
          toggle.textContent = '预览';
        }
      });
      ta.addEventListener('input', function () {
        if (preview.style.display !== 'none') {
          preview.innerHTML = self._render(ta.value);
        }
      });
    },
    _render: function (md) {
      var html = md
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/^### (.+)$/gm, '<h4>$1</h4>')
        .replace(/^## (.+)$/gm, '<h3>$1</h3>')
        .replace(/^# (.+)$/gm, '<h2>$1</h2>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/`(.+?)`/g, '<code>$1</code>')
        .replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>')
        .replace(/^- (.+)$/gm, '<li>$1</li>')
        .replace(/\n/g, '<br>');
      return html;
    }
  };

  var PostRating = {
    _maxStars: 5,
    init: function () {
      var self = this;
      document.querySelectorAll('.post-card').forEach(function (card) {
        var rating = card.querySelector('.post-rating');
        if (rating) return;
        var actions = card.querySelector('.post-card-actions');
        if (!actions) return;
        var ratingEl = document.createElement('div');
        ratingEl.className = 'post-rating';
        ratingEl.innerHTML = '<span class="rating-stars">' + '☆'.repeat(self._maxStars) + '</span>';
        ratingEl.querySelector('.rating-stars').addEventListener('click', function (e) {
          e.stopPropagation();
          if (!window.IS_LOGGED_IN) { App.showToast('请先登录', 'warning'); return; }
          var rect = this.getBoundingClientRect();
          var star = Math.ceil((e.clientX - rect.left) / rect.width * self._maxStars);
          var stars = this.querySelectorAll('.star');
          this.innerHTML = '';
          for (var i = 0; i < self._maxStars; i++) {
            var s = document.createElement('span');
            s.className = 'star' + (i < star ? ' active' : '');
            s.textContent = i < star ? '★' : '☆';
            this.appendChild(s);
          }
        });
        actions.appendChild(ratingEl);
      });
    }
  };

  var UserBadge = {
    init: function () {
      document.querySelectorAll('.author-name, .comment-author').forEach(function (el) {
        var level = parseInt(el.getAttribute('data-level')) || 0;
        if (level === 0) return;
        var badge = el.querySelector('.user-level-badge');
        if (badge) return;
        var span = document.createElement('span');
        span.className = 'user-level-badge';
        span.textContent = 'Lv.' + level;
        el.appendChild(span);
      });
    }
  };

  var CommentNest = {
    init: function () {
      document.querySelectorAll('.comment-item').forEach(function (item) {
        var depth = parseInt(item.dataset.depth) || 0;
        if (depth > 0) {
          item.style.marginLeft = (depth * 24) + 'px';
          item.style.borderLeft = '2px solid var(--border)';
          item.style.paddingLeft = '12px';
        }
      });
    }
  };

  var KeyboardNav = {
    _currentIndex: -1,
    init: function () {
      var self = this;
      document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA')) return;
        var cards = document.querySelectorAll('.post-card');
        if (cards.length === 0) return;
        if (e.key === 'j' || e.key === 'ArrowDown') {
          e.preventDefault();
          self._currentIndex = Math.min(self._currentIndex + 1, cards.length - 1);
          self._focus(cards);
        } else if (e.key === 'k' || e.key === 'ArrowUp') {
          e.preventDefault();
          self._currentIndex = Math.max(self._currentIndex - 1, 0);
          self._focus(cards);
        } else if (e.key === 'Enter' && self._currentIndex >= 0) {
          e.preventDefault();
          var card = cards[self._currentIndex];
          var postId = card.dataset.postId;
          if (postId) window.location.href = '/pages/post_detail.php?id=' + postId;
        } else if (e.key === 'Escape') {
          self._currentIndex = -1;
          cards.forEach(function (c) { c.classList.remove('keyboard-focus'); });
        }
      });
    },
    _focus: function (cards) {
      cards.forEach(function (c) { c.classList.remove('keyboard-focus'); });
      if (this._currentIndex >= 0 && this._currentIndex < cards.length) {
        cards[this._currentIndex].classList.add('keyboard-focus');
        cards[this._currentIndex].scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
  };

  var PrintStyles = {
    init: function () {
      var style = document.createElement('style');
      style.media = 'print';
      style.textContent = '@media print {' +
        '.site-header,.site-footer,.sidebar,.mobile-bottom-nav,.lw-fab,.lw-ai-panel,' +
        '.share-panel-overlay,.modal-overlay,.notification-bell,' +
        '.post-card-actions,.emoji-reactions,.comment-sort-bar { display:none !important; }' +
        '.post-detail-content { font-size:14px;line-height:1.8; }' +
        '.main-content,.page-content { max-width:100% !important;margin:0 !important;padding:0 !important; }' +
        '}';
      document.head.appendChild(style);
    }
  };

  var CodeHighlight = {
    init: function () {
      document.querySelectorAll('.post-card-content, .comment-body, .post-detail-content').forEach(function (el) {
        el.innerHTML = el.innerHTML.replace(/```(\w*)\n([\s\S]*?)```/g, function (match, lang, code) {
          return '<pre class="code-block' + (lang ? ' lang-' + lang : '') + '"><code>' + App.esc(code.trim()) + '</code></pre>';
        });
        el.innerHTML = el.innerHTML.replace(/`([^`]+)`/g, '<code class="inline-code">$1</code>');
      });
    }
  };

  var PostTemplate = {
    _templates: {
      '失物招领': '【物品名称】\n【丢失时间】\n【丢失地点】\n【物品特征】\n【联系方式】\n',
      '学习求助': '【课程名称】\n【问题描述】\n【已尝试的方法】\n【期望得到的帮助】\n',
      '表白': '【To】\n【想说的话】\n\n',
      '活动通知': '【活动名称】\n【活动时间】\n【活动地点】\n【活动内容】\n【参与方式】\n',
      '二手交易': '【物品名称】\n【新旧程度】\n【期望价格】\n【联系方式】\n【物品图片】\n'
    },
    init: function () {
      var ta = document.querySelector('textarea[name="content"], #content');
      if (!ta) return;
      var self = this;
      var select = document.createElement('select');
      select.className = 'post-template-select';
      select.style.cssText = 'padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px;background:var(--card-bg);color:var(--text);margin-left:8px;max-width:140px;';
      select.innerHTML = '<option value="">选择模板</option>';
      Object.keys(this._templates).forEach(function (key) {
        select.innerHTML += '<option value="' + key + '">' + key + '</option>';
      });
      var label = ta.closest('.form-group');
      if (label) {
        var labelEl = label.querySelector('label');
        if (labelEl) labelEl.appendChild(select);
      }
      select.addEventListener('change', function () {
        var template = self._templates[this.value];
        if (template && ta.value.trim() === '') {
          ta.value = template;
          ta.focus();
        }
        this.value = '';
      });
    }
  };

  // 语音输入（Web Speech API 麦克风转文字）已按需求全站移除：
  // 它会在每个 <textarea> 的 label 后自动塞一个 🎤 按钮，样式与全站不一致，
  // 且各家浏览器支持度差异大（不支持时点了毫无反馈）。连同 CSS 一并删除。
  // 注意：config/config.php 的 Permissions-Policy 已带 microphone=()，浏览器层也已禁用麦克风。

  var AutoSaveAllForms = {
    _key: 'formAutoSave',
    init: function () {
      var self = this;
      document.querySelectorAll('form').forEach(function (form) {
        var formId = form.id || form.action || window.location.pathname;
        var saved = self._get(formId);
        if (saved) {
          Object.keys(saved).forEach(function (name) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el && el.type !== 'password' && !el.value) {
              el.value = saved[name];
            }
          });
        }
        form.addEventListener('input', debounce(function () {
          var data = {};
          form.querySelectorAll('input:not([type="password"]):not([type="file"]), textarea, select').forEach(function (el) {
            if (el.name) data[el.name] = el.value;
          });
          self._set(formId, data);
        }, 1000));
        form.addEventListener('submit', function () {
          self._remove(formId);
        });
      });
    },
    _get: function (formId) {
      try { return JSON.parse(localStorage.getItem(this._key + '_' + formId)); } catch (e) { return null; }
    },
    _set: function (formId, data) {
      try { localStorage.setItem(this._key + '_' + formId, JSON.stringify(data)); } catch (e) {}
    },
    _remove: function (formId) {
      localStorage.removeItem(this._key + '_' + formId);
    }
  };

  var ScrollSpy = {
    init: function () {
      var headings = document.querySelectorAll('.post-detail-content h2, .post-detail-content h3, .post-detail-content h4');
      if (headings.length < 2) return;
      var self = this;
      headings.forEach(function (h, i) {
        if (!h.id) h.id = 'section-' + i;
      });
      window.addEventListener('scroll', throttle(function () {
        var current = '';
        headings.forEach(function (h) {
          if (h.getBoundingClientRect().top <= 100) current = h.id;
        });
        document.querySelectorAll('.toc-link').forEach(function (link) {
          link.classList.toggle('active', link.getAttribute('href') === '#' + current);
        });
      }, 100), { passive: true });
    }
  };

  var TabPersist = {
    init: function () {
      var self = this;
      document.querySelectorAll('[data-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
          var group = this.closest('[data-tab-group]');
          var groupId = group ? group.dataset.tabGroup : 'default';
          self._save(groupId, this.dataset.tab);
        });
      });
      document.querySelectorAll('[data-tab-group]').forEach(function (group) {
        var groupId = group.dataset.tabGroup;
        var saved = self._get(groupId);
        if (saved) {
          var tab = group.querySelector('[data-tab="' + saved + '"]');
          if (tab) tab.click();
        }
      });
    },
    _get: function (groupId) { return sessionStorage.getItem('tab_' + groupId); },
    _save: function (groupId, tab) { sessionStorage.setItem('tab_' + groupId, tab); }
  };

  var CookieConsent = {
    init: function () {
      if (localStorage.getItem('cookieConsent')) return;
      var bar = document.createElement('div');
      bar.className = 'cookie-consent-bar';
      bar.innerHTML = '<div class="cookie-consent-content">' +
        '<span>本网站使用 Cookie 来提升用户体验。</span>' +
        '<button class="cookie-accept-btn">知道了</button>' +
        '</div>';
      document.body.appendChild(bar);
      bar.querySelector('.cookie-accept-btn').addEventListener('click', function () {
        localStorage.setItem('cookieConsent', '1');
        bar.remove();
      });
    }
  };

  // 「安装应用」—— 入口固定在用户下拉菜单里（includes/user_dropdown.php #pwaInstallBtn）。
  // 三条路径，按优先级：
  //   1. 在安卓客户端内：把 APK 地址交给客户端，由它下载并拉起系统安装器（点一下直接装）；
  //   2. 安卓浏览器：直接下载 /download/ 下的安装包，交给系统安装；
  //   3. 其它平台：仍走 PWA —— 能拿到系统安装框就直接弹，拿不到就按平台给手动指引。
  // 为什么不自己做按钮：Chrome 只在站点满足可安装条件时才派发 beforeinstallprompt。
  var PWAInstall = {
    _deferredPrompt: null,
    _btn: null,
    init: function () {
      var self = this;
      this._btn = document.getElementById('pwaInstallBtn');
      if (!this._btn) { return; }

      // 已经从主屏/桌面图标启动（standalone）就说明装过了，入口没意义
      var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
        window.navigator.standalone === true;
      if (standalone) { this._btn.remove(); this._btn = null; return; }

      this._btn.addEventListener('click', function () { self.install(); });

      window.addEventListener('beforeinstallprompt', function (e) {
        // 拦下系统默认的迷你提示条，改由用户点菜单项时再弹
        e.preventDefault();
        self._deferredPrompt = e;
      });

      window.addEventListener('appinstalled', function () {
        self._deferredPrompt = null;
        if (self._btn) { self._btn.remove(); self._btn = null; }
        if (typeof showToast === 'function') { showToast('已安装到本机，可从桌面图标直接打开', 'success'); }
      });
    },

    // 按钮 data-* 上的信息由 PHP 从 config/app_config.php 输出，避免前端再请求一次接口
    attr: function (name) {
      return this._btn ? (this._btn.getAttribute(name) || '') : '';
    },

    isAndroid: function () {
      return /Android|HarmonyOS|HuaweiBrowser|MiuiBrowser/i.test(navigator.userAgent);
    },

    install: function () {
      var apk = this.attr('data-apk');
      var apkHref = apk ? new URL(apk, location.href).href : '';

      // 1) 安卓客户端内：交给客户端下载安装。WebView 自己下完的包点不开，
      //    必须走客户端，才能弹系统安装器并校验安装包完整性。
      var bridge = window.LoveWallApp;
      if (bridge && typeof bridge.available === 'function' && bridge.available()) {
        if (apkHref && typeof bridge.installApk === 'function') {
          bridge.installApk(apkHref, this.attr('data-version'), this.attr('data-sha256'));
          return;
        }
        if (typeof bridge.checkUpdate === 'function') { bridge.checkUpdate(); return; }
      }

      // 2) 安卓浏览器：直接下载安装包
      if (apkHref && this.isAndroid()) {
        if (typeof showToast === 'function') {
          showToast('正在下载安装包，下载完成后点击安装即可', 'info');
        }
        location.href = apkHref;
        return;
      }

      // 3) 其它平台：优先系统安装框，其次手动指引
      if (this._deferredPrompt) {
        var prompt = this._deferredPrompt;
        // 一次 prompt 只能用一次，先清空，等下一次事件再补
        this._deferredPrompt = null;
        prompt.prompt();
        prompt.userChoice.then(function (result) {
          if (result.outcome === 'accepted' && typeof showToast === 'function') {
            showToast('正在安装…', 'success');
          }
        });
        return;
      }
      this.showGuide();
    },

    // 浏览器没给自动安装入口时的手动指引（按平台给对应路径）
    showGuide: function () {
      var ua = navigator.userAgent;
      var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      var isAndroid = /Android|HarmonyOS|HuaweiBrowser|MiuiBrowser/i.test(ua);
      var inAppBrowser = /MicroMessenger|QQ\/|Weibo|Alipay|DingTalk|Feishu|Lark|UCBrowser|Quark/i.test(ua);

      var steps;
      if (inAppBrowser) {
        steps = [
          '当前是微信/QQ 等内置浏览器，一般不允许安装到桌面。',
          '请点右上角「⋯」，选择「在浏览器打开」，再用系统浏览器里的安装入口。'
        ];
      } else if (isIOS) {
        steps = [
          '用 Safari 打开本站（其他浏览器可能没有该入口）。',
          '点底部「分享」按钮，选择「添加到主屏幕」。',
          '确认名称后点「添加」，桌面就会出现本站图标。'
        ];
      } else if (isAndroid) {
        steps = [
          '点浏览器右上角「⋮」菜单。',
          '选择「安装应用」或「添加到主屏幕」。',
          '确认后即可像 App 一样全屏打开。'
        ];
      } else {
        steps = [
          '看地址栏右侧是否有「安装」图标（⊕ / 显示器小图标），点它即可安装。',
          '或打开浏览器右上角「⋮」菜单，选择「安装应用」/「应用 → 将此站点作为应用安装」。',
          '安装后可从桌面/开始菜单直接打开，全屏无地址栏。'
        ];
      }

      var html = '<div style="font-size:0.88rem;color:var(--text-secondary);line-height:1.8;">' +
        '没有自动弹出安装框也没关系，按下面几步手动装：' +
        '</div><ol style="margin:10px 0 0;padding-left:20px;font-size:0.88rem;line-height:1.9;color:var(--text);">';
      steps.forEach(function (s) { html += '<li>' + escapeHtml(s) + '</li>'; });
      html += '</ol>';

      if (typeof Modal !== 'undefined' && Modal && typeof Modal.open === 'function') {
        Modal.open({
          title: '安装应用',
          content: html,
          showCancel: false,
          confirmText: '知道了'
        });
      } else if (typeof showToast === 'function') {
        showToast('请用浏览器菜单里的「安装应用 / 添加到主屏幕」安装本站', 'info');
      }
    }
  };

  var PostTrending = {
    init: function () {
      document.querySelectorAll('.post-card').forEach(function (card) {
        var likeEl = card.querySelector('.like-btn .action-count');
        var commentEl = card.querySelector('.comment-btn .action-count');
        var likes = parseInt((likeEl && likeEl.textContent) || '0');
        var comments = parseInt((commentEl && commentEl.textContent) || '0');
        if (likes + comments * 3 >= 50) {
          var badge = document.createElement('span');
          badge.className = 'trending-badge';
          badge.textContent = '🔥 热门';
          badge.style.cssText = 'font-size:11px;color:#ff6b6b;margin-left:4px;font-weight:600;';
          var header = card.querySelector('.post-card-header');
          if (header) header.appendChild(badge);
        }
      });
    }
  };

  // 已移除 ImageGallery 模块：它和 main.js 的 Lightbox 绑同一批 `.post-card-images img`，
  // 点一次图会同时弹出两个覆盖层；而它自己的 `.gallery-overlay` 没有任何配套 CSS，
  // 只是往 body 里追加一堆无样式 DOM。图片查看统一由 main.js 的 Lightbox 负责
  // （已支持同帖多图分组、左右滑切换、pinch 缩放、下滑关闭）。

  var LoadMoreAnim = {
    init: function () {
      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType === 1 && node.classList.contains('post-card')) {
              node.style.animation = 'cardSlideUp 0.4s ease forwards';
              node.style.opacity = '0';
            }
          });
        });
      });
      var container = document.getElementById('postsContainer');
      if (container) observer.observe(container, { childList: true });
      var style = document.createElement('style');
      style.textContent = '@keyframes cardSlideUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }';
      document.head.appendChild(style);
    }
  };

  var ErrorBoundary = {
    init: function () {
      window.addEventListener('error', function (e) {
        if (e.target && e.target.tagName === 'IMG') {
          e.target.src = '/assets/images/default-avatar.svg';
          e.target.classList.add('img-error-fallback');
        }
      }, true);
      window.addEventListener('unhandledrejection', function (e) { });
    }
  };

  var LazyComponent = {
    init: function () {
      if (!window.IntersectionObserver) return;
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            var el = entry.target;
            if (el.dataset.lazySrc) {
              el.src = el.dataset.lazySrc;
              el.removeAttribute('data-lazy-src');
            }
            if (el.dataset.lazyHtml) {
              el.innerHTML = el.dataset.lazyHtml;
              el.removeAttribute('data-lazy-html');
            }
            observer.unobserve(el);
          }
        });
      }, { rootMargin: '300px' });
      document.querySelectorAll('[data-lazy-src], [data-lazy-html]').forEach(function (el) {
        observer.observe(el);
      });
    }
  };

  var BreadcrumbEnhance = {
    init: function () {
      var breadcrumb = document.querySelector('.breadcrumb');
      if (!breadcrumb) return;
      var items = breadcrumb.querySelectorAll('a');
      items.forEach(function (item) {
        item.addEventListener('click', function (e) {
          e.preventDefault();
          var href = this.getAttribute('href');
          document.body.style.opacity = '0.6';
          document.body.style.transition = 'opacity 0.2s';
          setTimeout(function () { window.location.href = href; }, 150);
        });
      });
    }
  };

  var MobileGesture = {
    _startX: 0,
    _startY: 0,
    init: function () {
      if (window.innerWidth > 768) return;
      var self = this;
      document.addEventListener('touchstart', function (e) {
        self._startX = e.touches[0].clientX;
        self._startY = e.touches[0].clientY;
      }, { passive: true });
      document.addEventListener('touchend', function (e) {
        var dx = e.changedTouches[0].clientX - self._startX;
        var dy = e.changedTouches[0].clientY - self._startY;
        if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy)) return;
        if (dx > 60 && !window.location.pathname.includes('post_detail')) {
          history.back();
        }
      });
    }
  };

  // 手机端卡片左滑快捷操作：在卡片底部展开「收藏 / 分享」。
  // 方向与既有 MobileGesture（右滑返回，dx > 60）相反，因此不会互相抢占；
  // 排除横滑看图、分类横滑条、评论区与详情页，避免抢走它们已有的手势。
  // 动作直接转发给卡片自带的 .favorite-btn / .share-btn，不重复实现收藏与分享逻辑。
  var CardSwipe = {
    _card: null,
    _openCard: null,
    _x0: 0,
    _y0: 0,
    _tracking: false,

    init: function () {
      this._injectStyles();
      var self = this;

      document.addEventListener('touchstart', function (e) {
        if (window.innerWidth > 768 || e.touches.length !== 1) { self._tracking = false; return; }
        var card = e.target.closest ? e.target.closest('.post-card') : null;
        if (!card || (e.target.closest && e.target.closest(
          '.post-card-images, .category-filters, .comments-list, .post-detail-card, .card-swipe-actions'
        ))) {
          self._tracking = false;
          return;
        }
        self._card = card;
        self._x0 = e.touches[0].clientX;
        self._y0 = e.touches[0].clientY;
        self._tracking = true;
      }, { passive: true });

      document.addEventListener('touchend', function (e) {
        if (!self._tracking) { return; }
        self._tracking = false;
        var card = self._card;
        self._card = null;
        if (!card || e.changedTouches.length !== 1) { return; }
        var dx = e.changedTouches[0].clientX - self._x0;
        var dy = e.changedTouches[0].clientY - self._y0;
        // 只认「明显的向左横滑」，纵向滚动与右滑返回都放过
        if (dx > -60 || Math.abs(dx) < Math.abs(dy)) { return; }
        if (self._openCard === card) { self.close(); } else { self.open(card); }
      }, { passive: true });

      document.addEventListener('click', function (e) {
        if (!self._openCard || self._openCard.contains(e.target)) { return; }
        self.close();
      });
    },

    open: function (card) {
      this.close();
      var tray = card.querySelector('.card-swipe-actions');
      if (!tray) {
        var fav = card.querySelector('.favorite-btn');
        var share = card.querySelector('.share-btn');
        if (!fav && !share) { return; }
        tray = document.createElement('div');
        tray.className = 'card-swipe-actions';
        if (fav) {
          tray.appendChild(this._makeBtn('fav', fav.classList.contains('favorited') ? '已收藏' : '收藏'));
        }
        if (share) {
          tray.appendChild(this._makeBtn('share', '分享'));
        }
        card.appendChild(tray);
      }
      card.classList.add('swipe-open');
      this._openCard = card;
    },

    _makeBtn: function (act, label) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'card-swipe-btn';
      btn.dataset.act = act;
      btn.textContent = label;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var card = btn.closest('.post-card');
        if (!card) { return; }
        // 转发给卡片自带的按钮，复用既有的收藏 / 分享实现
        var target = card.querySelector(act === 'fav' ? '.favorite-btn' : '.share-btn');
        if (!target) { return; }
        if (act !== 'fav') { target.click(); return; }
        // 收藏请求是异步的：卡片上的 class 要等接口返回才变，所以监听 class 变化再同步文案，
        // 不能在 click() 之后同步读取（那时永远是旧值）。3s 后自动放弃监听。
        var sync = function () {
          btn.textContent = target.classList.contains('favorited') ? '已收藏' : '收藏';
        };
        if (window.MutationObserver) {
          var observer = new MutationObserver(function () {
            observer.disconnect();
            sync();
          });
          observer.observe(target, { attributes: true, attributeFilter: ['class'] });
          setTimeout(function () { observer.disconnect(); sync(); }, 3000);
        }
        target.click();
      });
      return btn;
    },

    close: function () {
      if (this._openCard) { this._openCard.classList.remove('swipe-open'); }
      this._openCard = null;
    },

    _injectStyles: function () {
      if (document.getElementById('card-swipe-styles')) return;
      var style = document.createElement('style');
      style.id = 'card-swipe-styles';
      style.textContent = '.card-swipe-actions{display:none;justify-content:flex-end;gap:8px;margin-top:12px;}' +
        '.post-card.swipe-open .card-swipe-actions{display:flex;}' +
        '.card-swipe-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9999px;' +
        'border:1px solid var(--border);background:var(--bg-secondary);color:var(--text);font-size:0.82rem;' +
        'font-family:inherit;cursor:pointer;}' +
        '.card-swipe-btn:active{background:var(--primary-light);color:var(--primary);border-color:var(--primary);}';
      document.head.appendChild(style);
    }
  };

  var PostReactionSummary = {
    init: function () {
      document.querySelectorAll('.post-card').forEach(function (card) {
        var reactions = card.querySelectorAll('.emoji-reaction-btn.active');
        if (reactions.length === 0) return;
        var summary = document.createElement('span');
        summary.className = 'reaction-summary';
        summary.textContent = Array.from(reactions).map(function (r) { return r.dataset.emoji; }).join(' ');
        var header = card.querySelector('.post-card-header');
        if (header) header.appendChild(summary);
      });
    }
  };

  var CommentTimeTravel = {
    init: function () {
      var container = document.querySelector('.comments-list');
      if (!container) return;
      var timeline = document.createElement('div');
      timeline.className = 'comment-timeline';
      timeline.style.cssText = 'position:absolute;left:0;top:0;bottom:0;width:2px;background:var(--border);';
      container.style.position = 'relative';
      container.insertBefore(timeline, container.firstChild);
      document.querySelectorAll('.comment-item').forEach(function (item, i) {
        var dot = document.createElement('div');
        dot.className = 'timeline-dot';
        dot.style.cssText = 'position:absolute;left:-4px;top:20px;width:10px;height:10px;border-radius:50%;background:var(--primary);border:2px solid var(--card-bg);';
        item.style.position = 'relative';
        item.appendChild(dot);
      });
    }
  };

  var UserLevelProgress = {
    init: function () {
      var progressEl = document.querySelector('.level-progress');
      if (!progressEl) return;
      var current = parseInt(progressEl.dataset.current) || 0;
      var next = parseInt(progressEl.dataset.next) || 100;
      var percent = Math.min((current / next) * 100, 100);
      var bar = document.createElement('div');
      bar.className = 'level-progress-bar';
      bar.style.cssText = 'height:6px;background:var(--border);border-radius:3px;margin-top:6px;overflow:hidden;';
      bar.innerHTML = '<div class="level-progress-fill" style="width:' + percent + '%;height:100%;background:linear-gradient(90deg,var(--primary),#a855f7);border-radius:3px;transition:width 0.5s ease;"></div>';
      progressEl.appendChild(bar);
      var label = document.createElement('span');
      label.className = 'level-progress-label';
      label.textContent = current + ' / ' + next;
      label.style.cssText = 'font-size:11px;color:var(--text-muted);margin-top:2px;display:block;';
      progressEl.appendChild(label);
    }
  };

  var PostVisibility = {
    init: function () {
      document.querySelectorAll('.post-card').forEach(function (card) {
        var visibility = card.dataset.visibility;
        if (visibility === 'private') {
          var badge = document.createElement('span');
          badge.className = 'visibility-badge private';
          badge.textContent = '🔒';
          badge.title = '仅自己可见';
          var header = card.querySelector('.post-card-header');
          if (header) header.appendChild(badge);
        } else if (visibility === 'friends') {
          var badge2 = document.createElement('span');
          badge2.className = 'visibility-badge friends';
          badge2.textContent = '👥';
          badge2.title = '仅好友可见';
          var header2 = card.querySelector('.post-card-header');
          if (header2) header2.appendChild(badge2);
        }
      });
    }
  };

  var SyntaxHighlight = {
    init: function () {
      document.querySelectorAll('.code-block code').forEach(function (code) {
        var text = code.textContent;
        text = text.replace(/(\/\/.*$)/gm, '<span class="syn-comment">$1</span>');
        text = text.replace(/("(?:[^"\\]|\\.)*")/g, '<span class="syn-string">$1</span>');
        text = text.replace(/('(?:[^'\\]|\\.)*')/g, '<span class="syn-string">$1</span>');
        text = text.replace(/\b(function|var|let|const|if|else|for|while|return|class|import|export|from|async|await|try|catch|throw|new|this|true|false|null|undefined)\b/g, '<span class="syn-keyword">$1</span>');
        text = text.replace(/\b(\d+)\b/g, '<span class="syn-number">$1</span>');
        code.innerHTML = text;
      });
    }
  };


  var ImageCaption = {
    init: function () {
      document.querySelectorAll('.post-card-images img, .post-detail-images img').forEach(function (img) {
        var alt = img.getAttribute('alt');
        if (!alt || alt.indexOf('图片') === 0) return;
        var caption = document.createElement('span');
        caption.className = 'image-caption';
        caption.textContent = alt;
        img.parentNode.insertBefore(caption, img.nextSibling);
      });
    }
  };


  var FollowSystem = {
    _cache: { following: null, followers: null },
    _modal: null,

    init: function () {
      this._injectStyles();
      this._scanFollowButtons();
      this._bindGlobalEvents();
    },

    _injectStyles: function () {
      if (document.getElementById('follow-system-styles')) return;
      var style = document.createElement('style');
      style.id = 'follow-system-styles';
      style.textContent = '.follow-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:20px;font-size:0.85rem;font-weight:600;cursor:pointer;transition:all 0.25s ease;border:2px solid var(--primary);font-family:inherit;}.follow-btn.following{background:var(--primary);color:#fff;}.follow-btn:not(.following){background:transparent;color:var(--primary);}.follow-btn:not(.following):hover{background:var(--primary-light);}.follow-btn.following:hover{background:var(--primary-hover);border-color:var(--primary-hover);}.follow-count{font-size:0.8rem;color:var(--text-secondary);margin-left:4px;}.follow-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000;display:flex;align-items:center;justify-content:center;}.follow-modal-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);animation:fadeIn 0.2s ease;}.follow-modal-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:420px;max-height:70vh;overflow-y:auto;animation:slideUp 0.3s ease;}.follow-modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}.follow-modal-title{font-size:1.1rem;font-weight:700;color:var(--text);}.follow-modal-close{width:32px;height:32px;border:none;background:var(--bg);border-radius:50%;cursor:pointer;font-size:1.2rem;display:flex;align-items:center;justify-content:center;color:var(--text-secondary);transition:all 0.2s;}.follow-modal-close:hover{background:var(--danger);color:#fff;}.follow-user-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;cursor:pointer;transition:background 0.2s;}.follow-user-item:hover{background:var(--bg);}.follow-user-avatar{width:40px;height:40px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--primary);font-size:0.9rem;flex-shrink:0;}.follow-user-info{flex:1;min-width:0;}.follow-user-name{font-size:0.9rem;font-weight:600;color:var(--text);}.follow-user-bio{font-size:0.75rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.follow-user-action{flex-shrink:0;}.follow-tabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:16px;}.follow-tab{padding:8px 20px;border:none;background:none;cursor:pointer;font-size:0.9rem;color:var(--text-secondary);font-weight:500;transition:all 0.2s;border-bottom:2px solid transparent;margin-bottom:-2px;font-family:inherit;}.follow-tab.active{color:var(--primary);border-bottom-color:var(--primary);}@keyframes fadeIn{from{opacity:0}to{opacity:1}}@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}';
      document.head.appendChild(style);
    },

    _scanFollowButtons: function () {
      var self = this;
      document.querySelectorAll('[data-follow-user]').forEach(function (btn) {
        if (btn._followBound) return;
        btn._followBound = true;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var userId = this.getAttribute('data-follow-user');
          self.toggleFollow(userId, this);
        });
      });
      document.querySelectorAll('[data-show-followers], [data-show-following]').forEach(function (btn) {
        if (btn._followListBound) return;
        btn._followListBound = true;
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var userId = this.getAttribute('data-user-id');
          var type = this.hasAttribute('data-show-followers') ? 'followers' : 'following';
          self.showFollowList(userId, type);
        });
      });
    },

    _bindGlobalEvents: function () {
      var self = this;
      var observer = new MutationObserver(function () {
        self._scanFollowButtons();
      });
      observer.observe(document.body, { childList: true, subtree: true });
    },

    toggleFollow: async function (userId, btn) {
      if (!App.IS_LOGGED_IN) {
        showToast('请先登录后再关注', 'warning');
        return;
      }
      var isFollowing = btn.classList.contains('following');
      var action = isFollowing ? 'unfollow' : 'follow';

      try {
        var res = await fetchAPI('/api/user/follow.php', {
          method: 'POST',
          body: 'action=' + action + '&target_id=' + userId
        });
        if (res.success) {
          if (isFollowing) {
            btn.classList.remove('following');
            btn.innerHTML = svgIcon('userPlus', 14) + ' 关注';
          } else {
            btn.classList.add('following');
            btn.innerHTML = svgIcon('check', 14) + ' 已关注';
          }
          var countEl = btn.querySelector('.follow-count');
          if (countEl) {
            countEl.textContent = res.follower_count || '';
          }
          showToast(res.message || (isFollowing ? '已取消关注' : '关注成功'), 'success');
        } else {
          showToast(res.message || '操作失败', 'error');
        }
      } catch (e) {
        showToast('网络错误，请稍后重试', 'error');
      }
    },

    showFollowList: async function (userId, type) {
      var self = this;
      try {
        var res = await fetchAPI('/api/user/follow_list.php?user_id=' + userId + '&type=' + type);
        if (!res.success) {
          showToast(res.message || '加载失败', 'error');
          return;
        }
        var list = res.data || [];
        var title = type === 'followers' ? '粉丝列表' : '关注列表';

        var modal = document.createElement('div');
        modal.className = 'follow-modal';
        var html = '<div class="follow-modal-overlay"></div>';
        html += '<div class="follow-modal-content">';
        html += '<div class="follow-modal-header">';
        html += '<span class="follow-modal-title">' + title + ' (' + list.length + ')</span>';
        html += '<button class="follow-modal-close">&times;</button>';
        html += '</div>';
        html += '<div class="follow-list">';
        if (list.length === 0) {
          html += '<div style="text-align:center;padding:30px;color:var(--text-muted);">暂无数据</div>';
        } else {
          list.forEach(function (user) {
            var initial = (user.username || '?').charAt(0).toUpperCase();
            html += '<div class="follow-user-item">';
            html += '<div class="follow-user-avatar">' + initial + '</div>';
            html += '<div class="follow-user-info">';
            html += '<div class="follow-user-name">' + escapeHtml(user.username || '未知用户') + '</div>';
            html += '<div class="follow-user-bio">' + escapeHtml(user.bio || '这个用户很懒，什么都没写') + '</div>';
            html += '</div>';
            html += '</div>';
          });
        }
        html += '</div></div>';
        modal.innerHTML = html;
        document.body.appendChild(modal);

        modal.querySelector('.follow-modal-overlay').addEventListener('click', function () { modal.remove(); });
        modal.querySelector('.follow-modal-close').addEventListener('click', function () { modal.remove(); });
        self._modal = modal;
      } catch (e) {
        showToast('网络错误', 'error');
      }
    }
  };


  var FeatureVote = {
    _modal: null,

    init: function () {
      var btn = document.createElement('button');
      btn.className = 'feature-vote-btn';
      btn.innerHTML = '<span class="fv-emoji">✨</span><span class="fv-label">功能投票</span>';
      btn.title = '给功能建议投票，或提出你的建议';
      // 手机端收进用户面板弹层；桌面端仍按原有位置插进顶栏
      if (!mountSecondary(btn, 'me-panel')) return;
      var self = this;
      btn.addEventListener('click', function () { self.open(); });
    },

    open: function () {
      if (!App.IS_LOGGED_IN) { App.showToast('请先登录', 'warning'); return; }
      if (this._modal) this._modal.remove();
      var self = this;
      var modal = document.createElement('div');
      modal.className = 'modal-overlay show';
      modal.innerHTML =
        '<div class="modal feature-vote-modal">' +
          '<div class="modal-header">' +
            '<h3>功能投票</h3>' +
            '<button type="button" class="modal-close fv-close" aria-label="关闭">×</button>' +
          '</div>' +
          '<div class="modal-body">' +
            '<div class="fv-submit">' +
              '<textarea class="form-input fv-input" placeholder="想为网站增加什么功能？写下你的建议..." maxlength="500"></textarea>' +
              '<button type="button" class="btn btn-primary fv-submit-btn">提交建议</button>' +
            '</div>' +
            '<div class="fv-list"><div class="fv-loading">加载中...</div></div>' +
          '</div>' +
        '</div>';
      document.body.appendChild(modal);
      modal.querySelector('.fv-close').addEventListener('click', function () { modal.remove(); });
      modal.addEventListener('click', function (e) { if (e.target === modal) modal.remove(); });
      modal.querySelector('.fv-submit-btn').addEventListener('click', function () { self._submit(modal); });
      this._modal = modal;
      this._load(modal);
    },

    _load: function (modal) {
      var self = this;
      App.fetchAPI('/api/feature_requests.php')
        .then(function (res) {
          if (!res.success) throw new Error(res.message || '加载失败');
          self._render(modal, res.data || []);
        })
        .catch(function () {
          modal.querySelector('.fv-list').innerHTML = '<div class="fv-empty">加载失败，请刷新重试</div>';
        });
    },

    _render: function (modal, list) {
      var box = modal.querySelector('.fv-list');
      if (!list || list.length === 0) {
        box.innerHTML = '<div class="fv-empty">暂无功能建议，来提第一条吧！</div>';
        return;
      }
      var self = this;
      box.innerHTML = list.map(function (f) {
        var done = (f.status === 'done');
        var voted = !!f.voted;
        return (
          '<div class="fv-item' + (done ? ' fv-done' : '') + '">' +
            '<div class="fv-item-main">' +
              '<div class="fv-title">' + App.escapeHtml(f.title) + (done ? ' <span class="fv-tag">已实现</span>' : '') + '</div>' +
              '<div class="fv-meta">' + App.escapeHtml(f.nickname || f.qq || '用户') + ' · ' + App.escapeHtml(f.created_at || '') + '</div>' +
            '</div>' +
            '<button type="button" class="fv-vote-btn' + (voted ? ' voted' : '') + '" data-id="' + f.id + '">' +
              '<span class="fv-heart">' + (voted ? '❤' : '♡') + '</span>' +
              '<span class="fv-count">' + (f.vote_count || 0) + '</span>' +
            '</button>' +
          '</div>'
        );
      }).join('');
      box.querySelectorAll('.fv-vote-btn').forEach(function (btn) {
        btn.addEventListener('click', function () { self._vote(modal, btn); });
      });
    },

    _vote: function (modal, btn) {
      var id = btn.getAttribute('data-id');
      App.fetchAPI('/api/feature_requests.php', {
        method: 'POST',
        body: 'action=vote&feature_id=' + encodeURIComponent(id)
      }).then(function (res) {
        if (!res.success) { App.showToast(res.message || '操作失败', 'error'); return; }
        var d = res.data || {};
        var voted = !!d.voted;
        btn.classList.toggle('voted', voted);
        btn.querySelector('.fv-heart').textContent = voted ? '❤' : '♡';
        btn.querySelector('.fv-count').textContent = d.vote_count || 0;
        App.showToast(voted ? '投票成功' : '已取消投票', 'success');
      }).catch(function () { App.showToast('网络错误', 'error'); });
    },

    _submit: function (modal) {
      var self = this;
      var input = modal.querySelector('.fv-input');
      var title = (input.value || '').trim();
      if (title.length < 2) { App.showToast('建议不能少于2个字', 'warning'); return; }
      if (title.length > 500) { App.showToast('建议不能超过500字', 'warning'); return; }
      var btn = modal.querySelector('.fv-submit-btn');
      btn.disabled = true;
      App.fetchAPI('/api/feature_requests.php', {
        method: 'POST',
        body: 'action=create&title=' + encodeURIComponent(title)
      }).then(function (res) {
        btn.disabled = false;
        if (!res.success) { App.showToast(res.message || '提交失败', 'error'); return; }
        input.value = '';
        App.showToast('提交成功', 'success');
        self._load(modal);
      }).catch(function () { btn.disabled = false; App.showToast('网络错误', 'error'); });
    }
  };

  var PrivateMessage = {
    _modal: null,
    _users: null,
    _me: null,

    init: function () {
      var btn = document.createElement('button');
      btn.className = 'pm-btn';
      btn.innerHTML = '<span class="pm-emoji">✉️</span><span class="pm-label">私信</span>';
      btn.title = '站内私信';
      btn.addEventListener('click', function (self) {
        return function () { self.open(); };
      }(this));
      mountSecondary(btn, 'me-panel');
    },

    _getMe: function () {
      return window.USER_DATA || null;
    },

    // 数据改为服务器存储（多设备同步）；本地仅保留内存镜像用于渲染
    _data: { conversations: {} },
    _loaded: false,

    _getData: function () {
      return this._data || { conversations: {} };
    },

    _save: function (data) {
      // 本地内存镜像，服务器持久化由各操作接口完成（_syncFromServer/_sendMessage）
      this._data = data;
    },

    // 从服务器拉取当前用户全部会话
    _loadFromServer: function () {
      var self = this;
      return App.fetchAPI('/api/pm.php?action=sync').then(function (res) {
        if (res && res.success) {
          self._data = { conversations: (res.data && res.data.conversations) || {} };
        }
        self._loaded = true;
        return self._data;
      });
    },

    _pairKey: function (a, b) {
      return a < b ? a + '|' + b : b + '|' + a;
    },

    open: function () {
      var me = this._getMe();
      if (!window.IS_LOGGED_IN || !me || !me.qq) { App.showToast('请先登录', 'warning'); return; }
      this._me = me;
      if (this._modal) this._modal.remove();
      var modal = document.createElement('div');
      modal.className = 'modal-overlay show';
      modal.innerHTML =
        '<div class="modal pm-modal">' +
          '<div class="modal-header">' +
            '<h3>✉️ 站内私信</h3>' +
            '<button type="button" class="modal-close pm-close" aria-label="关闭">×</button>' +
          '</div>' +
          '<div class="pm-body" id="pmBody"><div class="pm-loading">加载中...</div></div>' +
          '<div class="pm-footer-tip">私信内容已保存到服务器，可多设备同步</div>' +
        '</div>';
      document.body.appendChild(modal);
      var self = this;
      modal.querySelector('.pm-close').addEventListener('click', function () { modal.remove(); });
      modal.addEventListener('click', function (e) { if (e.target === modal) modal.remove(); });
      this._modal = modal;
      // 先加载服务器会话，再渲染列表；同时异步加载用户列表备用
      App.fetchAPI('/api/user/list.php?limit=100').then(function (res) {
        self._users = (res.data && res.data.users) || [];
      }).catch(function () { self._users = []; });
      this._loadFromServer().then(function () {
        self._renderList(modal);
      }).catch(function () {
        var box = modal.querySelector('#pmBody');
        if (box) box.innerHTML = '<div class="pm-empty">私信加载失败，请刷新重试</div>';
      });
    },

    // 点击头像等场景：直接打开与某位同学的私信会话（懒加载，点击才初始化）
    openWith: function (peerQq, peerInfo) {
      var me = this._getMe();
      if (!window.IS_LOGGED_IN || !me || !me.qq) { App.showToast('请先登录', 'warning'); return; }
      if (!peerQq || peerQq === me.qq) return;
      this._me = me;
      // 预置对方昵称/头像，便于会话标题与头像即时展示
      this._users = this._users || [];
      var hasPeer = false;
      for (var i = 0; i < this._users.length; i++) {
        if (this._users[i].qq === peerQq) { hasPeer = true; break; }
      }
      if (!hasPeer && peerInfo && peerInfo.qq === peerQq) {
        this._users.push({ qq: peerInfo.qq, nickname: peerInfo.nickname, avatar: peerInfo.avatar || '' });
      }
      if (this._modal) this._modal.remove();
      var modal = document.createElement('div');
      modal.className = 'modal-overlay show';
      modal.innerHTML =
        '<div class="modal pm-modal">' +
          '<div class="modal-header">' +
            '<h3>✉️ 站内私信</h3>' +
            '<button type="button" class="modal-close pm-close" aria-label="关闭">×</button>' +
          '</div>' +
          '<div class="pm-body" id="pmBody"><div class="pm-loading">加载中...</div></div>' +
          '<div class="pm-footer-tip">私信内容已保存到服务器，可多设备同步</div>' +
        '</div>';
      document.body.appendChild(modal);
      var self = this;
      modal.querySelector('.pm-close').addEventListener('click', function () { modal.remove(); });
      modal.addEventListener('click', function (e) { if (e.target === modal) modal.remove(); });
      this._modal = modal;
      this._loadFromServer().then(function () {
        self._openChat(modal, peerQq);
      }).catch(function () {
        var box = modal.querySelector('#pmBody');
        if (box) box.innerHTML = '<div class="pm-empty">私信加载失败，请刷新重试</div>';
      });
    },

    _renderList: function (modal) {
      var self = this;
      var data = this._getData();
      var me = this._me;
      var keys = Object.keys(data.conversations || {}).filter(function (k) {
        var c = data.conversations[k];
        return c && k.split('|').indexOf(me.qq) !== -1;
      });
      // 按最后一条消息时间倒序
      keys.sort(function (ka, kb) {
        var ta = (data.conversations[ka].messages || []).slice(-1)[0];
        var tb = (data.conversations[kb].messages || []).slice(-1)[0];
        return (tb ? tb.ts : 0) - (ta ? ta.ts : 0);
      });
      var html = keys.map(function (k) {
        var c = data.conversations[k];
        var parts = k.split('|');
        var peerQq = parts[0] === me.qq ? parts[1] : parts[0];
        var peer = (c.peer || {});
        var last = (c.messages || []).slice(-1)[0];
        var unread = self._unread(c, peerQq);
        var lastText = last ? self._brief(last.text) : '暂无消息';
        var lastTime = last ? self._timeStr(last.ts) : '';
        return (
          '<div class="pm-session' + (unread > 0 ? ' unread' : '') + '" data-qq="' + encodeURIComponent(peerQq) + '">' +
            '<img class="pm-avatar" src="' + App.escapeHtml(peer.avatar || '') + '" alt="头像" loading="lazy" onerror="this.style.visibility=\'hidden\'">' +
            '<div class="pm-session-main">' +
              '<div class="pm-session-top"><span class="pm-session-name">' + App.escapeHtml(peer.nickname || peerQq) + '</span><span class="pm-session-time">' + App.escapeHtml(lastTime) + '</span></div>' +
              '<div class="pm-session-preview">' + (unread > 0 ? '<span class="pm-badge">' + unread + '</span>' : '') + '<span class="pm-preview-text">' + App.escapeHtml(lastText) + '</span></div>' +
            '</div>' +
          '</div>'
        );
      }).join('');
      var empty = keys.length === 0 ? '<div class="pm-empty">还没有私信会话<br>点击下方按钮，找同学聊聊吧</div>' : '';
      var box = modal.querySelector('#pmBody');
      if (!box) return;
      box.innerHTML =
        '<div class="pm-view-list">' +
          '<div class="pm-sessions">' + (html || empty) + '</div>' +
          '<div class="pm-actions"><button type="button" class="btn btn-primary btn-block pm-new">+ 发起新私信</button></div>' +
        '</div>';
      box.querySelectorAll('.pm-session').forEach(function (el) {
        el.addEventListener('click', function () { self._openChat(modal, decodeURIComponent(el.getAttribute('data-qq'))); });
      });
      var newBtn = box.querySelector('.pm-new');
      if (newBtn) newBtn.addEventListener('click', function () { self._renderContacts(modal); });
    },

    _unread: function (conversation, peerQq) {
      var me = this._me;
      var readTs = (conversation.read && conversation.read[me.qq]) || 0;
      var n = 0;
      (conversation.messages || []).forEach(function (m) {
        if (m.who === peerQq && m.ts > readTs) n++;
      });
      return n;
    },

    _renderContacts: function (modal) {
      var self = this;
      var box = modal.querySelector('#pmBody');
      if (!box) return;
      var users = (this._users || []).filter(function (u) { return u.qq && u.qq !== self._me.qq; });
      box.innerHTML =
        '<div class="pm-view-contacts">' +
          '<div class="pm-contacts-search"><input type="text" id="pmSearch" class="form-input" placeholder="搜索昵称或QQ号..." autocomplete="off"></div>' +
          '<div class="pm-contacts" id="pmContacts">' +
            (users.length === 0 ? '<div class="pm-empty">暂无其他用户</div>' : users.map(function (u) {
              return '<div class="pm-session pm-contact" data-qq="' + encodeURIComponent(u.qq) + '">' +
                '<img class="pm-avatar" src="' + App.escapeHtml(u.avatar || '') + '" alt="头像" loading="lazy" onerror="this.style.visibility=\'hidden\'">' +
                '<div class="pm-session-main">' +
                  '<div class="pm-session-top"><span class="pm-session-name">' + App.escapeHtml(u.nickname || u.qq) + '</span><span class="pm-session-time">' + App.escapeHtml(u.qq) + '</span></div>' +
                  '<div class="pm-session-preview"><span class="pm-preview-text">发一条私信</span></div>' +
                '</div>' +
              '</div>';
            }).join(''))
          '</div>' +
          '<div class="pm-actions"><button type="button" class="btn btn-outline btn-block pm-back-list">← 返回会话列表</button></div>' +
        '</div>';
      box.querySelectorAll('.pm-contact').forEach(function (el) {
        el.addEventListener('click', function () { self._openChat(modal, decodeURIComponent(el.getAttribute('data-qq'))); });
      });
      var back = box.querySelector('.pm-back-list');
      if (back) back.addEventListener('click', function () { self._renderList(modal); });
      var input = box.querySelector('#pmSearch');
      if (input) input.addEventListener('input', function () {
        var kw = (input.value || '').trim().toLowerCase();
        box.querySelectorAll('.pm-contact').forEach(function (el) {
          var name = (el.querySelector('.pm-session-name') || {}).textContent || '';
          var qq = decodeURIComponent(el.getAttribute('data-qq'));
          el.style.display = (name.toLowerCase().indexOf(kw) !== -1 || qq.indexOf(kw) !== -1) ? '' : 'none';
        });
      });
    },

    _openChat: function (modal, peerQq) {
      var self = this;
      var data = this._getData();
      var me = this._me;
      var key = this._pairKey(me.qq, peerQq);
      var conversation = data.conversations[key] || {
        peer: { qq: peerQq, nickname: peerQq, avatar: '' },
        messages: [],
        read: {}
      };
      // 更新对方昵称/头像（若联系人列表里有）
      if (this._users) {
        var found = this._users.filter(function (u) { return u.qq === peerQq; })[0];
        if (found) conversation.peer = { qq: found.qq, nickname: found.nickname, avatar: found.avatar };
      }
      conversation.read = conversation.read || {};
      conversation.read[me.qq] = Date.now();
      data.conversations[key] = conversation;
      this._save(data);
      // 已读状态同步到服务器
      App.fetchAPI('/api/pm.php?action=read', {
        method: 'POST',
        body: 'to_qq=' + encodeURIComponent(peerQq)
      });

      var box = modal.querySelector('#pmBody');
      if (!box) return;
      box.innerHTML =
        '<div class="pm-view-chat">' +
          '<div class="pm-chat-header">' +
            '<button type="button" class="btn btn-outline btn-sm pm-back">←</button>' +
            '<span class="pm-chat-title">' + App.escapeHtml(conversation.peer.nickname || peerQq) + '</span>' +
            '<button type="button" class="pm-report-btn" title="举报该用户">' + svgIcon('alertCircle', 15) + ' 举报</button>' +
          '</div>' +
          '<div class="pm-messages" id="pmMessages"></div>' +
          '<div class="pm-chat-input">' +
            '<textarea id="pmMsgInput" class="form-input pm-input" rows="1" placeholder="输入私信内容..." maxlength="1000"></textarea>' +
            '<button type="button" class="btn btn-primary pm-send">发送</button>' +
          '</div>' +
        '</div>';
      box.querySelector('.pm-back').addEventListener('click', function () { self._renderList(modal); });
      var reportBtn = box.querySelector('.pm-report-btn');
      if (reportBtn) {
        reportBtn.addEventListener('click', function () {
          self._report(modal, peerQq, conversation.peer.nickname || peerQq);
        });
      }

      var messagesEl = box.querySelector('#pmMessages');
      var renderMsgs = function () {
        var msgs = (self._getData().conversations[key].messages || []);
        messagesEl.innerHTML = msgs.map(function (m) {
          var mine = m.who === me.qq;
          return (
            '<div class="pm-msg ' + (mine ? 'me' : 'them') + '">' +
              '<div class="pm-msg-bubble">' + self._renderText(m.text) + '</div>' +
              '<div class="pm-msg-time">' + self._timeStr(m.ts) + '</div>' +
            '</div>'
          );
        }).join('') || '<div class="pm-empty">打个招呼，开启你们的对话吧</div>';
        // 给 URL 风险标签绑定点击拦截
        messagesEl.querySelectorAll('.pm-url-warning').forEach(function (el) {
          el.addEventListener('click', function () {
            var u = decodeURIComponent(el.getAttribute('data-url') || '');
            if (u) self._showUrlOpenConfirm(u);
          });
        });
        messagesEl.scrollTop = messagesEl.scrollHeight;
      };
      renderMsgs();

      var input = box.querySelector('#pmMsgInput');
      var sendBtn = box.querySelector('.pm-send');
      var doSend = function () {
        var text = (input.value || '').trim();
        if (!text) return;
        input.value = '';
        input.style.height = 'auto';
        sendBtn.disabled = true;
        App.fetchAPI('/api/pm.php?action=send', {
          method: 'POST',
          body: 'to_qq=' + encodeURIComponent(peerQq) + '&text=' + encodeURIComponent(text)
        }).then(function (res) {
          sendBtn.disabled = false;
          if (!res.success) { App.showToast(res.message || '发送失败', 'error'); input.value = text; return; }
          // 用服务器返回的会话数据更新本地镜像与渲染
          var d = self._getData();
          var conv = {
            peer: res.data.peer || conversation.peer,
            read: res.data.read || {},
            messages: res.data.messages || []
          };
          d.conversations[key] = conv;
          self._save(d);
          renderMsgs();
          input.focus();
        }).catch(function () {
          sendBtn.disabled = false;
          App.showToast('网络错误，发送失败', 'error');
          input.value = text;
        });
      };
      var send = function () {
        var text = (input.value || '').trim();
        if (!text) return;
        // 含外站链接：弹闲鱼式风险提示，确认后才发
        if (self._hasUrl(text)) {
          self._showUrlSendWarning(doSend);
          return;
        }
        doSend();
      };
      sendBtn.addEventListener('click', send);
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
      });
      input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 96) + 'px';
      });
      var autoResize = setInterval(function () {
        if (!document.body.contains(modal)) clearInterval(autoResize);
      }, 1000);
      setTimeout(function () { input.focus(); }, 50);
    },

    // URL 检测正则：仅识别显式链接（http(s):// 或 www.开头），
    // 不再把“裸域名/无协议的单词+常见后缀”（如 123.com、aa.cn 等正文随口提到的词）判定为风险链接，
    // 减少误报。
    _urlRegex: /(https?:\/\/[a-z0-9][-a-z0-9.]{0,200}[a-z0-9](?::\d+)?(?:\/[^\s<>"']*)?|www\.[a-z0-9][-a-z0-9.]{0,200}[a-z0-9](?::\d+)?(?:\/[^\s<>"']*)?)/gi,

    _hasUrl: function (text) {
      if (!text) return false;
      this._urlRegex.lastIndex = 0;
      return this._urlRegex.test(text);
    },

    // 渲染消息文本：转义 HTML + 把 URL 渲染为带风险标识的不可点击文本
    _renderText: function (text) {
      text = String(text || '');
      var regex = new RegExp(this._urlRegex.source, 'gi');
      var html = '';
      var last = 0;
      var match;
      while ((match = regex.exec(text)) !== null) {
        var url = match[0];
        if (match.index > last) {
          html += App.escapeHtml(text.slice(last, match.index)).replace(/\n/g, '<br>');
        }
        var full = url;
        if (full.indexOf('http') !== 0 && full.toLowerCase().indexOf('www.') === 0) full = 'http://' + full;
        html += '<span class="pm-url-warning" data-url="' + encodeURIComponent(full) + '">' +
                '<span class="pm-url-flag">⚠️ 风险链接</span>' +
                '<span class="pm-url-text">' + App.escapeHtml(url) + '</span>' +
                '</span>';
        last = match.index + url.length;
      }
      if (last < text.length) {
        html += App.escapeHtml(text.slice(last)).replace(/\n/g, '<br>');
      }
      return html;
    },

    // 发送含链接消息前的风险提示（闲鱼风格）
    _showUrlSendWarning: function (onConfirm) {
      // 已存在则不重复弹
      var existing = document.getElementById('pmUrlSendWarn');
      if (existing) { existing.remove(); }
      var dlg = document.createElement('div');
      dlg.id = 'pmUrlSendWarn';
      dlg.className = 'modal-overlay show';
      dlg.innerHTML =
        '<div class="modal pm-url-modal">' +
          '<div class="pm-url-icon-big">⚠️</div>' +
          '<div class="pm-url-title">消息中包含外部链接</div>' +
          '<div class="pm-url-desc">' +
            '对方发送的链接可能存在<strong>诈骗、钓鱼、木马</strong>等风险。<br>' +
            '本站无法核实外站链接的安全性，请谨慎甄别，<strong>不要轻易输入账号密码或支付</strong>。' +
          '</div>' +
          '<div class="pm-url-actions">' +
            '<button type="button" class="btn btn-outline pm-url-cancel">取消</button>' +
            '<button type="button" class="btn btn-danger pm-url-continue">仍要发送</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(dlg);
      var close = function () { dlg.remove(); };
      dlg.querySelector('.pm-url-cancel').addEventListener('click', close);
      dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
      dlg.querySelector('.pm-url-continue').addEventListener('click', function () {
        close();
        if (typeof onConfirm === 'function') onConfirm();
      });
    },

    // 点击 URL 文本时的二次确认（防止误点跳走）
    _showUrlOpenConfirm: function (url) {
      var existing = document.getElementById('pmUrlOpenConfirm');
      if (existing) { existing.remove(); }
      var dlg = document.createElement('div');
      dlg.id = 'pmUrlOpenConfirm';
      dlg.className = 'modal-overlay show';
      // 显示前 60 字，避免过长
      var shortUrl = url.length > 60 ? url.slice(0, 60) + '...' : url;
      dlg.innerHTML =
        '<div class="modal pm-url-modal">' +
          '<div class="pm-url-icon-big">🚫</div>' +
          '<div class="pm-url-title">即将离开本站</div>' +
          '<div class="pm-url-desc">' +
            '你即将访问外站链接：<br>' +
            '<code class="pm-url-code">' + App.escapeHtml(shortUrl) + '</code><br>' +
            '该链接与本站无关，可能存在风险。是否继续？' +
          '</div>' +
          '<div class="pm-url-actions">' +
            '<button type="button" class="btn btn-outline pm-url-cancel">留在本站</button>' +
            '<button type="button" class="btn btn-danger pm-url-continue">继续访问</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(dlg);
      var close = function () { dlg.remove(); };
      dlg.querySelector('.pm-url-cancel').addEventListener('click', close);
      dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
      dlg.querySelector('.pm-url-continue').addEventListener('click', function () {
        close();
        // 新标签打开，加 noopener/noreferrer 提升安全
        var w = window.open(url, '_blank', 'noopener,noreferrer');
        if (!w) App.showToast('已阻止打开新窗口，请检查浏览器弹窗设置', 'warning');
      });
    },

    _brief: function (text) {
      text = String(text || '');
      return text.length > 40 ? text.slice(0, 40) + '...' : text;
    },

    _timeStr: function (ts) {
      if (!ts) return '';
      var d = new Date(ts);
      var now = new Date();
      var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
      var hm = pad(d.getHours()) + ':' + pad(d.getMinutes());
      if (d.toDateString() === now.toDateString()) return hm;
      var yesterday = new Date(now.getTime() - 86400000);
      if (d.toDateString() === yesterday.toDateString()) return '昨天 ' + hm;
      return (d.getMonth() + 1) + '/' + d.getDate();
    },

    _report: function (chatModal, peerQq, peerNickname) {
      var me = this._me;
      var self = this;
      var data = this._getData();
      var key = this._pairKey(me.qq, peerQq);
      var msgs = ((data.conversations[key] || {}).messages || []).slice(-30);

      var reportModal = document.createElement('div');
      reportModal.className = 'modal-overlay show';
      reportModal.innerHTML =
        '<div class="modal pmr-modal">' +
          '<div class="modal-header">' +
            '<h3>举报用户</h3>' +
            '<button type="button" class="modal-close pmr-close" aria-label="关闭">×</button>' +
          '</div>' +
          '<div class="modal-body pmr-body">' +
            '<p class="pmr-target">被举报人：<strong>' + App.escapeHtml(peerNickname) + '</strong></p>' +
            '<div class="pmr-rec-header">' +
              '<span>勾选要提交的聊天记录（最近 ' + msgs.length + ' 条）</span>' +
              '<button type="button" class="btn btn-outline btn-sm pmr-all">全选/全不选</button>' +
            '</div>' +
            '<div class="pmr-rec-list">' +
              (msgs.length === 0 ? '<div class="pmr-empty">暂无聊天记录可选</div>' : msgs.map(function (m, i) {
                var mine = m.who === me.qq;
                var who = mine ? '我' : '对方';
                return '<label class="pmr-rec">' +
                  '<input type="checkbox" class="pmr-check" value="' + i + '">' +
                  '<div class="pmr-rec-main">' +
                    '<span class="pmr-rec-meta">' + who + ' · ' + self._timeStr(m.ts) + '</span>' +
                    '<span class="pmr-rec-text">' + App.escapeHtml(m.text) + '</span>' +
                  '</div>' +
                '</label>';
              }).join(''))
            '</div>' +
            '<label class="pmr-reason-label">举报理由 <span class="pmr-required">*</span></label>' +
            '<textarea id="pmrReason" class="form-input" rows="3" placeholder="请描述具体违规情况..." maxlength="500"></textarea>' +
            '<div class="pmr-action">' +
              '<button type="button" class="btn btn-outline pmr-cancel">取消</button>' +
              '<button type="button" class="btn btn-danger pmr-submit">提交举报</button>' +
            '</div>' +
          '</div>' +
        '</div>';
      document.body.appendChild(reportModal);
      reportModal.querySelector('.pmr-close').addEventListener('click', function () { reportModal.remove(); });
      reportModal.querySelector('.pmr-cancel').addEventListener('click', function () { reportModal.remove(); });
      reportModal.addEventListener('click', function (e) { if (e.target === reportModal) reportModal.remove(); });
      var allBtn = reportModal.querySelector('.pmr-all');
      if (allBtn) allBtn.addEventListener('click', function () {
        var checks = reportModal.querySelectorAll('.pmr-check');
        var someUnchecked = Array.prototype.some.call(checks, function (c) { return !c.checked; });
        checks.forEach(function (c) { c.checked = someUnchecked; });
      });
      var submitBtn = reportModal.querySelector('.pmr-submit');
      submitBtn.addEventListener('click', function () {
        var reason = (reportModal.querySelector('#pmrReason').value || '').trim();
        if (reason.length < 2 || reason.length > 500) { App.showToast('举报理由需在2-500字之间', 'warning'); return; }
        var selected = [];
        reportModal.querySelectorAll('.pmr-check:checked').forEach(function (c) {
          var m = msgs[parseInt(c.value, 10)];
          if (m) selected.push({ who: m.who === me.qq ? '我' : '对方', text: m.text, ts: m.ts });
        });
        submitBtn.disabled = true;
        App.fetchAPI('/api/pm_report.php', {
          method: 'POST',
          body: 'target_qq=' + encodeURIComponent(peerQq) +
                '&target_nickname=' + encodeURIComponent(peerNickname) +
                '&reason=' + encodeURIComponent(reason) +
                '&records=' + encodeURIComponent(JSON.stringify(selected))
        }).then(function (res) {
          submitBtn.disabled = false;
          if (!res.success) { App.showToast(res.message || '提交失败', 'error'); return; }
          reportModal.remove();
          App.showToast(res.message || '举报已提交', 'success');
        }).catch(function () { submitBtn.disabled = false; App.showToast('网络错误', 'error'); });
      });
    }
  };

  var DiceRoller = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('dice-styles')) return;
      var style = document.createElement('style');
      style.id = 'dice-styles';
      style.textContent = '.dice-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10011;display:flex;align-items:center;justify-content:center;}.dice-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.dice-content{position:relative;background:var(--card-bg);border-radius:20px;padding:28px;width:90%;max-width:340px;text-align:center;animation:slideUp 0.3s ease;}.dice-display{width:100px;height:100px;margin:0 auto 20px;border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:3rem;font-weight:700;color:#fff;transition:all 0.3s;}.dice-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}.dice-btn{padding:10px 20px;border:none;border-radius:10px;cursor:pointer;font-size:0.9rem;font-weight:600;transition:all 0.2s;font-family:inherit;}.dice-roll{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;}.dice-history{font-size:0.8rem;color:var(--text-muted);margin-top:12px;max-height:60px;overflow-y:auto;}.dice-trigger{position:fixed;right:80px;bottom:120px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.dice-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('dice-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'dice-trigger-btn';
      btn.className = 'dice-trigger';
      btn.title = '骰子';
      btn.innerHTML = svgIcon('dice', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var history = JSON.parse(localStorage.getItem('wl_dice_history') || '[]');
      var html = '<div class="dice-modal"><div class="dice-overlay"></div><div class="dice-content"><h3 style="margin:0 0 8px;color:var(--text);">骰子</h3><div class="dice-display" id="diceResult" style="background:linear-gradient(135deg,#667eea,#764ba2);">?</div><div class="dice-btns"><button class="dice-btn dice-roll" id="diceRoll">掷骰子</button></div><div class="dice-history" id="diceHistory">' + (history.length ? '最近: ' + history.slice(-5).reverse().join(', ') : '') + '</div><button style="margin-top:12px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;" id="diceClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.dice-modal');
      this._modal.querySelector('.dice-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#diceClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#diceRoll').addEventListener('click', function () {
        var result = Math.floor(Math.random() * 6) + 1;
        var dotPatterns = ['','\u2680','\u2681','\u2682','\u2683','\u2684','\u2685'];
        var colors = ['','#E91E63','#4CAF50','#2196F3','#FF9800','#9C27B0','#F44336'];
        var display = document.getElementById('diceResult');
        display.textContent = dotPatterns[result] || result;
        display.style.background = colors[result];
        display.style.transform = 'rotate(360deg)';
        setTimeout(function () { display.style.transform = ''; }, 300);
        history.push(result);
        if (history.length > 20) history.shift();
        localStorage.setItem('wl_dice_history', JSON.stringify(history));
        document.getElementById('diceHistory').textContent = '最近: ' + history.slice(-5).reverse().join(', ');
      });
    }
  };


  var CoinFlip = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('coin-styles')) return;
      var style = document.createElement('style');
      style.id = 'coin-styles';
      style.textContent = '.coin-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10012;display:flex;align-items:center;justify-content:center;}.coin-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.coin-content{position:relative;background:var(--card-bg);border-radius:20px;padding:28px;width:90%;max-width:300px;text-align:center;animation:slideUp 0.3s ease;}.coin-circle{width:100px;height:100px;border-radius:50%;margin:0 auto 20px;background:linear-gradient(135deg,#FFD54F,#FF8F00);display:flex;align-items:center;justify-content:center;font-size:2.5rem;transition:transform 0.8s ease;}.coin-flip{animation:coinFlip 0.8s ease;}.coin-btn{padding:10px 24px;background:linear-gradient(135deg,#FFD54F,#FF8F00);color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:0.9rem;font-weight:600;font-family:inherit;}.coin-result{font-size:1.2rem;font-weight:700;color:var(--text);margin-top:12px;}.coin-stats{font-size:0.8rem;color:var(--text-muted);margin-top:8px;}@keyframes coinFlip{0%{transform:rotateY(0)}50%{transform:rotateY(720deg)}100%{transform:rotateY(1440deg)}}.coin-trigger{position:fixed;right:80px;bottom:170px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#FFD54F,#FF8F00);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(255,213,79,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.coin-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('coin-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'coin-trigger-btn';
      btn.className = 'coin-trigger';
      btn.title = '抛硬币';
      btn.innerHTML = svgIcon('star', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var stats = JSON.parse(localStorage.getItem('wl_coin_stats') || '{"heads":0,"tails":0}');
      var html = '<div class="coin-modal"><div class="coin-overlay"></div><div class="coin-content"><h3 style="margin:0 0 8px;color:var(--text);">抛硬币</h3><div class="coin-circle" id="coinCircle">&#129689;</div><div class="coin-result" id="coinResult">点击下方按钮</div><div class="coin-stats" id="coinStats">正面 ' + stats.heads + ' | 反面 ' + stats.tails + '</div><button class="coin-btn" id="coinBtn">抛硬币</button><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="coinClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.coin-modal');
      this._modal.querySelector('.coin-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#coinClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#coinBtn').addEventListener('click', function () {
        var circle = document.getElementById('coinCircle');
        circle.classList.remove('coin-flip');
        void circle.offsetWidth;
        circle.classList.add('coin-flip');
        var isHeads = Math.random() > 0.5;
        setTimeout(function () {
          circle.textContent = isHeads ? '\u263A' : '\u2639';
          document.getElementById('coinResult').textContent = isHeads ? '正面！' : '反面！';
          stats[isHeads ? 'heads' : 'tails']++;
          localStorage.setItem('wl_coin_stats', JSON.stringify(stats));
          document.getElementById('coinStats').textContent = '正面 ' + stats.heads + ' | 反面 ' + stats.tails;
        }, 400);
      });
    }
  };


  var RandomPicker = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('picker-styles')) return;
      var style = document.createElement('style');
      style.id = 'picker-styles';
      style.textContent = '.picker-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10013;display:flex;align-items:center;justify-content:center;}.picker-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.picker-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:400px;text-align:center;animation:slideUp 0.3s ease;}.picker-textarea{width:100%;height:100px;padding:10px;border:1px solid var(--border);border-radius:10px;font-size:0.9rem;resize:vertical;margin-bottom:12px;box-sizing:border-box;font-family:inherit;}.picker-textarea:focus{border-color:var(--primary);outline:none;}.picker-result{font-size:1.5rem;font-weight:700;color:var(--primary);margin:16px 0;min-height:40px;display:flex;align-items:center;justify-content:center;transition:all 0.3s;}.picker-result.rolling{animation:pickerRoll 0.1s steps(1) infinite;}@keyframes pickerRoll{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}.picker-btn{padding:10px 24px;background:linear-gradient(135deg,#f093fb,#f5576c);color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:0.9rem;font-weight:600;font-family:inherit;margin:4px;}.picker-trigger{position:fixed;right:80px;bottom:220px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#f093fb,#f5576c);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(240,147,251,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.picker-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('picker-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'picker-trigger-btn';
      btn.className = 'picker-trigger';
      btn.title = '随机抽取';
      btn.innerHTML = svgIcon('dice', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var saved = localStorage.getItem('wl_picker_items') || '';
      var html = '<div class="picker-modal"><div class="picker-overlay"></div><div class="picker-content"><h3 style="margin:0 0 12px;color:var(--text);">随机抽取</h3><textarea class="picker-textarea" id="pickerInput" placeholder="输入选项，每行一个...">' + escapeHtml(saved) + '</textarea><div class="picker-result" id="pickerResult">点击抽取</div><button class="picker-btn" id="pickerBtn">随机抽取</button><button class="picker-btn" id="pickerClear" style="background:var(--bg);color:var(--text-secondary);">清空</button><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="pickerClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.picker-modal');
      this._modal.querySelector('.picker-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#pickerClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#pickerBtn').addEventListener('click', function () {
        var text = document.getElementById('pickerInput').value.trim();
        localStorage.setItem('wl_picker_items', text);
        var items = text.split('\n').filter(function (i) { return i.trim(); });
        if (items.length === 0) { showToast('请先输入选项', 'warning'); return; }
        var resultEl = document.getElementById('pickerResult');
        resultEl.classList.add('rolling');
        var count = 0, max = 15;
        var timer = setInterval(function () {
          resultEl.textContent = items[Math.floor(Math.random() * items.length)];
          count++;
          if (count >= max) { clearInterval(timer); resultEl.classList.remove('rolling'); }
        }, 80);
      });
      this._modal.querySelector('#pickerClear').addEventListener('click', function () {
        document.getElementById('pickerInput').value = '';
        document.getElementById('pickerResult').textContent = '点击抽取';
        localStorage.removeItem('wl_picker_items');
      });
    }
  };


  var NotePad = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('note-styles')) return;
      var style = document.createElement('style');
      style.id = 'note-styles';
      style.textContent = '.note-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10014;display:flex;align-items:center;justify-content:center;}.note-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.note-content{position:relative;background:#fff8e1;border-radius:16px;padding:24px;width:90%;max-width:420px;animation:slideUp 0.3s ease;box-shadow:0 8px 30px rgba(0,0,0,0.12);}.note-textarea{width:100%;height:200px;padding:12px;border:none;background:transparent;font-size:0.95rem;resize:vertical;font-family:inherit;line-height:1.8;color:#5D4037;outline:none;box-sizing:border-box;}.note-textarea::placeholder{color:#BCAAA4;}.note-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px;}.note-save{padding:8px 20px;background:#FFB74D;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:0.85rem;font-weight:600;font-family:inherit;}.note-clear{background:#EF5350;}.note-trigger{position:fixed;right:80px;bottom:270px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#FFE082,#FFB74D);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(255,224,130,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.note-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('note-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'note-trigger-btn';
      btn.className = 'note-trigger';
      btn.title = '便签';
      btn.innerHTML = svgIcon('edit', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    _save: function () {
      var textarea = document.getElementById('noteTextarea');
      if (textarea) {
        localStorage.setItem('wl_notepad', textarea.value);
        showToast('已保存');
      }
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var content = localStorage.getItem('wl_notepad') || '';
      var html = '<div class="note-modal"><div class="note-overlay"></div><div class="note-content"><h3 style="margin:0 0 8px;color:#5D4037;">便签</h3><textarea class="note-textarea" id="noteTextarea" placeholder="记录你的想法...">' + escapeHtml(content) + '</textarea><div class="note-actions"><button class="note-save note-clear" id="noteClear">清空</button><button class="note-save" id="noteSave">保存</button></div><button style="margin-top:8px;background:none;border:none;color:#BCAAA4;cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="noteClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.note-modal');
      this._modal.querySelector('.note-overlay').addEventListener('click', function () { self._save(); self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#noteClose').addEventListener('click', function () { self._save(); self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#noteSave').addEventListener('click', function () { self._save(); });
      this._modal.querySelector('#noteClear').addEventListener('click', function () {
        document.getElementById('noteTextarea').value = '';
        localStorage.removeItem('wl_notepad');
        showToast('已清空');
      });
    }
  };


  var PollSystem = {
    init: function () {
      this._scanPollWidgets();
    },
    _scanPollWidgets: function () {
      var self = this;
      document.querySelectorAll('.poll-widget').forEach(function (poll) {
        if (poll._pollBound) return;
        poll._pollBound = true;
        poll.querySelectorAll('.poll-option').forEach(function (opt) {
          opt.addEventListener('click', function () {
            if (poll.classList.contains('poll-voted')) return;
            poll.querySelectorAll('.poll-option').forEach(function (o) { o.classList.remove('selected'); });
            this.classList.add('selected');
          });
        });
        var voteBtn = poll.querySelector('.poll-vote-btn');
        if (voteBtn) {
          voteBtn.addEventListener('click', function () {
            var selected = poll.querySelector('.poll-option.selected');
            if (!selected) { showToast('请先选择一个选项', 'warning'); return; }
            var postId = poll.getAttribute('data-post-id');
            var optionIdx = selected.getAttribute('data-index');
            self._submitVote(poll, postId, optionIdx);
          });
        }
      });
    },
    _submitVote: async function (poll, postId, optionIdx) {
      try {
        var res = await fetchAPI('/api/posts/vote.php', { method: 'POST', body: 'post_id=' + postId + '&option=' + optionIdx });
        if (res.success) {
          poll.classList.add('poll-voted');
          poll.querySelector('.poll-results').style.display = 'block';
          poll.querySelector('.poll-vote-btn').style.display = 'none';
          var results = res.data || [];
          var total = results.reduce(function (s, r) { return s + r.count; }, 0) || 1;
          poll.querySelectorAll('.poll-option').forEach(function (opt, i) {
            var pct = Math.round((results[i] ? results[i].count : 0) / total * 100);
            var bar = opt.querySelector('.poll-bar');
            if (bar) { bar.style.width = pct + '%'; }
            var pctEl = opt.querySelector('.poll-pct');
            if (pctEl) { pctEl.textContent = pct + '%'; }
          });
          showToast('投票成功', 'success');
        } else {
          showToast(res.message || '投票失败', 'error');
        }
      } catch (e) { showToast('网络错误', 'error'); }
    }
  };


  var TodoList = {
    _items: [],
    _modal: null,
    init: function () {
      this._loadFromStorage();
      this._injectStyles();
      this._injectTrigger();
    },
    _loadFromStorage: function () {
      try { this._items = JSON.parse(localStorage.getItem('wl_todos') || '[]'); } catch (e) { this._items = []; }
    },
    _saveToStorage: function () {
      localStorage.setItem('wl_todos', JSON.stringify(this._items));
    },
    _injectStyles: function () {
      if (document.getElementById('todo-styles')) return;
      var style = document.createElement('style');
      style.id = 'todo-styles';
      style.textContent = '.todo-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10002;display:flex;align-items:center;justify-content:center;}.todo-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);animation:fadeIn 0.2s ease;}.todo-content{position:relative;background:var(--card-bg);border-radius:16px;width:90%;max-width:420px;max-height:70vh;display:flex;flex-direction:column;overflow:hidden;animation:slideUp 0.3s ease;}.todo-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);}.todo-title{font-size:1.1rem;font-weight:700;color:var(--text);}.todo-close{width:32px;height:32px;border:none;background:var(--bg);border-radius:50%;cursor:pointer;font-size:1.2rem;display:flex;align-items:center;justify-content:center;color:var(--text-secondary);}.todo-close:hover{background:var(--danger);color:#fff;}.todo-input-row{display:flex;gap:8px;padding:12px 20px;border-bottom:1px solid var(--border);}.todo-input{flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:0.9rem;outline:none;font-family:inherit;}.todo-input:focus{border-color:var(--primary);}.todo-add-btn{padding:8px 16px;background:var(--primary);color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:0.85rem;font-weight:600;}.todo-list{flex:1;overflow-y:auto;padding:8px 0;}.todo-item{display:flex;align-items:center;gap:10px;padding:10px 20px;transition:background 0.2s;}.todo-item:hover{background:var(--bg);}.todo-check{width:20px;height:20px;border:2px solid var(--border);border-radius:50%;cursor:pointer;flex-shrink:0;transition:all 0.2s;}.todo-check.done{background:var(--success);border-color:var(--success);}.todo-check.done::after{content:\'\';display:block;width:5px;height:9px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg);margin:3px 0 0 6px;}.todo-text{flex:1;font-size:0.9rem;color:var(--text);}.todo-text.done{text-decoration:line-through;color:var(--text-muted);}.todo-del{width:28px;height:28px;border:none;background:none;cursor:pointer;color:var(--text-muted);font-size:1rem;border-radius:6px;display:flex;align-items:center;justify-content:center;}.todo-del:hover{background:var(--danger-light);color:var(--danger);}.todo-footer{padding:8px 20px;border-top:1px solid var(--border);font-size:0.75rem;color:var(--text-muted);text-align:center;}.todo-trigger{position:fixed;right:20px;bottom:180px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#f093fb,#f5576c);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(240,147,251,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.todo-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('todo-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'todo-trigger-btn';
      btn.className = 'todo-trigger';
      btn.title = '待办事项';
      btn.innerHTML = svgIcon('check', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var modal = document.createElement('div');
      modal.className = 'todo-modal';
      var html = '<div class="todo-overlay"></div><div class="todo-content">';
      html += '<div class="todo-header"><span class="todo-title">待办事项</span><button class="todo-close">&times;</button></div>';
      html += '<div class="todo-input-row"><input class="todo-input" placeholder="添加新任务..." id="todoNewInput"><button class="todo-add-btn" id="todoAddBtn">添加</button></div>';
      html += '<div class="todo-list" id="todoListContainer"></div>';
      html += '<div class="todo-footer">共 <span id="todoCount">0</span> 项任务</div>';
      html += '</div>';
      modal.innerHTML = html;
      document.body.appendChild(modal);
      this._modal = modal;
      this._renderList();
      modal.querySelector('.todo-overlay').addEventListener('click', function () { modal.remove(); self._modal = null; });
      modal.querySelector('.todo-close').addEventListener('click', function () { modal.remove(); self._modal = null; });
      modal.querySelector('#todoAddBtn').addEventListener('click', function () { self._add(); });
      modal.querySelector('#todoNewInput').addEventListener('keydown', function (e) { if (e.key === 'Enter') self._add(); });
    },
    _add: function () {
      var input = document.getElementById('todoNewInput');
      var text = (input.value || '').trim();
      if (!text) return;
      this._items.push({ id: Date.now(), text: text, done: false });
      input.value = '';
      this._saveToStorage();
      this._renderList();
    },
    _toggle: function (id) {
      var item = this._items.find(function (i) { return i.id === id; });
      if (item) { item.done = !item.done; }
      this._saveToStorage();
      this._renderList();
    },
    _remove: function (id) {
      this._items = this._items.filter(function (i) { return i.id !== id; });
      this._saveToStorage();
      this._renderList();
    },
    _renderList: function () {
      var container = document.getElementById('todoListContainer');
      var countEl = document.getElementById('todoCount');
      if (!container) return;
      var self = this;
      var html = '';
      this._items.forEach(function (item) {
        html += '<div class="todo-item"><div class="todo-check' + (item.done ? ' done' : '') + '" data-id="' + item.id + '"></div>';
        html += '<span class="todo-text' + (item.done ? ' done' : '') + '">' + escapeHtml(item.text) + '</span>';
        html += '<button class="todo-del" data-id="' + item.id + '">&times;</button></div>';
      });
      container.innerHTML = html || '<div style="text-align:center;padding:30px;color:var(--text-muted);">暂无任务</div>';
      if (countEl) { countEl.textContent = this._items.length; }
      container.querySelectorAll('.todo-check').forEach(function (cb) {
        cb.addEventListener('click', function () { self._toggle(parseInt(this.getAttribute('data-id'))); });
      });
      container.querySelectorAll('.todo-del').forEach(function (btn) {
        btn.addEventListener('click', function () { self._remove(parseInt(this.getAttribute('data-id'))); });
      });
    }
  };


  var Stopwatch = {
    _startTime: 0, _elapsed: 0, _timer: null, _running: false, _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('stopwatch-styles')) return;
      var style = document.createElement('style');
      style.id = 'stopwatch-styles';
      style.textContent = '.sw-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10003;display:flex;align-items:center;justify-content:center;}.sw-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.sw-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:320px;text-align:center;animation:slideUp 0.3s ease;}.sw-time{font-size:3rem;font-weight:700;font-variant-numeric:tabular-nums;color:var(--text);margin:16px 0;font-family:monospace;}.sw-btns{display:flex;gap:10px;justify-content:center;}.sw-btn{padding:10px 24px;border:none;border-radius:10px;cursor:pointer;font-size:0.9rem;font-weight:600;transition:all 0.2s;font-family:inherit;}.sw-btn-start{background:var(--success);color:#fff;}.sw-btn-stop{background:var(--danger);color:#fff;}.sw-btn-reset{background:var(--bg);color:var(--text-secondary);}.sw-trigger{position:fixed;right:20px;bottom:240px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#a18cd1,#fbc2eb);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(161,140,209,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.sw-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('sw-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'sw-trigger-btn';
      btn.className = 'sw-trigger';
      btn.title = '秒表';
      btn.innerHTML = svgIcon('clock', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var modal = document.createElement('div');
      modal.className = 'sw-modal';
      modal.innerHTML = '<div class="sw-overlay"></div><div class="sw-content"><h3 style="margin:0 0 8px;color:var(--text);">秒表</h3><div class="sw-time" id="swDisplay">00:00.00</div><div class="sw-btns"><button class="sw-btn sw-btn-start" id="swStart">开始</button><button class="sw-btn sw-btn-reset" id="swReset">重置</button></div><button style="margin-top:12px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;" id="swClose">关闭</button></div>';
      document.body.appendChild(modal);
      this._modal = modal;
      this._updateDisplay();
      modal.querySelector('.sw-overlay').addEventListener('click', function () { self._stop(); modal.remove(); self._modal = null; });
      modal.querySelector('#swClose').addEventListener('click', function () { self._stop(); modal.remove(); self._modal = null; });
      modal.querySelector('#swStart').addEventListener('click', function () { self._toggle(); });
      modal.querySelector('#swReset').addEventListener('click', function () { self._reset(); });
    },
    _toggle: function () {
      if (this._running) { this._stop(); } else { this._start(); }
    },
    _start: function () {
      var self = this;
      this._running = true;
      this._startTime = Date.now() - this._elapsed;
      var btn = document.getElementById('swStart');
      if (btn) { btn.textContent = '暂停'; btn.className = 'sw-btn sw-btn-stop'; }
      this._timer = setInterval(function () { self._elapsed = Date.now() - self._startTime; self._updateDisplay(); }, 50);
    },
    _stop: function () {
      this._running = false;
      if (this._timer) { clearInterval(this._timer); this._timer = null; }
      var btn = document.getElementById('swStart');
      if (btn) { btn.textContent = '继续'; btn.className = 'sw-btn sw-btn-start'; }
    },
    _reset: function () {
      this._stop();
      this._elapsed = 0;
      this._updateDisplay();
      var btn = document.getElementById('swStart');
      if (btn) { btn.textContent = '开始'; btn.className = 'sw-btn sw-btn-start'; }
    },
    _updateDisplay: function () {
      var el = document.getElementById('swDisplay');
      if (!el) return;
      var total = this._elapsed;
      var min = Math.floor(total / 60000);
      var sec = Math.floor((total % 60000) / 1000);
      var ms = Math.floor((total % 1000) / 10);
      el.textContent = String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0') + '.' + String(ms).padStart(2, '0');
    }
  };


  var Pomodoro = {
    _duration: 25 * 60, _remaining: 25 * 60, _timer: null, _running: false, _modal: null, _sessions: 0,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('pomodoro-styles')) return;
      var style = document.createElement('style');
      style.id = 'pomodoro-styles';
      style.textContent = '.pomo-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10004;display:flex;align-items:center;justify-content:center;}.pomo-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.pomo-content{position:relative;background:var(--card-bg);border-radius:20px;padding:28px;width:90%;max-width:360px;text-align:center;animation:slideUp 0.3s ease;}.pomo-ring{width:160px;height:160px;border-radius:50%;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;position:relative;}.pomo-ring-inner{width:140px;height:140px;border-radius:50%;background:var(--card-bg);display:flex;align-items:center;justify-content:center;flex-direction:column;}.pomo-time{font-size:2.2rem;font-weight:700;color:var(--text);font-family:monospace;}.pomo-label{font-size:0.8rem;color:var(--text-muted);}.pomo-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}.pomo-btn{padding:10px 20px;border:none;border-radius:8px;cursor:pointer;font-size:0.85rem;font-weight:600;transition:all 0.2s;font-family:inherit;}.pomo-btn-start{background:linear-gradient(135deg,#FF6B6B,#E91E63);color:#fff;}.pomo-btn-pause{background:linear-gradient(135deg,#FFB74D,#F57C00);color:#fff;}.pomo-btn-reset{background:var(--bg);color:var(--text-secondary);}.pomo-sessions{font-size:0.8rem;color:var(--text-muted);margin-top:12px;}.pomo-trigger{position:fixed;right:20px;bottom:300px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#FF6B6B,#E91E63);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(255,107,107,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.pomo-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('pomo-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'pomo-trigger-btn';
      btn.className = 'pomo-trigger';
      btn.title = '番茄钟';
      btn.innerHTML = svgIcon('clock', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var modal = document.createElement('div');
      modal.className = 'pomo-modal';
      var pct = this._duration > 0 ? (this._remaining / this._duration) * 100 : 100;
      var bgColor = this._running ? '#FF6B6B' : '#4FC3F7';
      modal.innerHTML = '<div class="pomo-overlay"></div><div class="pomo-content"><h3 style="margin:0 0 8px;color:var(--text);">番茄钟</h3><div class="pomo-ring" style="background:conic-gradient(' + bgColor + ' ' + (100 - pct) * 3.6 + 'deg, var(--bg) 0deg);"><div class="pomo-ring-inner"><div class="pomo-time" id="pomoTime">25:00</div><div class="pomo-label">分钟</div></div></div><div class="pomo-btns"><button class="pomo-btn pomo-btn-start" id="pomoToggle">开始</button><button class="pomo-btn pomo-btn-reset" id="pomoReset">重置</button></div><div class="pomo-sessions">已完成 <span id="pomoSessions">0</span> 个番茄</div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;" id="pomoClose">关闭</button></div>';
      document.body.appendChild(modal);
      this._modal = modal;
      this._display();
      modal.querySelector('.pomo-overlay').addEventListener('click', function () { self._pause(); modal.remove(); self._modal = null; });
      modal.querySelector('#pomoClose').addEventListener('click', function () { self._pause(); modal.remove(); self._modal = null; });
      modal.querySelector('#pomoToggle').addEventListener('click', function () { self._toggle(); });
      modal.querySelector('#pomoReset').addEventListener('click', function () { self._reset(); });
    },
    _toggle: function () {
      if (this._running) { this._pause(); } else { this._resume(); }
    },
    _resume: function () {
      var self = this;
      this._running = true;
      var btn = document.getElementById('pomoToggle');
      if (btn) { btn.textContent = '暂停'; btn.className = 'pomo-btn pomo-btn-pause'; }
      this._timer = setInterval(function () {
        if (self._remaining <= 0) {
          self._pause();
          self._sessions++;
          self._remaining = self._duration;
          showToast('番茄钟完成！休息一下', 'success');
          if (App.NotificationSound) { App.NotificationSound.play(); }
          self._display();
          return;
        }
        self._remaining--;
        self._display();
      }, 1000);
    },
    _pause: function () {
      this._running = false;
      if (this._timer) { clearInterval(this._timer); this._timer = null; }
      var btn = document.getElementById('pomoToggle');
      if (btn) { btn.textContent = '继续'; btn.className = 'pomo-btn pomo-btn-start'; }
    },
    _reset: function () {
      this._pause();
      this._remaining = this._duration;
      this._display();
      var btn = document.getElementById('pomoToggle');
      if (btn) { btn.textContent = '开始'; btn.className = 'pomo-btn pomo-btn-start'; }
    },
    _display: function () {
      var el = document.getElementById('pomoTime');
      if (el) { var m = Math.floor(this._remaining / 60); var s = this._remaining % 60; el.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); }
      var se = document.getElementById('pomoSessions');
      if (se) { se.textContent = this._sessions; }
      var ring = this._modal && this._modal.querySelector('.pomo-ring');
      if (ring) { var pct = this._duration > 0 ? (this._remaining / this._duration) * 100 : 100; var bgColor = this._running ? '#FF6B6B' : '#4FC3F7'; ring.style.background = 'conic-gradient(' + bgColor + ' ' + (100 - pct) * 3.6 + 'deg, var(--bg) 0deg)'; }
    }
  };


  var WeatherWidget = {
    _cache: null, _cacheTime: 0,
    init: function () {
      this._injectStyles();
      this._injectWidget();
    },
    _injectStyles: function () {
      if (document.getElementById('weather-styles')) return;
      var style = document.createElement('style');
      style.id = 'weather-styles';
      style.textContent = '.weather-widget{position:fixed;top:80px;right:16px;z-index:998;background:var(--card-bg);border-radius:12px;padding:10px 14px;box-shadow:0 2px 12px rgba(0,0,0,0.08);font-size:0.8rem;display:flex;align-items:center;gap:8px;border:1px solid var(--border);transition:all 0.3s;}.weather-widget:hover{box-shadow:0 4px 20px rgba(0,0,0,0.12);}.weather-icon{font-size:1.5rem;}.weather-temp{font-weight:700;color:var(--text);}.weather-desc{color:var(--text-muted);}@media(max-width:768px){.weather-widget{display:none;}}';
      document.head.appendChild(style);
    },
    _injectWidget: function () {
      var self = this;
      if (document.getElementById('weather-widget')) return;
      var widget = document.createElement('div');
      widget.id = 'weather-widget';
      widget.className = 'weather-widget';
      widget.innerHTML = '<span class="weather-icon">&#9728;</span><span class="weather-temp">--°C</span><span class="weather-desc">加载中...</span>';
      document.body.appendChild(widget);
      this._fetchWeather();
    },
    _fetchWeather: async function () {
      var self = this;
      var now = Date.now();
      if (this._cache && (now - this._cacheTime) < 1800000) { this._render(this._cache); return; }
      try {
        var res = await fetch('/api/weather_proxy.php?city=淮南');
        var data = await res.json();
        if (data.error) { throw new Error(data.error); }
        self._cache = data;
        self._cacheTime = now;
        self._render(data);
      } catch (e) {
        var widget = document.getElementById('weather-widget');
        if (widget) widget.innerHTML = '<span class="weather-icon">&#9925;</span><span class="weather-desc">无法获取天气</span>';
      }
    },
    _render: function (data) {
      var widget = document.getElementById('weather-widget');
      if (!widget) return;
      try {
        var temp = data.temp;
        var desc = data.wd + ' ' + data.ws;
        var icon = '&#9728;';
        var wd = data.wd || '';
        if (wd.indexOf('雨') > -1) icon = '&#127783;';
        else if (wd.indexOf('云') > -1 || wd.indexOf('阴') > -1) icon = '&#9729;';
        else if (wd.indexOf('雪') > -1) icon = '&#10052;';
        widget.innerHTML = '<span class="weather-icon">' + icon + '</span><span class="weather-temp"></span><span class="weather-desc"></span>';
        widget.querySelector('.weather-temp').textContent = temp + '°C';
        widget.querySelector('.weather-desc').textContent = desc;
      } catch (e) {
        widget.innerHTML = '<span class="weather-icon">&#9925;</span><span class="weather-desc">天气数据异常</span>';
      }
    }
  };


  var ClockWidget = {
    init: function () {
      this._injectStyles();
      this._injectWidget();
    },
    _injectStyles: function () {
      if (document.getElementById('clock-styles')) return;
      var style = document.createElement('style');
      style.id = 'clock-styles';
      style.textContent = '.clock-widget{position:fixed;top:80px;left:16px;z-index:998;background:var(--card-bg);border-radius:12px;padding:8px 14px;box-shadow:0 2px 12px rgba(0,0,0,0.08);font-size:0.8rem;border:1px solid var(--border);transition:all 0.3s;}.clock-widget:hover{box-shadow:0 4px 20px rgba(0,0,0,0.12);}.clock-time{font-weight:700;color:var(--text);font-size:1rem;font-family:monospace;}.clock-date{color:var(--text-muted);margin-top:2px;}@media(max-width:768px){.clock-widget{display:none;}}';
      document.head.appendChild(style);
    },
    _injectWidget: function () {
      if (document.getElementById('clock-widget')) return;
      var widget = document.createElement('div');
      widget.id = 'clock-widget';
      widget.className = 'clock-widget';
      widget.innerHTML = '<div class="clock-time">00:00:00</div><div class="clock-date">2024-01-01</div>';
      document.body.appendChild(widget);
      this._tick();
      var self = this;
      setInterval(function () { self._tick(); }, 1000);
    },
    _tick: function () {
      var now = new Date();
      var time = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0') + ':' + String(now.getSeconds()).padStart(2, '0');
      var date = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
      var weekdays = ['日', '一', '二', '三', '四', '五', '六'];
      date += ' 星期' + weekdays[now.getDay()];
      var timeEl = document.querySelector('.clock-time');
      var dateEl = document.querySelector('.clock-date');
      if (timeEl) timeEl.textContent = time;
      if (dateEl) dateEl.textContent = date;
    }
  };


  var Calculator = {
    _expr: '', _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('calc-styles')) return;
      var style = document.createElement('style');
      style.id = 'calc-styles';
      style.textContent = '.calc-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10005;display:flex;align-items:center;justify-content:center;}.calc-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.calc-content{position:relative;background:var(--card-bg);border-radius:16px;padding:20px;width:90%;max-width:300px;animation:slideUp 0.3s ease;}.calc-display{width:100%;padding:12px 16px;border:1px solid var(--border);border-radius:10px;font-size:1.5rem;text-align:right;margin-bottom:12px;background:var(--bg);color:var(--text);font-family:monospace;box-sizing:border-box;}.calc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}.calc-btn{padding:12px;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;transition:all 0.15s;font-family:inherit;}.calc-num{background:var(--bg);color:var(--text);}.calc-num:hover{background:var(--border);}.calc-op{background:var(--primary);color:#fff;}.calc-op:hover{background:var(--primary-hover);}.calc-eq{background:var(--success);color:#fff;}.calc-eq:hover{background:var(--accent-green-dark);}.calc-clear{background:var(--danger);color:#fff;}.calc-clear:hover{background:var(--accent-pink-dark);}.calc-trigger{position:fixed;right:20px;bottom:360px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#43e97b,#38f9d7);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(67,233,123,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.calc-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('calc-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'calc-trigger-btn';
      btn.className = 'calc-trigger';
      btn.title = '计算器';
      btn.innerHTML = svgIcon('grid', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      this._expr = '';
      var btns = ['7','8','9','/','4','5','6','*','1','2','3','-','0','.','=','+'];
      var html = '<div class="calc-modal"><div class="calc-overlay"></div><div class="calc-content"><input class="calc-display" id="calcDisplay" value="" readonly><div class="calc-grid">';
      btns.forEach(function (b) {
        var cls = 'calc-btn ';
        if ('0123456789.'.indexOf(b) > -1) cls += 'calc-num';
        else if (b === '=') cls += 'calc-eq';
        else cls += 'calc-op';
        html += '<button class="' + cls + '" data-key="' + b + '">' + b + '</button>';
      });
      html += '<button class="calc-btn calc-clear" data-key="C">C</button>';
      html += '</div></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.calc-modal');
      this._modal.querySelector('.calc-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelectorAll('.calc-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var key = this.getAttribute('data-key');
          if (key === 'C') { self._expr = ''; }
          else if (key === '=') {
            try { self._expr = String(Function('"use strict";return (' + self._expr + ')')()); } catch (e) { self._expr = 'Error'; }
          } else { self._expr += key; }
          document.getElementById('calcDisplay').value = self._expr;
        });
      });
    }
  };


  var QRCode = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('qr-styles')) return;
      var style = document.createElement('style');
      style.id = 'qr-styles';
      style.textContent = '.qr-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10006;display:flex;align-items:center;justify-content:center;}.qr-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.qr-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:380px;text-align:center;animation:slideUp 0.3s ease;}.qr-input{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:10px;font-size:0.9rem;margin-bottom:12px;box-sizing:border-box;outline:none;font-family:inherit;}.qr-input:focus{border-color:var(--primary);}.qr-btn{padding:10px 24px;background:var(--primary);color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:0.9rem;font-weight:600;}.qr-img{margin:16px auto;display:block;width:200px;max-width:200px;aspect-ratio:1;}.qr-download{display:inline-block;margin-top:8px;padding:8px 16px;background:var(--success);color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:0.8rem;}.qr-trigger{position:fixed;right:20px;bottom:420px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.qr-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('qr-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'qr-trigger-btn';
      btn.className = 'qr-trigger';
      btn.title = '二维码生成';
      btn.innerHTML = svgIcon('grid', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var modal = document.createElement('div');
      modal.className = 'qr-modal';
      modal.innerHTML = '<div class="qr-overlay"></div><div class="qr-content"><h3 style="margin:0 0 12px;color:var(--text);">二维码生成器</h3><input class="qr-input" id="qrInput" placeholder="输入文本或链接..."><button class="qr-btn" id="qrGenBtn">生成二维码</button><div id="qrResult"></div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;" id="qrClose">关闭</button></div>';
      document.body.appendChild(modal);
      this._modal = modal;
      modal.querySelector('.qr-overlay').addEventListener('click', function () { modal.remove(); self._modal = null; });
      modal.querySelector('#qrClose').addEventListener('click', function () { modal.remove(); self._modal = null; });
      modal.querySelector('#qrGenBtn').addEventListener('click', function () {
        var text = document.getElementById('qrInput').value.trim();
        if (!text) { showToast('请输入内容', 'warning'); return; }
        var url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(text);
        document.getElementById('qrResult').innerHTML = '<img class="qr-img" src="' + url + '" alt="QR Code"><br><a class="qr-download" href="' + url + '" download="qrcode.png">下载二维码</a>';
      });
    }
  };


  var ColorPicker = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('cp-styles')) return;
      var style = document.createElement('style');
      style.id = 'cp-styles';
      style.textContent = '.cp-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10007;display:flex;align-items:center;justify-content:center;}.cp-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.cp-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:360px;text-align:center;animation:slideUp 0.3s ease;}.cp-picker{width:100%;height:200px;border:none;border-radius:10px;cursor:pointer;margin-bottom:12px;}.cp-hex{width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;text-align:center;font-size:1rem;font-family:monospace;margin-bottom:8px;box-sizing:border-box;}.cp-preview{width:60px;height:60px;border-radius:50%;margin:0 auto 8px;border:2px solid var(--border);}.cp-saved{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-top:8px;}.cp-swatch{width:28px;height:28px;border-radius:6px;border:1px solid var(--border);cursor:pointer;}.cp-trigger{position:fixed;right:20px;bottom:480px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#f6d365,#fda085);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(246,211,101,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.cp-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('cp-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'cp-trigger-btn';
      btn.className = 'cp-trigger';
      btn.title = '颜色选择器';
      btn.innerHTML = svgIcon('star', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var saved = JSON.parse(localStorage.getItem('wl_colors') || '[]');
      var html = '<div class="cp-modal"><div class="cp-overlay"></div><div class="cp-content"><h3 style="margin:0 0 12px;color:var(--text);">颜色选择器</h3><input type="color" class="cp-picker" id="cpPicker" value="#4A90D9"><input class="cp-hex" id="cpHex" value="#4A90D9" readonly><div class="cp-preview" id="cpPreview" style="background:#4A90D9;"></div><div class="cp-saved" id="cpSaved">';
      saved.forEach(function (c) { html += '<div class="cp-swatch" style="background:' + c + '" data-color="' + c + '"></div>'; });
      html += '</div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;" id="cpClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.cp-modal');
      this._modal.querySelector('.cp-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#cpClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      var picker = this._modal.querySelector('#cpPicker');
      var hex = this._modal.querySelector('#cpHex');
      var preview = this._modal.querySelector('#cpPreview');
      picker.addEventListener('input', function () {
        hex.value = this.value;
        preview.style.background = this.value;
      });
      picker.addEventListener('change', function () {
        var c = this.value;
        if (saved.indexOf(c) === -1) { saved.unshift(c); if (saved.length > 10) saved.pop(); localStorage.setItem('wl_colors', JSON.stringify(saved)); }
        var savedEl = document.getElementById('cpSaved');
        savedEl.innerHTML = '';
        saved.forEach(function (cl) { var sw = document.createElement('div'); sw.className = 'cp-swatch'; sw.style.background = cl; sw.setAttribute('data-color', cl); sw.addEventListener('click', function () { picker.value = cl; hex.value = cl; preview.style.background = cl; }); savedEl.appendChild(sw); });
      });
    }
  };


  var GradientGenerator = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('grad-styles')) return;
      var style = document.createElement('style');
      style.id = 'grad-styles';
      style.textContent = '.grad-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10008;display:flex;align-items:center;justify-content:center;}.grad-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.grad-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:400px;text-align:center;animation:slideUp 0.3s ease;}.grad-preview{width:100%;height:120px;border-radius:12px;margin-bottom:12px;}.grad-row{display:flex;gap:8px;align-items:center;justify-content:center;margin-bottom:8px;}.grad-row input[type="color"]{width:40px;height:40px;border:none;border-radius:8px;cursor:pointer;}.grad-angle{width:80px;padding:6px;border:1px solid var(--border);border-radius:6px;text-align:center;font-size:0.85rem;}.grad-css{width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;font-size:0.8rem;font-family:monospace;background:var(--bg);resize:none;margin-top:8px;box-sizing:border-box;}.grad-presets{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:8px;}.grad-preset{width:40px;height:40px;border-radius:8px;border:1px solid var(--border);cursor:pointer;}.grad-trigger{position:fixed;right:20px;bottom:540px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#a18cd1,#fbc2eb);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(161,140,209,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.grad-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('grad-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'grad-trigger-btn';
      btn.className = 'grad-trigger';
      btn.title = '渐变色生成器';
      btn.innerHTML = svgIcon('star', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var presets = ['#667eea,#764ba2', '#f093fb,#f5576c', '#4facfe,#00f2fe', '#43e97b,#38f9d7', '#fa709a,#fee140', '#a18cd1,#fbc2eb', '#fad0c4,#ffd1ff', '#ffecd2,#fcb69f'];
      var html = '<div class="grad-modal"><div class="grad-overlay"></div><div class="grad-content"><h3 style="margin:0 0 12px;color:var(--text);">渐变色生成器</h3><div class="grad-preview" id="gradPreview" style="background:linear-gradient(135deg,#667eea,#764ba2);"></div><div class="grad-row"><input type="color" id="gradColor1" value="#667eea"><span style="color:var(--text-secondary);">→</span><input type="color" id="gradColor2" value="#764ba2"><span style="color:var(--text-muted);font-size:0.8rem;">角度</span><input class="grad-angle" id="gradAngle" value="135" type="number">°</div><textarea class="grad-css" id="gradCss" rows="2" readonly>background: linear-gradient(135deg, #667eea, #764ba2);</textarea><div class="grad-presets" id="gradPresets">';
      presets.forEach(function (p) { html += '<div class="grad-preset" style="background:linear-gradient(135deg,' + p + ')" data-colors="' + p + '"></div>'; });
      html += '</div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;" id="gradClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.grad-modal');
      this._modal.querySelector('.grad-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#gradClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      var update = function () {
        var c1 = document.getElementById('gradColor1').value;
        var c2 = document.getElementById('gradColor2').value;
        var a = document.getElementById('gradAngle').value || 135;
        var css = 'background: linear-gradient(' + a + 'deg, ' + c1 + ', ' + c2 + ');';
        document.getElementById('gradPreview').style.background = 'linear-gradient(' + a + 'deg, ' + c1 + ', ' + c2 + ')';
        document.getElementById('gradCss').value = css;
      };
      this._modal.querySelector('#gradColor1').addEventListener('input', update);
      this._modal.querySelector('#gradColor2').addEventListener('input', update);
      this._modal.querySelector('#gradAngle').addEventListener('input', update);
      this._modal.querySelectorAll('.grad-preset').forEach(function (p) {
        p.addEventListener('click', function () {
          var colors = this.getAttribute('data-colors').split(',');
          document.getElementById('gradColor1').value = colors[0];
          document.getElementById('gradColor2').value = colors[1];
          update();
        });
      });
    }
  };


  var PasswordGenerator = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('pwdgen-styles')) return;
      var style = document.createElement('style');
      style.id = 'pwdgen-styles';
      style.textContent = '.pwdgen-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10009;display:flex;align-items:center;justify-content:center;}.pwdgen-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.pwdgen-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:380px;text-align:center;animation:slideUp 0.3s ease;}.pwdgen-output{width:100%;padding:12px;border:1px solid var(--border);border-radius:8px;font-size:1.1rem;font-family:monospace;text-align:center;margin-bottom:12px;background:var(--bg);box-sizing:border-box;}.pwdgen-options{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:12px;font-size:0.85rem;}.pwdgen-options label{cursor:pointer;display:flex;align-items:center;gap:4px;color:var(--text-secondary);}.pwdgen-len{width:80px;padding:6px;border:1px solid var(--border);border-radius:6px;text-align:center;}.pwdgen-btn{padding:10px 24px;background:var(--primary);color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:0.9rem;font-weight:600;}.pwdgen-trigger{position:fixed;right:20px;bottom:600px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.pwdgen-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('pwdgen-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'pwdgen-trigger-btn';
      btn.className = 'pwdgen-trigger';
      btn.title = '密码生成器';
      btn.innerHTML = svgIcon('lock', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var html = '<div class="pwdgen-modal"><div class="pwdgen-overlay"></div><div class="pwdgen-content"><h3 style="margin:0 0 12px;color:var(--text);">密码生成器</h3><input class="pwdgen-output" id="pwdgenOutput" readonly value="点击生成"><div class="pwdgen-options"><label><input type="checkbox" id="pwdUpper" checked> 大写</label><label><input type="checkbox" id="pwdLower" checked> 小写</label><label><input type="checkbox" id="pwdNum" checked> 数字</label><label><input type="checkbox" id="pwdSym" checked> 符号</label>长度 <input class="pwdgen-len" id="pwdLen" value="16" type="number" min="4" max="64"></div><button class="pwdgen-btn" id="pwdGenBtn">生成密码</button><button class="pwdgen-btn" id="pwdCopyBtn" style="background:var(--success);margin-left:8px;">复制</button><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="pwdClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.pwdgen-modal');
      this._modal.querySelector('.pwdgen-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#pwdClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#pwdGenBtn').addEventListener('click', function () {
        var upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', lower = 'abcdefghijklmnopqrstuvwxyz', nums = '0123456789', syms = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        var chars = '';
        if (document.getElementById('pwdUpper').checked) chars += upper;
        if (document.getElementById('pwdLower').checked) chars += lower;
        if (document.getElementById('pwdNum').checked) chars += nums;
        if (document.getElementById('pwdSym').checked) chars += syms;
        if (!chars) { showToast('请至少选择一种字符类型', 'warning'); return; }
        var len = parseInt(document.getElementById('pwdLen').value) || 16;
        var pwd = '';
        for (var i = 0; i < len; i++) { pwd += chars[Math.floor(Math.random() * chars.length)]; }
        document.getElementById('pwdgenOutput').value = pwd;
      });
      this._modal.querySelector('#pwdCopyBtn').addEventListener('click', function () {
        var out = document.getElementById('pwdgenOutput');
        out.select();
        document.execCommand('copy');
        showToast('已复制到剪贴板', 'success');
      });
    }
  };


  var UnitConverter = {
    _modal: null,
    _types: { 'length': { name: '长度', units: ['米','千米','厘米','毫米','英里','英尺','英寸'], rates: [1,0.001,100,1000,0.000621371,3.28084,39.3701] }, 'weight': { name: '重量', units: ['千克','克','毫克','吨','磅','盎司'], rates: [1,1000,1000000,0.001,2.20462,35.274] }, 'temp': { name: '温度', units: ['摄氏度','华氏度','开尔文'], rates: [1,1,1] }, 'area': { name: '面积', units: ['平方米','平方千米','公顷','亩','平方英尺'], rates: [1,0.000001,0.0001,0.0015,10.7639] } },
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('unit-styles')) return;
      var style = document.createElement('style');
      style.id = 'unit-styles';
      style.textContent = '.unit-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10010;display:flex;align-items:center;justify-content:center;}.unit-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.unit-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:400px;animation:slideUp 0.3s ease;}.unit-tabs{display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap;}.unit-tab{padding:6px 12px;border:1px solid var(--border);border-radius:20px;background:none;cursor:pointer;font-size:0.8rem;color:var(--text-secondary);transition:all 0.2s;font-family:inherit;}.unit-tab.active{background:var(--primary);color:#fff;border-color:var(--primary);}.unit-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;}.unit-input{flex:1;padding:10px;border:1px solid var(--border);border-radius:8px;text-align:right;font-size:1rem;box-sizing:border-box;}.unit-select{padding:10px;border:1px solid var(--border);border-radius:8px;font-size:0.85rem;max-width:80px;}.unit-eq{text-align:center;color:var(--text-muted);margin:8px 0;font-size:1.2rem;}.unit-trigger{position:fixed;right:20px;bottom:660px;width:50px;height:50px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:999;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.unit-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('unit-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'unit-trigger-btn';
      btn.className = 'unit-trigger';
      btn.title = '单位换算';
      btn.innerHTML = svgIcon('grid', 22);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var html = '<div class="unit-modal"><div class="unit-overlay"></div><div class="unit-content"><h3 style="margin:0 0 12px;color:var(--text);">单位换算</h3><div class="unit-tabs" id="unitTabs">';
      var firstKey = 'length';
      Object.keys(this._types).forEach(function (k) { html += '<button class="unit-tab' + (k === firstKey ? ' active' : '') + '" data-type="' + k + '">' + self._types[k].name + '</button>'; });
      html += '</div><div class="unit-row"><input class="unit-input" id="unitFrom" value="1" type="number"><select class="unit-select" id="unitFromUnit"></select></div><div class="unit-eq">=</div><div class="unit-row"><input class="unit-input" id="unitTo" readonly><select class="unit-select" id="unitToUnit"></select></div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="unitClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.unit-modal');
      this._modal.querySelector('.unit-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#unitClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      var currentType = 'length';
      var updateUnits = function () {
        var type = self._types[currentType];
        var fromSel = document.getElementById('unitFromUnit'), toSel = document.getElementById('unitToUnit');
        fromSel.innerHTML = ''; toSel.innerHTML = '';
        type.units.forEach(function (u, i) {
          fromSel.innerHTML += '<option value="' + i + '">' + u + '</option>';
          toSel.innerHTML += '<option value="' + i + '"' + (i === 1 ? ' selected' : '') + '>' + u + '</option>';
        });
        self._convert();
      };
      var convert = function () {
        if (currentType === 'temp') {
          var v = parseFloat(document.getElementById('unitFrom').value) || 0;
          var f = parseInt(document.getElementById('unitFromUnit').value);
          var t = parseInt(document.getElementById('unitToUnit').value);
          var celsius = v;
          if (f === 1) celsius = (v - 32) * 5 / 9;
          else if (f === 2) celsius = v - 273.15;
          var result = celsius;
          if (t === 1) result = celsius * 9 / 5 + 32;
          else if (t === 2) result = celsius + 273.15;
          document.getElementById('unitTo').value = Math.round(result * 10000) / 10000;
        } else {
          var v2 = parseFloat(document.getElementById('unitFrom').value) || 0;
          var f2 = parseInt(document.getElementById('unitFromUnit').value);
          var t2 = parseInt(document.getElementById('unitToUnit').value);
          var type = self._types[currentType];
          var base = v2 / type.rates[f2];
          var result2 = base * type.rates[t2];
          document.getElementById('unitTo').value = Math.round(result2 * 10000) / 10000;
        }
      };
      this._convert = convert;
      this._modal.querySelectorAll('.unit-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
          self._modal.querySelectorAll('.unit-tab').forEach(function (t) { t.classList.remove('active'); });
          this.classList.add('active');
          currentType = this.getAttribute('data-type');
          updateUnits();
        });
      });
      document.getElementById('unitFrom').addEventListener('input', convert);
      document.getElementById('unitFromUnit').addEventListener('change', convert);
      document.getElementById('unitToUnit').addEventListener('change', convert);
      updateUnits();
    }
  };


  var SnowEffect = {
    _enabled: false, _container: null, _timer: null,
    init: function () {
      this._enabled = localStorage.getItem('wl_snow') === '1';
      if (this._enabled) this.start();
      this._injectToggle();
    },
    _injectToggle: function () {
      var self = this;
      var footer = document.querySelector('.site-footer');
      if (!footer || document.getElementById('snow-toggle')) return;
      var btn = document.createElement('button');
      btn.id = 'snow-toggle';
      btn.style.cssText = 'background:none;border:1px solid var(--border);border-radius:6px;padding:4px 10px;cursor:pointer;font-size:0.75rem;color:var(--text-muted);margin-left:8px;';
      btn.textContent = this._enabled ? '关闭雪花' : '开启雪花';
      btn.addEventListener('click', function () { self.toggle(); });
      footer.appendChild(btn);
    },
    toggle: function () {
      this._enabled = !this._enabled;
      localStorage.setItem('wl_snow', this._enabled ? '1' : '0');
      if (this._enabled) { this.start(); } else { this.stop(); }
      var btn = document.getElementById('snow-toggle');
      if (btn) btn.textContent = this._enabled ? '关闭雪花' : '开启雪花';
    },
    start: function () {
      if (this._container) return;
      this._container = document.createElement('div');
      this._container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999;';
      document.body.appendChild(this._container);
      var self = this;
      this._timer = setInterval(function () {
        if (!self._container) return;
        var snow = document.createElement('div');
        var size = Math.random() * 8 + 4;
        var left = Math.random() * 100;
        snow.style.cssText = 'position:absolute;top:-10px;left:' + left + '%;width:' + size + 'px;height:' + size + 'px;background:rgba(255,255,255,0.8);border-radius:50%;animation:snowFall ' + (Math.random() * 3 + 4) + 's linear forwards;';
        self._container.appendChild(snow);
        setTimeout(function () { if (snow.parentNode) snow.remove(); }, 7000);
      }, 200);
      if (!document.getElementById('snow-keyframes')) {
        var style = document.createElement('style');
        style.id = 'snow-keyframes';
        style.textContent = '@keyframes snowFall{0%{transform:translateY(0) rotate(0deg);opacity:1}100%{transform:translateY(100vh) rotate(360deg);opacity:0}}';
        document.head.appendChild(style);
      }
    },
    stop: function () {
      if (this._timer) { clearInterval(this._timer); this._timer = null; }
      if (this._container) { this._container.remove(); this._container = null; }
    }
  };


  var ConfettiEffect = {
    fire: function (options) {
      options = options || {};
      var count = options.count || 50;
      var colors = options.colors || ['#FF6B6B','#4FC3F7','#FFD54F','#66BB6A','#BA68C8','#FF9800'];
      var container = document.createElement('div');
      container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:99999;';
      document.body.appendChild(container);
      for (var i = 0; i < count; i++) {
        var piece = document.createElement('div');
        var size = Math.random() * 10 + 6;
        var left = Math.random() * 100;
        var color = colors[Math.floor(Math.random() * colors.length)];
        piece.style.cssText = 'position:absolute;top:-20px;left:' + left + '%;width:' + size + 'px;height:' + size + 'px;background:' + color + ';border-radius:2px;animation:confettiFall ' + (Math.random() * 2 + 2) + 's ease-in forwards;animation-delay:' + (Math.random() * 0.5) + 's;';
        if (Math.random() > 0.5) piece.style.borderRadius = '50%';
        container.appendChild(piece);
      }
      if (!document.getElementById('confetti-keyframes')) {
        var style = document.createElement('style');
        style.id = 'confetti-keyframes';
        style.textContent = '@keyframes confettiFall{0%{transform:translateY(0) rotate(0deg);opacity:1}100%{transform:translateY(100vh) rotate(720deg);opacity:0}}';
        document.head.appendChild(style);
      }
      setTimeout(function () { container.remove(); }, 3000);
    }
  };


  var ParticleBackground = {
    _canvas: null, _enabled: false,
    init: function () {
      this._enabled = localStorage.getItem('wl_particles') === '1';
      if (this._enabled) this.start();
    },
    toggle: function () {
      this._enabled = !this._enabled;
      localStorage.setItem('wl_particles', this._enabled ? '1' : '0');
      if (this._enabled) { this.start(); } else { this.stop(); }
    },
    start: function () {
      if (this._canvas) return;
      var canvas = document.createElement('canvas');
      canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:0;';
      document.body.insertBefore(canvas, document.body.firstChild);
      this._canvas = canvas;
      var ctx = canvas.getContext('2d');
      var particles = [];
      var resize = function () { canvas.width = window.innerWidth; canvas.height = window.innerHeight; };
      resize();
      window.addEventListener('resize', resize);
      for (var i = 0; i < 50; i++) {
        particles.push({ x: Math.random() * canvas.width, y: Math.random() * canvas.height, vx: (Math.random() - 0.5) * 0.5, vy: (Math.random() - 0.5) * 0.5, r: Math.random() * 2 + 1 });
      }
      var animate = function () {
        if (!canvas.parentNode) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        // 主题类现在挂在 <html> 上（见 includes/theme_boot.php），每帧读一次即可，无需进内层循环
        var darkMode = document.documentElement.classList.contains('dark-theme');
        particles.forEach(function (p) {
          p.x += p.vx; p.y += p.vy;
          if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
          if (p.y < 0 || p.y > canvas.height) p.vy *= -1;
          ctx.beginPath();
          ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
          ctx.fillStyle = darkMode ? 'rgba(255,255,255,0.15)' : 'rgba(74,144,217,0.15)';
          ctx.fill();
        });
        for (var i = 0; i < particles.length; i++) {
          for (var j = i + 1; j < particles.length; j++) {
            var dx = particles[i].x - particles[j].x;
            var dy = particles[i].y - particles[j].y;
            var dist = Math.sqrt(dx * dx + dy * dy);
            if (dist < 100) {
              ctx.beginPath();
              ctx.moveTo(particles[i].x, particles[i].y);
              ctx.lineTo(particles[j].x, particles[j].y);
              ctx.strokeStyle = darkMode ? 'rgba(255,255,255,' + (0.05 * (1 - dist / 100)) + ')' : 'rgba(74,144,217,' + (0.05 * (1 - dist / 100)) + ')';
              ctx.stroke();
            }
          }
        }
        requestAnimationFrame(animate);
      };
      animate();
    },
    stop: function () {
      if (this._canvas) { this._canvas.remove(); this._canvas = null; }
    }
  };


  // 正文「朗读」（speechSynthesis）连同悬停时自动注入的 🎤 按钮一并移除。
  // 该模块本来也没有被 runModule 注册（init 从未执行），属于会自动注入语音按钮的死代码。
  // 页内「文字转语音」是工具箱里的一项独立工具，由 pages/tools.php 自己实现，不在此列。


  var QuickSearch = {
    _modal: null,
    init: function () {
      this._bindKeyboard();
    },
    _bindKeyboard: function () {
      var self = this;
      document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
          e.preventDefault();
          self.open();
        }
      });
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var modal = document.createElement('div');
      modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:10050;display:flex;align-items:flex-start;justify-content:center;padding-top:15vh;';
      modal.innerHTML = '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.4);" id="qsOverlay"></div><div style="position:relative;background:var(--card-bg);border-radius:16px;width:90%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,0.15);overflow:hidden;animation:slideUp 0.2s ease;"><div style="display:flex;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border);"><span style="margin-right:8px;">' + svgIcon('search', 18) + '</span><input id="qsInput" style="flex:1;border:none;outline:none;font-size:1rem;background:transparent;color:var(--text);" placeholder="搜索帖子、用户、分类..." autofocus><span style="font-size:0.75rem;color:var(--text-muted);background:var(--bg);padding:2px 8px;border-radius:4px;">ESC</span></div><div id="qsResults" style="max-height:300px;overflow-y:auto;padding:8px;"></div><div style="padding:8px 16px;border-top:1px solid var(--border);font-size:0.75rem;color:var(--text-muted);">支持搜索帖子标题、内容和用户名</div></div>';
      document.body.appendChild(modal);
      this._modal = modal;
      modal.querySelector('#qsOverlay').addEventListener('click', function () { modal.remove(); self._modal = null; });
      var input = modal.querySelector('#qsInput');
      var debounceTimer;
      input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = this.value.trim();
        if (!q) { document.getElementById('qsResults').innerHTML = ''; return; }
        debounceTimer = setTimeout(function () { self._search(q); }, 300);
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { modal.remove(); self._modal = null; }
      });
      input.focus();
    },
    _search: async function (q) {
      var container = document.getElementById('qsResults');
      if (!container) return;
      container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);">搜索中...</div>';
      try {
        var res = await fetchAPI('/api/search/quick.php?q=' + encodeURIComponent(q));
        if (!res || !res.success) { container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);">无结果</div>'; return; }
        var posts = res.posts || [];
        if (posts.length === 0) { container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);">未找到相关帖子</div>'; return; }
        var html = '';
        posts.slice(0, 8).forEach(function (p) {
          html += '<a href="/pages/post_detail.php?id=' + p.id + '" style="display:flex;align-items:center;gap:10px;padding:10px 16px;text-decoration:none;color:var(--text);transition:background 0.2s;border-radius:0;" onmouseover="this.style.background=\'var(--bg)\'" onmouseout="this.style.background=\'transparent\'">';
          html += '<span style="flex-shrink:0;">' + svgIcon('message', 16) + '</span>';
          html += '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(p.title) + '</span>';
          html += '<span style="font-size:0.75rem;color:var(--text-muted);flex-shrink:0;">' + escapeHtml(p.category || '') + '</span>';
          html += '</a>';
        });
        container.innerHTML = html;
      } catch (e) { container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);">搜索出错</div>'; }
    }
  };

  var FortuneCookie = {
    _modal: null,
    _fortunes: ['今天会有好事发生！','保持微笑，好运自然来','今天适合学习新知识','贵人就在身边，多留意','今天可能会有意外惊喜','做事要专注，效率会翻倍','今天适合和朋友联系','出门走走，会有新发现','今天适合做计划','放松心情，一切都会好的'],
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('fortune-styles')) return;
      var style = document.createElement('style');
      style.id = 'fortune-styles';
      style.textContent = '.fortune-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10015;display:flex;align-items:center;justify-content:center;}.fortune-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.fortune-content{position:relative;background:linear-gradient(135deg,#fff9c4,#fff3e0);border-radius:20px;padding:32px;width:90%;max-width:360px;text-align:center;animation:slideUp 0.4s ease;box-shadow:0 10px 40px rgba(0,0,0,0.15);}.fortune-icon{font-size:3rem;margin-bottom:12px;}.fortune-text{font-size:1.1rem;color:#5D4037;line-height:1.6;margin:12px 0;}.fortune-btn{padding:10px 28px;background:linear-gradient(135deg,#FFB74D,#FF9800);color:#fff;border:none;border-radius:25px;cursor:pointer;font-size:0.9rem;font-weight:600;font-family:inherit;}.fortune-trigger{position:fixed;right:80px;bottom:320px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#FFB74D,#FF9800);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(255,183,77,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.fortune-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('fortune-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'fortune-trigger-btn';
      btn.className = 'fortune-trigger';
      btn.title = '今日运势';
      btn.innerHTML = svgIcon('star', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var today = new Date().toDateString();
      var saved = JSON.parse(localStorage.getItem('wl_fortune') || '{}');
      var fortune = saved[today] || this._fortunes[Math.floor(Math.random() * this._fortunes.length)];
      if (!saved[today]) { saved[today] = fortune; localStorage.setItem('wl_fortune', JSON.stringify(saved)); }
      var html = '<div class="fortune-modal"><div class="fortune-overlay"></div><div class="fortune-content"><div class="fortune-icon">&#127808;</div><h3 style="margin:0;color:#5D4037;">今日运势</h3><div class="fortune-text">' + fortune + '</div><button class="fortune-btn" id="fortuneCloseBtn">知道了</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.fortune-modal');
      this._modal.querySelector('.fortune-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#fortuneCloseBtn').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
    }
  };

  var LuckyWheel = {
    _modal: null,
    _defaults: ['谢谢参与','1积分','5积分','10积分','神秘礼物','再来一次','20积分','50积分'],
    _prizes: ['谢谢参与','1积分','5积分','10积分','神秘礼物','再来一次','20积分','50积分'],
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('wheel-styles')) return;
      var style = document.createElement('style');
      style.id = 'wheel-styles';
      style.textContent = '.wheel-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10016;display:flex;align-items:center;justify-content:center;}.wheel-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.wheel-content{position:relative;background:var(--card-bg);border-radius:20px;padding:24px;width:90%;max-width:420px;text-align:center;animation:slideUp 0.3s ease;}.wheel-canvas-wrap{position:relative;display:inline-block;margin:0 auto 16px;}.wheel-pointer{position:absolute;top:-12px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:16px solid transparent;border-right:16px solid transparent;border-top:28px solid #E91E63;z-index:2;filter:drop-shadow(0 2px 3px rgba(0,0,0,0.3));}.wheel-canvas-wrap .wheel-pointer::after{content:"";position:absolute;top:-32px;left:-6px;width:12px;height:12px;border-radius:50%;background:#E91E63;}.wheel-spin-btn{padding:10px 28px;background:linear-gradient(135deg,#E91E63,#9C27B0);color:#fff;border:none;border-radius:25px;cursor:pointer;font-size:0.9rem;font-weight:600;font-family:inherit;margin:8px 4px;}.wheel-edit-btn{padding:10px 20px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:25px;cursor:pointer;font-size:0.85rem;font-family:inherit;margin:8px 4px;}.wheel-result{font-size:1.1rem;font-weight:700;color:var(--primary);margin:12px 0;min-height:28px;}.wheel-edit-panel{display:none;margin-top:12px;text-align:left;}.wheel-edit-panel textarea{width:100%;height:100px;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text);font-family:inherit;font-size:0.85rem;resize:vertical;}.wheel-trigger{position:fixed;right:80px;bottom:370px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#E91E63,#9C27B0);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(233,30,99,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.wheel-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('wheel-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'wheel-trigger-btn';
      btn.className = 'wheel-trigger';
      btn.title = '幸运转盘';
      btn.innerHTML = svgIcon('star', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    _drawWheel: function (canvas, rotation) {
      var ctx = canvas.getContext('2d');
      var r = canvas.width / 2;
      var colors = ['#FF6B6B','#4FC3F7','#FFD54F','#66BB6A','#BA68C8','#FF9800','#E91E63','#00BCD4'];
      var slices = this._prizes.length;
      var angle = (2 * Math.PI) / slices;
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      var self = this;
      for (var i = 0; i < slices; i++) {
        var start = i * angle + rotation;
        var end = start + angle;
        ctx.beginPath();
        ctx.moveTo(r, r);
        ctx.arc(r, r, r - 4, start, end);
        ctx.fillStyle = colors[i];
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 2;
        ctx.stroke();
        ctx.save();
        ctx.translate(r, r);
        ctx.rotate(start + angle / 2);
        ctx.fillStyle = '#fff';
        ctx.font = 'bold 11px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(self._prizes[i].length > 5 ? self._prizes[i].substring(0, 4) + '..' : self._prizes[i], r * 0.55, 4);
        ctx.restore();
      }
      ctx.beginPath();
      ctx.arc(r, r, 16, 0, 2 * Math.PI);
      ctx.fillStyle = '#fff';
      ctx.fill();
      ctx.strokeStyle = '#E91E63';
      ctx.lineWidth = 2;
      ctx.stroke();
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }

      try {
        var saved = localStorage.getItem('wl_lucky_wheel_prizes');
        if (saved) {
          var arr = JSON.parse(saved);
          if (Array.isArray(arr) && arr.length >= 2) this._prizes = arr;
        }
      } catch(e) {}
      var html = '<div class="wheel-modal"><div class="wheel-overlay"></div><div class="wheel-content"><h3 style="margin:0 0 16px;color:var(--text);">幸运转盘</h3><div class="wheel-canvas-wrap"><div class="wheel-pointer"></div><canvas class="wheel-canvas" id="wheelCanvas" width="280" height="280"></canvas></div><div class="wheel-result" id="wheelResult">点击转盘试试手气！</div><button class="wheel-spin-btn" id="wheelSpin">开始旋转</button><button class="wheel-edit-btn" id="wheelEditBtn">自定义选项</button><button class="wheel-edit-btn" id="wheelResetBtn" style="margin:8px 4px">恢复默认</button><div class="wheel-edit-panel" id="wheelEditPanel"><textarea id="wheelEditInput" placeholder="每行一个选项">'+self._prizes.join('\n')+'</textarea><button class="wheel-spin-btn" id="wheelEditSave" style="margin-top:8px">保存选项</button></div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="wheelClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.wheel-modal');

      var closeAndReset = function () {
        self._modal.remove();
        self._modal = null;
        self._prizes = self._defaults.slice();
        localStorage.removeItem('wl_lucky_wheel_prizes');
      };
      this._modal.querySelector('.wheel-overlay').addEventListener('click', closeAndReset);
      this._modal.querySelector('#wheelClose').addEventListener('click', closeAndReset);

      this._modal.querySelector('#wheelResetBtn').addEventListener('click', function () {
        self._prizes = self._defaults.slice();
        localStorage.removeItem('wl_lucky_wheel_prizes');
        var input = self._modal.querySelector('#wheelEditInput');
        if (input) input.value = self._defaults.join('\n');
        self._drawWheel(document.getElementById('wheelCanvas'), 0);
        document.getElementById('wheelResult').textContent = '已恢复默认选项';
      });

      var editPanel = this._modal.querySelector('#wheelEditPanel');
      this._modal.querySelector('#wheelEditBtn').addEventListener('click', function () {
        var panel = self._modal.querySelector('#wheelEditPanel');
        panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
      });
      this._modal.querySelector('#wheelEditSave').addEventListener('click', function () {
        var input = self._modal.querySelector('#wheelEditInput').value.trim();
        if (input) {
          self._prizes = input.split('\n').filter(function(s) { return s.trim(); });
          if (self._prizes.length < 2) self._prizes = ['A','B'];

          localStorage.setItem('wl_lucky_wheel_prizes', JSON.stringify(self._prizes));
          self._drawWheel(canvas, 0);
          document.getElementById('wheelResult').textContent = '已更新' + self._prizes.length + '个选项！';
          editPanel.style.display = 'none';
        }
      });

      var canvas = this._modal.querySelector('#wheelCanvas');
      this._drawWheel(canvas, 0);
      var spinning = false;
      this._modal.querySelector('#wheelSpin').addEventListener('click', function () {
        if (spinning) return;
        spinning = true;
        var target = Math.random() * 360 + 1800;
        var start = 0;
        var duration = 3000;
        var startTime = Date.now();
        var anim = setInterval(function () {
          var elapsed = Date.now() - startTime;
          var progress = Math.min(elapsed / duration, 1);
          var eased = 1 - Math.pow(1 - progress, 3);
          var currentRotation = start + target * eased;
          self._drawWheel(canvas, currentRotation * Math.PI / 180);
          if (progress >= 1) {
            clearInterval(anim);
            spinning = false;
            var finalAngle = (currentRotation % 360) * Math.PI / 180;
            var sliceAngle = (2 * Math.PI) / self._prizes.length;
            var idx = Math.floor(((2 * Math.PI - finalAngle + sliceAngle / 2) % (2 * Math.PI)) / sliceAngle) % self._prizes.length;
            document.getElementById('wheelResult').textContent = '恭喜：' + self._prizes[idx] + '！';
            if (self._prizes[idx] !== '谢谢参与') { ConfettiEffect.fire({ count: 30 }); }
          }
        }, 16);
      });
    }
  };

  var ScratchCard = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('scratch-styles')) return;
      var style = document.createElement('style');
      style.id = 'scratch-styles';
      style.textContent = '.scratch-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10017;display:flex;align-items:center;justify-content:center;}.scratch-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.scratch-content{position:relative;background:var(--card-bg);border-radius:20px;padding:24px;width:90%;max-width:360px;text-align:center;animation:slideUp 0.3s ease;}.scratch-canvas{border-radius:12px;cursor:pointer;margin:0 auto;display:block;border:2px solid var(--border);}.scratch-hint{font-size:0.85rem;color:var(--text-muted);margin-top:8px;}.scratch-trigger{position:fixed;right:80px;bottom:420px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#a8e063,#56ab2f);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(168,224,99,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.scratch-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('scratch-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'scratch-trigger-btn';
      btn.className = 'scratch-trigger';
      btn.title = '刮刮卡';
      btn.innerHTML = svgIcon('star', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var prizes = ['恭喜获得10积分！', '恭喜获得5积分！', '谢谢参与～', '恭喜获得20积分！', '神秘礼物一份！'];
      var prize = prizes[Math.floor(Math.random() * prizes.length)];
      var html = '<div class="scratch-modal"><div class="scratch-overlay"></div><div class="scratch-content"><h3 style="margin:0 0 12px;color:var(--text);">刮刮卡</h3><canvas class="scratch-canvas" id="scratchCanvas" width="300" height="200"></canvas><div class="scratch-hint">用手指或鼠标刮开涂层</div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;" id="scratchClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.scratch-modal');
      this._modal.querySelector('.scratch-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#scratchClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      var canvas = this._modal.querySelector('#scratchCanvas');
      var ctx = canvas.getContext('2d');

      ctx.fillStyle = '#5D4037';
      ctx.font = 'bold 20px sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(prize, 150, 90);
      ctx.font = '14px sans-serif';
      ctx.fillText('刮开查看奖品', 150, 120);

      ctx.fillStyle = '#B0BEC5';
      ctx.fillRect(0, 0, 300, 200);
      ctx.fillStyle = '#90A4AE';
      ctx.font = '16px sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('刮开此处', 150, 105);
      var isDrawing = false;
      canvas.addEventListener('mousedown', function (e) { isDrawing = true; self._scratch(e, canvas); });
      canvas.addEventListener('mousemove', function (e) { if (isDrawing) self._scratch(e, canvas); });
      canvas.addEventListener('mouseup', function () { isDrawing = false; self._checkScratch(canvas); });
      canvas.addEventListener('touchstart', function (e) { e.preventDefault(); isDrawing = true; self._scratch(e.touches[0], canvas); });
      canvas.addEventListener('touchmove', function (e) { e.preventDefault(); if (isDrawing) self._scratch(e.touches[0], canvas); });
      canvas.addEventListener('touchend', function () { isDrawing = false; self._checkScratch(canvas); });
    },
    _scratch: function (e, canvas) {
      var rect = canvas.getBoundingClientRect();
      var x = e.clientX - rect.left;
      var y = e.clientY - rect.top;
      var ctx = canvas.getContext('2d');
      ctx.globalCompositeOperation = 'destination-out';
      ctx.beginPath();
      ctx.arc(x, y, 25, 0, Math.PI * 2);
      ctx.fill();
      ctx.globalCompositeOperation = 'source-over';
    },
    _checkScratch: function (canvas) {
      var ctx = canvas.getContext('2d');
      var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      var pixels = imageData.data;
      var transparent = 0;
      for (var i = 3; i < pixels.length; i += 4) { if (pixels[i] === 0) transparent++; }
      if (transparent / (pixels.length / 4) > 0.4) { showToast('刮开成功！', 'success'); }
    }
  };

  var CountdownTimer = {
    _modal: null, _timer: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('cd-styles')) return;
      var style = document.createElement('style');
      style.id = 'cd-styles';
      style.textContent = '.cd-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10018;display:flex;align-items:center;justify-content:center;}.cd-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.cd-content{position:relative;background:var(--card-bg);border-radius:20px;padding:24px;width:90%;max-width:420px;text-align:center;animation:slideUp 0.3s ease;}.cd-presets{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:16px;}.cd-preset{padding:6px 14px;border:1px solid var(--border);border-radius:20px;background:none;cursor:pointer;font-size:0.8rem;transition:all 0.2s;font-family:inherit;color:var(--text-secondary);}.cd-preset:hover,.cd-preset.active{border-color:var(--primary);color:var(--primary);background:var(--primary-light);}.cd-time{font-size:2.6rem;font-weight:700;font-family:"ZCOOL QingKe HuangYou","Noto Sans SC","PingFang SC","Microsoft YaHei",sans-serif;color:var(--text);margin:16px 0;letter-spacing:2px;}.cd-btns{display:flex;gap:10px;justify-content:center;}.cd-btn{padding:10px 24px;border:none;border-radius:10px;cursor:pointer;font-size:0.9rem;font-weight:600;font-family:inherit;}.cd-start{background:var(--success);color:#fff;}.cd-stop{background:var(--danger);color:#fff;}.cd-reset{background:var(--bg);color:var(--text-secondary);}.cd-trigger{position:fixed;right:80px;bottom:470px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.cd-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('cd-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'cd-trigger-btn';
      btn.className = 'cd-trigger';
      btn.title = '倒计时';
      btn.innerHTML = svgIcon('clock', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var html = '<div class="cd-modal"><div class="cd-overlay"></div><div class="cd-content"><h3 style="margin:0 0 12px;color:var(--text);">倒计时</h3><div class="cd-presets"><button class="cd-preset" data-sec="60">1分钟</button><button class="cd-preset" data-sec="180">3分钟</button><button class="cd-preset" data-sec="300">5分钟</button><button class="cd-preset" data-sec="600">10分钟</button><button class="cd-preset" data-sec="1800">30分钟</button><button class="cd-preset" data-sec="3600">1小时</button><button class="cd-preset" data-sec="7200">2小时</button><button class="cd-preset" data-sec="10800">3小时</button></div><div class="cd-time" id="cdDisplay">00:00:00</div><div class="cd-btns"><button class="cd-btn cd-start" id="cdStart">开始</button><button class="cd-btn cd-reset" id="cdReset">重置</button></div><button style="margin-top:12px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="cdClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.cd-modal');
      var remaining = 0, running = false;
      this._modal.querySelector('.cd-overlay').addEventListener('click', function () { if (self._timer) clearInterval(self._timer); self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#cdClose').addEventListener('click', function () { if (self._timer) clearInterval(self._timer); self._modal.remove(); self._modal = null; });
      var updateDisplay = function () {
        var h = Math.floor(remaining / 3600), m = Math.floor((remaining % 3600) / 60), s = remaining % 60;
        document.getElementById('cdDisplay').textContent = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
      };
      this._modal.querySelectorAll('.cd-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
          self._modal.querySelectorAll('.cd-preset').forEach(function (b) { b.classList.remove('active'); });
          this.classList.add('active');
          remaining = parseInt(this.getAttribute('data-sec'));
          updateDisplay();
        });
      });
      this._modal.querySelector('#cdStart').addEventListener('click', function () {
        if (running) { running = false; if (self._timer) clearInterval(self._timer); this.textContent = '开始'; this.className = 'cd-btn cd-start'; return; }
        if (remaining <= 0) { showToast('请先设置时间', 'warning'); return; }
        running = true;
        this.textContent = '暂停';
        this.className = 'cd-btn cd-stop';
        self._timer = setInterval(function () {
          if (remaining <= 0) { clearInterval(self._timer); running = false; showToast('倒计时结束！', 'success'); document.getElementById('cdStart').textContent = '开始'; document.getElementById('cdStart').className = 'cd-btn cd-start'; return; }
          remaining--;
          updateDisplay();
        }, 1000);
      });
      this._modal.querySelector('#cdReset').addEventListener('click', function () {
        if (self._timer) clearInterval(self._timer);
        running = false;
        remaining = 0;
        updateDisplay();
        document.getElementById('cdStart').textContent = '开始';
        document.getElementById('cdStart').className = 'cd-btn cd-start';
      });
    }
  };

  

  var PhotoWall = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('pw-styles')) return;
      var style = document.createElement('style');
      style.id = 'pw-styles';
      style.textContent = '.pw-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10019;display:flex;align-items:center;justify-content:center;}.pw-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.7);}.pw-content{position:relative;background:var(--card-bg);border-radius:20px;width:95%;max-width:700px;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;animation:slideUp 0.3s ease;}.pw-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border);}.pw-close{width:32px;height:32px;border:none;background:var(--bg);border-radius:50%;cursor:pointer;font-size:1.2rem;}.pw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;padding:16px;overflow-y:auto;}.pw-item{position:relative;border-radius:10px;overflow:hidden;cursor:pointer;aspect-ratio:1;}.pw-item img{width:100%;height:100%;object-fit:cover;transition:transform 0.3s;}.pw-item:hover img{transform:scale(1.1);}.pw-lightbox{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10020;background:rgba(0,0,0,0.9);display:flex;align-items:center;justify-content:center;}.pw-lightbox img{max-width:90%;max-height:90%;border-radius:8px;}.pw-lightbox-close{position:absolute;top:20px;right:20px;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.2);border:none;color:#fff;font-size:1.5rem;cursor:pointer;}.pw-trigger{position:fixed;right:80px;bottom:520px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#f093fb,#f5576c);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(240,147,251,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.pw-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('pw-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'pw-trigger-btn';
      btn.className = 'pw-trigger';
      btn.title = '照片墙';
      btn.innerHTML = svgIcon('image', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var html = '<div class="pw-modal"><div class="pw-overlay"></div><div class="pw-content"><div class="pw-header"><h3 style="margin:0;color:var(--text);">照片墙</h3><button class="pw-close">&times;</button></div><div class="pw-grid" id="pwGrid">';
      var images = document.querySelectorAll('.post-images img, .post-detail-content img');
      if (images.length === 0) {
        html += '<div style="text-align:center;padding:40px;color:var(--text-muted);grid-column:1/-1;">暂无照片，浏览帖子中的图片会在这里展示</div>';
      } else {
        images.forEach(function (img) {
          html += '<div class="pw-item"><img src="' + img.src + '" alt="photo" loading="lazy"></div>';
        });
      }
      html += '</div></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.pw-modal');
      this._modal.querySelector('.pw-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('.pw-close').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelectorAll('.pw-item').forEach(function (item) {
        item.addEventListener('click', function () {
          var src = this.querySelector('img').src;
          var lb = document.createElement('div');
          lb.className = 'pw-lightbox';
          lb.innerHTML = '<img src="' + src + '"><button class="pw-lightbox-close">&times;</button>';
          document.body.appendChild(lb);
          lb.addEventListener('click', function (e) { if (e.target === lb || e.target.classList.contains('pw-lightbox-close')) lb.remove(); });
        });
      });
    }
  };

  var QuoteOfTheDay = {
    _quotes: [
      { text: '生活不止眼前的苟且，还有诗和远方。', author: '高晓松' },
      { text: '世界以痛吻我，要我报之以歌。', author: '泰戈尔' },
      { text: '人生如逆旅，我亦是行人。', author: '苏轼' },
      { text: '既然选择了远方，便只顾风雨兼程。', author: '汪国真' },
      { text: '星光不问赶路人，时光不负有心人。', author: '佚名' },
      { text: '宝剑锋从磨砺出，梅花香自苦寒来。', author: '佚名' },
      { text: '你若盛开，蝴蝶自来。', author: '佚名' },
      { text: '不积跬步，无以至千里。', author: '荀子' },
      { text: '学而不思则罔，思而不学则殆。', author: '孔子' },
      { text: '天行健，君子以自强不息。', author: '周易' }
    ],
    init: function () {
      this._inject();
    },
    _render: function (quote) {
      var container = document.querySelector('.site-main');
      if (!container || document.querySelector('.daily-quote')) return;
      var el = document.createElement('div');
      el.className = 'daily-quote';
      el.style.cssText = 'background:linear-gradient(135deg,var(--primary-light),var(--bg));border-radius:12px;padding:14px 20px;margin-bottom:16px;text-align:center;border:1px solid var(--border);';
      el.innerHTML = '<p style="margin:0;font-size:0.95rem;color:var(--text);font-style:italic;">' + escapeHtml(quote.text) + '</p><p style="margin:6px 0 0;font-size:0.8rem;color:var(--text-muted);">—— ' + escapeHtml(quote.author) + '</p>';
      var firstChild = container.firstChild;
      if (firstChild) { container.insertBefore(el, firstChild); } else { container.appendChild(el); }
    },
    _inject: function () {
      var self = this;
      // 每次加载都从一言（Hitokoto）API 获取随机一句，不缓存，保持句子多样
      fetch('https://v1.hitokoto.cn/?encode=json&charset=utf-8')
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.hitokoto) throw new Error('bad response');
          var quote = { text: data.hitokoto, author: data.from ? '『' + data.from + '』' : '佚名' };
          self._render(quote);
        })
        .catch(function () {
          // 接口不可用时回退到本地词库
          var fallback = self._quotes[Math.floor(Math.random() * self._quotes.length)];
          self._render(fallback);
        });
    }
  };

  var AchievementBadge = {
    _achievements: [
      { id: 'first_post', name: '初来乍到', desc: '发布第一篇帖子', icon: 'edit' },
      { id: 'ten_posts', name: '活跃分子', desc: '发布10篇帖子', icon: 'message' },
      { id: 'first_comment', name: '畅所欲言', desc: '发表第一条评论', icon: 'send' },
      { id: 'ten_likes', name: '受欢迎', desc: '获得10个赞', icon: 'heart' },
      { id: 'checkin_7', name: '坚持不懈', desc: '连续签到7天', icon: 'calendar' },
      { id: 'night_owl', name: '夜猫子', desc: '在凌晨发帖', icon: 'moon' }
    ],
    init: function () {
      this._checkAndAward();
    },
    _checkAndAward: function () {
      var earned = JSON.parse(localStorage.getItem('wl_badges') || '[]');
      var self = this;

      if (App.IS_LOGGED_IN && earned.length === 0) {

        setTimeout(function () {
          if (earned.indexOf('welcome') === -1) {
            earned.push('welcome');
            localStorage.setItem('wl_badges', JSON.stringify(earned));
          }
        }, 3000);
      }
    },
    showBadges: function () {
      var earned = JSON.parse(localStorage.getItem('wl_badges') || '[]');
      var html = '<div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;">';
      this._achievements.forEach(function (a) {
        var has = earned.indexOf(a.id) > -1;
        html += '<div style="padding:12px;border-radius:10px;text-align:center;width:90px;' + (has ? 'background:var(--primary-light);border:1px solid var(--primary);' : 'background:var(--bg);border:1px solid var(--border);opacity:0.5;') + '">';
        html += '<div style="font-size:1.5rem;margin-bottom:4px;">' + (has ? svgIcon(a.icon, 20) : '&#128274;') + '</div>';
        html += '<div style="font-size:0.75rem;font-weight:600;color:var(--text);">' + a.name + '</div>';
        html += '</div>';
      });
      html += '</div>';
      return html;
    }
  };

  var NightMode = {
    _enabled: false,
    init: function () {
      this._enabled = localStorage.getItem('wl_nightmode') === '1';
      if (this._enabled) this.enable();
      this._injectToggle();
    },
    _injectToggle: function () {
      var self = this;
      var footer = document.querySelector('.site-footer');
      if (!footer || document.getElementById('nightmode-toggle')) return;
      var btn = document.createElement('button');
      btn.id = 'nightmode-toggle';
      btn.style.cssText = 'background:none;border:1px solid var(--border);border-radius:6px;padding:4px 10px;cursor:pointer;font-size:0.75rem;color:var(--text-muted);margin-left:8px;';
      btn.textContent = this._enabled ? '关闭护眼' : '护眼模式';
      btn.addEventListener('click', function () { self.toggle(); });
      footer.appendChild(btn);
    },
    toggle: function () {
      this._enabled = !this._enabled;
      localStorage.setItem('wl_nightmode', this._enabled ? '1' : '0');
      if (this._enabled) { this.enable(); } else { this.disable(); }
      var btn = document.getElementById('nightmode-toggle');
      if (btn) btn.textContent = this._enabled ? '关闭护眼' : '护眼模式';
    },
    enable: function () {
      if (document.getElementById('nightmode-style')) return;
      var style = document.createElement('style');
      style.id = 'nightmode-style';
      style.textContent = 'html{filter:sepia(0.3) brightness(0.9) contrast(0.9);}';
      document.head.appendChild(style);
    },
    disable: function () {
      var style = document.getElementById('nightmode-style');
      if (style) style.remove();
    }
  };

  var WordCount = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('wc-styles')) return;
      var style = document.createElement('style');
      style.id = 'wc-styles';
      style.textContent = '.wc-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10021;display:flex;align-items:center;justify-content:center;}.wc-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.wc-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:500px;animation:slideUp 0.3s ease;}.wc-textarea{width:100%;height:180px;padding:12px;border:1px solid var(--border);border-radius:10px;font-size:0.9rem;resize:vertical;margin-bottom:12px;box-sizing:border-box;font-family:inherit;}.wc-stats{display:flex;gap:16px;justify-content:center;flex-wrap:wrap;font-size:0.85rem;color:var(--text-secondary);}.wc-stat{text-align:center;}.wc-stat-val{font-size:1.3rem;font-weight:700;color:var(--primary);}.wc-trigger{position:fixed;right:80px;bottom:570px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.wc-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('wc-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'wc-trigger-btn';
      btn.className = 'wc-trigger';
      btn.title = '字数统计';
      btn.innerHTML = svgIcon('edit', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var html = '<div class="wc-modal"><div class="wc-overlay"></div><div class="wc-content"><h3 style="margin:0 0 12px;color:var(--text);">字数统计</h3><textarea class="wc-textarea" id="wcTextarea" placeholder="输入或粘贴文本..."></textarea><div class="wc-stats"><div class="wc-stat"><div class="wc-stat-val" id="wcChars">0</div>字符数</div><div class="wc-stat"><div class="wc-stat-val" id="wcWords">0</div>单词数</div><div class="wc-stat"><div class="wc-stat-val" id="wcLines">0</div>行数</div><div class="wc-stat"><div class="wc-stat-val" id="wcChinese">0</div>中文字数</div></div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="wcClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.wc-modal');
      this._modal.querySelector('.wc-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#wcClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#wcTextarea').addEventListener('input', function () {
        var text = this.value;
        document.getElementById('wcChars').textContent = text.length;
        document.getElementById('wcWords').textContent = text.trim() ? text.trim().split(/\s+/).length : 0;
        document.getElementById('wcLines').textContent = text ? text.split('\n').length : 0;
        document.getElementById('wcChinese').textContent = (text.match(/[\u4e00-\u9fff]/g) || []).length;
      });
    }
  };

  var JSONFormatter = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('json-styles')) return;
      var style = document.createElement('style');
      style.id = 'json-styles';
      style.textContent = '.json-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10022;display:flex;align-items:center;justify-content:center;}.json-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.json-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:550px;animation:slideUp 0.3s ease;}.json-textarea{width:100%;height:150px;padding:10px;border:1px solid var(--border);border-radius:10px;font-size:0.85rem;font-family:monospace;resize:vertical;margin-bottom:8px;box-sizing:border-box;}.json-output{width:100%;height:200px;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:10px;font-size:0.85rem;font-family:monospace;overflow:auto;white-space:pre-wrap;word-break:break-all;}.json-btns{display:flex;gap:8px;margin-bottom:8px;}.json-btn{padding:8px 16px;border:none;border-radius:8px;cursor:pointer;font-size:0.8rem;font-weight:600;font-family:inherit;}.json-format{background:var(--primary);color:#fff;}.json-compress{background:var(--bg);color:var(--text-secondary);}.json-copy{background:var(--success);color:#fff;}.json-trigger{position:fixed;right:80px;bottom:620px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.json-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('json-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'json-trigger-btn';
      btn.className = 'json-trigger';
      btn.title = 'JSON格式化';
      btn.innerHTML = svgIcon('code', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var html = '<div class="json-modal"><div class="json-overlay"></div><div class="json-content"><h3 style="margin:0 0 12px;color:var(--text);">JSON格式化</h3><textarea class="json-textarea" id="jsonInput" placeholder="粘贴JSON数据..."></textarea><div class="json-btns"><button class="json-btn json-format" id="jsonFormat">格式化</button><button class="json-btn json-compress" id="jsonCompress">压缩</button><button class="json-btn json-copy" id="jsonCopy">复制结果</button></div><div class="json-output" id="jsonOutput"></div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="jsonClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.json-modal');
      this._modal.querySelector('.json-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#jsonClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#jsonFormat').addEventListener('click', function () {
        try {
          var obj = JSON.parse(document.getElementById('jsonInput').value);
          document.getElementById('jsonOutput').textContent = JSON.stringify(obj, null, 2);
          document.getElementById('jsonOutput').style.color = 'var(--success)';
        } catch (e) { document.getElementById('jsonOutput').textContent = 'JSON解析错误: ' + e.message; document.getElementById('jsonOutput').style.color = 'var(--danger)'; }
      });
      this._modal.querySelector('#jsonCompress').addEventListener('click', function () {
        try {
          var obj = JSON.parse(document.getElementById('jsonInput').value);
          document.getElementById('jsonOutput').textContent = JSON.stringify(obj);
          document.getElementById('jsonOutput').style.color = 'var(--text)';
        } catch (e) { document.getElementById('jsonOutput').textContent = 'JSON解析错误: ' + e.message; document.getElementById('jsonOutput').style.color = 'var(--danger)'; }
      });
      this._modal.querySelector('#jsonCopy').addEventListener('click', function () {
        var text = document.getElementById('jsonOutput').textContent;
        if (!text) return;
        navigator.clipboard.writeText(text).then(function () { showToast('已复制', 'success'); }).catch(function () { showToast('复制失败', 'error'); });
      });
    }
  };

  var Base64Tool = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('b64-styles')) return;
      var style = document.createElement('style');
      style.id = 'b64-styles';
      style.textContent = '.b64-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10023;display:flex;align-items:center;justify-content:center;}.b64-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.b64-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:500px;animation:slideUp 0.3s ease;}.b64-textarea{width:100%;height:120px;padding:10px;border:1px solid var(--border);border-radius:10px;font-size:0.85rem;resize:vertical;margin-bottom:8px;box-sizing:border-box;font-family:inherit;}.b64-btns{display:flex;gap:8px;margin-bottom:8px;}.b64-btn{padding:8px 16px;border:none;border-radius:8px;cursor:pointer;font-size:0.8rem;font-weight:600;font-family:inherit;}.b64-encode{background:var(--primary);color:#fff;}.b64-decode{background:var(--success);color:#fff;}.b64-trigger{position:fixed;right:80px;bottom:670px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.b64-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('b64-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'b64-trigger-btn';
      btn.className = 'b64-trigger';
      btn.title = 'Base64编解码';
      btn.innerHTML = svgIcon('code', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var html = '<div class="b64-modal"><div class="b64-overlay"></div><div class="b64-content"><h3 style="margin:0 0 12px;color:var(--text);">Base64编解码</h3><textarea class="b64-textarea" id="b64Input" placeholder="输入文本..."></textarea><div class="b64-btns"><button class="b64-btn b64-encode" id="b64Encode">编码</button><button class="b64-btn b64-decode" id="b64Decode">解码</button></div><textarea class="b64-textarea" id="b64Output" placeholder="结果..." readonly></textarea><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="b64Close">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.b64-modal');
      this._modal.querySelector('.b64-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#b64Close').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#b64Encode').addEventListener('click', function () {
        try { document.getElementById('b64Output').value = btoa(unescape(encodeURIComponent(document.getElementById('b64Input').value))); } catch (e) { document.getElementById('b64Output').value = '编码失败'; }
      });
      this._modal.querySelector('#b64Decode').addEventListener('click', function () {
        try { document.getElementById('b64Output').value = decodeURIComponent(escape(atob(document.getElementById('b64Input').value))); } catch (e) { document.getElementById('b64Output').value = '解码失败，请检查输入'; }
      });
    }
  };

  var IPLookup = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('ip-styles')) return;
      var style = document.createElement('style');
      style.id = 'ip-styles';
      style.textContent = '.ip-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10024;display:flex;align-items:center;justify-content:center;}.ip-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.ip-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:400px;text-align:center;animation:slideUp 0.3s ease;}.ip-input{width:100%;padding:10px;border:1px solid var(--border);border-radius:10px;font-size:0.9rem;margin-bottom:10px;box-sizing:border-box;text-align:center;}.ip-result{background:var(--bg);border-radius:10px;padding:16px;margin-top:12px;text-align:left;font-size:0.85rem;}.ip-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border-light);}.ip-label{color:var(--text-muted);}.ip-val{color:var(--text);font-weight:500;}.ip-btn{padding:10px 24px;background:var(--primary);color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:0.9rem;font-weight:600;}.ip-trigger{position:fixed;right:80px;bottom:720px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.ip-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('ip-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'ip-trigger-btn';
      btn.className = 'ip-trigger';
      btn.title = 'IP查询';
      btn.innerHTML = svgIcon('globe', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var html = '<div class="ip-modal"><div class="ip-overlay"></div><div class="ip-content"><h3 style="margin:0 0 12px;color:var(--text);">IP信息查询</h3><input class="ip-input" id="ipInput" placeholder="输入IP地址..."><button class="ip-btn" id="ipLookup">查询</button><div class="ip-result" id="ipResult" style="display:none;"></div><button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="ipClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.ip-modal');
      this._modal.querySelector('.ip-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#ipClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#ipLookup').addEventListener('click', async function () {
        var ip = document.getElementById('ipInput').value.trim();
        if (!ip) { showToast('请输入IP地址', 'warning'); return; }
        var resultEl = document.getElementById('ipResult');
        resultEl.style.display = 'block';
        resultEl.innerHTML = '<div style="text-align:center;color:var(--text-muted);">查询中...</div>';
        try {
          var data = null;
          try {
            var res = await fetch('https://api.ipapi.is/?q=' + ip);
            if (res.ok) data = await res.json();
          } catch (e1) {}
          if (!data || data.error) {
            try {
              var res2 = await fetch('https://ipapi.co/' + ip + '/json/');
              if (res2.ok) data = await res2.json();
            } catch (e2) {}
          }
          if (data && !data.error) {
            resultEl.innerHTML = '<div class="ip-row"><span class="ip-label">IP</span><span class="ip-val">' + (data.ip || ip) + '</span></div><div class="ip-row"><span class="ip-label">国家</span><span class="ip-val">' + (data.country || data.country_name || '') + '</span></div><div class="ip-row"><span class="ip-label">城市</span><span class="ip-val">' + (data.city || '') + '</span></div><div class="ip-row"><span class="ip-label">ISP</span><span class="ip-val">' + (data.isp || data.org || data.asn || '') + '</span></div>';
          } else {
            resultEl.innerHTML = '<div style="text-align:center;color:var(--danger);">查询失败，请检查IP地址</div>';
          }
        } catch (e) { resultEl.innerHTML = '<div style="text-align:center;color:var(--danger);">查询失败，请检查网络连接</div>'; }
      });
    }
  };

  var ScreenCapture = {
    init: function () {
      this._injectTrigger();
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('sc-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'sc-trigger-btn';
      btn.style.cssText = 'position:fixed;right:80px;bottom:770px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;';
      btn.title = '网页截图';
      btn.innerHTML = svgIcon('image', 18);
      btn.addEventListener('click', function () { self.capture(); });
      document.body.appendChild(btn);
    },
    capture: function () {
      try {
        var canvas = document.createElement('canvas');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        showToast('正在截图...', 'info');

        var link = document.createElement('a');
        link.download = 'screenshot_' + Date.now() + '.png';
        link.textContent = '截图已生成，请使用浏览器截图工具(Ctrl+Shift+S)';
        showToast('使用浏览器截图工具: Ctrl+Shift+S (Edge) 或开发者工具', 'info');
      } catch (e) { showToast('截图失败', 'error'); }
    }
  };

  var ReadingMode = {
    _enabled: false, _overlay: null,
    init: function () {
      this._bindKeyboard();
    },
    _bindKeyboard: function () {
      var self = this;
      document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'R') {
          e.preventDefault();
          self.toggle();
        }
      });
    },
    toggle: function () {
      if (this._enabled) { this.disable(); } else { this.enable(); }
    },
    enable: function () {
      this._enabled = true;
      if (!this._overlay) {
        this._overlay = document.createElement('div');
        this._overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:9998;background:var(--bg);padding:40px;overflow-y:auto;';
        this._overlay.innerHTML = '<button style="position:fixed;top:20px;right:20px;z-index:1;width:40px;height:40px;border-radius:50%;background:var(--card-bg);border:1px solid var(--border);cursor:pointer;font-size:1.2rem;color:var(--text-secondary);" id="rmClose">&times;</button><div id="rmContent" style="max-width:700px;margin:0 auto;font-size:1.1rem;line-height:2;color:var(--text);"></div>';
        document.body.appendChild(this._overlay);
        var self = this;
        this._overlay.querySelector('#rmClose').addEventListener('click', function () { self.disable(); });
      }
      var content = document.querySelector('.post-detail-content, .post-content, article');
      var rmContent = document.getElementById('rmContent');
      if (content && rmContent) { rmContent.innerHTML = content.innerHTML; }
      this._overlay.style.display = 'block';
      document.body.style.overflow = 'hidden';
    },
    disable: function () {
      this._enabled = false;
      if (this._overlay) this._overlay.style.display = 'none';
      document.body.style.overflow = '';
    }
  };

  var SiteMap = {
    _modal: null,
    init: function () {
      this._injectStyles();
      this._injectTrigger();
    },
    _injectStyles: function () {
      if (document.getElementById('sitemap-styles')) return;
      var style = document.createElement('style');
      style.id = 'sitemap-styles';
      style.textContent = '.sitemap-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:10025;display:flex;align-items:center;justify-content:center;}.sitemap-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.5);}.sitemap-content{position:relative;background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:500px;max-height:75vh;overflow-y:auto;animation:slideUp 0.3s ease;}.sitemap-section{margin-bottom:16px;}.sitemap-section-title{font-size:0.9rem;font-weight:700;color:var(--primary);margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid var(--primary-light);}.sitemap-links{display:flex;flex-wrap:wrap;gap:8px;}.sitemap-link{display:inline-block;padding:6px 14px;background:var(--bg);border-radius:20px;font-size:0.8rem;color:var(--text);text-decoration:none;transition:all 0.2s;}.sitemap-link:hover{background:var(--primary-light);color:var(--primary);}.sitemap-trigger{position:fixed;right:80px;bottom:820px;width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);border:none;cursor:pointer;z-index:998;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:all 0.3s;display:flex;align-items:center;justify-content:center;}.sitemap-trigger:hover{transform:scale(1.1);}';
      document.head.appendChild(style);
    },
    _injectTrigger: function () {
      var self = this;
      if (document.getElementById('sitemap-trigger-btn')) return;
      var btn = document.createElement('button');
      btn.id = 'sitemap-trigger-btn';
      btn.className = 'sitemap-trigger';
      btn.title = '站点地图';
      btn.innerHTML = svgIcon('compass', 18);
      btn.addEventListener('click', function () { self.open(); });
      document.body.appendChild(btn);
    },
    open: function () {
      var self = this;
      if (this._modal) { this._modal.remove(); }
      var pages = [
        { title: '主要页面', links: [
          { name: '首页', url: '/index.php' }, { name: '个人中心', url: '/pages/user_center.php' },
          { name: '发帖', url: '/pages/post.php' }, { name: '登录', url: '/pages/login.php' },
          { name: '注册', url: '/pages/register.php' }
        ]},
        { title: '工具', links: [
          { name: '忘记密码', url: '/pages/forgot_password.php' }, { name: '双因素认证', url: '/pages/2fa_setup.php' }
        ]},
        { title: '管理后台', links: [
          { name: '管理面板', url: '/admin/index.php' }, { name: '帖子管理', url: '/admin/posts.php' },
          { name: '用户管理', url: '/admin/users.php' }, { name: '公告管理', url: '/admin/announcements.php' },
          { name: '站点设置', url: '/admin/settings.php' }
        ]}
      ];
      var html = '<div class="sitemap-modal"><div class="sitemap-overlay"></div><div class="sitemap-content"><h3 style="margin:0 0 16px;color:var(--text);">站点地图</h3>';
      pages.forEach(function (section) {
        html += '<div class="sitemap-section"><div class="sitemap-section-title">' + section.title + '</div><div class="sitemap-links">';
        section.links.forEach(function (link) {
          html += '<a class="sitemap-link" href="' + link.url + '">' + link.name + '</a>';
        });
        html += '</div></div>';
      });
      html += '<button style="margin-top:10px;background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.8rem;display:block;width:100%;" id="sitemapClose">关闭</button></div></div>';
      var modal = document.createElement('div');
      modal.innerHTML = html;
      document.body.appendChild(modal.firstElementChild);
      this._modal = document.querySelector('.sitemap-modal');
      this._modal.querySelector('.sitemap-overlay').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
      this._modal.querySelector('#sitemapClose').addEventListener('click', function () { self._modal.remove(); self._modal = null; });
    }
  };

  var BubbleEffect = {
    init: function () {
      this._bind();
    },
    _bind: function () {
      var self = this;
      document.addEventListener('click', function (e) {
        if (e.target.closest('button, a, input, textarea, select')) return;
        if (Math.random() > 0.7) {
          self._createBubble(e.clientX, e.clientY);
        }
      });
    },
    _createBubble: function (x, y) {
      var bubble = document.createElement('div');
      var size = Math.random() * 8 + 4;
      var colors = ['#4FC3F7', '#FFB74D', '#66BB6A', '#BA68C8', '#FF6B6B'];
      var color = colors[Math.floor(Math.random() * colors.length)];
      bubble.style.cssText = 'position:fixed;left:' + (x - size/2) + 'px;top:' + (y - size/2) + 'px;width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:' + color + ';pointer-events:none;z-index:99999;animation:bubbleUp 1s ease-out forwards;';
      document.body.appendChild(bubble);
      setTimeout(function () { if (bubble.parentNode) bubble.remove(); }, 1000);
      if (!document.getElementById('bubble-keyframes')) {
        var style = document.createElement('style');
        style.id = 'bubble-keyframes';
        style.textContent = '@keyframes bubbleUp{0%{opacity:0.8;transform:translateY(0) scale(1)}100%{opacity:0;transform:translateY(-40px) scale(2)}}';
        document.head.appendChild(style);
      }
    }
  };

  var AnimatedBackground = {
    _enabled: false,
    init: function () {
      this._enabled = localStorage.getItem('wl_animated_bg') === '1';
      if (this._enabled) this.enable();
    },
    toggle: function () {
      this._enabled = !this._enabled;
      localStorage.setItem('wl_animated_bg', this._enabled ? '1' : '0');
      if (this._enabled) { this.enable(); } else { this.disable(); }
    },
    enable: function () {
      if (document.getElementById('animated-bg-style')) return;
      var style = document.createElement('style');
      style.id = 'animated-bg-style';
      style.textContent = 'body::before{content:"";position:fixed;top:0;left:0;right:0;bottom:0;z-index:-1;background:linear-gradient(270deg,#ee7752,#e73c7e,#23a6d5,#23d5ab);background-size:400% 400%;animation:gradientShift 15s ease infinite;opacity:0.08;}@keyframes gradientShift{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}';
      document.head.appendChild(style);
    },
    disable: function () {
      var style = document.getElementById('animated-bg-style');
      if (style) style.remove();
    }
  };

  // 话题 # 与 @提及 的解析已统一收敛到 RichText 模块（见上方定义），
  // 旧的 HashtagSystem / UserMention 会改坏已有标签且指向死链，已删除。

  var PageLoadProgress = {
    init: function () {
      this._injectStyles();
      this._bar = document.createElement('div');
      this._bar.id = 'pageLoadProgress';
      this._bar.style.cssText = 'position:fixed;top:0;left:0;height:3px;width:0;background:linear-gradient(90deg,var(--primary),var(--accent-green));z-index:9999;transition:width 0.2s ease;pointer-events:none;';
      document.body.appendChild(this._bar);
      var self = this;
      var origPush = history.pushState;
      var origReplace = history.replaceState;
      history.pushState = function () { self.start(); origPush.apply(this, arguments); };
      history.replaceState = function () { self.start(); origReplace.apply(this, arguments); };
      window.addEventListener('beforeunload', function () { self.start(); });
      window.addEventListener('load', function () { self.done(); });
      window.addEventListener('popstate', function () { self.start(); setTimeout(function () { self.done(); }, 600); });
      document.addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (a && a.href && a.href.indexOf(window.location.origin) === 0 && !a.target && a.getAttribute('href') !== '#') {
          self.start();
        }
      });
    },
    _injectStyles: function () {
      if (document.getElementById('pglp-styles')) return;
      var style = document.createElement('style');
      style.id = 'pglp-styles';
      style.textContent = '#pageLoadProgress.done{width:100%!important;opacity:0;transition:width 0.3s ease,opacity 0.3s ease 0.3s}';
      document.head.appendChild(style);
    },
    start: function () { if (this._bar) { this._bar.style.width = '60%'; this._bar.classList.remove('done'); } },
    done: function () { if (this._bar) { this._bar.style.width = '100%'; this._bar.classList.add('done'); } }
  };

  var AutoFocusInput = {
    // 两条护栏，都是实测踩出来的：
    // 1) **不要碰 textarea**。原先的选择器把 textarea 也算进去，于是帖子详情页
    //    的第一个可见输入框就是评论框 —— 全新打开详情页会被 focus 顺带滚到 641px，
    //    帖子开头直接看不到，移动端还会立刻弹出键盘。（实测抓到的调用栈就在这一行。）
    //    textarea 一律是「长文本输入」，用户刚进页面时绝不该被强行聚焦。
    // 2) **preventScroll**。即使聚焦的是页面下部的输入框（工具页 / 用户中心），
    //    也绝不能让它把视口拽走 —— 聚焦和滚动是两件事，只做前者。
    _selector: 'input[type="text"]:not([readonly]):not([disabled]),' +
      'input[type="search"]:not([readonly]):not([disabled]),' +
      'input[type="password"]:not([readonly]):not([disabled])',
    init: function () {
      var inputs = document.querySelectorAll(this._selector);
      if (inputs.length === 0) return;
      for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].offsetParent !== null) { this._focus(inputs[i]); break; }
      }
    },
    _focus: function (el) {
      try {
        el.focus({ preventScroll: true });
      } catch (e) {
        // 极老内核不认 options 参数，退化为普通 focus（宁可聚焦也不报错中断模块）
        try { el.focus(); } catch (e2) {}
      }
    }
  };

  var PasteImageUpload = {
    init: function () {
      var self = this;
      this._injectStyles();
      document.addEventListener('paste', function (e) {
        var ta = e.target.closest('textarea');
        if (!ta) return;
        var items = e.clipboardData && e.clipboardData.items;
        if (!items) return;
        for (var i = 0; i < items.length; i++) {
          if (items[i].type.indexOf('image') === 0) {
            e.preventDefault();
            self._handlePaste(ta, items[i].getAsFile());
            return;
          }
        }
      });
    },
    _injectStyles: function () {
      if (document.getElementById('piu-styles')) return;
      var style = document.createElement('style');
      style.id = 'piu-styles';
      style.textContent = '.paste-img-preview{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;margin:4px 0;background:var(--primary-light);border-radius:6px;font-size:13px;color:var(--primary);}.paste-img-preview img{width:24px;height:24px;object-fit:cover;border-radius:4px;}.paste-img-preview .remove-paste{background:none;border:none;color:var(--danger);cursor:pointer;font-size:16px;padding:0 4px;}';
      document.head.appendChild(style);
    },
    _handlePaste: function (ta, file) {
      var reader = new FileReader();
      reader.onload = function (e) {
        var preview = document.createElement('div');
        preview.className = 'paste-img-preview';
        preview.innerHTML = '<img src="' + e.target.result + '" alt=""><span>图片已粘贴</span><button class="remove-paste" title="移除">&times;</button>';
        preview.querySelector('.remove-paste').addEventListener('click', function () { preview.remove(); });
        ta.parentNode.insertBefore(preview, ta.nextSibling);
        var fileInput = document.querySelector('input[type="file"][accept*="image"]');
        if (fileInput) {
          var dt = new DataTransfer();
          dt.items.add(file);
          fileInput.files = dt.files;
          fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        setTimeout(function () { if (preview.parentNode) preview.remove(); }, 5000);
      };
      reader.readAsDataURL(file);
    }
  };

  var FloatingSelectionToolbar = {
    init: function () {
      this._injectStyles();
      var self = this;
      this._toolbar = document.createElement('div');
      this._toolbar.className = 'floating-sel-toolbar';
      this._toolbar.style.display = 'none';
      this._toolbar.innerHTML = '<button data-action="copy" title="复制">' + svgIcon('copy', 16) + '</button><button data-action="share" title="分享">' + svgIcon('share', 16) + '</button><button data-action="search" title="搜索">' + svgIcon('search', 16) + '</button>';
      document.body.appendChild(this._toolbar);
      document.addEventListener('mouseup', function (e) {
        setTimeout(function () {
          var sel = window.getSelection();
          var text = (sel || '').toString().trim();
          if (!text || text.length < 2) { self._toolbar.style.display = 'none'; return; }
          var range = sel.getRangeAt(0);
          var rect = range.getBoundingClientRect();
          var top = rect.top + window.scrollY - 45;
          var left = Math.min(rect.left + rect.width / 2 - 60, window.innerWidth - 140);
          if (top < 0) top = rect.bottom + window.scrollY + 5;
          self._toolbar.style.display = 'flex';
          self._toolbar.style.top = top + 'px';
          self._toolbar.style.left = Math.max(5, left) + 'px';
          self._toolbar.setAttribute('data-text', text);
        }, 10);
      });
      this._toolbar.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var text = self._toolbar.getAttribute('data-text');
        if (btn.dataset.action === 'copy') { navigator.clipboard.writeText(text).then(function () { showToast('已复制', 'success'); }); }
        else if (btn.dataset.action === 'share') { if (navigator.share) { navigator.share({ text: text }); } else { navigator.clipboard.writeText(text); showToast('已复制', 'success'); } }
        else if (btn.dataset.action === 'search') { window.open('https://www.baidu.com/s?wd=' + encodeURIComponent(text), '_blank'); }
        self._toolbar.style.display = 'none';
      });
      document.addEventListener('mousedown', function (e) { if (!self._toolbar.contains(e.target)) self._toolbar.style.display = 'none'; });
    },
    _injectStyles: function () {
      if (document.getElementById('fst-styles')) return;
      var style = document.createElement('style');
      style.id = 'fst-styles';
      style.textContent = '.floating-sel-toolbar{position:absolute;z-index:999;display:flex;gap:4px;padding:6px 8px;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-md);animation:selFadeIn 0.15s ease;}.floating-sel-toolbar button{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border:none;background:var(--bg);border-radius:6px;cursor:pointer;transition:all 0.15s;}.floating-sel-toolbar button:hover{background:var(--primary-light);color:var(--primary);}@keyframes selFadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}';
      document.head.appendChild(style);
    }
  };

  var NotificationBadge = {
    _count: 0,
    init: function () {
      this._injectStyles();
      var menuBtn = document.getElementById('userMenuBtn');
      if (!menuBtn) return;
      this._badge = document.createElement('span');
      this._badge.className = 'notif-badge';
      this._badge.style.display = 'none';
      menuBtn.style.position = 'relative';
      menuBtn.appendChild(this._badge);
      this._fetch();
      var self = this;
      setInterval(function () {
        if (document.hidden) return; // 后台标签不再轮询未读数，省流量
        self._fetch();
      }, 60000);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) self._fetch(); // 回前台立刻补一次，红点不滞后
      });
    },
    _injectStyles: function () {
      if (document.getElementById('nb-styles')) return;
      var style = document.createElement('style');
      style.id = 'nb-styles';
      style.textContent = '.notif-badge{position:absolute;top:-3px;right:-3px;min-width:18px;height:18px;border-radius:9px;background:var(--danger);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 5px;line-height:1;animation:badgePop 0.3s ease;}@keyframes badgePop{0%{transform:scale(0)}50%{transform:scale(1.3)}100%{transform:scale(1)}}';
      document.head.appendChild(style);
    },
    _fetch: function () {
      var self = this;
      if (typeof fetchAPI !== 'function') return;
      fetchAPI('/api/notifications/unread_count.php')
        .then(function (r) { return r && r.json ? r.json() : r; })
        .then(function (data) {
          var count = (data && data.count) || 0;
          self._count = count;
          if (self._badge) {
            self._badge.textContent = count > 99 ? '99+' : count;
            self._badge.style.display = count > 0 ? 'flex' : 'none';
          }
        })
        .catch(function () {});
    },
    update: function (count) {
      this._count = count || 0;
      if (this._badge) {
        this._badge.textContent = this._count > 99 ? '99+' : this._count;
        this._badge.style.display = this._count > 0 ? 'flex' : 'none';
      }
    }
  };

  // QuickActionPanel（右下角 ＋ 展开「发帖/搜索/回顶/刷新」）已移除：
  // 与 polish.js 的统一浮动按钮功能完全重叠，保留一个即可。


  var CommentQuoteReply = {
    init: function () {
      this._injectStyles();
      var self = this;
      document.addEventListener('click', function (e) {
        var comment = e.target.closest('.comment-item');
        if (!comment) return;
        if (e.target.closest('button, a, .comment-floor, .emoji-btn')) return;
        var body = comment.querySelector('.comment-body');
        if (!body) return;
        var author = comment.querySelector('.comment-author');
        var authorName = author ? author.textContent.trim() : '用户';
        var text = body.textContent.trim().substring(0, 100);
        var ta = document.querySelector('textarea[name="comment"], #commentInput, textarea[placeholder*="评论"]');
        if (ta) {
          ta.value = '> @' + authorName + ': ' + text + '\n\n';
          ta.focus();
          ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
          showToast('已引用评论', 'info');
        }
      });
    },
    _injectStyles: function () {
      if (document.getElementById('cqr-styles')) return;
      var style = document.createElement('style');
      style.id = 'cqr-styles';
      style.textContent = '.comment-item{cursor:pointer;transition:background 0.15s;}.comment-item:hover{background:var(--bg-secondary);}';
      document.head.appendChild(style);
    }
  };

  var FormDraftSave = {
    _key: 'wl_form_drafts',
    init: function () {
      var self = this;
      document.querySelectorAll('form').forEach(function (form) {
        var formId = form.id || form.action || window.location.pathname;
        formId = formId.replace(/[^a-zA-Z0-9]/g, '_');
        var saved = self._get(formId);
        if (saved && Object.keys(saved).length > 0) {
          var banner = document.createElement('div');
          banner.className = 'draft-banner';
          banner.innerHTML = '检测到未提交的草稿，<a href="#" class="draft-restore">点击恢复</a> 或 <a href="#" class="draft-discard">忽略</a>';
          form.parentNode.insertBefore(banner, form);
          banner.querySelector('.draft-restore').addEventListener('click', function (e) {
            e.preventDefault();
            Object.keys(saved).forEach(function (name) {
              var el = form.querySelector('[name="' + name + '"]');
              if (el && el.type !== 'password' && !el.value) el.value = saved[name];
            });
            banner.remove();
            showToast('草稿已恢复', 'success');
          });
          banner.querySelector('.draft-discard').addEventListener('click', function (e) {
            e.preventDefault();
            self._remove(formId);
            banner.remove();
          });
        }
        form.addEventListener('input', debounce(function () {
          var data = {};
          form.querySelectorAll('input:not([type="password"]):not([type="file"]), textarea, select').forEach(function (el) {
            if (el.name && el.value) data[el.name] = el.value;
          });
          if (Object.keys(data).length > 0) { self._set(formId, data); } else { self._remove(formId); }
        }, 1500));
        form.addEventListener('submit', function () { setTimeout(function () { self._remove(formId); }, 500); });
      });
      this._injectStyles();
    },
    _injectStyles: function () {
      if (document.getElementById('fds-styles')) return;
      var style = document.createElement('style');
      style.id = 'fds-styles';
      style.textContent = '.draft-banner{padding:10px 14px;margin-bottom:12px;background:var(--warning-light);border:1px solid var(--warning);border-radius:8px;font-size:13px;color:var(--text);}.draft-banner a{color:var(--primary);font-weight:600;text-decoration:none;margin:0 4px;}.draft-banner a:hover{text-decoration:underline;}';
      document.head.appendChild(style);
    },
    _get: function (formId) { try { var d = JSON.parse(localStorage.getItem(this._key + '_' + formId)); return d && d._ts && Date.now() - d._ts < 86400000 ? d : null; } catch (e) { return null; } },
    _set: function (formId, data) { data._ts = Date.now(); try { localStorage.setItem(this._key + '_' + formId, JSON.stringify(data)); } catch (e) {} },
    _remove: function (formId) { localStorage.removeItem(this._key + '_' + formId); }
  };

  var TableRowHighlight = {
    init: function () {
      this._injectStyles();
      var self = this;
      document.querySelectorAll('table tbody tr').forEach(function (row) {
        row.addEventListener('mouseenter', function () { row.classList.add('tr-highlight'); });
        row.addEventListener('mouseleave', function () { row.classList.remove('tr-highlight'); });
      });
      var observer = new MutationObserver(function () {
        document.querySelectorAll('table tbody tr:not(.tr-bound)').forEach(function (row) {
          row.classList.add('tr-bound');
          row.addEventListener('mouseenter', function () { row.classList.add('tr-highlight'); });
          row.addEventListener('mouseleave', function () { row.classList.remove('tr-highlight'); });
        });
      });
      document.querySelectorAll('table').forEach(function (t) { observer.observe(t, { childList: true, subtree: true }); });
    },
    _injectStyles: function () {
      if (document.getElementById('trh-styles')) return;
      var style = document.createElement('style');
      style.id = 'trh-styles';
      style.textContent = '.tr-highlight{background:var(--primary-light)!important;transition:background 0.2s ease;}table tbody tr{cursor:default;transition:background 0.2s ease;}';
      document.head.appendChild(style);
    }
  };

  var ConfirmDialog = {
    _overlay: null,
    _resolve: null,
    init: function () {
      this._injectStyles();
      this._createOverlay();
      var self = this;
      window._wlConfirm = function (msg, title) {
        return new Promise(function (resolve) {
          self._resolve = resolve;
          self._overlay.querySelector('.cd-title').textContent = title || '确认';
          self._overlay.querySelector('.cd-msg').textContent = msg || '确定要执行此操作吗？';
          self._overlay.style.display = 'flex';
          self._overlay.querySelector('.cd-confirm').focus();
        });
      };
      window.confirm = function (msg) {
        return window._wlConfirm ? false : (typeof msg === 'string' ? false : false);
      };
    },
    _injectStyles: function () {
      if (document.getElementById('cd-styles')) return;
      var style = document.createElement('style');
      style.id = 'cd-styles';
      style.textContent = '.cd-overlay{position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.4);animation:cdFadeIn 0.2s ease;}.cd-dialog{background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:380px;box-shadow:var(--shadow-lg);animation:cdSlideIn 0.25s ease;}.cd-title{font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:10px;}.cd-msg{font-size:0.9rem;color:var(--text-secondary);margin-bottom:20px;line-height:1.5;}.cd-actions{display:flex;gap:10px;justify-content:flex-end;}.cd-btn{padding:8px 20px;border-radius:8px;border:none;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.2s;}.cd-cancel{background:var(--bg);color:var(--text);}.cd-cancel:hover{background:var(--bg-secondary);}.cd-confirm{background:var(--primary);color:#fff;}.cd-confirm:hover{background:var(--primary-hover);}.cd-danger{background:var(--danger);}.cd-danger:hover{background:var(--accent-pink-dark);}@keyframes cdFadeIn{from{opacity:0}to{opacity:1}}@keyframes cdSlideIn{from{opacity:0;transform:translateY(-20px) scale(0.95)}to{opacity:1;transform:translateY(0) scale(1)}}';
      document.head.appendChild(style);
    },
    _createOverlay: function () {
      var self = this;
      this._overlay = document.createElement('div');
      this._overlay.className = 'cd-overlay';
      this._overlay.style.display = 'none';
      this._overlay.innerHTML = '<div class="cd-dialog"><div class="cd-title"></div><div class="cd-msg"></div><div class="cd-actions"><button class="cd-btn cd-cancel">取消</button><button class="cd-btn cd-confirm">确定</button></div></div>';
      document.body.appendChild(this._overlay);
      this._overlay.querySelector('.cd-cancel').addEventListener('click', function () { self._overlay.style.display = 'none'; if (self._resolve) self._resolve(false); });
      this._overlay.querySelector('.cd-confirm').addEventListener('click', function () { self._overlay.style.display = 'none'; if (self._resolve) self._resolve(true); });
      this._overlay.addEventListener('click', function (e) { if (e.target === this) { self._overlay.style.display = 'none'; if (self._resolve) self._resolve(false); } });
    }
  };

  var SearchSuggestions = {
    _max: 8,
    _key: 'wl_search_suggestions',
    init: function () {
      var searchInput = document.querySelector('.search-input, #searchInput');
      if (!searchInput) return;
      this._injectStyles();
      var self = this;
      var dropdown = document.createElement('div');
      dropdown.className = 'ss-dropdown';
      dropdown.style.display = 'none';
      searchInput.parentNode.style.position = 'relative';
      searchInput.parentNode.appendChild(dropdown);
      searchInput.addEventListener('focus', function () { self._show(searchInput, dropdown); });
      searchInput.addEventListener('blur', function () { setTimeout(function () { dropdown.style.display = 'none'; }, 200); });
      dropdown.addEventListener('click', function (e) {
        var item = e.target.closest('.ss-item');
        if (item) {
          searchInput.value = item.textContent.trim();
          dropdown.style.display = 'none';
          var searchBtn = document.getElementById('searchBtn');
          if (searchBtn) searchBtn.click();
        }
      });
      var searchBtn = document.getElementById('searchBtn');
      if (searchBtn) {
        searchBtn.addEventListener('click', function () {
          var val = searchInput.value.trim();
          if (val) self._save(val);
        });
      }
      searchInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { var val = searchInput.value.trim(); if (val) self._save(val); } });
    },
    _injectStyles: function () {
      if (document.getElementById('ss-styles')) return;
      var style = document.createElement('style');
      style.id = 'ss-styles';
      style.textContent = '.ss-dropdown{position:absolute;top:100%;left:0;right:0;z-index:500;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-md);max-height:240px;overflow-y:auto;margin-top:4px;}.ss-header{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;font-size:11px;color:var(--text-muted);border-bottom:1px solid var(--border-light);}.ss-clear{background:none;border:none;color:var(--primary);cursor:pointer;font-size:11px;}.ss-item{padding:8px 12px;font-size:13px;color:var(--text);cursor:pointer;transition:background 0.15s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}.ss-item:hover{background:var(--primary-light);}.ss-empty{padding:16px;text-align:center;color:var(--text-muted);font-size:13px;}';
      document.head.appendChild(style);
    },
    _get: function () { try { return JSON.parse(localStorage.getItem(this._key)) || []; } catch (e) { return []; } },
    _save: function (term) {
      if (!term) return;
      var list = this._get().filter(function (t) { return t !== term; });
      list.unshift(term);
      if (list.length > this._max) list.length = this._max;
      try { localStorage.setItem(this._key, JSON.stringify(list)); } catch (e) {}
    },
    _show: function (input, dropdown) {
      var list = this._get();
      if (list.length === 0) { dropdown.style.display = 'none'; return; }
      var html = '<div class="ss-header">搜索建议<span class="ss-clear">清除</span></div>';
      list.forEach(function (t) { html += '<div class="ss-item">' + escapeHtml(t) + '</div>'; });
      dropdown.innerHTML = html;
      dropdown.style.display = 'block';
      var self = this;
      dropdown.querySelector('.ss-clear').addEventListener('click', function (e) { e.stopPropagation(); localStorage.removeItem(self._key); dropdown.style.display = 'none'; });
    }
  };

  // ScrollToTopButton（.stt-btn 回到顶部）已移除：站内第三个「回到顶部」，
  // 现统一由 polish.js 的统一浮动按钮提供。


  var InputValidation = {
    init: function () {
      this._injectStyles();
      var self = this;
      document.querySelectorAll('input[required], input[type="email"], input[type="url"], input[pattern], textarea[required]').forEach(function (el) {
        self._bind(el);
      });
      var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (node.nodeType === 1) {
              if (node.matches && node.matches('input[required], input[type="email"], input[type="url"], input[pattern], textarea[required]')) { self._bind(node); }
              if (node.querySelectorAll) { node.querySelectorAll('input[required], input[type="email"], input[type="url"], input[pattern], textarea[required]').forEach(function (c) { self._bind(c); }); }
            }
          });
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    },
    _injectStyles: function () {
      if (document.getElementById('iv-styles')) return;
      var style = document.createElement('style');
      style.id = 'iv-styles';
      style.textContent = 'input.iv-valid,textarea.iv-valid{border-color:var(--success)!important;box-shadow:0 0 0 3px var(--success-light)!important;}input.iv-invalid,textarea.iv-invalid{border-color:var(--danger)!important;box-shadow:0 0 0 3px var(--danger-light)!important;}.iv-msg{font-size:11px;margin-top:2px;display:block;}.iv-msg.iv-valid{color:var(--success);}.iv-msg.iv-invalid{color:var(--danger);}';
      document.head.appendChild(style);
    },
    _bind: function (el) {
      if (el._ivBound) return;
      el._ivBound = true;
      var self = this;
      var msgEl = document.createElement('span');
      msgEl.className = 'iv-msg';
      el.parentNode.insertBefore(msgEl, el.nextSibling);
      el.addEventListener('input', debounce(function () { self._validate(el, msgEl); }, 300));
      el.addEventListener('blur', function () { self._validate(el, msgEl); });
    },
    _validate: function (el, msgEl) {
      el.classList.remove('iv-valid', 'iv-invalid');
      msgEl.className = 'iv-msg';
      msgEl.textContent = '';
      if (!el.value && el.required) {
        el.classList.add('iv-invalid');
        msgEl.className = 'iv-msg iv-invalid';
        msgEl.textContent = '此字段为必填项';
        return;
      }
      if (!el.value) return;
      if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value)) {
        el.classList.add('iv-invalid');
        msgEl.className = 'iv-msg iv-invalid';
        msgEl.textContent = '请输入有效的邮箱地址';
        return;
      }
      if (el.type === 'url' && !/^https?:\/\/.+/.test(el.value)) {
        el.classList.add('iv-invalid');
        msgEl.className = 'iv-msg iv-invalid';
        msgEl.textContent = '请输入有效的URL';
        return;
      }
      if (el.pattern) {
        try { var re = new RegExp(el.pattern); if (!re.test(el.value)) { el.classList.add('iv-invalid'); msgEl.className = 'iv-msg iv-invalid'; msgEl.textContent = el.title || '格式不正确'; return; } } catch (e) {}
      }
      if (el.value) {
        el.classList.add('iv-valid');
        msgEl.className = 'iv-msg iv-valid';
        msgEl.textContent = '✓';
      }
    }
  };

  var LoadingSkeleton = {
    init: function () {
      this._injectStyles();
      var self = this;
      var container = document.getElementById('postsContainer');
      if (container) {
        var origFetch = typeof App.fetchPosts === 'function' ? App.fetchPosts : null;
        if (origFetch) {
          App.fetchPosts = function () {
            self.show(container);
            return origFetch.apply(this, arguments).finally(function () { self.hide(container); });
          };
        }
      }
    },
    _injectStyles: function () {
      if (document.getElementById('ls-styles')) return;
      var style = document.createElement('style');
      style.id = 'ls-styles';
      style.textContent = '.ls-card{background:var(--card-bg);border-radius:12px;padding:16px;margin-bottom:12px;border:1px solid var(--border);overflow:hidden;}.ls-line{height:14px;background:var(--bg-secondary);border-radius:4px;margin-bottom:10px;animation:lsShimmer 1.5s infinite;}.ls-line-sm{width:35%;}.ls-line-md{width:65%;}.ls-line-lg{width:90%;}.ls-line-xl{width:100%;}.ls-btn{width:90px;height:32px;background:var(--bg-secondary);border-radius:6px;animation:lsShimmer 1.5s infinite;}@keyframes lsShimmer{0%{opacity:0.6}50%{opacity:1}100%{opacity:0.6}}';
      document.head.appendChild(style);
    },
    show: function (container, count) {
      count = count || 4;
      var html = '';
      for (var i = 0; i < count; i++) {
        html += '<div class="ls-card"><div class="ls-line ls-line-sm"></div><div class="ls-line ls-line-xl"></div><div class="ls-line ls-line-lg"></div><div class="ls-line ls-line-md"></div><div class="ls-btn"></div></div>';
      }
      container.innerHTML = html;
    },
    hide: function (container) {
      var cards = container.querySelectorAll('.ls-card');
      cards.forEach(function (c) { c.remove(); });
    }
  };

  // BackToTopWithProgress（.btt-progress 带回顶进度环）已移除：站内第四个「回到顶部」，
  // 现统一由 polish.js 的统一浮动按钮提供（阅读进度另有 main.js 的 .scroll-progress 与 .lw-readbar）。


  var MobileNavBar = {
    init: function () {
      if (window.innerWidth > 768) return;
      // 各页面在 HTML 中已内置静态的 .mobile-bottom-nav（见 index/tools/post_detail/user_center 等），
      // 若再注入 #mobile-nav-bar 会与静态导航在移动端底部重叠、造成布局错乱（刷新后尤甚）。
      // 检测到已有静态移动导航时直接返回，避免重复渲染；仅在没有静态导航的页面才注入兜底。
      if (document.querySelector('.mobile-bottom-nav')) return;
      this._injectStyles();
      if (document.getElementById('mobile-nav-bar')) return;
      var nav = document.createElement('nav');
      nav.id = 'mobile-nav-bar';
      nav.className = 'mobile-nav-bar';
      var items = [
        { icon: 'home', label: '首页', href: '/' },
        { icon: 'search', label: '搜索', action: function () { var inp = document.getElementById('searchInput'); if (inp) { inp.focus(); inp.scrollIntoView({ behavior: 'smooth' }); } } },
        { icon: 'plus', label: '发帖', href: '/pages/post.php' },
        { icon: 'bell', label: '消息', action: function () { if (window.App && App.PrivateMessage) { App.PrivateMessage.open(); } } },
        { icon: 'user', label: '我的', href: '/pages/user_center.php' }
      ];
      var html = '';
      items.forEach(function (item) {
        var tag = item.href ? 'a' : 'button';
        html += '<' + tag + ' class="mnb-item" ' + (item.href ? 'href="' + item.href + '"' : '') + '>' + svgIcon(item.icon, 20) + '<span class="mnb-label">' + item.label + '</span></' + tag + '>';
      });
      nav.innerHTML = html;
      document.body.appendChild(nav);
      document.body.style.paddingBottom = '70px';
      nav.querySelectorAll('button.mnb-item').forEach(function (btn) {
        var item = items.find(function (i) { return i.label === btn.querySelector('.mnb-label').textContent; });
        if (item && item.action) btn.addEventListener('click', function (e) { e.preventDefault(); item.action(); });
      });
    },
    _injectStyles: function () {
      if (document.getElementById('mnb-styles')) return;
      var style = document.createElement('style');
      style.id = 'mnb-styles';
      style.textContent = '.mobile-nav-bar{position:fixed;bottom:0;left:0;right:0;z-index:990;display:flex;justify-content:space-around;align-items:center;padding:8px 0 12px;background:var(--card-bg);border-top:1px solid var(--border);box-shadow:0 -2px 10px rgba(0,0,0,0.06);}.mnb-item{display:flex;flex-direction:column;align-items:center;gap:2px;text-decoration:none;color:var(--text-secondary);background:none;border:none;cursor:pointer;padding:4px 12px;transition:color 0.2s;font-size:0;}.mnb-item:hover,.mnb-item:active{color:var(--primary);}.mnb-label{font-size:10px;font-weight:500;}.mnb-item[href="/"],.mnb-item[href="/index.php"]{color:var(--primary);}@media(min-width:769px){.mobile-nav-bar{display:none!important}}';
      document.head.appendChild(style);
    }
  };

  var SmoothScrollAnchors = {
    init: function () {
      var self = this;
      document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href^="#"]');
        if (!a) return;
        var href = a.getAttribute('href');
        if (!href || href === '#') return;
        e.preventDefault();
        var target = document.getElementById(href.substring(1));
        if (!target) target = document.querySelector('[data-anchor="' + href.substring(1) + '"]');
        if (!target) return;
        var headerHeight = 60;
        var top = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 10;
        window.scrollTo({ top: top, behavior: 'smooth' });
        if (history.pushState) { history.pushState(null, '', href); }
      });
    }
  };

  var ExternalLinkConfirm = {
    init: function () {
      this._injectStyles();
      var self = this;
      document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a) return;
        var href = a.getAttribute('href');
        if (!href || href === '#' || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
        if (href.startsWith('/') || href.indexOf(window.location.hostname) !== -1) return;
        if (a.classList.contains('elc-trusted')) return;
        e.preventDefault();
        self._show(href, a);
      });
    },
    _injectStyles: function () {
      if (document.getElementById('elc-styles')) return;
      var style = document.createElement('style');
      style.id = 'elc-styles';
      style.textContent = '.elc-overlay{position:fixed;inset:0;z-index:10060;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.4);animation:cdFadeIn 0.2s ease;}.elc-dialog{background:var(--card-bg);border-radius:16px;padding:24px;width:90%;max-width:400px;box-shadow:var(--shadow-lg);animation:cdSlideIn 0.25s ease;}.elc-title{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:8px;}.elc-url{display:block;padding:8px 12px;background:var(--bg);border-radius:6px;font-size:12px;color:var(--text-secondary);word-break:break-all;margin-bottom:16px;max-height:60px;overflow:hidden;}.elc-actions{display:flex;gap:10px;justify-content:flex-end;}.elc-actions button{padding:8px 20px;border-radius:8px;border:none;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.2s;}.elc-btn-cancel{background:var(--bg);color:var(--text);}.elc-btn-go{background:var(--primary);color:#fff;}.elc-btn-go:hover{background:var(--primary-hover);}';
      document.head.appendChild(style);
    },
    _show: function (href, anchor) {
      var overlay = document.createElement('div');
      overlay.className = 'elc-overlay';
      overlay.innerHTML = '<div class="elc-dialog"><div class="elc-title">即将离开本站</div><div class="elc-url">' + escapeHtml(href) + '</div><div class="elc-actions"><button class="elc-btn-cancel">取消</button><button class="elc-btn-go">继续访问</button></div></div>';
      document.body.appendChild(overlay);
      overlay.querySelector('.elc-btn-cancel').addEventListener('click', function () { overlay.remove(); });
      overlay.querySelector('.elc-btn-go').addEventListener('click', function () { overlay.remove(); window.open(href, '_blank'); });
      overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
    }
  };

  var PageTitleUpdate = {
    _original: document.title,
    _unread: 0,
    _timer: null,
    init: function () {
      this._original = document.title;
      var self = this;
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { self._unread = 0; document.title = self._original; }
      });
      this._timer = setInterval(function () {
        if (self._unread > 0 && !document.hidden) { self._unread = 0; document.title = self._original; }
      }, 3000);
    },
    set: function (count) {
      this._unread = count || 0;
      if (this._unread > 0 && document.hidden) {
        document.title = '【' + (this._unread > 99 ? '99+' : this._unread) + '条新消息】 ' + this._original;
      }
    }
  };

  var CopyCodeButton = {
    init: function () {
      this._injectStyles();
      var self = this;
      this._scan();
      var observer = new MutationObserver(function () { self._scan(); });
      observer.observe(document.body, { childList: true, subtree: true });
    },
    _injectStyles: function () {
      if (document.getElementById('ccb-styles')) return;
      var style = document.createElement('style');
      style.id = 'ccb-styles';
      style.textContent = '.code-block-wrapper{position:relative;}.ccb-copy-btn{position:absolute;top:8px;right:8px;padding:4px 10px;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.2);border-radius:4px;color:#fff;cursor:pointer;font-size:12px;transition:all 0.2s;opacity:0;}.code-block-wrapper:hover .ccb-copy-btn{opacity:1;}.ccb-copy-btn:hover{background:rgba(255,255,255,0.25);}.ccb-copy-btn.copied{background:var(--success);border-color:var(--success);}';
      document.head.appendChild(style);
    },
    _scan: function () {
      var self = this;
      document.querySelectorAll('pre code:not(.ccb-bound)').forEach(function (code) {
        code.classList.add('ccb-bound');
        var pre = code.parentElement;
        if (!pre.classList.contains('code-block-wrapper')) {
          pre.classList.add('code-block-wrapper');
          pre.style.position = 'relative';
        }
        var btn = document.createElement('button');
        btn.className = 'ccb-copy-btn';
        btn.textContent = '复制';
        btn.addEventListener('click', function () {
          var text = code.textContent;
          navigator.clipboard.writeText(text).then(function () {
            btn.textContent = '已复制!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = '复制'; btn.classList.remove('copied'); }, 2000);
          }).catch(function () {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;left:-9999px;';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            btn.textContent = '已复制!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = '复制'; btn.classList.remove('copied'); }, 2000);
          });
        });
        pre.appendChild(btn);
      });
    }
  };

  var PasswordStrengthMeter = {
    init: function () {
      var pwInput = document.querySelector('input[type="password"][name="password"], input[type="password"][name="new_password"], #register-password');
      if (!pwInput) return;
      var meter = document.createElement('div');
      meter.className = 'pw-strength-meter';
      meter.style.cssText = 'height:4px;border-radius:2px;margin-top:6px;transition:all 0.3s;background:var(--border);width:0;';
      pwInput.parentNode.insertBefore(meter, pwInput.nextSibling);
      var label = document.createElement('span');
      label.className = 'pw-strength-label';
      label.style.cssText = 'font-size:11px;color:var(--text-muted);margin-top:2px;display:block;';
      meter.parentNode.insertBefore(label, meter.nextSibling);
      pwInput.addEventListener('input', function () {
        var val = this.value;
        var score = 0;
        if (val.length >= 8) score++;
        if (val.length >= 12) score++;
        if (/[a-z]/.test(val) && /[A-Z]/.test(val)) score++;
        if (/\d/.test(val)) score++;
        if (/[^a-zA-Z0-9]/.test(val)) score++;
        var pct = (score / 5) * 100;
        var colors = ['#ef4444','#f59e0b','#f59e0b','#84cc16','#22c55e'];
        var texts = ['非常弱','弱','一般','强','非常强'];
        meter.style.width = pct + '%';
        meter.style.background = colors[score] || colors[0];
        label.textContent = texts[score] || texts[0];
        label.style.color = colors[score] || colors[0];
      });
    }
  };

  var FormShakeOnError = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '@keyframes formShake{0%,100%{transform:translateX(0)}10%,30%,50%,70%,90%{transform:translateX(-4px)}20%,40%,60%,80%{transform:translateX(4px)}}.form-shake{animation:formShake 0.5s ease;border-color:var(--danger)!important;}';
      document.head.appendChild(style);
      document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList.contains('needs-validation')) return;
        var invalid = form.querySelectorAll(':invalid, .is-invalid');
        if (invalid.length > 0) {
          e.preventDefault();
          invalid[0].focus();
          invalid[0].classList.add('form-shake');
          invalid[0].addEventListener('animationend', function () { this.classList.remove('form-shake'); }, { once: true });
          if (typeof showToast === 'function') showToast('请检查表单中的错误', 'warning');
        }
      }, true);
    }
  };

  var SessionExpiryWarning = {
    init: function () {
      if (!App.IS_LOGGED_IN) return;
      var sessionTime = parseInt(document.cookie.match(/session_expires=(\d+)/)?.[1] || '0') || 0;
      if (!sessionTime) return;
      var remaining = sessionTime - Math.floor(Date.now() / 1000);
      if (remaining <= 0) return;
      var warned = false;
      var warnAt = remaining - 300;
      if (warnAt <= 0) return;
      setTimeout(function () {
        if (typeof showToast === 'function') {
          showToast('您的登录会话即将过期，请保存工作', 'warning');
        }
      }, warnAt * 1000);
    }
  };

  var IdleAutoLock = {
    _timeout: null,
    _idleTime: 1800000,
    init: function () {
      if (!App.IS_LOGGED_IN) return;
      var self = this;
      var events = ['mousedown','mousemove','keypress','scroll','touchstart'];
      function resetTimer() {
        clearTimeout(self._timeout);
        self._timeout = setTimeout(function () {
          if (typeof showToast === 'function') showToast('长时间未操作，建议锁定屏幕', 'info');
          self._timeout = setTimeout(function () {
            // 长时间空闲后自动登出，跳转登录页（登出由服务端会话失效处理）。
            // 必须走 fetchAPI：logout.php 有 CSRF 校验，裸 fetch 不带 csrf_token 会被 403
            // 直接拒掉、会话根本没销毁，跳回登录页时仍带着有效会话 —— 等于自动登出从未生效。
            var go = function () { window.location.href = '/pages/login.php'; };
            try {
              if (typeof fetchAPI === 'function') {
                fetchAPI('/api/auth/logout.php', { method: 'POST' }).catch(function () {}).then(go);
              } else {
                fetch('/api/auth/logout.php', { method: 'POST' }).catch(function () {}).then(go);
              }
            } catch (e) {
              go();
            }
          }, 300000);
        }, self._idleTime);
      }
      events.forEach(function (ev) { document.addEventListener(ev, resetTimer, { passive: true }); });
      resetTimer();
    }
  };

  var StaggeredEntrance = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '@keyframes staggerFadeIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}.stagger-item{opacity:0;animation:staggerFadeIn 0.5s ease forwards;}';
      document.head.appendChild(style);
      var items = document.querySelectorAll('.post-card, .card, .widget, .uc-section, .admin-card');
      items.forEach(function (item, i) {
        item.classList.add('stagger-item');
        item.style.animationDelay = (i * 0.04) + 's';
      });
    }
  };

  var NetworkQualityIndicator = {
    init: function () {
      if (!navigator.connection) return;
      var self = this;
      var bar = document.createElement('div');
      bar.className = 'network-indicator';
      bar.style.cssText = 'position:fixed;top:0;left:0;right:0;height:3px;z-index:99999;background:transparent;transition:all 0.5s;';
      document.body.appendChild(bar);
      function update() {
        var conn = navigator.connection;
        var type = conn.effectiveType || '4g';
        var colors = { 'slow-2g': '#ef4444', '2g': '#f59e0b', '3g': '#fbbf24', '4g': 'transparent' };
        var rtt = conn.rtt || 0;
        if (rtt > 500) type = 'slow-2g';
        else if (rtt > 200) type = '2g';
        bar.style.background = colors[type] || 'transparent';
        if (type !== '4g' && typeof showToast === 'function') {
          showToast('当前网络较慢，可能影响体验', 'info');
        }
      }
      update();
      navigator.connection.addEventListener('change', update);
    }
  };

  var ResponsiveTableScroll = {
    init: function () {
      document.querySelectorAll('table:not(.no-wrap)').forEach(function (table) {
        var wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        wrapper.style.cssText = 'overflow-x:auto;-webkit-overflow-scrolling:touch;';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
        var hint = document.createElement('div');
        hint.className = 'table-scroll-hint';
        hint.style.cssText = 'text-align:center;font-size:11px;color:var(--text-muted);padding:4px 0;display:none;';
        hint.textContent = '← 左右滑动查看更多 →';
        wrapper.parentNode.insertBefore(hint, wrapper.nextSibling);
        function checkScroll() {
          hint.style.display = table.scrollWidth > wrapper.clientWidth ? 'block' : 'none';
        }
        checkScroll();
        window.addEventListener('resize', checkScroll);
        new MutationObserver(checkScroll).observe(table, { childList: true, subtree: true });
      });
    }
  };

  var StepProgressIndicator = {
    init: function () {
      var steps = document.querySelectorAll('.step-progress');
      if (!steps.length) return;
      var style = document.createElement('style');
      style.textContent = '.step-progress{display:flex;align-items:center;gap:0;margin:16px 0;}.step-item{display:flex;align-items:center;flex:1;position:relative;}.step-circle{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.8rem;font-weight:700;border:2px solid var(--border);background:var(--card-bg);color:var(--text-muted);transition:all 0.3s;z-index:1;}.step-item.active .step-circle{background:var(--primary);border-color:var(--primary);color:#fff;}.step-item.completed .step-circle{background:var(--success);border-color:var(--success);color:#fff;}.step-line{flex:1;height:2px;background:var(--border);margin:0 8px;}.step-item.completed .step-line{background:var(--success);}.step-label{font-size:0.75rem;color:var(--text-muted);text-align:center;margin-top:4px;}.step-item.active .step-label{color:var(--primary);font-weight:600;}';
      document.head.appendChild(style);
    }
  };

  var InputFloatingLabel = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '.floating-label{position:relative;margin-bottom:16px;}.floating-label input,.floating-label textarea,.floating-label select{padding-top:20px;padding-bottom:8px;}.floating-label label{position:absolute;left:12px;top:50%;transform:translateY(-50%);transition:all 0.2s;font-size:0.9rem;color:var(--text-muted);pointer-events:none;background:transparent;}.floating-label input:focus~label,.floating-label textarea:focus~label,.floating-label select:focus~label,.floating-label input:not(:placeholder-shown)~label,.floating-label textarea:not(:placeholder-shown)~label,.floating-label.filled label{top:8px;font-size:0.7rem;color:var(--primary);transform:translateY(0);}';
      document.head.appendChild(style);
      document.querySelectorAll('.floating-label input, .floating-label textarea').forEach(function (input) {
        input.placeholder = ' ';
        input.addEventListener('input', function () {
          this.parentElement.classList.toggle('filled', this.value.length > 0);
        });
      });
    }
  };

  var ScrollNavigationDots = {
    init: function () {
      var sections = document.querySelectorAll('[data-section]');
      if (sections.length < 2) return;
      var dots = document.createElement('div');
      dots.className = 'scroll-dots';
      dots.style.cssText = 'position:fixed;right:16px;top:50%;transform:translateY(-50%);z-index:50;display:flex;flex-direction:column;gap:8px;';
      sections.forEach(function (section, i) {
        var dot = document.createElement('button');
        dot.className = 'scroll-dot';
        dot.style.cssText = 'width:10px;height:10px;border-radius:50%;border:2px solid var(--primary);background:transparent;cursor:pointer;transition:all 0.3s;padding:0;';
        dot.title = section.dataset.section;
        dot.addEventListener('click', function () { section.scrollIntoView({ behavior: 'smooth' }); });
        dots.appendChild(dot);
      });
      document.body.appendChild(dots);
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            var idx = Array.from(sections).indexOf(entry.target);
            dots.querySelectorAll('.scroll-dot').forEach(function (d, i) {
              d.style.background = i === idx ? 'var(--primary)' : 'transparent';
            });
          }
        });
      }, { threshold: 0.5 });
      sections.forEach(function (s) { observer.observe(s); });
    }
  };

  var QuickJumpMenu = {
    init: function () {
      document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.key === 'k') {
          e.preventDefault();
          var modal = document.createElement('div');
          modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000;display:flex;align-items:flex-start;justify-content:center;padding-top:20vh;';
          modal.innerHTML = '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.5);" id="qj-overlay"></div><div style="position:relative;background:var(--card-bg);border-radius:16px;width:90%;max-width:500px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);"><input id="qj-input" placeholder="搜索页面功能..." style="width:100%;padding:16px;border:none;outline:none;font-size:1rem;background:var(--card-bg);color:var(--text);border-bottom:1px solid var(--border);"><div id="qj-results" style="max-height:300px;overflow-y:auto;"></div></div>';
          document.body.appendChild(modal);
          var input = document.getElementById('qj-input');
          var results = document.getElementById('qj-results');
          var links = [
            { name: '首页', url: '/', icon: 'home' },
            { name: '发帖', url: '/pages/post.php', icon: 'edit' },
            { name: '用户中心', url: '/pages/user_center.php', icon: 'user' },
            { name: '工具箱', url: '/pages/tools.php', icon: 'grid' },
            { name: '管理后台', url: '/admin/', icon: 'shield' },
            { name: '登录', url: '/pages/login.php', icon: 'logIn' },
            { name: '注册', url: '/pages/register.php', icon: 'userPlus' }
          ];
          function render(filter) {
            var f = filter.toLowerCase();
            var filtered = links.filter(function (l) { return l.name.toLowerCase().includes(f) || l.url.toLowerCase().includes(f); });
            results.innerHTML = filtered.map(function (l) {
              return '<div class="qj-item" data-url="' + l.url + '" style="padding:12px 16px;cursor:pointer;display:flex;align-items:center;gap:12px;transition:background 0.15s;color:var(--text);">' + (svgIcon(l.icon, 18) || '') + '<span>' + l.name + '</span><span style="margin-left:auto;font-size:0.75rem;color:var(--text-muted);">' + l.url + '</span></div>';
            }).join('') || '<div style="padding:24px;text-align:center;color:var(--text-muted);">无匹配结果</div>';
            results.querySelectorAll('.qj-item').forEach(function (item) {
              item.addEventListener('click', function () { window.location.href = this.dataset.url; });
            });
          }
          render('');
          input.addEventListener('input', function () { render(this.value); });
          input.focus();
          modal.querySelector('#qj-overlay').addEventListener('click', function () { modal.remove(); });
          input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') { modal.remove(); }
            if (ev.key === 'Enter') {
              var first = results.querySelector('.qj-item');
              if (first) window.location.href = first.dataset.url;
            }
          });
        }
      });
    }
  };

  var PostWordCounter = {
    init: function () {
      var textarea = document.querySelector('#post-content, textarea[name="content"]');
      if (!textarea) return;
      var counter = document.createElement('div');
      counter.className = 'word-counter';
      counter.style.cssText = 'text-align:right;font-size:0.75rem;color:var(--text-muted);margin-top:4px;';
      textarea.parentNode.insertBefore(counter, textarea.nextSibling);
      function update() {
        var len = textarea.value.length;
        var max = parseInt(textarea.getAttribute('maxlength') || '5000');
        counter.textContent = len + ' / ' + max;
        counter.style.color = len > max * 0.9 ? 'var(--danger)' : len > max * 0.7 ? 'var(--warning)' : 'var(--text-muted)';
      }
      textarea.addEventListener('input', update);
      update();
    }
  };

  var UserProfileCard = {
    _card: null,
    _timer: null,
    init: function () {
      var self = this;
      document.addEventListener('mouseover', function (e) {
        var link = e.target.closest('.user-link, .post-card-author, .comment-author');
        if (!link) return;
        var userId = link.dataset.userId || link.getAttribute('data-user-id');
        if (!userId) return;
        self._timer = setTimeout(function () { self._show(userId, e); }, 600);
      });
      document.addEventListener('mouseout', function (e) {
        var link = e.target.closest('.user-link, .post-card-author, .comment-author');
        if (link) { clearTimeout(self._timer); self._hide(); }
      });
    },
    _show: function (userId, e) {
      if (this._card) this._card.remove();
      var card = document.createElement('div');
      card.className = 'user-profile-card';
      card.style.cssText = 'position:fixed;z-index:9999;background:var(--card-bg);border-radius:12px;padding:16px;box-shadow:0 8px 32px rgba(0,0,0,0.15);border:1px solid var(--border);min-width:200px;max-width:280px;pointer-events:none;';
      card.innerHTML = '<div style="text-align:center;padding:12px;color:var(--text-muted);">加载中...</div>';
      document.body.appendChild(card);
      var rect = card.getBoundingClientRect();
      var x = Math.min(e.clientX, window.innerWidth - rect.width - 10);
      var y = e.clientY + 10;
      card.style.left = x + 'px';
      card.style.top = y + 'px';
      this._card = card;
      var self = this;
      fetchAPI('/api/user/profile.php?user_id=' + userId).then(function (res) {
        if (!res.success || !self._card) return;
        var u = res.data;
        self._card.innerHTML = '<div style="display:flex;align-items:center;gap:12px;"><div style="width:48px;height:48px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--primary);font-size:1.1rem;">' + (u.username || '?').charAt(0).toUpperCase() + '</div><div><div style="font-weight:600;color:var(--text);">' + escapeHtml(u.username || '') + '</div><div style="font-size:0.75rem;color:var(--text-muted);">' + (u.title || '普通用户') + '</div></div></div>' + (u.bio ? '<div style="margin-top:8px;font-size:0.8rem;color:var(--text-secondary);">' + escapeHtml(u.bio) + '</div>' : '') + '<div style="display:flex;gap:16px;margin-top:10px;font-size:0.75rem;color:var(--text-muted);"><span>帖子 ' + (u.post_count || 0) + '</span><span>粉丝 ' + (u.follower_count || 0) + '</span></div>';
      }).catch(function () {});
    },
    _hide: function () {
      if (this._card) { this._card.remove(); this._card = null; }
    }
  };

  var FavoriteHeartAnimation = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '@keyframes heartBeat{0%{transform:scale(1)}15%{transform:scale(1.3)}30%{transform:scale(1)}45%{transform:scale(1.2)}60%{transform:scale(1)}}.heart-animate{animation:heartBeat 0.6s ease;display:inline-block;}@keyframes heartFloat{0%{opacity:1;transform:translateY(0) scale(1)}100%{opacity:0;transform:translateY(-60px) scale(1.5)}}.heart-float{position:fixed;pointer-events:none;z-index:9999;font-size:1.5rem;animation:heartFloat 1s ease forwards;}';
      document.head.appendChild(style);
      document.addEventListener('click', function (e) {
        var btn = e.target.closest('.favorite-btn, .like-btn');
        if (!btn) return;
        var rect = btn.getBoundingClientRect();
        var heart = document.createElement('span');
        heart.className = 'heart-float';
        heart.textContent = btn.classList.contains('favorite-btn') ? '⭐' : '❤️';
        heart.style.left = rect.left + rect.width / 2 - 12 + 'px';
        heart.style.top = rect.top + 'px';
        document.body.appendChild(heart);
        heart.addEventListener('animationend', function () { heart.remove(); });
      });
    }
  };

  var AutoSaveIndicator = {
    init: function () {
      var textareas = document.querySelectorAll('textarea[data-autosave]');
      if (!textareas.length) return;
      var style = document.createElement('style');
      style.textContent = '.autosave-indicator{display:inline-flex;align-items:center;gap:4px;font-size:0.7rem;color:var(--text-muted);padding:2px 8px;border-radius:12px;transition:all 0.3s;}.autosave-indicator.saving{color:var(--warning);}.autosave-indicator.saved{color:var(--success);}.autosave-indicator .as-dot{width:6px;height:6px;border-radius:50%;background:currentColor;}';
      document.head.appendChild(style);
      textareas.forEach(function (ta) {
        var indicator = document.createElement('span');
        indicator.className = 'autosave-indicator';
        indicator.innerHTML = '<span class="as-dot"></span>已保存';
        indicator.classList.add('saved');
        ta.parentNode.insertBefore(indicator, ta.nextSibling);
        var timer;
        ta.addEventListener('input', function () {
          indicator.classList.remove('saved');
          indicator.classList.add('saving');
          indicator.innerHTML = '<span class="as-dot"></span>保存中...';
          clearTimeout(timer);
          timer = setTimeout(function () {
            indicator.classList.remove('saving');
            indicator.classList.add('saved');
            indicator.innerHTML = '<span class="as-dot"></span>已保存';
            if (ta.dataset.autosave === 'local') {
              localStorage.setItem('autosave_' + (ta.name || ta.id || 'default'), ta.value);
            }
          }, 800);
        });
      });
    }
  };

  var TextSelectionActions = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '.selection-toolbar{position:absolute;z-index:9999;background:var(--card-bg);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.15);border:1px solid var(--border);padding:4px;display:none;gap:2px;}.selection-toolbar button{width:32px;height:32px;border:none;background:transparent;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-secondary);transition:all 0.15s;font-size:1rem;}.selection-toolbar button:hover{background:var(--bg);color:var(--primary);}';
      document.head.appendChild(style);
      var toolbar = document.createElement('div');
      toolbar.className = 'selection-toolbar';
      toolbar.innerHTML = '<button title="复制" id="sel-copy">📋</button><button title="搜索" id="sel-search">🔍</button>';
      document.body.appendChild(toolbar);
      document.addEventListener('mouseup', function (e) {
        setTimeout(function () {
          var sel = window.getSelection();
          if (!sel.toString().trim()) { toolbar.style.display = 'none'; return; }
          var range = sel.getRangeAt(0);
          var rect = range.getBoundingClientRect();
          toolbar.style.display = 'flex';
          toolbar.style.left = rect.left + rect.width / 2 - toolbar.offsetWidth / 2 + 'px';
          toolbar.style.top = rect.top - toolbar.offsetHeight - 8 + window.scrollY + 'px';
        }, 10);
      });
      document.getElementById('sel-copy').addEventListener('click', function () {
        navigator.clipboard.writeText(window.getSelection().toString()).then(function () {
          if (typeof showToast === 'function') showToast('已复制到剪贴板', 'success');
        });
        toolbar.style.display = 'none';
      });
      document.getElementById('sel-search').addEventListener('click', function () {
        var text = window.getSelection().toString().trim();
        if (text) window.open('https://www.baidu.com/s?wd=' + encodeURIComponent(text), '_blank');
        toolbar.style.display = 'none';
      });
      document.addEventListener('mousedown', function (e) {
        if (!toolbar.contains(e.target)) toolbar.style.display = 'none';
      });
    }
  };

  var CommentHighlightNav = {
    init: function () {
      var hash = window.location.hash;
      if (!hash || hash.indexOf('#comment-') !== 0) return;
      var el = document.querySelector(hash);
      if (!el) return;
      setTimeout(function () {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.style.transition = 'background 0.6s';
        el.style.background = 'var(--primary-light)';
        setTimeout(function () { el.style.background = ''; }, 2000);
      }, 500);
    }
  };

  var TabFocusIndicator = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = 'body:not(.using-mouse) :focus-visible{outline:2px solid var(--primary)!important;outline-offset:2px!important;border-radius:4px;}' +
        'body:not(.using-mouse) a:focus-visible,body:not(.using-mouse) button:focus-visible,body:not(.using-mouse) input:focus-visible,body:not(.using-mouse) textarea:focus-visible,body:not(.using-mouse) select:focus-visible{outline:2px solid var(--primary)!important;outline-offset:2px!important;}' +
        '.using-mouse :focus{outline:none!important;}';
      document.head.appendChild(style);
      document.body.classList.add('using-mouse');
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Tab') document.body.classList.remove('using-mouse');
      });
      document.addEventListener('mousedown', function () {
        document.body.classList.add('using-mouse');
      });
    }
  };

  var ScrollToSection = {
    init: function () {
      document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href^="#"]');
        if (!link) return;
        var href = link.getAttribute('href');
        if (href === '#' || href === '#!' || href === '#0') return;
        var target = document.querySelector(href);
        if (!target) return;
        e.preventDefault();
        var headerOffset = 70;
        var top = target.getBoundingClientRect().top + window.pageYOffset - headerOffset;
        window.scrollTo({ top: top, behavior: 'smooth' });
        if (history.pushState) history.pushState(null, null, href);
      });
    }
  };

  var MediaDownloadButton = {
    init: function () {
      document.querySelectorAll('.post-card-images img, .post-detail-images img').forEach(function (img) {
        if (img._dlBound) return;
        img._dlBound = true;
        img.addEventListener('contextmenu', function (e) {
          e.preventDefault();
          var btn = document.createElement('button');
          btn.textContent = '下载图片';
          btn.style.cssText = 'position:fixed;z-index:9999;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;padding:8px 16px;cursor:pointer;font-size:0.85rem;box-shadow:0 4px 12px rgba(0,0,0,0.15);color:var(--text);';
          btn.style.left = e.clientX + 'px';
          btn.style.top = e.clientY + 'px';
          document.body.appendChild(btn);
          btn.addEventListener('click', function () {
            var a = document.createElement('a');
            a.href = img.src;
            a.download = img.src.split('/').pop().split('?')[0] || 'image';
            a.click();
            btn.remove();
          });
          document.addEventListener('click', function rm() { btn.remove(); document.removeEventListener('click', rm); }, { once: true });
        });
      });
    }
  };

  var CollapsibleSections = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '.collapsible-header{cursor:pointer;display:flex;align-items:center;justify-content:space-between;user-select:none;}.collapsible-header::after{content:"▼";font-size:0.7rem;transition:transform 0.3s;color:var(--text-muted);}.collapsible-header.collapsed::after{transform:rotate(-90deg);}.collapsible-body{overflow:hidden;transition:max-height 0.3s ease;max-height:2000px;}.collapsible-body.collapsed{max-height:0;}';
      document.head.appendChild(style);
      document.querySelectorAll('.collapsible-header').forEach(function (header) {
        header.addEventListener('click', function () {
          this.classList.toggle('collapsed');
          var body = this.nextElementSibling;
          if (body && body.classList.contains('collapsible-body')) {
            body.classList.toggle('collapsed');
          }
        });
      });
    }
  };

  var AdminQuickActions = {
    init: function () {
      if (!document.body.classList.contains('admin-page')) return;
      var style = document.createElement('style');
      style.textContent = '.admin-quick-bar{position:fixed;bottom:20px;right:20px;z-index:99;display:flex;flex-direction:column;gap:8px;}.admin-quick-btn{width:44px;height:44px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.1rem;box-shadow:0 4px 12px rgba(0,0,0,0.2);transition:all 0.25s;}.admin-quick-btn:hover{transform:scale(1.1);}.admin-quick-btn.primary{background:var(--primary);}.admin-quick-btn.success{background:var(--success);}.admin-quick-btn.warning{background:var(--warning);}.admin-quick-btn.top{background:var(--text-muted);}';
      document.head.appendChild(style);
      var bar = document.createElement('div');
      bar.className = 'admin-quick-bar';
      bar.innerHTML = '<button class="admin-quick-btn primary" title="新建帖子" onclick="window.location.href=\'/pages/post.php\'">' + svgIcon('edit', 18) + '</button>' +
        '<button class="admin-quick-btn success" title="返回首页" onclick="window.location.href=\'/\'">' + svgIcon('home', 18) + '</button>' +
        '<button class="admin-quick-btn top" title="回到顶部" id="admin-back-top">' + svgIcon('chevronUp', 18) + '</button>';
      document.body.appendChild(bar);
      document.getElementById('admin-back-top').addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    }
  };

  var ToastImprovements = {
    init: function () {
      var observer = new MutationObserver(function () {
        var toasts = document.querySelectorAll('.toast');
        if (toasts.length > 3) {
          for (var i = 0; i < toasts.length - 3; i++) {
            toasts[i].style.opacity = '0';
            setTimeout(function (t) { t.remove(); }, 300, toasts[i]);
          }
        }
      });
      var container = document.querySelector('.toast-container');
      if (container) observer.observe(container, { childList: true });
    }
  };

  var ImageLoadPlaceholder = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '.img-placeholder{background:var(--bg-secondary);display:inline-flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:0.8rem;min-height:100px;}.img-loaded{animation:imgFadeIn 0.3s ease;}@keyframes imgFadeIn{from{opacity:0}to{opacity:1}}';
      document.head.appendChild(style);
      if (!window.IntersectionObserver) return;
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          var img = entry.target;
          if (img.dataset.src) {
            img.src = img.dataset.src;
            img.removeAttribute('data-src');
            img.addEventListener('load', function () { img.classList.add('img-loaded'); });
            img.addEventListener('error', function () {
              img.style.display = 'none';
              var placeholder = img.nextElementSibling;
              if (placeholder && placeholder.classList.contains('img-placeholder')) {
                placeholder.textContent = '图片加载失败';
              }
            });
          }
          observer.unobserve(img);
        });
      }, { rootMargin: '200px' });
      document.querySelectorAll('img[data-src]').forEach(function (img) { observer.observe(img); });
    }
  };

  var InfiniteScrollSentinel = {
    init: function () {
      var sentinel = document.getElementById('infiniteScrollSentry');
      if (!sentinel) return;
      var observer = new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting) {
          var event = new CustomEvent('infiniteScrollReached');
          sentinel.dispatchEvent(event);
        }
      }, { rootMargin: '300px' });
      observer.observe(sentinel);
    }
  };

  var MobilePullToRefresh = {
    init: function () {
      if (window.innerWidth > 768) return;
      var container = document.getElementById('postsContainer');
      if (!container) return;
      var startY = 0;
      var pulling = false;
      var indicator = document.createElement('div');
      indicator.className = 'pull-indicator';
      indicator.style.cssText = 'text-align:center;padding:8px;color:var(--text-muted);font-size:0.8rem;display:none;';
      container.parentNode.insertBefore(indicator, container);
      container.addEventListener('touchstart', function (e) {
        if (window.scrollY > 0) return;
        startY = e.touches[0].clientY;
      }, { passive: true });
      container.addEventListener('touchmove', function (e) {
        if (window.scrollY > 0) return;
        var dy = e.touches[0].clientY - startY;
        if (dy > 30) {
          pulling = true;
          indicator.style.display = 'block';
          indicator.textContent = dy > 80 ? '释放刷新' : '下拉刷新...';
        }
      }, { passive: true });
      container.addEventListener('touchend', function () {
        if (!pulling) return;
        pulling = false;
        indicator.textContent = '刷新中...';
        indicator.style.display = 'block';
        if (App.PostFeed) {
          App.PostFeed.loadPosts(true).then(function () {
            indicator.style.display = 'none';
          });
        } else {
          setTimeout(function () { indicator.style.display = 'none'; }, 500);
        }
      });
    }
  };

  var InputClearButton = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '.input-clear-btn{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:24px;height:24px;border:none;background:var(--bg);border-radius:50%;cursor:pointer;display:none;align-items:center;justify-content:center;font-size:0.7rem;color:var(--text-muted);transition:all 0.2s;}.input-clear-btn:hover{background:var(--danger);color:#fff;}.input-clear-wrapper{position:relative;width:100%;flex:1 1 auto;min-width:0;}.input-clear-wrapper input{width:100%;box-sizing:border-box;padding-right:36px;}.input-clear-wrapper .input-clear-btn{position:absolute;display:none;}.input-clear-wrapper input:focus~.input-clear-btn,.input-clear-wrapper input.has-value~.input-clear-btn{display:flex;align-items:center;justify-content:center;}';
      document.head.appendChild(style);
      document.querySelectorAll('input[type="text"], input[type="search"]').forEach(function (input) {
        if (input.closest('.input-clear-wrapper')) return;
        var wrapper = document.createElement('div');
        wrapper.className = 'input-clear-wrapper';
        wrapper.style.position = 'relative';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        var btn = document.createElement('button');
        btn.className = 'input-clear-btn';
        btn.textContent = '✕';
        btn.type = 'button';
        wrapper.appendChild(btn);
        input.addEventListener('input', function () {
          btn.style.display = this.value ? 'flex' : 'none';
        });
        btn.addEventListener('click', function () {
          input.value = '';
          input.focus();
          input.dispatchEvent(new Event('input', { bubbles: true }));
          btn.style.display = 'none';
        });
      });
    }
  };

  var LongPressCopy = {
    init: function () {
      var timer;
      var target;
      document.addEventListener('touchstart', function (e) {
        target = e.target.closest('.copyable, .post-card-content, .comment-content');
        if (!target) return;
        timer = setTimeout(function () {
          var text = target.textContent.trim();
          if (text.length > 20) {
            navigator.clipboard.writeText(text).then(function () {
              if (typeof showToast === 'function') showToast('内容已复制', 'success');
            });
          }
        }, 800);
      }, { passive: true });
      document.addEventListener('touchend', function () { clearTimeout(timer); });
      document.addEventListener('touchmove', function () { clearTimeout(timer); });
    }
  };

  var EmptyStateEnhance = {
    init: function () {
      var style = document.createElement('style');
      style.textContent = '.empty-state-enhanced{text-align:center;padding:32px 16px;}.empty-state-enhanced .ese-icon{font-size:2.5rem;margin-bottom:12px;opacity:0.6;}.empty-state-enhanced .ese-title{font-size:1rem;font-weight:600;color:var(--text);margin-bottom:4px;}.empty-state-enhanced .ese-desc{font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;}.empty-state-enhanced .ese-action{display:inline-block;padding:8px 20px;background:var(--primary);color:#fff;border-radius:20px;text-decoration:none;font-size:0.85rem;transition:all 0.2s;}.empty-state-enhanced .ese-action:hover{background:var(--primary-hover);transform:translateY(-1px);}';
      document.head.appendChild(style);
    }
  };

  // 功能初始化：恢复全部功能模块，确保所有按钮交互可用。
  // 修正前用 fn() 裸调导致模块内 this 丢失（'use strict' 下为 undefined），
  // 使私信/签到/投票/关注等在初始化时静默失败——现统一改为方法调用绑定 this。
  // 每个模块 try/catch 隔离 + requestIdleCallback 延后，兼顾首屏速度与稳定性。
  // 继续停用的仅有：ConfirmDialog（覆盖 window.confirm 强制返回 false，破坏原生确认框）、
  // SearchSuggestions/SearchHistory（搜索推荐已被要求删除）、AutoRefresh（定时自动刷新易致页面抖动）、
  // 以及纯视觉无交互组件（入场动画、水波纹、卡片倾斜、打字机、页面转场、彩蛋等）。
  function safelyInit(label, fn) {
    try { fn(); }
    catch (e) { if (typeof console !== 'undefined' && console.warn) console.warn('[enhancements] ' + label + ' skipped:', e); }
  }

  function runModule(label, mod) {
    if (mod && typeof mod.init === 'function') {
      safelyInit(label, function () { mod.init(); });
    }
  }

  /**
   * 输入联想（C24 @提及 / C25 #话题）—— 一个模块同时服务两种触发符。
   *
   * 为什么用 document 级事件委托而不是逐个绑定：评论框会在帖子详情、信息流、
   * 私信弹窗等处被动态插入/重建，逐个绑定必然漏。委托到 document 后，
   * 「文本框是什么时候出现的」就不再重要。
   *
   * 只挂在「正文类」输入框上（见 SELECTORS），不动搜索框与短表单：
   * @ / # 在正文里才有意义，在验证码、QQ 号这类框里弹出候选只会干扰。
   *
   * 触发规则与渲染规则对齐 includes/text_linkify.php：触发符必须位于行首或空白/括号/标点之后，
   * 这样邮箱 a@b.com、URL 里的 # 都不会误触发 —— 「能弹出来的」和「正文里能变成链接的」是同一批。
   */
  var InputSuggest = {
    _box: null,
    _target: null,
    _items: [],
    _active: -1,
    _range: null,
    _timer: null,
    _seq: 0,

    // 正文类输入框：帖子评论 / 发帖正文 / 私信正文。新增输入框时在页面里加 [data-lw-suggest] 即可接入。
    SELECTORS: ['#commentInput', '#content', '#pmMsgInput', 'textarea[data-lw-suggest]'],

    init: function () {
      var self = this;
      document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el || el.tagName !== 'TEXTAREA' || !self._isTarget(el)) return;
        self._onInput(el);
      });
      document.addEventListener('keydown', function (e) {
        if (!self._box || e.target !== self._target) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); self._move(e.key === 'ArrowDown' ? 1 : -1); }
        else if (e.key === 'Enter' || e.key === 'Tab') { if (self._active >= 0) { e.preventDefault(); self._pick(self._active); } }
        else if (e.key === 'Escape') { self._close(); }
      });
      document.addEventListener('mousedown', function (e) {
        if (!self._box) return;
        if (self._box.contains(e.target) || e.target === self._target) return;
        self._close();
      });
      window.addEventListener('scroll', function () { if (self._box) self._position(); }, true);
      window.addEventListener('resize', function () { if (self._box) self._position(); });
    },

    _isTarget: function (el) {
      if (!el.matches) return false;
      for (var i = 0; i < this.SELECTORS.length; i++) {
        if (el.matches(this.SELECTORS[i])) return true;
      }
      return false;
    },

    _onInput: function (el) {
      var range = this._context(el);
      if (!range) { this._close(); return; }
      this._target = el;
      this._range = range;
      var self = this;
      var seq = ++this._seq;
      clearTimeout(this._timer);
      this._timer = setTimeout(function () { self._fetch(range, seq); }, 180);
    },

    /** 找出光标前是否处在一个未结束的 @xxx / #xxx 里 */
    _context: function (el) {
      var pos = el.selectionStart;
      if (typeof pos !== 'number') return null;
      var before = el.value.slice(0, pos);
      // 触发符前面必须是行首或空白/常见标点，避免 a@b.com、C#、URL#hash 误触发
      var m = before.match(/(^|[\s(（\[【>「,，。;；])([@#])([^\s@#]{0,20})$/);
      if (!m || !m[2]) return null;
      var keyword = m[3];
      if (keyword.length === 0) return null;
      return { trigger: m[2], start: pos - keyword.length - 1, end: pos, keyword: keyword };
    },

    _fetch: async function (range, seq) {
      var url = range.trigger === '@'
        ? '/api/user/list.php?search=' + encodeURIComponent(range.keyword) + '&limit=6'
        : '/api/search/tags.php?q=' + encodeURIComponent(range.keyword) + '&limit=6';
      try {
        var res = await fetchAPI(url);
        if (seq !== this._seq) return;              // 竞态：只认最后一次请求
        if (!res || !res.success) { this._close(); return; }
        var items;
        if (range.trigger === '@') {
          items = (res.users || []).map(function (u) {
            return { type: 'user', label: u.nickname, avatar: u.avatar, sub: '', value: '@' + u.nickname + ' ' };
          });
        } else {
          items = (res.tags || []).map(function (t) {
            return { type: 'tag', label: '#' + t.tag, avatar: '', sub: t.count + ' 条', value: '#' + t.tag + ' ' };
          });
        }
        this._render(items);
      } catch (err) {
        this._close();
      }
    },

    _render: function (items) {
      if (!items.length) { this._close(); return; }
      this._items = items;
      this._active = 0;
      if (!this._box) {
        this._box = document.createElement('div');
        this._box.className = 'lw-suggest';
        this._box.setAttribute('role', 'listbox');
        document.body.appendChild(this._box);
      }
      var self = this;
      this._box.innerHTML = items.map(function (it, i) {
        return '<button type="button" class="lw-suggest-item' + (i === 0 ? ' is-active' : '') + '" role="option" data-i="' + i + '">'
          + (it.avatar
            ? '<img class="lw-suggest-avatar" src="' + escapeHtml(it.avatar) + '" alt="" loading="lazy">'
            : '<span class="lw-suggest-ico">' + svgIcon(it.type === 'tag' ? 'tag' : 'user', 16) + '</span>')
          + '<span class="lw-suggest-label">' + escapeHtml(it.label) + '</span>'
          + (it.sub ? '<span class="lw-suggest-sub">' + escapeHtml(it.sub) + '</span>' : '')
          + '</button>';
      }).join('');
      Array.prototype.forEach.call(this._box.querySelectorAll('.lw-suggest-item'), function (btn) {
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          self._pick(parseInt(btn.getAttribute('data-i'), 10));
        });
      });
      this._box.style.display = 'block';
      this._position();
    },

    _position: function () {
      if (!this._box || !this._target) return;
      var r = this._target.getBoundingClientRect();
      var box = this._box;
      box.style.left = Math.max(8, r.left) + 'px';
      box.style.width = Math.max(220, Math.min(r.width, window.innerWidth - 16)) + 'px';
      var h = box.offsetHeight || 180;
      var below = r.bottom + 6;
      if (below + h > window.innerHeight - 8 && r.top - h - 6 > 8) {
        box.style.top = Math.max(8, r.top - h - 6) + 'px';
      } else {
        box.style.top = below + 'px';
      }
    },

    _move: function (dir) {
      if (!this._items.length || !this._box) return;
      this._active = (this._active + dir + this._items.length) % this._items.length;
      var nodes = this._box.querySelectorAll('.lw-suggest-item');
      for (var i = 0; i < nodes.length; i++) {
        nodes[i].classList.toggle('is-active', i === this._active);
      }
      if (nodes[this._active] && nodes[this._active].scrollIntoView) {
        nodes[this._active].scrollIntoView({ block: 'nearest' });
      }
    },

    _pick: function (i) {
      var it = this._items[i];
      var el = this._target;
      var rg = this._range;
      if (!it || !el || !rg) { this._close(); return; }
      var val = el.value;
      el.value = val.slice(0, rg.start) + it.value + val.slice(rg.end);
      var caret = rg.start + it.value.length;
      try { el.setSelectionRange(caret, caret); } catch (e) {}
      // 通知字数统计 / 草稿自动保存等既有监听器（它们都监听 input）
      try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
      this._close();
      el.focus();
    },

    _close: function () {
      clearTimeout(this._timer);
      if (this._box) { this._box.remove(); this._box = null; }
      this._items = [];
      this._active = -1;
      this._target = null;
      this._range = null;
    }
  };

  /* ================= UX50 新增可见功能模块 ================= */

  // 1) 帖子阅读时长估算：在正文顶部显示「预计阅读 X 分钟 · Y 字」
  var PostReadingTimeEstimate = {
    init: function () {
      var content = document.querySelector('.post-detail-content');
      if (!content) return;
      var text = content.innerText || content.textContent || '';
      var charCount = text.replace(/\s/g, '').length;
      if (charCount < 20) return;
      // 中文阅读速度约 300-400 字/分钟，取 350
      var minutes = Math.max(1, Math.round(charCount / 350));
      var badge = document.createElement('span');
      badge.className = 'post-reading-time-badge';
      badge.textContent = '预计阅读 ' + minutes + ' 分钟 · ' + charCount + ' 字';
      badge.title = '基于中文平均阅读速度估算';
      // 插入到正文最前
      content.insertBefore(badge, content.firstChild);
    }
  };

  // 2) 帖子正文阅读进度条：滚动时显示已读百分比（限定 post-detail-card 范围内）
  var PostContentProgress = {
    init: function () {
      var card = document.querySelector('.post-detail-card');
      var content = document.querySelector('.post-detail-content');
      if (!card || !content) return;
      // 注入样式
      if (!document.getElementById('ux50-progress-style')) {
        var s = document.createElement('style');
        s.id = 'ux50-progress-style';
        s.textContent = '.post-content-progress{position:sticky;top:0;left:0;right:0;height:3px;background:var(--border,#eee);z-index:5;border-radius:0 0 3px 3px;overflow:hidden;}.post-content-progress-inner{height:100%;width:0;background:linear-gradient(90deg,#E2574C,#B8943E);transition:width .1s linear;}.post-content-progress.done .post-content-progress-inner{background:#22c55e;}.post-reading-time-badge{display:inline-block;font-size:0.75rem;color:var(--text-muted,#999);margin-bottom:0.75rem;padding:2px 10px;border-radius:999px;background:var(--bg,#f5f5f5);}.comment-filter-btn{margin-left:0.75rem;font-size:0.75rem;padding:3px 12px;border-radius:999px;border:1px solid var(--border,#ddd);background:var(--bg,#fff);color:var(--text,#333);cursor:pointer;transition:all .2s;}.comment-filter-btn.on{background:var(--primary,#E2574C);color:#fff;border-color:var(--primary,#E2574C);}';
        document.head.appendChild(s);
      }
      var bar = document.createElement('div');
      bar.className = 'post-content-progress';
      bar.innerHTML = '<div class="post-content-progress-inner"></div>';
      card.style.position = card.style.position || 'relative';
      card.appendChild(bar);
      var inner = bar.querySelector('.post-content-progress-inner');
      var ticking = false;
      function update() {
        ticking = false;
        var rect = content.getBoundingClientRect();
        var vh = window.innerHeight;
        var total = rect.height;
        var scrolled = Math.min(Math.max(0, vh - rect.top), total + vh);
        var pct = Math.min(100, Math.max(0, (scrolled / (total + vh * 0.5)) * 100));
        inner.style.width = pct + '%';
        if (pct >= 98) bar.classList.add('done'); else bar.classList.remove('done');
      }
      window.addEventListener('scroll', function () {
        if (!ticking) { requestAnimationFrame(update); ticking = true; }
      }, { passive: true });
      update();
    }
  };

  // 3) 只看楼主：评论区过滤按钮，仅显示帖子作者的评论
  var CommentAuthorOnlyFilter = {
    init: function () {
      var card = document.querySelector('.post-detail-card');
      var commentsList = document.getElementById('commentsList');
      if (!card || !commentsList) return;
      var authorId = parseInt(card.getAttribute('data-author-id') || '0', 10);
      if (!authorId) return; // 匿名帖无楼主概念
      var title = document.querySelector('.comments-title');
      if (!title) return;

      var toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'comment-filter-btn';
      toggle.textContent = '只看楼主';
      toggle.title = '仅显示楼主的评论';
      title.appendChild(toggle);

      var active = false;
      toggle.addEventListener('click', function () {
        active = !active;
        toggle.classList.toggle('on', active);
        toggle.textContent = active ? '显示全部' : '只看楼主';
        commentsList.querySelectorAll('.comment-item').forEach(function (item) {
          var uid = parseInt(item.getAttribute('data-user-id') || '0', 10);
          var isAuthor = uid === authorId;
          if (active) {
            item.style.display = isAuthor ? '' : 'none';
          } else {
            item.style.display = '';
          }
        });
      });
    }
  };

  // 4) 回到评论区悬浮按钮：阅读正文时一键回到评论
  var BackToCommentsFab = {
    init: function () {
      var comments = document.querySelector('.comments-section');
      if (!comments) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'back-to-comments-fab';
      btn.innerHTML = '💬 评论';
      btn.title = '回到评论区';
      btn.style.cssText = 'position:fixed;right:1rem;bottom:5.5rem;z-index:90;display:none;' +
        'padding:0.5rem 0.9rem;border-radius:999px;border:none;cursor:pointer;' +
        'background:var(--primary,#E2574C);color:#fff;font-size:0.8rem;box-shadow:0 4px 14px rgba(0,0,0,.18);' +
        'transition:all .2s;';
      btn.onmouseenter = function () { btn.style.transform = 'translateY(-2px)'; };
      btn.onmouseleave = function () { btn.style.transform = ''; };
      btn.addEventListener('click', function () {
        comments.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      document.body.appendChild(btn);

      var ticking = false;
      window.addEventListener('scroll', function () {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () {
          ticking = false;
          var rect = comments.getBoundingClientRect();
          // 评论区顶部已滚出视口且底部未进入时显示
          btn.style.display = (rect.top < -20 && rect.bottom > window.innerHeight) ? 'block' : 'none';
        });
      }, { passive: true });
    }
  };

  // 5) 帖子卡片阅读时长角标：信息流卡片显示预计阅读时长
  var PostCardReadingTime = {
    init: function () {
      // 卡片多为动态渲染，用委托 + 定时扫描兜底
      function scan() {
        document.querySelectorAll('.post-card[data-reading-time-set="0"], .post-card:not([data-reading-time-set])').forEach(function (card) {
          card.setAttribute('data-reading-time-set', '1');
          var content = card.querySelector('.post-card-content, .post-card-text');
          if (!content) return;
          var text = (content.innerText || content.textContent || '').replace(/\s/g, '');
          if (text.length < 20) return;
          var minutes = Math.max(1, Math.round(text.length / 350));
          var badge = document.createElement('span');
          badge.className = 'post-card-reading-time';
          badge.textContent = minutes + ' 分钟阅读';
          badge.style.cssText = 'display:inline-flex;align-items:center;gap:3px;font-size:0.7rem;' +
            'color:var(--text-muted,#999);margin-right:0.5rem;';
          var meta = card.querySelector('.post-card-meta, .post-card-footer');
          if (meta) meta.insertBefore(badge, meta.firstChild);
          else card.appendChild(badge);
        });
      }
      scan();
      // 监听动态加载
      var mo = new MutationObserver(function () { scan(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  };

  // 6) 评论区楼层快速跳转：显示「楼层导航」下拉，选择后平滑滚动到对应楼层
  var CommentFloorJump = {
    init: function () {
      var commentsList = document.getElementById('commentsList');
      if (!commentsList) return;
      var floors = commentsList.querySelectorAll('.comment-item > .comment-body .comment-floor');
      if (floors.length < 5) return;
      var title = document.querySelector('.comments-title');
      if (!title) return;

      var select = document.createElement('select');
      select.className = 'comment-floor-jump';
      select.style.cssText = 'margin-left:auto;font-size:0.75rem;padding:2px 6px;border-radius:6px;' +
        'border:1px solid var(--border,#ddd);background:var(--bg,#fff);color:var(--text,#333);';
      var opt0 = document.createElement('option');
      opt0.value = ''; opt0.textContent = '跳转到楼层...';
      select.appendChild(opt0);
      floors.forEach(function (f) {
        var opt = document.createElement('option');
        var text = (f.textContent || '').trim();
        opt.value = f.closest('.comment-item').id;
        opt.textContent = text;
        select.appendChild(opt);
      });
      select.addEventListener('change', function () {
        if (!this.value) return;
        var el = document.getElementById(this.value);
        if (el) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
          el.style.transition = 'background .6s';
          el.style.background = 'rgba(226,87,76,0.12)';
          setTimeout(function () { el.style.background = ''; }, 1200);
        }
        this.value = '';
      });
      title.appendChild(select);
    }
  };

  // 7) 复制代码块按钮增强：对 <pre><code> 自动注入一键复制
  var CodeBlockCopyButton = {
    init: function () {
      var blocks = document.querySelectorAll('pre');
      if (!blocks.length) return;
      blocks.forEach(function (pre) {
        if (pre.querySelector('.code-copy-btn')) return;
        var code = pre.querySelector('code');
        if (!code) return;
        pre.style.position = 'relative';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'code-copy-btn';
        btn.textContent = '复制';
        btn.style.cssText = 'position:absolute;top:6px;right:8px;font-size:0.7rem;padding:2px 8px;' +
          'border-radius:4px;border:1px solid rgba(255,255,255,.3);background:rgba(0,0,0,.35);' +
          'color:#fff;cursor:pointer;opacity:0;transition:opacity .2s;';
        pre.onmouseenter = function () { btn.style.opacity = '1'; };
        pre.onmouseleave = function () { btn.style.opacity = '0'; };
        btn.addEventListener('click', function () {
          var text = code.innerText || code.textContent;
          if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
              btn.textContent = '已复制'; setTimeout(function () { btn.textContent = '复制'; }, 1500);
            });
          }
        });
        pre.appendChild(btn);
      });
    }
  };

  // 8) 评论排序切换：按时间 / 按热度（点赞数）客户端重排
  var CommentSortToggle = {
    init: function () {
      var list = document.getElementById('commentsList');
      var title = document.querySelector('.comments-title');
      if (!list || !title) return;
      var wrap = document.createElement('span');
      wrap.className = 'comment-sort-toggle';
      wrap.style.cssText = 'margin-left:0.6rem;font-size:0.75rem;display:inline-flex;gap:4px;';
      var btnTime = document.createElement('button');
      btnTime.type = 'button';
      btnTime.textContent = '按时间';
      btnTime.className = 'cs-btn on';
      var btnHot = document.createElement('button');
      btnHot.type = 'button';
      btnHot.textContent = '按热度';
      btnHot.className = 'cs-btn';
      var btnCss = 'padding:2px 10px;border-radius:999px;border:1px solid var(--border,#ddd);background:var(--bg,#fff);color:var(--text-muted,#999);cursor:pointer;transition:all .2s;';
      btnTime.style.cssText = btnCss;
      btnHot.style.cssText = btnCss;
      function setOn(active) {
        btnTime.classList.toggle('on', active === 'time');
        btnHot.classList.toggle('on', active === 'hot');
        var onCss = 'background:var(--primary,#E2574C);color:#fff;border-color:var(--primary,#E2574C);';
        btnTime.style.cssText = btnCss + (active === 'time' ? onCss : '');
        btnHot.style.cssText = btnCss + (active === 'hot' ? onCss : '');
      }
      function sortComments(mode) {
        var items = Array.from(list.querySelectorAll(':scope > .comment-item'));
        if (mode === 'hot') {
          items.sort(function (a, b) {
            var ra = parseInt((a.getAttribute('data-reply-count') || '0'), 10);
            var rb = parseInt((b.getAttribute('data-reply-count') || '0'), 10);
            return rb - ra;
          });
        } else {
          items.sort(function (a, b) {
            var ta = a.getAttribute('data-created-at') || '';
            var tb = b.getAttribute('data-created-at') || '';
            return ta.localeCompare(tb);
          });
        }
        items.forEach(function (it) { list.appendChild(it); });
      }
      btnTime.addEventListener('click', function () { setOn('time'); sortComments('time'); });
      btnHot.addEventListener('click', function () { setOn('hot'); sortComments('hot'); });
      wrap.appendChild(btnTime);
      wrap.appendChild(btnHot);
      title.appendChild(wrap);
      setOn('time');
    }
  };

  // 9) 评论输入快捷工具栏：@提及 / #话题 / 😀表情 一键插入
  var CommentInputToolbar = {
    init: function () {
      var ta = document.getElementById('commentInput') || document.querySelector('.comment-form textarea');
      if (!ta) return;
      var toolbar = document.createElement('div');
      toolbar.className = 'comment-input-toolbar';
      toolbar.style.cssText = 'display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;';
      var btns = [
        { t: '@', v: '@', tip: '提及用户' },
        { t: '#', v: '#', tip: '话题标签' },
        { t: '😀', v: '😀', tip: '笑脸' },
        { t: '❤️', v: '❤️', tip: '爱心' },
        { t: '👍', v: '👍', tip: '点赞' },
        { t: '🎉', v: '🎉', tip: '庆祝' }
      ];
      btns.forEach(function (b) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = b.t;
        btn.title = b.tip;
        btn.style.cssText = 'padding:3px 10px;border-radius:6px;border:1px solid var(--border,#ddd);background:var(--bg,#fff);cursor:pointer;font-size:0.85rem;transition:all .15s;';
        btn.onmouseenter = function () { btn.style.background = 'var(--primary,#E2574C)'; btn.style.color = '#fff'; };
        btn.onmouseleave = function () { btn.style.background = 'var(--bg,#fff)'; btn.style.color = ''; };
        btn.addEventListener('click', function () {
          var start = ta.selectionStart || ta.value.length;
          var end = ta.selectionEnd || ta.value.length;
          ta.value = ta.value.slice(0, start) + b.v + ta.value.slice(end);
          ta.focus();
          ta.selectionStart = ta.selectionEnd = start + b.v.length;
          ta.dispatchEvent(new Event('input', { bubbles: true }));
        });
        toolbar.appendChild(btn);
      });
      ta.parentNode.insertBefore(toolbar, ta.nextSibling);
    }
  };

  // 10) 帖子图片一键下载：灯箱或图片上添加下载按钮
  var PostImageDownload = {
    init: function () {
      var imgs = document.querySelectorAll('.post-images img, .post-card-images img, .post-detail-content img');
      if (!imgs.length) return;
      imgs.forEach(function (img) {
        if (img.parentElement && img.parentElement.classList.contains('post-image-dl-wrap')) return;
        img.style.cursor = 'zoom-in';
      });
      // 监听图片点击，在灯箱外提供下载
      document.addEventListener('click', function (e) {
        var img = e.target.closest('.post-images img, .post-card-images img, .post-detail-content img');
        if (!img) return;
        // 不阻止原有灯箱，只在长按或右键提示下载
      });
      // 给帖子详情图片加下载角标
      document.querySelectorAll('.post-detail-content .post-images img').forEach(function (img) {
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;display:inline-block;';
        img.parentNode.insertBefore(wrap, img);
        wrap.appendChild(img);
        var dl = document.createElement('a');
        dl.href = img.src;
        dl.download = '';
        dl.target = '_blank';
        dl.rel = 'noopener';
        dl.textContent = '⬇ 下载';
        dl.style.cssText = 'position:absolute;bottom:6px;right:6px;font-size:0.7rem;padding:2px 8px;' +
          'background:rgba(0,0,0,.6);color:#fff;border-radius:4px;text-decoration:none;opacity:0;transition:opacity .2s;';
        wrap.onmouseenter = function () { dl.style.opacity = '1'; };
        wrap.onmouseleave = function () { dl.style.opacity = '0'; };
        wrap.appendChild(dl);
      });
    }
  };

  // 11) 字数统计实时显示：发布页 / 评论框显示当前字数 / 上限
  var LiveCharCounter = {
    init: function () {
      var targets = [
        { el: document.getElementById('content'), max: 5000 },
        { el: document.getElementById('commentInput'), max: 500 }
      ];
      targets.forEach(function (t) {
        if (!t.el) return;
        var counter = document.createElement('div');
        counter.className = 'char-counter';
        counter.style.cssText = 'text-align:right;font-size:0.7rem;color:var(--text-muted,#999);margin-top:2px;';
        t.el.parentNode.insertBefore(counter, t.el.nextSibling);
        function update() {
          var len = t.el.value.length;
          counter.textContent = len + ' / ' + t.max;
          counter.style.color = len > t.max ? '#ef4444' : len > t.max * 0.9 ? '#f59e0b' : '';
        }
        t.el.addEventListener('input', update);
        update();
      });
    }
  };

  // 12) 帖子详情目录导航（TOC）：从正文 H2/H3 抽取，生成侧边/顶部目录
  var PostTOC = {
    init: function () {
      var content = document.querySelector('.post-detail-content');
      if (!content) return;
      var headings = content.querySelectorAll('h2, h3');
      if (headings.length < 3) return;
      var toc = document.createElement('nav');
      toc.className = 'post-toc';
      toc.style.cssText = 'background:var(--bg,#f8f8f8);border-radius:12px;padding:12px 16px;margin-bottom:1rem;border-left:3px solid var(--primary,#E2574C);';
      toc.innerHTML = '<div style="font-weight:600;font-size:0.85rem;margin-bottom:8px;">📑 本文目录</div>';
      var list = document.createElement('ul');
      list.style.cssText = 'margin:0;padding-left:1rem;line-height:1.9;font-size:0.8rem;';
      headings.forEach(function (h, i) {
        var id = 'post-toc-' + i;
        h.id = id;
        var li = document.createElement('li');
        var a = document.createElement('a');
        a.href = '#' + id;
        a.textContent = h.textContent;
        a.style.cssText = 'color:var(--text,#333);text-decoration:none;';
        a.onmouseenter = function () { a.style.color = 'var(--primary,#E2574C)'; };
        a.onmouseleave = function () { a.style.color = ''; };
        a.addEventListener('click', function (e) {
          e.preventDefault();
          h.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        if (h.tagName === 'H3') li.style.paddingLeft = '1rem';
        li.appendChild(a);
        list.appendChild(li);
      });
      toc.appendChild(list);
      content.insertBefore(toc, content.firstChild);
    }
  };

  // 13) 页面访问时长统计：底部显示「你已在本站停留 X 分钟」
  var SessionTimeBadge = {
    init: function () {
      var footer = document.querySelector('.site-footer');
      if (!footer) return;
      var start = Date.now();
      var badge = document.createElement('div');
      badge.className = 'session-time-badge';
      badge.style.cssText = 'font-size:0.7rem;color:var(--text-muted,#aaa);margin-top:4px;';
      footer.appendChild(badge);
      function update() {
        var mins = Math.floor((Date.now() - start) / 60000);
        badge.textContent = mins > 0 ? '你已停留 ' + mins + ' 分钟，感谢陪伴 🌸' : '';
      }
      setInterval(update, 60000);
      update();
    }
  };

  // 14) 楼主徽章：在评论区给帖子作者的评论加「楼主」标记
  var CommentAuthorBadge = {
    init: function () {
      var card = document.querySelector('.post-detail-card');
      if (!card) return;
      var authorId = parseInt(card.getAttribute('data-author-id') || '0', 10);
      if (!authorId) return;
      document.querySelectorAll('#commentsList .comment-item').forEach(function (item) {
        var uid = parseInt(item.getAttribute('data-user-id') || '0', 10);
        if (uid !== authorId) return;
        var header = item.querySelector('.comment-header');
        if (!header || header.querySelector('.op-badge')) return;
        var badge = document.createElement('span');
        badge.className = 'op-badge';
        badge.textContent = '楼主';
        badge.style.cssText = 'font-size:0.65rem;padding:1px 6px;border-radius:4px;' +
          'background:var(--primary,#E2574C);color:#fff;font-weight:600;margin-left:4px;';
        var author = header.querySelector('.comment-author');
        if (author) author.parentNode.insertBefore(badge, author.nextSibling);
        else header.appendChild(badge);
      });
    }
  };

  // 15) 稍后再看：localStorage 收藏帖子，卡片上加书签按钮
  var SavedPostsLater = {
    init: function () {
      var KEY = 'lw_saved_posts';
      function getSaved() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
      function setSaved(arr) { localStorage.setItem(KEY, JSON.stringify(arr)); }
      function isSaved(id) { return getSaved().indexOf(String(id)) !== -1; }
      function toggle(id) {
        var arr = getSaved();
        var i = arr.indexOf(String(id));
        if (i === -1) { arr.push(String(id)); setSaved(arr); return true; }
        arr.splice(i, 1); setSaved(arr); return false;
      }
      // 给帖子卡片加书签按钮
      function injectButtons() {
        document.querySelectorAll('.post-card[data-post-id]').forEach(function (card) {
          if (card.querySelector('.save-later-btn')) return;
          var id = card.getAttribute('data-post-id');
          var actions = card.querySelector('.post-card-actions');
          if (!actions) return;
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'action-btn save-later-btn';
          btn.title = '稍后再看';
          btn.style.cssText = 'background:none;border:none;cursor:pointer;font-size:1rem;padding:4px;';
          btn.textContent = isSaved(id) ? '🔖' : '📑';
          btn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            var saved = toggle(id);
            btn.textContent = saved ? '🔖' : '📑';
            if (typeof showToast === 'function') showToast(saved ? '已加入稍后再看' : '已移出稍后再看', saved ? 'success' : 'info');
          });
          actions.insertBefore(btn, actions.firstChild);
        });
      }
      injectButtons();
      var mo = new MutationObserver(injectButtons);
      mo.observe(document.body, { childList: true, subtree: true });
    }
  };

  // 16) 帖子卡片首图懒加载占位 + 加载失败兜底（强化版）
  var PostImageFallback = {
    init: function () {
      document.querySelectorAll('img').forEach(function (img) {
        if (img.dataset.fbSet) return;
        img.dataset.fbSet = '1';
        img.addEventListener('error', function () {
          if (!img.dataset.fallback) {
            img.dataset.fallback = '1';
            img.src = '/assets/images/default-avatar.svg';
          }
        });
      });
    }
  };

  // 17) 搜索历史：localStorage 记录最近 8 条搜索，聚焦时下拉显示
  var SearchHistoryLocal = {
    init: function () {
      var input = document.getElementById('searchInput');
      if (!input) return;
      var KEY = 'lw_search_history';
      function get() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
      function set(arr) { localStorage.setItem(KEY, JSON.stringify(arr.slice(0, 8))); }
      function add(q) {
        q = q.trim(); if (!q) return;
        var arr = get().filter(function (x) { return x !== q; });
        arr.unshift(q); set(arr);
      }
      var box = input.closest('.search-box');
      if (!box) return;
      box.style.position = 'relative';
      var panel = document.createElement('div');
      panel.className = 'search-history-panel';
      panel.style.cssText = 'position:absolute;top:100%;left:0;right:0;margin-top:4px;background:var(--card-bg,#fff);' +
        'border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:8px 0;z-index:50;display:none;max-height:280px;overflow-y:auto;';
      box.appendChild(panel);

      function render() {
        var arr = get();
        if (arr.length === 0) { panel.style.display = 'none'; return; }
        panel.innerHTML = '<div style="padding:6px 14px;font-size:0.7rem;color:var(--text-muted,#999);display:flex;justify-content:space-between;align-items:center;">' +
          '<span>搜索历史</span><button class="sh-clear" style="background:none;border:none;color:var(--text-muted,#999);cursor:pointer;font-size:0.7rem;">清空</button></div>';
        arr.forEach(function (q) {
          var item = document.createElement('div');
          item.style.cssText = 'padding:8px 14px;cursor:pointer;font-size:0.85rem;color:var(--text,#333);display:flex;align-items:center;gap:8px;';
          item.innerHTML = '<span style="opacity:.5;">🕘</span><span>' + escapeHtml(q) + '</span>';
          item.addEventListener('click', function () { input.value = q; input.dispatchEvent(new Event('input', { bubbles: true })); panel.style.display = 'none'; });
          item.onmouseenter = function () { item.style.background = 'var(--bg,#f5f5f5)'; };
          item.onmouseleave = function () { item.style.background = ''; };
          panel.appendChild(item);
        });
        panel.querySelector('.sh-clear').addEventListener('click', function () { set([]); render(); });
        panel.style.display = 'block';
      }
      input.addEventListener('focus', render);
      input.addEventListener('blur', function () { setTimeout(function () { panel.style.display = 'none'; }, 200); });
      // 拦截搜索提交，保存历史
      var form = input.closest('form');
      if (form) {
        form.addEventListener('submit', function () { add(input.value); });
      }
      var btn = document.getElementById('searchBtn');
      if (btn) btn.addEventListener('click', function () { add(input.value); });
    }
  };

  // 18) 评论框/发布框自适应高度
  var TextareaAutoGrow = {
    init: function () {
      document.querySelectorAll('textarea').forEach(function (ta) {
        if (ta.dataset.autoGrow) return;
        ta.dataset.autoGrow = '1';
        function grow() {
          ta.style.height = 'auto';
          ta.style.height = Math.min(ta.scrollHeight, 400) + 'px';
        }
        ta.addEventListener('input', grow);
        grow();
      });
    }
  };

  // 19) 帖子卡片悬浮微交互：hover 上浮 + 阴影增强
  var PostCardHoverLift = {
    init: function () {
      if (!document.getElementById('postsContainer')) return;
      var style = document.createElement('style');
      style.textContent = '.post-card{transition:transform .2s ease,box-shadow .2s ease;}.post-card:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(0,0,0,.1);}';
      document.head.appendChild(style);
    }
  };

  // 20) 滚动时顶栏毛玻璃效果增强
  var HeaderGlassOnScroll = {
    init: function () {
      var header = document.querySelector('.site-header');
      if (!header) return;
      var style = document.createElement('style');
      style.textContent = '.site-header{transition:background .3s,backdrop-filter .3s,box-shadow .3s;}.site-header.scrolled{background:var(--header-glass,rgba(255,255,255,.85));backdrop-filter:saturate(180%) blur(16px);-webkit-backdrop-filter:saturate(180%) blur(16px);box-shadow:0 2px 12px rgba(0,0,0,.06);}';
      document.head.appendChild(style);
      var ticking = false;
      window.addEventListener('scroll', function () {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () {
          ticking = false;
          header.classList.toggle('scrolled', window.scrollY > 10);
        });
      }, { passive: true });
      header.classList.toggle('scrolled', window.scrollY > 10);
    }
  };

  /* ================= UX50 第五批：4 个可见功能模块 ================= */

  // 19) 列表已读标记：点击进入过的帖子在列表里淡化 + 「已读」角标，可一键清除
  var FeedReadMark = {
    init: function () {
      var KEY = 'lw_read_posts';
      var CAP = 300;
      function get() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
      function set(a) { try { localStorage.setItem(KEY, JSON.stringify(a.slice(-CAP))); } catch (e) {} }
      function has(id) { return get().indexOf(String(id)) !== -1; }
      function mark(id) {
        if (!id) return;
        var a = get();
        if (a.indexOf(String(id)) === -1) { a.push(String(id)); set(a); }
      }

      function refreshChip() {
        var tabs = document.querySelector('.sort-tabs');
        if (!tabs) return;
        var n = get().length;
        var chip = document.getElementById('lwReadChip');
        if (!n) { if (chip && chip.parentNode) chip.parentNode.removeChild(chip); return; }
        if (!chip) {
          chip = document.createElement('button');
          chip.type = 'button';
          chip.id = 'lwReadChip';
          chip.className = 'sort-tab lw-read-chip-btn';
          chip.addEventListener('click', function () {
            set([]);
            paint();
            if (typeof showToast === 'function') showToast('已清除全部已读标记', 'info');
          });
          tabs.appendChild(chip);
        }
        chip.textContent = '清除已读 ' + n;
      }

      function paint() {
        var cards = document.querySelectorAll('.post-card[data-post-id]');
        if (cards.length) {
          cards.forEach(function (card) {
            var id = card.getAttribute('data-post-id');
            var read = has(id);
            card.classList.toggle('lw-read', read);
            var chip = card.querySelector('.lw-read-chip');
            if (read && !chip) {
              chip = document.createElement('span');
              chip.className = 'lw-read-chip';
              chip.textContent = '已读';
              var host = card.querySelector('.post-card-header') || card.querySelector('.post-meta') || card;
              host.appendChild(chip);
            } else if (!read && chip && chip.parentNode) {
              chip.parentNode.removeChild(chip);
            }
          });
        }
        refreshChip();
      }

      var queued = false;
      function schedulePaint() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () { queued = false; paint(); });
      }

      // 点进详情即视为已读（捕获阶段，避免被卡片自身的跳转逻辑吞掉）
      document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;
        var link = t.closest('.post-card a[href*="post_detail"]');
        if (!link) return;
        var card = link.closest('.post-card[data-post-id]');
        if (card) mark(card.getAttribute('data-post-id'));
      }, true);

      schedulePaint();
      window.addEventListener('pageshow', schedulePaint);
      new MutationObserver(schedulePaint).observe(document.body, { childList: true, subtree: true });

      var style = document.createElement('style');
      style.textContent =
        '.lw-read{opacity:.6;transition:opacity .25s ease;}' +
        '.lw-read:hover,.lw-read:focus-within{opacity:1;}' +
        '.lw-read-chip{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;font-size:.66rem;' +
        'background:var(--bg-secondary,#EEF0F3);color:var(--text-muted,#8E9AA8);vertical-align:middle;}' +
        '.lw-read-chip-btn{font-size:.74rem;}';
      document.head.appendChild(style);

      if (App) { App.FeedReadMark = { mark: mark, clear: function () { set([]); paint(); }, list: get }; }
    }
  };

  // 20) 评论直达链接：每条评论可复制「#comment-id」深链；打开带锚点的页面自动定位高亮
  var CommentCopyLink = {
    init: function () {
      function inject() {
        var items = document.querySelectorAll('.comment-item[id^="comment-"]');
        if (!items.length) return;
        items.forEach(function (item) {
          if (item.querySelector('.lw-comment-link-btn')) return;
          var actions = item.querySelector('.comment-actions');
          if (!actions) return;
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'lw-comment-link-btn';
          btn.textContent = '复制链接';
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var url = location.origin + location.pathname + '#' + item.id;
            var done = function () {
              if (typeof showToast === 'function') showToast('评论链接已复制', 'success');
            };
            if (App && App.copyToClipboard) {
              Promise.resolve(App.copyToClipboard(url)).then(done, done);
            } else if (navigator.clipboard) {
              navigator.clipboard.writeText(url).then(done, done);
            } else {
              done();
            }
          });
          actions.appendChild(btn);
        });
      }

      function locate() {
        if (!location.hash || !/^#comment-\d+$/.test(location.hash)) return;
        var el = document.querySelector(location.hash);
        if (!el) return;
        el.classList.add('lw-comment-target');
        setTimeout(function () {
          if (el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 300);
      }

      inject();
      locate();
      new MutationObserver(inject).observe(document.body, { childList: true, subtree: true });
      window.addEventListener('hashchange', locate);

      var style = document.createElement('style');
      style.textContent =
        '.lw-comment-link-btn{background:none;border:0;padding:0 6px;font-size:.76rem;color:var(--text-muted,#8E9AA8);' +
        'cursor:pointer;}' +
        '.lw-comment-link-btn:hover{color:var(--primary,#2F5B9A);text-decoration:underline;}' +
        '.lw-comment-target{animation:lwCommentFlash 1.6s ease;border-radius:12px;}' +
        '@keyframes lwCommentFlash{0%{background:var(--primary-light,#E7EEF7);}100%{background:transparent;}}';
      document.head.appendChild(style);
    }
  };

  // 21) 通知面板「全部 / 未读」筛选：一眼只看未读，读过的可一键折叠
  var NotificationUnreadFilter = {
    init: function () {
      var mode = 'all';

      function apply(panel) {
        panel.querySelectorAll('.notification-item').forEach(function (it) {
          var unread = it.classList.contains('unread');
          it.style.display = (mode === 'unread' && !unread) ? 'none' : '';
        });
        panel.querySelectorAll('.lw-notif-filter').forEach(function (b) {
          b.classList.toggle('active', b.getAttribute('data-mode') === mode);
        });
      }

      function build(panel) {
        var head = panel.querySelector('.notification-panel-header');
        if (!head) return;
        if (!head.querySelector('.lw-notif-filter')) {
          var wrap = document.createElement('div');
          wrap.className = 'lw-notif-filters';
          [['all', '全部'], ['unread', '未读']].forEach(function (pair) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'lw-notif-filter';
            b.setAttribute('data-mode', pair[0]);
            b.textContent = pair[1];
            b.addEventListener('click', function (e) {
              e.preventDefault();
              e.stopPropagation();
              mode = pair[0];
              apply(panel);
            });
            wrap.appendChild(b);
          });
          head.appendChild(wrap);
        }
        apply(panel);
      }

      var listObserver = null;
      function hook(panel) {
        build(panel);
        var list = panel.querySelector('.notification-panel-list');
        if (list && !listObserver) {
          listObserver = new MutationObserver(function () { build(panel); });
          listObserver.observe(list, { childList: true, subtree: true });
        }
      }

      var panel = document.querySelector('.notification-panel');
      if (panel) { hook(panel); return; }
      // 面板由 NotificationSystem 动态创建，等它出现
      var bodyObserver = new MutationObserver(function () {
        var p = document.querySelector('.notification-panel');
        if (p) { hook(p); bodyObserver.disconnect(); }
      });
      bodyObserver.observe(document.body, { childList: true, subtree: true });

      var style = document.createElement('style');
      style.textContent =
        '.lw-notif-filters{display:inline-flex;gap:6px;margin-left:auto;}' +
        '.lw-notif-filter{background:none;border:1px solid var(--border,#E3E7EC);color:var(--text-secondary,#5B6B7B);' +
        'font-size:.7rem;padding:2px 9px;border-radius:999px;cursor:pointer;}' +
        '.lw-notif-filter.active{background:var(--primary,#2F5B9A);border-color:var(--primary,#2F5B9A);color:#fff;}';
      document.head.appendChild(style);
    }
  };

  /* ================= /UX50 新增模块 ================= */

  function initNewFeatures() {
    runModule('ReadingProgress', ReadingProgress);
    runModule('FollowSystem', FollowSystem);
    runModule('KeyboardShortcuts', KeyboardShortcuts);
    runModule('GlyphShortcuts', GlyphShortcuts);
    runModule('InputSuggest', InputSuggest);
    runModule('SmartTimeDisplay', SmartTimeDisplay);
    runModule('CommentFloor', CommentFloor);
    runModule('QuickReplyTemplates', QuickReplyTemplates);
    runModule('SelectionShare', SelectionShare);
    runModule('DailyCheckIn', DailyCheckIn);
    runModule('FeatureVote', FeatureVote);
    runModule('PrivateMessage', PrivateMessage);
    runModule('RandomPost', RandomPost);
    runModule('DynamicFooterYear', DynamicFooterYear);
    runModule('DoubleTapToTop', DoubleTapToTop);
    runModule('NewCommentSlide', NewCommentSlide);
    runModule('OfflineIndicator', OfflineIndicator);
    runModule('SwipeCategories', SwipeCategories);
    runModule('CommentCharCounter', CommentCharCounter);
    runModule('EmojiReactions', EmojiReactions);
    // 返回顶部按钮已统一收敛到 polish.js 的 .lw-fab，这里不再注入任何回顶控件
    runModule('ImageLazyLoad', ImageLazyLoad);
    runModule('TitleUnreadCount', TitleUnreadCount);
    runModule('LongPressPreview', LongPressPreview);
    // 沉浸式阅读悬浮按钮已按要求移除：原按钮与返回顶部按钮位置重叠
    runModule('LiveTimestamp', LiveTimestamp);
    runModule('HoverCard', HoverCard);
    runModule('PostPreviewCard', PostPreviewCard);
    runModule('RichText', RichText);
    runModule('PostShare', PostShare);
    runModule('DragDropUpload', DragDropUpload);
    runModule('PostCollapse', PostCollapse);
    runModule('ContextMenu', ContextMenu);
    runModule('ScrollMemory', ScrollMemory);
    runModule('NotificationSound', NotificationSound);
    runModule('PostHistory', PostHistory);
    runModule('EmojiPicker', EmojiPicker);
    runModule('CommentSort', CommentSort);
    runModule('UserStatus', UserStatus);
    runModule('ScrollToComment', ScrollToComment);
    runModule('CapsLockWarning', CapsLockWarning);
    runModule('ImageZoom', ImageZoom);
    runModule('PostStatistics', PostStatistics);
    runModule('TextExpander', TextExpander);
    runModule('PostBookmark', PostBookmark);
    runModule('PostFilter', PostFilter);
    runModule('MarkdownPreview', MarkdownPreview);
    runModule('PostRating', PostRating);
    runModule('UserBadge', UserBadge);
    runModule('CommentNest', CommentNest);
    runModule('KeyboardNav', KeyboardNav);
    runModule('PrintStyles', PrintStyles);
    runModule('CodeHighlight', CodeHighlight);
    runModule('PostTemplate', PostTemplate);
    runModule('AutoSaveAllForms', AutoSaveAllForms);
    runModule('ScrollSpy', ScrollSpy);
    runModule('TabPersist', TabPersist);
    runModule('CookieConsent', CookieConsent);
    runModule('PWAInstall', PWAInstall);
    runModule('PostTrending', PostTrending);

    runModule('ErrorBoundary', ErrorBoundary);
    runModule('LazyComponent', LazyComponent);
    runModule('BreadcrumbEnhance', BreadcrumbEnhance);
    runModule('MobileGesture', MobileGesture);
    runModule('CardSwipe', CardSwipe);
    runModule('PostReactionSummary', PostReactionSummary);
    runModule('CommentTimeTravel', CommentTimeTravel);
    runModule('UserLevelProgress', UserLevelProgress);
    runModule('PostVisibility', PostVisibility);
    runModule('SyntaxHighlight', SyntaxHighlight);
    runModule('ImageCaption', ImageCaption);
    runModule('PollSystem', PollSystem);
    runModule('PageLoadProgress', PageLoadProgress);
    runModule('AutoFocusInput', AutoFocusInput);
    runModule('PasteImageUpload', PasteImageUpload);
    runModule('FloatingSelectionToolbar', FloatingSelectionToolbar);
    runModule('NotificationBadge', NotificationBadge);
    runModule('CommentQuoteReply', CommentQuoteReply);
    runModule('FormDraftSave', FormDraftSave);
    runModule('TableRowHighlight', TableRowHighlight);
    runModule('InputValidation', InputValidation);
    runModule('LoadingSkeleton', LoadingSkeleton);
    runModule('MobileNavBar', MobileNavBar);
    runModule('SmoothScrollAnchors', SmoothScrollAnchors);
    runModule('ExternalLinkConfirm', ExternalLinkConfirm);
    runModule('CopyCodeButton', CopyCodeButton);
    runModule('PasswordStrengthMeter', PasswordStrengthMeter);
    runModule('FormShakeOnError', FormShakeOnError);
    runModule('SessionExpiryWarning', SessionExpiryWarning);
    runModule('ResponsiveTableScroll', ResponsiveTableScroll);
    runModule('StepProgressIndicator', StepProgressIndicator);
    runModule('InputFloatingLabel', InputFloatingLabel);
    runModule('ScrollNavigationDots', ScrollNavigationDots);
    runModule('QuickJumpMenu', QuickJumpMenu);
    runModule('PostWordCounter', PostWordCounter);
    runModule('UserProfileCard', UserProfileCard);
    runModule('AutoSaveIndicator', AutoSaveIndicator);
    runModule('TextSelectionActions', TextSelectionActions);
    runModule('CommentHighlightNav', CommentHighlightNav);
    runModule('TabFocusIndicator', TabFocusIndicator);
    runModule('ScrollToSection', ScrollToSection);
    runModule('MediaDownloadButton', MediaDownloadButton);
    runModule('CollapsibleSections', CollapsibleSections);
    runModule('AdminQuickActions', AdminQuickActions);
    runModule('ToastImprovements', ToastImprovements);
    runModule('ImageLoadPlaceholder', ImageLoadPlaceholder);
    runModule('InfiniteScrollSentinel', InfiniteScrollSentinel);
    runModule('InputClearButton', InputClearButton);
    runModule('LongPressCopy', LongPressCopy);
    runModule('EmptyStateEnhance', EmptyStateEnhance);
    // —— 以下三个模块此前已实现但漏注册（死代码），现统一接线 ——
    runModule('QuickSearch', QuickSearch);                 // Ctrl+K 全局搜索浮层
    runModule('DoubleClickLike', DoubleClickLike);         // 双击卡片点赞 + 飘心
    runModule('FavoriteHeartAnimation', FavoriteHeartAnimation); // 点赞/收藏按钮飘心动画
    // —— UX50 新增 7 个可见功能模块 ——
    runModule('PostReadingTimeEstimate', PostReadingTimeEstimate);   // 正文阅读时长角标
    runModule('PostContentProgress', PostContentProgress);           // 正文阅读进度条
    runModule('CommentAuthorOnlyFilter', CommentAuthorOnlyFilter);   // 只看楼主
    runModule('BackToCommentsFab', BackToCommentsFab);               // 回到评论区悬浮按钮
    runModule('PostCardReadingTime', PostCardReadingTime);           // 卡片阅读时长角标
    runModule('CommentFloorJump', CommentFloorJump);                 // 楼层快速跳转
    runModule('CodeBlockCopyButton', CodeBlockCopyButton);           // 代码块一键复制
    // —— UX50 第二批 6 个可见功能模块 ——
    runModule('CommentSortToggle', CommentSortToggle);               // 评论排序（时间/热度）
    runModule('CommentInputToolbar', CommentInputToolbar);           // 评论输入快捷工具栏
    runModule('PostImageDownload', PostImageDownload);               // 帖子图片一键下载
    runModule('LiveCharCounter', LiveCharCounter);                   // 实时字数统计
    runModule('PostTOC', PostTOC);                                   // 长文目录导航
    runModule('SessionTimeBadge', SessionTimeBadge);                 // 访问停留时长
    // —— UX50 第三批 4 个可见功能模块 ——
    runModule('CommentAuthorBadge', CommentAuthorBadge);             // 楼主徽章
    runModule('SavedPostsLater', SavedPostsLater);                   // 稍后再看（localStorage）
    runModule('PostImageFallback', PostImageFallback);               // 图片加载失败兜底
    // —— UX50 第四批 4 个可见功能模块 ——
    runModule('SearchHistoryLocal', SearchHistoryLocal);             // 搜索历史下拉
    runModule('TextareaAutoGrow', TextareaAutoGrow);                 // 文本框自适应高度
    runModule('PostCardHoverLift', PostCardHoverLift);               // 卡片悬浮上浮动效
    runModule('HeaderGlassOnScroll', HeaderGlassOnScroll);           // 顶栏滚动毛玻璃
    // —— UX50 第五批 4 个可见功能模块 ——
    runModule('FeedReadMark', FeedReadMark);                          // 列表已读标记
    runModule('CommentCopyLink', CommentCopyLink);                    // 评论直达链接
    runModule('NotificationUnreadFilter', NotificationUnreadFilter);  // 通知未读筛选
    // —— UX50 第六批 4 个可见功能模块（均为已写但漏注册的死代码，现接线） ——
    runModule('RippleEffect', RippleEffect);                          // 卡片/按钮点击涟漪
    runModule('FocusMode', FocusMode);                                // 发帖页「专注模式」沉浸写作
    runModule('SessionExpiryWarning', SessionExpiryWarning);          // 登录会话即将过期提醒
    runModule('KonamiCode', KonamiCode);                              // 键盘彩蛋（可分享的趣味点）
  }

  // 保持与原逻辑一致的 DOMContentLoaded 立即初始化，确保点击第一时间响应
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNewFeatures);
  } else {
    initNewFeatures();
  }

  if (window.App) {
    window.App.ReadingProgress = ReadingProgress;
    window.App.KeyboardShortcuts = KeyboardShortcuts;
    window.App.FontSizeToggle = FontSizeToggle;
    window.App.SearchHistory = SearchHistory;
    window.App.RandomPost = RandomPost;
    window.App.DailyCheckIn = DailyCheckIn;
    window.App.TitleUnreadCount = TitleUnreadCount;
    window.App.EmojiReactions = EmojiReactions;
    window.App.KonamiCode = KonamiCode;
    window.App.FullscreenToggle = FullscreenToggle;
    window.App.LiveTimestamp = LiveTimestamp;
    window.App.HoverCard = HoverCard;
    window.App.PostPreviewCard = PostPreviewCard;
    window.App.PostShare = PostShare;
    window.App.PostCollapse = PostCollapse;
    window.App.DragDropUpload = DragDropUpload;
    window.App.NotificationSound = NotificationSound;
    window.App.PostHistory = PostHistory;
    window.App.AutoRefresh = AutoRefresh;
    window.App.FocusMode = FocusMode;
    window.App.EmojiPicker = EmojiPicker;
    window.App.CommentSort = CommentSort;
    window.App.TagCloud = TagCloud;
    window.App.PostBookmark = PostBookmark;
    window.App.PostFilter = PostFilter;
    window.App.MarkdownPreview = MarkdownPreview;
    window.App.PostRating = PostRating;
    window.App.UserBadge = UserBadge;
    window.App.KeyboardNav = KeyboardNav;
    window.App.CodeHighlight = CodeHighlight;
    window.App.PostTemplate = PostTemplate;
    window.App.ScrollMemory = ScrollMemory;
    window.App.ErrorBoundary = ErrorBoundary;
    window.App.LazyComponent = LazyComponent;
    window.App.PostTrending = PostTrending;
    window.App.FollowSystem = FollowSystem;
    window.App.FeatureVote = FeatureVote;
    window.App.PrivateMessage = PrivateMessage;
    window.App.DiceRoller = DiceRoller;
    window.App.CoinFlip = CoinFlip;
    window.App.RandomPicker = RandomPicker;
    window.App.NotePad = NotePad;
    window.App.PollSystem = PollSystem;
    window.App.TodoList = TodoList;
    window.App.Stopwatch = Stopwatch;
    window.App.Pomodoro = Pomodoro;
    window.App.Calculator = Calculator;
    window.App.QRCode = QRCode;
    window.App.ColorPicker = ColorPicker;
    window.App.GradientGenerator = GradientGenerator;
    window.App.PasswordGenerator = PasswordGenerator;
    window.App.UnitConverter = UnitConverter;
    window.App.SnowEffect = SnowEffect;
    window.App.ConfettiEffect = ConfettiEffect;
    window.App.ParticleBackground = ParticleBackground;
    window.App.QuickSearch = QuickSearch;
    window.App.FortuneCookie = FortuneCookie;
    window.App.LuckyWheel = LuckyWheel;
    window.App.ScratchCard = ScratchCard;
    window.App.CountdownTimer = CountdownTimer;
    window.App.PhotoWall = PhotoWall;
    window.App.QuoteOfTheDay = QuoteOfTheDay;
    window.App.AchievementBadge = AchievementBadge;
    window.App.NightMode = NightMode;
    window.App.WordCount = WordCount;
    window.App.JSONFormatter = JSONFormatter;
    window.App.Base64Tool = Base64Tool;
    window.App.IPLookup = IPLookup;
    window.App.ScreenCapture = ScreenCapture;
    window.App.ReadingMode = ReadingMode;
    window.App.SiteMap = SiteMap;
    window.App.BubbleEffect = BubbleEffect;
    window.App.AnimatedBackground = AnimatedBackground;
    window.App.RichText = RichText;

    window.App.PageLoadProgress = PageLoadProgress;
    window.App.AutoFocusInput = AutoFocusInput;
    window.App.PasteImageUpload = PasteImageUpload;
    window.App.FloatingSelectionToolbar = FloatingSelectionToolbar;
    window.App.NotificationBadge = NotificationBadge;
    window.App.CommentQuoteReply = CommentQuoteReply;
    window.App.FormDraftSave = FormDraftSave;
    window.App.TableRowHighlight = TableRowHighlight;
    window.App.ConfirmDialog = ConfirmDialog;
    window.App.SearchSuggestions = SearchSuggestions;
    window.App.InputValidation = InputValidation;
    window.App.LoadingSkeleton = LoadingSkeleton;
    window.App.MobileNavBar = MobileNavBar;
    window.App.SmoothScrollAnchors = SmoothScrollAnchors;
    window.App.ExternalLinkConfirm = ExternalLinkConfirm;
    window.App.PageTitleUpdate = PageTitleUpdate;
    window.App.CopyCodeButton = CopyCodeButton;

    window.App.PasswordStrengthMeter = PasswordStrengthMeter;
    window.App.FormShakeOnError = FormShakeOnError;
    window.App.SessionExpiryWarning = SessionExpiryWarning;
    window.App.IdleAutoLock = IdleAutoLock;
    window.App.StaggeredEntrance = StaggeredEntrance;
    window.App.NetworkQualityIndicator = NetworkQualityIndicator;
    window.App.ResponsiveTableScroll = ResponsiveTableScroll;
    window.App.StepProgressIndicator = StepProgressIndicator;
    window.App.InputFloatingLabel = InputFloatingLabel;
    window.App.ScrollNavigationDots = ScrollNavigationDots;
    window.App.QuickJumpMenu = QuickJumpMenu;
    window.App.PostWordCounter = PostWordCounter;
    window.App.UserProfileCard = UserProfileCard;
    window.App.FavoriteHeartAnimation = FavoriteHeartAnimation;
    window.App.AutoSaveIndicator = AutoSaveIndicator;
    window.App.TextSelectionActions = TextSelectionActions;
    window.App.CommentHighlightNav = CommentHighlightNav;
    window.App.TabFocusIndicator = TabFocusIndicator;
    window.App.ScrollToSection = ScrollToSection;
    window.App.MediaDownloadButton = MediaDownloadButton;
    window.App.CollapsibleSections = CollapsibleSections;
    window.App.AdminQuickActions = AdminQuickActions;
    window.App.ToastImprovements = ToastImprovements;
    window.App.ImageLoadPlaceholder = ImageLoadPlaceholder;
    window.App.InfiniteScrollSentinel = InfiniteScrollSentinel;
    window.App.MobilePullToRefresh = MobilePullToRefresh;
    window.App.InputClearButton = InputClearButton;
    window.App.LongPressCopy = LongPressCopy;
    window.App.EmptyStateEnhance = EmptyStateEnhance;
  }

})();
