# Meine Weltreise – Streaming Documentation Site

## Problem Statement (Original, German)
Erstelle eine moderne, responsive Website, die optisch an eine Streaming-Plattform erinnert, aber kein Netflix-Logo oder geschützte Markenelemente verwendet.

Thema: Meine Weltreise als Serie (Staffel 1 mit 14 Wochen Australien, 3 Tage Bali, 1 Woche Lombok, 2 Wochen Philippinen).

Design: dunkler Hintergrund (#141414), Akzent-Rot (#E50914), große Hero-Sektion, elegante Sans-Serif, sanfte Hover-Animationen, Kartenlayout, responsive.

## User Choices (from ask_human)
- **Tech-Stack:** Reines HTML/CSS/JS ohne Frameworks
- **Videos/Thumbnails:** User lädt eigene Dateien hoch
- **Anzahl Episoden:** 18 (14 Australien + 1 Bali + 1 Lombok + 2 Philippinen)
- **Speicherung:** Nur LocalStorage (kein Backend)

## Architecture
- **Frontend:** Statisches HTML/CSS/JS in `/app/frontend/public/`
  - `index.html` – Struktur (Hero, Navbar, Grid, Detail-Modal, Intro-Screen)
  - `style.css` – Netflix-inspired Dark Theme, Bebas Neue + Inter Fonts
  - `script.js` – Vanilla JS, Modul-Pattern, LocalStorage-Persistenz
  - `data/episodes.json` – 18 Episoden inkl. Highlights & Reisedetails
  - `videos/` – Ordner für vom User hochzuladende Videodateien
  - `thumbnails/` – Ordner für Vorschaubilder (mit farbigem SVG-Fallback)
- **React-Shell:** `src/App.js` gibt `null` zurück; Root-Div ist `display:none`
- **Kein Backend:** Kein FastAPI-Endpoint benötigt, MongoDB ungenutzt

## Implementierte Features (Feb 2026)
- ✅ Intro-Animation beim Seitenladen (Bebas-Letter-Reveal)
- ✅ Fixed Navbar mit Scroll-Effekt (Blur)
- ✅ Große Hero-Sektion mit animiertem Backdrop, Badge, Titel, Meta, Beschreibung, 2 CTA-Buttons
- ✅ Suchfeld (Debounced, sucht in Titel/Ort/Beschreibung/Highlights)
- ✅ Länder-Filter-Chips (Alle, Australien, Indonesien, Philippinen) mit Flag-Emojis
- ✅ 18 Episoden-Karten (Hover-Zoom, Play-Overlay, Episodennummer-Badge, Fortschrittsbalken)
- ✅ Farbige SVG-Platzhalter pro Land, falls Thumbnail fehlt
- ✅ Detail-Modal mit Videoplayer, Highlights, Reisedetails, Up-Next-Karte
- ✅ Autoplay-Countdown (5 s) → nächste Episode
- ✅ Favoriten-Marker (Herz-Icon in Karte + Detail)
- ✅ "Weiter ansehen"-Sektion (nur bei angefangenen Videos)
- ✅ "Favoriten"-Sektion
- ✅ Fortschritt & Watched-Status in LocalStorage
- ✅ Tastatursteuerung: Space (Play/Pause), F (Vollbild), Esc (Schließen), ←/→ (10 s), N (Next)
- ✅ Vollbild-Modus
- ✅ Skeleton-Loading-Animation
- ✅ Sanfte Fade-in-, Stagger- und Micro-Animations
- ✅ Vollständig responsive (Desktop, Tablet, Smartphone)

## Nächste Schritte / Backlog
- **P0**: User lädt eigene Videos in `/app/frontend/public/videos/ep01.mp4 ... ep18.mp4` und Thumbnails hoch
- **P1**: Optional Untertitel-Tracks (WebVTT) pro Episode
- **P1**: Sharable Deep-Links (`?ep=07`) für einzelne Folgen
- **P2**: PWA-Manifest + Offline-Support
- **P2**: Karten-Ansicht der Reiseroute

## Test-Credentials
Keine – reine Frontend-App ohne Login.

## Deployment
- Statische Dateien liegen in `/app/frontend/public/` und werden vom CRA-Dev-Server auf Port 3000 ausgeliefert
- Externe URL: `REACT_APP_BACKEND_URL` aus `/app/frontend/.env`
