/* ==========================================================================
   Meine Weltreise - Streaming App
   Vanilla JS, lädt Inhalte aus /api/content, geschützt durch Zugangscode
   ========================================================================== */

(function () {
    "use strict";

    const API = "/api";

    const state = {
        settings: {},
        episodes: [],
        seasons: [],
        countries: [],
        filtered: [],
        activeCountry: "all",
        activeSeason: "all",
        activeSearch: "",
        currentEpisodeIndex: null,
        autoplayCountdown: null,
        role: null,
    };

    const LS = {
        progress: "weltreise:progress",
        favorites: "weltreise:favorites",
        watched: "weltreise:watched",
    };

    const $ = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));

    const loadJSON = (k, f) => { try { const r = localStorage.getItem(k); return r ? JSON.parse(r) : f; } catch { return f; } };
    const saveJSON = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} };

    const getProgress = () => loadJSON(LS.progress, {});
    const setProgress = (id, s) => { const p = getProgress(); p[id] = s; saveJSON(LS.progress, p); };
    const getFavorites = () => loadJSON(LS.favorites, []);
    const isFavorite = (id) => getFavorites().includes(id);
    const toggleFavorite = (id) => {
        const favs = getFavorites();
        const i = favs.indexOf(id);
        if (i >= 0) favs.splice(i, 1); else favs.push(id);
        saveJSON(LS.favorites, favs);
        return favs.includes(id);
    };
    const getWatched = () => loadJSON(LS.watched, []);
    const markWatched = (id) => { const w = getWatched(); if (!w.includes(id)) { w.push(id); saveJSON(LS.watched, w); } };
    const isWatched = (id) => getWatched().includes(id);

    const flagFor = (country) => {
        const c = state.countries.find(x => x.name === country);
        return c?.flag_emoji || "🌍";
    };

    const formatDuration = (mins) => {
        if (!mins) return "—";
        if (mins >= 60) { const h = Math.floor(mins / 60); const m = mins % 60; return m ? `${h}h ${m}m` : `${h}h`; }
        return `${mins}m`;
    };

    // ---- Auth Guard ----
    async function guard() {
        try {
            const r = await fetch(`${API}/auth/me`, { credentials: "include" });
            const me = await r.json();
            state.role = me.role;
            if (!me.authenticated) { window.location.replace("/login.html"); return false; }
            return true;
        } catch { window.location.replace("/login.html"); return false; }
    }

    // ---- Data loading from API ----
    async function loadContent() {
        try {
            const r = await fetch(`${API}/content`, { credentials: "include" });
            if (r.status === 401) { window.location.replace("/login.html"); return; }
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            const data = await r.json();
            state.settings = data.settings || {};
            state.episodes = (data.episodes || []).map((e, i) => ({
                ...e,
                duration_min: e.duration || Math.round((e.duration_seconds || 0) / 60),
            }));
            state.seasons = data.seasons || [];
            state.countries = data.countries || [];
            state.filtered = [...state.episodes];
            applyDynamicSettings();
            renderFilters();
            renderSeasonFilters();
            renderEpisodes();
            renderContinueRow();
            renderFavoritesRow();
        } catch (err) {
            console.error("Konnte Inhalte nicht laden:", err);
            $("#episode-grid").innerHTML =
                '<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">⚠️</div><p>Inhalte konnten nicht geladen werden.</p></div>';
        }
    }

    function applyDynamicSettings() {
        const s = state.settings || {};
        // Farben
        if (s.color_bg) document.documentElement.style.setProperty("--bg", s.color_bg);
        if (s.color_accent) {
            document.documentElement.style.setProperty("--accent", s.color_accent);
            document.documentElement.style.setProperty("--accent-hover", s.color_accent);
        }
        // Texte
        if (s.series_title) { const el = $("#hero-title"); if (el) el.textContent = s.series_title; document.title = `${s.series_title} – Streaming`; }
        if (s.hero_subtitle) { const el = $("#hero-season"); if (el) el.textContent = s.hero_subtitle; }
        if (s.hero_description) { const el = $("#hero-description"); if (el) el.textContent = s.hero_description; }
        if (s.logo_text) $$(".logo-text").forEach(x => x.textContent = s.logo_text);
        if (s.footer_text) { const el = $(".footer-inner > div"); if (el) el.textContent = s.footer_text; }
        // Hero-Bild als Overlay
        if (s.hero_bg_url) {
            const bd = $("#hero-backdrop");
            if (bd) {
                bd.style.backgroundImage = `linear-gradient(135deg, rgba(20,5,5,0.5) 0%, rgba(20,20,20,0.7) 60%, rgba(6,17,22,0.8) 100%), url('${s.hero_bg_url}')`;
                bd.style.backgroundSize = "cover";
                bd.style.backgroundPosition = "center";
            }
        }
        $("#hero-episode-count").textContent = `${state.episodes.length} Folgen`;
    }

    // ---- Filters ----
    function renderFilters() {
        const container = $("#country-filters");
        const items = [["all", "Alle", ""], ...state.countries.map(c => [c.name, c.name, c.flag_emoji || ""])];
        container.innerHTML = items.map(([id, name, flag]) => {
            const active = id === state.activeCountry ? "active" : "";
            const label = id === "all" ? "Alle" : `${flag} ${name}`;
            return `<button class="chip ${active}" data-country="${id}" data-testid="filter-${id}">${label}</button>`;
        }).join("");
        $$(".chip", container).forEach(btn => btn.addEventListener("click", () => {
            state.activeCountry = btn.dataset.country;
            $$(".chip", container).forEach(b => b.classList.remove("active"));
            btn.classList.add("active");
            applyFilters();
        }));
    }

    function renderSeasonFilters() {
        // Nur anzeigen, wenn mehr als eine Staffel
        const bar = $("#season-filter-bar");
        if (!bar) return;
        if (state.seasons.length <= 1) { bar.hidden = true; return; }
        bar.hidden = false;
        const c = $("#season-filters");
        const items = [["all", "Alle Staffeln"], ...state.seasons.map(s => [s.id, s.name])];
        c.innerHTML = items.map(([id, name]) => {
            const active = id === state.activeSeason ? "active" : "";
            return `<button class="chip ${active}" data-season="${id}">${name}</button>`;
        }).join("");
        $$(".chip", c).forEach(btn => btn.addEventListener("click", () => {
            state.activeSeason = btn.dataset.season;
            $$(".chip", c).forEach(b => b.classList.remove("active"));
            btn.classList.add("active");
            applyFilters();
        }));
    }

    function applyFilters() {
        const search = state.activeSearch.trim().toLowerCase();
        state.filtered = state.episodes.filter(ep => {
            const c = state.activeCountry === "all" || ep.country === state.activeCountry;
            const se = state.activeSeason === "all" || ep.season_id === state.activeSeason;
            const s = !search ||
                (ep.title || "").toLowerCase().includes(search) ||
                (ep.location || "").toLowerCase().includes(search) ||
                (ep.description || "").toLowerCase().includes(search) ||
                (ep.highlights || []).some(h => h.toLowerCase().includes(search));
            return c && se && s;
        });
        renderEpisodes();
    }

    // ---- Card rendering ----
    function placeholderThumb(ep) {
        const colors = { "Australien": ["#c9450d", "#f4a261"], "Indonesien": ["#2a9d8f", "#264653"], "Philippinen": ["#0077b6", "#00b4d8"] };
        const [c1, c2] = colors[ep.country] || ["#333", "#666"];
        const num = String(ep.number || 0).padStart(2, "0");
        const loc = (ep.location || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
        const svg =
            `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360">` +
            `<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">` +
            `<stop offset="0%" stop-color="${c1}"/><stop offset="100%" stop-color="${c2}"/>` +
            `</linearGradient></defs>` +
            `<rect width="640" height="360" fill="url(#g)"/>` +
            `<text x="320" y="170" text-anchor="middle" fill="rgba(255,255,255,0.95)" font-family="Arial,sans-serif" font-size="72" font-weight="800">E${num}</text>` +
            `<text x="320" y="230" text-anchor="middle" fill="rgba(255,255,255,0.85)" font-family="Arial,sans-serif" font-size="26" font-weight="600">${loc}</text>` +
            `</svg>`;
        return "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg);
    }

    function episodeCardHTML(ep, index) {
        const durSec = ep.duration_seconds || ((ep.duration_min || ep.duration || 0) * 60);
        const prog = getProgress()[ep.id] || 0;
        const percent = durSec ? Math.min(100, (prog / durSec) * 100) : 0;
        const watched = isWatched(ep.id);
        const fav = isFavorite(ep.id);
        const thumb = ep.thumbnail_url || placeholderThumb(ep);
        const durMin = ep.duration_min || ep.duration || 0;
        return `
        <article class="episode-card" data-index="${index}" data-testid="episode-card-${ep.id}" tabindex="0" role="button" aria-label="${ep.title || ""}">
            <div class="thumb-wrap">
                <img src="${thumb}" alt="${ep.title || ""}" loading="lazy" data-fallback="1"/>
                <div class="thumb-shine"></div>
                <div class="ep-number-badge">E${String(ep.number || 0).padStart(2, "0")}</div>
                <button class="ep-fav-badge ${fav ? "is-favorite" : ""}" data-fav="${ep.id}" data-testid="fav-btn-${ep.id}" aria-label="Favorit">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                    </svg>
                </button>
                <div class="thumb-play"><div class="play-circle"><svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></div></div>
                <div class="progress-strip"><div class="progress-fill" style="width:${percent}%"></div></div>
            </div>
            <div class="card-body">
                <div class="card-topline">
                    <span class="country-flag">${flagFor(ep.country)}</span>
                    <span>${ep.country || "—"}</span>
                    <span>·</span>
                    <span>${formatDuration(durMin)}</span>
                </div>
                <h3 class="card-title">${ep.title || "(ohne Titel)"}</h3>
                <p class="card-desc">${ep.description || ""}</p>
                <div class="card-footer">
                    <span class="card-location">📍 ${ep.location || "—"}</span>
                    ${watched ? '<span class="watched-tag">✓ Angesehen</span>' : `<span>${ep.date || ""}</span>`}
                </div>
            </div>
        </article>`;
    }

    function renderEpisodes() {
        const grid = $("#episode-grid"); const empty = $("#empty-state");
        if (!state.filtered.length) { grid.innerHTML = ""; empty.hidden = false; }
        else {
            empty.hidden = true;
            grid.innerHTML = state.filtered.map(ep => {
                const origIdx = state.episodes.findIndex(e => e.id === ep.id);
                return episodeCardHTML(ep, origIdx);
            }).join("");
            $$(".episode-card", grid).forEach((card, i) => card.style.animationDelay = `${Math.min(i * 40, 500)}ms`);
            attachCardListeners(grid);
        }
        $("#episodes-count-label").textContent = `${state.filtered.length} von ${state.episodes.length} Folgen`;
    }

    function attachCardListeners(container) {
        $$(".episode-card", container).forEach(card => {
            const idx = parseInt(card.dataset.index, 10);
            const ep = state.episodes[idx];
            const img = card.querySelector("img[data-fallback]");
            if (img && ep) {
                img.addEventListener("error", () => { img.src = placeholderThumb(ep); }, { once: true });
                if (img.complete && img.naturalWidth === 0) img.src = placeholderThumb(ep);
            }
            card.addEventListener("click", (e) => {
                if (e.target.closest("[data-fav]")) return;
                openEpisode(idx);
            });
            card.addEventListener("keydown", (e) => {
                if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openEpisode(idx); }
            });
        });
        $$("[data-fav]", container).forEach(btn => {
            btn.addEventListener("click", (e) => {
                e.stopPropagation();
                const now = toggleFavorite(btn.dataset.fav);
                btn.classList.toggle("is-favorite", now);
                renderFavoritesRow();
            });
        });
    }

    function renderContinueRow() {
        const prog = getProgress(); const watched = getWatched();
        const items = state.episodes.filter(ep => {
            const p = prog[ep.id] || 0;
            const t = ep.duration_seconds || ((ep.duration_min || 0) * 60);
            return p > 5 && !watched.includes(ep.id) && p < t * 0.95;
        }).slice(0, 8);
        const sec = $("#continue");
        if (!items.length) { sec.hidden = true; return; }
        sec.hidden = false;
        const row = $("#continue-row");
        row.innerHTML = items.map(ep => episodeCardHTML(ep, state.episodes.findIndex(e => e.id === ep.id))).join("");
        attachCardListeners(row);
    }

    function renderFavoritesRow() {
        const favs = getFavorites();
        const items = state.episodes.filter(ep => favs.includes(ep.id));
        const sec = $("#favorites");
        if (!items.length) { sec.hidden = true; return; }
        sec.hidden = false;
        const row = $("#favorites-row");
        row.innerHTML = items.map(ep => episodeCardHTML(ep, state.episodes.findIndex(e => e.id === ep.id))).join("");
        attachCardListeners(row);
    }

    // ---- Player / Detail ----
    function openEpisode(index) {
        if (index < 0 || index >= state.episodes.length) return;
        state.currentEpisodeIndex = index;
        const ep = state.episodes[index];
        const durMin = ep.duration_min || ep.duration || 0;
        $("#detail-episode-tag").textContent = `Staffel · Episode ${ep.number || ""}`;
        $("#detail-title").textContent = ep.title || "";
        $("#detail-location").textContent = `📍 ${ep.location || "—"}`;
        $("#detail-date").textContent = ep.date || "";
        $("#detail-duration").textContent = formatDuration(durMin);
        $("#detail-description").textContent = ep.long_description || ep.description || "";
        $("#detail-highlights").innerHTML = (ep.highlights || []).map(h => `<li>${h}</li>`).join("") || "<li>Keine Highlights hinterlegt</li>";
        const travel = ep.travel || {};
        $("#detail-travel").innerHTML = Object.entries({ "Land": ep.country || "—", "Ort": ep.location || "—", "Datum": ep.date || "—", "Dauer": formatDuration(durMin), ...travel }).map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join("");

        const favBtn = $("#detail-favorite");
        favBtn.classList.toggle("is-favorite", isFavorite(ep.id));
        favBtn.onclick = () => { const n = toggleFavorite(ep.id); favBtn.classList.toggle("is-favorite", n); renderFavoritesRow(); renderEpisodes(); };

        const video = $("#video-player");
        video.src = ep.video_url || "";
        video.poster = ep.thumbnail_url || placeholderThumb(ep);
        // Fehler-Handler: zeige klare Meldung wenn Datei nicht abspielbar ist
        const playerHint = $(".player-hint");
        video.onerror = () => {
            if (playerHint) {
                playerHint.style.opacity = "1";
                playerHint.innerHTML = ep.video_url
                    ? `⚠️ Video konnte nicht abgespielt werden. Prüfe im Admin-Panel, ob die Datei ein unterstützter Codec ist (H.264/AAC MP4).`
                    : `⚠️ Für diese Episode ist noch kein Video hinterlegt. Öffne den Admin-Bereich, um eines zuzuweisen.`;
                playerHint.style.background = "rgba(229, 9, 20, 0.85)";
                playerHint.style.color = "#fff";
            }
        };
        const saved = getProgress()[ep.id] || 0;
        video.onloadedmetadata = () => {
            if (playerHint) { playerHint.style.background = ""; playerHint.style.color = ""; }
            if (saved && saved < video.duration - 5) video.currentTime = saved;
            video.play().catch(() => {});
        };
        video.ontimeupdate = () => { if (video.currentTime > 3) setProgress(ep.id, Math.floor(video.currentTime)); };
        video.onended = () => { markWatched(ep.id); renderEpisodes(); renderContinueRow(); startAutoplayCountdown(); };

        // Up-Next
        const nextEp = state.episodes[index + 1];
        const upNextBlock = $("#up-next-block");
        if (nextEp) {
            upNextBlock.hidden = false;
            $("#up-next-thumb").src = nextEp.thumbnail_url || placeholderThumb(nextEp);
            $("#up-next-title").textContent = nextEp.title || "";
            $("#up-next-meta").textContent = `E${nextEp.number || ""} · ${nextEp.location || ""} · ${formatDuration(nextEp.duration_min || 0)}`;
            $("#up-next-card").onclick = () => { cancelAutoplay(); openEpisode(index + 1); };
            $("#autoplay-countdown").textContent = "Nächste Folge";
        } else { upNextBlock.hidden = true; }

        $("#detail-fullscreen").onclick = () => toggleFullscreen(video);

        const overlay = $("#detail-overlay");
        overlay.classList.add("is-open");
        overlay.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
    }

    function closeDetail() {
        const overlay = $("#detail-overlay"); const video = $("#video-player");
        video.pause(); cancelAutoplay();
        overlay.classList.remove("is-open"); overlay.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        renderEpisodes(); renderContinueRow();
    }

    function startAutoplayCountdown() {
        cancelAutoplay();
        const next = state.currentEpisodeIndex + 1;
        if (next >= state.episodes.length) return;
        let s = 5; const label = $("#autoplay-countdown");
        label.textContent = `Auto-Play in ${s}s`;
        state.autoplayCountdown = setInterval(() => {
            s--;
            if (s <= 0) { clearInterval(state.autoplayCountdown); openEpisode(next); }
            else label.textContent = `Auto-Play in ${s}s`;
        }, 1000);
    }
    function cancelAutoplay() { if (state.autoplayCountdown) { clearInterval(state.autoplayCountdown); state.autoplayCountdown = null; } }
    function toggleFullscreen(el) {
        if (!document.fullscreenElement) (el.requestFullscreen || el.webkitRequestFullscreen)?.call(el);
        else document.exitFullscreen?.();
    }

    // ---- Events ----
    function initEvents() {
        const search = $("#search-input"); let t;
        search.addEventListener("input", (e) => {
            clearTimeout(t);
            t = setTimeout(() => {
                state.activeSearch = e.target.value;
                applyFilters();
                if (state.activeSearch) document.getElementById("episodes").scrollIntoView({ behavior: "smooth" });
            }, 180);
        });

        $("#btn-start-season").addEventListener("click", () => {
            const watched = getWatched();
            const idx = state.episodes.findIndex(ep => !watched.includes(ep.id));
            openEpisode(idx >= 0 ? idx : 0);
        });
        $("#btn-all-episodes").addEventListener("click", () => document.getElementById("episodes").scrollIntoView({ behavior: "smooth" }));

        $("#detail-close").addEventListener("click", closeDetail);
        $("#detail-overlay").addEventListener("click", (e) => { if (e.target.id === "detail-overlay") closeDetail(); });

        document.addEventListener("keydown", (e) => {
            if (!$("#detail-overlay").classList.contains("is-open")) return;
            if (["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) return;
            const v = $("#video-player");
            if (e.code === "Space") { e.preventDefault(); if (v.paused) v.play(); else v.pause(); }
            else if (e.key === "Escape") closeDetail();
            else if (e.key === "f" || e.key === "F") toggleFullscreen(v);
            else if (e.key === "ArrowRight") v.currentTime = Math.min(v.duration || 0, v.currentTime + 10);
            else if (e.key === "ArrowLeft") v.currentTime = Math.max(0, v.currentTime - 10);
            else if (e.key === "n" || e.key === "N") { const n = state.currentEpisodeIndex + 1; if (n < state.episodes.length) openEpisode(n); }
        });

        // Speed control (rechtsklick auf Video-Player öffnet native controls, aber wir bieten auch Menü)
        const nav = $("#navbar");
        window.addEventListener("scroll", () => nav.classList.toggle("scrolled", window.scrollY > 40), { passive: true });

        // Logout button
        const lg = $("#logout-btn");
        if (lg) lg.addEventListener("click", async (e) => {
            e.preventDefault();
            await fetch(`${API}/auth/logout`, { method: "POST", credentials: "include" });
            window.location.replace("/login.html");
        });
    }

    document.addEventListener("DOMContentLoaded", async () => {
        setTimeout(() => { const intro = $("#intro-screen"); if (intro) intro.remove(); }, 3300);
        const ok = await guard();
        if (!ok) return;
        loadContent();
        initEvents();
    });
})();
