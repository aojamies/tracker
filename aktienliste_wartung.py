import json
import os
import re
import tempfile
from collections import defaultdict
from decimal import Decimal, InvalidOperation
from math import isfinite
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen


AKTIENLISTE = Path(__file__).resolve().parent / "aktienliste.txt"
KURSTABELLE = Path(__file__).resolve().parent / "kurstabelle.txt"
ZEILENMUSTER = re.compile(
    r"^\s*(\d+)\s*\|\s*([A-Z0-9]{12})\s*\|\s*([^|]*?)\s*(?:\|\s*([^|]*))?\s*$",
    re.IGNORECASE,
)


def lese_aktien():
    aktien = []
    with AKTIENLISTE.open(encoding="utf-8-sig") as datei:
        for zeilenindex, zeile in enumerate(datei):
            treffer = ZEILENMUSTER.match(zeile.rstrip("\r\n"))
            if treffer:
                aktien.append(
                    {
                        "nummer": int(treffer.group(1)),
                        "isin": treffer.group(2).upper(),
                        "bezeichnung": treffer.group(3).strip(),
                        "branche": (treffer.group(4) or "").strip(),
                        "zeilennummer": zeilenindex + 1,
                        "index": len(aktien),
                        "zeilenindex": zeilenindex,
                    }
                )

    if not aktien:
        raise ValueError(f"Keine Aktien in {AKTIENLISTE.name} gefunden.")
    return aktien


def gruppiere_duplikate(aktien, feld, normalisiere=lambda wert: wert):
    gruppen = defaultdict(list)
    for aktie in aktien:
        wert = normalisiere(aktie[feld])
        if wert:
            gruppen[wert].append(aktie)
    return [gruppe for gruppe in gruppen.values() if len(gruppe) > 1]


def zeige_duplikate(aktien):
    isin_duplikate = gruppiere_duplikate(aktien, "isin")
    namens_duplikate = gruppiere_duplikate(
        aktien,
        "bezeichnung",
        lambda wert: " ".join(wert.split()).casefold(),
    )

    if not isin_duplikate and not namens_duplikate:
        print("Keine Duplikate nach ISIN oder Aktienbezeichnung gefunden.")
        return

    for titel, gruppen, feld in (
        ("Doppelte ISINs", isin_duplikate, "isin"),
        ("Doppelte Aktienbezeichnungen", namens_duplikate, "bezeichnung"),
    ):
        if not gruppen:
            continue
        print(f"\n{titel}:")
        for gruppe in gruppen:
            print(f"  {gruppe[0][feld]}:")
            for aktie in gruppe:
                print(
                    f"    Nr. {aktie['nummer']} (Dateizeile {aktie['zeilennummer']}): "
                    f"{aktie['isin']} | {aktie['bezeichnung']}"
                )


def pruefe_alphabetische_reihenfolge(aktien):
    abweichungen = []
    for vorher, nachher in zip(aktien, aktien[1:]):
        if vorher["bezeichnung"].casefold() > nachher["bezeichnung"].casefold():
            abweichungen.append((vorher, nachher))

    if not abweichungen:
        print("Die Aktienbezeichnungen sind alphabetisch aufsteigend sortiert.")
        return

    print("Die Reihenfolge ist an folgenden Stellen nicht alphabetisch:")
    for vorher, nachher in abweichungen:
        print(
            f"  Nr. {vorher['nummer']} {vorher['bezeichnung']} steht vor "
            f"Nr. {nachher['nummer']} {nachher['bezeichnung']}"
        )


def teile_zeilenende(zeile):
    if zeile.endswith("\r\n"):
        return zeile[:-2], "\r\n"
    if zeile.endswith(("\n", "\r")):
        return zeile[:-1], zeile[-1:]
    return zeile, ""


def schreibe_temporar(dateipfad, inhalt):
    datei_handle, temp_name = tempfile.mkstemp(
        prefix=f".{dateipfad.name}.", suffix=".tmp", dir=dateipfad.parent
    )
    try:
        with os.fdopen(datei_handle, "wb") as datei:
            datei.write(inhalt)
            datei.flush()
            os.fsync(datei.fileno())
    except Exception:
        Path(temp_name).unlink(missing_ok=True)
        raise
    return Path(temp_name)


