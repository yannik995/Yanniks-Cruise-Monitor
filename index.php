<?php
/*******************************************************
 * Yanniks CruiseMonitor – mit Kabinen-Historie & Telegram
 * WEB:
 *   - Anzeige + Filter (adults/ship/from/to, min/max Tage, Mindest-Kabine, max €/Nacht/Person, "nur neue")
 *   - Historien-Graph (SVG) pro Reise, Linien je Kabinentyp
 * CLI:
 *   - php index.php --update --adults=1 [--verbose]
 *   - Lädt Liste (size=1000) + Kabinen-Details pro Reise (bei neu/Preisänderung oder 1x täglich)
 *   - Schreibt Preis-Historie pro Kabinentyp
 *   - Telegram: neue Reisen & Preissenkungen (mit Kabinentypen alt/neu)
 *******************************************************/
declare(strict_types=1);

/* ======================= Konfiguration laden ======================= */
$CFG = [];
$cfgFile = __DIR__ . '/config.php';
if (is_file($cfgFile)) {
    $loaded = require $cfgFile;
    if (is_array($loaded)) { $CFG = $loaded; }
}
function cfg(string $key, $default = null) {
    global $CFG;
    return $CFG[$key] ?? $default;
}

/* =================== Konstanten aus Konfig ableiten =================== */
const LIST_BASE   = 'https://aida.de/content/aida-search-and-booking/requests/search.cruise.v1.json';
const DETAIL_BASE = 'https://aida.de/content/aida-search-and-booking/requests/detail.content.json';

