import Combine
import CoreBluetooth
import Foundation

@MainActor
final class AvionicsBeaconManager: NSObject, ObservableObject {
    static let serviceUUID = CBUUID(string: "7A2D5E01-9F83-4C0A-BA18-4B39A2D2E001")
    static let advertisedLocalName = "IPCA-AVIONICS"
    static let restorationIdentifier = "ipca.cvrUnit.avionicsBeacon.central"

    static let temporarilyMissingAfter: TimeInterval = 5
    static let offConfirmationAfter: TimeInterval = 15

    @Published private(set) var bluetoothAuthorization = "Unknown"
    @Published private(set) var centralState = "Unknown"
    @Published private(set) var isScanning = false
    @Published private(set) var scanAllMode = false
    @Published private(set) var beaconDetected = false
    @Published private(set) var currentState: AvionicsBeaconState = .unknown
    @Published private(set) var avionicsPowerState: AvionicsPowerState?
    @Published private(set) var firstSeenAt: Date?
    @Published private(set) var lastSeenAt: Date?
    @Published private(set) var secondsSinceLastAdvertisement: TimeInterval?
    @Published private(set) var currentRSSI: Int?
    @Published private(set) var strongestRSSI: Int?
    @Published private(set) var weakestRSSI: Int?
    @Published private(set) var advertisementCount = 0
    @Published private(set) var advertisedLocalName = ""
    @Published private(set) var advertisedServiceUUIDs: [String] = []
    @Published private(set) var manufacturerDataHex = ""
    @Published private(set) var logEntries: [AvionicsBeaconLogEntry] = []
    @Published private(set) var activeMarker = "Not marked"
    @Published private(set) var lastError = ""

    var onAvionicsStateChanged: ((AvionicsPowerState) -> Void)?
    var onMatchingBeaconAdvertisement: (() -> Void)?

    private var centralManager: CBCentralManager?
    private var timer: Timer?
    private var previousTargetAdvertisementAt: Date?
    private var hasEverSeenBeacon = false
    private var shouldScanWhenReady = false
    private var requestedScanAllMode = false

    override init() {
        super.init()
        updateBluetoothAuthorization()
        centralManager = CBCentralManager(
            delegate: self,
            queue: nil,
            options: [CBCentralManagerOptionRestoreIdentifierKey: Self.restorationIdentifier]
        )
    }

    deinit {
        timer?.invalidate()
        centralManager?.stopScan()
    }

    func startScan(scanAll: Bool = false) {
        scanAllMode = scanAll
        requestedScanAllMode = scanAll
        shouldScanWhenReady = true
        updateBluetoothAuthorization()
        guard let centralManager, centralManager.state == .poweredOn else {
            lastError = "Bluetooth is not powered on."
            currentState = .bluetoothUnavailable
            logEvent("scan start blocked: Bluetooth unavailable")
            return
        }

        isScanning = true
        lastError = ""
        let services = scanAll ? nil : [Self.serviceUUID]
        centralManager.scanForPeripherals(
            withServices: services,
            options: [CBCentralManagerScanOptionAllowDuplicatesKey: true]
        )
        startTimer()
        updateState()
        logEvent(scanAll ? "scan started: all BLE advertisements" : "scan started: service \(Self.serviceUUID.uuidString)")

        #if DEBUG
        print("[AvionicsBeaconTest] scan started scanAll=\(scanAll)")
        #endif
    }

    func stopScan() {
        shouldScanWhenReady = false
        centralManager?.stopScan()
        isScanning = false
        timer?.invalidate()
        timer = nil
        updateState()
        logEvent("scan stopped")

        #if DEBUG
        print("[AvionicsBeaconTest] scan stopped")
        #endif
    }

    func clearLog() {
        logEntries = []
        lastError = ""
        logEvent("log cleared")
    }

    func mark(_ marker: String) {
        activeMarker = marker
        logEvent("manual marker changed: \(marker)")
    }

    private func startTimer() {
        timer?.invalidate()
        timer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { [weak self] _ in
            Task { @MainActor in
                self?.updateState()
            }
        }
    }

    private func handleDiscovery(peripheral: CBPeripheral, advertisementData: [String: Any], rssi: NSNumber) {
        let now = Date()
        let localName = advertisementData[CBAdvertisementDataLocalNameKey] as? String
        let serviceUUIDs = serviceUUIDStrings(from: advertisementData)
        let manufacturerHex = manufacturerHex(from: advertisementData)
        let matchedService = serviceUUIDs.contains(Self.serviceUUID.uuidString.uppercased())
        let elapsed = matchedService ? previousTargetAdvertisementAt.map { now.timeIntervalSince($0) } : nil
        if matchedService {
            previousTargetAdvertisementAt = now
        }

        logEntries.append(AvionicsBeaconLogEntry(
            kind: .discovery,
            timestamp: now,
            marker: activeMarker,
            peripheralIdentifier: peripheral.identifier.uuidString,
            peripheralName: peripheral.name,
            advertisedLocalName: localName,
            advertisedServiceUUIDs: serviceUUIDs,
            manufacturerDataHex: manufacturerHex,
            rssi: rssi.intValue,
            secondsSincePreviousAdvertisement: elapsed,
            matchedCustomService: matchedService
        ))

        guard matchedService else { return }

        hasEverSeenBeacon = true
        beaconDetected = true
        firstSeenAt = firstSeenAt ?? now
        lastSeenAt = now
        secondsSinceLastAdvertisement = 0
        currentRSSI = rssi.intValue
        strongestRSSI = max(strongestRSSI ?? rssi.intValue, rssi.intValue)
        weakestRSSI = min(weakestRSSI ?? rssi.intValue, rssi.intValue)
        advertisementCount += 1
        advertisedLocalName = localName ?? peripheral.name ?? ""
        advertisedServiceUUIDs = serviceUUIDs
        manufacturerDataHex = manufacturerHex ?? ""
        onMatchingBeaconAdvertisement?()
        updateState()

        #if DEBUG
        print("[AvionicsBeaconTest][DISCOVERY] target=true rssi=\(rssi.intValue) localName=\(advertisedLocalName)")
        #endif
    }

