<?php
declare(strict_types=1);

class BestFuelPrice extends IPSModule
{
    private const VERSION = '1.8';
    private const BUILD   = 10;

    // Fixed Tankerkönig module id (instances to consider)
    private const TANKERKOENIG_MODULE_ID = '47286CAD-187A-6D88-89F0-BDA50CBF712F';

    // Variable idents in station instances
    private const IDENT_PATROLSTATION = 'PetrolStation';
    private const IDENT_STATE         = 'State';
    private const IDENT_DISTANCE      = 'DistanceKm';

    // Output variables in this module instance
    private const OUT_TIME    = 'BestTime';
    private const OUT_PRICE   = 'BestPrice';
    private const OUT_NAME    = 'BestStation';
    private const OUT_DIST    = 'BestDistance';
    private const OUT_ROUTE   = 'BestRoute';

    // Profiles
    private const PROFILE_PRICE = 'Tankerkoenig.PricePerLiter';
    private const PROFILE_DIST  = 'Tankerkoenig.DistanceKM';

    // Archive module guid
    private const ARCHIVE_MODULE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    public function Create(): void
    {
        parent::Create();

        // Core selection
        $this->RegisterPropertyInteger('StationsCategoryID', 0);
        $this->RegisterPropertyString('FuelIdent', 'Diesel'); // Diesel|E5|E10
        $this->RegisterPropertyBoolean('OnlyOpen', true);

        // Debug
        $this->RegisterPropertyBoolean('EnableDebug', false);

        // Distance options
        $this->RegisterPropertyBoolean('EnableDistance', false);
        $this->RegisterPropertyBoolean('WriteDistanceToStations', true);
        $this->RegisterPropertyFloat('MaxDistanceKm', 5.0);

        // Minutes in UI, converted internally
        $this->RegisterPropertyInteger('DistanceUpdateIntervalMinutes', 1440); // 24h
        $this->RegisterPropertyInteger('AutoUpdateIntervalMinutes', 0);        // 0=off, min=10

        // Location source
        $this->RegisterPropertyBoolean('UseLocationControl', true);
        $this->RegisterPropertyInteger('LocationControlID', 0); // instance
        $this->RegisterPropertyString('OwnLocation', '');       // "lat, lng" from SelectLocation

        // Google Maps (instance)
        $this->RegisterPropertyInteger('GoogleMapsInstanceID', 0);

        // Timer: do NOT rely on $_IPS["TARGET"]
        $this->RegisterTimer('AutoUpdate', 0, 'BFP_Update(' . $this->InstanceID . ');');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();

        // Output variables
        $this->RegisterVariableInteger(self::OUT_TIME, 'Zeit', '~UnixTimestamp', 10);
        $this->RegisterVariableFloat(self::OUT_PRICE, 'Preis', self::PROFILE_PRICE, 20);
        $this->RegisterVariableString(self::OUT_NAME, 'Tankstelle', '~TextBox', 30);
        $this->RegisterVariableFloat(self::OUT_DIST, 'Entfernung', self::PROFILE_DIST, 40);
        $this->RegisterVariableString(self::OUT_ROUTE, 'Route', '~HTMLBox', 50);

        IPS_SetIcon($this->GetIDForIdent(self::OUT_PRICE), 'Fuel');
        IPS_SetIcon($this->GetIDForIdent(self::OUT_DIST), 'Distance');
        IPS_SetIcon($this->GetIDForIdent(self::OUT_ROUTE), 'Map');

        // Remove legacy output variable if present
        $legacy = @IPS_GetObjectIDByIdent('BestStationInstanceID', $this->InstanceID);
        if (is_int($legacy) && $legacy > 0 && IPS_ObjectExists($legacy)) {
            @IPS_DeleteVariable($legacy);
        }

        // Archive price
        $this->EnableArchiveLogging($this->GetIDForIdent(self::OUT_PRICE));

        // Timer interval from minutes; enforce min 10 min if enabled
        $min = max(0, (int)$this->ReadPropertyInteger('AutoUpdateIntervalMinutes'));
        if ($min > 0 && $min < 10) {
            $this->Dbg('Timer', 'AutoUpdateIntervalMinutes < 10 -> clamp to 10', 0, true);
            $min = 10;
        }
        $this->SetTimerInterval('AutoUpdate', $min > 0 ? $min * 60 * 1000 : 0);

        $this->ValidateConfiguration();
    }

