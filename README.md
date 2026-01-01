# DWD Niederschlagsvorhersage für Home Assistant

<img width="408" height="167" alt="image" src="https://github.com/user-attachments/assets/52c37779-6503-4f3e-ae2f-01efb7b669cb" />

Niederschlagsvorhersage der nächsten Stunde in 5min Schritten in Anlehnung/Alternative zu AccuWeather MinuteCast(R).

## Voraussetzungen

- Server mit PHP (curl, GdImage)
- Home Assistant
  - Ich nutze für die Visualisierung [Lovelace Mini Graph Card](https://github.com/kalkih/mini-graph-card)
 
## Funktionsweise

Alle 5min lädt das Script die 12 Bilder (1h á 5min) von dem DWD GeoServer WMS für deinen Ort und wertet das 1x1px Bild der Legende nach aus.

<img width="100" height="390" alt="image" src="https://github.com/user-attachments/assets/6d3f4c0d-54ed-476a-a816-188e1dfd5023" />
Quelle: https://geoportal.bayern.de/

## Anleitung

1. Das PHP Script auf deinen Server hochladen (dieser muss öffentlich erreichbar sein), z.b. via https://example.de/rain_radar.php?lat=52.520008&lng=13.404954
2. Die Latitude and Longitude von deinem Standort herausfinden, z.b. mit [https://www.latlong.net/](https://www.latlong.net/)
3. Lat/Lng Parameter in der URL mit deinen ersetzen
4. In HA Sensor und Template YAML anlegen
5. In deinem HA Dashboard die Regenkarte anlegen

## Gedanken

- Ohne Server nur mit Python in HA möglich?
- Anderer DWD Dienst ohne Bildauswertung?
- Dynamisch Ort via Handy-Location auslesen?
