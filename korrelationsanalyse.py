import argparse
import csv
import math
import sys
from datetime import datetime, timedelta
from itertools import combinations
from pathlib import Path


VERZEICHNIS = Path(__file__).resolve().parent
KURSTABELLE = VERZEICHNIS / "kurstabelle.txt"
KONFIGURATION = VERZEICHNIS / "tracker.cfg"
NORMALISIERTE_KURSE = VERZEICHNIS / "normalisierte_kurse.txt"
ERGEBNISDATEI = VERZEICHNIS / "kausalitaet.txt"


def lese_kurstabelle(dateipfad):
    with dateipfad.open(encoding="utf-8-sig", newline="") as datei:
        leser = csv.reader(datei, delimiter="\t")
        kopfzeile = next(leser, None)
        if not kopfzeile or len(kopfzeile) < 3:
            raise ValueError("Die Kurstabelle benötigt Datum und mindestens zwei Aktien.")

        zeilen = []
        for zeilennummer, felder in enumerate(leser, start=2):
            if not felder or not any(felder):
                continue
            if len(felder) != len(kopfzeile):
                raise ValueError(
                    f"Zeile {zeilennummer} hat {len(felder)} statt "
                    f"{len(kopfzeile)} Spalten."
                )
            try:
                zeitpunkt = datetime.fromisoformat(felder[0].strip())
            except ValueError as fehler:
                raise ValueError(
                    f"Ungültiger Zeitstempel in Zeile {zeilennummer}: {felder[0]}"
                ) from fehler

            kurse = []
            for feld in felder[1:]:
                try:
                    kurs = float(feld)
                except ValueError:
                    kurs = math.nan
                kurse.append(kurs if math.isfinite(kurs) and kurs > 0 else None)
            zeilen.append((zeitpunkt, kurse))

    if not zeilen:
        raise ValueError("Die Kurstabelle enthält keine Kurszeilen.")
    zeilen.sort(key=lambda zeile: zeile[0])
    return kopfzeile, zeilen


def lese_korrelationsfenster(dateipfad):
    einstellungen = {}
    with dateipfad.open(encoding="utf-8-sig") as datei:
        for zeilennummer, zeile in enumerate(datei, start=1):
            zeile = zeile.strip()
            if not zeile or zeile.startswith("#"):
                continue
            if "=" not in zeile:
                raise ValueError(
                    f"Ungültiger Eintrag in {dateipfad.name}, Zeile {zeilennummer}."
                )
            schluessel, wert = zeile.split("=", maxsplit=1)
            einstellungen[schluessel.strip()] = wert.strip()

    try:
        tage = int(einstellungen["KorrelationsfensterTage"])
    except KeyError as fehler:
        raise ValueError(
            f"KorrelationsfensterTage fehlt in {dateipfad.name}."
        ) from fehler
    except ValueError as fehler:
        raise ValueError(
            f"KorrelationsfensterTage in {dateipfad.name} muss eine ganze Zahl sein."
        ) from fehler

    if tage < 0:
        raise ValueError("KorrelationsfensterTage darf nicht negativ sein.")
    return tage


def resample_tageskurse(kopfzeile, zeilen):
    kurswerte_nach_tag = {}
    for zeitpunkt, kurse in zeilen:
        tageskurse = kurswerte_nach_tag.setdefault(
            zeitpunkt.date(), [[] for _ in kopfzeile[1:]]
        )
        for index, kurs in enumerate(kurse):
            if kurs is not None:
                tageskurse[index].append(kurs)

    erster_tag = zeilen[0][0].date()
    letzter_tag = zeilen[-1][0].date()
    tage = [
        erster_tag + timedelta(days=offset)
        for offset in range((letzter_tag - erster_tag).days + 1)
    ]
    tageswerte = []
    for tag in tage:
        kurslisten = kurswerte_nach_tag.get(tag, [[] for _ in kopfzeile[1:]])
        tageswerte.append(
            [sum(kurse) / len(kurse) if kurse else None for kurse in kurslisten]
        )

    for index, aktie in enumerate(kopfzeile[1:]):
        gueltige_indizes = [
            tagindex
            for tagindex, kurse in enumerate(tageswerte)
            if kurse[index] is not None
        ]
        if not gueltige_indizes:
            raise ValueError(f"Für {aktie} gibt es keinen gültigen Kurs.")

        erster_gueltiger = gueltige_indizes[0]
        letzter_gueltiger = gueltige_indizes[-1]
        for tagindex in range(erster_gueltiger):
            tageswerte[tagindex][index] = tageswerte[erster_gueltiger][index]

        for links, rechts in zip(gueltige_indizes, gueltige_indizes[1:]):
            kurs_links = tageswerte[links][index]
            kurs_rechts = tageswerte[rechts][index]
            abstand = rechts - links
            for tagindex in range(links + 1, rechts):
                anteil = (tagindex - links) / abstand
                tageswerte[tagindex][index] = kurs_links + anteil * (
                    kurs_rechts - kurs_links
                )

        for tagindex in range(letzter_gueltiger + 1, len(tage)):
            tageswerte[tagindex][index] = tageswerte[letzter_gueltiger][index]

    return [
        (datetime.combine(tag, datetime.min.time()), kurse)
        for tag, kurse in zip(tage, tageswerte)
    ]


