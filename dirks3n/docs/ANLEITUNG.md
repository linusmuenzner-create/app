# dirks3n auf ALL-INKL installieren – Schritt für Schritt

Dauer: ca. 45 Minuten. Du brauchst:
- deine Zugangsdaten für das **ALL-INKL-Kundenmenü (KAS)**: <https://kas.all-inkl.com>
- ein FTP-Programm, z. B. **FileZilla** (kostenlos: <https://filezilla-project.org>)
- einen Texteditor (Windows: Notepad++ · Mac: TextEdit im Modus „Reiner Text“ oder VS Code)

> 💡 Begriffe: **KAS** = Verwaltungsoberfläche von ALL-INKL. **FTP** = Dateien vom
> Computer auf den Server kopieren. **Datenbank** = Speicher für deine Messwerte.

Die Menüpunkte im KAS können je nach Tarif etwas anders heißen. Wenn du einen Punkt nicht
findest, such ihn über die Suche oben im KAS oder schreib mir den Namen, den du siehst.

---

## Schritt 1 – Zufällige Geheimnisse erzeugen

Du brauchst **drei lange, zufällige Zeichenketten** (Einrichtungs-Schlüssel, Kurzbefehl-Token, Cron-Schlüssel).

- Mac: Programm **Terminal** öffnen → `openssl rand -hex 24` eingeben → Enter. Dreimal wiederholen.
- Windows: In einem Passwort-Manager (z. B. Bitwarden, 1Password) ein Passwort mit 40 Zeichen,
  **nur Buchstaben und Zahlen** erzeugen. Dreimal.

Notiere sie kurz (z. B. in deinem Passwort-Manager). Sie kommen gleich in die Konfigurationsdatei.

---

## Schritt 2 – Datenbank anlegen

1. KAS → **Datenbanken** → **Neue Datenbank anlegen**.
2. Kommentar: `dirks3n`. Ein **Passwort** vergeben (lang, zufällig) und notieren.
3. Speichern. ALL-INKL zeigt dir jetzt den **Datenbanknamen** (z. B. `d0412345`).
   Bei ALL-INKL ist der **Benutzername gleich dem Datenbanknamen**.
4. In der Liste bei der neuen Datenbank auf **phpMyAdmin** klicken und mit Datenbankname + Passwort anmelden.
5. Links die Datenbank anklicken → oben Reiter **SQL**.
6. Die Datei `private/sql/schema.sql` in deinem Editor öffnen, **alles** kopieren, in das SQL-Feld einfügen → **OK**.
7. Links sollten jetzt 10 Tabellen erscheinen, die mit `d3_` beginnen. ✅

---

## Schritt 3 – Konfigurationsdatei ausfüllen

1. Im Projektordner `dirks3n/private/` die Datei `config.sample.php` **kopieren** und die Kopie
   **`config.php`** nennen.
2. `config.php` im Editor öffnen und ausfüllen:

| Eintrag | Was du einträgst |
|---|---|
| `db → name` und `db → user` | Datenbankname aus Schritt 2 (z. B. `d0412345`) |
| `db → password` | Datenbank-Passwort aus Schritt 2 |
| `db → host` | `localhost` lassen |
| `setup_key` | Geheimnis Nr. 1 |
| `ingest_token` | Geheimnis Nr. 2 |
| `cron_key` | Geheimnis Nr. 3 |
| `anthropic_api_key` | erst mal leer lassen (Schritt 9) |
| `ai_monthly_budget_usd` | `3.00` (deine Obergrenze) |

3. Speichern. **Wichtig:** Die Anführungszeichen `'…'` müssen stehen bleiben.

---

## Schritt 4 – Dateien hochladen (FTP)

1. FTP-Zugangsdaten: KAS → **FTP** → dort steht dein FTP-Benutzer (z. B. `w0123456`) und der Server
   (z. B. `w0123456.kasserver.com`). Passwort ggf. dort neu setzen.
2. FileZilla → Datei → Servermanager → Neuer Server:
   - Protokoll: **FTP**, Verschlüsselung: **Explizites FTP über TLS erfordern**
   - Server, Benutzer, Passwort wie oben → Verbinden.
3. Auf dem Server (rechte Seite) bist du im Hauptverzeichnis deines Accounts. Lege dort den Ordner
   **`dirks3n`** an.
4. Lade von deinem Computer die **beiden Ordner `public` und `private`** in diesen Ordner hoch, sodass es so aussieht:

```
/dirks3n/
   ├── public/     (die App – nur dieser Ordner wird im Internet sichtbar)
   └── private/    (Konfiguration, Logik, Logs – NICHT sichtbar)
```

> ⚠️ Versteckte Dateien mitkopieren: `public/.htaccess`, `public/.user.ini`, `private/.htaccess`.
> In FileZilla: Menü **Server → Anzeige versteckter Dateien erzwingen**.

---

## Schritt 5 – Subdomain anlegen und auf `public` zeigen lassen

1. KAS → **Domain** → **Subdomains** → **Neue Subdomain anlegen**.
2. Subdomain: `dirks3n`, Domain: `munzner.net`.
3. **Pfad / Zielverzeichnis:** `/dirks3n/public/` ← ganz wichtig, **nicht** `/dirks3n/`!
   Nur so bleibt `private` von außen unerreichbar.
4. **PHP-Version:** 8.2 oder neuer auswählen (falls die Option dort angeboten wird).
5. Speichern. Es kann bis zu einer Stunde dauern, bis die Subdomain erreichbar ist.

---

## Schritt 6 – HTTPS (SSL) einschalten

1. KAS → **Domain** → **Subdomains** → bei `dirks3n.munzner.net` auf **bearbeiten** (bzw. Reiter **SSL**).
2. **SSL-Schutz** aktivieren → **Let's Encrypt** wählen (kostenlos) → speichern.
3. Falls angeboten: **„SSL erzwingen“ / HTTPS-Weiterleitung** und **HSTS** aktivieren.
   (Die App leitet zusätzlich selbst auf HTTPS um.)
4. Test: <https://dirks3n.munzner.net> öffnen → Schloss-Symbol im Browser. ✅

---

## Schritt 7 – Benutzer anlegen

1. <https://dirks3n.munzner.net/setup.php> öffnen.
2. **Einrichtungs-Schlüssel** = Geheimnis Nr. 1, Benutzername und ein **starkes Passwort (mind. 12 Zeichen)** eingeben.
3. „Benutzer anlegen“. Danach ist `setup.php` automatisch gesperrt.
4. <https://dirks3n.munzner.net> öffnen und anmelden. ✅
5. Unter **Profil → Server** prüfen: PHP-Version ✓, Max. Laufzeit 60 s ✓, HTTPS aktiv ✓.

> Wenn „Max. Laufzeit“ nicht 60 s zeigt: Die Datei `public/.user.ini` wirkt erst nach ca. 5 Minuten.
> Bleibt es dabei, kannst du den Wert im KAS bei den **PHP-Einstellungen** der Subdomain setzen
> (`max_execution_time = 60`). Die App funktioniert aber auch mit 30 s – KI-Aufrufe brechen
> dann in seltenen Fällen ab, und es greifen die Regeln.

---

## Schritt 8 – App aufs iPhone holen

1. Auf dem iPhone in **Safari** <https://dirks3n.munzner.net> öffnen und anmelden.
2. Teilen-Symbol (Quadrat mit Pfeil) → **„Zum Home-Bildschirm“** → Hinzufügen.
3. dirks3n startet jetzt wie eine normale App im Vollbild. Du bleibst 30 Tage angemeldet.

---

## Schritt 9 – Claude-API-Schlüssel (für die KI)

1. <https://console.anthropic.com> → Konto anlegen.
2. **Billing / Plans** → Guthaben aufladen (z. B. 5 $). Zusätzlich unter **Limits** ein
   **monatliches Ausgabenlimit** (z. B. 5 $) setzen – doppelte Sicherheit zur App-Grenze.
3. **API Keys → Create Key** → Namen `dirks3n` → Schlüssel kopieren (beginnt mit `sk-ant-`).
4. In `private/config.php` bei `anthropic_api_key` einfügen, Datei per FTP erneut hochladen.
5. In der App „Neu entscheiden“ tippen → unten steht „Entschieden von der KI“. ✅

> Den Schlüssel **nie** ins Frontend, in Chats oder in Git. Er liegt nur in `private/config.php`.

---

## Schritt 10 – Cronjob einrichten

Der Cronjob ruft stündlich eine Adresse auf. Dabei lädt er den Kalender, erstellt bei Bedarf die
Tagesentscheidung (spätestens um 10 Uhr, falls morgens keine Daten kamen) und räumt nachts auf.

1. KAS → **Tools** → **Cronjobs** → **Neuen Cronjob anlegen**.
2. Protokoll/URL: `https://dirks3n.munzner.net/cron.php?key=GEHEIMNIS-NR-3`
3. Zeit: **jede Stunde** (Minute z. B. `7`). Falls dein Tarif nur wenige Cronjobs erlaubt: mindestens
   täglich um **10:07 Uhr**, dann kommt der Kalender nur einmal am Tag.
4. Optional: E-Mail-Benachrichtigung **aus** (sonst bekommst du jede Stunde eine Mail).
5. Test: Die URL im Browser öffnen → es erscheinen Zeilen wie „Kalender: …“, „Entscheidung: …“. ✅

---

## Schritt 11 – Kalender verbinden (nur lesend)

**iPhone/iCloud-Kalender:**
1. iPhone → App **Kalender** → unten **Kalender** → beim gewünschten Kalender auf **(i)**.
2. **Öffentlicher Kalender** einschalten → **Link teilen…** → **Kopieren**.
3. ⚠️ Jeder, der diesen Link kennt, kann die Termine sehen. Gib ihn nirgendwo anders ein.

**Google Kalender:**
1. Am Computer <https://calendar.google.com> → Zahnrad → **Einstellungen** → links deinen Kalender.
2. Ganz unten **„Privatadresse im iCal-Format“** kopieren.

Dann in dirks3n → **Profil → Kalender** den Link einfügen (ein Link pro Zeile) → **Kalender jetzt laden**.

---

## Schritt 12 – Kurzbefehl für Apple Health

Siehe **[KURZBEFEHL.md](KURZBEFEHL.md)**.

---

## Typische Grenzen von Shared Hosting (und was das für dich heißt)

| Grenze | Wo sie dich betrifft | Lösung in dirks3n |
|---|---|---|
| PHP-Laufzeit (oft 30 s) | KI-Aufrufe dauern 5–20 s | `.user.ini` setzt 60 s; die KI-Anfrage bricht nach 55 s ab und dann greifen die Regeln |
| Keine dauerhaften Prozesse | Nichts läuft „im Hintergrund“ | Cronjob jede Stunde + Entscheidung direkt nach dem Datenempfang |
| Upload-Größe | Fotos (spätere Module) | Fotos werden auf dem iPhone auf ca. 1024 px verkleinert, Limit 10 MB |
| Arbeitsspeicher | große Datenmengen | Daten werden in Blöcken gespeichert; 256 MB reichen locker |
| Cron-Anzahl je Tarif | Häufigkeit | Ein einziger Cronjob erledigt alles |

## Sicherheit – was bereits eingebaut ist

- Passwort-Hashing mit Argon2id (oder bcrypt), Sperre nach 5 Fehlversuchen pro 15 Minuten.
- Sitzungs-Cookie: `Secure`, `HttpOnly`, `SameSite=Strict`; CSRF-Schutz für alle Änderungen.
- HTTPS-Zwang + HSTS, strenge Content-Security-Policy, keine Fremd-Skripte (Chart.js liegt lokal).
- `private/` liegt außerhalb des Web-Verzeichnisses und ist zusätzlich per `.htaccess` gesperrt.
- Datenempfang und Cron nur mit geheimem Token/Schlüssel; Vergleich zeitkonstant.
- Der Service Worker speichert **keine** Gesundheitsdaten zwischen.

## Updates einspielen

Neue Versionen: einfach die geänderten Dateien per FTP überschreiben. **`private/config.php` nie überschreiben.**
Falls sich die Datenbank ändert, liefere ich ein kleines SQL-Update mit.
