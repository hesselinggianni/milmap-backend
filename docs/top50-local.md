# Permanente lokale TOP50raster-kaart

TOP50raster wordt uit de landelijke PDOK/Kadaster GeoTIFF-download opgebouwd
tot WebP XYZ-tegels (256 pixels, kwaliteit 90, zoom 0–14). Kaartverzoeken en
KAKO-voorbeelden lezen uitsluitend lokale bestanden. Ze starten geen externe
download, ook niet wanneer een tegel ontbreekt of oud is.

- Bron: https://service.pdok.nl/kadaster/brt-topraster/atom/top50raster.xml
- Licentie: CC-BY 4.0. Behoud de Kadaster-naamsvermelding in de kaart.
- Tegels: `storage/app/tiles/pdok/top50/{z}/{x}/{y}.webp`.
- Gereedmelding en statistieken: `storage/app/tiles/pdok/top50/manifest.json`.
- Voortgang: `storage/app/top50-source/status.json`.
- Converter: `scripts/build-top50.py` (Python met rasterio en Pillow).

De converter leest `storage/app/top50-source/top50.zip.part`. Controleer vóór
de start dat dit het volledige ZIP-bestand is. De bronbladen worden in
`storage/app/top50-source/sheets` uitgepakt. De legenda is geen geografisch
kaartblad en wordt overgeslagen. Hoogste detail wordt naar EPSG:3857
gereprojecteerd; lagere niveaus worden uit de lokale tegelpiramide afgeleid.

De kopie wordt niet via WMS bijgewerkt en niet door `tiles:prune-pdok` gewist.
Voor een volgende editie: bouw een nieuwe piramide in een aparte projectroot,
controleer het manifest en enkele uitsneden, en vervang daarna de bestaande
map. De converter hervat bestaande tegels; een nieuwe bron vereist dus een
lege uitvoermap. Bewaar de oude piramide totdat de nieuwe editie is geverifieerd.

`scripts/install-top50.py` verzorgt een gerichte installatie op de huidige
MilMap-server, met een backup van gewijzigde productiecode. Het publiceert
alleen de kaartcontrollers en gerichte aanpassingen aan de bestaande live
frontendbundels. Andere lokale appwijzigingen worden niet meegepubliceerd.
