/* Expandable charts fed by real data (JSON endpoints). Markup comes from chart_card() in includes/stats.php. */
(function () {
  'use strict';
  if (!window.Chart) return;

  var root = getComputedStyle(document.documentElement);
  var V = function (n) { return root.getPropertyValue(n).trim(); };
  var COLORS = { accent: V('--accent'), green: V('--green'), red: V('--red'), warn: V('--warn') };
  var MUTED = V('--muted'), LINE = V('--line');
  var ICON_X = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

  Chart.defaults.color = MUTED;
  Chart.defaults.font.family = 'system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif';
  Chart.defaults.borderColor = LINE;

  function col(n) { return COLORS[n] || COLORS.accent; }
  function alpha(hex, a) {
    var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
    return m ? 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + a + ')' : hex;
  }
  function num(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
  function compact(v) {
    var a = Math.abs(v);
    if (a >= 1e7) return (v / 1e7).toFixed(1).replace(/\.0$/, '') + 'Cr';
    if (a >= 1e5) return (v / 1e5).toFixed(1).replace(/\.0$/, '') + 'L';
    if (a >= 1e3) return (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
    return String(Math.round(v * 100) / 100);
  }
  var NOSYM = /^(Trades|Total users|New users|Predicted up|Predicted down)$/;

  function buildConfig(j) {
    var sym = j.sym || '', kind = j.kind, many = j.datasets.length > 1;
    var datasets = j.datasets.map(function (d, i) {
      var o = { label: d.label, data: d.data };
      var c = col(d.color);
      if (kind === 'doughnut') {
        o.backgroundColor = d.colors.map(col);
        o.borderColor = V('--panel'); o.borderWidth = 3; o.hoverOffset = 6;
        return o;
      }
      if (d.type === 'line' || kind === 'line') {
        o.type = 'line'; o.borderColor = c; o.backgroundColor = alpha(c, .16); o.borderWidth = 2; o.tension = .3;
        o.pointRadius = d.data.length > 60 ? 0 : 2.5; o.pointHoverRadius = 5; o.fill = !!d.fill;
        if (d.axis === 'y2') o.yAxisID = 'y2';
        o.order = 0;
        return o;
      }
      o.backgroundColor = d.posneg ? d.data.map(function (x) { return alpha(x >= 0 ? COLORS.green : COLORS.red, .8); }) : alpha(c, .8);
      o.borderRadius = 5; o.maxBarThickness = 34; o.order = 1;
      return o;
    });

    var opts = {
      responsive: true, maintainAspectRatio: false, animation: { duration: 400 },
      interaction: { mode: kind === 'doughnut' ? 'nearest' : 'index', intersect: false },
      plugins: {
        legend: { display: many || kind === 'doughnut', position: kind === 'doughnut' ? 'bottom' : 'top', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
        tooltip: {
          callbacks: {
            label: function (ctx) {
              var v = ctx.parsed.y !== undefined && kind !== 'hbar' ? ctx.parsed.y : (kind === 'hbar' ? ctx.parsed.x : ctx.parsed);
              var s = kind === 'doughnut' ? '' : (NOSYM.test(ctx.dataset.label) ? '' : sym);
              return ' ' + ctx.dataset.label + ': ' + (v < 0 ? '-' : '') + s + num(Math.abs(v));
            }
          }
        }
      }
    };
    if (kind !== 'doughnut') {
      var tickCb = function (v) { return compact(v); };
      opts.scales = {
        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
        y: { beginAtZero: kind !== 'line', grid: { color: alpha(LINE, .6) }, ticks: { callback: tickCb } }
      };
      if (kind === 'hbar') {
        opts.indexAxis = 'y';
        opts.scales = { x: { beginAtZero: true, grid: { color: alpha(LINE, .6) }, ticks: { callback: tickCb } }, y: { grid: { display: false } } };
      }
      if (datasets.some(function (d) { return d.yAxisID === 'y2'; })) {
        opts.scales.y2 = { position: 'right', grid: { display: false }, ticks: { callback: tickCb }, beginAtZero: false };
      }
    } else {
      opts.cutout = '62%';
    }
    return { type: kind === 'hbar' ? 'bar' : (kind === 'doughnut' ? 'doughnut' : (kind === 'line' ? 'line' : 'bar')), data: { labels: j.labels, datasets: datasets }, options: opts };
  }

  function render(card, j) {
    var msg = card.querySelector('.cmsg'), note = card.querySelector('.cnote'), canvas = card.querySelector('canvas');
    if (card._chart) { card._chart.destroy(); card._chart = null; }
    note.textContent = j.note || '';
    if (j.empty) { msg.textContent = 'No data yet for this period.'; msg.classList.remove('hide'); return; }
    msg.classList.add('hide');
    card._chart = new Chart(canvas.getContext('2d'), buildConfig(j));
  }

  function load(card) {
    var d = card.dataset, msg = card.querySelector('.cmsg');
    if (!card._chart) { msg.textContent = 'Loading…'; msg.classList.remove('hide'); }
    var qs = new URLSearchParams({ metric: d.metric, acc: d.acc, days: d.days });
    if (+d.user) qs.set('user', d.user);
    fetch(d.src + '?' + qs.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (!j.ok) throw new Error(j.error || 'failed'); render(card, j); })
      .catch(function () { if (!card._chart) { msg.textContent = 'Could not load this chart.'; msg.classList.remove('hide'); } });
  }

  function bind(card, expanded) {
    var sel = card.querySelector('.cdays');
    if (sel) {
      sel.value = card.dataset.days;
      sel.addEventListener('change', function () { card.dataset.days = sel.value; load(card); });
    }
    var btn = card.querySelector('.cexp');
    if (expanded) { btn.innerHTML = ICON_X; btn.title = 'Close'; btn.setAttribute('aria-label', 'Close'); }
    btn.addEventListener('click', function () { expanded ? closeModal() : expand(card); });
  }

  var modal = null;
  function expand(card) {
    closeModal();
    modal = document.createElement('div');
    modal.className = 'cmodal';
    var clone = card.cloneNode(true);
    clone.classList.add('expanded');
    clone.querySelector('.cbody').innerHTML = '<canvas></canvas><div class="cmsg">Loading…</div>';
    clone.querySelector('.cnote').textContent = card.querySelector('.cnote').textContent;
    modal.appendChild(clone);
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) closeModal(); });
    document.body.appendChild(modal);
    document.body.classList.add('no-scroll');
    bind(clone, true);
    load(clone);
  }
  function closeModal() {
    if (!modal) return;
    var c = modal.querySelector('.chartcard');
    if (c && c._chart) c._chart.destroy();
    modal.remove(); modal = null;
    document.body.classList.remove('no-scroll');
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  function init() {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.chartcard'));
    cards.forEach(function (c) { bind(c, false); load(c); });

    // account picker (INR / USDT / Demo) drives every money chart on the page
    document.querySelectorAll('[data-chartacc]').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('[data-chartacc]').forEach(function (x) { x.classList.toggle('on', x === b); });
        cards.forEach(function (c) { c.dataset.acc = b.dataset.chartacc; if (c.dataset.metric !== 'signups') load(c); });
      });
    });

    // keep the numbers live
    setInterval(function () { if (!document.hidden && !modal) cards.forEach(load); }, 60000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
