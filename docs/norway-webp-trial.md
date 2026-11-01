# Noorse N50 WebP-proef — 18 oktober 2026

Bron: © Kartverket, N50 Raster UTM33 rutevis, cel 0500.
Dataset: https://kartkatalog.geonorge.no/metadata/n50-raster-utm33-rutevis/1a7d4919-fc5d-4278-9ae9-45ee23f26808
Licentie: https://www.kartverket.no/api-og-data/vilkar-for-bruk
Download: openbare Geonorge-download-API, zonder e-mail of sleutel.

De bron is 8000 × 8000 pixels, 2,5 m/pixel: 20 × 20 km (400 km²).
TIFF: 14.861.400 bytes. Bergachtig gebied bij Storekneppet/Veslkne.

| Variant | Tegels | Niveaus | Bytes | MB (decimaal) |
| --- | ---: | ---: | ---: | ---: |
| WebP lossless | 1365 | 6 | 23.210.810 | 23,21 |
| WebP kwaliteit 85 | 1365 | 6 | 15.756.936 | 15,76 |

Alle bestanden zijn na schrijven opnieuw als WebP geopend; dimensies gecontroleerd.
Lossless is voor elke tegel pixelgelijk aan het invoerbeeld van dat niveau.
Q85 PSNR over alle pixels van de piramide: 30,27 dB. Visuele vergelijking van
512 × 512 pixels met kaartnamen en hoogtelijnen staat in comparison.webp.
Q85 is 32,1% kleiner dan lossless, met zichtbare maar beperkte compressieartefacten.

De API biedt 1336 broncellen aan. Vermenigvuldiging met dit ene proefvak geeft
circa 21,1 GB Q85 of 31,0 GB lossless voor alle cellen, inclusief dezelfde
lokale overzichtsniveaus. Dit is slechts een indicatie: kaartinhoud, lege
kustvlakken, reprojectie en de uiteindelijke zoomkeuze veranderen de grootte.
De eerdere 30–100 GB was een ruime onbevestigde raming voor tegels.
Voorlopige werkbegroting: 25–50 GB voor de uiteindelijke tegelset, exclusief
TIFF-bronnen, tijdelijke verwerking en back-ups. Geen garantie op basis van één vak.

Deze proef gebruikt native EPSG:25833 met lokale tegelindices en afmetingen
van maximaal 256 × 256; de randen zijn kleiner. Dit is GEEN kant-en-klare
EPSG:3857 XYZ-serverlaag. De bestaande Noorse kaart in de app is niet aangepast.
Voor aansluiting moet de export gereprojecteerd en op het app-rooster betegeld
worden; daarna de omvang opnieuw meten. Er zijn geen WMTS/Geovekst-tegels gekopieerd.

## Reproduceren

Vanuit de backend-map (Python met Pillow en numpy):

```sh
python3 scripts/norway-webp-trial.py \
  storage/app/norway-webp-trial/source/33_N50raster_0500.tif \
  storage/app/norway-webp-trial/results
```

Resultaten, bron en downloadreferentie staan onder storage/app/norway-webp-trial/.
Dit is lokale opslag; de proef is niet naar de productieserver uitgerold.
