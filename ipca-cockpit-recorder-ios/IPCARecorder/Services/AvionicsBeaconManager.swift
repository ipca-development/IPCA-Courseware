import Combine
import CoreBluetooth
import Foundation

@MainActor
final class AvionicsBeaconManager: NSObject, ObservableObject {
    static let serviceUUID = CBUUID(string: "7A2D5E01-9F83-4C0A-BA18-4B39A2D2E001")
    static let advertisedLocalName = "IPCA-AVIONICS"

    static let onConfirmationWindow: TimeInterval = 5
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

    // Future production integration hook. This diagnostic intentionally does
    // not connect this callback to recorder start/stop behavior yet.
    var onAvionicsStateChanged: ((AvionicsPowerState) -> Void)?

    private var centralManager: CBCentralManager?
    private var timer: Timer?
    private var recentTargetAdvertisementDates: [Date] = []
    private var previousAdvertisementAt: Date?
    private var hasEverSeenBeacon = false

    override init() {
        super.init()
        updateBluetoothAuthorization()
        centralManager = CBCentralManager(delegate: self, queue: nil)
    }

    deinit {
        timer?.invalidate()
        centralManager?.stopScan()
    }

    func startScan(scanAll: Bool = false) {
        scanAllMode = scanAll
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
        let elapsed = previousAdvertisementAt.map { now.timeIntervalSince($0) }
        previousAdvertisementAt = now

        let entry = AvionicsBeaconLogEntry(
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
        )
        logEntries.append(entry)

        guard matchedService else {
            return
        }

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
        recentTargetAdvertisementDates.append(now)
        recentTargetAdvertisementDates = recentTargetAdvertisementDates.filter {
            now.timeIntervalSince($0) <= Self.onConfirmationWindow
        }

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
            if recentTargetAdvertisementDates.count >= 2 && secondsSinceLastAdvertisement <= Self.temporarilyMissingAfter {
                transition(to: .avionicsOn)
            } else if secondsSinceLastAdvertisement > Self.offConfirmationAfter {
                transition(to: .avionicsOff)
            } else if secondsSinceLastAdvertisement > Self.temporarilyMissingAfter {
                transition(to: .temporarilyMissing)
            } else {
                transition(to: .candidateOn)
            }
            return
        }

        if isScanning {
            transition(to: .scanning)
        } else {
            transition(to: .unknown)
        }
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
            logEvent("future integration callback available: onAvionicsStateChanged(.\(newPowerState.rawValue))")
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