define('CACHE_DIR',       cfg('CACHE_DIR',       __DIR__ . '/cache'));
define('TIMEOUT_SECONDS', (int)cfg('TIMEOUT_SECONDS', 25));
define('SIZE_PER_PAGE',   (int)cfg('SIZE_PER_PAGE',   1000));
define('USER_AGENT',      (string)cfg('USER_AGENT',    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36'));
define('CURRENCY',        (string)cfg('CURRENCY',      '€'));
define('NEW_BADGE_DAYS',  (int)cfg('NEW_BADGE_DAYS',   3));

if (!is_dir(CACHE_DIR)) { @mkdir(CACHE_DIR, 0775, true); }
$cookieFile = CACHE_DIR . '/aida_cookies.txt';

/* =================== Domain-Logik/Labels/Ranking =================== */
// Reihenfolge: je höher, desto „besser“
const CABIN_RANK = ['I'=>1,'M'=>2,'B'=>3,'V'=>4,'K'=>5,'D'=>6,'P'=>7,'J'=>8,'S'=>9];
function cabinRank(string $code): int { return CABIN_RANK[$code] ?? 0; }

const CABIN_LABELS = [
        'I'=>'Innen','M'=>'Meerblick','B'=>'Balkon','V'=>'Veranda','K'=>'Ver. Komfort',
        'D'=>'Ver. Deluxe','P'=>'Ver. Patio.','J'=>'J.Suite','S'=>'Suite'
];
const TARIFF_KEYS  = ['lig','cla','claAl','ind','indAl','comAl','pau','pauAl','see','seeAl'];

/* ----------------------------- CLI MODE ----------------------------- */
if (php_sapi_name() === 'cli') {
    ini_set('memory_limit', '1024M');
    set_time_limit(0);

    [$cliAdults, $doUpdate, $verbose] = parseCliArgs($argv ?? []);
    if ($doUpdate !== true) {
        fwrite(STDOUT, "Yanniks CruiseMonitor (CLI)\n".
                "Benutzung: php ".basename(__FILE__)." --update --adults=1|2 [--verbose]\n");
        exit(0);
    }
    if ($cliAdults !== 1 && $cliAdults !== 2) { $cliAdults = 1; }

    $cacheFile     = CACHE_DIR . "/aida_adults{$cliAdults}.json";
    $prevCacheFile = CACHE_DIR . "/aida_adults{$cliAdults}_prev.json";
    $histFile      = CACHE_DIR . "/aida_adults{$cliAdults}_history.json"; // Preisverlauf pro JID/Kabinentyp

    try {
        logStatus("Starte Update (adults={$cliAdults}) …");

        // Alt-Cache & Historie laden
        $oldItems = [];
        if (is_file($cacheFile)) {
            [$oldItems, ] = loadCache($cacheFile);
            if (!is_array($oldItems)) $oldItems = [];
        }
        $oldMapByJid = mapByJourney($oldItems);
        $history = loadHistory($histFile); // ['jid'=>['cabins'=>['I'=>[['ts'=>..,'pnp'=>..,'amount'=>..],..], ... ] ]]

        // Live-Liste holen
        warmUpAida($cookieFile);
        $resp = fetchAidaList($cliAdults, 1, SIZE_PER_PAGE, $cookieFile);
        $baseRows = extractVariantsFromList($resp, $cliAdults); // enthält listAmount & flightIncluded
        $total = count($baseRows);
        if ($total === 0) throw new RuntimeException('AIDA API ergab 0 Reisen.');

        // Hilfs-Maps aus der aktuellen Liste
        $currentJids = [];
        $newListAmtByJid = [];
        foreach ($baseRows as $row) {
            $jid = $row['journeyIdentifier'] ?? null;
            if (!$jid) continue;
            $currentJids[$jid] = true;
            $newListAmtByJid[$jid] = array_key_exists('listAmount', $row) ? $row['listAmount'] : null;
        }

        // Daily-Flag
        $daily = shouldDailyRefresh($cliAdults);
        if ($daily) logStatus("Täglicher Detail-Refresh ist fällig (letzter vor mehr als 24 h).");

        // Entscheiden, für welche Journeys die Detail-API nötig ist (Vergleich nur über listAmount des Hauptcaches)
        $needDetail = [];    // jids, die wir neu ziehen
        $reuseOnly  = [];    // jids, die wir aus altem enriched übernehmen können
        $newJids    = [];    // in diesem Lauf neu in der Liste

        foreach ($baseRows as $row) {
            $jid = $row['journeyIdentifier'] ?? null; if (!$jid) continue;

            $oldListAmt = isset($oldMapByJid[$jid]['listAmount']) ? (int)$oldMapByJid[$jid]['listAmount'] : null;
            $newListAmt = isset($newListAmtByJid[$jid]) ? (int)$newListAmtByJid[$jid] : null;

            $isNew      = !isset($oldMapByJid[$jid]);
            $amtChanged = ($isNew || $newListAmt !== $oldListAmt);

            if ($isNew) { $newJids[$jid] = true; }

            if ($daily || $isNew || $amtChanged) {
                $needDetail[] = $jid;
            } else {
                $reuseOnly[]  = $jid;
            }
        }

        logStatus("Reisen gesamt: {$total}. Details nötig: ".count($needDetail).", Reuse: ".count($reuseOnly));

        // Enrichment
        $enrichedMap = []; // jid => enriched row
        foreach ($reuseOnly as $jid) {
            $enrichedMap[$jid] = $oldMapByJid[$jid];
            $enrichedMap[$jid]['listAmount'] = $newListAmtByJid[$jid] ?? ($enrichedMap[$jid]['listAmount'] ?? null);
        }

        // Telegram-Events sammeln
        $tgEvents = []; // ['type'=>'new'|'drop', 'row'=>[], 'delta'=>float, 'old'=>float, 'oldType'=>?, 'newType'=>?]

        // Detail-Calls für geänderte/neue
        $i = 0; $n = count($needDetail);
        foreach ($baseRows as $row) {
            $jid = $row['journeyIdentifier'] ?? null; if (!$jid) continue;

            $isNewInThisRun = isset($newJids[$jid]);

            if (in_array($jid, $needDetail, true)) {
                $i++;
                $title = $row['title'] ?? $jid ?? '–';
                logStatus(sprintf("[%d/%d] Journey %s — %s (Details abrufen)", $i, $n, $jid, $title));

                $detail = fetchCabinDetail($jid, $cliAdults, $cookieFile);
                if (!$detail) {
                    logStatus("  ! Keine Detail-Daten. Reuse alter Details falls vorhanden.");
                    if (isset($oldMapByJid[$jid])) {
                        $enriched = mergeMeta($oldMapByJid[$jid], $oldMapByJid[$jid], $row);
                    } else {
                        $enriched = $row;
                        $enriched['journeyIdentifier'] = $jid;
                        $enriched['added_at'] = gmdate('c');
                        $enriched['absLink'] = buildFindLink($jid, $cliAdults);
                    }
                } else {
                    [$cheapest, $alts, $lastAPIPriceUpdate] = computeCheapestAndAlternatives($row, $detail, $cliAdults);

                    if ($verbose && $alts) {
                        foreach ($alts as $code => $info) {
                            $lbl = CABIN_LABELS[$code] ?? $code;
                            $pnp = $info['pnp'] !== null ? number_format((float)$info['pnp'], 0, ',', '.') . ' €/N' : '–';
                            $amt = $info['amount'] !== null ? number_format((float)$info['amount'], 0, ',', '.') . ' €' : '–';
                            logStatus("    - {$lbl} ({$code}): {$pnp} | {$amt}");
                        }
                    }

                    $enriched = $row;
                    $enriched['journeyIdentifier'] = $jid;
                    $enriched['absLink'] = buildFindLink($jid, $cliAdults);
                    $enriched['cheapest'] = $cheapest;
                    $enriched['alternatives'] = $alts;
                    $enriched['lastAPIPriceUpdate'] = $lastAPIPriceUpdate;
                    if ($cheapest) {
                        $enriched['amount'] = $cheapest['amount'];
                        $enriched['amountPerNightPerAdult'] = $cheapest['pnp'];
                        $enriched['cheapest_code'] = $cheapest['code'] ?? null;
                    } else {
                        unset($enriched['amount'], $enriched['amountPerNightPerAdult'], $enriched['cheapest_code']);
                    }
                }

                // listAmount aktualisieren
                $enriched['listAmount'] = $newListAmtByJid[$jid] ?? ($enriched['listAmount'] ?? null);

                // added_at setzen/mitnehmen
                if ($isNewInThisRun) {
                    $enriched['added_at'] = gmdate('c');
                } elseif (isset($oldMapByJid[$jid]['added_at'])) {
                    $enriched['added_at'] = $oldMapByJid[$jid]['added_at'];
                }

                // Telegram: „neu“
                if ($isNewInThisRun) {
                    $tgEvents[] = ['type'=>'new', 'row'=>$enriched];
                }

                // Telegram: Preissenkung – mit Kabinentyp alt/neu
                if (!$isNewInThisRun && isset($enriched['amount']) && isset($oldMapByJid[$jid]['amount'])) {
                    $oldAmt = (float)$oldMapByJid[$jid]['amount'];
                    $newAmt = (float)$enriched['amount'];
                    if ($newAmt < $oldAmt - 0.001) {
                        $tgEvents[] = [
                                'type'=>'drop', 'row'=>$enriched,
                                'delta'=>$newAmt-$oldAmt, 'old'=>$oldAmt,
                                'oldType'=>$oldMapByJid[$jid]['cheapest']['code'] ?? ($oldMapByJid[$jid]['cheapest_code'] ?? null),
                                'newType'=>$enriched['cheapest']['code'] ?? ($enriched['cheapest_code'] ?? null),
                        ];
                    }
                }

                // Historie fortschreiben (pro Kabinentyp)
                $history = upsertHistoryFromAlternatives($history, $jid, $enriched['alternatives'] ?? [], (int)($enriched['duration'] ?? 0), (int)$cliAdults);

                $enrichedMap[$jid] = mergeMeta($enrichedMap[$jid] ?? null, $enriched, $row);
            } else {
                // Reuse
                $base = $enrichedMap[$jid] ?? ($oldMapByJid[$jid] ?? []);
                $enrichedMap[$jid] = mergeMeta($base, $base, $row);
                $enrichedMap[$jid]['listAmount'] = $newListAmtByJid[$jid] ?? ($enrichedMap[$jid]['listAmount'] ?? null);
                if (isset($oldMapByJid[$jid]['added_at'])) {
                    $enrichedMap[$jid]['added_at'] = $oldMapByJid[$jid]['added_at'];
                }
            }
        }

        // Entfernte Journeys raus
        foreach ($enrichedMap as $jid => $_row) {
            if (!isset($currentJids[$jid])) unset($enrichedMap[$jid]);
        }

        // prev-Cache speichern nur bei Preisänderung
        $enriched = array_values($enrichedMap);
        $changed = false;
        foreach ($enriched as $it) {
            $jid = $it['journeyIdentifier'] ?? null;
            if ($jid && isset($oldMapByJid[$jid])) {
                $oldAmt = (float)($oldMapByJid[$jid]['amount'] ?? -1);
                $newAmt = (float)($it['amount'] ?? -2);
                if (abs($oldAmt - $newAmt) > 0.001) { $changed = true; break; }
            }
        }
        if ($changed && is_file($cacheFile)) {
            @copy($cacheFile, $prevCacheFile);
            logStatus("prev-Cache aktualisiert (Preisänderung erkannt).");
        } else {
            logStatus("prev-Cache unverändert (keine Preisänderung).");
        }

        // Hauptcache & Historie & Daily-Marker
        saveCache($cacheFile, $enriched);
        saveHistory($histFile, $history);
        if ($daily) setDailyRefreshed($cliAdults);

        // Telegram senden (falls konfiguriert) – optional max PNP Filter
        $sent = 0;
        foreach ($tgEvents as $ev) {
            if (!cfg('TELEGRAM_ENABLED', false)) continue;

            // Benachrichtigungs-Filter: max PNP
            $notifyMaxPnp = (float) cfg('TG_NOTIFY_MAX_PNP', 0);
            $row = $ev['row'] ?? [];
            $pnp = $row['amountPerNightPerAdult'] ?? null;
            if ($notifyMaxPnp > 0 && $pnp !== null && (float)$pnp > $notifyMaxPnp) {
                if ($verbose) logStatus("  - Skip Telegram (pnp ".(float)$pnp." > {$notifyMaxPnp})");
                continue;
            }

            $msg    = formatTgMessage($ev, $cliAdults);
            if(tgShouldNotify($ev, $cliAdults)){
                $silent = tgShouldBeSilent($ev, $cliAdults);
                if (tgSend($msg, $silent)) $sent++;
            }
            usleep(200000);
        }
        if ($sent>0) logStatus("Telegram: {$sent} Benachrichtigungen gesendet.");

        logStatus("Fertig. Cache gespeichert: ".basename($cacheFile)." (".count($enriched)." Reisen).");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "[FEHLER] ".$e->getMessage()."\n");
        exit(1);
    }
}


/* ----------------------------- WEB MODE ----------------------------- */
/* WICHTIG: Update-Parameter im Web werden ignoriert! */
$adults   = isset($_GET['adults']) ? max(1, min(2, (int)$_GET['adults'])) : 1;

// Schiffe (Checkbox-Dropdown Auswahl)
$shipFilt = '';
if (!empty($_GET['ships']) && is_array($_GET['ships'])) {
    $shipFilt = implode(',', array_filter(array_map('trim', $_GET['ships'])));
} elseif (!empty($_GET['ship'])) {
    $shipFilt = trim((string)$_GET['ship']);
}
$selectedShips = array_filter(array_map('trim', explode(',', $shipFilt)));

$fromFilt = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$toFilt   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

$minNights = isset($_GET['minDays']) ? max(0, (int)$_GET['minDays']) : 0;
$maxNights = isset($_GET['maxDays']) ? max(0, (int)$_GET['maxDays']) : 0;

// NEU: max €/Nacht/Person (Web)
$maxPnp    = isset($_GET['maxPnp']) ? max(0, (int)$_GET['maxPnp']) : 0;

$newOnly  = isset($_GET['newOnly']) ? (int)$_GET['newOnly'] === 1 : false;
$newDaysQ = isset($_GET['newDays']) ? max(0, (int)$_GET['newDays']) : 0; // 0 = Standard

// Mindest-Kabine: '', 'I','M','B','V','K','D','J','S','P'
$minCabin  = isset($_GET['minCabin']) ? strtoupper(trim((string)$_GET['minCabin'])) : '';
if ($minCabin && !isset(CABIN_RANK[$minCabin])) $minCabin = '';

$cacheFile     = CACHE_DIR . "/aida_adults{$adults}.json";
$prevCacheFile = CACHE_DIR . "/aida_adults{$adults}_prev.json";
$histFile      = CACHE_DIR . "/aida_adults{$adults}_history.json";

[$items, $meta] = loadCache($cacheFile);
$rows = is_array($items) ? $items : [];
$prevMap = loadPrevMap($prevCacheFile);
$history = loadHistory($histFile);

// Filter anwenden
$rows = applyFilters($rows, $shipFilt, $fromFilt, $toFilt, $minNights, $maxNights, $newOnly, $newDaysQ, $maxPnp);

// Deltas (günstigste Option)
$changes=[];
foreach ($rows as $r) {
    $jid=$r['journeyIdentifier']??null;
    if (!$jid || !isset($prevMap[$jid])) continue;
    $prev=$prevMap[$jid];

    $dTotal=null; $pTotal=null;
    if (isset($r['amount'],$prev['amount'])) {
        $dTotal=(float)$r['amount'] - (float)$prev['amount'];
        $pTotal=(float)$prev['amount'];
    }
    $dPnp=null; $pPnp=null;
    if (isset($r['amountPerNightPerAdult'],$prev['amountPerNightPerAdult'])) {
        $dPnp=(float)$r['amountPerNightPerAdult'] - (float)$prev['amountPerNightPerAdult'];
        $pPnp=(float)$prev['amountPerNightPerAdult'];
    }
    $when = $r['lastAPIPriceUpdate'] ?? ($prevMap['_meta_updated_at'] ?? null);

    $changes[$jid]=[
            'deltaTotal'=>$dTotal,'prevTotal'=>$pTotal,
            'deltaNightPerAdult'=>$dPnp,'prevNightPerAdult'=>$pPnp,
            'changeDate'=>$when,
    ];
}

// Sortieren
usort($rows, fn($a,$b)=>($a['amountPerNightPerAdult']??INF)<=>($b['amountPerNightPerAdult']??INF));

// Render
renderList($adults, $rows, $changes, $meta, $shipFilt, $fromFilt, $toFilt, $minNights, $maxNights, $minCabin, $newOnly, $newDaysQ, $selectedShips, $maxPnp, $history);

/* ========================== FUNKTIONEN ========================== */

function parseCliArgs(array $argv): array {
    $adults = 1; $update = false; $verbose=false;
    foreach ($argv as $arg) {
        if ($arg === '--update') $update = true;
        elseif ($arg === '--verbose') $verbose = true;
        elseif (str_starts_with($arg, '--adults=')) {
            $adults = (int)substr($arg, 9);
        }
    }
    return [$adults, $update, $verbose];
}

function logStatus(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    fwrite(STDOUT, "[$ts] $msg\n");
}

function warmUpAida(string $cookieFile): void {
    $ch = curl_init('https://aida.de/');
    curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
            ],
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function fetchAidaList(int $adults, int $page, int $size, string $cookieFile): array {
    $url = LIST_BASE
            . "/size={$size}"
            . "/p={$page}"
            . "/sortCriteria=DepartureDate"
            . "/sortDirection=Asc"
            . "/pax[adults]={$adults}"
            . "/pax[juveniles]=0"
            . "/pax[children]=0"
            . "/pax[babies]=0.json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => TIMEOUT_SECONDS,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_HTTPHEADER     => [
                    'Accept: application/json, text/javascript, */*; q=0.01',
                    'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
                    'Referer: https://aida.de/',
                    'Origin: https://aida.de',
                    'Sec-Fetch-Site: same-origin',
                    'Sec-Fetch-Mode: cors',
                    'Sec-Fetch-Dest: empty',
            ],
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
    ]);
    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0)  throw new RuntimeException('cURL Fehler: '.$errno);
    if ($status < 200 || $status >= 300) throw new RuntimeException("HTTP $status\n".$body);

    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException('Ungültiges JSON');
    return $data;
}