    private func updateState() {
        updateBluetoothAuthorization()
        guard centralManager?.state == .poweredOn else {
            transition(to: .bluetoothUnavailable)
            return
        }

        if let lastSeenAt {
            secondsSinceLastAdvertisement = Date().timeIntervalSince(lastSeenAt)
        }

        if let secondsSinceLastAdvertisement, hasEverSeenBeacon {
            if secondsSinceLastAdvertisement > Self.offConfirmationAfter {
                transition(to: .avionicsOff)
            } else if secondsSinceLastAdvertisement > Self.temporarilyMissingAfter {
                transition(to: .temporarilyMissing)
            } else {
                transition(to: .avionicsOn)
            }
            return
        }

        transition(to: isScanning ? .scanning : .unknown)
    }

    private func transition(to newState: AvionicsBeaconState) {
        guard currentState != newState else { return }
        let oldState = currentState
        currentState = newState
        logEvent("state changed: \(oldState.label) -> \(newState.label)")

        let newPowerState: AvionicsPowerState?
        switch newState {
        case .avionicsOn:
            newPowerState = .on
        case .avionicsOff:
            newPowerState = .off
        default:
            newPowerState = avionicsPowerState
        }

        if let newPowerState, newPowerState != avionicsPowerState {
            avionicsPowerState = newPowerState
            onAvionicsStateChanged?(newPowerState)
            logEvent("avionics power state changed: \(newPowerState.rawValue)")
        }
    }

    private func serviceUUIDStrings(from advertisementData: [String: Any]) -> [String] {
        let serviceKeys = [
            CBAdvertisementDataServiceUUIDsKey,
            CBAdvertisementDataOverflowServiceUUIDsKey,
            CBAdvertisementDataSolicitedServiceUUIDsKey
        ]
        let uuids = serviceKeys.flatMap { key in
            advertisementData[key] as? [CBUUID] ?? []
        }
        return Array(Set(uuids.map { $0.uuidString.uppercased() })).sorted()
    }

    private func manufacturerHex(from advertisementData: [String: Any]) -> String? {
        guard let data = advertisementData[CBAdvertisementDataManufacturerDataKey] as? Data else {
            return nil
        }
        return data.map { String(format: "%02X", $0) }.joined()
    }

    private func logEvent(_ event: String) {
        logEntries.append(AvionicsBeaconLogEntry(kind: .event, marker: activeMarker, event: event))
    }

    private func updateBluetoothAuthorization() {
        switch CBCentralManager.authorization {
        case .allowedAlways:
            bluetoothAuthorization = "Allowed Always"
        case .denied:
            bluetoothAuthorization = "Denied"
        case .restricted:
            bluetoothAuthorization = "Restricted"
        case .notDetermined:
            bluetoothAuthorization = "Not Determined"
        @unknown default:
            bluetoothAuthorization = "Unknown"
        }
    }

    private func updateCentralState(_ state: CBManagerState) {
        switch state {
        case .poweredOn:
            centralState = "Powered On"
        case .poweredOff:
            centralState = "Powered Off"
        case .resetting:
            centralState = "Resetting"
        case .unauthorized:
            centralState = "Unauthorized"
        case .unsupported:
            centralState = "Unsupported"
        case .unknown:
            centralState = "Unknown"
        @unknown default:
            centralState = "Unknown"
        }
    }
}

extension AvionicsBeaconManager: CBCentralManagerDelegate {
    nonisolated func centralManagerDidUpdateState(_ central: CBCentralManager) {
        Task { @MainActor in
            updateCentralState(central.state)
            updateState()
            logEvent("central manager state changed: \(centralState)")
            if central.state == .poweredOn, shouldScanWhenReady, !isScanning {
                startScan(scanAll: requestedScanAllMode)
            }
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, willRestoreState dict: [String: Any]) {
        Task { @MainActor in
            shouldScanWhenReady = true
            requestedScanAllMode = false
            scanAllMode = false
            updateCentralState(central.state)
            logEvent("central manager restored by iOS")
            if let restoredServices = dict[CBCentralManagerRestoredStateScanServicesKey] as? [CBUUID] {
                let serviceList = restoredServices.map(\.uuidString).joined(separator: ", ")
                logEvent("restored scan services: \(serviceList)")
            }
            if central.state == .poweredOn {
                startScan(scanAll: false)
            } else {
                updateState()
            }
        }
    }

    nonisolated func centralManager(
        _ central: CBCentralManager,
        didDiscover peripheral: CBPeripheral,
        advertisementData: [String: Any],
        rssi RSSI: NSNumber
    ) {
        Task { @MainActor in
            handleDiscovery(peripheral: peripheral, advertisementData: advertisementData, rssi: RSSI)
        }
    }
}
