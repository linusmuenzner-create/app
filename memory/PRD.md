# Meine Weltreise – Streaming Documentation Site

## Aktueller Stand (Feb 2026)
Vollständige, geschützte Streaming-Plattform mit Admin-Panel.

## Problem Statement (verkürzt)
Zugangsgeschützte, Netflix-artige Website zum Betrachten der persönlichen Reise-Serie
"Meine Weltreise" mit vollständigem Admin-Panel zum Verwalten aller Inhalte
(Videos, Episoden, Staffeln, Länder, Design, Zugangsdaten) ohne Code-Änderungen.

## Tech-Stack (User-bestätigt)
- **Backend:** FastAPI (Python) + MongoDB
- **Frontend:** Vanilla HTML/CSS/JS (kein Framework)
- **Auth:** JWT (HS256) in httpOnly Secure Cookie, 30 Tage Laufzeit
- **File-Storage:** Server-Disk unter `/app/backend/uploads/{videos,images}/`
- **Password-Hashing:** bcrypt

## Zugangsdaten (in /app/memory/test_credentials.md)
- **Website-Zugangscode:** `pineapplethefruitdude`
- **Admin-Passwort:** `Jagger-Admin-2026-Kx9!Pq`
- Beide im Admin-Panel unter "Sicherheit" änderbar.

## Struktur
```
/app/backend/
  server.py          – FastAPI-App (Auth, Content, Admin CRUD, Uploads, Seed)
  .env               – JWT_SECRET, INITIAL_ACCESS_CODE, INITIAL_ADMIN_PASSWORD, UPLOAD_DIR
  uploads/videos/    – hochgeladene Videos
  uploads/images/    – hochgeladene Bilder & Thumbnails

/app/frontend/public/
  login.html         – Zugangscode-Eingabe
  index.html         – Hauptseite (Serie ansehen)
  admin.html         – Admin-Dashboard
  auth.js/auth.css   – Auth-Guard + Login-UI
  script.js/style.css – Serie-UI (Hero, Episoden, Player, Filter)
  admin.js/admin.css  – Admin-Dashboard-UI
  data/episodes.json  – Seed-Fallback (falls DB leer beim Startup)
```

## API-Endpunkte
### Public (nach Access-Code)
- `GET  /api/auth/me`
- `POST /api/auth/access-code {code}`
- `POST /api/auth/admin {password}`
- `POST /api/auth/logout`
- `GET  /api/content` – Settings + Seasons + Episoden + Länder
- `GET  /api/uploads/{videos|images}/{fname}` – nur mit Cookie

### Admin (Rolle admin)
- `GET/PUT /api/admin/settings`
- `GET/POST/PUT/DELETE /api/admin/seasons` (Delete kaskadiert Episoden)
- `GET/POST/PUT/DELETE /api/admin/episodes`
- `POST /api/admin/episodes/reorder {ids: []}`
- `GET/POST/DELETE /api/admin/countries`
- `GET /api/admin/media`  `DELETE /api/admin/media/{id}`
- `POST /api/admin/upload` (multipart file + kind)
- `POST /api/admin/change-access-code`
- `POST /api/admin/change-password`

## Implementierte Features
### Zugangsschutz
- ✅ Elegante Login-Seite mit Fehler-Animation (Shake)
- ✅ JWT-Cookie mit httpOnly+Secure+SameSite=Lax
- ✅ Brute-Force-Schutz (Lockout nach 5 Fehlversuchen, 15 min – nur bei Falscheingaben)
- ✅ Persistent bis Logout

### Admin-Panel (`/admin.html`)
- ✅ Übersicht mit Statistiken (Episoden, Staffeln, Länder, Medien) und Fortschritts-Balken
- ✅ Episoden: Tabelle pro Staffel, Reihenfolge ändern (▲▼), Sichtbarkeit-Toggle, Bearbeiten (Modal mit allen Feldern), Löschen
- ✅ Videos verwalten: Drag&Drop Zone (Video + Thumbnails), Multi-Upload, XHR-Fortschrittsbalken
- ✅ Medien-Bibliothek: alle Uploads mit Vorschau, Löschen
- ✅ Staffeln: Create/Edit/Delete (Kaskadierend), Reihenfolge
- ✅ Länder: Add mit Flag-Emoji, Delete
- ✅ Einstellungen: Serientitel, Untertitel, Beschreibung, Hero-Bild, Hero-Hintergrund, Logo, Farben (Hintergrund + Akzent mit Color-Picker), Schriftarten, Footer
- ✅ Sicherheit: Zugangscode ändern, Admin-Passwort ändern
- ✅ Sidebar-Nav mit aktivem Tab-Highlight
- ✅ Toast-Notifications
- ✅ Media-Picker in Episoden-Bearbeitung (Aus Medien wählen)

### Website (Viewer)
- ✅ Alle bisherigen Features (Hero, Kartenraster, Detail-Modal, Player, Autoplay, Favoriten, Fortschritt, Suche, Filter, Tastatursteuerung)
- ✅ Dynamisches Laden aus API statt statischer JSON
- ✅ Live-Übernahme geänderter Design-Einstellungen (Farben, Titel, Beschreibung, Bilder)
- ✅ Staffel-Filter (erscheint automatisch bei >1 Staffel)
- ✅ Admin-Link + Abmelden im Nav

## Sicherheit
- Passwort-Hashing: bcrypt (12 Rounds default)
- JWT: HS256, 30-Tage Ablauf
- httpOnly + Secure Cookies verhindern XSS-Token-Diebstahl
- SameSite=Lax + JSON-Body verhindert einfache CSRF
- Uploads: MIME-Whitelist (mp4/webm/mov/mkv, jpg/png/webp/gif/svg), Größenlimit 500 MB, sanitizer für Dateinamen
- Uploaded files sind auth-guarded (401 ohne Cookie)
- Login-Attempts mit TTL-Index (automatisches Löschen)

## Test-Ergebnisse (iteration_1)
- Backend: **17/17 pytest** (Auth, RBAC, CRUD, Uploads, Brute-Force, Change-Credential-Roundtrip)
- Frontend Playwright: **100%** aller Flows (Login falsch/richtig, Home, Search, Filter, Detail-Modal, alle 8 Admin-Tabs, Logout)

## Bug-Fixes nach Testing (Testing-Agent minor issues)
- ✅ Brute-Force prüft nun erst Credentials, dann Lockout — legitime Nutzer können sofort nach richtiger Eingabe rein
- ✅ Season-Delete kaskadiert nun automatisch alle Episoden der Staffel

## Backlog / Next Steps
- **P1**: Reales Domain-Deployment (jaggerderby.com) via Deployment-Agent
- **P1**: Videodauer automatisch via ffprobe extrahieren beim Upload (aktuell manuell)
- **P2**: Reset-Passwort per E-Mail (setzt Mail-Integration voraus – Resend/SendGrid)
- **P2**: PostHog/Analytics für Watch-Statistiken
- **P2**: CSRF-Token für zusätzliche Härtung
- **P3**: PWA-Manifest + Offline-Support
