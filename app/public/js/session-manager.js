(function () {
  'use strict';

  var KEEP_ALIVE_INTERVAL_MS = 30 * 60 * 1000;
  var REDIRECT_DELAY_MS = 1500;
  var PING_URL = '/auth/ping';
  var LOGIN_URL = '/login';
  var TOAST_MESSAGE = 'Tu sesión expiró, te llevamos al login...';
  var SESSION_EXPIRED_HEADER = 'x-session-expired';

  var state = {
    intervalId: null,
    redirecting: false,
    pingInFlight: false,
    toastEl: null,
  };

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    var match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (match) {
      try {
        return decodeURIComponent(match[1]);
      } catch (e) {
        return match[1];
      }
    }
    return '';
  }

  function ensureToast() {
    if (state.toastEl && document.body.contains(state.toastEl)) return state.toastEl;

    var el = document.createElement('div');
    el.id = 'session-toast';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.style.cssText = [
      'position:fixed',
      'top:24px',
      'left:50%',
      'transform:translateX(-50%) translateY(-20px)',
      'background:rgba(8,29,74,0.95)',
      'color:#fff',
      'padding:14px 22px',
      'border-radius:14px',
      'border:1px solid rgba(255,255,255,0.15)',
      'box-shadow:0 8px 30px rgba(0,0,0,0.4),0 0 60px rgba(70,84,168,0.35)',
      'backdrop-filter:blur(12px)',
      '-webkit-backdrop-filter:blur(12px)',
      'font-family:system-ui,-apple-system,sans-serif',
      'font-size:14px',
      'font-weight:500',
      'z-index:99999',
      'opacity:0',
      'transition:opacity .25s ease,transform .25s ease',
      'display:flex',
      'align-items:center',
      'gap:10px',
      'max-width:90vw',
    ].join(';');

    var spinner = document.createElement('span');
    spinner.style.cssText = [
      'display:inline-block',
      'width:14px',
      'height:14px',
      'border:2px solid rgba(255,255,255,0.3)',
      'border-top-color:#fff',
      'border-radius:50%',
      'animation:session-spin .9s linear infinite',
    ].join(';');

    var text = document.createElement('span');
    text.textContent = TOAST_MESSAGE;

    el.appendChild(spinner);
    el.appendChild(text);

    if (!document.getElementById('session-toast-style')) {
      var style = document.createElement('style');
      style.id = 'session-toast-style';
      style.textContent = '@keyframes session-spin{to{transform:rotate(360deg)}}#session-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}';
      document.head.appendChild(style);
    }

    document.body.appendChild(el);
    state.toastEl = el;
    return el;
  }

  function showToast() {
    var el = ensureToast();
    requestAnimationFrame(function () {
      el.classList.add('show');
    });
  }

  function hideToast() {
    if (state.toastEl) state.toastEl.classList.remove('show');
  }

  function triggerSessionExpiredRedirect() {
    if (state.redirecting) return;
    state.redirecting = true;
    stopKeepAlive();
    showToast();
    setTimeout(function () {
      window.location.href = LOGIN_URL;
    }, REDIRECT_DELAY_MS);
  }

  function isSessionExpiredResponse(res) {
    if (res.status === 419) return true;
    if (res.status !== 401) return false;
    var header = res.headers.get(SESSION_EXPIRED_HEADER);
    if (!header) return false;
    return String(header).trim() === '1';
  }

  window.apiFetch = function apiFetch(url, options) {
    options = options || {};
    var init = Object.assign({}, options);
    if (init.credentials === undefined) init.credentials = 'same-origin';

    // Auto-inject CSRF token si no se pasó en headers. Sin esto, Laravel
    // rechaza los POST / PUT / DELETE con 422 antes de que la lógica del
    // controller corra. El token viene de <meta name="csrf-token"> o de
    // la cookie XSRF-TOKEN que Laravel rota automáticamente.
    init.headers = init.headers || {};
    var alreadyHasCsrf = false;
    for (var k in init.headers) {
      if (k && k.toLowerCase() === 'x-csrf-token') { alreadyHasCsrf = true; break; }
    }
    if (!alreadyHasCsrf) {
      var csrf = getCsrfToken();
      if (csrf) {
        if (typeof Headers !== 'undefined' && !init.headers instanceof Headers) {
          // ok
        }
        init.headers['X-CSRF-TOKEN'] = csrf;
      }
    }

    return fetch(url, init).then(function (res) {
      if (isSessionExpiredResponse(res)) {
        triggerSessionExpiredRedirect();
      }
      return res;
    });
  };

  function sendPing() {
    if (state.pingInFlight || state.redirecting) return Promise.resolve();
    if (typeof document !== 'undefined' && document.hidden) return Promise.resolve();
    state.pingInFlight = true;
    var headers = { 'Accept': 'application/json' };
    var token = getCsrfToken();
    if (token) headers['X-CSRF-TOKEN'] = token;

    return fetch(PING_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
    })
      .then(function (res) {
        if (isSessionExpiredResponse(res)) {
          triggerSessionExpiredRedirect();
        }
      })
      .catch(function () {
      })
      .then(function () {
        state.pingInFlight = false;
      });
  }

  function startKeepAlive() {
    stopKeepAlive();
    state.intervalId = setInterval(sendPing, KEEP_ALIVE_INTERVAL_MS);
  }

  function stopKeepAlive() {
    if (state.intervalId !== null) {
      clearInterval(state.intervalId);
      state.intervalId = null;
    }
  }

  function handleVisibilityChange() {
    if (typeof document === 'undefined') return;
    if (document.hidden) {
      stopKeepAlive();
      return;
    }
    sendPing().finally(startKeepAlive);
  }

  function init() {
    startKeepAlive();
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', handleVisibilityChange);
    }
    window.addEventListener('pagehide', stopKeepAlive);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();