def aktualisiere_nummer(zeile, nummer):
    nummernfeld = re.match(r"^(\s*)(\d+)(\s*)\|", zeile)
    if nummernfeld is None:
        raise ValueError("Eine Aktienzeile hat eine ungültige Nummerierung.")
    feldbreite = len(nummernfeld.group(2)) + len(nummernfeld.group(3))
    nummerntext = str(nummer).ljust(feldbreite)
    return f"{nummernfeld.group(1)}{nummerntext}|" + zeile[nummernfeld.end() :]


def bereite_aktienliste_vor(aktien, zu_entfernende_aktie):
    original = AKTIENLISTE.read_bytes()
    hat_bom = original.startswith(b"\xef\xbb\xbf")
    text = original.decode("utf-8-sig")
    neue_zeilen = []
    neue_nummer = 1

    for index, zeile in enumerate(text.splitlines(keepends=True)):
        inhalt, zeilenende = teile_zeilenende(zeile)
        treffer = ZEILENMUSTER.match(inhalt)
        if treffer:
            if index == zu_entfernende_aktie["zeilenindex"]:
                continue
            inhalt = aktualisiere_nummer(inhalt, neue_nummer)
            neue_nummer += 1
        neue_zeilen.append(inhalt + zeilenende)

    if neue_nummer != len(aktien):
        raise ValueError("Die Aktienliste wurde während des Vorgangs verändert.")
    return ("\ufeff" if hat_bom else "") + "".join(neue_zeilen)


def lese_und_valide_kurstabelle(aktien):
    original = KURSTABELLE.read_bytes()
    hat_bom = original.startswith(b"\xef\xbb\xbf")
    text = original.decode("utf-8-sig")
    zeilen = text.splitlines(keepends=True)
    if not zeilen:
        raise ValueError("Die Kurstabelle ist leer.")

    kopfzeile, _ = teile_zeilenende(zeilen[0])
    kopffelder = kopfzeile.split("\t")
    if len(kopffelder) != len(aktien) + 1:
        raise ValueError(
            "Die Anzahl der Spalten in kurstabelle.txt passt nicht zur Aktienliste. "
            "Es wurden keine Dateien geändert."
        )
    if kopffelder[1:] != [aktie["bezeichnung"] for aktie in aktien]:
        raise ValueError(
            "Die Spaltenüberschriften in kurstabelle.txt entsprechen nicht der "
            "Reihenfolge in aktienliste.txt. Es wurden keine Dateien geändert."
        )

    for zeilennummer, zeile in enumerate(zeilen[1:], start=2):
        inhalt, _ = teile_zeilenende(zeile)
        if inhalt and len(inhalt.split("\t")) != len(kopffelder):
            raise ValueError(
                f"Zeile {zeilennummer} in kurstabelle.txt hat eine unerwartete "
                "Spaltenanzahl. Es wurden keine Dateien geändert."
            )

    return original, hat_bom, zeilen, kopffelder


def bereite_kurstabelle_vor(aktien, zu_entfernende_aktie):
    original, hat_bom, zeilen, kopffelder = lese_und_valide_kurstabelle(aktien)
    spaltenindex = zu_entfernende_aktie["index"] + 1
    neue_zeilen = []
    for zeile in zeilen:
        inhalt, zeilenende = teile_zeilenende(zeile)
        if not inhalt:
            neue_zeilen.append(zeile)
            continue
        felder = inhalt.split("\t")
        del felder[spaltenindex]
        neue_zeilen.append("\t".join(felder) + zeilenende)

    neues_text = "".join(neue_zeilen)
    return ((b"\xef\xbb\xbf" if hat_bom else b"") + neues_text.encode("utf-8"), original)