function fetchCabinDetail(string $journeyIdentifier, int $adults, string $cookieFile): ?array {
    $url = DETAIL_BASE
            . "/language=de"
            . "/adults={$adults}"
            . "/juveniles=0"
            . "/children=0"
            . "/babies=0"
            . "/TariffType=CLA"
            . "/JourneyIdentifier=".rawurlencode($journeyIdentifier).".json";

    $detailFile = CACHE_DIR . "/detail_{$adults}_{$journeyIdentifier}.json";
    // Cache 180 Sekunden gültig
    if (is_file($detailFile) && (time() - filemtime($detailFile) < 180)) {
        $raw = file_get_contents($detailFile);
        $js  = json_decode((string)$raw, true);
        if (is_array($js)) return $js;
    }

    warmUpAida($cookieFile);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => TIMEOUT_SECONDS,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_HTTPHEADER     => [
                    'Accept: application/json, text/javascript, */*; q=0.01',
                    'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
                    'Referer: https://aida.de/',
                    'Origin: https://aida.de',
                    'Sec-Fetch-Site: same-origin',
                    'Sec-Fetch-Mode: cors',
                    'Sec-Fetch-Dest: empty',
            ],
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
    ]);
    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $status < 200 || $status >= 300) return null;

    $data = json_decode((string)$body, true);
    if (is_array($data)) {
        file_put_contents($detailFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $data;
    }
    return null;
}

