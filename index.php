<?php
/*******************************************************
 * Yanniks CruiseMonitor
 * WEB:
 *   - Anzeige + Filter (adults/ship/from/to)
 * CLI:
 *   - php yanniks-cruisemonitor.php --update --adults=1
 *   - Lädt Liste (size=1000) + Kabinen-Details pro Reise
 *   - Status-Logs: Reise, Kabinen, Preise, günstigste Option
 *******************************************************/
declare(strict_types=1);

const CACHE_DIR       = __DIR__ . '/cache';
const TIMEOUT_SECONDS = 25;
const SIZE_PER_PAGE   = 1000;
const LIST_BASE       = 'https://aida.de/content/aida-search-and-booking/requests/search.cruise.v1.json';
const DETAIL_BASE     = 'https://aida.de/content/aida-search-and-booking/requests/detail.content.json';
const USER_AGENT      = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const CURRENCY        = '€';

// Reihenfolge: je höher, desto „besser“
const CABIN_RANK = ['I'=>1,'M'=>2,'B'=>3,'V'=>4,'K'=>5,'D'=>6,'P'=>7,'J'=>8,'S'=>9];
function cabinRank(string $code): int { return CABIN_RANK[$code] ?? 0; }

const CABIN_LABELS = [
        'I'=>'Innen','M'=>'Meerblick','B'=>'Balkon','V'=>'Veranda','K'=>'Ver. Komfort',
        'D'=>'Ver. Deluxe','P'=>'Ver. Patio.','J'=>'J.Suite','S'=>'Suite'
];
const TARIFF_KEYS  = ['lig','cla','claAl','ind','indAl','comAl','pau','pauAl','see','seeAl'];