def bereite_aktienliste_mit_verschobener_aktie_vor(aktien, aktie, zielposition):
    original = AKTIENLISTE.read_bytes()
    hat_bom = original.startswith(b"\xef\xbb\xbf")
    zeilen = original.decode("utf-8-sig").splitlines(keepends=True)
    neue_reihenfolge = list(aktien)
    bewegte_aktie = neue_reihenfolge.pop(aktie["index"])
    neue_reihenfolge.insert(zielposition, bewegte_aktie)
    neue_zeilen = []
    aktienindex = 0

    for zeile in zeilen:
        inhalt, zeilenende = teile_zeilenende(zeile)
        if ZEILENMUSTER.match(inhalt):
            quelle = neue_reihenfolge[aktienindex]
            quellinhalt, _ = teile_zeilenende(zeilen[quelle["zeilenindex"]])
            neue_zeilen.append(aktualisiere_nummer(quellinhalt, aktienindex + 1) + zeilenende)
            aktienindex += 1
        else:
            neue_zeilen.append(zeile)

    if aktienindex != len(aktien):
        raise ValueError("Die Aktienliste wurde während des Vorgangs verändert.")
    return ("\ufeff" if hat_bom else "") + "".join(neue_zeilen)


def aktualisiere_bezeichnung(zeile, bezeichnung):
    prefix = re.match(r"^(\s*\d+\s*\|\s*[A-Z0-9]{12}\s*\|\s*)", zeile)
    if prefix is None:
        raise ValueError("Eine Aktienzeile hat ein ungültiges Format.")
    ende_bezeichnung = zeile.find("|", prefix.end())
    if ende_bezeichnung < 0:
        raise ValueError("In einer Aktienzeile fehlt die Branche.")
    breite = ende_bezeichnung - prefix.end()
    if len(bezeichnung) > breite:
        raise ValueError(f"Die Bezeichnung darf höchstens {breite} Zeichen lang sein.")
    return prefix.group(1) + bezeichnung.ljust(breite) + zeile[ende_bezeichnung:]


def bereite_aktienliste_mit_neuer_bezeichnung_vor(aktien, aktie, bezeichnung):
    original = AKTIENLISTE.read_bytes()
    hat_bom = original.startswith(b"\xef\xbb\xbf")
    zeilen = original.decode("utf-8-sig").splitlines(keepends=True)
    neue_reihenfolge = list(aktien)
    geaenderte_aktie = dict(neue_reihenfolge.pop(aktie["index"]))
    geaenderte_aktie["bezeichnung"] = bezeichnung
    zielposition = next(
        (
            index
            for index, andere_aktie in enumerate(neue_reihenfolge)
            if andere_aktie["bezeichnung"].casefold() > bezeichnung.casefold()
        ),
        len(neue_reihenfolge),
    )
    neue_reihenfolge.insert(zielposition, geaenderte_aktie)
    neue_zeilen = []
    aktienindex = 0

    for zeile in zeilen:
        inhalt, zeilenende = teile_zeilenende(zeile)
        if ZEILENMUSTER.match(inhalt):
            quelle = neue_reihenfolge[aktienindex]
            quellinhalt, _ = teile_zeilenende(zeilen[quelle["zeilennummer"] - 1])
            if quelle["index"] == aktie["index"]:
                quellinhalt = aktualisiere_bezeichnung(quellinhalt, bezeichnung)
            neue_zeilen.append(aktualisiere_nummer(quellinhalt, aktienindex + 1) + zeilenende)
            aktienindex += 1
        else:
            neue_zeilen.append(zeile)

    if aktienindex != len(aktien):
        raise ValueError("Die Aktienliste wurde während des Vorgangs verändert.")
    return ("\ufeff" if hat_bom else "") + "".join(neue_zeilen), zielposition


def bereite_kurstabelle_mit_verschobener_aktie_vor(aktien, aktie, zielposition):
    original, hat_bom, zeilen, _ = lese_und_valide_kurstabelle(aktien)
    neue_reihenfolge = list(range(len(aktien)))
    bewegte_spalte = neue_reihenfolge.pop(aktie["index"])
    neue_reihenfolge.insert(zielposition, bewegte_spalte)
    neue_zeilen = []

    for zeile in zeilen:
        inhalt, zeilenende = teile_zeilenende(zeile)
        if not inhalt:
            neue_zeilen.append(zeile)
            continue
        felder = inhalt.split("\t")
        neue_felder = [felder[0]] + [felder[index + 1] for index in neue_reihenfolge]
        neue_zeilen.append("\t".join(neue_felder) + zeilenende)

    neues_text = "".join(neue_zeilen)
    return ((b"\xef\xbb\xbf" if hat_bom else b"") + neues_text.encode("utf-8"), original)


