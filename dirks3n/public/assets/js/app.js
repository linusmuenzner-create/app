/* dirks3n – App (ohne Framework, ohne Build-Schritt) */
(function () {
  'use strict';

  // ------------------------------------------------------------------ Grundlagen
  const $app = document.getElementById('app');
  const $tabbar = document.getElementById('tabbar');
  const $toast = document.getElementById('toast');
  const state = { csrf: '', charts: [], day: null, range: 30, today: null };

  // Datenreihen-Farben (validiert für dunklen Hintergrund) + Status
  const C = {
    s1: '#1f7fd1', s2: '#5e8e12', s3: '#7b61e8', s4: '#d9621f',
    lime: '#9bd60a', cyan: '#2bb3d6',
    text: '#4a5260', muted: '#868e9b', grid: 'rgba(15,23,42,0.07)', surface: '#ffffff',
    good: '#3b7d0c', ok: '#0e7fb0', warning: '#a96300', critical: '#d22d2d',
  };

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n, d = 0) => (n === null || n === undefined || Number.isNaN(n)) ? '–' : Number(n).toLocaleString('de-DE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const hm = (min) => { if (min == null) return '–'; const h = Math.floor(min / 60); const m = Math.round(min % 60); return `${h}:${String(m).padStart(2, '0')}`; };
  const isoDay = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const parseDay = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
  const addDays = (s, n) => { const d = parseDay(s); d.setDate(d.getDate() + n); return isoDay(d); };
  const dayLabel = (s, opts = { weekday: 'long', day: 'numeric', month: 'long' }) => parseDay(s).toLocaleDateString('de-DE', opts);
  const signed = (n, d = 0) => (n == null ? '–' : (n > 0 ? '+' : '') + fmt(n, d));

  function toast(msg, ms = 3200) {
    $toast.textContent = msg;
    $toast.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { $toast.hidden = true; }, ms);
  }

  async function api(action, { method = 'GET', body = null, params = {} } = {}) {
    const qs = new URLSearchParams({ a: action, ...params }).toString();
    const res = await fetch(`api.php?${qs}`, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
      body: body ? JSON.stringify(body) : null,
    });
    let data = {};
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (res.status === 401 && action !== 'login') { renderLogin(); throw new Error('Nicht angemeldet'); }
    if (!res.ok) throw new Error(data.error || `Fehler ${res.status}`);
    return data;
  }

  function destroyCharts() { state.charts.forEach((c) => c.destroy()); state.charts = []; }

  // ------------------------------------------------------------------ Icons (eigene Linien-Icons)
  const P = {
    prev: '<path d="M15 18l-6-6 6-6"/>',
    next: '<path d="M9 18l6-6-6-6"/>',
    refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    warn: '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
    dash: '<path d="M6 12h12"/>',
    dumbbell: '<path d="M6.5 6.5v11M17.5 6.5v11M3.5 9v6M20.5 9v6M6.5 12h11"/>',
    run: '<circle cx="15" cy="4.5" r="2"/><path d="M8 21l3-6 3 2v5"/><path d="M6 12l3-3.5 4 1 3 3.5 3 .5"/><path d="M11 15l-1-6.5"/>',
    bolt: '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/>',
    stretch: '<circle cx="12" cy="4.5" r="2"/><path d="M4 9.5c3 1 5.5 1.5 8 1.5s5-.5 8-1.5M12 11v4l-4 6M12 15l4 6"/>',
    walk: '<circle cx="13" cy="4.5" r="2"/><path d="M10 21l2-6 3 2.5V21M8.5 13.5 10 8.5l3.5 1 2 3.5 2.5 1M10 8.5 7 11"/>',
    moon: '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/>',
    heart: '<path d="M12 20s-7.5-4.4-9.2-9.2C1.6 7.4 3.8 4 7.2 4c2 0 3.6 1.1 4.8 2.8C13.2 5.1 14.8 4 16.8 4c3.4 0 5.6 3.4 4.4 6.8C19.5 15.6 12 20 12 20z"/>',
    pulse: '<path d="M2 12h4l2.5-6 4 12 3-8 1.5 2H22"/>',
    wave: '<path d="M2 12c2.5 0 2.5-6 5-6s2.5 12 5 12 2.5-12 5-12 2.5 6 5 6"/>',
    steps: '<path d="M8.5 3.5c1.7 0 2.8 1.8 2.8 4.2 0 2-1 3.3-2.8 3.3S5.7 9.7 5.7 7.7c0-2.4 1.1-4.2 2.8-4.2zM6.2 13.2l4.6-.5.4 2.6c.3 1.6-.7 2.9-2.2 3s-2.6-1-2.8-2.5z"/><path d="M15.5 6.5c1.7 0 2.8 1.8 2.8 4.2 0 2-1 3.3-2.8 3.3s-2.8-1.3-2.8-3.3c0-2.4 1.1-4.2 2.8-4.2zM13.2 16.2l4.6-.5.4 2.6c.3 1.6-.7 2.9-2.2 3s-2.6-1-2.8-2.5z"/>',
    flame: '<path d="M12 22c4 0 7-2.8 7-6.8 0-4.5-4-7-4.6-11.2C11 6 8.8 9 9.5 12.5 7.8 11.7 7 10 7 8.5 5.6 10 5 12.3 5 15.2 5 19.2 8 22 12 22z"/>',
    scale: '<rect x="3" y="3" width="18" height="18" rx="5"/><path d="M8.5 9.5a5 5 0 0 1 7 0L13 12.5"/>',
    calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    timer: '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 1.5M9.5 2.5h5"/>',
    gauge: '<path d="M4 17a8 8 0 1 1 16 0"/><path d="M12 17l4-5"/>',
    watch: '<rect x="6.5" y="6" width="11" height="12" rx="3"/><path d="M9 6V3h6v3M9 18v3h6v-3"/><path d="M12 10v2.5l1.5 1"/>',
    link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    unlink: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1M3 3l18 18"/>',
    target: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".8"/>',
    phone: '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
    spark: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
    server: '<rect x="3.5" y="4" width="17" height="7" rx="2"/><rect x="3.5" y="13" width="17" height="7" rx="2"/><path d="M7.5 7.5h.01M7.5 16.5h.01"/>',
    logout: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10"/>',
  };
  const ico = (name, cls = '') => `<svg class="ico ${cls}" viewBox="0 0 24 24" aria-hidden="true">${P[name] || ''}</svg>`;

  const KIND = {
    'Kraft': { icon: 'dumbbell', cls: '' }, 'Ausdauer': { icon: 'run', cls: '' }, 'HIIT': { icon: 'bolt', cls: '' },
    'Mobilität': { icon: 'stretch', cls: 'easy' }, 'Aktive Erholung': { icon: 'walk', cls: 'easy' }, 'Ruhetag': { icon: 'moon', cls: 'rest' },
  };
  const CAT_ICON = { strength: 'dumbbell', cardio: 'run', hiit: 'bolt', mobility: 'stretch', walk: 'walk', other: 'pulse' };

  function statusOf(score) {
    if (score == null) return { cls: 'none', icon: 'dash', grad: ['#cfd4dc', '#cfd4dc'], glow: 'rgba(15,23,42,.03)' };
    if (score >= 75) return { cls: 'good', icon: 'check', grad: [C.cyan, C.lime], glow: 'rgba(198,244,50,.35)' };
    if (score >= 60) return { cls: 'ok', icon: 'check', grad: ['#1f7fd1', C.cyan], glow: 'rgba(43,179,214,.22)' };
    if (score >= 45) return { cls: 'warning', icon: 'warn', grad: ['#d9621f', '#f0a020'], glow: 'rgba(240,160,32,.2)' };
    return { cls: 'critical', icon: 'warn', grad: ['#b42318', '#ef4444'], glow: 'rgba(239,68,68,.16)' };
  }

  function greeting() {
    const h = new Date().getHours();
    return h < 11 ? 'Guten Morgen' : h < 17 ? 'Hallo' : 'Guten Abend';
  }

  // ------------------------------------------------------------------ Login
  function renderLogin(hasUser = true) {
    destroyCharts();
    $tabbar.hidden = true;
    $app.innerHTML = `
      <div class="login fade-in">
        <div class="wordmark">dirks<span>3</span>n</div>
        <p class="tagline">Trainiere klug. Erhole dich besser.</p>
        ${hasUser ? '' : '<p class="error">Noch kein Benutzer angelegt – bitte zuerst <a href="setup.php">die Einrichtung</a> abschließen.</p>'}
        <form id="loginForm" class="card">
          <label class="field"><span>Benutzername</span><input name="username" autocomplete="username" autocapitalize="none" required></label>
          <label class="field"><span>Passwort</span><input name="password" type="password" autocomplete="current-password" required></label>
          <div id="loginErr" class="error" hidden></div>
          <button class="btn primary block" type="submit">Anmelden</button>
        </form>
      </div>`;
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      const err = document.getElementById('loginErr');
      err.hidden = true;
      try {
        const r = await api('login', { method: 'POST', body: { username: f.get('username'), password: f.get('password') } });
        state.csrf = r.csrf;
        if (!location.hash || location.hash === '#/') location.hash = '#/heute';
        route();
      } catch (ex) {
        err.textContent = ex.message;
        err.hidden = false;
      }
    });
  }

  // ------------------------------------------------------------------ Heute
  async function renderToday() {
    const day = state.day || null;
    $app.innerHTML = '<div class="skeleton" style="height:70px;background:none"></div><div class="skeleton" style="height:190px"></div><div class="skeleton"></div><div class="skeleton" style="height:110px"></div>';
    let d;
    try {
      d = await api('today', { params: day ? { day } : {} });
    } catch (e) { $app.innerHTML = `<div class="card">${esc(e.message)}</div>`; return; }
    destroyCharts();
    state.today = d;
    if (!state.day) state.day = d.day;
    const r = d.readiness;
    const st = statusOf(r.score);
    const v = d.values || {};
    const f = r.facts || {};
    const isToday = d.is_today;
    const sp = d.spark || {};

    const circ = 2 * Math.PI * 62;
    const pct = r.score == null ? 0 : r.score / 100;
    const comp = r.components || {};
    const meter = (label, key) => comp[key] == null ? '' :
      `<div class="meter"><div class="lbl"><span>${label}</span><b>${Math.round(comp[key])}</b></div><div class="bar"><i style="width:${Math.round(comp[key])}%"></i></div></div>`;

    const g = d.google || {};
    const banner = g.reauth
      ? `<div class="banner">${ico('warn')}<div class="grow">Die Fitbit-Verbindung ist abgelaufen. Bis zur Erneuerung nutzt dirks3n die Apple-Health-Daten.</div><a class="btn primary" href="google-connect.php">Neu verbinden</a></div>`
      : (g.needs_update ? '<div class="banner">' + ico('warn') + '<div class="grow">Datenbank-Update 002 fehlt (siehe docs/GOOGLE-HEALTH.md).</div></div>' : '');

    $app.innerHTML = `
      <header class="header fade-in">
        <div>
          <div class="eyebrow">${esc(dayLabel(d.day))}</div>
          <h1>${isToday ? esc(greeting()) : esc(dayLabel(d.day, { weekday: 'long' }))}<span class="accent">.</span></h1>
        </div>
        <div class="pillnav">
          <button class="iconbtn" id="prevDay" aria-label="Vorheriger Tag">${ico('prev')}</button>
          <button class="iconbtn" id="nextDay" aria-label="Nächster Tag" ${isToday ? 'disabled' : ''}>${ico('next')}</button>
        </div>
      </header>

      ${banner}

      <section class="card hero fade-in" style="--glow:${st.glow}">
        <div class="card-head"><div class="card-title">${ico('gauge', 'sm')}Bereitschaft</div><span class="status ${st.cls}">${ico(st.icon, 'sm')}${esc(r.label)}</span></div>
        <div class="readiness">
          <div class="ring" role="img" aria-label="Bereitschaft ${r.score ?? 'unbekannt'} von 100">
            <svg viewBox="0 0 148 148">
              <defs><linearGradient id="rg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${st.grad[0]}"/><stop offset="1" stop-color="${st.grad[1]}"/></linearGradient></defs>
              <circle class="track" cx="74" cy="74" r="62" fill="none" stroke-width="11"/>
              <circle class="value" cx="74" cy="74" r="62" fill="none" stroke-width="11" stroke="url(#rg)"
                stroke-dasharray="${circ}" stroke-dashoffset="${circ}" data-target="${circ * (1 - pct)}"/>
            </svg>
            <div class="center"><div><div class="score">${r.score ?? '–'}</div><div class="of">von 100</div></div></div>
          </div>
          <div>
            <div class="meters">${meter('HRV', 'hrv')}${meter('Schlaf', 'sleep')}${meter('Ruhepuls', 'rhr')}${meter('Belastung', 'load')}</div>
            ${r.score == null ? '<p class="small muted" style="margin:10px 0 0">Noch keine Schlaf- oder HRV-Daten für heute.</p>' : ''}
          </div>
        </div>
      </section>

      ${decisionCard(d)}

      <div class="tiles fade-in">
        ${tile('Schlaf', 'moon', v.sleep_min != null ? hm(v.sleep_min) : '–', 'h',
          v.sleep_min != null ? `Tief ${fmt(v.sleep_deep_min)} · REM ${fmt(v.sleep_rem_min)} min` : 'keine Daten', sp.sleep_min, C.s3)}
        ${tile('HRV', 'wave', fmt(v.hrv), 'ms', f.hrv && f.hrv.baseline7 ? `${signed(f.hrv.change_pct)} % zum 7-T-Schnitt` : '', sp.hrv, C.s2)}
        ${tile('Ruhepuls', 'heart', fmt(v.resting_hr), 'bpm', f.resting_hr && f.resting_hr.baseline7 ? `${signed(f.resting_hr.diff_bpm, 1)} zum 7-T-Schnitt` : '', sp.resting_hr, C.s4)}
        ${tile('Schritte', 'steps', fmt(v.steps), '', isToday && d.yesterday.steps != null ? `gestern ${fmt(d.yesterday.steps)}` : '', sp.steps, C.s1)}
        ${tile('Aktiv', 'flame', fmt(v.active_kcal), 'kcal', v.basal_kcal != null ? `gesamt ${fmt((v.active_kcal || 0) + v.basal_kcal)} kcal` : '', sp.active_kcal, C.s4)}
        ${tile(v.hr_avg != null ? 'Puls Ø' : 'Gewicht', v.hr_avg != null ? 'pulse' : 'scale',
          v.hr_avg != null ? fmt(v.hr_avg) : fmt(v.weight, 1), v.hr_avg != null ? 'bpm' : 'kg',
          v.hr_avg != null ? `${fmt(v.hr_min)}–${fmt(v.hr_max)} bpm` : (v.body_fat != null ? `Körperfett ${fmt(v.body_fat, 1)} %` : ''),
          v.hr_avg != null ? sp.hr_avg : sp.weight, C.s1)}
      </div>

      ${d.hr_intraday && d.hr_intraday.length ? `<section class="card fade-in">
        <div class="card-head"><div class="card-title">${ico('pulse', 'sm')}Puls ${isToday ? 'heute' : ''}</div><span class="tag">Fitbit · 5 min</span></div>
        <div class="chart-box" style="height:150px"><canvas id="ch-hr" role="img" aria-label="Herzfrequenz-Verlauf"></canvas></div>
        <div class="legend"><span><i style="background:${C.s1}"></i>Ø je 5 Minuten</span><span><i style="background:rgba(31,127,209,.2)"></i>Spanne min–max</span></div>
      </section>` : ''}

      ${d.workouts.length ? `<section class="card fade-in"><div class="card-head"><div class="card-title">${ico('timer', 'sm')}Trainings ${isToday ? 'heute' : ''}</div></div><ul class="list">
        ${d.workouts.map((w) => `<li><span class="badge-ico">${ico(CAT_ICON[w.category] || 'pulse', 'sm')}</span><span class="grow">${esc(w.activity)}<div class="small muted">${esc(w.start)} Uhr${w.avg_hr ? ' · Ø ' + fmt(w.avg_hr) + ' bpm' : ''}</div></span><span class="secondary num">${fmt(w.duration_min)} min</span></li>`).join('')}
      </ul></section>` : ''}

      <section class="card fade-in">
        <div class="card-head"><div class="card-title">${ico('calendar', 'sm')}Termine</div></div>
        ${d.events.length ? `<ul class="list">${d.events.map((e) => `<li><span class="time">${e.all_day ? 'ganztägig' : esc(e.start) + '–' + esc(e.end)}</span><span class="grow">${esc(e.title)}${e.location ? `<div class="small muted">${esc(e.location)}</div>` : ''}</span></li>`).join('')}</ul>`
          : '<p class="muted small" style="margin:0">Keine Termine. Kalender-Link unter „Profil“ hinterlegen.</p>'}
      </section>

      <div class="footer-info">
        ${g.connected ? `<span class="dot"></span>Fitbit: ${d.sync.google ? esc(relTime(d.sync.google.received_at)) : 'noch nie'}<br>` : ''}
        Apple Health: ${d.sync.health ? esc(relTime(d.sync.health.received_at)) : 'noch nie'} ·
        Kalender: ${d.sync.calendar ? esc(relTime(d.sync.calendar.received_at)) : 'noch nie'}
      </div>`;

    requestAnimationFrame(() => {
      const ring = $app.querySelector('.ring .value');
      if (ring) ring.style.strokeDashoffset = ring.dataset.target;
    });
    document.getElementById('prevDay').onclick = () => { state.day = addDays(d.day, -1); renderToday(); };
    document.getElementById('nextDay').onclick = () => { if (!isToday) { state.day = addDays(d.day, 1); renderToday(); } };
    const btn = document.getElementById('decideBtn');
    if (btn) btn.onclick = () => decide(d, btn);
    if (d.hr_intraday && d.hr_intraday.length) drawIntraday(d.hr_intraday);
  }

  function decisionCard(d) {
    const dec = d.decision;
    if (!dec) {
      return `<section class="card fade-in">
        <div class="card-head"><div class="card-title">${ico('target', 'sm')}Plan für heute</div></div>
        <div class="plan-title">Noch kein Plan</div>
        <p class="reason" style="margin-top:8px">${d.is_today ? 'Sobald deine Schlafdaten da sind, plant dirks3n automatisch. Oder du lässt jetzt sofort planen.' : 'Für diesen Tag gibt es keinen Plan.'}</p>
        ${d.is_today ? `<button class="btn primary block" id="decideBtn" style="margin-top:14px">${ico('spark', 'sm')}Jetzt planen</button>` : ''}
      </section>`;
    }
    const det = dec.details || {};
    const session = det.session || [];
    const warnings = det.warnings || [];
    const k = KIND[dec.kind] || { icon: 'pulse', cls: '' };
    return `<section class="card fade-in">
      <div class="card-head"><div class="card-title">${ico('target', 'sm')}${dec.train ? 'Plan für heute' : 'Heute ist Erholung'}</div>
        <span class="tag">${dec.engine === 'ai' ? 'Coach' : 'Regeln'}</span></div>
      <div class="plan-top">
        <div class="kind-ico ${k.cls}">${ico(k.icon)}</div>
        <div><div class="small muted">${esc(dec.kind)}</div><div class="plan-title">${esc(dec.focus || dec.kind)}</div></div>
      </div>
      <div class="chips">
        ${dec.train ? `<span class="chip">${ico('gauge')}<b>${esc(dec.intensity)}</b></span>` : ''}
        ${dec.duration_min ? `<span class="chip">${ico('timer')}<b>${fmt(dec.duration_min)} min</b></span>` : ''}
        ${dec.time_hint ? `<span class="chip">${ico('clock')}<b>${esc(dec.time_hint)}</b></span>` : ''}
      </div>
      <p class="reason">${esc(dec.reasoning)}</p>
      ${warnings.map((w) => `<div class="warn">${ico('warn', 'sm')}<span>${esc(w)}</span></div>`).join('')}
      ${session.length ? `<ul class="session">${session.map((s) => `<li><span>${esc(s.name)}</span><span>${esc(s.detail)}</span></li>`).join('')}</ul>` : ''}
      <div class="engine">
        <span>Aktualisiert ${esc(relTime(dec.updated_at))}</span>
        ${d.is_today ? `<span>${d.regenerations_left}× neu planbar</span>` : ''}
      </div>
      ${d.is_today && d.regenerations_left > 0 ? `<button class="btn ghost block" id="decideBtn" style="margin-top:12px">${ico('refresh', 'sm')}Neu planen</button>` : ''}
    </section>`;
  }

  async function decide(d, btn) {
    let note = '';
    if (d.decision) {
      note = window.prompt('Möchtest du etwas dazu sagen? (optional, z. B. „Bin erkältet“, „Nur 30 Minuten Zeit“, „Lieber Laufen“)', '');
      if (note === null) return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px"></span> Plane …';
    try {
      await api('decide', { method: 'POST', body: { day: d.day, note } });
      toast('Neuer Plan ist da');
    } catch (e) { toast(e.message, 5000); }
    renderToday();
  }

  /** Mini-Verlauf (7 Tage) als SVG */
  function sparkline(vals, color) {
    const pts = (vals || []).map((v, i) => [i, v]).filter((p) => p[1] != null);
    if (pts.length < 2) return '<svg class="spark" viewBox="0 0 100 28"></svg>';
    const ys = pts.map((p) => p[1]);
    const min = Math.min(...ys); const max = Math.max(...ys); const span = max - min || 1;
    const x = (i) => 2 + (i / 6) * 96; const y = (v) => 24 - ((v - min) / span) * 20;
    const line = pts.map((p, n) => `${n ? 'L' : 'M'}${x(p[0]).toFixed(1)} ${y(p[1]).toFixed(1)}`).join(' ');
    const last = pts[pts.length - 1];
    const id = 'sg' + Math.random().toString(36).slice(2, 7);
    return `<svg class="spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true">
      <defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${color}" stop-opacity=".28"/><stop offset="1" stop-color="${color}" stop-opacity="0"/></linearGradient></defs>
      <path d="${line} L${x(last[0]).toFixed(1)} 28 L${x(pts[0][0]).toFixed(1)} 28 Z" fill="url(#${id})"/>
      <path d="${line}" fill="none" stroke="${color}" stroke-width="1.8" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>`;
  }

  function tile(label, icon, val, unit, delta, spark, color) {
    return `<div class="tile"><div class="label">${ico(icon)}${esc(label)}</div>
      <div class="val">${esc(val)}${unit && val !== '–' ? `<small>${esc(unit)}</small>` : ''}</div>
      <div class="delta">${esc(delta || '')}</div>${sparkline(spark, color)}</div>`;
  }

  function relTime(s) {
    if (!s) return '';
    const t = new Date(s.replace(' ', 'T'));
    const diff = (Date.now() - t.getTime()) / 60000;
    if (diff < 1) return 'gerade eben';
    if (diff < 60) return `vor ${Math.round(diff)} min`;
    if (diff < 24 * 60 && t.getDate() === new Date().getDate()) return `heute ${t.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}`;
    return t.toLocaleString('de-DE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  // ------------------------------------------------------------------ Diagramm-Grundeinstellungen
  function chartDefaults() {
    if (!window.Chart || chartDefaults.done) return;
    chartDefaults.done = true;
    const D = window.Chart.defaults;
    D.locale = 'de-DE';
    D.font.family = getComputedStyle(document.body).fontFamily;
    D.font.size = 11;
    D.color = C.muted;
  }

  function baseOptions() {
    return {
      responsive: true, maintainAspectRatio: false, animation: { duration: 500 },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0e1116', borderColor: '#0e1116', borderWidth: 1, titleColor: '#ffffff', bodyColor: '#d5d9e0',
          padding: 10, cornerRadius: 12, displayColors: true, boxPadding: 4, usePointStyle: true,
        },
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: C.muted, maxRotation: 0, autoSkip: true, maxTicksLimit: 6 }, border: { color: C.grid } },
        y: { grid: { color: C.grid }, ticks: { color: C.muted, maxTicksLimit: 5 }, border: { display: false } },
      },
    };
  }

  function drawIntraday(rows) {
    chartDefaults();
    const el = document.getElementById('ch-hr');
    if (!el || !window.Chart) return;
    const labels = rows.map((r) => r[0]);
    const o = baseOptions();
    o.plugins.tooltip.callbacks = {
      label: (ctx) => `Ø ${fmt(ctx.parsed.y)} bpm`,
      afterLabel: (ctx) => `Spanne ${fmt(rows[ctx.dataIndex][2])}–${fmt(rows[ctx.dataIndex][3])} bpm`,
    };
    o.plugins.tooltip.filter = (item) => item.datasetIndex === 2;
    o.plugins.tooltip.displayColors = false;
    state.charts.push(new window.Chart(el, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { data: rows.map((r) => r[3]), borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: false, tension: 0.3 },
          { data: rows.map((r) => r[2]), borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: '-1', backgroundColor: 'rgba(31,127,209,.14)', tension: 0.3 },
          { data: rows.map((r) => r[1]), borderColor: C.s1, borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: C.s1, pointHoverBorderColor: C.surface, pointHoverBorderWidth: 2, tension: 0.3, fill: false },
        ],
      },
      options: o,
    }));
  }

  // ------------------------------------------------------------------ Statistik
  const RANGES = [[7, 'Woche'], [30, 'Monat'], [90, '3 Mon.'], [365, 'Jahr']];

  async function renderStats() {
    $app.innerHTML = `
      <header class="header fade-in"><div><div class="eyebrow" id="rangeLabel">&nbsp;</div><h1>Statistik<span class="accent">.</span></h1></div></header>
      <div class="segment" role="group" aria-label="Zeitraum">
        ${RANGES.map(([n, l]) => `<button data-range="${n}" aria-pressed="${state.range === n}">${l}</button>`).join('')}
      </div>
      <div id="statsBody"><div class="skeleton"></div><div class="skeleton"></div></div>`;
    $app.querySelectorAll('[data-range]').forEach((b) => b.addEventListener('click', () => { state.range = Number(b.dataset.range); renderStats(); }));

    let s;
    try { s = await api('stats', { params: { range: state.range } }); } catch (e) { document.getElementById('statsBody').innerHTML = `<div class="card">${esc(e.message)}</div>`; return; }
    destroyCharts();
    chartDefaults();
    document.getElementById('rangeLabel').textContent = `${dayLabel(s.start, { day: 'numeric', month: 'short' })} – ${dayLabel(s.end, { day: 'numeric', month: 'short', year: 'numeric' })}`;

    const weekly = s.range > 90;
    const g = weekly ? groupWeekly(s) : { labels: s.days.map(shortLabel), series: s.series, days: s.days };

    const cards = [
      { id: 'readiness', title: 'Bereitschaft', icon: 'gauge', unit: '/100', metric: 'readiness', type: 'line', dec: 0, sum: avgOf(s.series.readiness), higherIsBetter: true },
      { id: 'sleep', title: 'Schlaf', icon: 'moon', unit: 'h', metric: 'sleep_min', type: 'sleep', dec: 1, scale: 1 / 60, higherIsBetter: true },
      { id: 'hrv', title: 'HRV', icon: 'wave', unit: 'ms', metric: 'hrv', type: 'line', dec: 0, higherIsBetter: true },
      { id: 'rhr', title: 'Ruhepuls', icon: 'heart', unit: 'bpm', metric: 'resting_hr', type: 'line', dec: 0, higherIsBetter: false },
      { id: 'hr', title: 'Herzfrequenz', icon: 'pulse', unit: 'bpm', metric: 'hr_avg', type: 'hr', dec: 0, higherIsBetter: null },
      { id: 'steps', title: 'Schritte', icon: 'steps', unit: '/Tag', metric: 'steps', type: 'bar', dec: 0, higherIsBetter: true },
      { id: 'kcal', title: 'Kalorienverbrauch', icon: 'flame', unit: 'kcal/Tag', metric: 'active_kcal', type: 'kcal', dec: 0, higherIsBetter: true },
      { id: 'load', title: 'Trainingsbelastung', icon: 'timer', unit: '/Tag', metric: 'training_load', type: 'bar', dec: 0, higherIsBetter: null, zeroFill: true },
      { id: 'weight', title: 'Gewicht', icon: 'scale', unit: 'kg', metric: 'weight', type: 'line', dec: 1, higherIsBetter: null },
    ];

    const body = document.getElementById('statsBody');
    body.innerHTML = cards.map((c) => {
      const sm = s.summary[c.metric] || { avg: c.sum ?? null, prev_avg: null };
      let avg = sm.avg;
      let prev = sm.prev_avg;
      if (c.type === 'kcal' && s.summary.basal_kcal.avg != null) {
        avg = (avg || 0) + s.summary.basal_kcal.avg;
        prev = prev != null && s.summary.basal_kcal.prev_avg != null ? prev + s.summary.basal_kcal.prev_avg : null;
      }
      const scale = c.scale || 1;
      return `<section class="card fade-in">
        <div class="chart-head">
          <div><div class="card-title">${ico(c.icon, 'sm')}${esc(c.title)}</div>
            <div class="big">${avg != null ? fmt(avg * scale, c.dec) : '–'}<small>${esc(c.unit)} Ø</small></div></div>
          <div class="trend">${trendText(avg, prev, c.higherIsBetter, scale, c.dec)}</div>
        </div>
        ${hasData(g.series[c.metric]) ? `<div class="chart-box"><canvas id="ch-${c.id}" aria-label="${esc(c.title)} Diagramm" role="img"></canvas></div>${legendFor(c)}${tableFor(c, g)}` : '<div class="empty">Noch keine Daten in diesem Zeitraum</div>'}
      </section>`;
    }).join('') + workoutList(s);

    cards.forEach((c) => { if (hasData(g.series[c.metric])) drawChart(c, g); });
  }

  const shortLabel = (day) => parseDay(day).toLocaleDateString('de-DE', { day: 'numeric', month: 'numeric' });
  const hasData = (arr) => Array.isArray(arr) && arr.some((v) => v !== null && v !== undefined);
  const avgOf = (arr) => { const v = (arr || []).filter((x) => x != null); return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null; };

  function groupWeekly(s) {
    const buckets = [];
    s.days.forEach((day, i) => {
      const dt = parseDay(day);
      const monday = new Date(dt); monday.setDate(dt.getDate() - ((dt.getDay() + 6) % 7));
      const key = isoDay(monday);
      let b = buckets[buckets.length - 1];
      if (!b || b.key !== key) { b = { key, idx: [] }; buckets.push(b); }
      b.idx.push(i);
    });
    const series = {};
    Object.keys(s.series).forEach((m) => {
      series[m] = buckets.map((b) => avgOf(b.idx.map((i) => s.series[m][i])));
    });
    // Min/Max je Woche korrekt bilden (nicht mitteln)
    const pick = (m, fn) => buckets.map((b) => { const v = b.idx.map((i) => s.series[m][i]).filter((x) => x != null); return v.length ? fn(...v) : null; });
    if (s.series.hr_min) series.hr_min = pick('hr_min', Math.min);
    if (s.series.hr_max) series.hr_max = pick('hr_max', Math.max);
    return { labels: buckets.map((b) => 'KW ' + isoWeek(parseDay(b.key))), series, days: buckets.map((b) => b.key) };
  }
  function isoWeek(d) {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const dayNum = t.getUTCDay() || 7; t.setUTCDate(t.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(t.getUTCFullYear(), 0, 1));
    return Math.ceil(((t - yearStart) / 86400000 + 1) / 7);
  }

  function trendText(avg, prev, higherIsBetter, scale, dec) {
    if (avg == null || prev == null || prev === 0) return '<span class="muted">kein Vergleich</span>';
    const diff = (avg - prev) * scale;
    const pct = ((avg - prev) / Math.abs(prev)) * 100;
    if (Math.abs(pct) < 1) return '<span class="pill">gleich</span><br><span class="muted">vs. davor</span>';
    const arrow = diff > 0 ? '▲' : '▼';
    let col = 'color:var(--text-secondary)';
    if (higherIsBetter !== null) col = (diff > 0) === higherIsBetter ? 'color:var(--good)' : 'color:var(--warning)';
    return `<span class="pill" style="${col}">${arrow} ${diff > 0 ? '+' : ''}${fmt(diff, dec)}</span><br><span class="muted">vs. davor</span>`;
  }

  function legendFor(c) {
    const items = c.type === 'sleep' ? [['Tief', C.s1], ['Kern/Leicht', C.s2], ['REM', C.s3]]
      : c.type === 'kcal' ? [['Aktiv', C.s1], ['Grundumsatz', C.s2]]
      : c.type === 'hr' ? [['Ø pro Tag', C.s1], ['Spanne min–max', 'rgba(31,127,209,.22)']]
      : c.metric === 'hrv' ? [['Tageswert', C.s1], ['7-Tage-Schnitt', C.s2]] : [];
    return items.length ? `<div class="legend">${items.map(([l, col]) => `<span><i style="background:${col}"></i>${l}</span>`).join('')}</div>` : '';
  }

  function tableFor(c, g) {
    const rows = g.labels.map((l, i) => {
      const v = g.series[c.metric][i];
      if (v == null) return '';
      const shown = c.type === 'sleep' ? hm(v) : fmt(v, c.dec);
      return `<tr><td>${esc(l)}</td><td>${esc(shown)}</td></tr>`;
    }).reverse().join('');
    return `<details class="table"><summary>Als Tabelle</summary><table><thead><tr><th>Datum</th><th>${esc(c.title)}</th></tr></thead><tbody>${rows}</tbody></table></details>`;
  }

  const rolling = (arr, n) => arr.map((_, i) => avgOf(arr.slice(Math.max(0, i - n + 1), i + 1)));

  function drawChart(c, g) {
    const el = document.getElementById(`ch-${c.id}`);
    if (!el || !window.Chart) return;
    const o = baseOptions();
    const line = (data, color, extra = {}) => ({
      data, borderColor: color, backgroundColor: color, borderWidth: 2, tension: 0.35,
      pointRadius: data.filter((v) => v != null).length > 40 ? 0 : 3, pointHoverRadius: 5,
      pointBackgroundColor: color, pointBorderColor: C.surface, pointBorderWidth: 1.5,
      pointHoverBorderColor: C.surface, pointHoverBorderWidth: 2, spanGaps: true, ...extra,
    });
    const bars = (data, color, extra = {}) => ({
      data, backgroundColor: color, borderRadius: { topLeft: 5, topRight: 5 }, borderSkipped: 'start', maxBarThickness: 18,
      borderColor: C.surface, borderWidth: 0, ...extra,
    });
    const labels = g.labels;
    let cfg;

    if (c.type === 'sleep') {
      const h = (k) => g.series[k].map((v) => v == null ? null : v / 60);
      const hasStages = hasData(g.series.sleep_deep_min) || hasData(g.series.sleep_rem_min);
      cfg = {
        type: 'bar',
        data: {
          labels,
          datasets: hasStages
            ? [bars(h('sleep_deep_min'), C.s1, { label: 'Tief', borderRadius: 0 }),
               bars(h('sleep_core_min'), C.s2, { label: 'Kern/Leicht', borderRadius: 0, borderWidth: { top: 2 } }),
               bars(h('sleep_rem_min'), C.s3, { label: 'REM', borderWidth: { top: 2 } })]
            : [bars(h('sleep_min'), C.s1, { label: 'Schlaf' })],
        },
        options: { ...o, scales: { x: { ...o.scales.x, stacked: true }, y: { ...o.scales.y, stacked: true, ticks: { ...o.scales.y.ticks, callback: (v) => v + ' h' } } },
          plugins: { ...o.plugins, tooltip: { ...o.plugins.tooltip, callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${hm((ctx.parsed.y || 0) * 60)} h`,
            footer: (items) => `Gesamt: ${hm(items.reduce((a, i) => a + (i.parsed.y || 0), 0) * 60)} h` } } } },
      };
    } else if (c.type === 'kcal') {
      cfg = {
        type: 'bar',
        data: { labels, datasets: [bars(g.series.active_kcal, C.s1, { label: 'Aktiv', borderRadius: 0 }), bars(g.series.basal_kcal, C.s2, { label: 'Grundumsatz', borderWidth: { top: 2 } })] },
        options: { ...o, scales: { x: { ...o.scales.x, stacked: true }, y: { ...o.scales.y, stacked: true } },
          plugins: { ...o.plugins, tooltip: { ...o.plugins.tooltip, callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed.y)} kcal` } } } },
      };
    } else if (c.type === 'hr') {
      cfg = {
        type: 'line',
        data: { labels, datasets: [
          { data: g.series.hr_max, borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: false, spanGaps: true, tension: 0.3 },
          { data: g.series.hr_min, borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: '-1', backgroundColor: 'rgba(31,127,209,.13)', spanGaps: true, tension: 0.3 },
          line(g.series.hr_avg, C.s1, { label: 'Ø' }),
        ] },
        options: { ...o, scales: { ...o.scales, y: { ...o.scales.y, suggestedMin: Math.max(0, Math.min(...g.series.hr_min.filter((x) => x != null)) - 5) } },
          plugins: { ...o.plugins, tooltip: { ...o.plugins.tooltip, displayColors: false, filter: (it) => it.datasetIndex === 2,
          callbacks: { label: (ctx) => `Ø ${fmt(ctx.parsed.y)} bpm`, afterLabel: (ctx) => `Spanne ${fmt(g.series.hr_min[ctx.dataIndex])}–${fmt(g.series.hr_max[ctx.dataIndex])} bpm` } } } },
      };
    } else if (c.type === 'bar') {
      const data = c.zeroFill ? g.series[c.metric].map((v) => v ?? 0) : g.series[c.metric];
      cfg = {
        type: 'bar',
        data: { labels, datasets: [bars(data, C.s1, { label: c.title })] },
        options: { ...o, plugins: { ...o.plugins, tooltip: { ...o.plugins.tooltip, displayColors: false, callbacks: { label: (ctx) => `${fmt(ctx.parsed.y, c.dec)} ${c.unit}` } } } },
      };
    } else {
      const data = g.series[c.metric];
      const ds = [line(data, C.s1, { label: c.title })];
      if (c.metric === 'hrv' && data.length >= 7) ds.push(line(rolling(data, 7), C.s2, { label: '7-Tage-Schnitt', pointRadius: 0, borderDash: [5, 4] }));
      if (c.metric === 'readiness' || c.metric === 'hrv') {
        const grad = el.getContext('2d').createLinearGradient(0, 0, 0, 180);
        grad.addColorStop(0, 'rgba(31,127,209,.18)'); grad.addColorStop(1, 'rgba(31,127,209,0)');
        ds[0].fill = 'origin';
        ds[0].backgroundColor = grad;
      }
      const vals = data.filter((v) => v != null);
      const pad = vals.length ? Math.max(1, (Math.max(...vals) - Math.min(...vals)) * 0.25) : 1;
      cfg = {
        type: 'line',
        data: { labels, datasets: ds },
        options: { ...o,
          scales: { ...o.scales, y: c.metric === 'readiness'
            ? { ...o.scales.y, min: 0, max: 100 }
            : { ...o.scales.y, suggestedMin: vals.length ? Math.min(...vals) - pad : undefined, suggestedMax: vals.length ? Math.max(...vals) + pad : undefined } },
          plugins: { ...o.plugins, tooltip: { ...o.plugins.tooltip, displayColors: ds.length > 1, callbacks: { label: (ctx) => `${ds.length > 1 ? ctx.dataset.label + ': ' : ''}${fmt(ctx.parsed.y, c.dec)} ${c.unit}` } } } },
      };
    }
    state.charts.push(new window.Chart(el, cfg));
  }

  function workoutList(s) {
    if (!s.workouts.length) return `<section class="card"><div class="card-title">${ico('timer', 'sm')}Trainings</div><div class="empty">Keine Trainings in diesem Zeitraum</div></section>`;
    const total = s.workouts.reduce((a, w) => a + w.duration_min, 0);
    return `<section class="card fade-in"><div class="card-head"><div class="card-title">${ico('timer', 'sm')}Trainings</div><span class="tag">${s.workouts.length} · ${hm(total)} h</span></div><ul class="list">
      ${s.workouts.slice(0, 60).map((w) => `<li><span class="badge-ico">${ico(CAT_ICON[w.category] || 'pulse', 'sm')}</span><span class="grow">${esc(w.activity)}<div class="small muted">${esc(dayLabel(w.date, { weekday: 'short', day: 'numeric', month: 'numeric' }))}${w.avg_hr ? ' · Ø ' + fmt(w.avg_hr) + ' bpm' : ''}</div></span><span class="secondary num">${fmt(w.duration_min)} min</span></li>`).join('')}
    </ul></section>`;
  }

  // ------------------------------------------------------------------ Profil / Einstellungen
  function fitbitCard(g) {
    if (g.needs_update) {
      return `<section class="card"><div class="card-head"><div class="card-title">${ico('watch', 'sm')}Fitbit</div></div><p class="small secondary" style="margin:0">Bitte zuerst <b>private/sql/update_002_google.sql</b> in phpMyAdmin ausführen (Anleitung: docs/GOOGLE-HEALTH.md).</p></section>`;
    }
    if (!g.configured) {
      return `<section class="card"><div class="card-head"><div class="card-title">${ico('watch', 'sm')}Fitbit</div><span class="tag">nicht eingerichtet</span></div>
        <p class="small secondary" style="margin:0">Trage Client-ID, Client-Secret und encryption_key in <b>private/config.php</b> ein – Schritt für Schritt in docs/GOOGLE-HEALTH.md.</p></section>`;
    }
    if (!g.connected && !g.reauth) {
      return `<section class="card"><div class="card-head"><div class="card-title">${ico('watch', 'sm')}Fitbit</div><span class="tag">nicht verbunden</span></div>
        <p class="small secondary" style="margin:0 0 14px">Verbinde deine Charge 6 über dein Google-Konto. dirks3n liest nur – es schreibt nichts zurück.</p>
        <a class="btn primary block" href="google-connect.php">${ico('link', 'sm')}Mit Google verbinden</a></section>`;
    }
    const dev = (g.devices || []).find((x) => x.type === 'TRACKER') || (g.devices || [])[0];
    const bat = dev && dev.battery != null ? dev.battery : null;
    return `<section class="card">
      <div class="card-head"><div class="card-title">${ico('watch', 'sm')}Fitbit</div>
        ${g.reauth ? `<span class="status warning">${ico('warn', 'sm')}Abgelaufen</span>` : `<span class="status good">${ico('check', 'sm')}Verbunden</span>`}</div>
      ${g.reauth ? `<div class="warn" style="margin:0 0 14px">${ico('warn', 'sm')}<span>Google verlangt eine neue Zustimmung (im Testmodus alle 7 Tage). Einmal neu verbinden – die Daten bleiben erhalten.</span></div>` : ''}
      <dl class="kv">
        <dt>Gerät</dt><dd>${esc(dev ? dev.name : 'Fitbit')}</dd>
        ${bat != null ? `<dt>Akku</dt><dd>${fmt(bat)} %</dd>` : ''}
        ${dev && dev.last_sync ? `<dt>Uhr → App</dt><dd>${esc(relTime(dev.last_sync))}</dd>` : ''}
        <dt>App → dirks3n</dt><dd>${g.last_sync ? esc(relTime(g.last_sync)) : 'noch nie'}</dd>
        <dt>Verlauf (${fmt(g.backfill_days)} Tage)</dt><dd>${fmt(g.backfill_percent)} %</dd>
      </dl>
      ${g.backfill_percent < 100 ? `<div class="progress" style="margin:-6px 0 14px"><i style="width:${g.backfill_percent}%"></i></div>` : ''}
      ${g.missing_scopes && g.missing_scopes.length ? `<div class="warn" style="margin:0 0 14px">${ico('warn', 'sm')}<span>Nicht freigegeben: ${esc(g.missing_scopes.join(', '))}. Neu verbinden und alle Häkchen setzen.</span></div>` : ''}
      ${g.error && !g.reauth ? `<p class="small muted" style="margin:0 0 12px">${esc(g.error)}</p>` : ''}
      ${g.reauth
        ? `<a class="btn primary block" href="google-connect.php">${ico('link', 'sm')}Neu verbinden</a>`
        : `<div class="row"><button class="btn" id="gSync" type="button">${ico('refresh', 'sm')}Abrufen</button><button class="btn danger" id="gDisconnect" type="button">${ico('unlink', 'sm')}Trennen</button></div>`}
    </section>`;
  }

  async function renderSettings() {
    $app.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div>';
    let s;
    try { s = await api('settings'); } catch (e) { $app.innerHTML = `<div class="card">${esc(e.message)}</div>`; return; }
    const p = s.profile;
    const field = (name, label, type = 'text', extra = '') => `<label class="field"><span>${label}</span><input name="${name}" type="${type}" value="${esc(p[name] ?? '')}" ${extra}></label>`;
    const versionOk = (v) => { const [maj, min] = String(v).split('.').map(Number); return maj > 8 || (maj === 8 && min >= 1); };
    $app.innerHTML = `
      <header class="header fade-in"><div><div class="eyebrow">Ziele · Quellen · Konto</div><h1>Profil<span class="accent">.</span></h1></div></header>

      ${fitbitCard(s.google || {})}

      <form id="profileForm">
        <section class="card">
          <div class="card-head"><div class="card-title">${ico('target', 'sm')}Ziel & Erfahrung</div></div>
          ${field('goal', 'Dein Ziel (z. B. „4 kg Muskeln in 12 Wochen“)')}
          ${field('goal_deadline', 'Bis wann?', 'date')}
          <label class="field"><span>Trainingserfahrung</span><select name="experience">
            ${['Einsteiger', 'Fortgeschrittener Anfänger', 'Fortgeschritten', 'Sehr erfahren'].map((o) => `<option ${p.experience === o ? 'selected' : ''}>${o}</option>`).join('')}
          </select></label>
          ${field('sports', 'Sportarten (z. B. Krafttraining, Laufen)')}
          ${field('equipment', 'Ausstattung (z. B. Fitnessstudio, Kurzhanteln zu Hause)')}
          <div class="row">${field('sleep_target_h', 'Schlafziel (h)', 'number', 'step="0.25" min="5" max="11"')}${field('session_max_min', 'Max. Dauer (min)', 'number', 'step="5" min="15" max="240"')}</div>
          <div class="row">${field('height_cm', 'Größe (cm)', 'number', 'min="100" max="230"')}${field('birth_year', 'Geburtsjahr', 'number', 'min="1930" max="2015"')}</div>
          <label class="field"><span>Geschlecht</span><select name="sex">
            ${[['', '–'], ['m', 'männlich'], ['w', 'weiblich'], ['d', 'divers']].map(([v, l]) => `<option value="${v}" ${p.sex === v ? 'selected' : ''}>${l}</option>`).join('')}
          </select></label>
          <label class="field"><span>Hinweise für den Coach (Verletzungen, Vorlieben …)</span><textarea name="notes">${esc(p.notes)}</textarea></label>
        </section>

        <section class="card">
          <div class="card-head"><div class="card-title">${ico('calendar', 'sm')}Kalender</div></div>
          <label class="field"><span>Private Kalender-Links (ICS), einer pro Zeile</span>
            <textarea name="calendar_urls" placeholder="webcal://p01-caldav.icloud.com/…">${esc((s.calendar_urls || []).join('\n'))}</textarea></label>
          <button type="button" class="btn block" id="calSync">${ico('refresh', 'sm')}Kalender jetzt laden</button>
        </section>

        <button class="btn primary block" type="submit">Speichern</button>
      </form>

      <section class="card" style="margin-top:14px">
        <div class="card-head"><div class="card-title">${ico('phone', 'sm')}iPhone-Kurzbefehl (Apple Health)</div></div>
        <p class="small secondary" style="margin-top:0">Für Gewicht & als Ersatzquelle. Anleitung: docs/KURZBEFEHL.md</p>
        <div class="small muted">Adresse (URL)</div>
        <div class="code" style="margin:4px 0 10px">${esc(s.ingest_url)}</div>
        <div class="small muted">Header „Authorization“</div>
        <div class="code" id="tokenBox" style="margin:4px 0 12px">Bearer ••••••••••••</div>
        <div class="row"><button class="btn" id="showToken" type="button">Anzeigen</button><button class="btn" id="copyToken" type="button">Kopieren</button></div>
      </section>

      <section class="card">
        <div class="card-head"><div class="card-title">${ico('spark', 'sm')}Coach</div><span class="status ${s.ai.configured ? 'good' : 'none'}">${ico(s.ai.configured ? 'check' : 'dash', 'sm')}${s.ai.configured ? 'Aktiv' : 'Aus'}</span></div>
        <dl class="kv" style="margin-bottom:6px"><dt>Budget diesen Monat</dt><dd>${fmt(s.ai.spent_usd, 2)} $ / ${fmt(s.ai.budget_usd, 2)} $</dd></dl>
        <div class="progress"><i style="width:${Math.min(100, (s.ai.spent_usd / (s.ai.budget_usd || 1)) * 100)}%"></i></div>
        <p class="small muted" style="margin:10px 0 0">${s.ai.configured ? 'Ist das Budget aufgebraucht, plant dirks3n mit festen Regeln weiter.' : 'Ohne API-Schlüssel plant dirks3n mit festen Regeln.'}</p>
      </section>

      <section class="card">
        <div class="card-head"><div class="card-title">${ico('server', 'sm')}Server</div></div>
        <dl class="kv" style="margin:0">
          <dt>PHP</dt><dd>${esc(s.server.php)} ${versionOk(s.server.php) ? '✓' : '⚠︎ mind. 8.1'}</dd>
          <dt>Max. Laufzeit</dt><dd>${esc(s.server.max_execution_time)} s ${s.server.max_execution_time === 0 || s.server.max_execution_time >= 60 ? '✓' : '⚠︎ 60 s empfohlen'}</dd>
          <dt>Speicher / Upload</dt><dd>${esc(s.server.memory_limit)} / ${esc(s.server.upload_max_filesize)}</dd>
          <dt>HTTPS</dt><dd>${s.server.https ? 'aktiv ✓' : '⚠︎ nicht aktiv'}</dd>
        </dl>
      </section>

      <button class="btn ghost block" id="logout">${ico('logout', 'sm')}Abmelden</button>`;

    const tokenText = 'Bearer ' + s.ingest_token;
    document.getElementById('showToken').onclick = () => { document.getElementById('tokenBox').textContent = tokenText; };
    document.getElementById('copyToken').onclick = async () => {
      try { await navigator.clipboard.writeText(tokenText); toast('Kopiert'); } catch (e) { document.getElementById('tokenBox').textContent = tokenText; toast('Bitte manuell kopieren'); }
    };
    document.getElementById('logout').onclick = async () => { await api('logout', { method: 'POST' }); state.day = null; renderLogin(); };
    document.getElementById('calSync').onclick = async (e) => {
      const b = e.currentTarget; b.disabled = true;
      try { await saveSettings(); const r = await api('calendar_sync', { method: 'POST' }); toast(r.message, 4500); } catch (ex) { toast(ex.message, 5000); }
      b.disabled = false;
    };
    const gs = document.getElementById('gSync');
    if (gs) gs.onclick = async () => {
      gs.disabled = true; gs.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span> Lade …';
      try { const r = await api('google_sync', { method: 'POST' }); toast(r.message, 5000); } catch (ex) { toast(ex.message, 5000); }
      renderSettings();
    };
    const gd = document.getElementById('gDisconnect');
    if (gd) gd.onclick = async () => {
      if (!window.confirm('Fitbit-Verbindung wirklich trennen? Bereits geladene Daten bleiben erhalten.')) return;
      try { await api('google_disconnect', { method: 'POST' }); toast('Getrennt'); } catch (ex) { toast(ex.message, 5000); }
      renderSettings();
    };
    document.getElementById('profileForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await saveSettings(); toast('Gespeichert'); } catch (ex) { toast(ex.message, 5000); }
    });
  }

  async function saveSettings() {
    const form = document.getElementById('profileForm');
    const fd = new FormData(form);
    const profile = {};
    ['goal', 'goal_deadline', 'experience', 'sports', 'equipment', 'sleep_target_h', 'session_max_min', 'height_cm', 'birth_year', 'sex', 'notes']
      .forEach((k) => { profile[k] = fd.get(k); });
    const urls = String(fd.get('calendar_urls') || '').split('\n').map((u) => u.trim()).filter(Boolean);
    return api('settings', { method: 'POST', body: { profile, calendar_urls: urls } });
  }

  // ------------------------------------------------------------------ Router
  async function route() {
    const tab = (location.hash.replace('#/', '') || 'heute').split('?')[0];
    destroyCharts();
    $tabbar.hidden = false;
    $tabbar.querySelectorAll('a').forEach((a) => a.classList.toggle('active', a.dataset.tab === tab));
    window.scrollTo(0, 0);
    if (tab === 'statistik') return renderStats();
    if (tab === 'einstellungen') return renderSettings();
    return renderToday();
  }

  async function boot() {
    try {
      const s = await api('session');
      state.csrf = s.csrf;
      if (!s.loggedIn) return renderLogin(s.hasUser);
      if (!location.hash) location.hash = '#/heute';
      route();
    } catch (e) {
      $app.innerHTML = `<div class="card">Server nicht erreichbar: ${esc(e.message)}</div>`;
    }
  }

  // Beim Zurückkehren in die App (z. B. nach dem Kurzbefehl) Heute-Ansicht auffrischen
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && (location.hash === '#/heute' || !location.hash) && state.csrf && !$tabbar.hidden) {
      const last = renderToday._last || 0;
      if (Date.now() - last > 60000) { renderToday._last = Date.now(); renderToday(); }
    }
  });

  window.addEventListener('hashchange', () => {
    if (!state.csrf || $tabbar.hidden) return; // nicht angemeldet
    if (location.hash === '#/heute') state.day = null;
    route();
  });

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
  boot();
})();
