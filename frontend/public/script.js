/* ==========================================================================
   Meine Weltreise - Streaming App
   Reines JavaScript, keine Frameworks
   ========================================================================== */

(function () {
    "use strict";

    /* --------- State --------- */
    const state = {
        episodes: [],
        filtered: [],
        activeCountry: "all",
        activeSearch: "",
        currentEpisodeIndex: null,
        autoplayTimer: null,
        autoplayCountdown: null,
    };

    /* --------- LocalStorage Keys --------- */
    const LS = {
        progress: "weltreise:progress",
        favorites: "weltreise:favorites",
        watched: "weltreise:watched",
    };

    /* --------- Utilities --------- */
    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

    const loadJSON = (key, fallback) => {
        try {
            const raw = localStorage.getItem(key);
            return raw ? JSON.parse(raw) : fallback;
        } catch {
            return fallback;
        }
    };
    const saveJSON = (key, val) => {
        try { localStorage.setItem(key, JSON.stringify(val)); } catch {}
    };

    const getProgress = () => loadJSON(LS.progress, {});
    const setProgress = (id, seconds) => {
        const prog = getProgress();
        prog[id] = seconds;
        saveJSON(LS.progress, prog);
    };

    const getFavorites = () => loadJSON(LS.favorites, []);
    const isFavorite = (id) => getFavorites().includes(id);
    const toggleFavorite = (id) => {
        const favs = getFavorites();
        const idx = favs.indexOf(id);
        if (idx >= 0) favs.splice(idx, 1);
        else favs.push(id);
        saveJSON(LS.favorites, favs);
        return favs.includes(id);
    };

    const getWatched = () => loadJSON(LS.watched, []);
    const markWatched = (id) => {
        const w = getWatched();
        if (!w.includes(id)) {
            w.push(id);
            saveJSON(LS.watched, w);
        }
    };
    const isWatched = (id) => getWatched().includes(id);

    const flagFor = (country) => {
        const map = {
            "Australien": "🇦🇺",
            "Indonesien": "🇮🇩",
            "Philippinen": "🇵🇭",
        };
        return map[country] || "🌍";
    };

    const formatDuration = (mins) => {
        if (mins >= 60) {
            const h = Math.floor(mins / 60);
            const m = mins % 60;
            return m ? `${h}h ${m}m` : `${h}h`;
        }
        return `${mins}m`;
    };

    /* --------- Data Loading --------- */
    async function loadEpisodes() {
        try {
            const res = await fetch("data/episodes.json?" + Date.now());
            if (!res.ok) throw new Error("Fetch fehlgeschlagen");
            const data = await res.json();
            state.episodes = data.episodes || [];
            state.filtered = [...state.episodes];
            $("#hero-episode-count").textContent = `${state.episodes.length} Folgen`;
            renderFilters();
            renderEpisodes();
            renderContinueRow();
            renderFavoritesRow();
        } catch (err) {
            console.error("Konnte Episoden nicht laden:", err);
            $("#episode-grid").innerHTML =
                '<div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">⚠️</div><p>Episoden konnten nicht geladen werden.</p></div>';
        }
    }

    /* --------- Rendering --------- */
    function renderFilters() {
        const countries = ["all", ...new Set(state.episodes.map(e => e.country))];
        const container = $("#country-filters");
        container.innerHTML = countries.map(c => {
            const label = c === "all" ? "Alle" : `${flagFor(c)} ${c}`;
            const active = c === state.activeCountry ? "active" : "";
            return `<button class="chip ${active}" data-country="${c}" data-testid="filter-${c}">${label}</button>`;
        }).join("");

        $$(".chip", container).forEach(btn => {
            btn.addEventListener("click", () => {
                state.activeCountry = btn.dataset.country;
                $$(".chip", container).forEach(b => b.classList.remove("active"));
                btn.classList.add("active");
                applyFilters();
            });
        });
    }

    function applyFilters() {
        const search = state.activeSearch.trim().toLowerCase();
        state.filtered = state.episodes.filter(ep => {
            const matchCountry = state.activeCountry === "all" || ep.country === state.activeCountry;
            const matchSearch = !search ||
                ep.title.toLowerCase().includes(search) ||
                ep.location.toLowerCase().includes(search) ||
                ep.description.toLowerCase().includes(search) ||
                (ep.highlights || []).some(h => h.toLowerCase().includes(search));
            return matchCountry && matchSearch;
        });
        renderEpisodes();
    }

    function episodeCardHTML(ep, index) {
        const prog = getProgress()[ep.id] || 0;
        const duration = ep.durationSeconds || (ep.duration * 60);
        const percent = duration ? Math.min(100, (prog / duration) * 100) : 0;
        const watched = isWatched(ep.id);
        const fav = isFavorite(ep.id);
        const thumbSrc = ep.thumbnail || placeholderThumb(ep);

        return `
        <article class="episode-card" data-index="${index}" data-testid="episode-card-${ep.id}" tabindex="0" role="button" aria-label="${ep.title}">
            <div class="thumb-wrap">
                <img src="${thumbSrc}" alt="${ep.title}" loading="lazy" data-fallback="1"/>
                <div class="thumb-shine"></div>
                <div class="ep-number-badge">E${String(ep.number).padStart(2, "0")}</div>
                <button class="ep-fav-badge ${fav ? "is-favorite" : ""}" data-fav="${ep.id}" data-testid="fav-btn-${ep.id}" aria-label="Als Favorit markieren">
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
                    <span>${ep.country}</span>
                    <span>·</span>
                    <span>${formatDuration(ep.duration)}</span>
                </div>
                <h3 class="card-title">${ep.title}</h3>
                <p class="card-desc">${ep.description}</p>
                <div class="card-footer">
                    <span class="card-location">📍 ${ep.location}</span>
                    ${watched ? '<span class="watched-tag">✓ Angesehen</span>' : `<span>${ep.date}</span>`}
                </div>
            </div>
        </article>`;
    }

    function placeholderThumb(ep) {
        // Farbverlauf mit Episodennummer als SVG-Datauri (falls Thumbnail fehlt)
        const colors = {
            "Australien": ["#c9450d", "#f4a261"],
            "Indonesien": ["#2a9d8f", "#264653"],
            "Philippinen": ["#0077b6", "#00b4d8"],
        };
        const [c1, c2] = colors[ep.country] || ["#333", "#666"];
        const num = String(ep.number).padStart(2, "0");
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

    function renderEpisodes() {
        const grid = $("#episode-grid");
        const empty = $("#empty-state");

        if (!state.filtered.length) {
            grid.innerHTML = "";
            empty.hidden = false;
        } else {
            empty.hidden = true;
            grid.innerHTML = state.filtered.map((ep, i) => {
                const originalIndex = state.episodes.findIndex(e => e.id === ep.id);
                return episodeCardHTML(ep, originalIndex);
            }).join("");
            // Stagger animation
            $$(".episode-card", grid).forEach((card, i) => {
                card.style.animationDelay = `${Math.min(i * 40, 500)}ms`;
            });
            attachCardListeners(grid);
        }
        $("#episodes-count-label").textContent = `${state.filtered.length} von ${state.episodes.length} Folgen`;
    }

    function attachCardListeners(container) {
        $$(".episode-card", container).forEach(card => {
            const idx = parseInt(card.dataset.index, 10);
            const ep = state.episodes[idx];
            // Fallback für fehlende Thumbnails
            const img = card.querySelector("img[data-fallback]");
            if (img && ep) {
                img.addEventListener("error", () => {
                    img.src = placeholderThumb(ep);
                }, { once: true });
                // Falls das Bild bereits geladen hat und broken ist:
                if (img.complete && img.naturalWidth === 0) {
                    img.src = placeholderThumb(ep);
                }
            }
            card.addEventListener("click", (e) => {
                if (e.target.closest("[data-fav]")) return;
                openEpisode(idx);
            });
            card.addEventListener("keydown", (e) => {
                if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    openEpisode(idx);
                }
            });
        });
        $$("[data-fav]", container).forEach(btn => {
            btn.addEventListener("click", (e) => {
                e.stopPropagation();
                const id = btn.dataset.fav;
                const nowFav = toggleFavorite(id);
                btn.classList.toggle("is-favorite", nowFav);
                renderFavoritesRow();
            });
        });
    }

    function renderContinueRow() {
        const prog = getProgress();
        const watched = getWatched();
        const items = state.episodes.filter(ep => {
            const p = prog[ep.id] || 0;
            const total = ep.durationSeconds || (ep.duration * 60);
            return p > 5 && !watched.includes(ep.id) && p < total * 0.95;
        }).slice(0, 6);

        const sec = $("#continue");
        if (!items.length) { sec.hidden = true; return; }
        sec.hidden = false;
        const row = $("#continue-row");
        row.innerHTML = items.map(ep => {
            const idx = state.episodes.findIndex(e => e.id === ep.id);
            return episodeCardHTML(ep, idx);
        }).join("");
        attachCardListeners(row);
    }

    function renderFavoritesRow() {
        const favs = getFavorites();
        const items = state.episodes.filter(ep => favs.includes(ep.id));
        const sec = $("#favorites");
        if (!items.length) { sec.hidden = true; return; }
        sec.hidden = false;
        const row = $("#favorites-row");
        row.innerHTML = items.map(ep => {
            const idx = state.episodes.findIndex(e => e.id === ep.id);
            return episodeCardHTML(ep, idx);
        }).join("");
        attachCardListeners(row);
    }

    /* --------- Detail / Player --------- */
    function openEpisode(index) {
        if (index < 0 || index >= state.episodes.length) return;
        state.currentEpisodeIndex = index;
        const ep = state.episodes[index];

        $("#detail-episode-tag").textContent = `Staffel 1 · Episode ${ep.number}`;
        $("#detail-title").textContent = ep.title;
        $("#detail-location").textContent = `📍 ${ep.location}`;
        $("#detail-date").textContent = ep.date;
        $("#detail-duration").textContent = formatDuration(ep.duration);
        $("#detail-description").textContent = ep.longDescription || ep.description;

        // Highlights
        $("#detail-highlights").innerHTML = (ep.highlights || [])
            .map(h => `<li>${h}</li>`).join("") || "<li>Keine Highlights hinterlegt</li>";

        // Reisedetails
        const travel = ep.travel || {};
        $("#detail-travel").innerHTML = Object.entries({
            "Land": ep.country,
            "Ort": ep.location,
            "Datum": ep.date,
            "Dauer": formatDuration(ep.duration),
            ...travel,
        }).map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join("");

        // Favorit-Status
        const favBtn = $("#detail-favorite");
        favBtn.classList.toggle("is-favorite", isFavorite(ep.id));
        favBtn.onclick = () => {
            const now = toggleFavorite(ep.id);
            favBtn.classList.toggle("is-favorite", now);
            renderFavoritesRow();
            renderEpisodes();
        };

        // Video-Player
        const video = $("#video-player");
        video.src = ep.video || "";
        video.poster = ep.thumbnail || placeholderThumb(ep);

        // Fortschritt wiederherstellen
        const savedProgress = getProgress()[ep.id] || 0;
        video.onloadedmetadata = () => {
            if (savedProgress && savedProgress < video.duration - 5) {
                video.currentTime = savedProgress;
            }
            video.play().catch(() => { /* Autoplay evtl. blockiert */ });
        };

        // Fortschritt speichern
        video.ontimeupdate = () => {
            if (video.currentTime > 3) setProgress(ep.id, Math.floor(video.currentTime));
        };

        // Ende erreicht -> Watched + Auto-Next
        video.onended = () => {
            markWatched(ep.id);
            renderEpisodes();
            renderContinueRow();
            startAutoplayCountdown();
        };

        // Up-Next
        const nextEp = state.episodes[index + 1];
        const upNextBlock = $("#up-next-block");
        if (nextEp) {
            upNextBlock.hidden = false;
            $("#up-next-thumb").src = nextEp.thumbnail || placeholderThumb(nextEp);
            $("#up-next-title").textContent = nextEp.title;
            $("#up-next-meta").textContent = `E${nextEp.number} · ${nextEp.location} · ${formatDuration(nextEp.duration)}`;
            $("#up-next-card").onclick = () => {
                cancelAutoplay();
                openEpisode(index + 1);
            };
            $("#autoplay-countdown").textContent = "Nächste Folge";
        } else {
            upNextBlock.hidden = true;
        }

        // Vollbild
        $("#detail-fullscreen").onclick = () => toggleFullscreen(video);

        // Modal öffnen
        const overlay = $("#detail-overlay");
        overlay.classList.add("is-open");
        overlay.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
    }

    function closeDetail() {
        const overlay = $("#detail-overlay");
        const video = $("#video-player");
        video.pause();
        cancelAutoplay();
        overlay.classList.remove("is-open");
        overlay.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        renderEpisodes();
        renderContinueRow();
    }

    function startAutoplayCountdown() {
        cancelAutoplay();
        const next = state.currentEpisodeIndex + 1;
        if (next >= state.episodes.length) return;
        let seconds = 5;
        const label = $("#autoplay-countdown");
        label.textContent = `Auto-Play in ${seconds}s`;
        state.autoplayCountdown = setInterval(() => {
            seconds--;
            if (seconds <= 0) {
                clearInterval(state.autoplayCountdown);
                openEpisode(next);
            } else {
                label.textContent = `Auto-Play in ${seconds}s`;
            }
        }, 1000);
    }

    function cancelAutoplay() {
        if (state.autoplayCountdown) {
            clearInterval(state.autoplayCountdown);
            state.autoplayCountdown = null;
        }
    }

    function toggleFullscreen(el) {
        if (!document.fullscreenElement) {
            (el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen)?.call(el);
        } else {
            document.exitFullscreen?.();
        }
    }

    /* --------- Event Listeners --------- */
    function initEventListeners() {
        // Suche
        const searchInput = $("#search-input");
        let searchTimer;
        searchInput.addEventListener("input", (e) => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                state.activeSearch = e.target.value;
                applyFilters();
                if (state.activeSearch) {
                    document.getElementById("episodes").scrollIntoView({ behavior: "smooth", block: "start" });
                }
            }, 180);
        });

        // Hero-Buttons
        $("#btn-start-season").addEventListener("click", () => {
            // Erste ungesehene Episode starten oder Episode 0
            const watched = getWatched();
            const startIdx = state.episodes.findIndex(ep => !watched.includes(ep.id));
            openEpisode(startIdx >= 0 ? startIdx : 0);
        });
        $("#btn-all-episodes").addEventListener("click", () => {
            document.getElementById("episodes").scrollIntoView({ behavior: "smooth", block: "start" });
        });

        // Detail schließen
        $("#detail-close").addEventListener("click", closeDetail);
        $("#detail-overlay").addEventListener("click", (e) => {
            if (e.target.id === "detail-overlay") closeDetail();
        });

        // Tastatursteuerung
        document.addEventListener("keydown", (e) => {
            const overlayOpen = $("#detail-overlay").classList.contains("is-open");
            if (!overlayOpen) return;

            const video = $("#video-player");
            const inInput = ["INPUT", "TEXTAREA"].includes(document.activeElement.tagName);
            if (inInput) return;

            if (e.code === "Space") {
                e.preventDefault();
                if (video.paused) video.play(); else video.pause();
            } else if (e.key === "Escape") {
                closeDetail();
            } else if (e.key === "f" || e.key === "F") {
                toggleFullscreen(video);
            } else if (e.key === "ArrowRight") {
                video.currentTime = Math.min(video.duration || 0, video.currentTime + 10);
            } else if (e.key === "ArrowLeft") {
                video.currentTime = Math.max(0, video.currentTime - 10);
            } else if (e.key === "n" || e.key === "N") {
                const next = state.currentEpisodeIndex + 1;
                if (next < state.episodes.length) openEpisode(next);
            }
        });

        // Navbar scroll effekt
        const navbar = $("#navbar");
        window.addEventListener("scroll", () => {
            navbar.classList.toggle("scrolled", window.scrollY > 40);
        }, { passive: true });
    }

    /* --------- Init --------- */
    document.addEventListener("DOMContentLoaded", () => {
        // Intro nach Animation entfernen (Fallback nach 3.5s)
        setTimeout(() => {
            const intro = $("#intro-screen");
            if (intro) intro.remove();
        }, 3300);

        loadEpisodes();
        initEventListeners();
    });
})();