def normalisiere_kurse(kopfzeile, zeilen):
    letzte_kurse = []
    for spaltenindex in range(len(kopfzeile) - 1):
        letzter_kurs = next(
            (
                zeile[1][spaltenindex]
                for zeile in reversed(zeilen)
                if zeile[1][spaltenindex] is not None
            ),
            None,
        )
        if letzter_kurs is None:
            raise ValueError(f"Für {kopfzeile[spaltenindex + 1]} gibt es keinen gültigen Kurs.")
        letzte_kurse.append(letzter_kurs)

    normalisiert = [
        (
            zeitpunkt,
            [
                kurs / letzte_kurse[index] if kurs is not None else None
                for index, kurs in enumerate(kurse)
            ],
        )
        for zeitpunkt, kurse in zeilen
    ]
    return normalisiert


def schreibe_normalisierte_kurse(dateipfad, kopfzeile, zeilen):
    with dateipfad.open("w", encoding="utf-8", newline="") as datei:
        schreiber = csv.writer(datei, delimiter="\t", lineterminator="\n")
        schreiber.writerow(kopfzeile)
        for zeitpunkt, kurse in zeilen:
            schreiber.writerow(
                [zeitpunkt.isoformat(sep=" ")]
                + ["" if kurs is None else f"{kurs:.10f}" for kurs in kurse]
            )


def berechne_log_renditen(werte):
    renditen = []
    for vorher, aktuell in zip(werte, werte[1:]):
        renditen.append(math.log(aktuell / vorher))
    return renditen


def pearson_korrelation(erste, zweite):
    anzahl = len(erste)
    mittelwert_erste = sum(erste) / anzahl
    mittelwert_zweite = sum(zweite) / anzahl
    kreuzsumme = sum(
        (wert_erste - mittelwert_erste) * (wert_zweite - mittelwert_zweite)
        for wert_erste, wert_zweite in zip(erste, zweite)
    )
    quadratsumme_erste = sum((wert - mittelwert_erste) ** 2 for wert in erste)
    quadratsumme_zweite = sum((wert - mittelwert_zweite) ** 2 for wert in zweite)
    nenner = math.sqrt(quadratsumme_erste * quadratsumme_zweite)
    return kreuzsumme / nenner if nenner else None


def granger_test(ziel, quelle, max_lag):
    from numpy.linalg import LinAlgError
    from statsmodels.tsa.stattools import grangercausalitytests

    if len(ziel) <= 3 * max_lag + 1:
        return None, None
    try:
        tests = grangercausalitytests(list(zip(ziel, quelle)), maxlag=max_lag)
    except (ValueError, ZeroDivisionError, LinAlgError):
        return None, None

    beste_lagzahl, beste_p_value = min(
        (
            lagzahl,
            ergebnisse[0]["ssr_ftest"][1],
        )
        for lagzahl, ergebnisse in tests.items()
    )
    return beste_lagzahl, beste_p_value


