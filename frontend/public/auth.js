/* -------- Shared Auth Helper -------- */
(function () {
    const API = "/api";

    async function authMe() {
        try {
            const r = await fetch(`${API}/auth/me`, { credentials: "include" });
            if (!r.ok) return { authenticated: false, role: null };
            return await r.json();
        } catch { return { authenticated: false, role: null }; }
    }

    async function submitAccess(code) {
        const r = await fetch(`${API}/auth/access-code`, {
            method: "POST", credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ code }),
        });
        if (!r.ok) {
            const err = await r.json().catch(() => ({}));
            throw new Error(err.detail || "Anmeldung fehlgeschlagen.");
        }
        return await r.json();
    }

    async function submitAdmin(password) {
        const r = await fetch(`${API}/auth/admin`, {
            method: "POST", credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ password }),
        });
        if (!r.ok) {
            const err = await r.json().catch(() => ({}));
            throw new Error(err.detail || "Anmeldung fehlgeschlagen.");
        }
        return await r.json();
    }

    async function logout() {
        try { await fetch(`${API}/auth/logout`, { method: "POST", credentials: "include" }); } catch {}
    }

    function mountAccessForm({ formId, inputId, errorId, submitSelector, onSuccess }) {
        const form = document.getElementById(formId);
        const input = document.getElementById(inputId);
        const err = document.getElementById(errorId);
        const btn = form.querySelector(submitSelector);
        const spinner = btn.querySelector(".btn-spinner");
        const label = btn.querySelector(".btn-label");

        // Eye toggle
        const eye = document.getElementById("toggle-eye");
        if (eye) {
            eye.addEventListener("click", () => {
                const t = input.type === "password" ? "text" : "password";
                input.type = t;
            });
        }

        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            err.hidden = true; err.textContent = "";
            btn.disabled = true;
            if (label) label.style.opacity = "0.4";
            if (spinner) spinner.hidden = false;
            try {
                await submitAccess(input.value.trim());
                onSuccess?.();
            } catch (ex) {
                input.classList.add("shake");
                setTimeout(() => input.classList.remove("shake"), 500);
                err.textContent = ex.message || "Zugangscode ungültig.";
                err.hidden = false;
            } finally {
                btn.disabled = false;
                if (label) label.style.opacity = "1";
                if (spinner) spinner.hidden = true;
            }
        });
        setTimeout(() => input.focus(), 200);
    }

    function mountAdminForm({ formId, inputId, errorId, submitSelector, onSuccess }) {
        const form = document.getElementById(formId);
        const input = document.getElementById(inputId);
        const err = document.getElementById(errorId);
        const btn = form.querySelector(submitSelector);
        const spinner = btn.querySelector(".btn-spinner");
        const label = btn.querySelector(".btn-label");
        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            err.hidden = true; err.textContent = "";
            btn.disabled = true;
            if (label) label.style.opacity = "0.4";
            if (spinner) spinner.hidden = false;
            try {
                await submitAdmin(input.value);
                onSuccess?.();
            } catch (ex) {
                input.classList.add("shake");
                setTimeout(() => input.classList.remove("shake"), 500);
                err.textContent = ex.message || "Passwort ungültig.";
                err.hidden = false;
            } finally {
                btn.disabled = false;
                if (label) label.style.opacity = "1";
                if (spinner) spinner.hidden = true;
            }
        });
        setTimeout(() => input.focus(), 200);
    }

    // Guard for viewer pages: redirect to /login.html if not authenticated
    async function requireViewer() {
        const me = await authMe();
        if (!me.authenticated) {
            window.location.replace("/login.html");
            return null;
        }
        return me;
    }

    window.AuthGuard = { authMe, submitAccess, submitAdmin, logout, mountAccessForm, mountAdminForm, requireViewer, API };
})();
