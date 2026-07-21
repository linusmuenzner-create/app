/* global AuthGuard */
/* ============================================================
   Admin Dashboard – Meine Weltreise
   ============================================================ */
/* global AuthGuard */
(function () {
    const API = "/api";
    const state = {
        role: null,
        settings: {},
        seasons: [],
        countries: [],
        episodes: [],
        media: [],
        activeTab: "dashboard",
    };

    // ---- API helpers ----
    async function api(method, path, body) {
        const opts = { method, credentials: "include", headers: {} };
        if (body && !(body instanceof FormData)) {
            opts.headers["Content-Type"] = "application/json";
            opts.body = JSON.stringify(body);
        } else if (body instanceof FormData) {
            opts.body = body;
        }
        const r = await fetch(`${API}${path}`, opts);
        if (r.status === 401 || r.status === 403) {
            showLogin();
            throw new Error("Nicht authentifiziert");
        }
        if (!r.ok) {
            const err = await r.json().catch(() => ({}));
            throw new Error(err.detail || `Fehler ${r.status}`);
        }
        return r.json();
    }
    const get = (p) => api("GET", p);
    const post = (p, b) => api("POST", p, b);
    const put = (p, b) => api("PUT", p, b);
    const del = (p) => api("DELETE", p);

    // ---- UI helpers ----
    const el = (tag, attrs = {}, ...children) => {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (k === "class") e.className = v;
            else if (k === "html") e.innerHTML = v;
            else if (k.startsWith("on")) e.addEventListener(k.slice(2).toLowerCase(), v);
            else if (v === true) e.setAttribute(k, "");
            else if (v !== false && v != null) e.setAttribute(k, v);
        }
        for (const c of children) {
            if (c == null) continue;
            e.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
        }
        return e;
    };

    function toast(msg, kind = "success") {
        const t = document.getElementById("admin-toast");
        t.textContent = msg;
        t.className = `admin-toast is-${kind}`;
        t.hidden = false;
        setTimeout(() => (t.hidden = true), 3500);
    }

    // ---- Auth flow ----
    async function checkAuth() {
        const me = await fetch(`${API}/auth/me`, { credentials: "include" }).then(r => r.json());
        state.role = me.role;
        if (me.role === "admin") showDashboard();
        else showLogin();
    }
    function showLogin() {
        document.getElementById("admin-login").hidden = false;
        document.getElementById("admin-dashboard").hidden = true;
    }
    function showDashboard() {
        document.getElementById("admin-login").hidden = true;
        document.getElementById("admin-dashboard").hidden = false;
        loadAll();
    }

    async function loadAll() {
        try {
            const [settings, seasons, countries, episodes, media] = await Promise.all([
                get("/admin/settings"), get("/admin/seasons"), get("/admin/countries"),
                get("/admin/episodes"), get("/admin/media"),
            ]);
            state.settings = settings || {};
            state.seasons = seasons || [];
            state.countries = countries || [];
            state.episodes = episodes || [];
            state.media = media || [];
            renderTab(state.activeTab);
        } catch (e) { console.error(e); toast(e.message, "error"); }
    }

    // ---- Tabs ----
    document.querySelectorAll(".admin-nav-item").forEach(btn => {
        btn.addEventListener("click", () => {
            document.querySelectorAll(".admin-nav-item").forEach(b => b.classList.remove("active"));
            btn.classList.add("active");
            state.activeTab = btn.dataset.tab;
            renderTab(state.activeTab);
        });
    });

    function renderTab(tab) {
        const container = document.getElementById("admin-content");
        container.innerHTML = "";
        const titles = {
            dashboard: "Übersicht", episodes: "Episoden verwalten", videos: "Videos hochladen",
            media: "Medien-Bibliothek", seasons: "Staffeln", countries: "Länder",
            settings: "Einstellungen & Design", security: "Sicherheit",
        };
        document.getElementById("admin-page-title").textContent = titles[tab] || tab;
        const view = {
            dashboard: viewDashboard, episodes: viewEpisodes, videos: viewVideos,
            media: viewMedia, seasons: viewSeasons, countries: viewCountries,
            settings: viewSettings, security: viewSecurity,
        }[tab];
        view?.(container);
    }

    // ---- Dashboard ----
    function viewDashboard(container) {
        const stats = [
            { label: "Episoden", value: state.episodes.length, color: "#e50914" },
            { label: "Staffeln", value: state.seasons.length, color: "#0077b6" },
            { label: "Länder", value: state.countries.length, color: "#2a9d8f" },
            { label: "Mediendateien", value: state.media.length, color: "#f4a261" },
        ];
        const uploaded = state.episodes.filter(e => e.video_url).length;
        container.appendChild(el("div", { class: "stat-grid" },
            ...stats.map(s => el("div", { class: "stat-card", style: `--stat-color:${s.color}` },
                el("div", { class: "stat-value" }, String(s.value)),
                el("div", { class: "stat-label" }, s.label),
            )),
        ));
        container.appendChild(el("div", { class: "card" },
            el("h3", {}, "Fortschritt"),
            el("div", { class: "progress-line" },
                el("div", { class: "progress-fill", style: `width: ${state.episodes.length ? (uploaded / state.episodes.length) * 100 : 0}%` })),
            el("p", { class: "muted" }, `${uploaded} von ${state.episodes.length} Episoden haben ein Video zugeordnet.`),
        ));
        container.appendChild(el("div", { class: "card" },
            el("h3", {}, "Schnellzugriff"),
            el("div", { class: "quick-actions" },
                el("button", { class: "btn btn-primary", "data-testid": "quick-videos",
                    onclick: () => { state.activeTab = "videos"; document.querySelector('[data-tab="videos"]').click(); } },
                    "Videos hochladen"),
                el("button", { class: "btn btn-secondary",
                    onclick: () => { state.activeTab = "episodes"; document.querySelector('[data-tab="episodes"]').click(); } },
                    "Episoden bearbeiten"),
                el("button", { class: "btn btn-secondary",
                    onclick: () => { state.activeTab = "settings"; document.querySelector('[data-tab="settings"]').click(); } },
                    "Design anpassen"),
            ),
        ));
    }

    // ---- Episodes ----
    function viewEpisodes(container) {
        const bySeason = {};
        state.episodes.forEach(e => (bySeason[e.season_id] ??= []).push(e));

        container.appendChild(el("div", { class: "toolbar" },
            el("button", { class: "btn btn-primary", "data-testid": "add-episode", onclick: () => editEpisode(null) },
                "+ Neue Episode"),
            el("span", { class: "muted" }, `${state.episodes.length} Episoden`),
        ));

        for (const season of state.seasons) {
            const eps = (bySeason[season.id] || []).sort((a, b) => (a.order || 0) - (b.order || 0));
            container.appendChild(el("h3", { class: "section-h" }, `${season.name} (${eps.length})`));
            const table = el("table", { class: "admin-table", "data-testid": `season-table-${season.id}` });
            table.appendChild(el("thead", {}, el("tr", {},
                el("th", {}, "#"), el("th", {}, "Titel"), el("th", {}, "Land"),
                el("th", {}, "Datum"), el("th", {}, "Video"), el("th", {}, "Sichtbar"), el("th", {}, "Aktionen"))));
            const tbody = el("tbody");
            eps.forEach((ep, idx) => {
                tbody.appendChild(el("tr", { "data-testid": `episode-row-${ep.id}` },
                    el("td", {},
                        el("div", { class: "order-cell" },
                            el("button", { class: "reorder-btn", title: "Nach oben", onclick: () => reorderEpisode(ep.id, -1, season.id) }, "▲"),
                            el("span", {}, String(ep.number || idx + 1)),
                            el("button", { class: "reorder-btn", title: "Nach unten", onclick: () => reorderEpisode(ep.id, +1, season.id) }, "▼"),
                        )),
                    el("td", {}, el("strong", {}, ep.title || "(ohne Titel)"),
                        el("div", { class: "muted small" }, ep.location || "")),
                    el("td", {}, ep.country || "—"),
                    el("td", {}, ep.date || "—"),
                    el("td", {}, ep.video_url ? el("span", { class: "pill pill-ok" }, "✓") : el("span", { class: "pill" }, "kein")),
                    el("td", {},
                        el("label", { class: "switch" },
                            el("input", { type: "checkbox", checked: !ep.hidden ? true : false,
                                onchange: (e) => toggleHidden(ep.id, !e.target.checked) }),
                            el("span", { class: "slider" }),
                        )),
                    el("td", {},
                        el("button", { class: "icon-btn-sm", title: "Bearbeiten", "data-testid": `edit-episode-${ep.id}`, onclick: () => editEpisode(ep) }, "✎"),
                        el("button", { class: "icon-btn-sm danger", title: "Löschen", "data-testid": `delete-episode-${ep.id}`, onclick: () => deleteEpisode(ep.id) }, "🗑"),
                    ),
                ));
            });
            table.appendChild(tbody);
            container.appendChild(table);
        }
    }

    async function reorderEpisode(id, dir, seasonId) {
        const eps = state.episodes.filter(e => e.season_id === seasonId).sort((a, b) => (a.order || 0) - (b.order || 0));
        const idx = eps.findIndex(e => e.id === id);
        const swap = idx + dir;
        if (swap < 0 || swap >= eps.length) return;
        [eps[idx], eps[swap]] = [eps[swap], eps[idx]];
        try { await post("/admin/episodes/reorder", { ids: eps.map(e => e.id) }); await loadAll(); toast("Reihenfolge gespeichert"); }
        catch (e) { toast(e.message, "error"); }
    }

    async function toggleHidden(id, hidden) {
        try { await put(`/admin/episodes/${id}`, { hidden, season_id: state.episodes.find(e => e.id === id).season_id, title: state.episodes.find(e => e.id === id).title }); await loadAll(); }
        catch (e) { toast(e.message, "error"); }
    }

    async function deleteEpisode(id) {
        if (!confirm("Diese Episode wirklich löschen?")) return;
        try { await del(`/admin/episodes/${id}`); toast("Gelöscht."); await loadAll(); }
        catch (e) { toast(e.message, "error"); }
    }

    function editEpisode(ep) {
        const isNew = !ep;
        ep = ep || { season_id: state.seasons[0]?.id, title: "", country: "", location: "", date: "",
                     description: "", long_description: "", duration: 20, thumbnail_url: "", video_url: "",
                     highlights: [], travel: {}, hidden: false };
        openModal(`${isNew ? "Neue" : "Bearbeite"} Episode`, (body) => {
            const f = el("form", { class: "edit-form", onsubmit: async (e) => {
                e.preventDefault();
                const data = collectForm(f);
                data.highlights = (data.highlights || "").split("\n").map(s => s.trim()).filter(Boolean);
                data.duration = parseInt(data.duration || 0, 10);
                data.number = parseInt(data.number || 0, 10) || undefined;
                data.travel = {};
                try {
                    if (isNew) await post("/admin/episodes", data);
                    else await put(`/admin/episodes/${ep.id}`, data);
                    closeModal();
                    await loadAll();
                    toast("Gespeichert.");
                } catch (ex) { toast(ex.message, "error"); }
            }});
            const seasonOpts = state.seasons.map(s => el("option", { value: s.id, ...(ep.season_id === s.id ? { selected: true } : {}) }, s.name));
            const countryOpts = [el("option", { value: "" }, "—"), ...state.countries.map(c =>
                el("option", { value: c.name, ...(ep.country === c.name ? { selected: true } : {}) }, `${c.flag_emoji || ""} ${c.name}`))];

            appendField(f, "Staffel", el("select", { name: "season_id", required: true }, ...seasonOpts));
            appendField(f, "Nummer", el("input", { name: "number", type: "number", value: ep.number || "" }));
            appendField(f, "Titel", el("input", { name: "title", required: true, value: ep.title || "" }));
            appendField(f, "Land", el("select", { name: "country" }, ...countryOpts));
            appendField(f, "Ort", el("input", { name: "location", value: ep.location || "" }));
            appendField(f, "Datum", el("input", { name: "date", value: ep.date || "", placeholder: "z.B. Woche 1 · 2025" }));
            appendField(f, "Laufzeit (Minuten)", el("input", { name: "duration", type: "number", value: ep.duration || 0 }));
            appendField(f, "Kurzbeschreibung", el("textarea", { name: "description", rows: 2 }, ep.description || ""));
            appendField(f, "Lange Beschreibung", el("textarea", { name: "long_description", rows: 4 }, ep.long_description || ""));
            appendField(f, "Highlights (eine Zeile pro Highlight)", el("textarea", { name: "highlights", rows: 4 }, (ep.highlights || []).join("\n")));

            // Thumbnail & Video mit Media-Picker
            appendField(f, "Vorschaubild-URL", pickerField("thumbnail_url", ep.thumbnail_url, "image"));
            appendField(f, "Video-URL", pickerField("video_url", ep.video_url, "video"));

            f.appendChild(el("div", { class: "form-actions" },
                el("button", { type: "button", class: "btn btn-secondary", onclick: closeModal }, "Abbrechen"),
                el("button", { type: "submit", class: "btn btn-primary", "data-testid": "save-episode" }, "Speichern"),
            ));
            body.appendChild(f);
        });
    }

    function pickerField(name, value, kind) {
        const input = el("input", { name, value: value || "", placeholder: "URL oder aus Medien wählen" });
        const btn = el("button", { type: "button", class: "btn btn-secondary btn-sm", onclick: () => pickMedia(kind, (url) => (input.value = url)) }, "Aus Medien wählen");
        return el("div", { class: "picker-row" }, input, btn);
    }

    function pickMedia(kind, cb) {
        const items = state.media.filter(m => m.kind === kind);
        openModal(`${kind === "video" ? "Video" : "Bild"} wählen`, (body) => {
            if (!items.length) { body.appendChild(el("p", { class: "muted" }, `Noch keine ${kind === "video" ? "Videos" : "Bilder"} hochgeladen. Wechsle zu "Videos verwalten" oder "Medien".`)); return; }
            const grid = el("div", { class: "media-grid" });
            items.forEach(m => {
                grid.appendChild(el("div", { class: "media-tile", onclick: () => { cb(m.url); closeModal(); } },
                    kind === "image"
                        ? el("img", { src: m.url, alt: m.name })
                        : el("video", { src: m.url, muted: true, preload: "metadata" }),
                    el("div", { class: "media-name" }, m.name || m.url),
                ));
            });
            body.appendChild(grid);
        });
    }

    // ---- Videos verwalten (Drag & Drop) ----
    function viewVideos(container) {
        const drop = el("div", { class: "drop-zone", "data-testid": "video-drop-zone" },
            el("div", { class: "drop-icon", html: '<svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' }),
            el("h3", {}, "Videos hierher ziehen"),
            el("p", { class: "muted" }, "oder klicke, um mehrere Dateien auszuwählen. MP4 / WebM / MOV, max. 1 GB pro Datei."),
            el("input", { type: "file", id: "video-file-input", accept: "video/*", multiple: true, hidden: true }),
            el("button", { class: "btn btn-primary", onclick: () => document.getElementById("video-file-input").click() }, "Dateien auswählen"),
        );
        container.appendChild(drop);
        const listBox = el("div", { class: "upload-list", id: "upload-list" });
        container.appendChild(listBox);

        // Optional zweite Zone: Thumbnails
        container.appendChild(el("div", { class: "drop-zone drop-secondary" },
            el("h3", {}, "Vorschaubilder hochladen"),
            el("p", { class: "muted" }, "JPG / PNG / WebP. Weise sie danach in einer Episode zu."),
            el("input", { type: "file", id: "image-file-input", accept: "image/*", multiple: true, hidden: true }),
            el("button", { class: "btn btn-secondary", onclick: () => document.getElementById("image-file-input").click() }, "Bilder auswählen"),
        ));

        const fileInput = document.getElementById("video-file-input");
        const imgInput = document.getElementById("image-file-input");
        fileInput.addEventListener("change", (e) => handleFiles(Array.from(e.target.files), "video"));
        imgInput.addEventListener("change", (e) => handleFiles(Array.from(e.target.files), "image"));

        drop.addEventListener("dragover", (e) => { e.preventDefault(); drop.classList.add("is-drag"); });
        drop.addEventListener("dragleave", () => drop.classList.remove("is-drag"));
        drop.addEventListener("drop", (e) => {
            e.preventDefault(); drop.classList.remove("is-drag");
            const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith("video/"));
            handleFiles(files, "video");
        });
    }

    async function handleFiles(files, kind) {
        const box = document.getElementById("upload-list") || document.body;
        for (const f of files) {
            const row = el("div", { class: "upload-row" },
                el("div", { class: "u-name" }, f.name),
                el("div", { class: "u-size muted" }, `${(f.size / 1024 / 1024).toFixed(1)} MB`),
                el("div", { class: "u-bar" }, el("div", { class: "u-bar-fill" })),
                el("div", { class: "u-status" }, "wartet..."),
            );
            box.prepend(row);
            const fill = row.querySelector(".u-bar-fill");
            const status = row.querySelector(".u-status");
            try {
                const url = f.size <= 4 * 1024 * 1024
                    ? await uploadFile(f, kind, (p) => { fill.style.width = `${p}%`; status.textContent = `${Math.round(p)}%`; })
                    : await uploadFileChunked(f, kind, (p) => { fill.style.width = `${p}%`; status.textContent = `${Math.round(p)}%`; });
                fill.style.width = "100%";
                status.innerHTML = `<span class="ok">✓ Erledigt</span>`;
                row.appendChild(el("a", { href: url, target: "_blank", class: "u-link" }, "Ansehen"));
                // Nur State refreshen, NICHT das ganze Tab neu rendern —
                // damit die Erfolgs-Row sichtbar bleibt.
                try {
                    const [media, episodes] = await Promise.all([get("/admin/media"), get("/admin/episodes")]);
                    state.media = media || [];
                    state.episodes = episodes || [];
                } catch {}
                toast(`${f.name} hochgeladen`);
            } catch (ex) {
                status.innerHTML = `<span class="err">✗ ${ex.message}</span>`;
                toast(`Upload fehlgeschlagen: ${ex.message}`, "error");
            }
        }
    }

    function uploadFile(file, kind, onProgress) {
        return new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append("file", file);
            fd.append("kind", kind);
            const xhr = new XMLHttpRequest();
            xhr.open("POST", `${API}/admin/upload`);
            xhr.withCredentials = true;
            xhr.upload.onprogress = (e) => e.lengthComputable && onProgress((e.loaded / e.total) * 100);
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try { resolve(JSON.parse(xhr.responseText).url); } catch { reject(new Error("Parse-Fehler")); }
                } else {
                    let m = `HTTP ${xhr.status}`;
                    try { m = JSON.parse(xhr.responseText).detail || m; } catch {}
                    reject(new Error(m));
                }
            };
            xhr.onerror = () => reject(new Error("Netzwerkfehler"));
            xhr.send(fd);
        });
    }

    // Chunked-Upload: 4 MB Chunks (unter Kubernetes-Ingress-Body-Limit)
    async function uploadFileChunked(file, kind, onProgress) {
        const CHUNK = 4 * 1024 * 1024;
        const total_chunks = Math.ceil(file.size / CHUNK);
        const upload_id = `up_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
        let uploaded = 0;
        for (let i = 0; i < total_chunks; i++) {
            const start = i * CHUNK;
            const end = Math.min(start + CHUNK, file.size);
            const blob = file.slice(start, end);
            const result = await postChunk({
                blob, upload_id, chunk_index: i, total_chunks,
                filename: file.name, kind,
                onChunkProgress: (loaded) => {
                    const overall = ((uploaded + loaded) / file.size) * 100;
                    onProgress(Math.min(99, overall));
                },
            });
            uploaded += (end - start);
            if (result.complete) {
                onProgress(100);
                return result.url;
            }
        }
        throw new Error("Upload nicht komplettiert");
    }

    function postChunk({ blob, upload_id, chunk_index, total_chunks, filename, kind, onChunkProgress }) {
        return new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append("file", blob, "chunk");
            fd.append("upload_id", upload_id);
            fd.append("chunk_index", String(chunk_index));
            fd.append("total_chunks", String(total_chunks));
            fd.append("filename", filename);
            fd.append("kind", kind);
            const xhr = new XMLHttpRequest();
            xhr.open("POST", `${API}/admin/upload-chunk`);
            xhr.withCredentials = true;
            xhr.upload.onprogress = (e) => { if (e.lengthComputable) onChunkProgress(e.loaded); };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try { resolve(JSON.parse(xhr.responseText)); } catch { reject(new Error("Parse-Fehler")); }
                } else {
                    let m = `HTTP ${xhr.status}`;
                    try { m = JSON.parse(xhr.responseText).detail || m; } catch {}
                    reject(new Error(m));
                }
            };
            xhr.onerror = () => reject(new Error("Netzwerkfehler beim Chunk-Upload"));
            xhr.send(fd);
        });
    }

    // ---- Media library ----
    function viewMedia(container) {
        container.appendChild(el("p", { class: "muted" }, `${state.media.length} Dateien insgesamt.`));
        const grid = el("div", { class: "media-grid" });
        state.media.forEach(m => {
            const tile = el("div", { class: "media-tile", "data-testid": `media-${m.id}` },
                m.kind === "image"
                    ? el("img", { src: m.url, alt: m.name })
                    : el("video", { src: m.url, muted: true, preload: "metadata" }),
                el("div", { class: "media-name" }, m.name || ""),
                el("div", { class: "media-actions" },
                    el("a", { class: "btn btn-sm btn-secondary", href: m.url, target: "_blank" }, "Öffnen"),
                    el("button", { class: "btn btn-sm danger", onclick: async () => {
                        if (!confirm("Datei wirklich löschen?")) return;
                        try { await del(`/admin/media/${m.id}`); toast("Gelöscht"); await loadAll(); } catch (ex) { toast(ex.message, "error"); }
                    }}, "Löschen"),
                ),
            );
            grid.appendChild(tile);
        });
        container.appendChild(grid);
    }

    // ---- Seasons ----
    function viewSeasons(container) {
        container.appendChild(el("div", { class: "toolbar" },
            el("button", { class: "btn btn-primary", "data-testid": "add-season", onclick: () => editSeason(null) }, "+ Neue Staffel"),
        ));
        const list = el("div", { class: "simple-list" });
        state.seasons.forEach(s => {
            list.appendChild(el("div", { class: "simple-row" },
                el("div", {}, el("strong", {}, s.name), el("div", { class: "muted small" }, `${state.episodes.filter(e => e.season_id === s.id).length} Episoden`)),
                el("div", { class: "row-actions" },
                    el("button", { class: "icon-btn-sm", onclick: () => editSeason(s) }, "✎"),
                    el("button", { class: "icon-btn-sm danger", onclick: async () => {
                        if (!confirm("Staffel löschen?")) return;
                        try { await del(`/admin/seasons/${s.id}`); await loadAll(); toast("Gelöscht"); } catch (e) { toast(e.message, "error"); }
                    }}, "🗑"),
                ),
            ));
        });
        container.appendChild(list);
    }

    function editSeason(s) {
        const isNew = !s;
        s = s || { name: "", order: state.seasons.length, hidden: false };
        openModal(isNew ? "Neue Staffel" : "Staffel bearbeiten", (body) => {
            const f = el("form", { class: "edit-form", onsubmit: async (e) => {
                e.preventDefault();
                const data = collectForm(f);
                data.order = parseInt(data.order || 0, 10);
                data.hidden = data.hidden === "on";
                try {
                    if (isNew) await post("/admin/seasons", data);
                    else await put(`/admin/seasons/${s.id}`, data);
                    closeModal(); await loadAll(); toast("Gespeichert.");
                } catch (ex) { toast(ex.message, "error"); }
            }});
            appendField(f, "Name", el("input", { name: "name", required: true, value: s.name }));
            appendField(f, "Reihenfolge", el("input", { name: "order", type: "number", value: s.order || 0 }));
            appendField(f, "Ausgeblendet", el("input", { name: "hidden", type: "checkbox", ...(s.hidden ? { checked: true } : {}) }));
            f.appendChild(el("div", { class: "form-actions" },
                el("button", { type: "button", class: "btn btn-secondary", onclick: closeModal }, "Abbrechen"),
                el("button", { type: "submit", class: "btn btn-primary" }, "Speichern"),
            ));
            body.appendChild(f);
        });
    }

    // ---- Countries ----
    function viewCountries(container) {
        const addForm = el("form", { class: "inline-form", onsubmit: async (e) => {
            e.preventDefault();
            const name = e.target.name.value.trim(); const flag = e.target.flag.value.trim();
            if (!name) return;
            try { await post("/admin/countries", { name, flag_emoji: flag }); e.target.reset(); await loadAll(); toast("Land hinzugefügt"); } catch (ex) { toast(ex.message, "error"); }
        }},
            el("input", { name: "name", placeholder: "Landname", required: true, "data-testid": "country-name-input" }),
            el("input", { name: "flag", placeholder: "🇦🇺 (optional)", maxlength: 4 }),
            el("button", { class: "btn btn-primary", type: "submit", "data-testid": "add-country" }, "Hinzufügen"),
        );
        container.appendChild(addForm);
        const list = el("div", { class: "chip-list" });
        state.countries.forEach(c => {
            list.appendChild(el("div", { class: "chip-item" },
                `${c.flag_emoji || ""} ${c.name}`,
                el("button", { class: "chip-x", onclick: async () => {
                    if (!confirm(`Land "${c.name}" löschen?`)) return;
                    try { await del(`/admin/countries/${c.id}`); await loadAll(); } catch (e) { toast(e.message, "error"); }
                }}, "×"),
            ));
        });
        container.appendChild(list);
    }

    // ---- Settings ----
    const GOOGLE_FONTS = [
        "Bebas Neue", "Inter", "Roboto", "Montserrat", "Playfair Display",
        "Oswald", "Poppins", "Lora", "Raleway", "Anton", "Archivo",
        "DM Sans", "Space Grotesk", "Fjalla One", "Josefin Sans", "Rubik",
        "Work Sans", "Nunito", "Merriweather", "Cormorant Garamond",
    ];

    function viewSettings(container) {
        const f = el("form", { class: "edit-form settings-form", onsubmit: async (e) => {
            e.preventDefault();
            const data = collectForm(f);
            // Socials zusammenbauen
            data.socials = {
                instagram: (data.social_instagram || "").trim(),
                youtube: (data.social_youtube || "").trim(),
                tiktok: (data.social_tiktok || "").trim(),
                x: (data.social_x || "").trim(),
                website: (data.social_website || "").trim(),
            };
            // Nur nicht-leere behalten
            Object.keys(data.socials).forEach(k => { if (!data.socials[k]) delete data.socials[k]; });
            delete data.social_instagram; delete data.social_youtube;
            delete data.social_tiktok; delete data.social_x; delete data.social_website;
            try {
                const updated = await put("/admin/settings", data);
                state.settings = updated;
                toast("Einstellungen gespeichert – Änderungen sind sofort live");
            } catch (ex) { toast(ex.message, "error"); }
        }});

        f.appendChild(el("h3", {}, "Serie & Titel"));
        appendField(f, "Serientitel (Hero-Überschrift)", el("input", { name: "series_title", value: state.settings.series_title || "" }));
        appendField(f, "Standard-Staffelname", el("input", { name: "season_default_name", value: state.settings.season_default_name || "" }));
        appendField(f, "Hero-Untertitel", el("input", { name: "hero_subtitle", value: state.settings.hero_subtitle || "" }));
        appendField(f, "Hero-Beschreibung", el("textarea", { name: "hero_description", rows: 3 }, state.settings.hero_description || ""));

        f.appendChild(el("h3", {}, "Logo & Marke"));
        appendField(f, "Logo-Text (Name in der Navigation)", el("input", { name: "logo_text", value: state.settings.logo_text || "", placeholder: "z.B. Weltreise" }));
        appendField(f, "Logo-Bild (ersetzt das Buchstaben-Symbol)", pickerField("logo_url", state.settings.logo_url, "image"));

        f.appendChild(el("h3", {}, "Bilder"));
        appendField(f, "Titelbild (Hero-Bild)", pickerField("hero_image_url", state.settings.hero_image_url, "image"));
        appendField(f, "Hero-Hintergrundbild (überschreibt Titelbild)", pickerField("hero_bg_url", state.settings.hero_bg_url, "image"));

        f.appendChild(el("h3", {}, "Farben"));
        const colorRow = el("div", { class: "color-row" },
            colorField("Hintergrund", "color_bg", state.settings.color_bg || "#141414"),
            colorField("Akzent", "color_accent", state.settings.color_accent || "#e50914"),
            colorField("Schrift", "color_text", state.settings.color_text || "#ffffff"),
        );
        f.appendChild(colorRow);

        f.appendChild(el("h3", {}, "Schriftarten"));
        appendField(f, "Schrift Überschriften", fontSelect("font_heading", state.settings.font_heading || "Bebas Neue"));
        appendField(f, "Schrift Fließtext", fontSelect("font_body", state.settings.font_body || "Inter"));

        f.appendChild(el("h3", {}, "Jahr & Footer"));
        appendField(f, "Jahr (im Hero angezeigt)", el("input", { name: "year", value: state.settings.year || "2026" }));
        appendField(f, "Footer-Text", el("input", { name: "footer_text", value: state.settings.footer_text || "" }));

        f.appendChild(el("h3", {}, "Social-Media-Links"));
        const soc = state.settings.socials || {};
        appendField(f, "Instagram-URL", el("input", { name: "social_instagram", value: soc.instagram || "", placeholder: "https://instagram.com/..." }));
        appendField(f, "YouTube-URL", el("input", { name: "social_youtube", value: soc.youtube || "", placeholder: "https://youtube.com/@..." }));
        appendField(f, "TikTok-URL", el("input", { name: "social_tiktok", value: soc.tiktok || "", placeholder: "https://tiktok.com/@..." }));
        appendField(f, "X (Twitter) URL", el("input", { name: "social_x", value: soc.x || "", placeholder: "https://x.com/..." }));
        appendField(f, "Eigene Website", el("input", { name: "social_website", value: soc.website || "", placeholder: "https://..." }));

        f.appendChild(el("div", { class: "form-actions sticky" },
            el("a", { href: "/", target: "_blank", class: "btn btn-secondary", "data-testid": "open-preview" }, "Vorschau in neuem Tab"),
            el("button", { type: "submit", class: "btn btn-primary", "data-testid": "save-settings" }, "Alle Einstellungen speichern"),
        ));
        container.appendChild(f);
    }

    function fontSelect(name, currentValue) {
        const wrap = el("div", { class: "picker-row" });
        const select = el("select", { name },
            el("option", { value: "" }, "— Standard —"),
            ...GOOGLE_FONTS.map(font => el("option", { value: font, ...(currentValue === font ? { selected: true } : {}) }, font)),
            el("option", { value: "__custom__" }, "Eigene Schrift..."),
        );
        const custom = el("input", { type: "text", name: `${name}_custom`, placeholder: "Google-Font-Name", value: (!GOOGLE_FONTS.includes(currentValue) && currentValue) ? currentValue : "" });
        custom.style.display = (!GOOGLE_FONTS.includes(currentValue) && currentValue) ? "block" : "none";
        select.addEventListener("change", () => {
            if (select.value === "__custom__") {
                custom.style.display = "block";
                custom.focus();
                // Beim Speichern muss der eigene Wert genutzt werden
                select.dataset.useCustom = "1";
            } else {
                custom.style.display = "none";
                delete select.dataset.useCustom;
            }
        });
        // Custom-Wert übernehmen
        custom.addEventListener("input", () => { select.dataset.customValue = custom.value; });
        if (!GOOGLE_FONTS.includes(currentValue) && currentValue) {
            select.value = "__custom__";
            select.dataset.useCustom = "1";
            select.dataset.customValue = currentValue;
        }
        wrap.appendChild(select);
        wrap.appendChild(custom);
        return wrap;
    }

    function colorField(label, name, val) {
        return el("label", { class: "color-field" },
            el("span", {}, label),
            el("input", { type: "color", name, value: val }),
            el("input", { type: "text", name: `${name}_text`, value: val, class: "color-text",
                oninput: (e) => { const c = e.target.parentElement.querySelector('input[type=color]'); if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(e.target.value)) c.value = e.target.value; } }),
        );
    }

    // ---- Security ----
    function viewSecurity(container) {
        container.appendChild(el("div", { class: "card" },
            el("h3", {}, "Zugangscode ändern (für Besucher)"),
            el("p", { class: "muted" }, "Alle Besucher müssen den aktuellen Zugangscode kennen, um die Serie zu sehen."),
            (() => {
                const f = el("form", { class: "edit-form", onsubmit: async (e) => {
                    e.preventDefault();
                    const nc = f.querySelector('input[name="new_code"]').value.trim();
                    if (nc.length < 4) { toast("Mindestens 4 Zeichen.", "error"); return; }
                    try { await post("/admin/change-access-code", { new_code: nc }); f.reset(); toast("Zugangscode aktualisiert."); }
                    catch (ex) { toast(ex.message, "error"); }
                }});
                appendField(f, "Neuer Zugangscode", el("input", { name: "new_code", type: "password", required: true, "data-testid": "new-access-code" }));
                f.appendChild(el("button", { class: "btn btn-primary", type: "submit", "data-testid": "save-access-code" }, "Speichern"));
                return f;
            })(),
        ));
        container.appendChild(el("div", { class: "card" },
            el("h3", {}, "Admin-Passwort ändern"),
            (() => {
                const f = el("form", { class: "edit-form", onsubmit: async (e) => {
                    e.preventDefault();
                    const np = f.querySelector('input[name="new_password"]').value;
                    if (np.length < 6) { toast("Mindestens 6 Zeichen.", "error"); return; }
                    try { await post("/admin/change-password", { new_password: np }); f.reset(); toast("Admin-Passwort aktualisiert."); }
                    catch (ex) { toast(ex.message, "error"); }
                }});
                appendField(f, "Neues Admin-Passwort", el("input", { name: "new_password", type: "password", required: true, "data-testid": "new-admin-pw" }));
                f.appendChild(el("button", { class: "btn btn-primary", type: "submit" }, "Passwort ändern"));
                return f;
            })(),
        ));
    }

    // ---- Form helpers ----
    function appendField(f, label, input) {
        f.appendChild(el("div", { class: "field" }, el("label", {}, label), input));
    }
    function collectForm(f) {
        const data = {};
        for (const el of f.querySelectorAll("input,textarea,select")) {
            if (!el.name) continue;
            // Nur die Hex-Spiegel-Inputs der Color-Picker überspringen (identifiziert per Klasse)
            if (el.classList && el.classList.contains("color-text")) continue;
            if (el.name.endsWith("_custom")) continue; // Font custom mirror
            if (el.type === "checkbox") data[el.name] = el.checked ? "on" : "";
            else if (el.tagName === "SELECT" && el.dataset.useCustom === "1") {
                data[el.name] = (el.dataset.customValue || "").trim();
            }
            else data[el.name] = el.value;
        }
        // Color-Picker (name ohne _text-Suffix) direkt übernehmen
        f.querySelectorAll('input[type="color"]').forEach(c => { data[c.name] = c.value; });
        return data;
    }

    // ---- Modal (mit Stack für verschachtelte Modals) ----
    const modalStack = [];
    function openModal(title, builder) {
        const modal = el("div", { class: "admin-modal", onclick: (e) => { if (e.target === modal) closeModal(); } },
            el("div", { class: "admin-modal-inner" },
                el("div", { class: "admin-modal-header" },
                    el("h2", {}, title),
                    el("button", { class: "admin-modal-close", onclick: closeModal, "aria-label": "Schließen" }, "×")),
                (() => { const body = el("div", { class: "admin-modal-body" }); builder(body); return body; })(),
            ));
        // Vorheriges Modal visuell in den Hintergrund treten lassen
        if (modalStack.length) {
            const prev = modalStack[modalStack.length - 1];
            prev.classList.add("is-under");
        }
        document.body.appendChild(modal);
        modalStack.push(modal);
    }
    function closeModal() {
        const m = modalStack.pop();
        if (!m) return;
        m.remove();
        if (modalStack.length) {
            const top = modalStack[modalStack.length - 1];
            top.classList.remove("is-under");
        }
    }

    // ---- Login form ----
    window.AuthGuard.mountAdminForm({
        formId: "admin-form", inputId: "admin-input", errorId: "admin-error",
        submitSelector: ".auth-submit",
        onSuccess: () => { state.role = "admin"; showDashboard(); },
    });

    // ---- Logout ----
    document.getElementById("admin-logout-btn").addEventListener("click", async () => {
        await window.AuthGuard.logout(); window.location.reload();
    });

    // ---- Init ----
    checkAuth();
})();