def analysiere(kopfzeile, zeilen, tage, max_lag, ergebnisdatei, normdatei):
    tageskurse = resample_tageskurse(kopfzeile, zeilen)
    normalisiert = normalisiere_kurse(kopfzeile, tageskurse)
    schreibe_normalisierte_kurse(normdatei, kopfzeile, normalisiert)

    neuester_zeitpunkt = normalisiert[-1][0]
    zeitraumstart = neuester_zeitpunkt - timedelta(days=tage) if tage else None
    fenster = [
        (zeitpunkt, kurse)
        for zeitpunkt, kurse in normalisiert
        if zeitraumstart is None or zeitpunkt >= zeitraumstart
    ]
    aktien = kopfzeile[1:]
    aktienpaare = list(combinations(range(len(aktien)), 2))
    ergebnisse = []

    for nummer, (index_a, index_b) in enumerate(aktienpaare, start=1):
        gemeinsame_kurse = [
            (kurse[index_a], kurse[index_b])
            for _, kurse in fenster
            if kurse[index_a] is not None and kurse[index_b] is not None
        ]
        renditen_a = berechne_log_renditen([paar[0] for paar in gemeinsame_kurse])
        renditen_b = berechne_log_renditen([paar[1] for paar in gemeinsame_kurse])
        korrelation = (
            pearson_korrelation(renditen_a, renditen_b)
            if len(renditen_a) >= 2
            else None
        )
        lag_b_nach_a, p_b_nach_a = granger_test(renditen_a, renditen_b, max_lag)
        lag_a_nach_b, p_a_nach_b = granger_test(renditen_b, renditen_a, max_lag)
        ergebnisse.append(
            (
                aktien[index_a],
                aktien[index_b],
                len(renditen_a),
                korrelation,
                lag_b_nach_a,
                p_b_nach_a,
                lag_a_nach_b,
                p_a_nach_b,
            )
        )
        print(
            f"\rAnalyse {nummer}/{len(aktienpaare)}: "
            f"{aktien[index_a]} / {aktien[index_b]}",
            end="",
            flush=True,
        )
    if aktienpaare:
        print()

    with ergebnisdatei.open("w", encoding="utf-8", newline="") as datei:
        datei.write(f"Zeitfenster (Tage): {tage if tage else 'gesamte Historie'}\n")
        datei.write(f"Daten von: {fenster[0][0].isoformat(sep=' ')}\n")
        datei.write(f"Daten bis: {neuester_zeitpunkt.isoformat(sep=' ')}\n")
        datei.write(f"Maximale Lagzahl: {max_lag}\n")
        datei.write(
            "Korrelation basiert auf synchronen Log-Renditen; "
            "Granger-Tests prüfen beide Richtungen der Log-Renditen.\n"
        )
        datei.write(
            "Aktie A\tAktie B\tBeobachtungen\tPearson r\t"
            "Korrelationsrichtung\tGranger B->A (Lag; p)\t"
            "Granger A->B (Lag; p)\n"
        )
        for aktie_a, aktie_b, anzahl, r, lag_b_a, p_b_a, lag_a_b, p_a_b in ergebnisse:
            if r is None:
                richtung = "nicht bestimmbar"
            elif r > 0:
                richtung = "positiv"
            elif r < 0:
                richtung = "negativ"
            else:
                richtung = "keine"
            datei.write(
                f"{aktie_a}\t{aktie_b}\t{anzahl}\t"
                f"{'n/a' if r is None else f'{r:.6f}'}\t{richtung}\t"
                f"{formatiere_granger(lag_b_a, p_b_a)}\t"
                f"{formatiere_granger(lag_a_b, p_a_b)}\n"
            )
    print(f"Normalisierte Kursverläufe: {normdatei}")
    print(f"Analyseergebnisse: {ergebnisdatei}")


def formatiere_granger(lagzahl, p_value):
    if lagzahl is None or p_value is None:
        return "nicht bestimmbar"
    return f"Lag {lagzahl}; p={p_value:.6g}"


def erstelle_parser():
    parser = argparse.ArgumentParser(
        description="Normalisiert Kursverläufe und analysiert Korrelation und Granger-Kausalität."
    )
    parser.add_argument(
        "--max-lag",
        type=int,
        default=5,
        help="Maximale Lagzahl für den Granger-Test (Standard: 5).",
    )
    parser.add_argument("--quelle", type=Path, default=KURSTABELLE, help="Eingabedatei.")
    parser.add_argument(
        "--normalisiert", type=Path, default=NORMALISIERTE_KURSE, help="Ausgabe der normalisierten Kurse."
    )
    parser.add_argument(
        "--ergebnis", type=Path, default=ERGEBNISDATEI, help="Textdatei für die Testergebnisse."
    )
    return parser


def main():
    parser = erstelle_parser()
    argumente = parser.parse_args()
    if argumente.max_lag < 1:
        parser.error("--max-lag muss mindestens 1 sein.")

    try:
        tage = lese_korrelationsfenster(KONFIGURATION)
        kopfzeile, zeilen = lese_kurstabelle(argumente.quelle)
        analysiere(
            kopfzeile,
            zeilen,
            tage,
            argumente.max_lag,
            argumente.ergebnis,
            argumente.normalisiert,
        )
    except (OSError, ValueError, ImportError) as fehler:
        print(f"Fehler: {fehler}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())