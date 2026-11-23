<?php
/**
 * dirks3n – Ersteinrichtung: legt deinen Benutzer an.
 * Funktioniert nur, solange noch KEIN Benutzer existiert, und nur mit dem setup_key aus config.php.
 * Danach ist diese Seite automatisch gesperrt.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/private/src/bootstrap.php';

Http::requireHttps();
Http::securityHeaders();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");

$msg = '';
$ok = false;
$tablesMissing = false;
try {
    $hasUser = Auth::hasUser();
} catch (Throwable $e) {
    $hasUser = false;
    $tablesMissing = true;
}

if (!$tablesMissing && !$hasUser && Http::method() === 'POST') {
    $key = (string)($_POST['setup_key'] ?? '');
    $user = trim((string)($_POST['username'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    if (str_starts_with((string)cfg('setup_key'), 'ÄNDERN') || !token_equals((string)cfg('setup_key'), $key)) {
        usleep(800000);
        $msg = 'Einrichtungs-Schlüssel falsch (oder in config.php noch nicht geändert).';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $user)) {
        $msg = 'Benutzername: 3–60 Zeichen, nur Buchstaben, Zahlen, Punkt, Minus, Unterstrich.';
    } elseif (mb_strlen($pw) < 12) {
        $msg = 'Passwort muss mindestens 12 Zeichen lang sein.';
    } elseif ($pw !== $pw2) {
        $msg = 'Die Passwörter stimmen nicht überein.';
    } else {
        Auth::createUser($user, $pw);
        $ok = true;
        $hasUser = true;
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>dirks3n – Einrichtung</title>
<style>
  body { background:#07080a; color:#f4f6f8; font:16px/1.5 -apple-system, system-ui, sans-serif; margin:0; padding:24px 16px; }
  main { max-width:420px; margin:0 auto; }
  h1 { font-size:24px; } label { display:block; margin:14px 0 4px; color:#a3abb8; font-size:14px; }
  input { width:100%; box-sizing:border-box; padding:12px; border-radius:10px; border:1px solid #333; background:#171a20; color:#f4f6f8; font-size:16px; }
  button { margin-top:20px; width:100%; padding:14px; border:0; border-radius:14px; background:#c6f432; color:#0b0d10; font-size:16px; font-weight:600; }
  .msg { padding:12px; border-radius:10px; background:#3a1d1d; margin-top:16px; } .ok { background:#14301f; }
  a { color:#c6f432; }
</style>
</head>
<body><main>
<h1>dirks3n einrichten</h1>
<?php if ($tablesMissing): ?>
  <p class="msg">Die Datenbank-Tabellen fehlen. Bitte zuerst <code>private/sql/schema.sql</code> in phpMyAdmin ausführen (siehe Anleitung).</p>
<?php elseif ($ok): ?>
  <p class="msg ok">Fertig! Dein Benutzer wurde angelegt. Diese Seite ist ab jetzt gesperrt.</p>
  <p><a href="./">→ Zur App und anmelden</a></p>
<?php elseif ($hasUser): ?>
  <p class="msg ok">Die Einrichtung ist bereits abgeschlossen.</p>
  <p><a href="./">→ Zur App</a></p>
<?php else: ?>
  <p>Lege deinen Zugang an. Den Einrichtungs-Schlüssel findest du in <code>private/config.php</code> unter <code>setup_key</code>.</p>
  <?php if ($msg): ?><p class="msg"><?= h($msg) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <label for="k">Einrichtungs-Schlüssel</label>
    <input id="k" name="setup_key" type="password" required>
    <label for="u">Benutzername</label>
    <input id="u" name="username" required autocapitalize="none" value="<?= h((string)($_POST['username'] ?? '')) ?>">
    <label for="p">Passwort (mind. 12 Zeichen)</label>
    <input id="p" name="password" type="password" required minlength="12" autocomplete="new-password">
    <label for="p2">Passwort wiederholen</label>
    <input id="p2" name="password2" type="password" required minlength="12" autocomplete="new-password">
    <button type="submit">Benutzer anlegen</button>
  </form>
<?php endif; ?>
</main></body>
</html>
