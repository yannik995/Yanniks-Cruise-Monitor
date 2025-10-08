<?php
/**
 * Yanniks CruiseMonitor — config.php
 *
 * Passe hier Deine Einstellungen an. Alles ist optional:
 * Die Hauptdatei setzt sinnvolle Defaults, wenn ein Wert fehlt.
 */

return [

    // Cache-Verzeichnis (absoluter Pfad empfohlen)
    'CACHE_DIR'       => __DIR__ . '/cache',

    // HTTP-Timeout (Sekunden) für API-Aufrufe
    'TIMEOUT_SECONDS' => 25,

    // Wie viele Ergebnisse pro Seite bei der List-API abgefragt werden
    'SIZE_PER_PAGE'   => 1000,

    // User-Agent für die Requests (Browser-ähnlich halten)
    'USER_AGENT'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36',

    // Währungssymbol in der UI
    'CURRENCY'        => '€',

    // Wie lange Reisen als "neu" (🆕) markiert werden (in Tagen)
    'NEW_BADGE_DAYS'  => 3,

    // Telegram-Bot (optional, sonst keine Benachrichtigungen)
    'TELEGRAM_ENABLED' => false,
    'TELEGRAM_BOT_TOKEN' => '',
    'TELEGRAM_CHAT_ID'   => '',

    // 🔔 Benachrichtigungen:
    // Standard: stumm (silent). Wenn pnp (€/N/Person) <= THRESHOLD, dann NICHT stumm.
    // Beispiel: 70 => Alles <= 70 €/N/Person pingt „laut“.
    'TG_PNP_ALERT_THRESHOLD' => 0,   // 0 oder leer => immer stumm
    'TG_DEFAULT_SILENT'      => true // Grundverhalten: stumm senden?
];
