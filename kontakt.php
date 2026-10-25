<?php
/*
 * Kontaktformular von meine-futterkiste.de
 * Nimmt die Felder aus #contactForm (index.html) entgegen und schickt sie an die Futterkiste.
 * Einstellungen (Empfänger, Absender, SMTP-Zugang) stehen in config.php.
 * Versand per SMTP über das STRATO-Postfach, sobald dort ein Passwort eingetragen ist, sonst per mail().
 * Antwortet mit JSON, wenn die Seite per fetch sendet, sonst Weiterleitung zurück auf index.html#kontakt.
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');

const ANLIEGEN = ['BARF-Beratung', 'Futterfrage', 'Produktanfrage', 'Sonstiges'];

$wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function reply(bool $ok, string $error = '', int $code = 200): void {
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: index.html?kontakt=' . ($ok ? 'ok' : 'fehler') . '#kontakt', true, 303);
    }
    exit;
}

// Einzeilige Felder: Steuerzeichen und Zeilenumbrüche raus (verhindert Header-Injection), Länge begrenzen
function line(string $key, int $max): string {
    $v = (string)($_POST[$key] ?? '');
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $v) ?? ''), 0, $max);
}

function header_addr(string $name, string $mail): string {
    return mb_encode_mimeheader($name, 'UTF-8', 'B') . ' <' . $mail . '>';
}

// Höchstens $limit Anfragen pro Stunde und IP. Merkt sich nur einen Hash der IP im Temp-Ordner des Servers.
// Klappt das Schreiben dort nicht, wird die Sperre einfach übersprungen.
function rate_limited(int $limit): bool {
    if ($limit <= 0) return false;
    $dir  = rtrim(sys_get_temp_dir(), '/\\');
    $file = $dir . '/mfk-kontakt-' . hash('sha256', 'mfk' . ($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
    $now  = time();
    // Einträge, die älter als eine Stunde sind, löschen (so steht es auch in der Datenschutzerklärung)
    foreach (glob($dir . '/mfk-kontakt-*.json') ?: [] as $old) {
        if (@filemtime($old) < $now - 3600) @unlink($old);
    }
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string)@file_get_contents($file), true);
        $hits = is_array($hits) ? array_filter($hits, function ($t) use ($now) { return is_int($t) && $t > $now - 3600; }) : [];
    }
    if (count($hits) >= $limit) return true;
    $hits[] = $now;
    @file_put_contents($file, json_encode(array_values($hits)), LOCK_EX);
    return false;
}

/*
 * Schlanker SMTP-Versand (AUTH LOGIN, SSL auf 465 oder STARTTLS auf 587), damit keine Bibliothek nötig ist.
 * Wirft eine Exception mit der Serverantwort, wenn etwas schiefgeht.
 */
