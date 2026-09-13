<?php

declare(strict_types=1);

/**
 * SkodaConnect Class für IP-Symcon
 * Autor: matzel687 / GitHub User
 * Anbindung an die offizielle MyŠkoda Public API (public.api.connect.skoda-auto.cz)
 * Inklusive dynamischer include-Filterung zur Optimierung der Payload.
 */
class SkodaConnect extends IPSModuleStrict
{
    private const API_BASE_URL = 'https://public.api.connect.skoda-auto.cz/api/v1';

    public function Create(): void
    {
        // Diese Zeile nicht entfernen
        parent::Create();

        // Eigenschaften registrieren
        $this->RegisterPropertyString("ApiKey", "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx");
        $this->RegisterPropertyString("VIN", "TMBJJ7NS1L0000000");
        $this->RegisterPropertyInteger("UpdateInterval", 300);

        // API-include-Filter
        $this->RegisterPropertyBoolean("IncludeInfo", true);
        $this->RegisterPropertyBoolean("IncludeStatus", true);
        $this->RegisterPropertyBoolean("IncludeFuelStatus", true);
        $this->RegisterPropertyBoolean("IncludeOdometer", true);
        $this->RegisterPropertyBoolean("IncludeParkingPosition", true);
        $this->RegisterPropertyBoolean("IncludeAirConditioning", true);
        $this->RegisterPropertyBoolean("IncludeCharging", true);
        $this->RegisterPropertyBoolean("IncludeChargingProfiles", true);
        $this->RegisterPropertyBoolean("IncludeOperations", true);

        // Sonstige Optionen
        $this->RegisterPropertyBoolean("IsBEV", false);
        $this->RegisterPropertyBoolean("EnableClimate", true);
        $this->RegisterPropertyBoolean("EnablePosition", true);
        $this->RegisterPropertyBoolean("EnableMap", true);

        // Timer für automatische Datenabfrage registrieren (Standard 300 Sek.)
        $this->RegisterTimer("UpdateTimer", 300.000, "SKODA_Update(\$_IPS['TARGET']);");

        // Set visualization type to 1, as we want to offer HTML
        $this->SetVisualizationType(1);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    /**
     * Ruft die Liste aller im MyŠkoda Account verfügbaren Fahrzeuge ab (GET /api/v1/vehicles)
     */
    public function GetVehicles(): array
    {
        $apiKey = trim($this->ReadPropertyString('ApiKey'));
        $vin = strtoupper(trim($this->ReadPropertyString('VIN')));

        if (empty($apiKey) || empty($vin)) {
            $this->SetStatus(201);
            $this->SendDebug('Error', 'API Key oder VIN nicht konfiguriert.', 0);
            return [];
        }

        $this->SendDebug('GetVehicles', 'Frage Fahrzeugdaten für VIN ' . $vin . ' ab...', 0);

        try {
            $response = $this->SendApiRequest(
                '/vehicles/' . $vin . '?include=info,status,charging,chargingProfiles,airConditioning,parkingPosition,odometer,operations',
                'GET'
            );

            // Die API liefert das Fahrzeug direkt, nicht verschachtelt in "vehicle"
            $vehicle = is_array($response) && isset($response['vehicle']) ? $response['vehicle'] : $response;

            $this->SendDebug('GetVehicles', 'Raw-Response: ' . json_encode($vehicle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0);

            return [$vehicle];
        } catch (Exception $e) {
            $this->SendDebug('Error', 'Fehler beim Abruf der Fahrzeugdaten: ' . $e->getMessage(), 0);
            return [];
        }
    }

    public function GetChargingProfiles(): array
    {
        $apiKey = trim($this->ReadPropertyString('ApiKey'));
        $vin = strtoupper(trim($this->ReadPropertyString('VIN')));

        if (empty($apiKey) || empty($vin)) {
            $this->SetStatus(201);
            $this->SendDebug('Error', 'API Key oder VIN nicht konfiguriert.', 0);
            return [];
        }

        try {
            $response = $this->SendApiRequest('/vehicles/' . $vin . '?include=chargingProfiles', 'GET');

            // API liefert das Fahrzeug direkt, nicht verschachtelt in "vehicle"
            $chargingProfiles = is_array($response) && isset($response['chargingProfiles'])
                ? $response['chargingProfiles']
                : [];

            $profiles = $chargingProfiles['profiles'] ?? [];
            $currentProfile = $chargingProfiles['currentVehiclePositionProfile'] ?? null;

            $this->SetValue('Charging_ProfilesJson', json_encode([
                'profiles' => $profiles,
                'currentVehiclePositionProfile' => $currentProfile,
                'carCapturedTimestamp' => $chargingProfiles['carCapturedTimestamp'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');

            $this->SetValue('Charging_ProfilesHtml', $this->GenerateChargingProfilesHtml($profiles, $currentProfile));

            $this->SendDebug('GetChargingProfiles', 'Ladeprofile erfolgreich geladen.', 0);
            return $profiles;
        } catch (Exception $e) {
            $this->SendDebug('Error', 'Fehler beim Abruf der Ladeprofile: ' . $e->getMessage(), 0);
            $this->LogMessage('MyŠkoda API Fehler: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(202);
            return [];
        }
    }

    public function UpdateChargingProfile(int $profileId, string $profileJson): bool
    {
        $apiKey = trim($this->ReadPropertyString('ApiKey'));
        $vin = strtoupper(trim($this->ReadPropertyString('VIN')));

        if (empty($apiKey) || empty($vin)) {
            $this->SetStatus(201);
            $this->SendDebug('Error', 'API Key oder VIN nicht konfiguriert.', 0);
            return false;
        }

        $profileData = json_decode($profileJson, true);
        if (!is_array($profileData)) {
            $this->SendDebug('Error', 'UpdateChargingProfile: Ungültiges Profil-JSON.', 0);
            return false;
        }

        try {
            $this->SendApiRequest('/vehicles/' . $vin . '/charging-profiles/' . $profileId, 'PUT', $profileData);
            $this->SendDebug('UpdateChargingProfile', 'Ladeprofil ' . $profileId . ' wurde an die Škoda API gesendet.', 0);
            return true;
        } catch (Exception $e) {
            $this->SendDebug('Error', 'UpdateChargingProfile: ' . $e->getMessage(), 0);
            $this->LogMessage('MyŠkoda API Fehler: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(202);
            return false;
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Abfrageintervall in Millisekunden setzen (Min. 300 Sekunden wegen API Rate Limit von 20 Req/h)
        $interval = max(300, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);

        // Custom Profile für Lademodus & Target SoC erstellen
        $this->RegisterProfiles();

        // Prüfen, ob "Reines Battery Electric Vehicle (BEV)" aktiviert ist
        $isBEV = $this->ReadPropertyBoolean('IsBEV');

        // Kilometerstand immer registrieren
        $this->RegisterVariableInteger('Status_Odometer', $this->Translate('Kilometerstand'), "", 8);

        // Kraftstoff-Variablen nur bei Nicht-BEV
        if (!$isBEV) {
            $this->RegisterVariableInteger('Fuel_LevelPercent', $this->Translate('Tankfüllung (%)'), '~Battery.100', 6);
            $this->RegisterVariableInteger('Fuel_CombustionRange', $this->Translate('Benzin Reichweite'), "", 7);
        } else {
            $this->UnregisterVariable('Fuel_LevelPercent');
            $this->UnregisterVariable('Fuel_CombustionRange');
        }

        // Standard Statusvariablen registrieren
        $this->RegisterVariableInteger("Charging_BatteryLevel", $this->Translate("Akkustand"), "~Battery.100", 1);
        $this->RegisterVariableInteger("Charging_ElectricRange", $this->Translate("Elektrische Reichweite"), "", 2);
        $this->RegisterVariableInteger("Charging_State", $this->Translate("Lade-Status"), "SKODA.ChargingState", 3);
        $this->RegisterVariableInteger("Charging_TargetSoC", $this->Translate("Ziel-SoC"), "", 4);

        $this->RegisterVariableString("Charging_ProfilesJson", $this->Translate("Ladeprofile JSON"), "", 30);
        $this->RegisterVariableString("Charging_ProfilesHtml", $this->Translate("Ladeprofile HTML"), "", 31);

        $this->RegisterVariableString("Status_RenderUrl", $this->Translate("Render URL"), "", 39);
        $this->RegisterVariableString("Status_RenderImagePath", $this->Translate("Render Bildpfad"), "", 40);
        $this->RegisterVariableString("Status_FormattedAddress", $this->Translate("Adresse"), "", 41);

        $this->RegisterVariableString("Status_VIN", $this->Translate("Fahrgestellnummer (VIN)"), "", 34);
        $this->RegisterVariableString("Status_VehicleName", $this->Translate("Fahrzeugname"), "", 35);
        $this->RegisterVariableString("Status_LicensePlate", $this->Translate("Kennzeichen"), "", 36);

        $this->RegisterVariableString("Status_TextDoors", $this->Translate("Türen & Schließstatus (Klartext)"), "", 9);
        $this->RegisterVariableString("Status_TextWindows", $this->Translate("Fenster & Schiebedach (Klartext)"), "", 10);
        $this->RegisterVariableString("Status_TextLights", $this->Translate("Beleuchtung (Klartext)"), "", 11);
        $this->RegisterVariableString("Status_TextHealth", $this->Translate("Fahrzeugzustand & Warnungen"), "", 12);

        $this->RegisterVariableInteger("Climate_State", $this->Translate("Klimatisierung Status"), "SKODA.ClimateState", 13);
        $this->EnableAction("Climate_State");

        $this->RegisterVariableFloat("Climate_TargetTemperature", $this->Translate("Soll-Temperatur"), "~Temperature", 14);
        $this->EnableAction("Climate_TargetTemperature");

        $this->RegisterVariableBoolean("Climate_Active", $this->Translate("Klimatisierung Einschalten"), "~Switch", 15);
        $this->EnableAction("Climate_Active");

        $this->RegisterVariableFloat("Climate_RemainingTime", $this->Translate("Verbleibende Laufzeit (Minuten)"), "", 22);

        $this->RegisterVariableInteger("Climate_PowerSource", $this->Translate("Klima Energiequelle"), "SKODA.PowerSource", 23);

        $this->RegisterVariableBoolean("Climate_WindowHeatingFront", $this->Translate("Frontscheibenheizung"), "~Switch", 16);
        $this->EnableAction("Climate_WindowHeatingFront");

        $this->RegisterVariableBoolean("Climate_WindowHeatingRear", $this->Translate("Heckscheibenheizung"), "~Switch", 24);
        $this->EnableAction("Climate_WindowHeatingRear");

        $this->RegisterVariableString("Climate_TextHeating", $this->Translate("Heizungen Status (Klartext)"), "", 25);

        $this->RegisterVariableFloat("Position_Latitude", $this->Translate("Breitengrad (Lat)"), "", 17);
        $this->RegisterVariableFloat("Position_Longitude", $this->Translate("Längengrad (Lon)"), "", 18);
        $this->RegisterVariableInteger("Position_Heading", $this->Translate("Fahrzeugausrichtung (Heading)"), "", 31);
        $this->RegisterVariableString("Position_Timestamp", $this->Translate("Zeitstempel Parkposition"), "", 32);
        $this->RegisterVariableString("Position_Type", $this->Translate("Standort-Typ"), "", 33);
        $this->RegisterVariableString("Position_Map", $this->Translate("Fahrzeug Standorts-Karte"), "~HTMLBox", 20);
        $this->RegisterVariableInteger("API_RateLimitRemaining", $this->Translate("Verbleibende API-Anfragen"), "SKODA.APIRateLimitRemaining", 19);

        $this->SetStatus(102); // Instanz aktiv
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Climate_State':
                if ((int)$Value === 1) { // HEATING
                    $targetTemp = (float)$this->GetValue('Climate_TargetTemperature');
                    $this->StartClimatisation($targetTemp);
                } elseif ((int)$Value === 0) { // OFF
                    $this->StopClimatisation();
                }
                break;

            case 'Climate_Active':
                if ($Value) {
                    $targetTemp = (float)$this->GetValue('Climate_TargetTemperature');
                    $this->StartClimatisation($targetTemp);
                } else {
                    $this->StopClimatisation();
                }
                break;

            case 'Climate_TargetTemperature':
                $this->SetTargetTemperature((float)$Value);
                break;

            case 'Climate_WindowHeatingFront':
                $rearState = (bool)$this->GetValue('Climate_WindowHeatingRear');
                $this->SetWindowHeating((bool)$Value, $rearState);
                break;

            case 'Climate_WindowHeatingRear':
                $frontState = (bool)$this->GetValue('Climate_WindowHeatingFront');
                $this->SetWindowHeating($frontState, (bool)$Value);
                break;

            case 'Charging_Active':
                if ($Value) {
                    $this->StartCharging();
                } else {
                    $this->StopCharging();
                }
                break;

            case 'Charging_TargetSoC':
                $this->SetTargetSoC((int)$Value);
                break;

            case 'Charging_ChargeMode':
                $this->SetChargeMode((int)$Value);
                break;

            default:
                throw new Exception("Ungültiger Ident: " . $Ident);
        }
    }

    public function StartClimatisation(float $temperature = 21.5): bool
    {
        return $this->SendVehicleAction(
            '/air-conditioning/start',
            'POST',
            [
                'targetTemperature' => [
                    'value' => (float)$temperature,
                    'unit' => 'CELSIUS',
                ],
            ],
            'StartClimatisation',
            [
                'Climate_TargetTemperature' => (float)$temperature,
                'Climate_Active' => true,
                'Climate_State' => 1,
            ]
        );
    }

    public function StopClimatisation(): bool
    {
        return $this->SendVehicleAction(
            '/air-conditioning/stop',
            'POST',
            [],
            'StopClimatisation',
            [
                'Climate_Active' => false,
                'Climate_State' => 0,
            ]
        );
    }

    public function SetTargetTemperature(float $temperature): bool
    {
        $temperature = max(5, min(30, $temperature));
        $this->SetValue('Climate_TargetTemperature', (float)$temperature);

        if ((bool)$this->GetValue('Climate_Active')) {
            return $this->StartClimatisation($temperature);
        }

        return true;
    }

    public function SetWindowHeating(bool $front, bool $rear): bool
    {
        $this->SetValue('Climate_WindowHeatingFront', (bool)$front);
        $this->SetValue('Climate_WindowHeatingRear', (bool)$rear);
        $this->SendDebug('SetWindowHeating', 'Die Škoda Public API dokumentiert keinen direkten Endpoint zum Ändern der Fensterheizung. Der Status wird lokal aktualisiert.', 0);

        return true;
    }

    public function StartCharging(): bool
    {
        return $this->SendVehicleAction(
            '/charging/start',
            'POST',
            [],
            'StartCharging',
            [
                'Charging_Active' => true,
                'Charging_State' => 1,
            ]
        );
    }

    public function StopCharging(): bool
    {
        return $this->SendVehicleAction(
            '/charging/stop',
            'POST',
            [],
            'StopCharging',
            [
                'Charging_Active' => false,
                'Charging_State' => 0,
            ]
        );
    }

    public function SetTargetSoC(int $target): bool
    {
        $target = max(0, min(100, $target));
        return $this->SendVehicleAction(
            '/charging/limit',
            'PUT',
            ['targetStateOfChargeInPercent' => $target],
            'SetTargetSoC',
            [
                'Charging_TargetSoC' => $target,
            ]
        );
    }

    public function SetChargeMode(int $mode): bool
    {
        $modeMap = [
            0 => 'MANUAL',
            1 => 'TIMER',
            2 => 'PREFERRED_CHARGING_TIMES',
        ];

        $modeValue = $modeMap[$mode] ?? 'MANUAL';

        return $this->SendVehicleAction(
            '/charging/mode',
            'PUT',
            ['chargeMode' => $modeValue],
            'SetChargeMode',
            [
                'Charging_ChargeMode' => $mode,
            ]
        );
    }

    private function SendVehicleAction(string $endpointSuffix, string $method, array $payload, string $debugName, array $localValues = []): bool
    {
        $apiKey = trim($this->ReadPropertyString('ApiKey'));
        $vin = strtoupper(trim($this->ReadPropertyString('VIN')));

        if (empty($apiKey) || empty($vin)) {
            $this->SetStatus(201);
            $this->SendDebug('Error', 'API Key oder VIN nicht konfiguriert.', 0);
            return false;
        }

        try {
            $this->SendApiRequest('/vehicles/' . $vin . $endpointSuffix, $method, empty($payload) ? null : $payload);

            foreach ($localValues as $identifier => $value) {
                $this->SetValue($identifier, $value);
            }

            $this->SendDebug($debugName, 'Aktion erfolgreich gesendet.', 0);
            return true;
        } catch (Exception $e) {
            $this->SendDebug('Error', $debugName . ': ' . $e->getMessage(), 0);
            $this->LogMessage('MyŠkoda API Fehler: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(202);
            return false;
        }
    }

    /**
     * Hauptfunktion zur Datenaktualisierung mit dynamischem include-Filter
     */
    public function Update()
    {
        $apiKey = trim($this->ReadPropertyString('ApiKey'));
        $vin = strtoupper(trim($this->ReadPropertyString('VIN')));

        if (empty($apiKey) || empty($vin)) {
            $this->SetStatus(201); // Fehlerhafte Konfiguration
            $this->SendDebug('Error', 'API Key oder VIN nicht konfiguriert.', 0);
            return;
        }

        // Dynamischen 'include' Parameter basierend auf den Instanz-Einstellungen aufbauen
        $includes = [];

        if ($this->ReadPropertyBoolean('IncludeInfo')) {
            $includes[] = 'info';
        }
        if ($this->ReadPropertyBoolean('IncludeStatus')) {
            $includes[] = 'status';
        }
        if ($this->ReadPropertyBoolean('IncludeFuelStatus')) {
            $includes[] = 'fuelStatus';
        }
        if ($this->ReadPropertyBoolean('IncludeOdometer')) {
            $includes[] = 'odometer';
        }
        if ($this->ReadPropertyBoolean('IncludeParkingPosition')) {
            $includes[] = 'parkingPosition';
        }
        if ($this->ReadPropertyBoolean('IncludeAirConditioning')) {
            $includes[] = 'airConditioning';
        }
        if ($this->ReadPropertyBoolean('IncludeCharging')) {
            $includes[] = 'charging';
        }
        if ($this->ReadPropertyBoolean('IncludeChargingProfiles')) {
            $includes[] = 'chargingProfiles';
        }
        if ($this->ReadPropertyBoolean('IncludeOperations')) {
            $includes[] = 'operations';
        }

        // Für Sicherheit: wenn keine Option gesetzt ist, Standardliste verwenden
        if (empty($includes)) {
            $includes = ['info', 'status', 'charging', 'chargingProfiles', 'airConditioning', 'parkingPosition', 'fuelStatus', 'odometer', 'operations'];
        }

        // BEV: fuelStatus entfällt automatisch
        if ($this->ReadPropertyBoolean('IsBEV')) {
            $includes = array_values(array_filter($includes, fn($item) => $item !== 'fuelStatus'));
        }

        $queryString = '?include=' . implode(',', $includes);
        $this->SendDebug('Update', "Starte Abfrage für VIN ${vin} mit Filter: ${queryString}", 0);

        try {
            // Abruf des Fahrzeugstatus mit gezieltem include-Filter
            $response = $this->SendApiRequest("/vehicles/${vin}${queryString}", 'GET');

            if (empty($response)) {
                $this->SetStatus(202);
                $this->SendDebug('Error', 'Keine oder ungültige Antwort von der Škoda API erhalten.', 0);
                return;
            }

            // API liefert direkt das Fahrzeugobjekt, nicht verschachtelt in "vehicle"
            $vehicle = is_array($response) && isset($response['vehicle']) ? $response['vehicle'] : $response;

            $this->SendDebug('Update', 'Vehicle Payload: ' . json_encode($vehicle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0);

            $parkingPosition = $vehicle['parkingPosition'] ?? [];
            $gpsCoordinates = $parkingPosition['gpsCoordinates'] ?? [];

            // 0. Fahrzeug-Stammdaten
            if (isset($vehicle['name'])) {
                $this->SetValue('Status_VehicleName', (string)$vehicle['name']);
            }

            if (isset($vehicle['licensePlate'])) {
                $this->SetValue('Status_LicensePlate', (string)$vehicle['licensePlate']);
            }

            if (isset($vehicle['vin'])) {
                $this->SetValue('Status_VIN', (string)$vehicle['vin']);
            }

            // 1. Render URL und PNG speichern
            if (isset($vehicle['renderUrl']) && !empty($vehicle['renderUrl'])) {
                $renderUrl = (string)$vehicle['renderUrl'];
                $this->SetValue('Status_RenderUrl', $renderUrl);

                $imagePath = $this->SaveRenderImage($renderUrl);
                if ($imagePath !== '') {
                    $this->SetValue('Status_RenderImagePath', $imagePath);
                }
            }

            // 2. Adresse
            if (isset($vehicle['parkingPosition']['formattedAddress']) && !empty($vehicle['parkingPosition']['formattedAddress'])) {
                $this->SetValue('Status_FormattedAddress', (string)$vehicle['parkingPosition']['formattedAddress']);
            }
             // 2b. Klartext-Variablen für Türen, Fenster, Beleuchtung und Fahrzeugzustand
            $status = $vehicle['status'] ?? [];
            $overall = $status['overall'] ?? [];
            $detail = $status['detail'] ?? [];

            $this->SetValue('Status_TextDoors', $this->FormatDoorsStatus($overall, $detail));
            $this->SetValue('Status_TextWindows', $this->FormatWindowsStatus($overall, $detail));
            $this->SetValue('Status_TextLights', $this->FormatLightsStatus($overall, $detail));
            $this->SetValue('Status_TextHealth', $this->FormatHealthStatus($vehicle));

            // 2c. Klimatisierungstext wieder herstellen
            $airConditioning = $vehicle['airConditioning'] ?? [];
            if (!empty($airConditioning)) {
                $this->SetValue('Climate_TextHeating', $this->FormatHeatingStatus($airConditioning));
            } else {
                $this->SetValue('Climate_TextHeating', 'Keine Informationen zur Klimatisierung verfügbar.');
            }
            // 3. Kilometerstand
            if (isset($vehicle['odometer']['mileageInKm'])) {
                $this->SetValue('Status_Odometer', (int)$vehicle['odometer']['mileageInKm']);
            }

            // 4. Lade-/Batteriedaten aus charging.status / charging.settings
            $charging = $vehicle['charging'] ?? [];
            $chargingStatus = $charging['status'] ?? [];
            $battery = $chargingStatus['battery'] ?? [];
            $chargingSettings = $charging['settings'] ?? [];

            if (isset($battery['stateOfChargeInPercent'])) {
                $this->SetValue('Charging_BatteryLevel', (int)$battery['stateOfChargeInPercent']);
            }

            if (isset($battery['remainingCruisingRangeInMeters'])) {
                $rangeKm = (int)round((float)$battery['remainingCruisingRangeInMeters'] / 1000);
                $this->SetValue('Charging_ElectricRange', $rangeKm);
            }

            if (isset($chargingSettings['targetStateOfChargeInPercent'])) {
                $this->SetValue('Charging_TargetSoC', (int)$chargingSettings['targetStateOfChargeInPercent']);
            }

            if (isset($chargingStatus['state'])) {
                $state = strtoupper((string)$chargingStatus['state']);
                $mapping = [
                    'OFF' => 0,
                    'NOT_CHARGING' => 0,
                    'CONNECTED' => 0,
                    'READY_FOR_CHARGING' => 2,
                    'CHARGING' => 1,
                    'CONSERVATION' => 3,
                    'CHARGE_PURPOSE_REACHED' => 3,
                    'ERROR' => 4,
                    'FAULT' => 4
                ];

                $this->SetValue('Charging_State', $mapping[$state] ?? 0);
            }

            // 5. Ladeprofile
            if (isset($vehicle['chargingProfiles'])) {
                $chargingProfiles = $vehicle['chargingProfiles'];
                $profiles = $chargingProfiles['profiles'] ?? [];
                $currentProfile = $chargingProfiles['currentVehiclePositionProfile'] ?? null;

                $this->SetValue('Charging_ProfilesJson', json_encode([
                    'profiles' => $profiles,
                    'currentVehiclePositionProfile' => $currentProfile,
                    'carCapturedTimestamp' => $chargingProfiles['carCapturedTimestamp'] ?? null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');

                $this->SetValue('Charging_ProfilesHtml', $this->GenerateChargingProfilesHtml($profiles, $currentProfile));
            } else {
                $this->SetValue('Charging_ProfilesJson', '[]');
                $this->SetValue('Charging_ProfilesHtml', '<div style="padding:12px;color:#64748b;">Keine Ladeprofile verfügbar.</div>');
            }

            // 6. Parkposition
            if ($this->ReadPropertyBoolean('EnablePosition') && !empty($gpsCoordinates)) {
                $lat = (float)($gpsCoordinates['latitude'] ?? 0);
                $lon = (float)($gpsCoordinates['longitude'] ?? 0);

                if ($lat !== 0 || $lon !== 0) {
                    $heading = isset($parkingPosition['heading']) ? (int)$parkingPosition['heading'] : 0;
                    $timestamp = isset($parkingPosition['carCapturedTimestamp']) ? (string)$parkingPosition['carCapturedTimestamp'] : '';
                    $type = isset($parkingPosition['state']) ? (string)$parkingPosition['state'] : 'PARKING_POSITION';

                    $this->SetValue('Position_Latitude', $lat);
                    $this->SetValue('Position_Longitude', $lon);
                    $this->SetValue('Position_Heading', $heading);
                    $this->SetValue('Position_Timestamp', $timestamp);
                    $this->SetValue('Position_Type', $type);

                    if ($this->ReadPropertyBoolean('EnableMap')) {
                        $this->SetValue('Position_Map', $this->GenerateMapHtml($lat, $lon, $heading, $timestamp, $type));
                    }
                }
            }

            $this->SetStatus(102); // Alles OK
            $this->SendDebug('Update', 'Fahrzeugdaten erfolgreich aktualisiert.', 0);

        } catch (Exception $e) {
            $this->SendDebug('Error', 'Fehler beim API-Abruf: ' . $e->getMessage(), 0);
            $this->LogMessage('MyŠkoda API Fehler: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(202);
        }
    }

    private function FormatChargingStatus(array $ch): string
    {
        $state = strtoupper((string)($ch['chargingState'] ?? 'OFF'));
        $plug = strtoupper((string)($ch['plugState'] ?? $ch['plugConnectionState'] ?? 'DISCONNECTED'));
        $soc = (int)($ch['battery']['stateOfChargeInPercent'] ?? $ch['batteryLevel'] ?? 0);
        $targetSoc = (int)($ch['targetStateOfChargeInPercent'] ?? $ch['targetStateOfCharge'] ?? 80);
        $range = (int)($ch['cruisingRangeKm'] ?? $ch['electricRange'] ?? 0);
        $power = (float)($ch['chargePowerInKw'] ?? 0);
        $rate = (float)($ch['chargeRateInKmPerHour'] ?? 0);
        $remaining = (float)($ch['remainingChargingTimeToCompleteInMin'] ?? 0);

        if (in_array($state, ['ERROR', 'FAULT'])) {
            return 'Achtung: Ladefehler an der Wallbox oder am Fahrzeug erkannt!';
        }

        if ($state === 'CHARGING') {
            $timeText = $remaining > 0 ? " Restladezeit: ca. " . round($remaining) . " Min bis ${targetSoc} %." : "";
            $powerText = $power > 0 ? " mit ${power} kW" : "";
            $rateText = $rate > 0 ? " (${rate} km/h)" : "";
            return "Das Ladekabel ist angeschlossen und verriegelt. Das Fahrzeug wird aktiv${powerText}${rateText} geladen. Akkustand: ${soc} % (${range} km).${timeText}";
        }

        if (in_array($state, ['CONSERVATION', 'CHARGE_PURPOSE_REACHED'])) {
            return "Ziel-Ladezustand von ${targetSoc} % erreicht. Das Fahrzeug befindet sich in der Lade-Erhaltung. Akkustand: ${soc} % (${range} km).";
        }

        if ($state === 'READY_FOR_CHARGING' || in_array($plug, ['CONNECTED', 'LOCKED', 'UNLOCKED'])) {
            $lockText = ($plug === 'LOCKED') ? 'und verriegelt' : 'aber unverriegelt';
            return "Das Ladekabel ist angeschlossen ${lockText}. Fahrzeug ist ladebereit. Aktueller Akkustand: ${soc} % (${range} km).";
        }

        return "Kein Ladekabel angeschlossen. Akkustand: ${soc} % (${range} km Reichweite).";
    }

    private function FormatHeatingStatus(array $climate): string
    {
        $activeItems = [];

        if (isset($climate['windowHeating'])) {
            $wh = $climate['windowHeating'];
            if (isset($wh['frontWindow']) && strtoupper((string)$wh['frontWindow']) === 'ON') {
                $activeItems[] = 'Frontscheibenheizung';
            }
            if (isset($wh['rearWindow']) && strtoupper((string)$wh['rearWindow']) === 'ON') {
                $activeItems[] = 'Heckscheibenheizung';
            }
        }

        if (isset($climate['seatHeating'])) {
            $sh = $climate['seatHeating'];
            if (isset($sh['frontLeft']) && in_array(strtoupper((string)$sh['frontLeft']), ['ON', '1', '2', '3'])) {
                $activeItems[] = 'Sitzheizung vorne links';
            }
            if (isset($sh['frontRight']) && in_array(strtoupper((string)$sh['frontRight']), ['ON', '1', '2', '3'])) {
                $activeItems[] = 'Sitzheizung vorne rechts';
            }
        }

        if (isset($climate['steeringWheelHeating']) && in_array(strtoupper((string)$climate['steeringWheelHeating']), ['ON', '1', '2', '3'])) {
            $activeItems[] = 'Lenkradheizung';
        }

        if (empty($activeItems)) {
            return 'Alle Scheiben-, Sitz- und Lenkradheizungen sind ausgeschaltet.';
        }

        if (count($activeItems) === 1) {
            return 'Die ' . $activeItems[0] . ' ist eingeschaltet.';
        }

        $lastItem = array_pop($activeItems);
        return 'Achtung: ' . implode(', ', $activeItems) . ' und ' . $lastItem . ' sind eingeschaltet.';
    }

    private function FormatDoorsStatus(array $overall, array $detail): string
    {
        $doorsLocked = strtoupper((string)($overall['doorsLocked'] ?? 'NO'));
        $doorsState = strtoupper((string)($overall['doors'] ?? ''));
        $lockedState = strtoupper((string)($overall['locked'] ?? 'NO'));

        if ($doorsState === 'OPEN') {
            return 'Achtung: Mindestens eine Tür oder Klappe ist offen.';
        }

        if ($doorsLocked === 'YES' || $lockedState === 'YES') {
            return 'Alle Türen und Klappen sind geschlossen und verriegelt.';
        }

        return 'Achtung: Fahrzeug ist nicht verriegelt, Türen und Klappen sind aber geschlossen.';
    }

    private function FormatWindowsStatus(array $overall, array $detail): string
    {
        $windowsState = strtoupper((string)($overall['windows'] ?? ''));
        if ($windowsState === 'OPEN') {
            return 'Achtung: Mindestens ein Fenster oder Schiebedach ist geöffnet.';
        }

        return 'Alle Fenster und das Schiebedach sind geschlossen.';
    }

    private function FormatLightsStatus(array $overall, array $detail): string
    {
        $lightsState = strtoupper((string)($overall['lights'] ?? ''));
        if ($lightsState === 'ON') {
            return 'Achtung: Die Beleuchtung ist eingeschaltet.';
        }

        return 'Die gesamte Beleuchtung ist ausgeschaltet.';
    }

    private function FormatHealthStatus(array $vehicle): string
    {
        $status = $vehicle['status'] ?? [];
        $overall = $status['overall'] ?? [];
        $detail = $status['detail'] ?? [];

        $warnings = [];

        if (($overall['doorsLocked'] ?? 'NO') === 'NO') {
            $warnings[] = 'Fahrzeug ist nicht verriegelt.';
        }

        if (($detail['sunroof'] ?? '') === 'OPEN') {
            $warnings[] = 'Schiebedach offen.';
        }

        if (($detail['trunk'] ?? '') === 'OPEN') {
            $warnings[] = 'Kofferraum offen.';
        }

        if (($detail['bonnet'] ?? '') === 'OPEN') {
            $warnings[] = 'Motorhaube offen.';
        }

        if (empty($warnings)) {
            return 'Fahrzeugzustand OK. Keine Warnmeldungen vorhanden.';
        }

        return 'Achtung: ' . implode(' | ', $warnings);
    }

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('SKODA.ChargingState')) {
            IPS_CreateVariableProfile('SKODA.ChargingState', 1);
            IPS_SetVariableProfileIcon('SKODA.ChargingState', 'Zap');
            IPS_SetVariableProfileAssociation('SKODA.ChargingState', 0, 'Aus / Lädt nicht', '', -1);
            IPS_SetVariableProfileAssociation('SKODA.ChargingState', 1, 'Wird aktiv geladen', '', 0x58D68D);
            IPS_SetVariableProfileAssociation('SKODA.ChargingState', 2, 'Ladebereit / Wartet', '', 0x5DADE2);
            IPS_SetVariableProfileAssociation('SKODA.ChargingState', 3, 'Ziel-SoC erreicht / Erhaltung', '', 0x58D68D);
            IPS_SetVariableProfileAssociation('SKODA.ChargingState', 4, 'Ladefehler', '', 0xEC7063);
            IPS_SetVariableProfileAssociation('SKODA.ChargingState', 5, 'Nicht verfügbar', '', -1);
        }

        if (!IPS_VariableProfileExists('SKODA.PlugState')) {
            IPS_CreateVariableProfile('SKODA.PlugState', 1);
            IPS_SetVariableProfileIcon('SKODA.PlugState', 'Plug');
            IPS_SetVariableProfileAssociation('SKODA.PlugState', 0, 'Nicht eingesteckt', '', -1);
            IPS_SetVariableProfileAssociation('SKODA.PlugState', 1, 'Eingesteckt (unverriegelt)', '', 0xF4D03F);
            IPS_SetVariableProfileAssociation('SKODA.PlugState', 2, 'Eingesteckt & verriegelt', '', 0x58D68D);
            IPS_SetVariableProfileAssociation('SKODA.PlugState', 3, 'Nicht verfügbar', '', -1);
        }

        if (!IPS_VariableProfileExists('SKODA.ClimateState')) {
            IPS_CreateVariableProfile('SKODA.ClimateState', 1);
            IPS_SetVariableProfileIcon('SKODA.ClimateState', 'Climate');
            IPS_SetVariableProfileAssociation('SKODA.ClimateState', 0, 'Aus (OFF)', '', -1);
            IPS_SetVariableProfileAssociation('SKODA.ClimateState', 1, 'Heizen (HEATING)', '', 0xEC7063);
            IPS_SetVariableProfileAssociation('SKODA.ClimateState', 2, 'Kühlen (COOLING)', '', 0x5DADE2);
            IPS_SetVariableProfileAssociation('SKODA.ClimateState', 3, 'Lüften (VENTILATION)', '', 0x58D68D);
        }

        if (!IPS_VariableProfileExists('SKODA.PowerSource')) {
            IPS_CreateVariableProfile('SKODA.PowerSource', 1);
            IPS_SetVariableProfileIcon('SKODA.PowerSource', 'Plug');
            IPS_SetVariableProfileAssociation('SKODA.PowerSource', 0, 'Aus / Keine', '', -1);
            IPS_SetVariableProfileAssociation('SKODA.PowerSource', 1, 'Hochvoltbatterie (BATTERY)', '', 0x58D68D);
            IPS_SetVariableProfileAssociation('SKODA.PowerSource', 2, 'Ladekabel / Netzstrom (MAINS)', '', 0x5DADE2);
        }

        if (!IPS_VariableProfileExists('SKODA.ChargeMode')) {
            IPS_CreateVariableProfile('SKODA.ChargeMode', 1);
            IPS_SetVariableProfileIcon('SKODA.ChargeMode', 'Zap');
            IPS_SetVariableProfileAssociation('SKODA.ChargeMode', 0, 'Sofortladen (MANUAL)', '', -1);
            IPS_SetVariableProfileAssociation('SKODA.ChargeMode', 1, 'Zeitgesteuert (TIMER)', '', -1);
            IPS_SetVariableProfileAssociation('SKODA.ChargeMode', 2, 'Reduziert / Battery Care', '', -1);
        }

        if (!IPS_VariableProfileExists('SKODA.TargetSoC')) {
            IPS_CreateVariableProfile('SKODA.TargetSoC', 1);
            IPS_SetVariableProfileIcon('SKODA.TargetSoC', 'Battery');
            IPS_SetVariableProfileText('SKODA.TargetSoC', '', ' %');
            IPS_SetVariableProfileValues('SKODA.TargetSoC', 0, 100, 5);

            $colorBlue  = 0x5DADE2;
            $colorGreen = 0x58D68D;
            $colorRed   = 0xEC7063;

            for ($v = 0; $v <= 100; $v += 10) {
                $color = $colorBlue;
                if ($v > 20 && $v <= 80) {
                    $color = $colorGreen;
                } elseif ($v > 80) {
                    $color = $colorRed;
                }
                IPS_SetVariableProfileAssociation('SKODA.TargetSoC', $v, (string)$v . ' %', '', $color);
            }
        }

        if (!IPS_VariableProfileExists('SKODA.APIRateLimitRemaining')) {
            IPS_CreateVariableProfile('SKODA.APIRateLimitRemaining', 1);
            IPS_SetVariableProfileIcon('SKODA.APIRateLimitRemaining', 'Information');
            IPS_SetVariableProfileText('SKODA.APIRateLimitRemaining', '', ' übrig');
            IPS_SetVariableProfileValues('SKODA.APIRateLimitRemaining', 0, 20, 1);
        }
    }

    private function GenerateChargingProfilesHtml(array $profiles, ?array $currentProfile = null): string
    {
        if (empty($profiles)) {
            return '<div style="padding:12px;color:#64748b;">Keine Ladeprofile in der Antwort der Škoda API gefunden.</div>';
        }

        $cards = [];
        foreach ($profiles as $profile) {
            $id = (int)($profile['id'] ?? 0);
            $name = htmlspecialchars((string)($profile['name'] ?? 'Unbenannt'), ENT_QUOTES, 'UTF-8');
            $settings = $profile['settings'] ?? [];
            $targetSoc = $settings['targetStateOfChargeInPercent'] ?? '—';
            $maxChargingCurrent = $settings['maxChargingCurrent'] ?? '—';
            $minBatteryEnabled = (bool)($settings['minBatteryStateOfCharge']['enabled'] ?? false);
            $minBattery = $settings['minBatteryStateOfCharge']['minimumBatteryStateOfChargeInPercent'] ?? '—';
            $preferredTimesCount = count($profile['preferredChargingTimes'] ?? []);
            $timerCount = count($profile['timers'] ?? []);
            $isCurrent = $currentProfile !== null && (int)($currentProfile['id'] ?? 0) === $id;

            $badge = $isCurrent
                ? '<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:700;">Aktives Profil</span>'
                : '<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;">Gespeichert</span>';

            $cards[] = '
                <div style="border:1px solid #dfe7f1;border-left:4px solid #0ea5e9;border-radius:18px;background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);padding:16px;box-shadow:0 10px 24px rgba(15,23,42,0.08);margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,#0ea5e9,#2563eb);display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 8px 18px rgba(37,99,235,0.25);">⚡</div>
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#0f172a;line-height:1.2;">' . $name . '</div>
                                <div style="font-size:12px;color:#64748b;margin-top:2px;">Profil-ID: ' . $id . '</div>
                            </div>
                        </div>
                        ' . $badge . '
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">
                        <div style="background:linear-gradient(180deg,#f8fafc 0%,#eef6ff 100%);border:1px solid #dbeafe;border-radius:12px;padding:10px;">
                            <div style="font-size:10px;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">Ziel-SoC</div>
                            <div style="font-size:20px;font-weight:800;color:#0f172a;margin-top:4px;">' . htmlspecialchars((string)$targetSoc, ENT_QUOTES, 'UTF-8') . ' %</div>
                        </div>
                        <div style="background:linear-gradient(180deg,#f8fafc 0%,#effaf5 100%);border:1px solid #bfdbd0;border-radius:12px;padding:10px;">
                            <div style="font-size:10px;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">Min. SoC</div>
                            <div style="font-size:20px;font-weight:800;color:#0f172a;margin-top:4px;">' . htmlspecialchars((string)$minBattery, ENT_QUOTES, 'UTF-8') . ' %</div>
                        </div>
                        <div style="background:linear-gradient(180deg,#f8fafc 0%,#f0fdf4 100%);border:1px solid #bbf7d0;border-radius:12px;padding:10px;">
                            <div style="font-size:10px;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">Ladestrom</div>
                            <div style="font-size:20px;font-weight:800;color:#0f172a;margin-top:4px;">' . htmlspecialchars((string)$maxChargingCurrent, ENT_QUOTES, 'UTF-8') . '</div>
                        </div>
                        <div style="background:linear-gradient(180deg,#f8fafc 0%,#f5f3ff 100%);border:1px solid #ddd6fe;border-radius:12px;padding:10px;">
                            <div style="font-size:10px;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">Timings</div>
                            <div style="font-size:20px;font-weight:800;color:#0f172a;margin-top:4px;">' . $preferredTimesCount . ' / ' . $timerCount . '</div>
                        </div>
                    </div>
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0;font-size:12px;color:#475569;line-height:1.7;display:flex;flex-wrap:wrap;gap:12px;">
                        <div><strong>Min. SoC sofort laden:</strong> ' . ($minBatteryEnabled ? 'Ja' : 'Nein') . '</div>
                        <div><strong>Preferred Charging Times:</strong> ' . $preferredTimesCount . '</div>
                        <div><strong>Timer:</strong> ' . $timerCount . '</div>
                    </div>
                </div>
            ';
        }

        return '<div style="font-family:Segoe UI,sans-serif;padding:8px;">' . implode('', $cards) . '</div>';
    }

    private function SaveRenderImage(string $renderUrl): string
    {
        try {
            $imageData = @file_get_contents($renderUrl);

            if ($imageData === false || strlen($imageData) === 0) {
                $this->SendDebug('SaveRenderImage', 'Renderbild konnte nicht geladen werden.', 0);
                return '';
            }

            $dir = dirname(__FILE__) . '/render';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $fileName = 'render_' . md5($renderUrl) . '.png';
            $filePath = $dir . '/' . $fileName;

            if (file_put_contents($filePath, $imageData) === false) {
                $this->SendDebug('SaveRenderImage', 'Renderbild konnte nicht gespeichert werden: ' . $filePath, 0);
                return '';
            }

            $this->SendDebug('SaveRenderImage', 'Renderbild gespeichert unter: ' . $filePath, 0);
            return $filePath;
        } catch (Exception $e) {
            $this->SendDebug('SaveRenderImage', 'Fehler beim Speichern des Renderbilds: ' . $e->getMessage(), 0);
            return '';
        }
    }

    private function SendApiRequest(string $endpoint, string $method = 'GET', $payload = null)
    {
        $apiKey = trim($this->ReadPropertyString('ApiKey'));
        $url = self::API_BASE_URL . $endpoint;

        $headers = [
            'X-API-Key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, true);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($payload) ? json_encode($payload) : $payload);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        if (preg_match('/RateLimit-Remaining:\s*(\d+)/i', $responseHeaders, $matches)) {
            $remaining = (int)$matches[1];
            $this->SetValue('API_RateLimitRemaining', $remaining);
            $this->SendDebug('RateLimit', "Verbleibende Anfragen in diesem Zeitfenster: ${remaining}", 0);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($responseBody, true) ?? [];
        }

        if ($httpCode === 429) {
            throw new Exception("API Rate Limit überschritten (HTTP 429). Bitte Abfrageintervall erhöhen.");
        }

        if ($httpCode === 401) {
            throw new Exception("API Key abgelaufen oder ungültig (HTTP 401). Neuer Key in MyŠkoda App erforderlich.");
        }

        throw new Exception("Škoda API Fehler HTTP ${httpCode}: " . $responseBody);
    }

    private function GenerateMapHtml(float $lat, float $lon, int $heading, string $timestamp, string $type): string
    {
        $directionText = $this->GetHeadingDirectionText($heading);
        $formattedTime = !empty($timestamp) ? date('d.m.Y H:i:s', strtotime($timestamp)) : 'Unbekannt';

        $svgIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#0284c7" stroke="#ffffff" stroke-width="1.5"><circle cx="12" cy="12" r="11" fill="#0f172a" stroke="#0284c7" stroke-width="2"/><path d="M12 3L18 19L12 16L6 19L12 3Z" fill="#38bdf8"/></svg>';

        $popupHtml = '<div style="font-family:sans-serif; font-size:12px;">'
            . '<b>Škoda Parkposition</b><br>'
            . 'Ausrichtung: ' . $heading . '° (' . $directionText . ')<br>'
            . 'Typ: ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Zeit: ' . $formattedTime
            . '</div>';

        $infoHtml = '<div class="info-title">📍 Parkposition Details</div>'
            . '<div class="info-grid">'
            . '<span>Typ:</span><span class="info-val">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span>'
            . '<span>Ausrichtung:</span><span class="info-val">' . $heading . '° (' . $directionText . ')</span>'
            . '<span>Gesendet:</span><span class="info-val">' . $formattedTime . '</span>'
            . '<span>Koordinaten:</span><span class="info-val">' . round($lat, 5) . ', ' . round($lon, 5) . '</span>'
            . '</div>';

        $svgIconJson = json_encode($svgIcon, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $popupHtmlJson = json_encode($popupHtml, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $infoHtmlJson = json_encode($infoHtml, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $html = '<!DOCTYPE html><html><head>';
        $html .= '<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>';
        $html .= '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>';
        $html .= '<style>';
        $html .= 'body, html { margin:0; padding:0; height:100%; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: linear-gradient(180deg, #eff6ff 0%, #e2e8f0 100%); }';
        $html .= '#map { width:100%; height:100%; min-height: 320px; border-radius: 16px; border: 1px solid #dfe7f1; overflow: hidden; box-shadow: 0 12px 24px rgba(15, 23, 42, 0.10); }';
        $html .= '.car-icon-container { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; }';
        $html .= '.car-marker { width: 36px; height: 36px; transition: transform 0.5s ease; filter: drop-shadow(0px 3px 6px rgba(0,0,0,0.4)); }';
        $html .= '.info-card { background: linear-gradient(180deg, rgba(15,23,42,0.92) 0%, rgba(30,41,59,0.90) 100%); backdrop-filter: blur(8px); color: #f8fafc; padding: 12px 14px; border-radius: 14px; border: 1px solid rgba(148,163,184,0.35); font-size: 12px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.28); }';
        $html .= '.info-title { font-weight: 700; color: #7dd3fc; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }';
        $html .= '.info-grid { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; color: #cbd5e1; }';
        $html .= '.info-val { font-weight: 700; color: #ffffff; }';
        $html .= '</style></head><body>';
        $html .= '<div id="map"></div>';
        $html .= '<script>';
        $html .= 'var map = L.map("map").setView([' . $lat . ', ' . $lon . '], 16);';
        $html .= 'L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { attribution: "&copy; OpenStreetMap" }).addTo(map);';
        $html .= 'var carIcon = L.divIcon({';
        $html .= '  className: "car-icon-container",';
        $html .= '  html: ' . $svgIconJson . ',';
        $html .= '  iconSize: [40, 40], iconAnchor: [20, 20]';
        $html .= '});';
        $html .= 'var marker = L.marker([' . $lat . ', ' . $lon . '], {icon: carIcon}).addTo(map);';
        $html .= 'marker.bindPopup(' . $popupHtmlJson . ');';
        $html .= 'var infoBox = L.control({position: "bottomleft"});';
        $html .= 'infoBox.onAdd = function(map) {';
        $html .= '  var div = L.DomUtil.create("div", "info-card");';
        $html .= '  div.innerHTML = ' . $infoHtmlJson . ';';
        $html .= '  return div;';
        $html .= '};';
        $html .= 'infoBox.addTo(map);';
        $html .= '</script></body></html>';

        return $html;
    }

    private function GetHeadingDirectionText(int $heading): string
    {
        $heading = ($heading % 360 + 360) % 360;
        $directions = ['Nord', 'Nord-Ost', 'Ost', 'Süd-Ost', 'Süd', 'Süd-West', 'West', 'Nord-West'];
        $index = (int)round($heading / 45) % 8;
        return $directions[$index];
    }

    /**
    * If the HTML-SDK is to be used, this function must be overwritten in order to return the HTML content.
    *
    * @return string Initial display of a representation via HTML SDK
    */
    public function GetVisualizationTile(): string
    {
        $imagePath = $this->GetValue('Status_RenderImagePath');

        if (empty($imagePath) || !is_file($imagePath)) {
            return '<div style="padding:12px;color:#64748b;">Noch kein Fahrzeugbild verfügbar.</div>';
        }

        $imageData = @file_get_contents($imagePath);
        if ($imageData === false) {
            return '<div style="padding:12px;color:#b91c1c;">Bild konnte nicht geladen werden.</div>';
        }

        $mime = $this->DetectMimeType($imagePath);
        $dataUri = 'data:' . $mime . ';base64,' . base64_encode($imageData);

        return '
            <div style="padding:8px;">
                <img src="' . $dataUri . '" style="width:100%;max-width:100%;height:auto;border-radius:12px;display:block;" />
            </div>
        ';
    }

    /**
    * Generate a message that updates all elements in the HTML display.
    *
    * @return string JSON encoded message information
    */
    private function GetFullUpdateMessage(): string
    {
        // Fill resultset
        $result = [];
        $result['stocktext'] = $this->ReadPropertyString('StockLabel');
        $result['stockfont'] = $this->ReadPropertyInteger('StockFont');
        $result['trendtext'] = $this->ReadPropertyFormatted('TrendVariable');
        $result['trendfont'] = $this->ReadPropertyInteger('TrendFont');
        $result['trendpositive'] = $this->GetColorFormatted($this->ReadPropertyInteger('TrendPositive'));
        $result['trendnegative'] = $this->GetColorFormatted($this->ReadPropertyInteger('TrendNegative'));
        $result['chartline'] = $this->GetColorFormatted($this->ReadPropertyInteger('ChartLine'));
        $result['chartperiod'] = $this->Translate(self::TWSW_MAP_PERIOD[$this->ReadPropertyInteger('ChartData')]);
        $result['chartsmooth'] = $this->ReadPropertyBoolean('ChartSmooth');
        $result['chartfill'] = $this->ReadPropertyBoolean('ChartFill');
        $result['chartoffset'] = $this->ReadPropertyInteger('ChartOffset');
        $result['chartdata'] = $this->ReadCacheArray();
        $result['pricetext'] = $this->ReadPropertyFormatted('PriceVariable');
        $result['pricefont'] = $this->ReadPropertyInteger('PriceFont');
        $this->LogDebug(__FUNCTION__, $result);
        // send it
        return json_encode($result);
    }

    private function BuildOpenStreetMapUrl(float $lat, float $lon): string
    {
        $bbox = [
            $lon - 0.005,
            $lat - 0.005,
            $lon + 0.005,
            $lat + 0.005
        ];

        return 'https://www.openstreetmap.org/export/embed.html?bbox='
            . implode(',', $bbox)
            . '&layer=mapnik&marker='
            . $lat . ',' . $lon;
    }
}