def bereite_kurstabelle_mit_neuer_bezeichnung_vor(
    aktien, aktie, bezeichnung, zielposition
):
    original, hat_bom, zeilen, _ = lese_und_valide_kurstabelle(aktien)
    neue_reihenfolge = list(range(len(aktien)))
    bewegte_spalte = neue_reihenfolge.pop(aktie["index"])
    neue_reihenfolge.insert(zielposition, bewegte_spalte)
    neue_zeilen = []

    for zeilennummer, zeile in enumerate(zeilen, start=1):
        inhalt, zeilenende = teile_zeilenende(zeile)
        if not inhalt:
            neue_zeilen.append(zeile)
            continue
        felder = inhalt.split("\t")
        neue_felder = [felder[0]] + [felder[index + 1] for index in neue_reihenfolge]
        if zeilennummer == 1:
            neue_felder[zielposition + 1] = bezeichnung
        neue_zeilen.append("\t".join(neue_felder) + zeilenende)

    neues_text = "".join(neue_zeilen)
    return ((b"\xef\xbb\xbf" if hat_bom else b"") + neues_text.encode("utf-8"), original)


def bereite_aktienliste_mit_neuer_aktie_vor(aktien, neue_aktie, position):
    original = AKTIENLISTE.read_bytes()
    hat_bom = original.startswith(b"\xef\xbb\xbf")
    zeilen = original.decode("utf-8-sig").splitlines(keepends=True)
    vorlage = next(
        teile_zeilenende(zeile)[0]
        for zeile in zeilen
        if ZEILENMUSTER.match(teile_zeilenende(zeile)[0])
    )
    rohrpositionen = [index for index, zeichen in enumerate(vorlage) if zeichen == "|"]
    namebreite = rohrpositionen[2] - (rohrpositionen[1] + 2)
    nummernbreite = rohrpositionen[0]
    if len(neue_aktie["bezeichnung"]) > namebreite:
        raise ValueError(
            f"Die Bezeichnung darf höchstens {namebreite} Zeichen lang sein."
        )

    neue_zeile = (
        f"{neue_aktie['nummer']:<{nummernbreite}}| "
        f"{neue_aktie['isin']:<12} | "
        f"{neue_aktie['bezeichnung']:<{namebreite}}| {neue_aktie['branche']}"
    )
    zeilenende = next(
        (ende for zeile in zeilen if (ende := teile_zeilenende(zeile)[1])), "\n"
    )
    einfuege_zeilenindex = (
        aktien[position]["zeilenindex"] if position < len(aktien) else None
    )
    neue_zeilen = []
    nummer = 1
    eingefuegt = False

    for zeilenindex, zeile in enumerate(zeilen):
        if zeilenindex == einfuege_zeilenindex:
            neue_zeilen.append(neue_zeile + zeilenende)
            nummer += 1
            eingefuegt = True

        inhalt, ende = teile_zeilenende(zeile)
        if ZEILENMUSTER.match(inhalt):
            neue_zeilen.append(aktualisiere_nummer(inhalt, nummer) + ende)
            nummer += 1
        else:
            neue_zeilen.append(zeile)

    if not eingefuegt:
        if neue_zeilen and not teile_zeilenende(neue_zeilen[-1])[1]:
            neue_zeilen[-1] += zeilenende
        neue_zeilen.append(neue_zeile + zeilenende)
        nummer += 1

    if nummer != len(aktien) + 2:
        raise ValueError("Die Aktienliste wurde während des Vorgangs verändert.")
    return ("\ufeff" if hat_bom else "") + "".join(neue_zeilen)


