<?php
declare(strict_types=1);

namespace Tracker\News;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

const CONFIG_FILE = __DIR__ . '/tracker.cfg';
const OUTPUT_FILE = __DIR__ . '/nachrichten.txt';
const TIMEZONE = 'Europe/Berlin';
const REQUEST_TIMEOUT_SECONDS = 30;

/** @return array{feeds: list<string>, haltedauerTage: int} */
function leseKonfiguration(): array
{
    $zeilen = file(CONFIG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($zeilen === false) {
        throw new RuntimeException('Die Konfigurationsdatei kann nicht gelesen werden.');
    }

    $werte = [];
    foreach ($zeilen as $zeile) {
        if (preg_match('/^\s*(NachrichtenFeeds|NachrichtenHaltedauerTage)\s*=\s*(.*?)\s*(?:#.*)?$/', $zeile, $treffer) === 1) {
            $werte[$treffer[1]] = trim($treffer[2]);
        }
    }

    $feeds = array_values(array_filter(array_map('trim', explode(';', $werte['NachrichtenFeeds'] ?? ''))));
    if ($feeds === []) {
        throw new RuntimeException('In tracker.cfg ist kein RSS-Feed konfiguriert.');
    }
    foreach ($feeds as $feed) {
        if (filter_var($feed, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($feed, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException("Ungültige RSS-Feed-URL: $feed");
        }
    }

    $haltedauerTage = filter_var($werte['NachrichtenHaltedauerTage'] ?? null, FILTER_VALIDATE_INT);
    if ($haltedauerTage === false || $haltedauerTage < 1) {
        throw new RuntimeException('NachrichtenHaltedauerTage muss eine ganze Zahl größer als 0 sein.');
    }

    return ['feeds' => $feeds, 'haltedauerTage' => $haltedauerTage];
}

/** @return list<array{id: string, zeitstempel: string, ueberschrift: string, textkoerper: string, quelle: string}> */
function holeNachrichten(string $feedUrl): array
{
    if (!extension_loaded('curl') || !extension_loaded('simplexml')) {
        throw new RuntimeException('Die PHP-Erweiterungen cURL und SimpleXML müssen aktiviert sein.');
    }

    $curl = curl_init($feedUrl);
    if ($curl === false) {
        throw new RuntimeException("Die Anfrage für $feedUrl konnte nicht gestartet werden.");
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Tracker-RSS-Abruf/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, text/xml'],
    ]);
    $antwort = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $fehler = curl_error($curl);

    if ($antwort === false || $httpStatus < 200 || $httpStatus >= 300) {
        $details = $fehler !== '' ? ": $fehler" : " (HTTP $httpStatus)";
        throw new RuntimeException("RSS-Feed konnte nicht abgerufen werden$details.");
    }

    $xml = simplexml_load_string($antwort, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if ($xml === false || !isset($xml->channel)) {
        throw new RuntimeException('Der Feed enthält keine lesbaren RSS-Daten.');
    }

    $nachrichten = [];
    foreach ($xml->channel->item as $item) {
        $ueberschrift = bereinigeText((string) ($item->title ?? ''));
        if ($ueberschrift === '') {
            continue;
        }

        $contentNamespace = 'http://purl.org/rss/1.0/modules/content/';
        $volltext = trim((string) ($item->children($contentNamespace)->encoded ?? ''));
        $beschreibung = $volltext !== '' ? $volltext : (string) ($item->description ?? '');
        $textkoerper = bereinigeText($beschreibung);
        $link = trim((string) ($item->link ?? ''));
        $guid = trim((string) ($item->guid ?? ''));
        $veroeffentlicht = trim((string) ($item->pubDate ?? ''));
        $zeitpunkt = parseZeitpunkt($veroeffentlicht);
        $identitaet = $guid !== '' ? $guid : ($link !== '' ? $link : $ueberschrift . '|' . $zeitpunkt);

        $nachrichten[] = [
            'id' => hash('sha256', $identitaet),
            'zeitstempel' => $zeitpunkt,
            'ueberschrift' => $ueberschrift,
            'textkoerper' => $textkoerper,
            'quelle' => $link !== '' ? $link : $feedUrl,
        ];
    }

    return $nachrichten;
}

function bereinigeText(string $text): string
{
    $text = preg_replace('/<\s*br\s*\/?>|<\/p\s*>/i', "\n", $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

    return trim($text);
}

function parseZeitpunkt(string $veroeffentlicht): string
{
    $timezone = new DateTimeZone(TIMEZONE);
    try {
        $zeitpunkt = $veroeffentlicht !== ''
            ? new DateTimeImmutable($veroeffentlicht, $timezone)
            : new DateTimeImmutable('now', $timezone);
    } catch (Exception) {
        $zeitpunkt = new DateTimeImmutable('now', $timezone);
    }

    return $zeitpunkt->setTimezone($timezone)->format('Y-m-d H:i:s');
}

/** @return list<array{id: string, zeitstempel: string, ueberschrift: string, textkoerper: string, quelle: string}> */
function leseArchiv(string $inhalt): array
{
    $nachrichten = [];
    $bloecke = preg_split('/(?=^ID: [a-f0-9]{64}\R)/m', $inhalt, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($bloecke ?: [] as $block) {
        if (preg_match('/^ID: ([a-f0-9]{64})\R/', $block, $idTreffer) !== 1
            || preg_match('/^Zeitstempel: (.+)$/m', $block, $zeitTreffer) !== 1
            || preg_match('/^Überschrift: (.+)$/m', $block, $titelTreffer) !== 1
            || preg_match('/^Textkörper:\R(.*?)\RQuelle: (.*)$/ms', $block, $textTreffer) !== 1) {
            continue;
        }

        $nachrichten[] = [
            'id' => $idTreffer[1],
            'zeitstempel' => trim($zeitTreffer[1]),
            'ueberschrift' => trim($titelTreffer[1]),
            'textkoerper' => trim($textTreffer[1]),
            'quelle' => trim($textTreffer[2]),
        ];
    }

    return $nachrichten;
}

/** @param list<array{id: string, zeitstempel: string, ueberschrift: string, textkoerper: string, quelle: string}> $neu */
function aktualisiereArchiv(array $neu, int $haltedauerTage): int
{
    $datei = fopen(OUTPUT_FILE, 'c+');
    if ($datei === false) {
        throw new RuntimeException('Die Nachrichtendatei kann nicht geöffnet werden.');
    }

    try {
        if (!flock($datei, LOCK_EX)) {
            throw new RuntimeException('Die Nachrichtendatei kann nicht gesperrt werden.');
        }

        rewind($datei);
        $inhalt = stream_get_contents($datei);
        if ($inhalt === false) {
            throw new RuntimeException('Die Nachrichtendatei kann nicht gelesen werden.');
        }

        $timezone = new DateTimeZone(TIMEZONE);
        $grenze = (new DateTimeImmutable('now', $timezone))->modify("-$haltedauerTage days");
        $archiv = [];
        foreach (array_merge(leseArchiv($inhalt), $neu) as $nachricht) {
            try {
                $zeitpunkt = new DateTimeImmutable($nachricht['zeitstempel'], $timezone);
            } catch (Exception) {
                continue;
            }
            if ($zeitpunkt < $grenze) {
                continue;
            }
            $archiv[$nachricht['id']] = $nachricht;
        }

        uasort($archiv, static fn (array $a, array $b): int => strcmp($b['zeitstempel'], $a['zeitstempel']));
        $ausgabe = '';
        foreach ($archiv as $nachricht) {
            $ausgabe .= 'ID: ' . $nachricht['id'] . PHP_EOL
                . 'Zeitstempel: ' . $nachricht['zeitstempel'] . PHP_EOL
                . 'Überschrift: ' . str_replace(["\r", "\n"], ' ', $nachricht['ueberschrift']) . PHP_EOL
                . 'Textkörper:' . PHP_EOL
                . $nachricht['textkoerper'] . PHP_EOL
                . 'Quelle: ' . str_replace(["\r", "\n"], '', $nachricht['quelle']) . PHP_EOL . PHP_EOL;
        }

        rewind($datei);
        if (!ftruncate($datei, 0) || fwrite($datei, $ausgabe) !== strlen($ausgabe) || !fflush($datei)) {
            throw new RuntimeException('Die Nachrichtendatei konnte nicht vollständig aktualisiert werden.');
        }

        flock($datei, LOCK_UN);
        return count($archiv);
    } finally {
        fclose($datei);
    }
}

function aktualisiereNachrichtenArchiv(): string
{
    $konfiguration = leseKonfiguration();
    $neueNachrichten = [];
    $erfolgreicheFeeds = 0;
    foreach ($konfiguration['feeds'] as $feed) {
        try {
            $neueNachrichten = array_merge($neueNachrichten, holeNachrichten($feed));
            $erfolgreicheFeeds++;
        } catch (Throwable $exception) {
            error_log("Feed übersprungen ($feed): " . $exception->getMessage());
        }
    }

    if ($erfolgreicheFeeds === 0) {
        throw new RuntimeException('Keiner der konfigurierten Feeds konnte abgerufen werden.');
    }

    $anzahl = aktualisiereArchiv($neueNachrichten, $konfiguration['haltedauerTage']);
    return count($neueNachrichten) . ' Nachrichten abgerufen; im Archiv verbleiben ' . $anzahl . ' Nachrichten.';
}

function starteNachrichtenabruf(): void
{
    $cliAufruf = PHP_SAPI === 'cli';
    if (!$cliAufruf) {
        header('Content-Type: text/plain; charset=utf-8');
        set_time_limit(0);
    }

    try {
        echo aktualisiereNachrichtenArchiv() . PHP_EOL;
    } catch (\Throwable $exception) {
        error_log('Fehler beim Nachrichtenabruf: ' . $exception->getMessage());
        if ($cliAufruf) {
            fwrite(STDERR, 'Fehler beim Nachrichtenabruf: ' . $exception->getMessage() . PHP_EOL);
        } else {
            http_response_code(500);
            echo "Fehler beim Nachrichtenabruf. Details stehen im Serverfehlerprotokoll.\n";
        }
        exit(1);
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    starteNachrichtenabruf();
}