    public function GetConfigurationForm(): string
    {
        $enableDistance = $this->ReadPropertyBoolean('EnableDistance');
        $useLC          = $this->ReadPropertyBoolean('UseLocationControl');

        $fuelOptions = [
            ['caption' => 'Diesel', 'value' => 'Diesel'],
            ['caption' => 'E5',     'value' => 'E5'],
            ['caption' => 'E10',    'value' => 'E10']
        ];

        $form = [
            'elements' => [
                [
                    'type'    => 'SelectCategory',
                    'name'    => 'StationsCategoryID',
                    'caption' => 'Kategorie mit Tankerkönig Instanzen'
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'FuelIdent',
                    'caption' => 'Kraftstoff',
                    'options' => $fuelOptions
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'OnlyOpen',
                    'caption' => 'Nur geöffnete Tankstellen berücksichtigen'
                ],

                ['type' => 'Label', 'caption' => '— Distanz (optional) —'],
                ['type' => 'CheckBox', 'name' => 'EnableDistance', 'caption' => 'Distanzberechnung aktivieren (GoogleMaps Modul notwendig)'],
                ['type' => 'CheckBox', 'name' => 'WriteDistanceToStations', 'caption' => 'Distanz Variable in Tankstellen-Instanzen anlegen', 'visible' => $enableDistance],
                ['type' => 'NumberSpinner', 'name' => 'DistanceUpdateIntervalMinutes', 'caption' => 'Distanz-Update Intervall (Minuten)', 'visible' => $enableDistance],
                ['type' => 'NumberSpinner', 'name' => 'MaxDistanceKm', 'caption' => 'Maximale Entfernung (km) für Bestpreis Berücksichtigung', 'visible' => $enableDistance],

                ['type' => 'CheckBox', 'name' => 'UseLocationControl', 'caption' => 'Standort aus LocationControl verwenden', 'visible' => $enableDistance],
                ['type' => 'SelectInstance', 'name' => 'LocationControlID', 'caption' => 'LocationControl Instanz', 'visible' => $enableDistance && $useLC],
                ['type' => 'SelectLocation', 'name' => 'OwnLocation', 'caption' => 'Eigener Standort (lat, lng)', 'visible' => $enableDistance && !$useLC],
                ['type' => 'SelectInstance', 'name' => 'GoogleMapsInstanceID', 'caption' => 'GoogleMaps Instanz', 'visible' => $enableDistance],

                ['type' => 'Label', 'caption' => '— Automatik (optional) —'],
                ['type' => 'NumberSpinner', 'name' => 'AutoUpdateIntervalMinutes', 'caption' => 'Automatisch berechnen alle X Minuten (0=aus, min. 10)'],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'EnableDebug',
                    'caption' => 'Debug aktivieren'
                ],

            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt berechnen', 'onClick' => 'BFP_Update(' . $this->InstanceID . ');']
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'OK'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Konfiguration unvollständig']
            ]
        ];

