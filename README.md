# IP-Symcon MyŠkoda Public API

Dieses Repository enthält ein IP-Symcon-Modul für die offizielle Škoda MyŠkoda Public API.

Das Modul ermöglicht den Zugriff auf Fahrzeugdaten, Ladezustände, Klimatisierung, Positionsdaten und Statusinformationen direkt aus der MyŠkoda-API.

## Funktionen

- Fahrzeugliste aus dem MyŠkoda-Account abrufen
- Fahrzeugstatus mit dynamischem `include`-Filter abfragen
- Ladezustand, Ladeleistung, Reichweite und Ladezeit auslesen
- Klimatisierungsstatus und Solltemperatur verarbeiten
- Fahrzeugaktionen wie Start/Stop von Laden und Klimatisierung senden
- Positionsdaten und Kartenansicht für die Parkposition anzeigen
- API-Rate-Limit-Informationen auslesen
- Eigene IP-Symcon-Profile für Statuswerte und Sonderdarstellungen bereitstellen

## API-Umsetzung

Das Modul wurde entsprechend der offiziellen Škoda Public API-Struktur angepasst. Dabei werden aktuell die dokumentierten Endpoints verwendet, unter anderem:

- `POST /api/v1/vehicles/{vin}/air-conditioning/start`
- `POST /api/v1/vehicles/{vin}/air-conditioning/stop`
- `POST /api/v1/vehicles/{vin}/charging/start`
- `POST /api/v1/vehicles/{vin}/charging/stop`
- `PUT /api/v1/vehicles/{vin}/charging/limit`
- `PUT /api/v1/vehicles/{vin}/charging/mode`

Hinweis:
- Für Fensterheizung gibt es laut offizieller API keinen eigenen Endpoint. Diese Funktion wird deshalb als lokale Statusaktualisierung behandelt.
- Die Fahrzeugaktionen wurden bewusst an die dokumentierte API-Syntax angepasst.

## Installation

1. Das Modul in IP-Symcon importieren.
2. Die Eigenschaft `ApiKey` mit dem gültigen API-Key aus der MyŠkoda App füllen.
3. Die `VIN` des gewünschten Fahrzeugs setzen.
4. Optional das `UpdateInterval` anpassen.
5. Bei Bedarf `IsBEV`, `EnableClimate`, `EnablePosition` und `EnableMap` konfigurieren.

## Konfiguration

### Wichtige Eigenschaften

- `ApiKey`: MyŠkoda API-Key
- `VIN`: Fahrzeug-VIN
- `UpdateInterval`: Aktualisierungsintervall in Sekunden, empfohlen mindestens `300`
- `IsBEV`: Bei reinen Battery-Electric Vehicles werden Benzin-/Verbrenner-Variablen automatisch ausgeblendet
- `EnableClimate`: Klimatisierungsdaten einschalten
- `EnablePosition`: Positionsdaten einschalten
- `EnableMap`: Kartenansicht für Parkposition einschalten

## Verfügbare Variablen

Das Modul legt je nach Fahrzeugtyp und aktivierten Bereichen verschiedene Variablen an, unter anderem:

- `Charging_BatteryLevel`
- `Charging_ElectricRange`
- `Charging_State`
- `Charging_PlugState`
- `Charging_Power`
- `Charging_Rate`
- `Charging_RemainingTime`
- `Charging_TargetSoC`
- `Charging_Active`
- `Charging_ChargeMode`
- `Climate_State`
- `Climate_TargetTemperature`
- `Climate_Active`
- `Climate_RemainingTime`
- `Climate_PowerSource`
- `Climate_WindowHeatingFront`
- `Climate_WindowHeatingRear`
- `Position_Latitude`
- `Position_Longitude`
- `Position_Heading`
- `Position_Timestamp`
- `Position_Type`
- `Position_Map`
- `API_RateLimitRemaining`

## Eigene IP-Symcon-Profile

Für Statuswerte mit semantischer Bedeutung werden eigene Profile verwendet:

- `SKODA.ChargingState`
- `SKODA.PlugState`
- `SKODA.ClimateState`
- `SKODA.PowerSource`
- `SKODA.ChargeMode`
- `SKODA.TargetSoC`
- `SKODA.APIRateLimitRemaining`

Diese Profile werden beim Modulstart automatisch angelegt, sofern sie noch nicht existieren.

## Beispiel für Aktionen

Das Modul unterstützt unter anderem folgende Aktionen:

- `StartClimatisation()`
- `StopClimatisation()`
- `StartCharging()`
- `StopCharging()`
- `SetTargetSoC()`
- `SetChargeMode()`
- `SetTargetTemperature()`

Die Aktionen werden über die IP-Symcon-Variable-Aktionen bzw. die passenden Moduleingriffe ausgelöst.

## Hinweise zum Rate Limit

Die Škoda Public API ist rate-limited. Daher ist ein ausreichend großes Aktualisierungsintervall wichtig.

Empfohlener Standard:

- `UpdateInterval = 300` Sekunden

Zusätzlich wird die Variable `API_RateLimitRemaining` regelmäßig mit dem verbleibenden Kontingent aktualisiert.

## Hinweise zu Fahrzeugtypen

- Bei Fahrzeugen ohne Verbrenner (`IsBEV = true`) werden Kraftstoff- und Reichweitenvariablen für den Verbrenner nicht angelegt.
- Für Fahrzeugmodelle mit abweichenden Datenstrukturen werden fehlende Felder automatisch übersprungen.

## Bekannte Einschränkungen

- Die API liefert je nach Fahrzeugmodell nicht immer dieselben Felder oder denselben Detailgrad.
- Manche Funktionen sind modellabhängig und können je nach Fahrzeuggeneration unterschiedlich verfügbar sein.
- `SetWindowHeating()` ist nur als lokale Statusaktualisierung verfügbar, da die öffentliche API hierfür keinen direkten Endpoint dokumentiert.

## Lizenz

Dieses Projekt wird ohne explizite Lizenzangabe bereitgestellt. Bitte prüfe vor der Nutzung die entsprechenden Nutzungsbedingungen des jeweiligen Providers.

## Hinweis

Dieses Modul basiert auf der offiziellen Škoda Public API und ist für private bzw. projektbezogene Nutzung gedacht. Eine Live-Verifikation mit gültigem API-Key und Fahrzeugdaten sollte in einer echten IP-Symcon-Umgebung erfolgen.
