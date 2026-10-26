<?php
/**
 * ---------------------------------------------------------------------------
 * Einstellungen für das Kontaktformular (kontakt.php) von meine-futterkiste.de
 * ---------------------------------------------------------------------------
 * Beim Livegang muss NUR diese Datei angepasst werden.
 * Sie gibt ein Array zurück und erzeugt selbst keine Ausgabe, ein direkter
 * Aufruf im Browser zeigt daher eine leere Seite (die .htaccess sperrt sie
 * zusätzlich).
 *
 * ACHTUNG: Steht unten ein SMTP-Passwort drin, diese Datei NICHT in ein
 * öffentliches GitHub-Repo hochladen, sondern direkt per SFTP/Webspace-
 * Explorer bei STRATO ablegen.
 */

return array(

    /* -----------------------------------------------------------------
     * Empfänger: hier landen die Anfragen aus dem Formular.
     * Mehrere Adressen mit Komma trennen:
     *   'empfaenger' => 'bestellungen@meine-futterkiste.de, info@meine-futterkiste.de',
     * ----------------------------------------------------------------- */
    'empfaenger' => 'info@meine-futterkiste.de',

    /* -----------------------------------------------------------------
     * Absender der E-Mail.
     *
     * Muss eine Adresse der EIGENEN Domain sein, die bei STRATO als
     * Postfach existiert. Die Adresse des Besuchers steht als "Reply-To"
     * in der Mail, "Antworten" schreibt also direkt an den Kunden.
     * ----------------------------------------------------------------- */
    'absender_mail' => 'bestellungen@meine-futterkiste.de',
    'absender_name' => 'Website Meine Futterkiste',

    /* Vorangestellter Text in der Betreffzeile */
    'betreff_prefix' => 'Website-Anfrage',

    /* -----------------------------------------------------------------
     * Spamschutz
     * limit_pro_stunde: wie viele Anfragen ein Besucher pro Stunde senden darf
     * max_links:        mehr Links in der Nachricht gelten als Spam
     * ----------------------------------------------------------------- */
    'limit_pro_stunde' => 5,
    'max_links'        => 3,

    /* -----------------------------------------------------------------
     * SMTP-Versand über das STRATO-Postfach (empfohlen)
     * -----------------------------------------------------------------
     * PHPs mail() funktioniert bei STRATO, landet aber eher im Spam.
     * Mit SMTP verschickt das Formular wie ein echtes Mailprogramm über
     * das Postfach unten.
     *
     * Solange 'smtp_passwort' leer ist, nutzt kontakt.php automatisch
     * mail(). Schlägt SMTP fehl (z. B. Passwort geändert), versucht es
     * ebenfalls noch mail(), damit keine Anfrage verloren geht.
     *
     * STRATO-Werte (Kundenlogin > E-Mail > Postfach-Einstellungen):
     *   Host:          smtp.strato.de
     *   Port:          465 (SSL/TLS)  oder  587 (STARTTLS)
     *   Benutzername:  die volle Postfach-Adresse
     *   Passwort:      das Postfach-Passwort
     *
     * 'smtp_benutzer' und 'absender_mail' sollten dieselbe Adresse sein,
     * sonst lehnt STRATO den Versand ab.
     * ----------------------------------------------------------------- */
    'smtp_host'             => 'smtp.strato.de',
    'smtp_port'             => 465,
    'smtp_verschluesselung' => 'ssl',               // 'ssl' (Port 465) oder 'tls' (Port 587)
    'smtp_benutzer'         => 'bestellungen@meine-futterkiste.de',
    'smtp_passwort'         => '',                  // Postfach-Passwort hier eintragen

    /* -----------------------------------------------------------------
     * Öffnungszeiten aus Google (hours.php)
     * -----------------------------------------------------------------
     * Schlüssel aus der Google Cloud Console (APIs & Dienste >
     * Anmeldedaten), dort beschränkt auf "Places API (New)".
     * Solange er leer ist, zeigt die Seite die festen Zeiten aus
     * index.html.
     *
     * google_place_id darf leer bleiben: hours.php sucht den Laden dann
     * einmalig über google_suche und merkt sich die ID. Wer sie kennt
     * (beginnt meist mit "ChIJ"), trägt sie direkt ein.
     * ----------------------------------------------------------------- */
    'google_api_key'  => '',
    'google_place_id' => '',
    'google_suche'    => 'Meine Futterkiste, Obere Seewiesen 56, 71711 Steinheim',

);