function extractVariantsFromList(array $resp, int $adults): array {
    $items = $resp['cruiseItems'] ?? [];
    $out = [];
    foreach ($items as $ci) {
        $title      = $ci['title'] ?? null;
        $duration   = (int)($ci['duration'] ?? 0);
        $routeCode  = $ci['routeCode'] ?? null;
        $routeGroup = $ci['routeGroupCode'] ?? null;

        foreach (($ci['cruiseItemVariant'] ?? []) as $v) {
            $jid      = $v['journeyIdentifier'] ?? null;
            $shipName = $v['ship']['marketingName'] ?? ($v['ship']['name'] ?? null);
            $start    = $v['startDate'] ?? null;
            $end      = $v['endDate'] ?? null;
            $flightIncluded = (bool)($v['flightIncluded'] ?? false);
            $listAmount = isset($v['amount']) ? (float)$v['amount'] : null;

            $lastAPIPriceUpdate = null;
            if (!empty($v['campaigns'])) {
                foreach ($v['campaigns'] as $camp) {
                    $cd = $camp['validity']['currentDate'] ?? null;
                    if ($cd) { $lastAPIPriceUpdate = $cd; break; }
                }
            }

            $out[] = [
                    'journeyIdentifier'  => $jid,
                    'title'              => $title,
                    'routeCode'          => $routeCode,
                    'routeGroupCode'     => $routeGroup,
                    'shipName'           => $shipName,
                    'duration'           => $duration,
                    'startDate'          => $start,
                    'endDate'            => $end,
                    'adults'             => $adults,
                    'lastAPIPriceUpdate' => $lastAPIPriceUpdate,
                    'listAmount'         => $listAmount,
                    'flightIncluded'     => $flightIncluded,
            ];
        }
    }
    $seen=[]; $unique=[];
    foreach ($out as $r) {
        $jid=$r['journeyIdentifier']??null;
        if ($jid && !isset($seen[$jid])) { $seen[$jid]=true; $unique[]=$r; }
    }
    return $unique;
}

function computeCheapestAndAlternatives(array $baseRow, ?array $detail, int $adults): array {
    $duration = (int)($baseRow['duration'] ?? 0);
    $lastAPIPriceUpdate = $baseRow['lastAPIPriceUpdate'] ?? null;
    $alts = [];

    if ($detail && !empty($detail['cabinItemsVariant'])) {
        if (!$lastAPIPriceUpdate) {
            foreach ($detail['cabinItemsVariant'] as $c) {
                foreach (TARIFF_KEYS as $k) {
                    if (!empty($c[$k]['campaigns'][0]['validity']['currentDate'])) {
                        $lastAPIPriceUpdate = $c[$k]['campaigns'][0]['validity']['currentDate'];
                        break 2;
                    }
                }
            }
        }

        foreach ($detail['cabinItemsVariant'] as $c) {
            $code = trim((string)($c['cabinCode'] ?? ''));
            $name = trim((string)($c['cabinName'] ?? ($code ?: 'Kabine')));
            $bestAmt = null; $bestLink = null;

            foreach (TARIFF_KEYS as $tKey) {
                $t = $c[$tKey] ?? null;
                if (!$t || (!isset($t['amount']) && !isset($t['amountPerPerson']))) continue;
                $amt = isset($t['amount']) ? (float)$t['amount'] :
                        (isset($t['amountPerPerson']) ? (float)$t['amountPerPerson'] * max(1,$adults) : null);
                if ($amt === null) continue;
                if ($bestAmt === null || $amt < $bestAmt) {
                    $bestAmt  = $amt;
                    $bestLink = (!empty($t['bookingLink']) && $t['bookingLink'] !== 'null') ? ('https://aida.de'.$t['bookingLink']) : null;
                }
            }

            if ($bestAmt !== null) {
                $pnp = ($duration > 0 && $adults > 0) ? $bestAmt / $duration / $adults : null;
                $alts[$code] = [
                        'name'        => ($name ?: ($code ?: 'Kabine')),
                        'amount'      => $bestAmt,
                        'pnp'         => $pnp,
                        'bookingLink' => $bestLink,
                ];
            }
        }
    }

    $cheapest = null; $cheapestKey = null;
    foreach ($alts as $code => $info) {
        if ($cheapest === null) { $cheapest = $info; $cheapestKey = $code; continue; }
        $a = $info['pnp'] ?? INF; $b = $cheapest['pnp'] ?? INF;
        if ($a < $b) { $cheapest = $info; $cheapestKey = $code; }
    }
    if ($cheapest) {
        $cheapest['code'] = $cheapestKey;
        $cheapest['name'] = ($alts[$cheapestKey]['name'] ?? (CABIN_LABELS[$cheapestKey] ?? $cheapestKey));
    }

    return [$cheapest, $alts, $lastAPIPriceUpdate];
}

function buildFindLink(string $jid, int $adults): string {
    return "https://aida.de/finden/{$jid}/CLASSIC?pax[adults]={$adults}&pax[juveniles]=0&pax[children]=0&pax[babies]=0";
}

function saveCache(string $file, array $items): void {
    $payload = ['updated_at'=>gmdate('c'),'count'=>count($items),'items'=>$items];
    $tmp = $file.'.tmp';
    file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @rename($tmp, $file);
}

function loadCache(string $file): array {
    if (!is_file($file)) return [null, []];
    $raw = file_get_contents($file);
    $json = json_decode((string)$raw, true);
    if (!is_array($json) || !isset($json['items'])) return [null, []];
    $meta = ['updated_at'=>$json['updated_at'] ?? null, 'count'=>$json['count'] ?? count($json['items'])];
    return [$json['items'], $meta];
}

function loadPrevMap(string $prev): array {
    if (!is_file($prev)) return [];
    $raw = file_get_contents($prev);
    $json = json_decode((string)$raw, true);
    if (!is_array($json) || !isset($json['items']) || !is_array($json['items'])) return [];
    $map=[];
    foreach ($json['items'] as $r) {
        $jid = $r['journeyIdentifier'] ?? null;
        if ($jid) $map[$jid]=$r;
    }
    $map['_meta_updated_at'] = $json['updated_at'] ?? null;
    return $map;
}

/* ============ Preis-Historie (pro Reise & Kabinentyp) ============ */

function loadHistory(string $file): array {
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    $js  = json_decode((string)$raw, true);
    return is_array($js) ? $js : [];
}

