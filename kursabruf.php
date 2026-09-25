<?php
declare(strict_types=1);

const INPUT_FILE = __DIR__ . '/aktienliste.txt';
const OUTPUT_FILE = __DIR__ . '/kurstabelle.txt';
const HITS_FILE = __DIR__ . '/treffer.txt';
const STATISTICS_FILE = __DIR__ . '/statistik.txt';
const INFO_PAGE_FILE = __DIR__ . '/kursinfo.html';
const STATUS_FILE = __DIR__ . '/kurstabelle.status.txt';
const CONFIG_FILE = __DIR__ . '/tracker.cfg';
const TIMEZONE = 'Europe/Berlin';
const REQUEST_TIMEOUT_SECONDS = 20;

/**
 * Liest alle ISINs aus der Aktienliste, ruft die aktuellen Kurse ab und
 * schreibt eine tab-getrennte Ergebniszeile in kurstabelle.txt.
 */
function schreibeKurstabelle(): void
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Die PHP-Erweiterung cURL ist nicht aktiviert.');
    }

    $isins = leseIsins(INPUT_FILE);
    if ($isins === []) {
        throw new RuntimeException('In der Aktienliste wurden keine ISINs gefunden.');
    }

    $kurse = [];
    foreach ($isins as $aktie) {
        $symbol = ermittleYahooSymbol($aktie['isin'], $aktie['bezeichnung']);
        if ($symbol === null) {
            $kurse[] = 'NA';
            error_log("Kein aktueller Yahoo-Finance-Kurs für {$aktie['isin']} ({$aktie['bezeichnung']}).");
            continue;
        }

        $kurse[] = ermittleLetztenKurs($symbol, $aktie['isin']);
    }

    $zeitpunkt = (new DateTimeImmutable('now', new DateTimeZone(TIMEZONE)))
        ->format('Y-m-d H:i:s');
    $zeile = implode("\t", [$zeitpunkt, ...$kurse]) . PHP_EOL;

    $datei = fopen(OUTPUT_FILE, 'ab');
    if ($datei === false) {
        throw new RuntimeException('Die Ausgabedatei kann nicht geöffnet werden.');
    }

    try {
        if (!flock($datei, LOCK_EX)) {
            throw new RuntimeException('Die Ausgabedatei kann nicht gesperrt werden.');
        }

        $dateistatus = fstat($datei);
        if ($dateistatus === false) {
            throw new RuntimeException('Der Status der Ausgabedatei kann nicht gelesen werden.');
        }

        $ausgabe = '';
        if ($dateistatus['size'] === 0) {
            $bezeichnungen = array_column($isins, 'bezeichnung');
            $ausgabe .= implode("\t", ['Zeitpunkt', ...$bezeichnungen]) . PHP_EOL;
        }
        $ausgabe .= $zeile;

        if (fwrite($datei, $ausgabe) === false) {
            throw new RuntimeException('Die Ergebniszeile konnte nicht geschrieben werden.');
        }

        flock($datei, LOCK_UN);
    } finally {
        fclose($datei);
    }
}

function schreibeStatus(string $meldung): void
{
    $zeitpunkt = (new DateTimeImmutable('now', new DateTimeZone(TIMEZONE)))
        ->format('Y-m-d H:i:s');
    $status = "[$zeitpunkt] $meldung" . PHP_EOL;

    if (file_put_contents(STATUS_FILE, $status, LOCK_EX) === false) {
        error_log("Statusdatei konnte nicht geschrieben werden: $meldung");
    }
}

/**
 * Berechnet die relative maximale Abweichung vom Mittelwert im Stabilitätsfenster.
 * $aktienSpalte ist 1-basiert: 1 bezeichnet die erste Kursspalte nach dem Zeitstempel.
 */
function berechneMaxDiffStab(int $aktienSpalte): float
{
    if ($aktienSpalte < 1) {
        throw new InvalidArgumentException('Die Aktienspalte muss mindestens 1 sein.');
    }

    $konfiguration = leseStabilitaetsKonfiguration(CONFIG_FILE);
    $jetzt = new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
    $fensterAnfang = $jetzt->modify('-' . $konfiguration['tStabilAnfang'] . ' days');
    $fensterEnde = $jetzt->modify('-' . $konfiguration['tStabilEnde'] . ' days');
    $kurswerte = leseKurswerteImZeitfenster($aktienSpalte, $fensterAnfang, $fensterEnde);

    if ($kurswerte === []) {
        throw new RuntimeException('Im Stabilitätsfenster wurden keine gültigen Kurswerte gefunden.');
    }

    $mittelwert = array_sum($kurswerte) / count($kurswerte);
    if ($mittelwert <= 0.0) {
        throw new RuntimeException('Der Mittelwert der Kurswerte muss positiv sein.');
    }

    $maximaleDifferenz = 0.0;
    foreach ($kurswerte as $kurs) {
        $maximaleDifferenz = max($maximaleDifferenz, abs($kurs - $mittelwert));
    }

    return $maximaleDifferenz / $mittelwert;
}

/**
 * Berechnet den relativen maximalen Kursabfall gegenüber dem Stabilitätsmittelwert.
 * Ein Kurs unter dem Mittelwert ergibt einen negativen MaxAbfall.
 */