def bereite_kurstabelle_mit_neuer_aktie_vor(aktien, neue_aktie, position):
    original, hat_bom, zeilen, kopffelder = lese_und_valide_kurstabelle(aktien)
    neue_zeilen = []
    spaltenindex = position + 1

    for zeilennummer, zeile in enumerate(zeilen, start=1):
        inhalt, zeilenende = teile_zeilenende(zeile)
        if not inhalt:
            neue_zeilen.append(zeile)
            continue
        felder = inhalt.split("\t")
        wert = neue_aktie["kurs"] if zeilennummer > 1 else neue_aktie["bezeichnung"]
        felder.insert(spaltenindex, wert)
        neue_zeilen.append("\t".join(felder) + zeilenende)

    neues_text = "".join(neue_zeilen)
    return ((b"\xef\xbb\xbf" if hat_bom else b"") + neues_text.encode("utf-8"), original)


def schreibe_dateipaar(neuer_listeninhalt, neuer_kurstabelleninhalt, alter_kurstabelleninhalt):
    temp_kurstabelle = None
    temp_aktienliste = None
    temp_rollback = None
    try:
        temp_kurstabelle = schreibe_temporar(KURSTABELLE, neuer_kurstabelleninhalt)
        temp_aktienliste = schreibe_temporar(AKTIENLISTE, neuer_listeninhalt)
        os.replace(temp_kurstabelle, KURSTABELLE)
        try:
            os.replace(temp_aktienliste, AKTIENLISTE)
        except OSError:
            temp_rollback = schreibe_temporar(KURSTABELLE, alter_kurstabelleninhalt)
            os.replace(temp_rollback, KURSTABELLE)
            raise
    finally:
        if temp_kurstabelle is not None:
            temp_kurstabelle.unlink(missing_ok=True)
        if temp_aktienliste is not None:
            temp_aktienliste.unlink(missing_ok=True)
        if temp_rollback is not None:
            temp_rollback.unlink(missing_ok=True)


def yahoo_request(url):
    anfrage = Request(
        url,
        headers={
            "Accept": "application/json",
            "User-Agent": "Aktienkurs-Skript/1.0",
        },
    )
    try:
        with urlopen(anfrage, timeout=20) as antwort:
            return json.load(antwort)
    except (HTTPError, URLError, TimeoutError, json.JSONDecodeError) as fehler:
        raise RuntimeError(f"Yahoo-Finance-Anfrage fehlgeschlagen: {fehler}") from fehler


def ermittle_yahoo_symbol(isin, bezeichnung):
    suchbegriffe = [isin]
    name_ohne_index = re.sub(r"\s*\([^)]*\)\s*$", "", bezeichnung)
    if name_ohne_index:
        suchbegriffe.append(name_ohne_index)

    for suchbegriff in suchbegriffe:
        daten = yahoo_request(
            "https://query1.finance.yahoo.com/v1/finance/search?q="
            + quote(suchbegriff)
        )
        for quote_treffer in daten.get("quotes", []):
            if quote_treffer.get("quoteType") == "EQUITY" and quote_treffer.get(
                "symbol"
            ):
                return quote_treffer["symbol"]
    return None


def ermittle_letzten_kurs(symbol, isin):
    daten = yahoo_request(
        "https://query1.finance.yahoo.com/v8/finance/chart/"
        + quote(symbol)
        + "?range=1d&interval=1d"
    )
    ergebnisse = daten.get("chart", {}).get("result", [])
    kurs = (
        ergebnisse[0].get("meta", {}).get("regularMarketPrice")
        if ergebnisse
        else None
    )
    if isinstance(kurs, bool) or not isinstance(kurs, (int, float)) or not isfinite(kurs):
        raise RuntimeError(f"Kein aktueller Kurs für {isin} ({symbol}) geliefert.")
    return f"{kurs:.6f}"


def ermittle_aktuellen_kurs(isin, bezeichnung):
    symbol = ermittle_yahoo_symbol(isin, bezeichnung)
    if symbol is None:
        raise RuntimeError(f"Kein Yahoo-Finance-Symbol für {isin} gefunden.")
    return ermittle_letzten_kurs(symbol, isin)