function saveHistory(string $file, array $history): void {
    $tmp = $file.'.tmp';
    file_put_contents($tmp, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @rename($tmp, $file);
}

/**
 * Ergänzt/aktualisiert Historie auf Basis der derzeitigen Alternativen.
 * Struktur:
 *   history[jid]['cabins'][code] = [
 *      ['ts'=>'2025-01-01T12:00:00Z','pnp'=>123.45,'amount'=>999],
 *      ...
 *   ]
 */
function upsertHistoryFromAlternatives(array $history, string $jid, array $alts, int $duration, int $adults): array {
    if (!$jid) return $history;
    $now = gmdate('c');
    if (!isset($history[$jid])) $history[$jid] = ['cabins'=>[]];

    foreach ($alts as $code => $info) {
        if (!isset($history[$jid]['cabins'][$code])) $history[$jid]['cabins'][$code] = [];
        $series = &$history[$jid]['cabins'][$code];

        $pnp = $info['pnp'] ?? null;
        $amt = $info['amount'] ?? null;
        // Nur schreiben, wenn realer Preis existiert
        if ($pnp === null && $amt !== null && $duration > 0 && $adults > 0) {
            $pnp = $amt / $duration / $adults;
        }
        if ($pnp === null) continue;

        $last = end($series);
        if (!$last || abs((float)$last['pnp'] - (float)$pnp) > 0.001) {
            $series[] = ['ts'=>$now, 'pnp'=>(float)$pnp, 'amount'=> $amt !== null ? (float)$amt : null];
            if (count($series) > 400) { // begrenzen
                $series = array_slice($series, -400);
            }
        }
        unset($series); // break ref
    }
    return $history;
}

/* -------- Metafelder mergen -------- */
// Detail-Felder (cheapest/alternatives/amount/amountPerNightPerAdult/lastAPIPriceUpdate/cheapest_code) erhalten.
function mergeMeta(?array $existing, array $enrichedOrOld, array $freshBase): array {
    $dst = $existing ?? $enrichedOrOld ?? [];

    if (!empty($freshBase['journeyIdentifier'])) {
        $dst['journeyIdentifier'] = $freshBase['journeyIdentifier'];
    } elseif (!empty($dst['journeyIdentifier'])) {
        // ok
    } elseif (!empty($enrichedOrOld['journeyIdentifier'])) {
        $dst['journeyIdentifier'] = $enrichedOrOld['journeyIdentifier'];
    }

    if (!empty($enrichedOrOld['added_at']) && empty($dst['added_at'])) {
        $dst['added_at'] = $enrichedOrOld['added_at'];
    }

    foreach (['title','shipName','startDate','endDate','duration','routeCode','routeGroupCode','adults','flightIncluded'] as $k) {
        if (array_key_exists($k, $freshBase)) $dst[$k] = $freshBase[$k];
    }

    if (empty($dst['absLink']) && !empty($dst['journeyIdentifier']) && !empty($dst['adults'])) {
        $dst['absLink'] = buildFindLink($dst['journeyIdentifier'], (int)$dst['adults']);
    }
    return $dst;
}

/* -------- Daily Marker -------- */
function dailyMarkerFile(int $adults): string { return CACHE_DIR . "/aida_adults{$adults}_daily.txt"; }
function shouldDailyRefresh(int $adults): bool {
    $f = dailyMarkerFile($adults);
    $today = date('Y-m-d');
    $last = is_file($f) ? trim((string)file_get_contents($f)) : '';
    return $last !== $today;
}
function setDailyRefreshed(int $adults): void {
    $f = dailyMarkerFile($adults);
    $today = date('Y-m-d');
    file_put_contents($f, $today);
}

function mapByJourney(array $items): array {
    $map=[];
    foreach ($items as $r) {
        $jid = $r['journeyIdentifier'] ?? null;
        if ($jid) $map[$jid]=$r;
    }
    return $map;
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function filterAlternativesByMinCabin(array $alts, string $minCabin): array {
    if (!$minCabin) return $alts;
    $minRank = cabinRank($minCabin);
    $out = [];
    foreach ($alts as $code => $info) {
        if (cabinRank((string)$code) >= $minRank) $out[$code] = $info;
    }
    return $out;
}

function pickCheapestFromAlts(array $alts): ?array {
    $best = null; $bestKey = null;
    foreach ($alts as $code => $info) {
        $p = $info['pnp'] ?? null;
        if ($p === null) continue;
        if ($best === null || $p < ($best['pnp'] ?? INF)) { $best = $info; $bestKey = $code; }
    }
    if ($best) { $best['code'] = $bestKey; }
    return $best;
}

function applyFilters(
        array $rows,
        string $shipFilt,
        string $from,
        string $to,
        int $minNights,
        int $maxNights,
        bool $newOnly = false,
        int $newDaysOverride = 0,
        int $maxPnp = 0
): array {
    $ships = array_filter(array_map('trim', explode(',', $shipFilt ?: '')));
    $fromT = $from ? strtotime($from.' 00:00:00') : null;
    $toT   = $to   ? strtotime($to.' 23:59:59')   : null;

    $defaultDays = (int) cfg('NEW_BADGE_DAYS', 3);
    $days = $newDaysOverride > 0 ? $newDaysOverride : $defaultDays;
    $newCutoffTs = time() - ($days * 86400);

    return array_values(array_filter($rows, function($r) use ($ships,$fromT,$toT,$minNights,$maxNights,$newOnly,$newCutoffTs,$maxPnp) {
        if ($ships) {
            $s = mb_strtolower((string)($r['shipName'] ?? ''));
            $ok = false;
            foreach ($ships as $needle) {
                if ($needle !== '' && str_contains($s, mb_strtolower($needle))) { $ok = true; break; }
            }
            if (!$ok) return false;
        }
        $start = isset($r['startDate']) ? strtotime($r['startDate']) : null;
        if ($fromT && $start && $start < $fromT) return false;
        if ($toT   && $start && $start > $toT)   return false;

        $dur = (int)($r['duration'] ?? 0);
        if ($minNights && $dur && $dur < $minNights) return false;
        if ($maxNights && $dur && $dur > $maxNights) return false;

        if ($newOnly) {
            $added_at = isset($r['added_at']) ? strtotime($r['added_at']) : null;
            if (!$added_at || $added_at < $newCutoffTs) return false;
        }

        if ($maxPnp > 0 && isset($r['amountPerNightPerAdult'])) {
            if ((float)$r['amountPerNightPerAdult'] > $maxPnp) return false;
        }

        return true;
    }));
}

/* ---------------------------- Rendering (Web) ---------------------------- */

function renderList(
        int $adults,
        array $rows,
        array $changes,
        array $meta,
        string $shipFilt,
        string $fromFilt,
        string $toFilt,
        int $minNights,
        int $maxNights,
        string $minCabin,
        bool $newOnly,
        int $newDaysQ,
        array $selectedShips = [],
        float $maxPnp = 0.0,
        array $history = []
): void {

    $updatedAt=$meta['updated_at'] ?? null;
    $count = count($rows);

    $daysForNew = $newDaysQ > 0 ? $newDaysQ : (int) cfg('NEW_BADGE_DAYS', 3);
    $newCutoffTs = time() - ($daysForNew * 86400);

    $params = array_filter([
            'ship'     => $shipFilt ?: null,
            'from'     => $fromFilt ?: null,
            'to'       => $toFilt ?: null,
            'minDays'  => $minNights ?: null,
            'maxDays'  => $maxNights ?: null,
            'minCabin' => $minCabin ?: null,
            'newOnly'  => $newOnly ? 1 : null,
            'newDays'  => $daysForNew ?: null,
            'maxPnp'   => $maxPnp > 0 ? (int)$maxPnp : null,
    ], static fn($v) => $v !== null && $v !== '');

    $urlA1 = '?' . http_build_query($params + ['adults' => 1]);
    $urlA2 = '?' . http_build_query($params + ['adults' => 2]);

    // Schiff-Menge aus aktuellen Rows für die Checkbox-Liste
    $shipSet=[];
    foreach ($rows as $r) { if (!empty($r['shipName'])) $shipSet[$r['shipName']]=true; }
    ksort($shipSet);
    ?>
    <!doctype html>
    <html lang="de"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Yanniks CruiseMonitor</title>
        <style>
            :root{--bg:#0b1220;--fg:#e7ecf3;--muted:#9aa7b7;--card:#111a2b;--border:#22314a;--accent:#3aa0ff;--down:#2ecc71;--up:#ff4757}
            *{box-sizing:border-box}
            body{margin:0;padding:24px;background:var(--bg);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial}
            header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px;flex-wrap:wrap}
            h1{font-size:20px;margin:0}.small{color:var(--muted);font-size:12px}
            .actions{display:flex;flex-wrap:wrap;gap:8px}
            .actions a{text-decoration:none;color:var(--fg);background:var(--card);border:1px solid var(--border);padding:10px 14px;border-radius:10px;font-size:14px;line-height:1.3}
            .actions a.active{outline:2px solid var(--accent)}.actions a:hover{background:#0e1a2d}
            .filter{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0;align-items:center}
            .filter input,.filter select{background:#0e1a2d;border:1px solid var(--border);color:var(--fg);padding:8px 10px;border-radius:10px}
            .filter a.link, .filter button{background:#0e1a2d;border:1px solid var(--border);color:var(--fg);padding:8px 12px;border-radius:10px;text-decoration:none;cursor:pointer}
            .filter a.link:hover, .filter button:hover{background:#12233d}
            .dropdown {position: relative;display: inline-block}
            .dropbtn {background:#0e1a2d;color:var(--fg);border:1px solid var(--border);padding:8px 12px;border-radius:10px;cursor:pointer}
            .dropdown-content {display:none;position:absolute;background:#0e1a2d;border:1px solid var(--border);border-radius:10px;min-width:220px;padding:6px;box-shadow:0 4px 14px rgba(0,0,0,0.3);z-index:10}
            .dropdown-content label {display:flex;align-items:center;gap:6px;padding:4px 6px;font-size:14px;color:var(--fg)}
            .dropdown-content label:hover {background:#12233d}
            .dropdown:hover .dropdown-content {display:block}
            table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
            thead th{text-align:left;padding:12px;font-size:13px;color:var(--muted);border-bottom:1px solid var(--border)}
            tbody td{padding:12px;border-bottom:1px solid var(--border);vertical-align:top}
            tbody tr:hover{background:#0f1b30}
            .numeric{text-align:right;white-space:nowrap}
            .center{text-align:center}
            .delta{font-size:12px;margin-left:6px}.delta.down{color:var(--down)}.delta.up{color:var(--up)}.delta.zero{color:var(--muted)}
            .badge{font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:#0e1a2d}
            .badge.ok{color:var(--down);border-color:var(--down)}
            .badge.no{color:#f39c12;border-color:#f39c12}
            .new{margin-left:6px}
            a.link{color:var(--accent);text-decoration:none}a.link:hover{text-decoration:underline}
            .alt{color:var(--muted);font-size:12px;margin-top:4px}
            details.chart{margin-top:6px}
            .legend{display:flex;gap:10px;flex-wrap:wrap;margin:6px 0 2px 0;font-size:12px;color:var(--muted)}
            .legend .key{display:inline-flex;align-items:center;gap:6px}
            .legend .swatch{display:inline-block;width:12px;height:12px;border-radius:3px;border:1px solid var(--border)}
            .pnp-max{width:150px}
            @media (max-width:768px){
                header{flex-direction:column;align-items:flex-start}
                h1{font-size:18px}
                .actions{width:100%;justify-content:stretch}
                .actions a{flex:1 1 100%;text-align:center;font-size:15px}
            }
        </style>
    </head>
    <body>
    <header>
        <div>
            <h1>Yanniks CruiseMonitor</h1>
            <div class="small">
                Ansicht: <?php echo $adults===1?'Alleinreisende:r':'2 Personen'; ?>
                · <?php echo $updatedAt ? 'Zuletzt aktualisiert: '.e(date('d.m.Y H:i:s', strtotime($updatedAt))).' UTC' : 'Noch kein Update.'; ?>
                · Reisen: <?php echo (int)$count; ?>
                <?php if ($minCabin) echo ' · Mindest-Kabine: '.e(CABIN_LABELS[$minCabin] ?? $minCabin); ?>
                <?php if ($maxPnp>0) echo ' · max €/N/Person: '.number_format($maxPnp,0,',','.'); ?>
            </div>
        </div>
        <div class="actions">
            <a href="<?php echo e($urlA1); ?>" class="<?php echo $adults===1?'active':''; ?>">1 Erw.</a>
            <a href="<?php echo e($urlA2); ?>" class="<?php echo $adults===2?'active':''; ?>">2 Erw.</a>
        </div>
    </header>

    <form class="filter" method="get">
        <input type="hidden" name="adults" value="<?php echo (int)$adults; ?>">

        <!-- Schiffe Multi-Select (Checkbox-Dropdown) -->
        <div class="dropdown">
            <button type="button" class="dropbtn">Schiffe ▾</button>
            <div class="dropdown-content">
                <?php foreach ($shipSet as $name => $_): ?>
                    <label>
                        <input type="checkbox" name="ships[]" value="<?php echo e($name); ?>"
                                <?php echo in_array($name, $selectedShips, true)?'checked':''; ?>>
                        <span><?php echo e($name); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <input type="date" name="from" value="<?php echo e($fromFilt); ?>">
        <input type="date" name="to"   value="<?php echo e($toFilt); ?>">
        <input type="number" name="minDays" min="0" placeholder="min Tage" style="width:110px" value="<?php echo (int)$minNights; ?>">
        <input type="number" name="maxDays" min="0" placeholder="max Tage" style="width:110px" value="<?php echo (int)$maxNights; ?>">

        <!-- Mindest-Kabine -->
        <select name="minCabin">
            <option value="">ab (alle Kabinen)</option>
            <option value="I" <?php echo $minCabin==='I'?'selected':''; ?>>ab Innen</option>
            <option value="M" <?php echo $minCabin==='M'?'selected':''; ?>>ab Meerblick</option>
            <option value="B" <?php echo $minCabin==='B'?'selected':''; ?>>ab Balkon</option>
            <option value="V" <?php echo $minCabin==='V'?'selected':''; ?>>ab Veranda</option>
            <option value="K" <?php echo $minCabin==='K'?'selected':''; ?>>ab Ver. Komfort</option>
            <option value="D" <?php echo $minCabin==='D'?'selected':''; ?>>ab Ver. Deluxe</option>
            <option value="P" <?php echo $minCabin==='P'?'selected':''; ?>>ab Ver. Patio</option>
            <option value="J" <?php echo $minCabin==='J'?'selected':''; ?>>ab Junior Suite</option>
            <option value="S" <?php echo $minCabin==='S'?'selected':''; ?>>ab Suite</option>
        </select>

        <!-- Neu-Filter -->
        <label style="display:flex;align-items:center;gap:6px">
            <input type="checkbox" name="newOnly" value="1" <?php echo $newOnly ? 'checked' : ''; ?>>
            nur neue
        </label>
        <input type="number" name="newDays" min="1" placeholder="Neu seit (Tage)" style="width:150px" value="<?php echo (int)$daysForNew; ?>">

        <!-- Max Preis/Nacht/Person -->
        <input class="pnp-max" type="number" name="maxPnp" min="0" step="1" placeholder="max €/N/Person"
               value="<?php echo $maxPnp>0 ? (int)$maxPnp : ''; ?>">

        <button type="submit">Filtern</button>
        <a class="link" href="?adults=<?php echo (int)$adults; ?>">Zurücksetzen</a>
    </form>

    <?php if (!$rows): ?>
        <div class="small">Keine Daten (Filter zu streng oder Cache leer). Update bitte per CLI ausführen.</div>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Reise</th>
                <th>Schiff</th>
                <th>Zeitraum</th>
                <th class="numeric">Nächte</th>
                <th class="center">Flug</th>
                <th class="numeric">€/Nacht/Person</th>
                <th class="numeric">Gesamt</th>
                <th>Link</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $jid   = $r['journeyIdentifier'] ?? null;
                $title = $r['title'] ?? '';
                $ship  = $r['shipName'] ?? '';
                $start = $r['startDate'] ?? '';
                $end   = $r['endDate'] ?? '';
                $dur   = (int)($r['duration'] ?? 0);

                $alts  = $r['alternatives'] ?? [];
                $flight = (bool)($r['flightIncluded'] ?? false);

                // Mindest-Kabine anwenden
                $altsFiltered = filterAlternativesByMinCabin($alts, $minCabin);
                $cheFiltered  = pickCheapestFromAlts($altsFiltered);
                if (!$cheFiltered) { continue; }

                // Max pnp Filter (falls in den Query-Parametern angegeben)
                if ($maxPnp > 0 && isset($cheFiltered['pnp']) && $cheFiltered['pnp'] > $maxPnp) { continue; }

                $amount = $cheFiltered['amount'] ?? null;
                $pnp    = $cheFiltered['pnp'] ?? null;

                $fmtTotal = $amount===null ? '-' : number_format((float)$amount, 0, ',', '.').' '.CURRENCY;
                $fmtPnp   = $pnp===null ? '-' : number_format((float)$pnp, 0, ',', '.').' '.CURRENCY;

                // Alternativen-Tooltip
                $altParts = [];
                foreach ($altsFiltered as $code=>$info) {
                    if ($code === ($cheFiltered['code'] ?? null)) continue;
                    $label = CABIN_LABELS[$code] ?? $info['name'] ?? $code;
                    $pp    = $info['pnp'] ?? null;
                    if ($pp !== null) $altParts[] = $label.': '.number_format((float)$pp,0,',','.').' '.CURRENCY.'/N';
                }
                $altText = $altParts ? implode(' | ', $altParts) : 'keine Alternativen';
                $cabinCode = $cheFiltered['code'] ?? '';
                $cabinName = $cheFiltered['name'] ?? (CABIN_LABELS[$cabinCode] ?? ($cabinCode ?: 'Kabine'));

                // Deltas (auf Basis prevCache günstigste Option; ggf. wechselt Typ)
                $deltaNightStr = '';
                if (isset($changes[$jid]) && $changes[$jid]['deltaNightPerAdult'] !== null) {
                    $d = (float)$changes[$jid]['deltaNightPerAdult'];
                    $old = $changes[$jid]['prevNightPerAdult']; $when=$changes[$jid]['changeDate'];
                    $whenStr = $when ? date('d.m.Y H:i', strtotime($when)).' Uhr' : 'Zeitpunkt unbekannt';
                    if (abs($d)>0.001) {
                        $cls=$d<0?'down':'up'; $arrow=$d<0?'▼':'▲';
                        $titleDelta='Vorher: '.number_format((float)$old,0,',','.').' '.CURRENCY.' pro Nacht/Person • geändert: '.$whenStr;
                        $deltaNightStr = sprintf('<span class="delta %s" title="%s">%s %s</span>',$cls,e($titleDelta),$arrow,number_format($d,0,',','.'));
                    } else { $deltaNightStr = '<span class="delta zero" title="keine Änderung">=</span>'; }
                }
                $deltaTotalStr = '';
                if (isset($changes[$jid]) && $changes[$jid]['deltaTotal'] !== null) {
                    $dT = (float)$changes[$jid]['deltaTotal'];
                    $oldT = $changes[$jid]['prevTotal']; $when=$changes[$jid]['changeDate'];
                    $whenStr = $when ? date('d.m.Y H:i', strtotime($when)).' Uhr' : 'Zeitpunkt unbekannt';
                    if (abs($dT)>0.001) {
                        $cls=$dT<0?'down':'up'; $arrow=$dT<0?'▼':'▲';
                        $titleT='Vorher: '.number_format((float)$oldT,0,',','.').' '.CURRENCY.' gesamt • geändert: '.$whenStr;
                        $deltaTotalStr = sprintf('<span class="delta %s" title="%s">%s %s</span>',$cls,e($titleT),$arrow,number_format($dT,0,',','.'));
                    } else { $deltaTotalStr = '<span class="delta zero" title="keine Änderung">=</span>'; }
                }

                // 🆕 Badge (wenn added_at vorhanden & frisch)
                $added_at = $r['added_at'] ?? null;
                $isNew   = $added_at && (strtotime($added_at) >= $newCutoffTs);
                $addedTitle = $added_at ? ('Hinzugefügt am '.date('d.m.Y H:i', strtotime($added_at)).' UTC') : 'Hinzugefügt: unbekannt';

                $findLink = $jid ? buildFindLink($jid, $adults) : null;

                ?>
                <tr>
                    <td>
                        <div>
                            <?php if ($isNew): ?>
                                <span class="badge new" title="<?php echo e($addedTitle); ?>">🆕</span>
                                &nbsp;
                            <?php endif; ?>
                            <?php echo e($title ?: $jid ?: '–'); ?>
                        </div>
                        <div class="alt" title="<?php echo e($altText); ?>">
                            <?php echo e($cabinName); ?> <span class="alt">(<?php echo e($altText); ?>)</span>
                        </div>
                    </td>
                    <td><?php echo e($ship ?: '–'); ?></td>
                    <td><?php echo e($start ?: '–'); ?> – <?php echo e($end ?: '–'); ?></td>
                    <td class="numeric"><?php echo $dur ?: '–'; ?></td>
                    <td class="center">
                        <?php echo $flight ? '<span class="badge ok">inkl.</span>' : '<span class="badge no">ohne</span>'; ?>
                    </td>
                    <td class="numeric"><?php echo $fmtPnp; ?> <?php echo $deltaNightStr; ?></td>
                    <td class="numeric"><?php echo $fmtTotal; ?> <?php echo $deltaTotalStr; ?></td>
                    <td>
                        <?php if ($findLink): ?><a class="link" href="<?php echo e($findLink); ?>" target="_blank" rel="noopener">Zur Reise</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </body></html>
    <?php
}

/* ---------------------------- Telegram ---------------------------- */
/**
 * Erwartete Event-Formate:
 *  - NEW:
 *      ['type'=>'new','row'=>[...]]
 *      Optional in row: ['cheapest'=>['code'=>'M','pnp'=>..,'amount'=>..]]
 *  - DROP (Preissenkung):
 *      [
 *        'type'=>'drop',
 *        'row'=>[...],                 // aktueller Datensatz (mit cheapest/code)
 *        'delta'=>float,               // newAmount - oldAmount (negativ)
 *        'old'=>float,                 // alter Gesamtpreis
 *        'oldCheapestCode'=>'I'        // optional: vorher günstigster Kabinentyp
 *      ]
 *
 * Benötigte Config in config.php:
 *  TELEGRAM_ENABLED (bool)
 *  TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID
 *  TG_DEFAULT_SILENT (bool, default true)
 *  TG_PNP_ALERT_THRESHOLD (float, <= Schwellwert => NICHT stumm)
 *  TG_MAX_PNP_NOTIFY (float, >0: nur benachrichtigen, wenn pnp <= Wert)
 */

function tgSend(string $text, bool $silent = true): bool {
    $token = cfg('TELEGRAM_BOT_TOKEN', '');
    $chat  = cfg('TELEGRAM_CHAT_ID', '');
    if (!$token || !$chat) return false;

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $post = http_build_query([
            'chat_id'                  => $chat,
            'text'                     => $text,              // Plaintext, robust ggü. Sonderzeichen
            'disable_web_page_preview' => true,
            'disable_notification'     => $silent,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 12,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($err === 0 && $code >= 200 && $code < 300);
}

/**
 * Entscheidet, ob ein Event überhaupt gesendet werden soll (Max-Preis/Nacht Filter).
 * - TG_MAX_PNP_NOTIFY: Wenn > 0, werden NUR Events gesendet, deren pnp <= Schwelle ist.
 */
function tgShouldNotify(array $ev, int $adults): bool {
    $maxPnp = (float) cfg('TG_MAX_PNP_NOTIFY', 0);
    if ($maxPnp <= 0) return true; // kein Limit

    $r = $ev['row'] ?? [];
    $pnp = null;

    if (isset($r['amountPerNightPerAdult'])) {
        $pnp = (float)$r['amountPerNightPerAdult'];
    } elseif (isset($r['amount'], $r['duration']) && $r['duration'] && $adults > 0) {
        $pnp = (float)$r['amount'] / (int)$r['duration'] / $adults;
    }

    return ($pnp !== null) ? ($pnp <= $maxPnp) : true;
}

/**
 * Steuert stumm/laute Zustellung:
 *  - Standard kommt aus TG_DEFAULT_SILENT (default true).
 *  - Wenn pnp <= TG_PNP_ALERT_THRESHOLD, dann NICHT stumm (laute Push).
 */
function tgShouldBeSilent(array $ev, int $adults): bool {
    $defaultSilent = (bool) (cfg('TG_DEFAULT_SILENT', true));
    $threshold     = (float) (cfg('TG_PNP_ALERT_THRESHOLD', 0));

    $r   = $ev['row'] ?? [];
    $pnp = null;

    if (isset($r['amountPerNightPerAdult'])) {
        $pnp = (float)$r['amountPerNightPerAdult'];
    } elseif (isset($r['amount'], $r['duration']) && $r['duration'] && $adults > 0) {
        $pnp = (float)$r['amount'] / (int)$r['duration'] / $adults;
    }

    if ($threshold > 0 && $pnp !== null && $pnp <= $threshold) {
        return false; // laut
    }
    return $defaultSilent; // sonst Standard (meist stumm)
}

/** Kleines Label-Helper */
function cabinLabelFor(string $code): string {
    return CABIN_LABELS[$code] ?? $code;
}

/**
 * Baut den Telegram-Text.
 * - Enthält Anzahl Personen im Titel
 * - „Neue Reise“ inkl. günstigstem Kabinentyp
 * - „Preissenkung“ inkl. altem & neuem Kabinentyp (falls verfügbar)
 */
function formatTgMessage(array $ev, int $adults): string {
    $r = $ev['row'] ?? [];
    $title = (string)($r['title'] ?? ($r['journeyIdentifier'] ?? 'Reise'));
    $ship  = (string)($r['shipName'] ?? '');
    $start = (string)($r['startDate'] ?? '');
    $end   = (string)($r['endDate'] ?? '');
    $dur   = (int)($r['duration'] ?? 0);
    $pnp   = isset($r['amountPerNightPerAdult']) ? (float)$r['amountPerNightPerAdult'] : null;
    $amt   = isset($r['amount']) ? (float)$r['amount'] : null;

    $jid   = (string)($r['journeyIdentifier'] ?? '');
    $link  = $jid ? buildFindLink($jid, $adults) : '';

    $newCode = $r['cheapest']['code'] ?? null; // bevorzugt aus 'cheapest'
    if (!$newCode && isset($r['alternatives']) && is_array($r['alternatives'])) {
        // Fallback: günstigste Alternative anhand pnp bestimmen
        $best = null; $bestCode = null;
        foreach ($r['alternatives'] as $c=>$info) {
            $pp = $info['pnp'] ?? null;
            if ($pp === null) continue;
            if ($best === null || $pp < $best) { $best=$pp; $bestCode=$c; }
        }
        $newCode = $bestCode;
    }
    $newCodeLbl = $newCode ? cabinLabelFor((string)$newCode).' ('.(string)$newCode.')' : null;

    if (($ev['type'] ?? '') === 'new') {
        $lines = [
                "🆕 Neue Reise ({$adults} PAX)",
                $title,
                $ship ? "Schiff: {$ship}" : null,
                $dur ? "Nächte: {$dur}" : null,
                ($start && $end) ? "Zeitraum: {$start} – {$end}" : null,
                ($newCodeLbl ? "Günstigster Typ: {$newCodeLbl}" : null),
                ($pnp !== null ? "ab: ".number_format($pnp,0,',','.')." ".CURRENCY."/N/Person" : null),
                ($amt !== null ? "Gesamt: ".number_format($amt,0,',','.')." ".CURRENCY : null),
                $link,
        ];
        return implode("\n", array_values(array_filter($lines, fn($x)=>$x!==null && $x!=='')));
    }

    if (($ev['type'] ?? '') === 'drop') {
        $oldTotal  = (float)($ev['old'] ?? 0.0);
        $newTotal  = (float)($r['amount'] ?? 0.0);
        $diff      = $newTotal - $oldTotal; // negativ
        $oldCode   = $ev['oldCheapestCode'] ?? null;
        $oldCodeLbl= $oldCode ? cabinLabelFor((string)$oldCode).' ('.(string)$oldCode.')' : null;

        $lines = [
                "💸 Preissenkung ({$adults} PAX)",
                $title,
                $ship ? "Schiff: {$ship}" : null,
                $dur ? "Nächte: {$dur}" : null,
                ($start && $end) ? "Zeitraum: {$start} – {$end}" : null,
                ($oldCodeLbl ? "Alt. Typ: {$oldCodeLbl}" : null),
                ($newCodeLbl ? "Neu. Typ: {$newCodeLbl}" : null),
                "Alt: ".number_format($oldTotal,0,',','.')." ".CURRENCY,
                "Neu: ".number_format($newTotal,0,',','.')." ".CURRENCY." (".number_format($diff,0,',','.')." ".CURRENCY.")",
                ($pnp !== null ? "ab: ".number_format((float)$r['amountPerNightPerAdult'],0,',','.')." ".CURRENCY."/N/Person" : null),
                $link,
        ];
        return implode("\n", array_values(array_filter($lines, fn($x)=>$x!==null && $x!=='')));
    }

    // Fallback
    return trim($title."\n".$link);
}