function berechneMaxAbfall(int $aktienSpalte): float
{
    if ($aktienSpalte < 1) {
        throw new InvalidArgumentException('Die Aktienspalte muss mindestens 1 sein.');
    }

    $konfiguration = leseStabilitaetsKonfiguration(CONFIG_FILE);
    $jetzt = new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
    $stabilKurse = leseKurswerteImZeitfenster(
        $aktienSpalte,
        $jetzt->modify('-' . $konfiguration['tStabilAnfang'] . ' days'),
        $jetzt->modify('-' . $konfiguration['tStabilEnde'] . ' days')
    );
    $abfallKurse = leseKurswerteImZeitfenster(
        $aktienSpalte,
        $jetzt->modify('-' . $konfiguration['tAbfallAnfang'] . ' days'),
        $jetzt->modify('-' . $konfiguration['tAbfallEnde'] . ' days')
    );

    $mittelwert = array_sum($stabilKurse) / count($stabilKurse);
    if ($mittelwert <= 0.0) {
        throw new RuntimeException('Der Mittelwert der Kurswerte muss positiv sein.');
    }

    return (min($abfallKurse) - $mittelwert) / $mittelwert;
}

/**
 * Prüft, ob die Aktie das kombinierte Kursereignis erfüllt.
 */
function erfuelltAuswahlkriterium(int $aktienSpalte): bool
{
    $konfiguration = leseStabilitaetsKonfiguration(CONFIG_FILE);
    $maxDiffStab = berechneMaxDiffStab($aktienSpalte);
    $maxAbfall = berechneMaxAbfall($aktienSpalte);

    return abs($maxDiffStab) < $konfiguration['StabSchwelle']
        && $maxAbfall < $konfiguration['AbfallSchwelle'];
}

/**
 * Berechnet für jede Aktie die relative Kursänderung im Stabilitätsfenster und
 * liefert die Mittelwerte sowie den größten Anstieg und Abstieg mit betroffenen Aktie.
 *
 * @return array{KursAendMittel: float, MaxKursAbstieg: float, MaxKursAbstiegAktie: string, MaxKursAnstieg: float, MaxKursAnstiegAktie: string}
 */
function berechneKurskennzahlen(): array
{
    $aktien = leseIsins(INPUT_FILE);
    if ($aktien === []) {
        throw new RuntimeException('In der Aktienliste wurden keine ISINs gefunden.');
    }

    $konfiguration = leseStabilitaetsKonfiguration(CONFIG_FILE);
    $jetzt = new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
    $fensterAnfang = $jetzt->modify('-' . $konfiguration['tStabilAnfang'] . ' days');
    $aenderungen = [];
    $maxAbstieg = null;
    $maxAbstiegAktie = '';
    $maxAnstieg = null;
    $maxAnstiegAktie = '';

    foreach ($aktien as $index => $aktie) {
        $aktienSpalte = $index + 1;

        try {
            $kurswerte = leseKurswerteImZeitfenster($aktienSpalte, $fensterAnfang, $jetzt);
        } catch (RuntimeException $exception) {
            error_log("Kennzahlen übersprungen für {$aktie['isin']}: {$exception->getMessage()}");
            continue;
        }

        if ($kurswerte === []) {
            continue;
        }

        $ersterKurs = $kurswerte[0];
        $letzterKurs = $kurswerte[count($kurswerte) - 1];
        if ($ersterKurs <= 0.0) {
            continue;
        }

        $aenderung = ($letzterKurs - $ersterKurs) / $ersterKurs;
        $aenderungen[] = $aenderung;

        $aktienMarke = $aktie['isin'] . ' (' . $aktie['bezeichnung'] . ')';
        if ($maxAbstieg === null || $aenderung < $maxAbstieg) {
            $maxAbstieg = $aenderung;
            $maxAbstiegAktie = $aktienMarke;
        }
        if ($maxAnstieg === null || $aenderung > $maxAnstieg) {
            $maxAnstieg = $aenderung;
            $maxAnstiegAktie = $aktienMarke;
        }
    }

    if ($aenderungen === []) {
        throw new RuntimeException('Für keine Aktie konnten Kursänderungen im Stabilitätsfenster berechnet werden.');
    }

    return [
        'KursAendMittel' => array_sum($aenderungen) / count($aenderungen),
        'MaxKursAbstieg' => $maxAbstieg ?? 0.0,
        'MaxKursAbstiegAktie' => $maxAbstiegAktie,
        'MaxKursAnstieg' => $maxAnstieg ?? 0.0,
        'MaxKursAnstiegAktie' => $maxAnstiegAktie,
    ];
}

/**
 * Wertet alle Aktien nach einem erfolgreichen Kursabruf aus und speichert Treffer.
 */
