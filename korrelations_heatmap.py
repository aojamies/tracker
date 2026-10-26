import argparse
import csv
from pathlib import Path

import plotly.graph_objects as go


VERZEICHNIS = Path(__file__).resolve().parent
ERGEBNISDATEI = VERZEICHNIS / "kausalitaet.txt"
HTML_DATEI = VERZEICHNIS / "korrelations_heatmap.html"


def lese_korrelationsmatrix(dateipfad):
    aktien = []
    korrelationen = {}
    metadaten = {}

    with dateipfad.open(encoding="utf-8-sig", newline="") as datei:
        for zeile in datei:
            zeile = zeile.rstrip("\r\n")
            if not zeile:
                continue

            felder = next(csv.reader([zeile], delimiter="\t"))
            if felder[0] == "Aktie A":
                continue
            if len(felder) == 1 and ":" in felder[0]:
                schluessel, wert = felder[0].split(":", maxsplit=1)
                metadaten[schluessel.strip()] = wert.strip()
                continue
            if len(felder) == 1:
                continue
            if len(felder) < 5:
                raise ValueError(f"Ungültige Ergebniszeile: {zeile}")

            aktie_a, aktie_b = felder[0], felder[1]
            for aktie in (aktie_a, aktie_b):
                if aktie not in aktien:
                    aktien.append(aktie)

            try:
                korrelation = float(felder[3])
            except ValueError:
                korrelation = None
            korrelationen[aktie_a, aktie_b] = korrelation
            korrelationen[aktie_b, aktie_a] = korrelation

    if not aktien:
        raise ValueError(f"Keine Aktienpaare in {dateipfad.name} gefunden.")

    matrix = []
    for aktie_a in aktien:
        matrixzeile = []
        for aktie_b in aktien:
            if aktie_a == aktie_b:
                matrixzeile.append(1.0)
            else:
                matrixzeile.append(korrelationen.get((aktie_a, aktie_b)))
        matrix.append(matrixzeile)

    return aktien, matrix, metadaten


def erstelle_heatmap(aktien, matrix, metadaten, ausgabedatei):
    anzahl = len(aktien)
    zeitfenster = metadaten.get("Zeitfenster (Tage)", "unbekannt")
    daten_von = metadaten.get("Daten von", "unbekannt")
    daten_bis = metadaten.get("Daten bis", "unbekannt")

    figur = go.Figure(
        data=go.Heatmap(
            z=matrix,
            x=list(range(anzahl)),
            y=list(range(anzahl)),
            customdata=[
                [[aktien[zeilenindex], aktien[spaltenindex]] for spaltenindex in range(anzahl)]
                for zeilenindex in range(anzahl)
            ],
            zmin=-1,
            zmax=1,
            colorscale=[
                [0.0, "#2166AC"],
                [0.5, "#F7F7F7"],
                [1.0, "#B2182B"],
            ],
            colorbar={
                "title": {"text": "Pearson r"},
                "tickvals": [-1, -0.5, 0, 0.5, 1],
            },
            hoverongaps=False,
            hovertemplate=(
                "Zeile: %{customdata[0]}<br>Spalte: %{customdata[1]}<br>"
                "Korrelation: %{z:.4f}<extra></extra>"
            ),
        )
    )
    figur.update_layout(
        title=(
            "Korrelationen der Aktienkurse"
            f"<br><sup>{daten_von} bis {daten_bis}; Zeitfenster: {zeitfenster} Tage</sup>"
        ),
        width=max(1400, min(2600, anzahl * 12)),
        height=max(1200, min(2400, anzahl * 12)),
        margin={"l": 420, "r": 120, "t": 110, "b": 420},
        xaxis={
            "title": "Aktie (Spalte)",
            "tickmode": "array",
            "tickvals": list(range(anzahl)),
            "ticktext": aktien,
            "tickangle": -90,
            "tickfont": {"size": 8},
            "automargin": True,
        },
        yaxis={
            "title": "Aktie (Zeile)",
            "tickmode": "array",
            "tickvals": list(range(anzahl)),
            "ticktext": aktien,
            "tickfont": {"size": 8},
            "autorange": "reversed",
            "automargin": True,
            "scaleanchor": "x",
            "scaleratio": 1,
        },
        template="plotly_white",
    )
    figur.write_html(
        ausgabedatei,
        include_plotlyjs=True,
        full_html=True,
        config={"responsive": True, "scrollZoom": True},
    )


def erstelle_parser():
    parser = argparse.ArgumentParser(
        description="Erstellt eine interaktive Heatmap aus kausalitaet.txt."
    )
    parser.add_argument(
        "--quelle", type=Path, default=ERGEBNISDATEI, help="Eingabedatei."
    )
    parser.add_argument(
        "--ausgabe", type=Path, default=HTML_DATEI, help="Ausgabe der Heatmap."
    )
    return parser


def main():
    argumente = erstelle_parser().parse_args()
    try:
        aktien, matrix, metadaten = lese_korrelationsmatrix(argumente.quelle)
        erstelle_heatmap(aktien, matrix, metadaten, argumente.ausgabe)
    except (OSError, ValueError) as fehler:
        print(f"Fehler: {fehler}")
        return 1

    anzahl_paare = len(aktien) * (len(aktien) - 1) // 2
    print(f"Heatmap erstellt: {argumente.ausgabe}")
    print(f"Aktien: {len(aktien)}; Paare: {anzahl_paare}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())