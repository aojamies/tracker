<?php
declare(strict_types=1);

namespace Tracker\Categories;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

const NEWS_FILE = __DIR__ . '/nachrichten.txt';
const CATEGORIES_FILE = __DIR__ . '/nachrichten_kategorien.txt';
const KEYWORDS_FILE = __DIR__ . '/nachrichten_stichwoerter.txt';
const OUTPUT_FILE = __DIR__ . '/kategorien_treffer.txt';
const TIMEZONE = 'Europe/Berlin';

function normalisiereText(string $text): string
{
    $text = strtr($text, ['Ä' => 'ä', 'Ö' => 'ö', 'Ü' => 'ü', 'ẞ' => 'ß']);
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

    return ' ' . trim(is_string($text) ? $text : '') . ' ';
}

/** @return list<array{id: string, label: string, shortLabel: string, keywords: list<string>}> */
function leseKategorien(): array
{
    $zeilen = file(CATEGORIES_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($zeilen === false) {
        throw new RuntimeException('nachrichten_kategorien.txt kann nicht gelesen werden.');
    }

    $kategorien = [];
    foreach ($zeilen as $zeile) {
        if (preg_match('/^\s*(\d+)\s+(.+?)\s*$/u', $zeile, $treffer) === 1) {
            $label = trim($treffer[2]);
            $begriffe = preg_split('/\s*,\s*/u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $kategorien[$treffer[1]] = [
                'id' => $treffer[1],
                'label' => $label,
                'keywords' => array_values(array_unique(array_filter(array_map('trim', $begriffe)))),
            ];
        }
    }
    if ($kategorien === []) {
        throw new RuntimeException('Es wurden keine Kategorien gefunden.');
    }

    $synonymZeilen = file(KEYWORDS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($synonymZeilen === false) {
        throw new RuntimeException('nachrichten_stichwoerter.txt kann nicht gelesen werden.');
    }
    foreach ($synonymZeilen as $zeile) {
        if (preg_match('/^\s*(\d+)\s*\|\s*([^|]+?)\s*\|\s*(.*?)\s*$/u', $zeile, $treffer) !== 1) {
            continue;
        }
        if (!isset($kategorien[$treffer[1]])) {
            throw new RuntimeException("Stichwortdatei enthält unbekannte Kategorie {$treffer[1]}.");
        }
        $kurzbezeichnung = trim($treffer[2]);
        if ($kurzbezeichnung === '') {
            throw new RuntimeException("Stichwortdatei enthält eine leere Kurzbezeichnung für Kategorie {$treffer[1]}.");
        }
        $kategorien[$treffer[1]]['shortLabel'] = $kurzbezeichnung;
        $begriffe = preg_split('/\s*;\s*/u', trim($treffer[3]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kategorien[$treffer[1]]['keywords'] = array_values(array_unique([
            ...$kategorien[$treffer[1]]['keywords'],
            ...array_filter(array_map('trim', $begriffe)),
        ]));
    }
    foreach ($kategorien as $kategorie) {
        if (!isset($kategorie['shortLabel'])) {
            throw new RuntimeException("Für Kategorie {$kategorie['id']} fehlt eine Kurzbezeichnung.");
        }
    }

    return array_values($kategorien);
}

/** @return list<array{id: string, title: string, text: string, url: string}> */
function leseNachrichten(): array
{
    $inhalt = file_get_contents(NEWS_FILE);
    if ($inhalt === false) {
        throw new RuntimeException('nachrichten.txt kann nicht gelesen werden.');
    }

    $bloecke = preg_split('/(?=^ID: [a-f0-9]{64}\R)/m', $inhalt, -1, PREG_SPLIT_NO_EMPTY);
    $nachrichten = [];
    foreach ($bloecke ?: [] as $block) {
        if (preg_match('/^ID: ([a-f0-9]{64})\R/m', $block, $idTreffer) !== 1
            || preg_match('/^Überschrift: (.+)$/mu', $block, $titelTreffer) !== 1
            || preg_match('/^Textkörper:\R(.*?)\RQuelle: /msu', $block, $textTreffer) !== 1
            || preg_match('/^Quelle: (.+)$/mu', $block, $quelleTreffer) !== 1) {
            continue;
        }

        $nachrichten[$idTreffer[1]] = [
            'id' => $idTreffer[1],
            'title' => trim($titelTreffer[1]),
            'text' => trim($textTreffer[1]),
            'url' => trim($quelleTreffer[1]),
        ];
    }

    return array_values($nachrichten);
}

/** @param list<array{id: string, label: string, shortLabel: string, keywords: list<string>}> $kategorien
 *  @param list<array{id: string, title: string, text: string, url: string}> $nachrichten
 *  @return list<array{id: string, label: string, matches: list<array{id: string, title: string, url: string, keywords: list<string>}>>>
 */
function findeTreffer(array $kategorien, array $nachrichten): array
{
    $ergebnisse = [];
    foreach ($kategorien as $kategorie) {
        $ergebnisse[$kategorie['id']] = [
            'id' => $kategorie['id'],
            'label' => $kategorie['label'],
            'shortLabel' => $kategorie['shortLabel'],
            'matches' => [],
        ];

        $stichwoerter = [];
        foreach ($kategorie['keywords'] as $begriff) {
            $normalisiert = normalisiereText($begriff);
            if (trim($normalisiert) !== '') {
                $stichwoerter[$normalisiert] = $begriff;
            }
        }

        foreach ($nachrichten as $nachricht) {
            $suchtext = normalisiereText($nachricht['title'] . "\n" . $nachricht['text']);
            $gefundeneBegriffe = [];
            foreach ($stichwoerter as $normalisiert => $original) {
                if (str_contains($suchtext, $normalisiert)) {
                    $gefundeneBegriffe[] = $original;
                }
            }
            if ($gefundeneBegriffe !== []) {
                $ergebnisse[$kategorie['id']]['matches'][] = [
                    'id' => $nachricht['id'],
                    'title' => $nachricht['title'],
                    'url' => $nachricht['url'],
                    'keywords' => $gefundeneBegriffe,
                ];
            }
        }
    }

    return array_values($ergebnisse);
}

/** @param list<array{id: string, label: string, shortLabel: string, matches: list<array{id: string, title: string, url: string, keywords: list<string>}>> $kategorien
 */
function erzeugeErgebnis(array $kategorien, int $nachrichtenAnzahl): string
{
    $zeitpunkt = (new DateTimeImmutable('now', new DateTimeZone(TIMEZONE)))->format('Y-m-d H:i:s');
    $ausgabe = "Lokale Stichwortauswertung\n"
        . "Ausgewertet am: $zeitpunkt\n"
        . "Nachrichten: $nachrichtenAnzahl\n"
        . "Methode: normalisierte Phrasen- und Stichworttreffer; eine Nachricht zählt je Kategorie höchstens einmal.\n\n";

    foreach ($kategorien as $kategorie) {
        $ausgabe .= $kategorie['id'] . ' ' . $kategorie['shortLabel'] . ': ' . count($kategorie['matches']) . PHP_EOL;
        foreach ($kategorie['matches'] as $fundstelle) {
            $ausgabe .= '  Nachrichten-ID: ' . $fundstelle['id'] . PHP_EOL
                . '  Überschrift: ' . str_replace(["\r", "\n"], ' ', $fundstelle['title']) . PHP_EOL
                . '  Fundbegriffe: ' . implode('; ', $fundstelle['keywords']) . PHP_EOL
                . '  Link: ' . str_replace(["\r", "\n"], '', $fundstelle['url']) . PHP_EOL;
        }
        if ($kategorie['matches'] === []) {
            $ausgabe .= "  Keine Fundstellen\n";
        }
        $ausgabe .= PHP_EOL;
    }

    return $ausgabe;
}

function schreibeErgebnis(string $inhalt): void
{
    $datei = fopen(OUTPUT_FILE, 'c+');
    if ($datei === false) {
        throw new RuntimeException('kategorien_treffer.txt kann nicht geöffnet werden.');
    }

    try {
        if (!flock($datei, LOCK_EX)) {
            throw new RuntimeException('kategorien_treffer.txt kann nicht gesperrt werden.');
        }
        rewind($datei);
        if (!ftruncate($datei, 0) || fwrite($datei, $inhalt) !== strlen($inhalt) || !fflush($datei)) {
            throw new RuntimeException('kategorien_treffer.txt konnte nicht vollständig geschrieben werden.');
        }
        flock($datei, LOCK_UN);
    } finally {
        fclose($datei);
    }
}

function aktualisiereNachrichtenKategorien(): string
{
    $kategorien = leseKategorien();
    $nachrichten = leseNachrichten();
    $treffer = findeTreffer($kategorien, $nachrichten);
    $ergebnis = erzeugeErgebnis($treffer, count($nachrichten));
    schreibeErgebnis($ergebnis);
    return $ergebnis;
}

function starteNachrichtenkategorisierung(): void
{
    $cliAufruf = PHP_SAPI === 'cli';
    if (!$cliAufruf) {
        header('Content-Type: text/plain; charset=utf-8');
        set_time_limit(0);
    }

    try {
        echo aktualisiereNachrichtenKategorien();
    } catch (\Throwable $exception) {
        error_log('Fehler bei der lokalen Nachrichtenauswertung: ' . $exception->getMessage());
        if ($cliAufruf) {
            fwrite(STDERR, 'Fehler bei der lokalen Nachrichtenauswertung: ' . $exception->getMessage() . PHP_EOL);
        } else {
            http_response_code(500);
            echo "Auswertung fehlgeschlagen. Details stehen im Serverfehlerprotokoll.\n";
        }
        exit(1);
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    starteNachrichtenkategorisierung();
}