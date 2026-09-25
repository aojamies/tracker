<?php
declare(strict_types=1);

const INPUT_FILE = __DIR__ . '/aktienliste.txt';
const OUTPUT_FILE = __DIR__ . '/kurstabelle.txt';
const HITS_FILE = __DIR__ . '/treffer.txt';
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

    if ($treffer === []) {
        return;
    }

    $datei = fopen(HITS_FILE, 'ab');
    if ($datei === false) {
        throw new RuntimeException('Die Trefferdatei kann nicht geöffnet werden.');
    }

    try {
        if (!flock($datei, LOCK_EX)) {
            throw new RuntimeException('Die Trefferdatei kann nicht gesperrt werden.');
        }
        if (fwrite($datei, implode('', $treffer)) === false) {
            throw new RuntimeException('Die Treffer konnten nicht geschrieben werden.');
        }
        flock($datei, LOCK_UN);
    } finally {
        fclose($datei);
    }
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