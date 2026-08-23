/**
 * Blaster front-end runtime.
 *
 * Inlined into the page rather than loaded as a file: a notification bar that arrives after
 * first paint has already failed at its job, and at this size a second request costs more than
 * it saves.
 *
 * Its whole responsibility is the half of targeting that varies by *visitor* rather than by
 * request — device, referrer, first-versus-returning, view caps, prior dismissals. The server
 * has already decided everything that varies by request and sent every bar that could apply,
 * hidden. This picks the winner. Nothing it learns about the visitor leaves the browser.
 */
(function () {
  'use strict';

  var ROOT = document.querySelector('[data-blaster]');
  if (!ROOT || ROOT.hasAttribute('data-blaster-ready')) return;
  ROOT.setAttribute('data-blaster-ready', '');

  var ENDPOINT = ROOT.getAttribute('data-blaster-endpoint') || '';
  var SITE_ID = ROOT.getAttribute('data-blaster-site') || '';
  var TRACK = ROOT.getAttribute('data-blaster-track') === '1';
  var STORE_KEY = 'blaster.state';
  var SESSION_KEY = 'blaster.session';

  var SEARCH_HOSTS = /(^|\.)(google|bing|yahoo|duckduckgo|baidu|yandex|ecosia|brave|startpage|qwant)\./i;

  // ------------------------------------------------------------------ storage
  //
  // Private browsing, disabled storage and quota errors all throw on access rather than
  // returning null, so every touch is guarded and falls back to memory. A bar that cannot
  // remember a dismissal is worse behaved than one that shows; a bar that throws is broken.

  var memory = {};

  function read(key) {
    try {
      var raw = (key === SESSION_KEY ? sessionStorage : localStorage).getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return memory[key] || null;
    }
  }

  function write(key, value) {
    memory[key] = value;
    try {
      (key === SESSION_KEY ? sessionStorage : localStorage).setItem(key, JSON.stringify(value));
    } catch (e) {
      /* memory copy already holds it */
    }
  }

  function state() {
    var s = read(STORE_KEY);
    if (!s || typeof s !== 'object') s = {};
    if (!s.bars || typeof s.bars !== 'object') s.bars = {};
    return s;
  }

  function barState(s, handle) {
    if (!s.bars[handle] || typeof s.bars[handle] !== 'object') s.bars[handle] = {};
    return s.bars[handle];
  }

  // ------------------------------------------------------------------ visitor facts

  var STATE = state();
  var IS_RETURNING = STATE.seen === true;

  // Recorded immediately, not on first bar shown: whether someone has been here before is a fact
  // about the visit, not about any particular bar, and a page with no eligible bar still counts
  // as having been visited.
  if (!IS_RETURNING) {
    STATE.seen = true;
    write(STORE_KEY, STATE);
  }

  function deviceClass(config) {
    var w = window.innerWidth || document.documentElement.clientWidth;
    if (w <= (config.mobileMax || 640)) return 'mobile';
    if (w <= (config.tabletMax || 1024)) return 'tablet';
    return 'desktop';
  }

  function referrerHost() {
    if (!document.referrer) return null;
    try {
      return new URL(document.referrer).hostname.toLowerCase();
    } catch (e) {
      return null;
    }
  }

  function matchesReferrer(rule, domains) {
    var host = referrerHost();
    switch (rule) {
      case 'direct':
        return host === null;
      case 'external':
        return host !== null && host !== window.location.hostname.toLowerCase();
      case 'search':
        return host !== null && SEARCH_HOSTS.test(host);
      case 'domains':
        if (host === null) return false;
        return (domains || []).some(function (d) {
          return host === d || host.slice(-(d.length + 1)) === '.' + d;
        });
      default:
        return true;
    }
  }

  function isEligible(config) {
    var t = config.targeting || {};

    if ((t.devices || []).indexOf(deviceClass(t)) === -1) return false;
    if (t.visitor === 'first' && IS_RETURNING) return false;
    if (t.visitor === 'returning' && !IS_RETURNING) return false;
    if (!matchesReferrer(t.referrer, t.referrerDomains)) return false;

    var d = config.display || {};
    var mine = barState(STATE, config.handle);

    // A version bump wipes the visitor's history with this bar. Editing a bar is how an author
    // says "this is a new thing to say", and a dismissal of the old wording must not suppress it.
    if (mine.v !== config.version) return true;

    if (d.maxViews > 0 && (mine.n || 0) >= d.maxViews) return false;

    var until = d.dismissDays === 0 ? (read(SESSION_KEY) || {})[config.handle] : mine.d;
    if (until && Date.now() < until) return false;

    return true;
  }

  // ------------------------------------------------------------------ counting

  function track(type, id) {
    if (!TRACK || !ENDPOINT) return;
    try {
      var body = new FormData();
      body.append('barId', id);
      body.append('siteId', SITE_ID);
      body.append('type', type);

      if (navigator.sendBeacon) {
        navigator.sendBeacon(ENDPOINT, body);
      } else {
        fetch(ENDPOINT, { method: 'POST', body: body, keepalive: true, credentials: 'omit', mode: 'no-cors' });
      }
    } catch (e) {
      /* counters are never worth breaking a page over */
    }
  }

  // ------------------------------------------------------------------ layout

  var offsets = { top: 0, bottom: 0 };

  function applyOffset(position, px) {
    offsets[position] = px;
    var root = document.documentElement;
    root.style.setProperty('--blaster-offset-' + position, px + 'px');
    document.body.style['padding' + (position === 'top' ? 'Top' : 'Bottom')] =
      px > 0 ? 'calc(var(--blaster-body-' + position + ', 0px) + ' + px + 'px)' : '';
  }

  function watchHeight(el, position) {
    var update = function () {
      applyOffset(position, el.classList.contains('blaster-is-shown') ? el.offsetHeight : 0);
    };
    update();

    if (window.ResizeObserver) {
      new ResizeObserver(update).observe(el);
    } else {
      window.addEventListener('resize', update);
    }

    return update;
  }

  // ------------------------------------------------------------------ one bar

  function setUp(el) {
    var config;
    try {
      config = JSON.parse(el.getAttribute('data-blaster-config') || '{}');
    } catch (e) {
      return;
    }

    var d = config.display || {};
    var position = config.position === 'bottom' ? 'bottom' : 'top';
    var duration = d.animation === 'none' ? 0 : (d.duration || 0);
    var reopenTab = ROOT.querySelector('[data-blaster-reopen="' + config.id + '"]');
    var autoCloseTimer = null;
    var syncHeight = null;

    // A bar that is not fixed has to sit where it belongs in the document. Blaster injects its
    // markup before </body>, so an in-flow top bar would otherwise render at the foot of the page.
    if (!d.sticky && position === 'top') {
      document.body.insertBefore(el, document.body.firstChild);
    } else if (d.sticky) {
      el.classList.add('blaster-bar--fixed');
    }

    function forgetSessionDismissal(handle) {
      var session = read(SESSION_KEY) || {};

      if (session[handle]) {
        delete session[handle];
        write(SESSION_KEY, session);
      }
    }

    function remember(changes) {
      var s = state();
      var mine = barState(s, config.handle);
      mine.v = config.version;
      for (var k in changes) mine[k] = changes[k];
      s.seen = true;
      write(STORE_KEY, s);
      STATE = s;
    }

    function show() {
      el.classList.add('blaster-is-shown');

      if (duration > 0) {
        var from = d.animation === 'fade'
          ? { opacity: '0' }
          : { transform: 'translateY(' + (position === 'top' ? '-100%' : '100%') + ')' };

        Object.keys(from).forEach(function (prop) { el.style[prop] = from[prop]; });
        el.getBoundingClientRect(); // force the start state to be painted before transitioning
        el.style.transition = (d.animation === 'fade' ? 'opacity ' : 'transform ') + duration + 'ms ease';
        el.style.opacity = '';
        el.style.transform = '';
      }

      if (d.sticky && d.push) syncHeight = watchHeight(el, position);

      // A bar the visitor has not seen at this version starts from nothing: the view count *and*
      // the old dismissal both go. Clearing only the count would let the bar through once and
      // then have the stale dismissal suppress it again on the very next page — which reads as
      // "the edit worked, then stopped working", and is far harder to notice than never working.
      var mine = barState(state(), config.handle);
      var isNewVersion = mine.v !== config.version;

      if (isNewVersion) {
        forgetSessionDismissal(config.handle);
        remember({ n: 1, d: null });
      } else {
        remember({ n: (mine.n || 0) + 1 });
      }

      track('view', config.id);

      if (d.autoClose > 0) autoCloseTimer = window.setTimeout(function () { close(false); }, d.autoClose * 1000);
    }

    function close(byVisitor) {
      if (autoCloseTimer) window.clearTimeout(autoCloseTimer);

      var finish = function () {
        el.classList.remove('blaster-is-shown');
        el.style.transition = '';
        if (d.sticky && d.push) applyOffset(position, 0);
        if (reopenTab) reopenTab.classList.add('blaster-is-shown');
      };

      if (duration > 0) {
        el.style.transition = (d.animation === 'fade' ? 'opacity ' : 'transform ') + duration + 'ms ease';
        if (d.animation === 'fade') el.style.opacity = '0';
        else el.style.transform = 'translateY(' + (position === 'top' ? '-100%' : '100%') + ')';
        window.setTimeout(finish, duration);
      } else {
        finish();
      }

      if (!byVisitor) return;

      // Auto-close is the bar giving up on its own; only a visitor closing it is a dismissal, and
      // only a dismissal should stop it coming back on the next page.
      if (d.dismissDays === 0) {
        var session = read(SESSION_KEY) || {};
        session[config.handle] = Date.now() + 86400000;
        write(SESSION_KEY, session);
      } else {
        remember({ d: Date.now() + d.dismissDays * 86400000 });
      }

      track('dismiss', config.id);
    }

    function reopen() {
      if (reopenTab) reopenTab.classList.remove('blaster-is-shown');
      if (duration > 0) el.style.transition = '';
      show();
    }

    var closeButton = el.querySelector('[data-blaster-close]');
    if (closeButton) closeButton.addEventListener('click', function () { close(true); });
    if (reopenTab) reopenTab.addEventListener('click', reopen);

    var cta = el.querySelector('[data-blaster-cta]');
    if (cta) cta.addEventListener('click', function () { track('click', config.id); });

    schedule(show, d);
  }

  /** Waits for whatever the bar's trigger is, then shows it. */
  function schedule(show, d) {
    switch (d.trigger) {
      case 'delay':
        window.setTimeout(show, (d.delay || 0) * 1000);
        return;

      case 'scroll': {
        var target = Math.max(1, d.scroll || 25) / 100;
        var onScroll = function () {
          var height = document.documentElement.scrollHeight - window.innerHeight;
          var progress = height > 0 ? window.pageYOffset / height : 1;
          if (progress < target) return;
          window.removeEventListener('scroll', onScroll);
          show();
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
        return;
      }

      case 'exit': {
        // Exit intent has no meaning on a touch device — there is no pointer to leave the
        // viewport — so those visitors get the bar after a beat instead of never.
        if (!window.matchMedia || !window.matchMedia('(hover: hover)').matches) {
          window.setTimeout(show, 8000);
          return;
        }
        var onLeave = function (event) {
          if (event.clientY > 0) return;
          document.removeEventListener('mouseout', onLeave);
          show();
        };
        document.addEventListener('mouseout', onLeave);
        return;
      }

      default:
        show();
    }
  }

  // ------------------------------------------------------------------ pick the winners
  //
  // The server sent every candidate in priority order. At most one bar may hold each position:
  // two stacked announcements are not twice the message, they are half the page.

  function start() {
    var taken = {};

    Array.prototype.forEach.call(ROOT.querySelectorAll('[data-blaster-bar]'), function (el) {
      var config;
      try {
        config = JSON.parse(el.getAttribute('data-blaster-config') || '{}');
      } catch (e) {
        return;
      }

      var position = config.position === 'bottom' ? 'bottom' : 'top';

      if (taken[position] || !isEligible(config)) {
        el.parentNode.removeChild(el);
        return;
      }

      taken[position] = true;
      setUp(el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