def entferne_aktie(aktien):
    eingabe = input("ISIN oder fortlaufende Nummer der Aktie: ").strip().upper()
    if re.fullmatch(r"[A-Z0-9]{12}", eingabe):
        treffer = [aktie for aktie in aktien if aktie["isin"] == eingabe]
    elif eingabe.isdecimal():
        treffer = [aktie for aktie in aktien if aktie["nummer"] == int(eingabe)]
    else:
        print("Bitte eine gültige ISIN oder Nummer eingeben.")
        return

    if not treffer:
        print("Keine passende Aktie gefunden.")
        return
    if len(treffer) > 1:
        print("Die ISIN kommt mehrfach vor. Bitte die zu entfernende Nummer auswählen:")
        for aktie in treffer:
            print(f"  Nr. {aktie['nummer']}: {aktie['bezeichnung']}")
        nummer = input("Nummer: ").strip()
        if not nummer.isdecimal():
            print("Ungültige Nummer; es wurde nichts geändert.")
            return
        treffer = [aktie for aktie in treffer if aktie["nummer"] == int(nummer)]
        if len(treffer) != 1:
            print("Keine eindeutige passende Nummer; es wurde nichts geändert.")
            return

    aktie = treffer[0]
    print(f"Ausgewählt: Nr. {aktie['nummer']} | {aktie['isin']} | {aktie['bezeichnung']}")
    if input("Wirklich aus Aktien- und Kurstabelle entfernen? [j/N]: ").strip().casefold() not in {
        "j",
        "ja",
    }:
        print("Abgebrochen; es wurde nichts geändert.")
        return

    neuer_listeninhalt = bereite_aktienliste_vor(aktien, aktie).encode("utf-8")
    neuer_kurstabelleninhalt, alter_kurstabelleninhalt = bereite_kurstabelle_vor(
        aktien, aktie
    )
    schreibe_dateipaar(
        neuer_listeninhalt, neuer_kurstabelleninhalt, alter_kurstabelleninhalt
    )

    print(f"{aktie['bezeichnung']} wurde entfernt; die Nummerierung wurde aktualisiert.")


def fuege_aktie_hinzu(aktien):
    if any(
        vorher["bezeichnung"].casefold() > nachher["bezeichnung"].casefold()
        for vorher, nachher in zip(aktien, aktien[1:])
    ):
        raise ValueError(
            "Die vorhandene Aktienliste ist nicht alphabetisch sortiert. "
            "Bitte zuerst korrigieren."
        )

    isin = input("ISIN (12 Zeichen): ").strip().upper()
    if re.fullmatch(r"[A-Z0-9]{12}", isin) is None:
        print("Ungültige ISIN; es wurde nichts geändert.")
        return
    if any(aktie["isin"] == isin for aktie in aktien):
        print("Diese ISIN ist bereits in der Aktienliste enthalten.")
        return

    bezeichnung = input("Bezeichnung des Wertpapiers: ").strip()
    branche = input("Branche: ").strip()
    if not bezeichnung or not branche or any(
        zeichen in bezeichnung + branche for zeichen in "|\t\r\n"
    ):
        print("Bezeichnung und Branche dürfen nicht leer sein oder | bzw. Tab enthalten.")
        return

    vorgeschlagener_kurs = None
    try:
        vorgeschlagener_kurs = ermittle_aktuellen_kurs(isin, bezeichnung)
        print(f"Aktueller Yahoo-Finance-Kurs: {vorgeschlagener_kurs}")
    except RuntimeError as fehler:
        print(f"Kurs konnte nicht automatisch abgerufen werden: {fehler}")

    kursaufforderung = "Aktuell bekannter Kurswert"
    if vorgeschlagener_kurs is not None:
        kursaufforderung += f" [{vorgeschlagener_kurs}]"
    kurseingabe = input(
        f"{kursaufforderung} (Enter übernimmt den Vorschlag): "
    ).strip()
    if not kurseingabe and vorgeschlagener_kurs is not None:
        kurseingabe = vorgeschlagener_kurs
    try:
        kurswert = Decimal(kurseingabe.replace(",", "."))
    except InvalidOperation:
        print("Ungültiger Kurswert; es wurde nichts geändert.")
        return
    if not kurswert.is_finite() or kurswert <= 0:
        print("Der Kurswert muss eine positive Zahl sein.")
        return

    position = next(
        (
            index
            for index, aktie in enumerate(aktien)
            if aktie["bezeichnung"].casefold() > bezeichnung.casefold()
        ),
        len(aktien),
    )
    neue_aktie = {
        "nummer": position + 1,
        "isin": isin,
        "bezeichnung": bezeichnung,
        "branche": branche,
        "kurs": f"{kurswert:.6f}",
    }

    neuer_listeninhalt = bereite_aktienliste_mit_neuer_aktie_vor(
        aktien, neue_aktie, position
    ).encode("utf-8")
    neuer_kurstabelleninhalt, alter_kurstabelleninhalt = (
        bereite_kurstabelle_mit_neuer_aktie_vor(aktien, neue_aktie, position)
    )
    print(
        f"Einfügen als Nr. {neue_aktie['nummer']}: {isin} | {bezeichnung} "
        f"| Kurs {neue_aktie['kurs']}"
    )
    if input("Aktie einfügen und alle bisherigen Kurswerte vorbelegen? [j/N]: ").strip().casefold() not in {
        "j",
        "ja",
    }:
        print("Abgebrochen; es wurde nichts geändert.")
        return

    schreibe_dateipaar(
        neuer_listeninhalt, neuer_kurstabelleninhalt, alter_kurstabelleninhalt
    )
    print(f"{bezeichnung} wurde eingefügt; Nummerierung und Kurstabelle wurden aktualisiert.")