        return json_encode($form);
    }

    public function Update(): void
    {
        $this->Dbg('Build', self::VERSION . ' (build ' . self::BUILD . ')', 0, true);
        $this->Dbg('Update', 'Start InstanceID=' . $this->InstanceID . ' ' . date('c'), 0, true);

        $this->ValidateConfiguration(true);

        $categoryId     = (int)$this->ReadPropertyInteger('StationsCategoryID');
        $fuelIdent      = (string)$this->ReadPropertyString('FuelIdent');
        $onlyOpen       = (bool)$this->ReadPropertyBoolean('OnlyOpen');

        $enableDistance = (bool)$this->ReadPropertyBoolean('EnableDistance');
        $maxKm          = (float)$this->ReadPropertyFloat('MaxDistanceKm');
        $writeDistance  = (bool)$this->ReadPropertyBoolean('WriteDistanceToStations');

        $distIntervalMin = max(0, (int)$this->ReadPropertyInteger('DistanceUpdateIntervalMinutes'));
        $distIntervalSec = $distIntervalMin * 60;

        // If distance enabled, test origin NOW so we always see issues
        if ($enableDistance) {
            try {
                $origin = $this->GetOriginLatLng();
                $this->Dbg('Origin.LatLng', 'lat=' . $origin['lat'] . ' lng=' . $origin['lng'], 0, true);
            } catch (Throwable $e) {
                $this->Dbg('Origin.Error', $e->getMessage(), 0, true);

                // IMPORTANT: Only $this->SetValue()
                $this->SetValue(self::OUT_TIME, 0);
                $this->SetValue(self::OUT_PRICE, 0.0);
                $this->SetValue(self::OUT_NAME, 'Standort ungültig: ' . $e->getMessage());
                $this->SetValue(self::OUT_DIST, 0.0);
                $this->SetValue(self::OUT_ROUTE, '<div style="padding:8px">Standort kann nicht gelesen werden.</div>');
                return;
            }
        }

        $instances = $this->GetChildInstancesRecursive($categoryId);
        $this->Dbg('Scan', 'Instances in tree: ' . count($instances), 0, false);

        $best = null;

        $normalizedExpected = $this->NormalizeGuid(self::TANKERKOENIG_MODULE_ID);

        foreach ($instances as $iid) {
            $inst = IPS_GetInstance($iid);
            $mid = '';
            if (isset($inst['ModuleInfo']) && is_array($inst['ModuleInfo']) && isset($inst['ModuleInfo']['ModuleID'])) {
                $mid = (string)$inst['ModuleInfo']['ModuleID'];
            }
            if ($this->NormalizeGuid($mid) !== $normalizedExpected) {
                continue;
            }

            if ($onlyOpen) {
                $stateVar = $this->FindVariableRecursiveByIdent($iid, self::IDENT_STATE);
                if ($stateVar === null) continue;
                if ((int)GetValue($stateVar) !== 1) continue;
            }

            $fuelVar = $this->FindVariableRecursiveByIdent($iid, $fuelIdent);
            if ($fuelVar === null) continue;

            $price = $this->ParsePriceToFloat(GetValue($fuelVar));
            if ($price === null || $price <= 0) continue;

            $priceTime = (int)(IPS_GetVariable($fuelVar)['VariableUpdated'] ?? time());

            $distanceKm = null;
            if ($enableDistance) {
                $distanceKm = $this->GetOrUpdateDistanceKm($iid, $distIntervalSec, $writeDistance);
                if ($distanceKm === null || !is_finite($distanceKm) || $distanceKm <= 0.001) continue;
                if ($maxKm > 0 && $distanceKm > $maxKm) continue;
            }

            if ($best === null) {
                $best = ['instanceId' => $iid, 'price' => $price, 'time' => $priceTime, 'distanceKm' => $distanceKm];
                continue;
            }

            if ($price < (float)$best['price']) {
                $best = ['instanceId' => $iid, 'price' => $price, 'time' => $priceTime, 'distanceKm' => $distanceKm];
                continue;
            }

            // tie-break: nearer wins if distance enabled
            if ($enableDistance && abs($price - (float)$best['price']) < 0.0005) {
                if ($distanceKm !== null && $best['distanceKm'] !== null && (float)$distanceKm < (float)$best['distanceKm']) {
                    $best = ['instanceId' => $iid, 'price' => $price, 'time' => $priceTime, 'distanceKm' => $distanceKm];
                }
            }
        }

        if ($best === null) {
            $this->Dbg('Result', 'No candidate found', 0, true);
            $this->SetValue(self::OUT_TIME, 0);
            $this->SetValue(self::OUT_PRICE, 0.0);
            $this->SetValue(self::OUT_NAME, 'Kein passender Kandidat gefunden');
            $this->SetValue(self::OUT_DIST, 0.0);
            $this->SetValue(self::OUT_ROUTE, '<div style="padding:8px">Keine Route verfügbar.</div>');
            return;
        }

        $iid  = (int)$best['instanceId'];
        $addr = $this->BuildTankstelleArrayFromInstance($iid);
        
        $stationName = IPS_GetName($iid);
        $this->Dbg('stationName ', $stationName , 0, false);
        if (is_array($addr) && isset($addr['station_display_name']) && trim((string)$addr['station_display_name']) !== '') {
            $stationName = (string)$addr['station_display_name'];
        }
        
        $routeHtml = '<div style="padding:8px">Keine Route verfügbar.</div>';
        if ($enableDistance && is_array($addr)) {
            try {
                $routeHtml = $this->ComputeRouteHtml($addr);
            } catch (Throwable $e) {
                $this->Dbg('Route.Error', $e->getMessage(), 0, true);
                $routeHtml = '<div style="padding:8px">Route konnte nicht berechnet werden: ' .
                    htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>';
            }
        }

        $this->Dbg('Best', json_encode([
            'instanceId' => $iid,
            'name' => $stationName,
            'price' => (float)$best['price'],
            'time' => (int)$best['time'],
            'distanceKm' => $best['distanceKm']
        ]), 0, true);

        $this->SetValue(self::OUT_TIME, (int)$best['time']);
        $this->SetValue(self::OUT_PRICE, (float)$best['price']);
        $this->SetValue(self::OUT_NAME, $stationName);
        $this->SetValue(self::OUT_DIST, (float)($best['distanceKm'] ?? 0.0));
        $this->SetValue(self::OUT_ROUTE, $routeHtml);
    }

    // ---------------------------
    // Debug
    // ---------------------------
    private function Dbg(string $topic, $data, int $format, bool $alsoLogMessage): void
    {
        if (!$this->ReadPropertyBoolean('EnableDebug')) {
            return;
        }
        if (is_string($data) && strlen($data) > 5000) {
            $data = substr($data, 0, 5000) . '…';
        }
        $this->SendDebug($topic, (string)$data, $format);

        if ($alsoLogMessage) {
            IPS_LogMessage('BestFuelPrice/' . $topic, (string)$data);
        }
    }

    private function NormalizeGuid(string $s): string
    {
        $s = strtoupper(trim($s));
        $s = str_replace(['{', '}', ' '], '', $s);
        return $s;
    }

    // ---------------------------
    // Distance handling
    // ---------------------------
    private function GetOrUpdateDistanceKm(int $stationInstanceId, int $intervalSeconds, bool $writeToStation): ?float
    {
        $distVarId = @IPS_GetObjectIDByIdent(self::IDENT_DISTANCE, $stationInstanceId);

        if ((!$distVarId || !IPS_ObjectExists($distVarId)) && $writeToStation) {
            $distVarId = IPS_CreateVariable(VARIABLETYPE_FLOAT);
            IPS_SetParent($distVarId, $stationInstanceId);
            IPS_SetIdent($distVarId, self::IDENT_DISTANCE);
            IPS_SetName($distVarId, 'Distanz');
            IPS_SetVariableCustomProfile($distVarId, self::PROFILE_DIST);
            IPS_SetIcon($distVarId, 'Distance');
	    IPS_SetPosition($distVarId, 900);
        }

        $distanceKm = null;
        $updated = 0;

        if ($distVarId && IPS_ObjectExists($distVarId)) {
            $distanceKm = (float)GetValue($distVarId);
            $updated = (int)(IPS_GetVariable($distVarId)['VariableUpdated'] ?? 0);
        }

        $due = ($intervalSeconds <= 0) ? true : ($updated == 0 || (time() - $updated) >= $intervalSeconds);

        if (($distanceKm === null) || $distanceKm <= 0.001 || $due) {
            $addr = $this->BuildTankstelleArrayFromInstance($stationInstanceId);
            if (!is_array($addr)) return null;

            $distanceKm = $this->ComputeDistanceKm($addr);

            if ($writeToStation && $distVarId && IPS_ObjectExists($distVarId)) {
                SetValue($distVarId, $distanceKm);
            }
        }

        return $distanceKm;
    }

    // ---------------------------
    // Validation / Status
    // ---------------------------
    private function ValidateConfiguration(bool $throwOnError = false): void
    {
        $categoryId = (int)$this->ReadPropertyInteger('StationsCategoryID');
        if ($categoryId <= 0 || !IPS_ObjectExists($categoryId)) {
            $this->SetStatus(104);
            if ($throwOnError) throw new Exception('StationsCategoryID ist nicht gesetzt/ungültig.');
            return;
        }

        $enableDistance = (bool)$this->ReadPropertyBoolean('EnableDistance');
        if ($enableDistance) {
            $gm = (int)$this->ReadPropertyInteger('GoogleMapsInstanceID');
            if ($gm <= 0 || !IPS_ObjectExists($gm)) {
                $this->SetStatus(104);
                if ($throwOnError) throw new Exception('GoogleMapsInstanceID ist nicht gesetzt/ungültig.');
                return;
            }

            if ($this->ReadPropertyBoolean('UseLocationControl')) {
                $lc = (int)$this->ReadPropertyInteger('LocationControlID');
                if ($lc <= 0 || !IPS_ObjectExists($lc)) {
                    $this->SetStatus(104);
                    if ($throwOnError) throw new Exception('LocationControlID ist nicht gesetzt/ungültig.');
                    return;
                }
            }
        }

        $this->SetStatus(102);
    }

    // ---------------------------
    // Profiles / Archive
    // ---------------------------
    private function EnsureProfiles(): void
    {
        if (!in_array(self::PROFILE_PRICE, IPS_GetVariableProfileList(), true)) {
            IPS_CreateVariableProfile(self::PROFILE_PRICE, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits(self::PROFILE_PRICE, 3);
            IPS_SetVariableProfileText(self::PROFILE_PRICE, '', ' €/l');
            IPS_SetVariableProfileValues(self::PROFILE_PRICE, 0, 5, 0.001);
            IPS_SetVariableProfileIcon(self::PROFILE_PRICE, 'Fuel');
        }

        if (!in_array(self::PROFILE_DIST, IPS_GetVariableProfileList(), true)) {
            IPS_CreateVariableProfile(self::PROFILE_DIST, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits(self::PROFILE_DIST, 2);
            IPS_SetVariableProfileText(self::PROFILE_DIST, '', ' km');
            IPS_SetVariableProfileValues(self::PROFILE_DIST, 0, 500, 0.01);
            IPS_SetVariableProfileIcon(self::PROFILE_DIST, 'Distance');
        }
    }

    private function EnableArchiveLogging(int $varId): void
    {
        $archives = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE_GUID);
        if (empty($archives)) return;

        $archiveId = (int)$archives[0];
        if (!AC_GetLoggingStatus($archiveId, $varId)) {
            AC_SetLoggingStatus($archiveId, $varId, true);
            AC_SetAggregationType($archiveId, $varId, 0);
            AC_ReAggregateVariable($archiveId, $varId);
        }
    }

    // ---------------------------
    // Station discovery helpers
    // ---------------------------
    private function GetChildInstancesRecursive(int $parentId): array
    {
        $result = [];
        $stack = [$parentId];

        while (!empty($stack)) {
            $id = array_pop($stack);
            foreach (IPS_GetChildrenIDs($id) as $childId) {
                $obj = IPS_GetObject($childId);
                $type = $obj['ObjectType'] ?? -1;

                if ($type === OBJECTTYPE_INSTANCE) {
                    $result[] = $childId;
                } elseif ($type === OBJECTTYPE_CATEGORY) {
                    $stack[] = $childId;
                }
            }
        }

        sort($result);
        return $result;
    }

    private function FindVariableRecursiveByIdent(int $parentId, string $ident): ?int
    {
        $direct = @IPS_GetObjectIDByIdent($ident, $parentId);
        if (is_int($direct) && $direct > 0 && IPS_ObjectExists($direct)) return $direct;

        $queue = IPS_GetChildrenIDs($parentId);
        while (!empty($queue)) {
            $id = array_shift($queue);
            if (!IPS_ObjectExists($id)) continue;

            $o = IPS_GetObject($id);
            $type = $o['ObjectType'] ?? -1;

            if ($type === OBJECTTYPE_VARIABLE) {
                if ((string)($o['ObjectIdent'] ?? '') === $ident) return $id;
            } elseif ($type === OBJECTTYPE_CATEGORY) {
                foreach (IPS_GetChildrenIDs($id) as $child) $queue[] = $child;
            }
        }

        return null;
    }

    // ---------------------------
    // PatrolStation parsing -> address
    // ---------------------------
    private function BuildTankstelleArrayFromInstance(int $instanceId): ?array
    {
        $patrolVarId = $this->FindVariableRecursiveByIdent($instanceId, self::IDENT_PATROLSTATION);
        if ($patrolVarId === null) return null;

        $html = (string)GetValue($patrolVarId);
        return $this->ParsePatrolStationHtml($html);
    }

    private function ParsePatrolStationHtml(string $html): ?array
    {
        $cells = $this->ExtractHtmlTableCells($html);
        if (count($cells) < 2) return null;

        $brand = trim((string)($cells[0] ?? ''));
        $displayName = trim((string)($cells[1] ?? ''));

        $plzIndex = null;
        foreach ($cells as $i => $line) {
            if (preg_match('/\b\d{5}\b/u', $line)) { $plzIndex = $i; break; }
        }
        if ($plzIndex === null) return null;

        $cityLine = (string)$cells[$plzIndex];

        $streetLine = null;
        for ($j = $plzIndex - 1; $j >= 0; $j--) {
            $cand = trim((string)$cells[$j]);
            if ($cand !== '') { $streetLine = $cand; break; }
        }
        if ($streetLine === null) return null;

        $plz = null; $city = null;
        if (preg_match('/\b(\d{5})\b\s*(.+)$/u', $cityLine, $m)) {
            $plz = trim($m[1]);
            $city = trim($m[2]);
        }

        $ort = trim(($plz ? $plz . ' ' : '') . ($city ?? ''));

        return [
            'station_brand' => $brand,
            'station_display_name' => ($displayName !== '' ? $displayName : ($brand !== '' ? $brand : '')),
            'fuel-station-location-street' => $streetLine,
            'ort' => $ort
        ];
    }

    private function ExtractHtmlTableCells(string $html): array
    {
        $html = trim($html);
        if ($html === '') return [];

        if (class_exists('DOMDocument')) {
            $prev = libxml_use_internal_errors(true);
            try {
                $dom = new DOMDocument();
                $dom->loadHTML('<?xml encoding="UTF-8">' . $html);

                $cells = [];
                foreach ($dom->getElementsByTagName('td') as $td) {
                    $txt = trim(html_entity_decode($td->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $txt = preg_replace('/\s+/u', ' ', $txt);
                    if ($txt !== '') $cells[] = $txt;
                }
                if (count($cells) >= 2) return $cells;
            } catch (Throwable $e) {
                // fallback
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($prev);
            }
        }

        if (preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $html, $m)) {
            $cells = [];
            foreach ($m[1] as $raw) {
                $txt = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $txt = preg_replace('/\s+/u', ' ', $txt);
                if ($txt !== '') $cells[] = $txt;
            }
            return $cells;
        }

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R+/u', $plain) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines)));
        return $lines;
    }

    // ---------------------------
    // Location, distance, route
    // ---------------------------
    private function GetOriginLatLng(): array
    {
        if ($this->ReadPropertyBoolean('UseLocationControl')) {
            $lc = (int)$this->ReadPropertyInteger('LocationControlID');
            if ($lc <= 0 || !IPS_ObjectExists($lc)) {
                throw new Exception('LocationControlID ist ungültig.');
            }

            // EXACTLY like your working script
            $raw = (string)IPS_GetProperty($lc, 'Location');
            $this->Dbg('LocationControl.Raw', $raw, 0, true);

            $Location = json_decode($raw, true);
            $this->Dbg('LocationControl.Decoded', json_encode($Location), 0, false);

            if (!is_array($Location) || !array_key_exists('latitude', $Location) || !array_key_exists('longitude', $Location)) {
                throw new Exception('LocationControl: JSON ohne latitude/longitude.');
            }

            $lat = $this->ToFloat($Location['latitude']);
            $lng = $this->ToFloat($Location['longitude']);

            return ['lat' => $lat, 'lng' => $lng];
        }

        $s = trim((string)$this->ReadPropertyString('OwnLocation'));
        $this->Dbg('OwnLocation.Raw', $s, 0, true);
        $parsed = $this->ParseLatLngString($s);
        if ($parsed === null) {
            throw new Exception('Eigener Standort hat ungültiges Format. Erwartet "lat, lng".');
        }
        return $parsed;
    }

    private function ToFloat($v): float
    {
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        return (float)$v;
    }

    private function ParseLatLngString(string $s): ?array
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        // 1) JSON-Format: {"latitude":53.6053,"longitude":10.0746}
        if ($s[0] === '{') {
            $j = json_decode($s, true);
            if (is_array($j)) {
                // bevorzugt: latitude/longitude
                if (isset($j['latitude'], $j['longitude'])) {
                    return [
                        'lat' => (float) str_replace(',', '.', (string)$j['latitude']),
                        'lng' => (float) str_replace(',', '.', (string)$j['longitude'])
                    ];
                }
                // fallback: lat/lng
                if (isset($j['lat'], $j['lng'])) {
                    return [
                        'lat' => (float) str_replace(',', '.', (string)$j['lat']),
                        'lng' => (float) str_replace(',', '.', (string)$j['lng'])
                    ];
                }
            }
            return null;
        }

        // 2) "lat, lng" Format (SelectLocation)
        $parts = array_map('trim', explode(',', $s));
        if (count($parts) < 2) {
            return null;
        }

        $a = str_replace(',', '.', $parts[0]);
        $b = str_replace(',', '.', $parts[1]);

        if (!is_numeric($a) || !is_numeric($b)) {
            return null;
        }

        return ['lat' => (float)$a, 'lng' => (float)$b];
    }

    private function ComputeDistanceKm(array $tankstelle): float
    {
        $gm = (int)$this->ReadPropertyInteger('GoogleMapsInstanceID');
        $this->Dbg('tankstelle.location', json_encode($tankstelle), 0, false);
        $origin = $this->GetOriginLatLng();
        $street = (string)($tankstelle['fuel-station-location-street'] ?? '');
        $ort    = (string)($tankstelle['ort'] ?? '');

        $destination = trim($street . ' , ' . $ort);
        if ($destination === ',' || trim($destination) === '') {
            throw new Exception('Zieladresse leer.');
        }

        $map = [
            'origin'      => ['lat' => $origin['lat'], 'lng' => $origin['lng']],
            'destination' => $destination,
            'avoid'       => ['ferries', 'tolls'],
            'mode'        => 'driving'
        ];

        $this->Dbg('GoogleMaps.Map', json_encode($map), 0, false);

        $dmJson = GoogleMaps_GetDistanceMatrix($gm, json_encode($map));
        $decoded = json_decode((string)$dmJson, true);
        $element = $decoded['rows'][0]['elements'][0] ?? null;

        if (!is_array($element)) throw new Exception('DistanceMatrix unerwartet.');
        if (($element['status'] ?? 'UNKNOWN') !== 'OK') throw new Exception('DistanceMatrix Status=' . ($element['status'] ?? 'UNKNOWN'));
        $meters = $element['distance']['value'] ?? null;
        if (!is_numeric($meters)) throw new Exception('DistanceMatrix ohne distance.value.');

        return ((float)$meters) / 1000.0;
    }

    private function ComputeRouteHtml(array $tankstelle): string
    {
        $gm = (int)$this->ReadPropertyInteger('GoogleMapsInstanceID');

        $origin = $this->GetOriginLatLng();
        $street = (string)($tankstelle['fuel-station-location-street'] ?? '');
        $ort    = (string)($tankstelle['ort'] ?? '');

        $destination = trim($street . ' , ' . $ort);
        if ($destination === ',' || trim($destination) === '') {
            throw new Exception('Zieladresse leer.');
        }

        $map = [
            'origin'      => ['lat' => $origin['lat'], 'lng' => $origin['lng']],
            'destination' => $destination,
            'avoid'       => ['ferries', 'tolls'],
            'mode'        => 'driving'
        ];

        $this->Dbg('GoogleMaps.RouteMap', json_encode($map), 0, false);

        $url = GoogleMaps_GenerateEmbededMap($gm, json_encode($map));
        return '<iframe width="500" height="500" frameborder="0" style="border:0" scrolling="no" marginheight="0" marginwidth="0" src="' . $url . '"></iframe>';
    }

    // ---------------------------
    // Price parsing
    // ---------------------------
    private function ParsePriceToFloat($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            $v = (float)$raw;
            return ($v > 0) ? $v : null;
        }
        $s = trim((string)$raw);
        if ($s === '') return null;

        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9.]+/', '', $s);
        if ($s === '' || !is_numeric($s)) return null;

        $v = (float)$s;
        return ($v > 0) ? $v : null;
    }
}