if (!is_dir(CACHE_DIR)) { @mkdir(CACHE_DIR, 0775, true); }
$cookieFile = CACHE_DIR . '/aida_cookies.txt';

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

    try {
        logStatus("Starte Update (adults={$cliAdults}) …");

        // Alt-Cache laden (enriched)
        $oldItems = [];
        if (is_file($cacheFile)) {
            [$oldItems, ] = loadCache($cacheFile);
            if (!is_array($oldItems)) $oldItems = [];
        }
        $oldMapByJid = mapByJourney($oldItems);

        // Live-Liste holen
        warmUpAida($cookieFile);
        $resp = fetchAidaList($cliAdults, 1, SIZE_PER_PAGE, $cookieFile);
        $baseRows = extractVariantsFromList($resp, $cliAdults); // enthält listAmount
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

        foreach ($baseRows as $row) {
            $jid = $row['journeyIdentifier'] ?? null; if (!$jid) continue;



            $oldListAmt = isset($oldMapByJid[$jid]['listAmount']) ? (int)$oldMapByJid[$jid]['listAmount'] : null;
            $newListAmt = isset($newListAmtByJid[$jid]) ? (int)$newListAmtByJid[$jid] : null;

            $isNew      = !isset($oldMapByJid[$jid]);
            $amtChanged = ($isNew || $newListAmt !== $oldListAmt);



            if ($daily || $isNew || $amtChanged) {
                $needDetail[] = $jid;
            } else {
                $reuseOnly[]  = $jid;
            }
        }

        logStatus("Reisen gesamt: {$total}. Details nötig für: ".count($needDetail).", Reuse: ".count($reuseOnly));

        // Enrichment: für needDetail wirklich Detail-API aufrufen, sonst aus alt übernehmen
        $enrichedMap = []; // jid => enriched row

        // 1) Reuse-Items zuerst befüllen
        foreach ($reuseOnly as $jid) {
            $enrichedMap[$jid] = $oldMapByJid[$jid];
        }

        // 2) Detail-Calls für geänderte/neue
        $i = 0; $n = count($needDetail);
        foreach ($baseRows as $row) {
            $jid = $row['journeyIdentifier'] ?? null; if (!$jid) continue;

            // Wenn bereits via reuse befüllt, später nur Meta (title/ship/dates) updaten
            $isReuse = isset($enrichedMap[$jid]);

            if (in_array($jid, $needDetail, true)) {
                $i++;
                $title = $row['title'] ?? $jid ?? '–';
                logStatus(sprintf("[%d/%d] Journey %s — %s (Details abrufen)", $i, $n, $jid, $title));

                $detail = fetchCabinDetail($jid, $cliAdults, $cookieFile);
                if (!$detail) {
                    logStatus("  ! Keine Detail-Daten (API blockiert/leer). Reuse alter Details falls vorhanden.");
                    if (isset($oldMapByJid[$jid])) {
                        // Alte Details übernehmen & nur Metadaten (Titel/Zeiten/Schiff) aktualisieren
                        $enriched = mergeMeta($oldMapByJid[$jid], $oldMapByJid[$jid], $row);
                    } else {
                        // allerletzter Fallback: Basis + Link + ID setzen (Details fehlen dann halt)
                        $enriched = $row;
                        $enriched['journeyIdentifier'] = $jid; // <- explizit setzen
                        $enriched['absLink'] = buildFindLink($jid, $cliAdults);
                    }
                } else {
                    [$cheapest, $alts, $lastAPIPriceUpdate] = computeCheapestAndAlternatives($row, $detail, $cliAdults);

                    if ($alts) {
                        foreach ($alts as $code => $info) {
                            if ($verbose) {
                                $lbl = CABIN_LABELS[$code] ?? $code;
                                $pnp = $info['pnp'] !== null ? number_format((float)$info['pnp'], 0, ',', '.') . ' €/N' : '–';
                                $amt = $info['amount'] !== null ? number_format((float)$info['amount'], 0, ',', '.') . ' €' : '–';
                                logStatus("    - {$lbl} ({$code}): {$pnp} | {$amt}");
                            }
                        }
                    } else {
                        logStatus("    - Keine Kabinen-Preise gefunden.");
                    }

                    if ($cheapest) {
                        $lbl = CABIN_LABELS[$cheapest['code']] ?? $cheapest['code'];
                        $pnp = $cheapest['pnp'] !== null ? number_format((float)$cheapest['pnp'], 0, ',', '.') . ' €/N' : '–';
                        $amt = $cheapest['amount'] !== null ? number_format((float)$cheapest['amount'], 0, ',', '.') . ' €' : '–';
                        logStatus("    ✓ Günstigste: {$lbl} — {$pnp} | {$amt}");
                    } else {
                        logStatus("    ! Keine günstigste Option bestimmbar.");
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
                    } else {
                        unset($enriched['amount'], $enriched['amountPerNightPerAdult']);
                    }
                }
                // listAmount immer aktualisieren
                $enriched['listAmount'] = $newListAmtByJid[$jid] ?? ($enriched['listAmount'] ?? null);

                $enrichedMap[$jid] = mergeMeta($enrichedMap[$jid] ?? null, $enriched, $row);
            } else {
                // Pure reuse: alten enriched Datensatz nehmen und NUR Metadaten drüberlegen
                $base = $enrichedMap[$jid] ?? ($oldMapByJid[$jid] ?? []);
                $enrichedMap[$jid] = mergeMeta($base, $base, $row);
                // listAmount updaten auch bei Reuse
                $enrichedMap[$jid]['listAmount'] = $newListAmtByJid[$jid] ?? ($enrichedMap[$jid]['listAmount'] ?? null);
            }
        }

        // Journeys, die es nicht mehr in der Liste gibt, entfernen
        foreach ($enrichedMap as $jid => $_row) {

            if (!isset($currentJids[$jid])) unset($enrichedMap[$jid]);

        }

        // prev-Cache speichern nur bei Preisänderung (günstigste Option)
        $enriched = array_values($enrichedMap);
        $changed = false;
        foreach ($enriched as $it) {
            $jid = $it['journeyIdentifier'] ?? null;
            if ($jid && isset($oldMapByJid[$jid])) {
                $oldAmt = (float)($oldMapByJid[$jid]['amount'] ?? -1);
                $newAmt = (float)($it['amount'] ?? -2);
                if ($oldAmt !== $newAmt) { $changed = true; break; }
            }
        }
        if ($changed && is_file($cacheFile)) {
            @copy($cacheFile, $prevCacheFile);
            logStatus("prev-Cache aktualisiert (Preisänderung erkannt).");
        } else {
            logStatus("prev-Cache unverändert (keine Preisänderung).");
        }

        // Hauptcache speichern & Daily-Marker setzen
        saveCache($cacheFile, $enriched);

        if ($daily) setDailyRefreshed($cliAdults);


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
$shipFilt = isset($_GET['ship']) ? trim((string)$_GET['ship']) : '';
$fromFilt = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$toFilt   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

$minNights = isset($_GET['minDays']) ? max(0, (int)$_GET['minDays']) : 0;
$maxNights = isset($_GET['maxDays']) ? max(0, (int)$_GET['maxDays']) : 0;

// Mindest-Kabine: '', 'I','M','B','V','K','D','J','S','P'
$minCabin  = isset($_GET['minCabin']) ? strtoupper(trim((string)$_GET['minCabin'])) : '';
if ($minCabin && !isset(CABIN_RANK[$minCabin])) $minCabin = '';

$cacheFile     = CACHE_DIR . "/aida_adults{$adults}.json";
$prevCacheFile = CACHE_DIR . "/aida_adults{$adults}_prev.json";

[$items, $meta] = loadCache($cacheFile);
$rows = is_array($items) ? $items : [];
$prevMap = loadPrevMap($prevCacheFile);

// Filter anwenden
$rows = applyFilters($rows, $shipFilt, $fromFilt, $toFilt, $minNights, $maxNights);

// Deltas berechnen (günstigste Option)
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
renderList($adults, $rows, $changes, $meta, $shipFilt, $fromFilt, $toFilt, $minNights, $maxNights, $minCabin);

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
            $listAmount = isset($v['amount']) ? (float)$v['amount'] : null; // << wichtig

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
                    'flightIncluded'      => $flightIncluded,
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
                $amt = isset($t['amount']) ? (float)$t['amount'] : (isset($t['amountPerPerson']) ? (float)$t['amountPerPerson'] * max(1,$adults) : null);
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


// Metafelder (Titel/Zeiten/Schiff) aus frischer Liste übernehmen,
// dabei Detail-Felder (cheapest/alternatives/amount/amountPerNightPerAdult/lastAPIPriceUpdate)
// IMMER erhalten. journeyIdentifier & absLink werden garantiert gesetzt.
function mergeMeta(?array $existing, array $enrichedOrOld, array $freshBase): array {
    // Basis: wenn bereits ein bestehender Datensatz (mit Details) da ist, den nehmen,
    // sonst den "alten/enriched" (falls vorhanden), sonst eine leere Hülle.
    $dst = $existing ?? $enrichedOrOld ?? [];

    // Immer sicherstellen: Journey-ID bleibt erhalten
    if (!empty($freshBase['journeyIdentifier'])) {
        $dst['journeyIdentifier'] = $freshBase['journeyIdentifier'];
    } elseif (!empty($dst['journeyIdentifier'])) {
        // already there
    } elseif (!empty($enrichedOrOld['journeyIdentifier'])) {
        $dst['journeyIdentifier'] = $enrichedOrOld['journeyIdentifier'];
    }

    // Metafelder aus aktueller Liste übernehmen (aber NICHT Details überschreiben)
    foreach (['title','shipName','startDate','endDate','duration','routeCode','routeGroupCode','adults', 'flightIncluded'] as $k) {
        if (array_key_exists($k, $freshBase)) $dst[$k] = $freshBase[$k];
    }

    // Link: falls fehlt, neu bauen
    if (empty($dst['absLink']) && !empty($dst['journeyIdentifier']) && !empty($dst['adults'])) {
        $dst['absLink'] = buildFindLink($dst['journeyIdentifier'], (int)$dst['adults']);
    }

    // WICHTIG: Detail-Felder NIEMALS löschen/überschreiben, wenn freshBase sie gar nicht setzt.

    return $dst;
}


// Marker-Datei für täglichen Refresh (pro adults)
function dailyMarkerFile(int $adults): string {
    return CACHE_DIR . "/aida_adults{$adults}_daily.txt";
}

// Prüft, ob heute schon ein Voll-Refresh gemacht wurde
function shouldDailyRefresh(int $adults): bool {
    $f = dailyMarkerFile($adults);
    $today = date('Y-m-d');
    $last = is_file($f) ? trim((string)file_get_contents($f)) : '';
    return $last !== $today;
}

// Markiert, dass der heutige Voll-Refresh abgeschlossen wurde
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

function applyFilters(array $rows, string $shipFilt, string $from, string $to, int $minNights, int $maxNights): array {
    $ships = array_filter(array_map('trim', explode(',', $shipFilt ?: '')));
    $fromT = $from ? strtotime($from.' 00:00:00') : null;
    $toT   = $to   ? strtotime($to.' 23:59:59')   : null;

    return array_values(array_filter($rows, function($r) use ($ships,$fromT,$toT,$minNights,$maxNights) {
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
        string $minCabin
): void {

    $updatedAt=$meta['updated_at'] ?? null;
    $count = count($rows);

    $params = array_filter([
            'ship'     => $shipFilt ?: null,
            'from'     => $fromFilt ?: null,
            'to'       => $toFilt ?: null,
            'minDays'  => $minNights ?: null,
            'maxDays'  => $maxNights ?: null,
            'minCabin' => $minCabin ?: null,
    ], static fn($v) => $v !== null && $v !== '');

    $urlA1 = '?' . http_build_query($params + ['adults' => 1]);
    $urlA2 = '?' . http_build_query($params + ['adults' => 2]);

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
            .filter{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
            .filter input,.filter select{background:#0e1a2d;border:1px solid var(--border);color:var(--fg);padding:8px 10px;border-radius:10px}
            .filter a.link, .filter button{background:#0e1a2d;border:1px solid var(--border);color:var(--fg);padding:8px 12px;border-radius:10px;text-decoration:none;cursor:pointer}
            .filter a.link:hover, .filter button:hover{background:#12233d}
            table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
            thead th{text-align:left;padding:12px;font-size:13px;color:var(--muted);border-bottom:1px solid var(--border)}
            tbody td{padding:12px;border-bottom:1px solid var(--border);vertical-align:top}
            tbody tr:hover{background:#0f1b30}
            .numeric{text-align:right;white-space:nowrap}
            .delta{font-size:12px;margin-left:6px}.delta.down{color:var(--down)}.delta.up{color:var(--up)}.delta.zero{color:var(--muted)}
            .center{text-align:center}
            .badge{font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:#0e1a2d}
            .badge.ok{color:var(--down);border-color:var(--down)}
            .badge.no{color:#f39c12;border-color:#f39c12}
            a.link{color:var(--accent);text-decoration:none}a.link:hover{text-decoration:underline}
            .alt{color:var(--muted);font-size:12px;margin-top:4px}
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
            </div>

        </div>
        <div class="actions">
            <a href="<?php echo e($urlA1); ?>" class="<?php echo $adults===1?'active':''; ?>">1 Erw.</a>
            <a href="<?php echo e($urlA2); ?>" class="<?php echo $adults===2?'active':''; ?>">2 Erw.</a>
        </div>
    </header>

    <form class="filter" method="get">
        <input type="hidden" name="adults" value="<?php echo (int)$adults; ?>">
        <select name="ship">
            <option value="">Alle Schiffe</option>
            <?php foreach ($shipSet as $name => $_): ?>
                <option value="<?php echo e($name); ?>" <?php echo ($shipFilt===$name)?'selected':''; ?>><?php echo e($name); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?php echo e($fromFilt); ?>">
        <input type="date" name="to"   value="<?php echo e($toFilt); ?>">
        <input type="number" name="minDays" min="0" placeholder="min Tage" style="width:110px" value="<?php echo (int)$minNights; ?>">
        <input type="number" name="maxDays" min="0" placeholder="max Tage" style="width:110px" value="<?php echo (int)$maxNights; ?>">
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

                $che   = $r['cheapest'] ?? null;
                $alts  = $r['alternatives'] ?? [];

                $flight = (bool)($r['flightIncluded'] ?? false);

                $altsFiltered = filterAlternativesByMinCabin($alts, $minCabin);

                $cheFiltered = pickCheapestFromAlts($altsFiltered);

                if (!$cheFiltered) { continue; }

                $amount = $cheFiltered['amount'] ?? null;
                $pnp    = $cheFiltered['pnp'] ?? null;

                $fmtTotal = $amount===null ? '-' : number_format((float)$amount, 0, ',', '.').' '.CURRENCY;
                $fmtPnp   = $pnp===null ? '-' : number_format((float)$pnp, 0, ',', '.').' '.CURRENCY;

                $altParts = [];
                foreach ($altsFiltered as $code=>$info) {
                    if ($code === ($cheFiltered['code'] ?? null)) continue;
                    $label = CABIN_LABELS[$code] ?? $info['name'] ?? $code;
                    $pp    = $info['pnp'] ?? null;
                    if ($pp !== null) $altParts[] = $label.': '.number_format((float)$pp,0,',','.').' '.CURRENCY.'/N';
                }
                $altText = $altParts ? implode(' | ', $altParts) : 'keine Alternativen';
                $cabinName = $cheFiltered['name'] ?? (CABIN_LABELS[$cheFiltered['code'] ?? ''] ?? ($cheFiltered['code'] ?? 'Kabine'));

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

                $findLink = $jid ? buildFindLink($jid, $adults) : null;
                ?>
                <tr>
                    <td>
                        <div><?php echo e($title ?: $jid ?: '–'); ?></div>
                        <?php if ($cabinName): ?>
                            <div class="alt" title="<?php echo e($altText); ?>">
                                <?php echo e($cabinName); ?> <span class="alt">(<?php echo e($altText); ?>)</span>
                            </div>
                        <?php endif; ?>
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