def bewege_aktie(aktien):
    eingabe = input("ISIN oder fortlaufende Nummer der Aktie: ").strip().upper()
    if re.fullmatch(r"[A-Z0-9]{12}", eingabe):
        treffer = [aktie for aktie in aktien if aktie["isin"] == eingabe]
    elif eingabe.isdecimal():
        treffer = [aktie for aktie in aktien if aktie["nummer"] == int(eingabe)]
    else:
        print("Bitte eine gültige ISIN oder Nummer eingeben.")
        return

    if not treffer:
        print("Keine passende Aktie gefunden.")
        return
    if len(treffer) > 1:
        print("Die ISIN kommt mehrfach vor. Bitte die zu bewegende Nummer auswählen:")
        for aktie in treffer:
            print(f"  Nr. {aktie['nummer']}: {aktie['bezeichnung']}")
        nummer = input("Nummer: ").strip()
        if not nummer.isdecimal():
            print("Ungültige Nummer; es wurde nichts geändert.")
            return
        treffer = [aktie for aktie in treffer if aktie["nummer"] == int(nummer)]
        if len(treffer) != 1:
            print("Keine eindeutige passende Nummer; es wurde nichts geändert.")
            return

    aktie = treffer[0]
    zielnummer = input(f"Neue Nummer (1 bis {len(aktien)}): ").strip()
    if not zielnummer.isdecimal() or not 1 <= int(zielnummer) <= len(aktien):
        print("Ungültige Zielnummer; es wurde nichts geändert.")
        return

    zielposition = int(zielnummer) - 1
    if zielposition == aktie["index"]:
        print("Die Aktie steht bereits an dieser Position; es wurde nichts geändert.")
        return

    print(
        f"Verschieben: Nr. {aktie['nummer']} {aktie['bezeichnung']} "
        f"nach Nr. {zielposition + 1}"
    )
    if input("Aktienzeile und Kursspalte verschieben? [j/N]: ").strip().casefold() not in {
        "j",
        "ja",
    }:
        print("Abgebrochen; es wurde nichts geändert.")
        return

    neuer_listeninhalt = bereite_aktienliste_mit_verschobener_aktie_vor(
        aktien, aktie, zielposition
    ).encode("utf-8")
    neuer_kurstabelleninhalt, alter_kurstabelleninhalt = (
        bereite_kurstabelle_mit_verschobener_aktie_vor(aktien, aktie, zielposition)
    )
    schreibe_dateipaar(
        neuer_listeninhalt, neuer_kurstabelleninhalt, alter_kurstabelleninhalt
    )
    print(f"{aktie['bezeichnung']} wurde verschoben; die Nummerierung wurde aktualisiert.")


