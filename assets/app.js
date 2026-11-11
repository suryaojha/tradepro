/* Shared UI helpers: loaders, SweetAlert dialogs, IST/UTC clock + switch, responsive tables, admin drawer, password fields. */
(function () {
  'use strict';

  // ================= TP: alerts, confirms, loaders =================
  function swalTheme() {                       // dialogs follow the dark / light theme
    var s = getComputedStyle(document.documentElement), v = function (n) { return s.getPropertyValue(n).trim(); };
    return { background: v('--panel'), color: v('--text'), confirmButtonColor: v('--accent'), cancelButtonColor: v('--line') };
  }
  var TP = window.TP = {
    ready: function () { return typeof Swal !== 'undefined'; },
    toast: function (msg, type, ms) {
      if (!TP.ready()) { return; }
      Swal.fire(Object.assign({}, swalTheme(), {
        toast: true, position: 'top-end', icon: type || 'success', title: msg, showConfirmButton: false,
        timer: ms || 4500, timerProgressBar: true, customClass: { popup: 'tp-toast' }
      }));
    },
    alert: function (msg, type, title) {
      if (!TP.ready()) { window.alert(msg); return Promise.resolve(); }
      return Swal.fire(Object.assign({}, swalTheme(), { icon: type || 'info', title: title || undefined, text: msg, confirmButtonText: 'OK' }));
    },
    confirm: function (msg, o) {
      o = o || {};
      if (!TP.ready()) { return Promise.resolve(window.confirm(msg)); }
      return Swal.fire(Object.assign({}, swalTheme(), {
        icon: o.icon || 'question', title: o.title || 'Are you sure?', text: msg, showCancelButton: true,
        confirmButtonText: o.ok || 'Yes, continue', cancelButtonText: 'Cancel', reverseButtons: true, focusCancel: true
      })).then(function (r) { return !!r.isConfirmed; });
    },
    loading: function (on) {
      var el = document.getElementById('pageLoader');
      if (!el) return;
      el.classList.toggle('busy', !!on);
      el.classList.toggle('hide', !on);
    },
    progress: function (on) {
      var p = document.getElementById('topProgress');
      if (!p) return;
      p.classList.toggle('run', !!on);
    }
  };

  function hidePageLoader() {
    var el = document.getElementById('pageLoader');
    if (el) { el.classList.remove('busy'); el.classList.add('hide'); }
    TP.progress(false);
    document.querySelectorAll('.btn.loading').forEach(function (b) { b.classList.remove('loading'); b.disabled = false; });
  }
  window.addEventListener('pageshow', hidePageLoader);   // also when coming back with the Back button

  // links: show the progress bar while the next page loads
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (a && !e.defaultPrevented && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) {
      var h = a.getAttribute('href') || '';
      if (a.target !== '_blank' && !a.hasAttribute('download') && h && h.charAt(0) !== '#' && !/^(javascript|mailto|tel):/i.test(h) && a.origin === location.origin && !a.hasAttribute('data-confirm')) {
        TP.progress(true);
      }
    }
    // confirm on plain buttons / links
    var c = e.target.closest('[data-confirm]');
    if (c && c.tagName !== 'FORM' && !(c.tagName === 'BUTTON' && c.form && c.type !== 'button' && !c.hasAttribute('data-confirm-click'))) {
      e.preventDefault();
      TP.confirm(c.getAttribute('data-confirm')).then(function (ok) { if (ok && c.href) location.href = c.href; });
    }
  });

  function startLoad(form, btn) {
    TP.progress(true);
    if (btn && btn.classList && btn.classList.contains('btn')) {
      btn.classList.add('loading');
      setTimeout(function () { btn.disabled = true; }, 0);
    }
    setTimeout(function () { if (document.getElementById('pageLoader') && !document.hidden) TP.loading(true); }, 1200);
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f._ok || e.defaultPrevented || f.hasAttribute('data-noload')) return;
    var sub = e.submitter || null;
    if (f.hasAttribute('data-askpin') && !f._pinned) {          // ask for the 6-digit PIN before submitting
      e.preventDefault();
      if (!TP.ready()) { var p0 = window.prompt('Enter your 6-digit PIN'); if (p0) { f._pinned = true; var h0 = document.createElement('input'); h0.type = 'hidden'; h0.name = 'pin'; h0.value = p0; f.appendChild(h0); f.submit(); } return; }
      Swal.fire(Object.assign({}, swalTheme(), {
        title: f.getAttribute('data-askpin') || 'Enter your PIN', input: 'password', inputAttributes: { inputmode: 'numeric', maxlength: 6, autocomplete: 'off', placeholder: '••••••' },
        showCancelButton: true, confirmButtonText: 'Confirm', reverseButtons: true,
        inputValidator: function (v) { return /^\d{6}$/.test(v || '') ? null : 'Enter your 6-digit PIN'; }
      })).then(function (r) {
        if (!r.isConfirmed) return;
        f._pinned = true;
        var h = document.createElement('input'); h.type = 'hidden'; h.name = 'pin'; h.value = r.value; f.appendChild(h);
        startLoad(f, sub); f.submit();
      });
      return;
    }
    var msg = f.getAttribute('data-confirm') || (sub && sub.getAttribute('data-confirm'));
    if (!msg) { startLoad(f, sub); return; }
    e.preventDefault();
    TP.confirm(msg).then(function (ok) {
      if (!ok) return;
      f._ok = true;
      if (sub && sub.name) {
        var h = document.createElement('input'); h.type = 'hidden'; h.name = sub.name; h.value = sub.value; f.appendChild(h);
      }
      startLoad(f, sub);
      f.submit();
    });
  });

  // ================= live clock in the selected time zone =================
  function tickClock() {
    document.querySelectorAll('.tzclock').forEach(function (el) {
      try {
        el.textContent = new Intl.DateTimeFormat('en-GB', {
          timeZone: el.dataset.tz || 'UTC', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        }).format(new Date());
      } catch (e) { el.textContent = new Date().toISOString().substr(11, 8); }
    });
  }

  // ================= IST / UTC switch =================
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-tzset]');
    if (!b) return;
    document.cookie = 'tz=' + encodeURIComponent(b.dataset.tzset) + '; path=/; max-age=31536000; SameSite=Lax';
    TP.loading(true);
    location.reload();
  });

  // ================= tables -> cards on phones =================
  function rtLabel(table) {
    var rows = table.rows;
    if (!rows.length) return;
    var head = rows[0], labels = [];
    if (head.querySelector('th')) {
      head.classList.add('rt-head');
      for (var i = 0; i < head.cells.length; i++) labels.push(head.cells[i].textContent.trim());
    }
    for (var r = 0; r < rows.length; r++) {
      if (rows[r] === head && labels.length) continue;
      for (var c = 0; c < rows[r].cells.length; c++) {
        var td = rows[r].cells[c];
        if (td.tagName === 'TD' && !td.hasAttribute('colspan')) td.setAttribute('data-label', labels[c] || '');
      }
    }
  }
  window.rtLabel = rtLabel;

  // ================= password fields =================
  function score(p) {
    var s = 0;
    if (p.length >= 8) s++;
    if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++;
    if (/[0-9]/.test(p)) s++;
    if (/[^A-Za-z0-9]/.test(p)) s++;
    if (p.length >= 12 && s >= 3) s++;
    return p.length ? s : 0;
  }
  function initPasswords() {
    document.querySelectorAll('[data-pwtoggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var inp = btn.parentNode.querySelector('input');
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.textContent = show ? 'Hide' : 'Show';
      });
    });
    document.querySelectorAll('input[data-meter]').forEach(function (inp) {
      var wrap = inp.closest('.field'), bar = wrap.querySelector('.meter i'), hint = wrap.querySelector('.meterhint');
      var base = hint ? hint.textContent : '';
      inp.addEventListener('input', function () {
        var s = score(inp.value), cols = ['#f2495e', '#f2495e', '#f5b73b', '#22c984', '#22c984'], txt = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        bar.style.width = (s * 20) + '%';
        bar.style.background = cols[Math.max(0, s - 1)] || cols[0];
        if (hint) hint.textContent = inp.value ? (txt[Math.min(4, s)] || 'Weak') + ' — ' + base : base;
      });
    });
  }

  // ================= 6-digit PIN boxes =================
  function initPins() {
    document.querySelectorAll('.pinbox').forEach(function (box) {
      var boxes = Array.prototype.slice.call(box.querySelectorAll('input[type=password]')), hidden = box.querySelector('input[type=hidden]');
      function sync() {
        hidden.value = boxes.map(function (b) { return b.value; }).join('');
        if (hidden.value.length === 6) document.dispatchEvent(new CustomEvent('pin-complete', { detail: { name: box.dataset.pin } }));
      }
      boxes.forEach(function (b, i) {
        b.addEventListener('input', function () {
          b.value = b.value.replace(/\D/g, '').slice(-1);
          if (b.value && boxes[i + 1]) boxes[i + 1].focus();
          sync();
        });
        b.addEventListener('keydown', function (e) {
          if (e.key === 'Backspace' && !b.value && boxes[i - 1]) { boxes[i - 1].value = ''; boxes[i - 1].focus(); sync(); }
        });
        b.addEventListener('focus', function () { b.select(); });
        b.addEventListener('paste', function (e) {
          var t = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '').slice(0, 6);
          if (!t) return;
          e.preventDefault();
          boxes.forEach(function (x, k) { x.value = t[k] || ''; });
          (boxes[Math.min(t.length, 5)]).focus(); sync();
        });
      });
    });
    var first = document.querySelector('#pinForm .pinbox input[type=password]');
    if (first) first.focus();
  }

  // ================= Select2 =================
  function initSelect2(root) {
    if (!window.jQuery || !jQuery.fn.select2) return;
    jQuery(root || document).find('select.s2').each(function () {
      var $s = jQuery(this);
      if ($s.data('select2')) return;
      $s.select2({
        width: '100%', placeholder: $s.data('placeholder') || undefined, allowClear: !!$s.data('clear'),
        minimumResultsForSearch: $s.data('search') === 'always' ? 0 : 6, dropdownAutoWidth: false
      });
      $s.on('select2:open', function () {          // numeric keypad for number-like lists, focus the search box
        var f = document.querySelector('.select2-container--open .select2-search__field');
        if (f) { if ($s.data('numeric')) f.setAttribute('inputmode', 'numeric'); setTimeout(function () { f.focus(); }, 30); }
      });
    });
  }
  window.initSelect2 = initSelect2;

  // ================= admin drawer =================
  function initDrawer() {
    var side = document.getElementById('admSide'), scrim = document.getElementById('admScrim');
    if (!side) return;
    function set(open) { side.classList.toggle('open', open); scrim.classList.toggle('show', open); document.body.classList.toggle('no-scroll', open); }
    var m = document.getElementById('admMenu'), c = document.getElementById('admClose');
    if (m) m.addEventListener('click', function () { set(true); });
    if (c) c.addEventListener('click', function () { set(false); });
    scrim.addEventListener('click', function () { set(false); });
    side.querySelectorAll('a.item').forEach(function (a) { a.addEventListener('click', function () { set(false); }); });
  }

  // ================= dark / light theme =================
  var ICONS = '<svg class="moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>' +
    '<svg class="sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  function setTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    document.cookie = 'theme=' + t + '; path=/; max-age=31536000; SameSite=Lax';
    var meta = document.querySelector('meta[name=theme-color]'); if (meta) meta.content = t === 'light' ? '#f3f5fb' : '#0b1020';
    var tok = document.querySelector('meta[name=csrf]');
    try { fetch(window.TP_THEME_API || 'api/theme.php', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'theme=' + t + '&csrf=' + encodeURIComponent(tok ? tok.content : '') }); } catch (e) {}
    // charts (TradingView, admin charts) read the theme when they start, so reload pages that have them
    if (document.querySelector('#chart, #tvchart, .chartcard')) { TP.loading(true); location.reload(); }
  }
  function initTheme() {
    if (!document.querySelector('.themebtn') && document.querySelector('.authwrap')) {     // login / sign-up pages have no top bar
      var d = document.createElement('div'); d.className = 'auththeme'; d.innerHTML = '<button type="button" class="themebtn" aria-label="Switch dark / light theme">' + ICONS + '</button>';
      document.body.appendChild(d);
    }
    document.querySelectorAll('.themebtn').forEach(function (b) {
      if (!b.firstElementChild) b.innerHTML = ICONS;
      b.addEventListener('click', function () { setTheme(document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light'); });
    });
  }

  // ================= copy buttons =================
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var text = b.getAttribute('data-copy');
    var done = function () { TP.toast('Copied', 'success', 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, done);
    else { var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (x) {} t.remove(); done(); }
  });

  // ================= flash messages -> SweetAlert =================
  function showFlashes() {
    var list = window.TP_FLASH || [];
    if (!list.length || !TP.ready()) return;
    document.querySelectorAll('.js-flash').forEach(function (el) { el.style.display = 'none'; });
    var errs = list.filter(function (f) { return f.t === 'err'; });
    list.filter(function (f) { return f.t !== 'err'; }).forEach(function (f, i) {
      setTimeout(function () { TP.toast(f.m, f.t === 'ok' ? 'success' : 'info', 5000); }, i * 300);
    });
    if (errs.length) TP.alert(errs.map(function (f) { return f.m; }).join('\n'), 'error', 'Something needs attention');
    window.TP_FLASH = [];
  }

  document.addEventListener('DOMContentLoaded', function () {
    tickClock(); setInterval(tickClock, 1000);
    document.querySelectorAll('table.rt').forEach(rtLabel);
    initPasswords();
    initPins();
    initTheme();
    initSelect2();
    initDrawer();
    showFlashes();
    setTimeout(hidePageLoader, 120);
  });
  window.addEventListener('load', hidePageLoader);
})();