function analysiereKurstabelleNachErfolg(): void
{
    $aktien = leseIsins(INPUT_FILE);
    $letzteZeile = leseLetzteKurszeile();
    $letzterZeitpunkt = $letzteZeile[0];
    $konfiguration = leseStabilitaetsKonfiguration(CONFIG_FILE);
    $treffer = [];

    foreach ($aktien as $index => $aktie) {
        $aktienSpalte = $index + 1;

        try {
            $maxDiffStab = berechneMaxDiffStab($aktienSpalte);
            $maxAbfall = berechneMaxAbfall($aktienSpalte);
        } catch (RuntimeException $exception) {
            error_log("Analyse übersprungen für {$aktie['isin']}: {$exception->getMessage()}");
            continue;
        }

        if (abs($maxDiffStab) < $konfiguration['StabSchwelle']
            && $maxAbfall < $konfiguration['AbfallSchwelle']) {
            $treffer[] = implode("\t", [
                $letzterZeitpunkt,
                $aktie['isin'],
                $aktie['bezeichnung'],
                number_format($maxDiffStab, 8, '.', ''),
                number_format($maxAbfall, 8, '.', ''),
            ]) . PHP_EOL;
        }
    }

    $kennzahlen = berechneKurskennzahlen();
    $zeitpunkt = (new DateTimeImmutable('now', new DateTimeZone(TIMEZONE)))
        ->format('Y-m-d H:i:s');
    $kennzahlenZeilen = [
        implode("\t", [$zeitpunkt, 'KursAendMittel', number_format($kennzahlen['KursAendMittel'], 8, '.', ''), '']) . PHP_EOL,
        implode("\t", [$zeitpunkt, 'MaxKursAbstieg', number_format($kennzahlen['MaxKursAbstieg'], 8, '.', ''), $kennzahlen['MaxKursAbstiegAktie']]) . PHP_EOL,
        implode("\t", [$zeitpunkt, 'MaxKursAnstieg', number_format($kennzahlen['MaxKursAnstieg'], 8, '.', ''), $kennzahlen['MaxKursAnstiegAktie']]) . PHP_EOL,
    ];

    $datei = fopen(HITS_FILE, 'ab');
    if ($datei === false) {
        throw new RuntimeException('Die Trefferdatei kann nicht geöffnet werden.');
    }

    try {
        if (!flock($datei, LOCK_EX)) {
            throw new RuntimeException('Die Trefferdatei kann nicht gesperrt werden.');
        }

        $ausgabe = implode('', $treffer);
        if (fwrite($datei, $ausgabe) === false) {
            throw new RuntimeException('Die Treffer konnten nicht geschrieben werden.');
        }
        flock($datei, LOCK_UN);
    } finally {
        fclose($datei);
    }

    $statistikDatei = fopen(STATISTICS_FILE, 'ab');
    if ($statistikDatei === false) {
        throw new RuntimeException('Die Statistikdatei kann nicht geöffnet werden.');
    }

    try {
        if (!flock($statistikDatei, LOCK_EX)) {
            throw new RuntimeException('Die Statistikdatei kann nicht gesperrt werden.');
        }

        $dateigröße = filesize(STATISTICS_FILE);
        if ($dateigröße === false || $dateigröße === 0) {
            $kopf = "Zeitpunkt\tKennzahl\tWert\tAktie" . PHP_EOL;
            if (fwrite($statistikDatei, $kopf) === false) {
                throw new RuntimeException('Der Statistikkopf konnte nicht geschrieben werden.');
            }
        }

        $abschnitt = '---' . PHP_EOL . $zeitpunkt . PHP_EOL;
        if (fwrite($statistikDatei, $abschnitt) === false) {
            throw new RuntimeException('Der Statistikabschnitt konnte nicht geschrieben werden.');
        }

        if (fwrite($statistikDatei, implode('', $kennzahlenZeilen)) === false) {
            throw new RuntimeException('Die Statistikdaten konnten nicht geschrieben werden.');
        }
        flock($statistikDatei, LOCK_UN);
    } finally {
        fclose($statistikDatei);
    }

    erzeugeKursinfoSeite();
}