def aendere_bezeichnung(aktien):
    eingabe = input("ISIN oder fortlaufende Nummer der Aktie: ").strip().upper()
    if re.fullmatch(r"[A-Z0-9]{12}", eingabe):
        treffer = [aktie for aktie in aktien if aktie["isin"] == eingabe]
    elif eingabe.isdecimal():
        treffer = [aktie for aktie in aktien if aktie["nummer"] == int(eingabe)]
    else:
        print("Bitte eine gültige ISIN oder Nummer eingeben.")
        return

    if not treffer:
        print("Keine passende Aktie gefunden.")
        return
    if len(treffer) > 1:
        print("Die ISIN kommt mehrfach vor. Bitte die zu ändernde Nummer auswählen:")
        for aktie in treffer:
            print(f"  Nr. {aktie['nummer']}: {aktie['bezeichnung']}")
        nummer = input("Nummer: ").strip()
        if not nummer.isdecimal():
            print("Ungültige Nummer; es wurde nichts geändert.")
            return
        treffer = [aktie for aktie in treffer if aktie["nummer"] == int(nummer)]
        if len(treffer) != 1:
            print("Keine eindeutige passende Nummer; es wurde nichts geändert.")
            return

    aktie = treffer[0]
    bezeichnung = input("Neue Wertpapierbezeichnung: ").strip()
    if not bezeichnung or any(zeichen in bezeichnung for zeichen in "|\t\r\n"):
        print("Die Bezeichnung darf nicht leer sein oder | bzw. Tab enthalten.")
        return
    if bezeichnung == aktie["bezeichnung"]:
        print("Die Bezeichnung ist unverändert; es wurde nichts geändert.")
        return

    neuer_listeninhalt, zielposition = bereite_aktienliste_mit_neuer_bezeichnung_vor(
        aktien, aktie, bezeichnung
    )
    neuer_kurstabelleninhalt, alter_kurstabelleninhalt = (
        bereite_kurstabelle_mit_neuer_bezeichnung_vor(
            aktien, aktie, bezeichnung, zielposition
        )
    )
    print(
        f"Bezeichnung: {aktie['bezeichnung']} -> {bezeichnung}; "
        f"neue Position: {zielposition + 1}"
    )
    if input("Bezeichnung und Kurstabelle aktualisieren? [j/N]: ").strip().casefold() not in {
        "j",
        "ja",
    }:
        print("Abgebrochen; es wurde nichts geändert.")
        return

    schreibe_dateipaar(
        neuer_listeninhalt.encode("utf-8"),
        neuer_kurstabelleninhalt,
        alter_kurstabelleninhalt,
    )
    print("Die Bezeichnung wurde aktualisiert; Liste und Kurstabelle sind synchron.")


def main():
    aktionen = {
        "1": ("Duplikate nach ISIN und Bezeichnung suchen", zeige_duplikate),
        "2": ("Alphabetische Reihenfolge prüfen", pruefe_alphabetische_reihenfolge),
        "3": ("Aktie per ISIN oder Nummer entfernen", entferne_aktie),
        "4": ("Neue Aktie alphabetisch einfügen", fuege_aktie_hinzu),
        "5": ("Aktie an eine andere Listenposition bewegen", bewege_aktie),
        "6": ("Wertpapierbezeichnung ändern", aendere_bezeichnung),
    }

    while True:
        print("\nAktienliste warten")
        for nummer, (beschreibung, _) in aktionen.items():
            print(f"{nummer}. {beschreibung}")
        print("0. Beenden")
        auswahl = input("Auswahl: ").strip()

        if auswahl == "0":
            return
        if auswahl not in aktionen:
            print("Ungültige Auswahl.")
            continue

        try:
            aktien = lese_aktien()
            aktionen[auswahl][1](aktien)
        except (OSError, ValueError) as fehler:
            print(f"Fehler: {fehler}")


if __name__ == "__main__":
    main()