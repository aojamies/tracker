# Aktienkurs-Tracker

## Zweck

`kursabruf.php` ruft aktuelle Kurse für alle Einträge aus `aktienliste.txt` ab. Die Kurse werden in `kurstabelle.txt` gespeichert und anschließend analysiert. Dabei entstehen Treffer- und Statistikdaten. Aus diesen Daten wird `kursinfo.html` erzeugt.

Die Kursdaten und Nachrichten stammen von Yahoo Finance. Für Nachrichten werden zusätzlich Yahoo-RSS und Google-News-RSS als Fallback verwendet.

## Voraussetzungen

- PHP 8.1 oder neuer
- PHP-Erweiterung `curl`
- PHP-Erweiterung `SimpleXML` für RSS-Nachrichten
- Internetzugang zum Abruf der Kurs- und Nachrichtendaten
- Schreibrechte für das Projektverzeichnis

Unter Windows muss die PHP-Erweiterung in `php.ini` aktiviert sein, zum Beispiel:

```ini
extension_dir = "D:\prog\php_8511\ext"
extension=curl
```

Falls keine `php.ini` geladen wird, kann die Erweiterung einmalig beim Aufruf aktiviert werden:

```powershell
& "D:\prog\php_8511\php.exe" -d extension="D:\prog\php_8511\ext\php_curl.dll" kursabruf.php
```

## Vollständiger Kursabruf

### Windows PowerShell

```powershell
& "D:\prog\php_8511\php.exe" kursabruf.php
```

Wenn `php.exe` im `PATH` eingetragen ist:

```powershell
php kursabruf.php
```

### Windows Eingabeaufforderung

```cmd
D:\prog\php_8511\php.exe kursabruf.php
```

### Linux oder macOS

```bash
php kursabruf.php
```

Der vollständige Ablauf ist:

1. `aktienliste.txt` wird eingelesen.
2. Für jede Aktie wird ein Yahoo-Finance-Symbol ermittelt.
3. Der aktuelle Kurs wird abgerufen.
4. Eine neue Zeitzeile wird an `kurstabelle.txt` angehängt.
5. Die Analyse prüft die Stabilitäts- und Abfallfenster.
6. Treffer werden an `treffer.txt` angehängt.
7. Die Kennzahlen werden an `statistik.txt` angehängt.
8. `kursinfo.html` wird neu erzeugt.

Eine erfolgreiche CLI-Ausgabe lautet:

```text
Kurstabelle wurde erfolgreich ergänzt.
```

Beim Webserver wird bei aktivem FastCGI zunächst Folgendes ausgegeben; der eigentliche Abruf läuft danach weiter:

```text
Kursabruf gestartet. Die Ergebniszeile wird im Hintergrund geschrieben.
```

## Nur die Kursinfo-Seite aktualisieren

Dieser Modus liest die bereits vorhandenen Kurs-, Treffer- und Statistikdateien. Es wird kein neuer Kurs abgerufen.

### Windows PowerShell

```powershell
& "D:\prog\php_8511\php.exe" kursabruf.php --generate-info-only
```

### Windows Eingabeaufforderung

```cmd
D:\prog\php_8511\php.exe kursabruf.php --generate-info-only
```

### Linux oder macOS

```bash
php kursabruf.php --generate-info-only
```

Ausgabe bei Erfolg:

```text
Kursinfo-Seite wurde aktualisiert.
```

### Browser/Webserver

```text
https://example.org/tracker/kursabruf.php?generate-info-only=1
```

Der Browseraufruf erzeugt ebenfalls nur `kursinfo.html`. Der Parameter muss exakt `generate-info-only=1` lauten. Ein Fehler wird mit HTTP-Status 500 und einer Fehlermeldung ausgegeben.

Der Webserver muss Schreibrechte auf `kursinfo.html` und die übrigen Datendateien besitzen. Der Parameter sollte nicht ungeschützt öffentlich erreichbar sein, wenn beliebige Besucher die Seite neu erzeugen können. Geeignet sind zum Beispiel HTTP-Authentifizierung, eine interne URL oder eine Zugriffsbeschränkung auf die eigene IP-Adresse.

## Konfiguration

Die Datei `tracker.cfg` enthält unter anderem:

```ini
tStabilAnfang=20
tStabilEnde=5
tAbfallAnfang=4
tAbfallEnde=0
StabSchwelle=0.03
AbfallSchwelle=-0.05
LaengeZeitachse=1 Monat
```

`LaengeZeitachse` legt die anfängliche Auswahl in `kursinfo.html` fest. Zulässige Werte sind:

- `1 Tag`
- `1 Woche`
- `1 Monat`
- `6 Monate`
- `1 Jahr`
- `alle Werte`

Die Auswahl kann anschließend direkt in `kursinfo.html` geändert werden. Die Grafiken zeigen je Aktie Kurs-Minimum und Kurs-Maximum an der y-Achse sowie das erste und letzte Datum des gewählten Zeitraums an der x-Achse.

## Ausgabedateien

| Datei | Inhalt |
|---|---|
| `kurstabelle.txt` | Tab-getrennte historische Kurswerte mit Zeitstempel |
| `treffer.txt` | Aktien, die den Analysebedingungen entsprochen haben |
| `statistik.txt` | Verlauf der Kennzahlen und betroffene Aktien |
| `kursinfo.html` | Anwenderseite mit Treffern, Statistik, Nachrichten und Grafiken |
| `kurstabelle.status.txt` | Status und Fehler des normalen Webabrufs, falls vorhanden |

## Kursinfo-Seite

`kursinfo.html` kann direkt im Browser geöffnet oder vom Webserver ausgeliefert werden. Oben stehen:

- links die Treffer des neuesten Analyse-Durchlaufs einschließlich Nachrichten,
- rechts die neuesten Statistikwerte einschließlich Nachrichten zu den genannten Aktien.

Darunter erscheinen alle Aktien aus `aktienliste.txt` in drei Spalten. Für Aktien ohne gültige Werte im ausgewählten Zeitraum wird eine leere Datenmeldung angezeigt.

## Fehlerbehebung

### `Die PHP-Erweiterung cURL ist nicht aktiviert.`

`curl` in `php.ini` aktivieren oder PHP beim Aufruf mit `-d extension=...` starten. Mit folgendem Befehl lässt sich die geladene Konfiguration prüfen:

```powershell
& "D:\prog\php_8511\php.exe" --ini
```

### `Yahoo-Finance-Anfrage fehlgeschlagen`

Internetverbindung, DNS, Firewall und die Erreichbarkeit der Yahoo-Endpunkte prüfen. Ein einzelner fehlender Nachrichtendienst verhindert die Seitenerzeugung nicht; dann wird der nächste Nachrichten-Fallback versucht.

### Die HTML-Seite ist nicht aktuell

Den reinen Generierungsmodus ausführen:

```powershell
& "D:\prog\php_8511\php.exe" kursabruf.php --generate-info-only
```

Danach die Seite im Browser mit einer Aktualisierung ohne Cache neu laden.

### Keine Treffer

Das ist ein gültiger Zustand. `kursinfo.html` zeigt dann `Keine Treffer aus dem letzten Analyse-Durchlauf.` an. Treffer werden nur geschrieben, wenn die Bedingungen aus `tracker.cfg` erfüllt sind.
