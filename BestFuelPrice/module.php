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

        // --- Output variables: Icons nur initial setzen ---

        // Zeit
        $isNew = $this->IsNewIdent(self::OUT_TIME);
        $this->RegisterVariableInteger(self::OUT_TIME, 'Zeit', '~UnixTimestamp', 10);
        if ($isNew) {
            IPS_SetIcon($this->GetIDForIdent(self::OUT_TIME), 'Clock');
        }

        // Preis
        $isNew = $this->IsNewIdent(self::OUT_PRICE);
        $this->RegisterVariableFloat(self::OUT_PRICE, 'Preis', self::PROFILE_PRICE, 20);
        if ($isNew) {
            IPS_SetIcon($this->GetIDForIdent(self::OUT_PRICE), 'Fuel');
        }

        // Tankstelle (Text)
        $isNew = $this->IsNewIdent(self::OUT_NAME);
        $this->RegisterVariableString(self::OUT_NAME, 'Tankstelle', '~TextBox', 30);
        if ($isNew) {
            IPS_SetIcon($this->GetIDForIdent(self::OUT_NAME), 'Information');
        }

        // Entfernung
        $isNew = $this->IsNewIdent(self::OUT_DIST);
        $this->RegisterVariableFloat(self::OUT_DIST, 'Entfernung', self::PROFILE_DIST, 40);
        if ($isNew) {
            IPS_SetIcon($this->GetIDForIdent(self::OUT_DIST), 'Distance');
        }

        // Route (HTML)
        $isNew = $this->IsNewIdent(self::OUT_ROUTE);
        $this->RegisterVariableString(self::OUT_ROUTE, 'Route', '~HTMLBox', 50);
        if ($isNew) {
            IPS_SetIcon($this->GetIDForIdent(self::OUT_ROUTE), 'Map');
        }

        // --- Legacy cleanup (GetIDForIdent + UnregisterVariable) ---
        try {
            // Throws if not existing -> okay
            $this->GetIDForIdent('BestStationInstanceID');
            $this->UnregisterVariable('BestStationInstanceID');
            $this->Dbg('Legacy', 'Removed legacy variable BestStationInstanceID', 0, false);
        } catch (Throwable $e) {
            // not present -> ignore
        }

        // --- Timer interval from minutes; enforce min 10 min if enabled
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
                ['type' => 'Button', 'caption' => 'Jetzt berechnen', 'onClick' => 'BFP_Update(' . $this->InstanceID . ');'],
                ['type' => 'Button', 'caption' => 'Archivierung (Preis) aktivieren', 'onClick' => 'BFP_EnablePriceLogging(' . $this->InstanceID . ');']

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

        $distIntervalMin = max(0, (int)$this->ReadPropertyInteger('DistanceUpdateIntervalMinutes'));
        $distIntervalSec = $distIntervalMin * 60;

        // --- Distanz: Origin sofort testen (damit Fehler klar sind) ---
        if ($enableDistance) {
            try {
                $origin = $this->GetOriginLatLng();
                $this->Dbg('Origin.LatLng', 'lat=' . $origin['lat'] . ' lng=' . $origin['lng'], 0, true);
            } catch (Throwable $e) {
                $this->Dbg('Origin.Error', $e->getMessage(), 0, true);

                $this->SetValue(self::OUT_TIME, 0);
                $this->SetValue(self::OUT_PRICE, 0.0);
                $this->SetValue(self::OUT_NAME, 'Standort ungültig: ' . $e->getMessage());
                $this->SetValue(self::OUT_DIST, 0.0);
                $this->SetValue(self::OUT_ROUTE, '<div style="padding:8px">Standort kann nicht gelesen werden.</div>');
                return;
            }
        }

        // --- Distanz-Cache (im Modul) ---
        // Struktur: { "<stationInstanceId>": { "km": float, "ts": int } }
        $cacheRaw = (string)$this->GetBuffer('DistanceCache');
        $distanceCache = json_decode($cacheRaw, true);
        if (!is_array($distanceCache)) {
            $distanceCache = [];
        }

        // Helper als Closure: Distanz read-only aus Instanz oder Cache oder berechnen
        $getDistanceKm = function (int $stationInstanceId) use (&$distanceCache, $distIntervalSec): ?float {

            // 1) Wenn Tankstellen-Instanz bereits eine DistanceKm Variable hat -> read-only verwenden
            $distVarId = @IPS_GetObjectIDByIdent(self::IDENT_DISTANCE, $stationInstanceId);
            if (is_int($distVarId) && $distVarId > 0 && IPS_ObjectExists($distVarId)) {
                $km = (float)GetValue($distVarId);
                $updated = (int)(IPS_GetVariable($distVarId)['VariableUpdated'] ?? 0);

                if ($km > 0.001) {
                    // Cache spiegeln (optional)
                    $distanceCache[(string)$stationInstanceId] = [
                        'km' => $km,
                        'ts' => $updated > 0 ? $updated : time()
                    ];
                    return $km;
                }
            }

            // 2) Cache prüfen
            $key = (string)$stationInstanceId;
            if (isset($distanceCache[$key]['km'], $distanceCache[$key]['ts'])) {
                $km = (float)$distanceCache[$key]['km'];
                $ts = (int)$distanceCache[$key]['ts'];

                $due = ($distIntervalSec <= 0) ? true : ($ts == 0 || (time() - $ts) >= $distIntervalSec);
                if ($km > 0.001 && !$due) {
                    // ts hochziehen, damit „zuletzt benutzt“ erkennbar ist
                    $distanceCache[$key]['ts'] = time();
                    return $km;
                }
            }

            // 3) Neu berechnen (nur intern cachen)
            try {
                $addr = $this->BuildTankstelleArrayFromInstance($stationInstanceId);
                if (!is_array($addr)) {
                    return null;
                }

                $km = $this->ComputeDistanceKm($addr);
                if (!is_finite($km) || $km <= 0.001) {
                    return null;
                }

                $distanceCache[$key] = ['km' => (float)$km, 'ts' => time()];
                return (float)$km;
            } catch (Throwable $e) {
                $this->Dbg('Distance.Error', 'Station ' . $stationInstanceId . ': ' . $e->getMessage(), 0, true);
                return null;
            }
        };

        // --- Instanzen sammeln ---
        $instances = $this->GetChildInstancesRecursive($categoryId);
        $this->Dbg('Scan', 'Instances in tree: ' . count($instances), 0, false);

        $best = null;
        $normalizedExpected = $this->NormalizeGuid(self::TANKERKOENIG_MODULE_ID);

        // Mini-Statistik (Debug)
        $stats = [
            'total' => count($instances),
            'tanker' => 0,
            'open' => 0,
            'hasFuelVar' => 0,
            'validPrice' => 0,
            'distanceOk' => 0,
            'withinMaxKm' => 0,
            'candidates' => 0
        ];

        foreach ($instances as $iid) {
            $inst = IPS_GetInstance($iid);

            // Nur Tankerkönig-Instanzen
            $mid = '';
            if (isset($inst['ModuleInfo']) && is_array($inst['ModuleInfo']) && isset($inst['ModuleInfo']['ModuleID'])) {
                $mid = (string)$inst['ModuleInfo']['ModuleID'];
            }
            if ($this->NormalizeGuid($mid) !== $normalizedExpected) {
                continue;
            }
            $stats['tanker']++;

            // Nur geöffnet
            if ($onlyOpen) {
                $stateVar = $this->FindVariableRecursiveByIdent($iid, self::IDENT_STATE);
                if ($stateVar === null) {
                    continue;
                }
                if ((int)GetValue($stateVar) !== 1) {
                    continue;
                }
            }
            $stats['open']++;

            // Preisvariable Diesel/E5/E10
            $fuelVar = $this->FindVariableRecursiveByIdent($iid, $fuelIdent);
            if ($fuelVar === null) {
                continue;
            }
            $stats['hasFuelVar']++;

            $price = $this->ParsePriceToFloat(GetValue($fuelVar));
            if ($price === null || $price <= 0) {
                continue;
            }
            $stats['validPrice']++;

            $priceTime = (int)(IPS_GetVariable($fuelVar)['VariableUpdated'] ?? time());

            // Distanzfilter optional
            $distanceKm = null;
            if ($enableDistance) {
                $distanceKm = $getDistanceKm($iid);
                if ($distanceKm === null || !is_finite($distanceKm) || $distanceKm <= 0.001) {
                    continue;
                }
                $stats['distanceOk']++;

                if ($maxKm > 0) {
                    if ($distanceKm > $maxKm) {
                        continue;
                    }
                    $stats['withinMaxKm']++;
                }
            }

            $stats['candidates']++;

            // Best-Logik
            if ($best === null) {
                $best = ['instanceId' => $iid, 'price' => $price, 'time' => $priceTime, 'distanceKm' => $distanceKm];
                continue;
            }

            if ($price < (float)$best['price']) {
                $best = ['instanceId' => $iid, 'price' => $price, 'time' => $priceTime, 'distanceKm' => $distanceKm];
                continue;
            }

            // Tie-break: bei Preisgleichheit die nähere (nur sinnvoll wenn Distanz aktiv)
            if ($enableDistance && abs($price - (float)$best['price']) < 0.0005) {
                if ($distanceKm !== null && $best['distanceKm'] !== null && (float)$distanceKm < (float)$best['distanceKm']) {
                    $best = ['instanceId' => $iid, 'price' => $price, 'time' => $priceTime, 'distanceKm' => $distanceKm];
                }
            }
        }

        // Cache begrenzen (z.B. 500 Einträge, zuletzt benutzt zuerst)
        if (count($distanceCache) > 500) {
            uasort($distanceCache, function ($a, $b) {
                $ta = (int)($a['ts'] ?? 0);
                $tb = (int)($b['ts'] ?? 0);
                return $tb <=> $ta;
            });
            $distanceCache = array_slice($distanceCache, 0, 500, true);
        }

        // Cache zurückschreiben
        $this->SetBuffer('DistanceCache', json_encode($distanceCache));

        $this->Dbg('Stats', json_encode($stats), 0, true);

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
        $addr = null;
        try {
            $addr = $this->BuildTankstelleArrayFromInstance($iid);
        } catch (Throwable $e) {
            $this->Dbg('Address.Error', $e->getMessage(), 0, true);
        }

        $stationName = IPS_GetName($iid);
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
            'instanceId'  => $iid,
            'name'        => $stationName,
            'price'       => (float)$best['price'],
            'time'        => (int)$best['time'],
            'distanceKm'  => $best['distanceKm']
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
    private function GetDistanceKmReadOnlyOrCached(int $stationInstanceId, int $cacheIntervalSeconds): ?float
    {
        // 1) Read-only: vorhandene DistanceKm in Tankerkönig-Instanz nutzen (wenn User sie hat)
        $distVarId = @IPS_GetObjectIDByIdent(self::IDENT_DISTANCE, $stationInstanceId);
        if (is_int($distVarId) && $distVarId > 0 && IPS_ObjectExists($distVarId)) {
            $km = (float)GetValue($distVarId);
            if ($km > 0.001) {
                return $km;
            }
        }

        // 2) Interner Cache (Buffer)
        $cacheRaw = (string)$this->GetBuffer('DistanceCache');
        $cache = json_decode($cacheRaw, true);
        if (!is_array($cache)) {
            $cache = [];
        }

        $key = (string)$stationInstanceId;
        if (isset($cache[$key]['km'], $cache[$key]['ts'])) {
            $km = (float)$cache[$key]['km'];
            $ts = (int)$cache[$key]['ts'];

            $due = ($cacheIntervalSeconds <= 0) ? true : ($ts == 0 || (time() - $ts) >= $cacheIntervalSeconds);
            if ($km > 0.001 && !$due) {
                return $km;
            }
        }

        // 3) Neu berechnen (nur intern speichern!)
        $addr = $this->BuildTankstelleArrayFromInstance($stationInstanceId);
        if (!is_array($addr)) {
            return null;
        }

        $km = $this->ComputeDistanceKm($addr);
        if (!is_finite($km) || $km <= 0.001) {
            return null;
        }

        $cache[$key] = ['km' => (float)$km, 'ts' => time()];
        $this->SetBuffer('DistanceCache', json_encode($cache));

        return (float)$km;
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

    private function IsNewIdent(string $ident): bool
    {
        try {
            $id = $this->GetIDForIdent($ident);
            return !IPS_ObjectExists($id);
        } catch (Throwable $e) {
            return true; // GetIDForIdent wirft, wenn nicht vorhanden
        }
    }

    public function EnablePriceLogging(): void
    {
        // BestPrice Variable in dieser Instanz
        $varId = $this->GetIDForIdent(self::OUT_PRICE);

        $this->EnableArchiveLogging($varId);

        $this->Dbg('Archive', 'Logging enabled for ' . self::OUT_PRICE . ' (VarID=' . $varId . ')', 0, true);
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
