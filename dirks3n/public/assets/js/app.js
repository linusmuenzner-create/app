/* dirks3n – App (ohne Framework, ohne Build-Schritt) */
(function () {
  'use strict';

  // ------------------------------------------------------------------ Grundlagen
  const $app = document.getElementById('app');
  const $tabbar = document.getElementById('tabbar');
  const $toast = document.getElementById('toast');
  const state = { csrf: '', charts: [], day: null, range: 30, today: null };

  const COLORS = {
    s1: '#3987e5', s2: '#d95926', s3: '#199e70', s4: '#c98500',
    text: '#b4b3ab', muted: '#85847d', grid: '#26262a', surface: '#161618',
    good: '#2fb344', ok: '#199e70', warning: '#e0a100', critical: '#e66767',
  };

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n, d = 0) => (n === null || n === undefined || Number.isNaN(n)) ? '–' : Number(n).toLocaleString('de-DE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const hm = (min) => { if (min == null) return '–'; const h = Math.floor(min / 60); const m = Math.round(min % 60); return `${h}:${String(m).padStart(2, '0')}`; };
  const isoDay = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const parseDay = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
  const addDays = (s, n) => { const d = parseDay(s); d.setDate(d.getDate() + n); return isoDay(d); };
  const dayLabel = (s, opts = { weekday: 'long', day: 'numeric', month: 'long' }) => parseDay(s).toLocaleDateString('de-DE', opts);

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

  // ------------------------------------------------------------------ Icons
  const ICON = {
    prev: '<svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>',
    next: '<svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>',
    refresh: '<svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/></svg>',
    up: '<svg viewBox="0 0 24 24"><path d="M5 12l5 5L20 7"/></svg>',
    warn: '<svg viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
    down: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12l7 7 7-7"/></svg>',
    dash: '<svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg>',
  };
  const KIND_EMOJI = { 'Kraft': '🏋️', 'Ausdauer': '🏃', 'HIIT': '⚡', 'Mobilität': '🧘', 'Aktive Erholung': '🚶', 'Ruhetag': '😴' };

  function statusOf(score) {
    if (score == null) return { cls: 'none', icon: ICON.dash, color: COLORS.muted };
    if (score >= 75) return { cls: 'good', icon: ICON.up, color: COLORS.good };
    if (score >= 60) return { cls: 'ok', icon: ICON.up, color: COLORS.ok };
    if (score >= 45) return { cls: 'warning', icon: ICON.warn, color: COLORS.warning };
    return { cls: 'critical', icon: ICON.warn, color: COLORS.critical };
  }

  // ------------------------------------------------------------------ Login
  function renderLogin(hasUser = true) {
    destroyCharts();
    $tabbar.hidden = true;
    $app.innerHTML = `
      <div class="login">
        <div class="logo">dirks<span>3</span>n</div>
        <p class="secondary">Dein persönlicher KI-Fitness-Coach.</p>
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
    $app.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton" style="height:90px"></div>';
    let d;
    try {
      d = await api('today', { params: day ? { day } : {} });
    } catch (e) { $app.innerHTML = `<div class="card">${esc(e.message)}</div>`; return; }
    state.today = d;
    if (!state.day) state.day = d.day;
    const r = d.readiness;
    const st = statusOf(r.score);
    const v = d.values || {};
    const f = r.facts || {};
    const isToday = d.is_today;

    const circ = 2 * Math.PI * 52;
    const pct = r.score == null ? 0 : r.score / 100;
    const comp = r.components || {};
    const compRow = (label, key) => comp[key] == null ? '' :
      `<div class="bar-row"><span>${label}</span><div class="bar"><i style="width:${Math.round(comp[key])}%"></i></div><b>${Math.round(comp[key])}</b></div>`;

    $app.innerHTML = `
      <header class="header">
        <div>
          <h1>${isToday ? 'Heute' : esc(dayLabel(d.day, { weekday: 'long' }))}</h1>
          <div class="sub">${esc(dayLabel(d.day))}</div>
        </div>
        <div class="daynav">
          <button class="iconbtn" id="prevDay" aria-label="Vorheriger Tag">${ICON.prev}</button>
          <button class="iconbtn" id="nextDay" aria-label="Nächster Tag" ${isToday ? 'disabled' : ''}>${ICON.next}</button>
        </div>
      </header>

      <section class="card">
        <h2>Bereitschaft</h2>
        <div class="readiness">
          <div class="ring" role="img" aria-label="Bereitschaft ${r.score ?? 'unbekannt'} von 100">
            <svg viewBox="0 0 120 120"><circle class="track" cx="60" cy="60" r="52" fill="none" stroke-width="10"/>
              <circle class="value" cx="60" cy="60" r="52" fill="none" stroke-width="10" stroke="${st.color}"
                stroke-dasharray="${circ}" stroke-dashoffset="${circ * (1 - pct)}"/></svg>
            <div class="center"><div><div class="score">${r.score ?? '–'}</div><div class="of">von 100</div></div></div>
          </div>
          <div>
            <div class="status ${st.cls}">${st.icon}<span>${esc(r.label)}</span></div>
            <div class="bars">
              ${compRow('HRV', 'hrv')}${compRow('Schlaf', 'sleep')}${compRow('Ruhepuls', 'rhr')}${compRow('Belastung', 'load')}
            </div>
            ${r.score == null ? '<p class="small muted">Noch keine Schlaf-/HRV-Daten. Starte den Kurzbefehl auf dem iPhone.</p>' : ''}
          </div>
        </div>
      </section>

      ${decisionCard(d)}

      <div class="tiles">
        ${tile('Schlaf', v.sleep_min != null ? hm(v.sleep_min) : '–', 'h',
          v.sleep_min != null ? `Tief ${fmt(v.sleep_deep_min)} · REM ${fmt(v.sleep_rem_min)} min` : 'keine Daten')}
        ${tile('HRV', fmt(v.hrv), 'ms', f.hrv && f.hrv.baseline7 ? `Ø 7 T: ${fmt(f.hrv.baseline7)} ms (${signed(f.hrv.change_pct)} %)` : '')}
        ${tile('Ruhepuls', fmt(v.resting_hr), 'bpm', f.resting_hr && f.resting_hr.baseline7 ? `Ø 7 T: ${fmt(f.resting_hr.baseline7)} (${signed(f.resting_hr.diff_bpm, 1)})` : '')}
        ${tile('Schritte', fmt(v.steps), '', isToday && d.yesterday.steps != null ? `gestern ${fmt(d.yesterday.steps)}` : '')}
        ${tile('Aktiv', fmt(v.active_kcal), 'kcal', v.basal_kcal != null ? `gesamt ${fmt((v.active_kcal || 0) + v.basal_kcal)} kcal` : '')}
        ${tile('Gewicht', fmt(v.weight, 1), 'kg', v.body_fat != null ? `Körperfett ${fmt(v.body_fat, 1)} %` : '')}
      </div>

      ${d.workouts.length ? `<section class="card"><h2>Trainings ${isToday ? 'heute' : ''}</h2><ul class="list">
        ${d.workouts.map((w) => `<li><span class="time">${esc(w.start)}</span><span class="grow">${esc(w.activity)}</span><span class="secondary">${fmt(w.duration_min)} min${w.energy_kcal ? ' · ' + fmt(w.energy_kcal) + ' kcal' : ''}</span></li>`).join('')}
      </ul></section>` : ''}

      <section class="card">
        <h2>Termine</h2>
        ${d.events.length ? `<ul class="list">${d.events.map((e) => `<li><span class="time">${e.all_day ? 'ganztägig' : esc(e.start) + '–' + esc(e.end)}</span><span class="grow">${esc(e.title)}${e.location ? `<div class="small muted">${esc(e.location)}</div>` : ''}</span></li>`).join('')}</ul>`
          : '<p class="muted small" style="margin:0">Keine Termine. Kalender-Link unter „Profil“ hinterlegen.</p>'}
      </section>

      <div class="footer-info">
        Letzte Gesundheitsdaten: ${d.sync.health ? esc(relTime(d.sync.health.received_at)) + ` (${fmt(d.sync.health.items)} Werte)` : 'noch nie'}<br>
        Kalender: ${d.sync.calendar ? esc(relTime(d.sync.calendar.received_at)) : 'noch nie'} ·
        KI diesen Monat: ${fmt(d.ai.spent_usd, 2)} $ von ${fmt(d.ai.budget_usd, 2)} $
      </div>`;

    document.getElementById('prevDay').onclick = () => { state.day = addDays(d.day, -1); renderToday(); };
    document.getElementById('nextDay').onclick = () => { if (!isToday) { state.day = addDays(d.day, 1); renderToday(); } };
    const btn = document.getElementById('decideBtn');
    if (btn) btn.onclick = () => decide(d, btn);
  }

  function decisionCard(d) {
    const dec = d.decision;
    if (!dec) {
      return `<section class="card decision">
        <h2>Training</h2>
        <h3>Noch keine Entscheidung</h3>
        <p class="reason">${d.is_today ? 'Sobald morgens deine Schlafdaten ankommen, entscheidet dirks3n automatisch. Du kannst auch jetzt sofort entscheiden lassen.' : 'Für diesen Tag gibt es keine Entscheidung.'}</p>
        ${d.is_today ? '<button class="btn primary block" id="decideBtn" style="margin-top:12px">Jetzt entscheiden</button>' : ''}
      </section>`;
    }
    const det = dec.details || {};
    const session = det.session || [];
    const warnings = det.warnings || [];
    const title = dec.train ? `${esc(dec.kind)}${dec.focus ? ' · ' + esc(dec.focus) : ''}` : esc(dec.kind === 'Ruhetag' ? 'Ruhetag' : dec.kind);
    return `<section class="card decision">
      <h2>${dec.train ? 'Dein Training' : 'Heute kein Training'}</h2>
      <div class="kind"><span class="emoji" aria-hidden="true">${KIND_EMOJI[dec.kind] || '💪'}</span><h3>${title}</h3></div>
      <div class="chips">
        ${dec.train ? `<span class="chip">Intensität <b>${esc(dec.intensity)}</b></span>` : ''}
        ${dec.duration_min ? `<span class="chip">Dauer <b>${fmt(dec.duration_min)} min</b></span>` : ''}
        ${dec.time_hint ? `<span class="chip">Wann <b>${esc(dec.time_hint)}</b></span>` : ''}
      </div>
      <p class="reason">${esc(dec.reasoning)}</p>
      ${warnings.map((w) => `<div class="warn">${ICON.warn.replace('<svg', '<svg width="18" height="18" style="flex:none;fill:none;stroke:currentColor;stroke-width:2"')}<span>${esc(w)}</span></div>`).join('')}
      ${session.length ? `<ul class="session">${session.map((s) => `<li><span>${esc(s.name)}</span><span>${esc(s.detail)}</span></li>`).join('')}</ul>` : ''}
      <div class="engine">
        <span>${dec.engine === 'ai' ? 'Entschieden von der KI' : 'Entschieden nach Regeln'} · ${esc(relTime(dec.updated_at))}</span>
        ${d.is_today ? `<span>${d.regenerations_left} × neu möglich</span>` : ''}
      </div>
      ${d.is_today && d.regenerations_left > 0 ? `<button class="btn block" id="decideBtn" style="margin-top:12px">${ICON.refresh.replace('<svg', '<svg width="18" height="18" style="fill:none;stroke:currentColor;stroke-width:2"')} Neu entscheiden</button>` : ''}
    </section>`;
  }

  async function decide(d, btn) {
    let note = '';
    if (d.decision) {
      note = window.prompt('Möchtest du etwas dazu sagen? (optional, z. B. „Bin erkältet“, „Nur 30 Minuten Zeit“, „Lieber Laufen“)', '') ;
      if (note === null) return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px"></span> Entscheide …';
    try {
      await api('decide', { method: 'POST', body: { day: d.day, note } });
      toast('Neue Entscheidung ist da');
    } catch (e) { toast(e.message, 5000); }
    renderToday();
  }

  function tile(label, val, unit, delta) {
    return `<div class="tile"><div class="label">${esc(label)}</div><div class="val">${esc(val)}${unit && val !== '–' ? `<small>${esc(unit)}</small>` : ''}</div>${delta ? `<div class="delta">${esc(delta)}</div>` : ''}</div>`;
  }
  const signed = (n, d = 0) => (n == null ? '–' : (n > 0 ? '+' : '') + fmt(n, d));

  function relTime(s) {
    if (!s) return '';
    const t = new Date(s.replace(' ', 'T'));
    const diff = (Date.now() - t.getTime()) / 60000;
    if (diff < 1) return 'gerade eben';
    if (diff < 60) return `vor ${Math.round(diff)} min`;
    if (diff < 24 * 60 && t.getDate() === new Date().getDate()) return `heute ${t.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}`;
    return t.toLocaleString('de-DE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  // ------------------------------------------------------------------ Statistik
  const RANGES = [[7, 'Woche'], [30, 'Monat'], [90, '3 Monate'], [365, 'Jahr']];

  async function renderStats() {
    $app.innerHTML = `
      <header class="header"><div><h1>Statistik</h1><div class="sub" id="rangeLabel">&nbsp;</div></div></header>
      <div class="segment" role="group" aria-label="Zeitraum">
        ${RANGES.map(([n, l]) => `<button data-range="${n}" aria-pressed="${state.range === n}">${l}</button>`).join('')}
      </div>
      <div id="statsBody"><div class="skeleton"></div><div class="skeleton"></div></div>`;
    $app.querySelectorAll('[data-range]').forEach((b) => b.addEventListener('click', () => { state.range = Number(b.dataset.range); renderStats(); }));

    let s;
    try { s = await api('stats', { params: { range: state.range } }); } catch (e) { document.getElementById('statsBody').innerHTML = `<div class="card">${esc(e.message)}</div>`; return; }
    destroyCharts();
    document.getElementById('rangeLabel').textContent = `${dayLabel(s.start, { day: 'numeric', month: 'short' })} – ${dayLabel(s.end, { day: 'numeric', month: 'short', year: 'numeric' })}`;

    // Bei "Jahr" auf Wochen zusammenfassen (übersichtlicher)
    const weekly = s.range > 90;
    const g = weekly ? groupWeekly(s) : { labels: s.days.map(shortLabel), series: s.series, days: s.days };

    const cards = [
      { id: 'readiness', title: 'Bereitschaft', unit: '/100', metric: 'readiness', type: 'line', dec: 0, sum: avgOf(s.series.readiness), higherIsBetter: true },
      { id: 'sleep', title: 'Schlaf', unit: 'h', metric: 'sleep_min', type: 'sleep', dec: 1, scale: 1 / 60, higherIsBetter: true },
      { id: 'hrv', title: 'HRV', unit: 'ms', metric: 'hrv', type: 'line', dec: 0, higherIsBetter: true },
      { id: 'rhr', title: 'Ruhepuls', unit: 'bpm', metric: 'resting_hr', type: 'line', dec: 0, higherIsBetter: false },
      { id: 'steps', title: 'Schritte', unit: '/Tag', metric: 'steps', type: 'bar', dec: 0, higherIsBetter: true },
      { id: 'kcal', title: 'Kalorienverbrauch', unit: 'kcal/Tag', metric: 'active_kcal', type: 'kcal', dec: 0, higherIsBetter: true },
      { id: 'load', title: 'Trainingsbelastung', unit: '/Tag', metric: 'training_load', type: 'bar', dec: 0, higherIsBetter: null, zeroFill: true },
      { id: 'weight', title: 'Gewicht', unit: 'kg', metric: 'weight', type: 'line', dec: 1, higherIsBetter: null, spanGaps: true },
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
      return `<section class="card">
        <div class="chart-head">
          <div><h2 style="margin-bottom:4px">${esc(c.title)}</h2>
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
    if (Math.abs(pct) < 1) return 'gleich wie davor';
    const arrow = diff > 0 ? '▲' : '▼';
    let cls = '';
    if (higherIsBetter !== null) cls = (diff > 0) === higherIsBetter ? 'color:var(--good)' : 'color:var(--warning)';
    return `<span style="${cls}">${arrow} ${diff > 0 ? '+' : ''}${fmt(diff, dec)}</span><br><span class="muted">vs. Zeitraum davor</span>`;
  }

  function legendFor(c) {
    const items = c.type === 'sleep' ? [['Tief', COLORS.s1], ['REM', COLORS.s2], ['Kern/Leicht', COLORS.s3]]
      : c.type === 'kcal' ? [['Aktiv', COLORS.s1], ['Grundumsatz', COLORS.s2]]
      : c.metric === 'hrv' ? [['Tageswert', COLORS.s1], ['7-Tage-Schnitt', COLORS.s2]] : [];
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

  function rolling(arr, n) {
    return arr.map((_, i) => avgOf(arr.slice(Math.max(0, i - n + 1), i + 1)));
  }

  function drawChart(c, g) {
    const el = document.getElementById(`ch-${c.id}`);
    if (!el || !window.Chart) return;
    window.Chart.defaults.locale = 'de-DE';
    const baseOpts = {
      responsive: true, maintainAspectRatio: false, animation: { duration: 400 },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#1f1f22', borderColor: '#2a2a2e', borderWidth: 1, titleColor: '#f0efec', bodyColor: '#b4b3ab',
          padding: 10, cornerRadius: 10, displayColors: true, boxPadding: 4,
        },
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: COLORS.muted, maxRotation: 0, autoSkip: true, maxTicksLimit: 7, font: { size: 11 } }, border: { color: COLORS.grid } },
        y: { grid: { color: COLORS.grid }, ticks: { color: COLORS.muted, maxTicksLimit: 5, font: { size: 11 } }, border: { display: false } },
      },
    };
    const line = (data, color, extra = {}) => ({
      data, borderColor: color, backgroundColor: color, borderWidth: 2, tension: 0.35,
      pointRadius: data.filter((v) => v != null).length > 40 ? 0 : 3, pointHoverRadius: 5, pointHoverBorderColor: COLORS.surface, pointHoverBorderWidth: 2,
      spanGaps: true, ...extra,
    });
    const bars = (data, color, extra = {}) => ({
      data, backgroundColor: color, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'start', maxBarThickness: 22,
      borderColor: COLORS.surface, borderWidth: 0, ...extra,
    });
    let cfg;
    const labels = g.labels;

    if (c.type === 'sleep') {
      const deep = g.series.sleep_deep_min.map((v) => v == null ? null : v / 60);
      const rem = g.series.sleep_rem_min.map((v) => v == null ? null : v / 60);
      const core = g.series.sleep_core_min.map((v) => v == null ? null : v / 60);
      const total = g.series.sleep_min.map((v) => v == null ? null : v / 60);
      const hasStages = hasData(g.series.sleep_deep_min) || hasData(g.series.sleep_rem_min);
      const fmtH = (ctx) => `${ctx.dataset.label}: ${hm((ctx.parsed.y || 0) * 60)} h`;
      cfg = {
        type: 'bar',
        data: {
          labels,
          datasets: hasStages
            ? [bars(deep, COLORS.s1, { label: 'Tief', borderRadius: 0 }), bars(rem, COLORS.s2, { label: 'REM', borderRadius: 0, borderWidth: { top: 2 } }), bars(core, COLORS.s3, { label: 'Kern/Leicht', borderWidth: { top: 2 } })]
            : [bars(total, COLORS.s1, { label: 'Schlaf' })],
        },
        options: { ...baseOpts, scales: { x: { ...baseOpts.scales.x, stacked: true }, y: { ...baseOpts.scales.y, stacked: true, ticks: { ...baseOpts.scales.y.ticks, callback: (v) => v + ' h' } } },
          plugins: { ...baseOpts.plugins, tooltip: { ...baseOpts.plugins.tooltip, callbacks: { label: fmtH, footer: (items) => `Gesamt: ${hm(items.reduce((a, i) => a + (i.parsed.y || 0), 0) * 60)} h` } } } },
      };
    } else if (c.type === 'kcal') {
      cfg = {
        type: 'bar',
        data: { labels, datasets: [bars(g.series.active_kcal, COLORS.s1, { label: 'Aktiv', borderRadius: 0 }), bars(g.series.basal_kcal, COLORS.s2, { label: 'Grundumsatz', borderWidth: { top: 2 } })] },
        options: { ...baseOpts, scales: { x: { ...baseOpts.scales.x, stacked: true }, y: { ...baseOpts.scales.y, stacked: true } },
          plugins: { ...baseOpts.plugins, tooltip: { ...baseOpts.plugins.tooltip, callbacks: { label: (ctx) => `${ctx.dataset.label}: ${fmt(ctx.parsed.y)} kcal` } } } },
      };
    } else if (c.type === 'bar') {
      const data = c.zeroFill ? g.series[c.metric].map((v) => v ?? 0) : g.series[c.metric];
      cfg = {
        type: 'bar',
        data: { labels, datasets: [bars(data, COLORS.s1, { label: c.title })] },
        options: { ...baseOpts, plugins: { ...baseOpts.plugins, tooltip: { ...baseOpts.plugins.tooltip, displayColors: false, callbacks: { label: (ctx) => `${fmt(ctx.parsed.y, c.dec)} ${c.unit}` } } } },
      };
    } else {
      const data = g.series[c.metric];
      const ds = [line(data, COLORS.s1, { label: c.title })];
      if (c.metric === 'hrv' && data.length >= 7) ds.push(line(rolling(data, 7), COLORS.s2, { label: '7-Tage-Schnitt', pointRadius: 0, borderDash: [5, 4] }));
      const vals = data.filter((v) => v != null);
      const pad = vals.length ? Math.max(1, (Math.max(...vals) - Math.min(...vals)) * 0.25) : 1;
      cfg = {
        type: 'line',
        data: { labels, datasets: ds },
        options: { ...baseOpts,
          scales: { ...baseOpts.scales, y: c.metric === 'readiness'
            ? { ...baseOpts.scales.y, min: 0, max: 100 }
            : { ...baseOpts.scales.y, suggestedMin: vals.length ? Math.min(...vals) - pad : undefined, suggestedMax: vals.length ? Math.max(...vals) + pad : undefined } },
          plugins: { ...baseOpts.plugins, tooltip: { ...baseOpts.plugins.tooltip, displayColors: ds.length > 1, callbacks: { label: (ctx) => `${ds.length > 1 ? ctx.dataset.label + ': ' : ''}${fmt(ctx.parsed.y, c.dec)} ${c.unit}` } } } },
      };
    }
    state.charts.push(new window.Chart(el, cfg));
  }

  function workoutList(s) {
    if (!s.workouts.length) return '<section class="card"><h2>Trainings</h2><div class="empty">Keine Trainings in diesem Zeitraum</div></section>';
    const total = s.workouts.reduce((a, w) => a + w.duration_min, 0);
    return `<section class="card"><h2>Trainings (${s.workouts.length} · ${hm(total)} h)</h2><ul class="list">
      ${s.workouts.slice(0, 60).map((w) => `<li><span class="time">${esc(dayLabel(w.date, { weekday: 'short', day: 'numeric', month: 'numeric' }))}</span><span class="grow">${esc(w.activity)}</span><span class="secondary">${fmt(w.duration_min)} min</span></li>`).join('')}
    </ul></section>`;
  }

  // ------------------------------------------------------------------ Einstellungen
  async function renderSettings() {
    $app.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div>';
    let s;
    try { s = await api('settings'); } catch (e) { $app.innerHTML = `<div class="card">${esc(e.message)}</div>`; return; }
    const p = s.profile;
    const field = (name, label, type = 'text', extra = '') => `<label class="field"><span>${label}</span><input name="${name}" type="${type}" value="${esc(p[name] ?? '')}" ${extra}></label>`;
    $app.innerHTML = `
      <header class="header"><div><h1>Profil</h1><div class="sub">Ziele, Kalender und Datenübertragung</div></div></header>

      <form id="profileForm">
        <section class="card">
          <h2>Ziel & Erfahrung</h2>
          ${field('goal', 'Dein Ziel (z. B. „4 kg Muskeln in 12 Wochen“)')}
          ${field('goal_deadline', 'Bis wann?', 'date')}
          <label class="field"><span>Trainingserfahrung</span><select name="experience">
            ${['Einsteiger', 'Fortgeschrittener Anfänger', 'Fortgeschritten', 'Sehr erfahren'].map((o) => `<option ${p.experience === o ? 'selected' : ''}>${o}</option>`).join('')}
          </select></label>
          ${field('sports', 'Sportarten (z. B. Krafttraining, Laufen)')}
          ${field('equipment', 'Ausstattung (z. B. Fitnessstudio, Kurzhanteln zu Hause)')}
          <div class="row">${field('sleep_target_h', 'Schlafziel (h)', 'number', 'step="0.25" min="5" max="11"')}${field('session_max_min', 'Max. Trainingsdauer (min)', 'number', 'step="5" min="15" max="240"')}</div>
          <div class="row">${field('height_cm', 'Größe (cm)', 'number', 'min="100" max="230"')}${field('birth_year', 'Geburtsjahr', 'number', 'min="1930" max="2015"')}</div>
          <label class="field"><span>Geschlecht</span><select name="sex">
            ${[['', '–'], ['m', 'männlich'], ['w', 'weiblich'], ['d', 'divers']].map(([v, l]) => `<option value="${v}" ${p.sex === v ? 'selected' : ''}>${l}</option>`).join('')}
          </select></label>
          <label class="field"><span>Hinweise für den Coach (Verletzungen, Vorlieben …)</span><textarea name="notes">${esc(p.notes)}</textarea></label>
        </section>

        <section class="card">
          <h2>Kalender</h2>
          <label class="field"><span>Private Kalender-Links (ICS), einer pro Zeile</span>
            <textarea name="calendar_urls" placeholder="webcal://p01-caldav.icloud.com/…">${esc((s.calendar_urls || []).join('\n'))}</textarea></label>
          <button type="button" class="btn block" id="calSync">Kalender jetzt laden</button>
        </section>

        <button class="btn primary block" type="submit">Speichern</button>
      </form>

      <section class="card" style="margin-top:14px">
        <h2>iPhone-Kurzbefehl</h2>
        <p class="small secondary" style="margin-top:0">Diese Angaben brauchst du für den Kurzbefehl (Anleitung: docs/KURZBEFEHL.md).</p>
        <div class="small muted">Adresse (URL)</div>
        <div class="code" style="margin:4px 0 10px">${esc(s.ingest_url)}</div>
        <div class="small muted">Header „Authorization“</div>
        <div class="code" id="tokenBox" style="margin:4px 0 10px">Bearer ••••••••••••</div>
        <div class="row"><button class="btn" id="showToken" type="button">Anzeigen</button><button class="btn" id="copyToken" type="button">Kopieren</button></div>
      </section>

      <section class="card">
        <h2>KI</h2>
        <p style="margin:0">${s.ai.configured ? 'Aktiv' : 'Nicht aktiv – API-Schlüssel in config.php eintragen'} · Modell für Entscheidung: <span class="secondary">${esc(s.ai.decision_model)}</span></p>
        <p class="small secondary" style="margin:6px 0 0">Kosten diesen Monat: ${fmt(s.ai.spent_usd, 3)} $ von ${fmt(s.ai.budget_usd, 2)} $ (danach nur Regeln)</p>
      </section>

      <section class="card">
        <h2>Server</h2>
        <p class="small secondary" style="margin:0;line-height:1.8">
          PHP ${esc(s.server.php)} ${versionOk(s.server.php) ? '✓' : '⚠️ (mind. 8.1 nötig)'}<br>
          Max. Laufzeit: ${esc(s.server.max_execution_time)} s ${s.server.max_execution_time === 0 || s.server.max_execution_time >= 60 ? '✓' : '⚠️ (60 s empfohlen)'}<br>
          Speicher: ${esc(s.server.memory_limit)} · Upload: ${esc(s.server.upload_max_filesize)} / ${esc(s.server.post_max_size)}<br>
          HTTPS: ${s.server.https ? 'aktiv ✓' : '⚠️ nicht aktiv'}
        </p>
      </section>

      <button class="btn block" id="logout" style="margin-top:4px">Abmelden</button>`;

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
    document.getElementById('profileForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await saveSettings(); toast('Gespeichert'); } catch (ex) { toast(ex.message, 5000); }
    });
  }

  function versionOk(v) {
    const [maj, min] = String(v).split('.').map(Number);
    return maj > 8 || (maj === 8 && min >= 1);
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