function erzeugeKursinfoSeite(): void
{
    $aktien = leseIsins(INPUT_FILE);
    $zeilen = file(OUTPUT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $letzteZeile = count($zeilen) > 1 ? $zeilen[count($zeilen) - 1] : '';
    $letzterZeitpunkt = $letzteZeile !== '' ? trim((string) str_getcsv($letzteZeile, "\t")[0]) : '';
    $treffer = leseLetzteTreffer($letzterZeitpunkt);
    $statistik = leseLetzteStatistik();
    $nachrichtenCache = [];
    $aktienNachIsin = [];
    foreach ($aktien as $aktie) {
        $aktienNachIsin[$aktie['isin']] = $aktie;
    }
    $newsFuerAktie = static function (array $aktie) use (&$nachrichtenCache): array {
        if (!isset($nachrichtenCache[$aktie['isin']])) {
            $nachrichtenCache[$aktie['isin']] = holeKurznachrichten($aktie);
        }
        return $nachrichtenCache[$aktie['isin']];
    };
    $newsFuer = static function (string $isin) use ($newsFuerAktie, $aktienNachIsin): array {
        return isset($aktienNachIsin[$isin]) ? $newsFuerAktie($aktienNachIsin[$isin]) : [];
    };

    $trefferHtml = '';
    foreach ($treffer as $eintrag) {
        $trefferHtml .= '<div class="result"><strong>' . html($eintrag['bezeichnung']) . '</strong><span>' . html($eintrag['isin']) . '</span><small>MaxDiffStab: ' . html($eintrag['diff']) . ' | MaxAbfall: ' . html($eintrag['abfall']) . '</small>' . erzeugeNachrichtenHtml($newsFuer($eintrag['isin'])) . '</div>';
    }
    if ($trefferHtml === '') {
        $trefferHtml = '<p class="empty">Keine Treffer aus dem letzten Analyse-Durchlauf.</p>';
    }

    $statistikHtml = '';
    foreach ($statistik as $eintrag) {
        $statistikHtml .= '<div class="stat"><strong>' . html($eintrag['kennzahl']) . '</strong><span>' . html($eintrag['wert']) . '</span>' . ($eintrag['aktie'] !== '' ? '<small>' . html($eintrag['aktie']) . '</small>' : '');
        if (preg_match('/^([A-Z0-9]{12})\s*\((.*)\)$/', $eintrag['aktie'], $match) === 1) {
            $statistikHtml .= erzeugeNachrichtenHtml($newsFuerAktie([
                'isin' => $match[1],
                'bezeichnung' => $match[2],
            ]));
        }
        $statistikHtml .= '</div>';
    }
    if ($statistikHtml === '') {
        $statistikHtml = '<p class="empty">Noch keine Statistikinformationen vorhanden.</p>';
    }

    $zeitreihen = [];
    foreach ($aktien as $index => $aktie) {
        $werte = [];
        foreach (array_slice($zeilen, 1) as $zeile) {
            $felder = str_getcsv($zeile, "\t");
            $wert = $felder[$index + 1] ?? '';
            if (isset($felder[0]) && $wert !== '' && strtoupper($wert) !== 'NA' && is_numeric($wert)) {
                $werte[] = ['zeit' => trim($felder[0]), 'wert' => (float) $wert];
            }
        }
        $zeitreihen[] = ['isin' => $aktie['isin'], 'name' => $aktie['bezeichnung'], 'werte' => $werte];
    }

    $axis = leseKursinfoKonfiguration(CONFIG_FILE);
    $datenJson = json_encode($zeitreihen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kursinfo</title><style>' . kursinfoStyles() . '</style></head><body><main><header><div><p class="eyebrow">MARKET TRACKER</p><h1>Kursinfo</h1></div><label for="axisLength">Zeitachse<select id="axisLength"><option>1 Tag</option><option>1 Woche</option><option>1 Monat</option><option>6 Monate</option><option>1 Jahr</option><option>alle Werte</option></select></label></header><section class="overview"><article><h2>Treffer</h2><p class="timestamp">Analyse: ' . html($letzterZeitpunkt ?: 'nicht vorhanden') . '</p>' . $trefferHtml . '</article><article><h2>Statistik</h2><p class="timestamp">Letzter Durchlauf</p>' . $statistikHtml . '</article></section><hr><section><div class="chart-heading"><h2>Kursverlaeufe</h2><span id="chartCount"></span></div><div id="charts" class="charts"></div></section></main><script>const series=' . $datenJson . ';const initialAxis=' . json_encode($axis, JSON_THROW_ON_ERROR) . ';' . kursinfoScript() . '</script></body></html>';
    if (file_put_contents(INFO_PAGE_FILE, $html, LOCK_EX) === false) {
        throw new RuntimeException('Die Kursinfo-Seite konnte nicht geschrieben werden.');
    }
}

/** @return list<array{zeitpunkt:string,isin:string,bezeichnung:string,diff:string,abfall:string}> */
function leseLetzteTreffer(string $zeitpunkt): array
{
    $ergebnis = [];
    foreach (file(HITS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
        $felder = str_getcsv($zeile, "\t");
        if (count($felder) >= 5 && trim($felder[0]) === $zeitpunkt) {
            $ergebnis[] = ['zeitpunkt' => trim($felder[0]), 'isin' => trim($felder[1]), 'bezeichnung' => trim($felder[2]), 'diff' => trim($felder[3]), 'abfall' => trim($felder[4])];
        }
    }
    return $ergebnis;
}

/** @return list<array{kennzahl:string,wert:string,aktie:string}> */
function leseLetzteStatistik(): array
{
    $zeilen = file(STATISTICS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $start = -1;
    foreach ($zeilen as $index => $zeile) {
        if (trim($zeile) === '---') {
            $start = $index;
        }
    }
    if ($start < 0) {
        return [];
    }
    $ergebnis = [];
    foreach (array_slice($zeilen, $start + 2) as $zeile) {
        $felder = str_getcsv($zeile, "\t");
        if (count($felder) >= 4) {
            $ergebnis[] = ['kennzahl' => trim($felder[1]), 'wert' => trim($felder[2]), 'aktie' => trim($felder[3])];
        }
    }
    return $ergebnis;
}

/** @param array{isin:string,bezeichnung:string} $aktie @return list<array{titel:string,url:string,quelle:string}> */
function holeKurznachrichten(array $aktie): array
{
    try {
        $symbol = ermittleYahooSymbol($aktie['isin'], $aktie['bezeichnung']);
        if ($symbol === null) {
            return [];
        }
        $daten = yahooRequest('https://query1.finance.yahoo.com/v1/finance/search?q=' . rawurlencode($symbol));
        $nachrichten = [];
        foreach ($daten['news'] ?? [] as $nachricht) {
            if (count($nachrichten) >= 2 || !isset($nachricht['title'], $nachricht['link'])) {
                break;
            }
            $nachrichten[] = ['titel' => (string) $nachricht['title'], 'url' => (string) $nachricht['link'], 'quelle' => (string) ($nachricht['publisher'] ?? 'Yahoo Finance')];
        }
        if ($nachrichten === []) {
            $nachrichten = holeYahooRssNachrichten($symbol);
        }
        if ($nachrichten === []) {
            $nachrichten = holeGoogleNewsRssNachrichten($aktie['bezeichnung']);
        }
        return $nachrichten;
    } catch (Throwable $exception) {
        error_log("Kurznachrichten übersprungen für {$aktie['isin']}: {$exception->getMessage()}");
        return [];
    }
}

/** @param list<array{titel:string,url:string,quelle:string}> $nachrichten */
function erzeugeNachrichtenHtml(array $nachrichten): string
{
    if ($nachrichten === []) {
        return '<p class="news-empty">Keine Kurznachrichten verfügbar.</p>';
    }
    $html = '<ul class="news">';
    foreach ($nachrichten as $nachricht) {
        $html .= '<li><a href="' . html($nachricht['url']) . '" target="_blank" rel="noopener">' . html($nachricht['titel']) . '</a><small>' . html($nachricht['quelle']) . '</small></li>';
    }
    return $html . '</ul>';
}

function html(string $wert): string
{
    return htmlspecialchars($wert, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function leseKursinfoKonfiguration(string $dateiname): string
{
    foreach (file($dateiname, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
        if (preg_match('/^\s*LaengeZeitachse\s*=\s*(1 Tag|1 Woche|1 Monat|6 Monate|1 Jahr|alle Werte)\s*$/', $zeile, $match) === 1) {
            return $match[1];
        }
    }
    return '1 Monat';
}

function kursinfoStyles(): string
{
        return <<<'CSS'
:root { color-scheme: light; --ink: #17221f; --muted: #687772; --line: #d7dfda; --paper: #f4f7f1; --panel: #ffffff; --accent: #d96c3f; --accent-soft: #f9e2d8; }
* { box-sizing: border-box; }
body { margin: 0; color: var(--ink); background: radial-gradient(circle at 10% 0%, #fff7e8 0, transparent 34rem), var(--paper); font-family: Georgia, 'Times New Roman', serif; }
main { width: min(1480px, calc(100% - 40px)); margin: 0 auto; padding: 34px 0 60px; }
header { display: flex; align-items: end; justify-content: space-between; gap: 24px; margin-bottom: 26px; }
.eyebrow { margin: 0 0 5px; color: var(--accent); font: 700 11px/1.2 Arial, sans-serif; letter-spacing: 2px; }
h1, h2 { margin: 0; font-weight: 400; } h1 { font-size: clamp(36px, 5vw, 64px); line-height: .95; } h2 { font-size: 24px; }
label { display: grid; gap: 7px; color: var(--muted); font: 700 12px Arial, sans-serif; text-transform: uppercase; letter-spacing: 1px; }
select { min-width: 160px; padding: 11px 34px 11px 12px; border: 1px solid var(--line); border-radius: 3px; background: white; color: var(--ink); font: 15px Georgia, serif; }
.overview { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; } article { min-height: 190px; padding: 22px; border: 1px solid var(--line); border-top: 4px solid var(--accent); background: var(--panel); box-shadow: 0 10px 30px rgba(23,34,31,.05); }
.timestamp, .empty, .result span, .result small, .stat span, .stat small, #chartCount { color: var(--muted); font: 12px/1.5 Arial, sans-serif; } .timestamp { margin: 7px 0 16px; }
.result, .stat { display: grid; gap: 4px; padding: 11px 0; border-top: 1px solid var(--line); } .result strong, .stat strong { font-size: 16px; } .result small, .stat small { word-break: break-word; }
.news { display: grid; gap: 4px; margin: 4px 0 0; padding: 0; list-style: none; } .news li { display: grid; gap: 1px; padding-left: 12px; border-left: 2px solid var(--accent-soft); } .news a { color: var(--ink); font: 13px/1.3 Arial, sans-serif; text-decoration: none; } .news a:hover { color: var(--accent); } .news small, .news-empty { font: 10px Arial, sans-serif; color: var(--muted); } .news-empty { margin: 4px 0 0; }
hr { margin: 38px 0 28px; border: 0; border-top: 1px solid var(--line); } .chart-heading { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 14px; } .charts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.chart { min-width: 0; padding: 14px; border: 1px solid var(--line); background: rgba(255,255,255,.78); } .chart h3 { overflow: hidden; margin: 0 0 2px; font-size: 15px; font-weight: 400; text-overflow: ellipsis; white-space: nowrap; } .chart p { margin: 0 0 8px; color: var(--muted); font: 10px Arial, sans-serif; } svg { display: block; width: 100%; height: 150px; overflow: visible; } .gridline { stroke: #e8eeea; stroke-width: 1; } .axis { stroke: #9ca9a3; stroke-width: 1; } .axis-label { fill: var(--muted); font: 9px Arial, sans-serif; } .line { fill: none; stroke: var(--accent); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; } .no-data { display: grid; place-items: center; height: 150px; color: var(--muted); font: 12px Arial, sans-serif; }
@media (max-width: 800px) { main { width: min(100% - 24px, 620px); padding-top: 22px; } header, .overview { grid-template-columns: 1fr; display: grid; } .charts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .charts { grid-template-columns: 1fr; } header { align-items: start; } }
CSS;
}

function kursinfoScript(): string
{
        return <<<'JS'
const ranges = {'1 Tag': 1, '1 Woche': 7, '1 Monat': 31, '6 Monate': 183, '1 Jahr': 365};
const axisLength = document.getElementById('axisLength');
axisLength.value = initialAxis;
function renderCharts() {
    const days = ranges[axisLength.value];
    const cutoff = days ? Date.now() - days * 86400000 : 0;
    const container = document.getElementById('charts');
    container.replaceChildren();
    let shown = 0;
    series.forEach((item) => {
        const values = item.werte.filter((point) => !cutoff || Date.parse(point.zeit.replace(' ', 'T')) >= cutoff);
        const card = document.createElement('article');
        card.className = 'chart';
        const title = document.createElement('h3'); title.textContent = item.name; card.append(title);
        const subtitle = document.createElement('p'); subtitle.textContent = item.isin; card.append(subtitle);
        if (!values.length) { const empty = document.createElement('div'); empty.className = 'no-data'; empty.textContent = 'Keine Werte im Zeitraum'; card.append(empty); container.append(card); return; }
        shown++;
        const width = 340, height = 150, left = 38, right = 7, top = 15, bottom = 25;
        const numbers = values.map((point) => point.wert), min = Math.min(...numbers), max = Math.max(...numbers), span = max - min || 1;
        const plotWidth = width - left - right, plotHeight = height - top - bottom;
        const points = values.map((point, index) => `${left + index * plotWidth / Math.max(values.length - 1, 1)},${top + plotHeight - (point.wert - min) * plotHeight / span}`).join(' ');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', `0 0 ${width} ${height}`); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', `Kursverlauf ${item.name}`);
        [0.25, 0.5, 0.75].forEach((ratio) => { const line = document.createElementNS('http://www.w3.org/2000/svg', 'line'); line.setAttribute('x1', left); line.setAttribute('x2', width - right); line.setAttribute('y1', top + plotHeight * ratio); line.setAttribute('y2', top + plotHeight * ratio); line.classList.add('gridline'); svg.append(line); });
        const xAxis = document.createElementNS('http://www.w3.org/2000/svg', 'line'); xAxis.setAttribute('x1', left); xAxis.setAttribute('x2', width - right); xAxis.setAttribute('y1', top + plotHeight); xAxis.setAttribute('y2', top + plotHeight); xAxis.classList.add('axis'); svg.append(xAxis);
        const yAxis = document.createElementNS('http://www.w3.org/2000/svg', 'line'); yAxis.setAttribute('x1', left); yAxis.setAttribute('x2', left); yAxis.setAttribute('y1', top); yAxis.setAttribute('y2', top + plotHeight); yAxis.classList.add('axis'); svg.append(yAxis);
        function addLabel(text, x, y, anchor) { const label = document.createElementNS('http://www.w3.org/2000/svg', 'text'); label.textContent = text; label.setAttribute('x', x); label.setAttribute('y', y); label.setAttribute('text-anchor', anchor); label.classList.add('axis-label'); svg.append(label); }
        const dateLabel = (value) => value.slice(5, 10).replace('-', '.');
        addLabel(formatNumber(max), left - 5, top + 3, 'end'); addLabel(formatNumber(min), left - 5, top + plotHeight, 'end'); addLabel(dateLabel(values[0].zeit), left, height - 7, 'start'); addLabel(dateLabel(values[values.length - 1].zeit), width - right, height - 7, 'end');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'polyline'); path.setAttribute('points', points); path.classList.add('line'); svg.append(path); card.append(svg); container.append(card);
    });
    document.getElementById('chartCount').textContent = `${shown} von ${series.length} Aktien mit Werten`;
}
function formatNumber(value) { return Number(value).toLocaleString('de-DE', {maximumFractionDigits: 2}); }
axisLength.addEventListener('change', renderCharts); renderCharts();
JS;
}

/** @return list<string> */
function leseLetzteKurszeile(): array
{
    $zeilen = file(OUTPUT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($zeilen === false || count($zeilen) < 2) {
        throw new RuntimeException('Die Kurstabelle enthält noch keine Kurszeile.');
    }

    $letzteZeile = str_getcsv((string) end($zeilen), "\t");
    if (!isset($letzteZeile[0]) || trim($letzteZeile[0]) === '') {
        throw new RuntimeException('Die letzte Kurszeile enthält keinen Zeitstempel.');
    }

    return $letzteZeile;
}

/** @return list<float> */
function leseKurswerteImZeitfenster(
    int $aktienSpalte,
    DateTimeImmutable $fensterAnfang,
    DateTimeImmutable $fensterEnde
): array {
    $zeilen = file(OUTPUT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($zeilen === false || count($zeilen) < 2) {
        throw new RuntimeException('Die Kurstabelle enthält noch keine Kurswerte.');
    }

    $kurswerte = [];
    foreach (array_slice($zeilen, 1) as $zeile) {
        $felder = str_getcsv($zeile, "\t");
        if (!isset($felder[0], $felder[$aktienSpalte])) {
            continue;
        }

        $zeitpunkt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            trim($felder[0]),
            new DateTimeZone(TIMEZONE)
        );
        $kurs = trim($felder[$aktienSpalte]);
        if ($zeitpunkt === false || $zeitpunkt < $fensterAnfang || $zeitpunkt > $fensterEnde) {
            continue;
        }
        if ($kurs === '' || strtoupper($kurs) === 'NA' || !is_numeric($kurs)) {
            continue;
        }

        $kurswerte[] = (float) $kurs;
    }

    if ($kurswerte === []) {
        throw new RuntimeException('Im angeforderten Zeitfenster wurden keine gültigen Kurswerte gefunden.');
    }

    return $kurswerte;
}

/** @return array{tStabilAnfang: int, tStabilEnde: int, tAbfallAnfang: int, tAbfallEnde: int, StabSchwelle: float, AbfallSchwelle: float} */
function leseStabilitaetsKonfiguration(string $dateiname): array
{
    $zeilen = file($dateiname, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($zeilen === false) {
        throw new RuntimeException("Die Konfigurationsdatei '$dateiname' kann nicht gelesen werden.");
    }

    $werte = [];
    foreach ($zeilen as $zeile) {
        if (preg_match('/^\s*(tStabilAnfang|tStabilEnde|tAbfallAnfang|tAbfallEnde)\s*=\s*(\d+)\s*(?:#.*)?$/', $zeile, $treffer) === 1) {
            $werte[$treffer[1]] = (int) $treffer[2];
        } elseif (preg_match('/^\s*(StabSchwelle|AbfallSchwelle)\s*=\s*(-?\d+(?:\.\d+)?)\s*(?:#.*)?$/', $zeile, $treffer) === 1) {
            $werte[$treffer[1]] = (float) $treffer[2];
        }
    }

    $benoetigteWerte = [
        'tStabilAnfang',
        'tStabilEnde',
        'tAbfallAnfang',
        'tAbfallEnde',
        'StabSchwelle',
        'AbfallSchwelle',
    ];
    foreach ($benoetigteWerte as $name) {
        if (!isset($werte[$name])) {
            throw new RuntimeException("Die Konfiguration benötigt $name.");
        }
    }
    if ($werte['tStabilAnfang'] <= $werte['tStabilEnde']) {
        throw new RuntimeException('tStabilAnfang muss größer als tStabilEnde sein.');
    }
    if ($werte['tAbfallAnfang'] <= $werte['tAbfallEnde']) {
        throw new RuntimeException('tAbfallAnfang muss größer als tAbfallEnde sein.');
    }

    return $werte;
}

/** @return list<array{isin: string, bezeichnung: string}> */
function leseIsins(string $dateiname): array
{
    $zeilen = file($dateiname, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($zeilen === false) {
        throw new RuntimeException("Die Eingabedatei '$dateiname' kann nicht gelesen werden.");
    }

    $aktien = [];
    foreach ($zeilen as $zeile) {
        if (preg_match('/^\s*\d+\s*\|\s*([A-Z0-9]{12})\s*\|\s*(.*?)\s*$/i', $zeile, $treffer) === 1) {
            $aktien[] = [
                'isin' => strtoupper($treffer[1]),
                'bezeichnung' => trim($treffer[2]),
            ];
        }
    }

    return $aktien;
}

function ermittleYahooSymbol(string $isin, string $bezeichnung): ?string
{
    $suchbegriffe = [$isin];
    $nameOhneIndex = preg_replace('/\s*\([^)]*\)\s*$/', '', $bezeichnung);
    if (is_string($nameOhneIndex) && $nameOhneIndex !== '') {
        $suchbegriffe[] = $nameOhneIndex;
    }

    foreach ($suchbegriffe as $suchbegriff) {
        $daten = yahooRequest(
            'https://query1.finance.yahoo.com/v1/finance/search?q=' . rawurlencode($suchbegriff)
        );

        foreach ($daten['quotes'] ?? [] as $quote) {
            if (isset($quote['symbol']) && ($quote['quoteType'] ?? '') === 'EQUITY') {
                return (string) $quote['symbol'];
            }
        }
    }

    return null;
}

function ermittleLetztenKurs(string $symbol, string $isin): string
{
    $daten = yahooRequest(
        'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
        . '?range=1d&interval=1d'
    );
    $meta = $daten['chart']['result'][0]['meta'] ?? null;
    $kurs = $meta['regularMarketPrice'] ?? null;

    if (!is_int($kurs) && !is_float($kurs)) {
        throw new RuntimeException("Kein aktueller Kurs für $isin ($symbol) geliefert.");
    }

    return number_format((float) $kurs, 6, '.', '');
}

/** @return array<string, mixed> */
function yahooRequest(string $url): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Die cURL-Anfrage konnte nicht initialisiert werden.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Aktienkurs-Skript/1.0',
    ]);

    $antwort = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $fehler = curl_error($curl);
    curl_close($curl);

    if ($antwort === false || $httpStatus < 200 || $httpStatus >= 300) {
        $details = $fehler !== '' ? ": $fehler" : " (HTTP $httpStatus)";
        throw new RuntimeException("Yahoo-Finance-Anfrage fehlgeschlagen$details.");
    }

    try {
        $daten = json_decode($antwort, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Yahoo Finance lieferte ungültiges JSON.', 0, $exception);
    }

    if (!is_array($daten)) {
        throw new RuntimeException('Yahoo Finance lieferte keine gültigen Daten.');
    }

    return $daten;
}

if (in_array('--generate-info-only', $argv ?? [], true)
    || (PHP_SAPI !== 'cli' && isset($_GET['generate-info-only']) && $_GET['generate-info-only'] === '1')) {
    try {
        erzeugeKursinfoSeite();
        if (PHP_SAPI === 'cli') {
            echo "Kursinfo-Seite wurde aktualisiert." . PHP_EOL;
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Kursinfo-Seite wurde aktualisiert." . PHP_EOL;
        }
        exit(0);
    } catch (Throwable $exception) {
        http_response_code(500);
        error_log('Fehler beim Aktualisieren der Kursinfo: ' . $exception->getMessage());
        echo 'Fehler beim Aktualisieren der Kursinfo: ' . $exception->getMessage() . PHP_EOL;
        exit(1);
    }
}

try {
    if (PHP_SAPI !== 'cli') {
        ignore_user_abort(true);
        set_time_limit(0);
        schreibeStatus('Kursabruf gestartet.');

        if (function_exists('fastcgi_finish_request')) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Kursabruf gestartet. Die Ergebniszeile wird im Hintergrund geschrieben.\n";
            fastcgi_finish_request();
        }
    }

    schreibeKurstabelle();
    schreibeStatus('Kurstabelle wurde erfolgreich ergänzt.');
    analysiereKurstabelleNachErfolg();
    schreibeStatus('Kurstabelle wurde erfolgreich ergänzt und analysiert.');
    error_log('Kurstabelle wurde erfolgreich ergänzt.');
    if (PHP_SAPI === 'cli' || !function_exists('fastcgi_finish_request')) {
        echo "Kurstabelle wurde erfolgreich ergänzt." . PHP_EOL;
    }
} catch (Throwable $exception) {
    http_response_code(500);
    schreibeStatus('Fehler: ' . $exception->getMessage());
    error_log('Fehler: ' . $exception->getMessage());
    exit(1);
}

/** @return list<array{titel:string,url:string,quelle:string}> */
function holeYahooRssNachrichten(string $symbol): array
{
    $url = 'https://feeds.finance.yahoo.com/rss/2.0/headline?s=' . rawurlencode($symbol) . '&region=DE&lang=de-DE';
    $curl = curl_init($url);
    if ($curl === false) {
        return [];
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Aktienkurs-Skript/1.0',
    ]);
    $antwort = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($antwort === false || $httpStatus < 200 || $httpStatus >= 300) {
        return [];
    }
    $xml = simplexml_load_string($antwort);
    if ($xml === false) {
        return [];
    }
    $nachrichten = [];
    foreach ($xml->channel->item ?? [] as $item) {
        if (count($nachrichten) >= 2) {
            break;
        }
        $titel = trim((string) $item->title);
        $link = trim((string) $item->link);
        if ($titel !== '' && $link !== '') {
            $nachrichten[] = ['titel' => $titel, 'url' => $link, 'quelle' => 'Yahoo Finance'];
        }
    }
    return $nachrichten;
}

/** @return list<array{titel:string,url:string,quelle:string}> */
function holeGoogleNewsRssNachrichten(string $bezeichnung): array
{
    $name = preg_replace('/\s*\([^)]*\)\s*$/', '', $bezeichnung) ?: $bezeichnung;
    $url = 'https://news.google.com/rss/search?q=' . rawurlencode($name . ' Aktie') . '&hl=de&gl=DE&ceid=DE:de';
    $curl = curl_init($url);
    if ($curl === false) {
        return [];
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Aktienkurs-Skript/1.0',
    ]);
    $antwort = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($antwort === false || $httpStatus < 200 || $httpStatus >= 300) {
        return [];
    }
    $xml = simplexml_load_string($antwort);
    if ($xml === false) {
        return [];
    }
    $nachrichten = [];
    foreach ($xml->channel->item ?? [] as $item) {
        if (count($nachrichten) >= 2) {
            break;
        }
        $titel = trim((string) $item->title);
        $link = trim((string) $item->link);
        if ($titel !== '' && $link !== '') {
            $nachrichten[] = ['titel' => $titel, 'url' => $link, 'quelle' => 'Google News'];
        }
    }
    return $nachrichten;
}