function smtp_send(array $c, array $to, string $data): void {
    $host = (string)$c['smtp_host'];
    $port = (int)$c['smtp_port'];
    $ssl  = strtolower((string)$c['smtp_verschluesselung']) === 'ssl';
    $ctx  = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);

    $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Verbindung zu {$host}:{$port} fehlgeschlagen: {$errstr}");
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 515)) !== false) {
            $out .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return $out;
    };
    // $step steht im Fehlerlog statt des Befehls selbst, damit dort nie Zugangsdaten auftauchen
    $cmd = function (string $step, ?string $line, int ...$ok) use ($fp, $read): string {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $res = $read();
        if (!in_array((int)substr($res, 0, 3), $ok, true)) throw new RuntimeException("SMTP {$step}: " . trim($res));
        return $res;
    };

    try {
        $helo = $_SERVER['SERVER_NAME'] ?? 'meine-futterkiste.de';
        $cmd('Begrüßung', null, 220);
        $cmd('EHLO', 'EHLO ' . $helo, 250);
        if (!$ssl) {
            $cmd('STARTTLS', 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS fehlgeschlagen');
            $cmd('EHLO', 'EHLO ' . $helo, 250);
        }
        $cmd('AUTH', 'AUTH LOGIN', 334);
        $cmd('Benutzer', base64_encode((string)$c['smtp_benutzer']), 334);
        $cmd('Passwort', base64_encode((string)$c['smtp_passwort']), 235);
        $cmd('MAIL FROM', 'MAIL FROM:<' . $c['absender_mail'] . '>', 250);
        foreach ($to as $rcpt) $cmd('RCPT TO', 'RCPT TO:<' . $rcpt . '>', 250, 251);
        $cmd('DATA', 'DATA', 354);
        // Punkte am Zeilenanfang verdoppeln (SMTP-Regel), dann mit "." abschließen
        $cmd('Nachricht', preg_replace('/^\./m', '..', $data) . "\r\n.", 250);
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/* ---------- Anfrage prüfen ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: index.html#kontakt', true, 303);
    exit;
}

$config = require __DIR__ . '/config.php';

// Spamschutz: das versteckte Feld füllen nur Bots aus. Still „erfolgreich“ antworten.
if (trim((string)($_POST['website'] ?? '')) !== '') reply(true);

$name     = line('name', 100);
$kontakt  = line('kontakt', 150);
$hund     = line('hund', 100);
$anliegen = line('anliegen', 40);
if (!in_array($anliegen, ANLIEGEN, true)) $anliegen = 'Sonstiges';

$nachricht = (string)($_POST['nachricht'] ?? '');
$nachricht = str_replace(["\r\n", "\r"], "\n", $nachricht);
$nachricht = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $nachricht) ?? '';
$nachricht = mb_substr(trim($nachricht), 0, 5000);

if ($name === '' || $kontakt === '' || $nachricht === '') {
    reply(false, 'Bitte füllen Sie Name, Telefon oder E-Mail und Ihre Nachricht aus.', 422);
}
if (!mb_check_encoding($name . $kontakt . $hund . $nachricht, 'UTF-8')) {
    reply(false, 'Die Eingabe enthält ungültige Zeichen.', 422);
}
// Viele Links in der Nachricht sind fast immer Spam
if (preg_match_all('~https?://~i', $nachricht) > (int)($config['max_links'] ?? 3)) {
    reply(false, 'Bitte senden Sie die Nachricht ohne Links.', 422);
}
if (rate_limited((int)($config['limit_pro_stunde'] ?? 5))) {
    reply(false, 'Sie haben uns gerade schon mehrfach geschrieben. Bitte versuchen Sie es später noch einmal oder rufen Sie uns an.', 429);
}

/* ---------- Mail zusammenbauen ---------- */

$to = array_values(array_filter(array_map('trim', explode(',', (string)$config['empfaenger'])), function ($a) {
    return (bool)filter_var($a, FILTER_VALIDATE_EMAIL);
}));
$from     = (string)$config['absender_mail'];
$fromName = (string)($config['absender_name'] ?? 'Website');
if (!$to || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
    error_log('kontakt.php: Empfänger oder Absender in config.php ungültig');
    reply(false, '', 500);
}

$email = filter_var($kontakt, FILTER_VALIDATE_EMAIL) ? $kontakt : '';
$now   = new DateTime('now', new DateTimeZone('Europe/Berlin'));

$subject = ($config['betreff_prefix'] ?? 'Website-Anfrage') . ': ' . $anliegen . ' von ' . $name;
$body = "Neue Anfrage über das Kontaktformular auf meine-futterkiste.de\n"
      . str_repeat('-', 56) . "\n\n"
      . "Anliegen:   {$anliegen}\n"
      . "Name:       {$name}\n"
      . "Kontakt:    {$kontakt}\n"
      . "Hund:       " . ($hund !== '' ? $hund : '-') . "\n\n"
      . "Nachricht:\n{$nachricht}\n\n"
      . str_repeat('-', 56) . "\n"
      . 'Gesendet am ' . $now->format('d.m.Y \u\m H:i') . " Uhr\n"
      . ($email !== '' ? "Auf diese E-Mail können Sie direkt antworten.\n" : "Bitte telefonisch zurückmelden.\n");
// Base64 statt 8bit: übersteht jeden Mailserver unverändert, Umlaute inklusive
$bodyEncoded = rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
$subjectEncoded = mb_encode_mimeheader($subject, 'UTF-8', 'B');

$headers = [
    'From: ' . header_addr($fromName, $from),
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
    'X-Mailer: meine-futterkiste.de',
];
if ($email !== '') $headers[] = 'Reply-To: ' . header_addr($name, $email);

/* ---------- Senden: erst SMTP, bei Fehler oder ohne Passwort mail() ---------- */

$sent = false;
if (trim((string)($config['smtp_host'] ?? '')) !== '' && (string)($config['smtp_passwort'] ?? '') !== '') {
    $domain = substr(strrchr($from, '@') ?: '@meine-futterkiste.de', 1);
    $data = implode("\r\n", array_merge([
        'Date: ' . $now->format(DATE_RFC2822),
        'To: ' . implode(', ', $to),
        'Subject: ' . $subjectEncoded,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
    ], $headers)) . "\r\n\r\n" . $bodyEncoded;
    try {
        smtp_send($config, $to, $data);
        $sent = true;
    } catch (Throwable $e) {
        error_log('kontakt.php SMTP: ' . $e->getMessage());
    }
}
if (!$sent) {
    $sent = mail(implode(', ', $to), $subjectEncoded, $bodyEncoded, implode("\r\n", $headers), '-f' . $from);
    if (!$sent) error_log('kontakt.php: mail() fehlgeschlagen');
}

$sent ? reply(true) : reply(false, '', 500);
