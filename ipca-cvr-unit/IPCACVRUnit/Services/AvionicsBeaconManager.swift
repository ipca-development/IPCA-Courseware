import Combine
import CoreBluetooth
import Foundation

@MainActor
final class AvionicsBeaconManager: NSObject, ObservableObject {
    static let serviceUUID = CBUUID(string: "7A2D5E01-9F83-4C0A-BA18-4B39A2D2E001")
    static let statusCharacteristicUUID = CBUUID(string: "7A2D5E02-9F83-4C0A-BA18-4B39A2D2E001")
    static let recorderContactCharacteristicUUID = CBUUID(string: "7A2D5E03-9F83-4C0A-BA18-4B39A2D2E001")
    static let advertisedLocalName = "IPCA-AVIONICS"
    static let restorationIdentifier = "ipca.cvrUnit.avionicsBeacon.central"

    static let temporarilyMissingAfter: TimeInterval = 5
    static let offConfirmationAfter: TimeInterval = 60
    static let recorderContactRefreshInterval: TimeInterval = 8

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
    @Published private(set) var gattConnectionState = "Disconnected"
    @Published private(set) var latestStatus: AvionicsBeaconStatusPacket?
    @Published private(set) var lastGATTActivityAt: Date?

    var onAvionicsStateChanged: ((AvionicsPowerState) -> Void)?
    var onMatchingBeaconAdvertisement: (() -> Void)?
    var onBeaconRelationshipAvailable: (() -> Void)?
    var onBeaconCommunicationLost: (() -> Void)?

    private var centralManager: CBCentralManager?
    private var timer: Timer?
    private var recorderContactTimer: Timer?
    private var previousTargetAdvertisementAt: Date?
    private var hasEverSeenBeacon = false
    private var shouldScanWhenReady = false
    private var requestedScanAllMode = false
    private var targetPeripheral: CBPeripheral?
    private var statusCharacteristic: CBCharacteristic?
    private var recorderContactCharacteristic: CBCharacteristic?
    private var recorderToken: Data?
    private var recorderWriteSequence: UInt32 = 0
    private var recorderVersion = (major: UInt8(1), minor: UInt8(0), patch: UInt8(0))
    private var lastKnownBootCounter: UInt32?
    private var lastKnownBootUUID: String?

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
        recorderContactTimer?.invalidate()
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
        gattConnectionState = "Scanning"
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
        recorderContactTimer?.invalidate()
        recorderContactTimer = nil
        if let targetPeripheral {
            centralManager?.cancelPeripheralConnection(targetPeripheral)
        }
        centralManager?.stopScan()
        isScanning = false
        targetPeripheral = nil
        statusCharacteristic = nil
        recorderContactCharacteristic = nil
        gattConnectionState = "Disconnected"
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

    func setRecorderToken(_ token: Data?) {
        guard token == nil || token?.count == 16 else {
            lastError = "Recorder token must be exactly 16 bytes."
            logEvent("recorder token rejected: invalid length")
            return
        }
        recorderToken = token
        recorderWriteSequence = 0
        if token != nil {
            startRecorderContactTimer()
            writeRecorderContact(reason: "token assigned")
        } else {
            recorderContactTimer?.invalidate()
            recorderContactTimer = nil
        }
    }

    func currentRecorderTokenHex() -> String {
        recorderToken?.ipcaHexString ?? ""
    }

    func beaconStatusSnapshot() -> AvionicsBeaconStatusPacket? {
        latestStatus
    }

    func saveDiagnostics(recordingID: String, recordingSessionID: String, recordingEndReason: String) -> String? {
        do {
            let directory = try RecordingStore.recordingsDirectory()
            let url = directory.appendingPathComponent("\(recordingID).beacon.json")
            let payload = diagnosticsPayload(
                recordingID: recordingID,
                recordingSessionID: recordingSessionID,
                recordingEndReason: recordingEndReason
            )
            let data = try JSONSerialization.data(withJSONObject: payload, options: [.prettyPrinted, .sortedKeys])
            try data.write(to: url, options: [.atomic])
            logEvent("beacon diagnostics saved: \(url.lastPathComponent)")
            return url.path
        } catch {
            lastError = "Could not save beacon diagnostics: \(error.localizedDescription)"
            logEvent(lastError)
            return nil
        }
    }

    private func startTimer() {
        timer?.invalidate()
        timer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { [weak self] _ in
            Task { @MainActor in
                self?.updateState()
            }
        }
    }

    private func startRecorderContactTimer() {
        recorderContactTimer?.invalidate()
        recorderContactTimer = Timer.scheduledTimer(withTimeInterval: Self.recorderContactRefreshInterval, repeats: true) { [weak self] _ in
            Task { @MainActor in
                self?.writeRecorderContact(reason: "periodic refresh")
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
        connectIfNeeded(peripheral)
        updateState()

        #if DEBUG
        print("[AvionicsBeaconTest][DISCOVERY] target=true rssi=\(rssi.intValue) localName=\(advertisedLocalName)")
        #endif
    }

    private func connectIfNeeded(_ peripheral: CBPeripheral) {
        guard let centralManager else { return }
        if let targetPeripheral, targetPeripheral.identifier == peripheral.identifier {
            if targetPeripheral.state == .connected || targetPeripheral.state == .connecting {
                return
            }
        }
        targetPeripheral = peripheral
        peripheral.delegate = self
        gattConnectionState = "Connecting"
        logEvent("connecting to beacon GATT: \(peripheral.identifier.uuidString)")
        centralManager.connect(peripheral, options: nil)
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

        if targetPeripheral?.state == .connected {
            transition(to: .avionicsOn)
            return
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

    private func handleConnected(_ peripheral: CBPeripheral) {
        targetPeripheral = peripheral
        peripheral.delegate = self
        gattConnectionState = "Connected"
        lastGATTActivityAt = Date()
        logEvent("beacon GATT connected")
        transition(to: .avionicsOn)
        onBeaconRelationshipAvailable?()
        peripheral.discoverServices([Self.serviceUUID])
    }

    private func handleDisconnected(_ peripheral: CBPeripheral, error: Error?) {
        guard targetPeripheral?.identifier == peripheral.identifier else { return }
        statusCharacteristic = nil
        recorderContactCharacteristic = nil
        gattConnectionState = "Disconnected"
        if let error {
            logEvent("beacon GATT disconnected: \(error.localizedDescription)")
        } else {
            logEvent("beacon GATT disconnected")
        }
        onBeaconCommunicationLost?()
        if shouldScanWhenReady, centralManager?.state == .poweredOn {
            startScan(scanAll: requestedScanAllMode)
        }
    }

    private func handleServicesDiscovered(_ peripheral: CBPeripheral, error: Error?) {
        if let error {
            lastError = error.localizedDescription
            logEvent("service discovery failed: \(error.localizedDescription)")
            return
        }
        guard let services = peripheral.services else { return }
        for service in services where service.uuid == Self.serviceUUID {
            peripheral.discoverCharacteristics([
                Self.statusCharacteristicUUID,
                Self.recorderContactCharacteristicUUID
            ], for: service)
        }
    }

    private func handleCharacteristicsDiscovered(_ peripheral: CBPeripheral, service: CBService, error: Error?) {
        if let error {
            lastError = error.localizedDescription
            logEvent("characteristic discovery failed: \(error.localizedDescription)")
            return
        }
        guard service.uuid == Self.serviceUUID, let characteristics = service.characteristics else { return }
        for characteristic in characteristics {
            switch characteristic.uuid {
            case Self.statusCharacteristicUUID:
                statusCharacteristic = characteristic
                peripheral.readValue(for: characteristic)
                if characteristic.properties.contains(.notify) {
                    peripheral.setNotifyValue(true, for: characteristic)
                }
            case Self.recorderContactCharacteristicUUID:
                recorderContactCharacteristic = characteristic
                writeRecorderContact(reason: "characteristic discovered")
            default:
                break
            }
        }
    }

    private func handleCharacteristicUpdate(_ characteristic: CBCharacteristic, error: Error?) {
        if let error {
            lastError = error.localizedDescription
            logEvent("characteristic update failed: \(error.localizedDescription)")
            return
        }
        guard characteristic.uuid == Self.statusCharacteristicUUID, let value = characteristic.value else { return }
        guard let packet = Self.parseStatusPacket(value) else {
            logEvent("beacon status rejected: malformed \(value.count)-byte payload")
            return
        }
        latestStatus = packet
        lastGATTActivityAt = Date()
        gattConnectionState = "Active"
        transition(to: .avionicsOn)
        detectBeaconReboot(packet)
        logEvent("beacon status: \(packet.label)")
        onBeaconRelationshipAvailable?()
    }

    private func detectBeaconReboot(_ packet: AvionicsBeaconStatusPacket) {
        defer {
            lastKnownBootCounter = packet.bootCounter
            lastKnownBootUUID = packet.bootUUID
        }
        guard let lastKnownBootCounter, let lastKnownBootUUID else { return }
        if lastKnownBootCounter != packet.bootCounter || lastKnownBootUUID != packet.bootUUID {
            logEvent("beacon reboot detected: boot \(lastKnownBootCounter)/\(lastKnownBootUUID) -> \(packet.bootCounter)/\(packet.bootUUID)")
        }
    }

    private func writeRecorderContact(reason: String) {
        guard let peripheral = targetPeripheral,
              peripheral.state == .connected,
              let characteristic = recorderContactCharacteristic,
              let token = recorderToken,
              token.count == 16 else { return }

        recorderWriteSequence = recorderWriteSequence &+ 1
        var payload = Data()
        payload.append(1)
        payload.append(token)
        payload.appendUInt32LE(recorderWriteSequence)
        payload.append(recorderVersion.major)
        payload.append(recorderVersion.minor)
        payload.append(recorderVersion.patch)
        payload.appendUInt16LE(0)

        guard payload.count == 26 else {
            logEvent("recorder contact not written: malformed local payload")
            return
        }

        let writeType: CBCharacteristicWriteType = characteristic.properties.contains(.writeWithoutResponse) ? .withoutResponse : .withResponse
        peripheral.writeValue(payload, for: characteristic, type: writeType)
        lastGATTActivityAt = Date()
        logEvent("recorder contact written: \(reason), seq \(recorderWriteSequence)")
    }

    private func diagnosticsPayload(recordingID: String, recordingSessionID: String, recordingEndReason: String) -> [String: Any] {
        var payload: [String: Any] = [
            "schema_version": 1,
            "recording_id": recordingID,
            "recording_session_uid": recordingSessionID,
            "recording_end_reason": recordingEndReason,
            "generated_at": Self.isoString(Date()),
            "recorder_token_hex": currentRecorderTokenHex(),
            "gatt_connection_state": gattConnectionState,
            "last_gatt_activity_utc": lastGATTActivityAt.map(Self.isoString) ?? "",
            "last_advertisement_utc": lastSeenAt.map(Self.isoString) ?? "",
            "advertisement_count_observed_by_iphone": advertisementCount,
            "manufacturer_data_hex": manufacturerDataHex,
            "events": logEntries.suffix(200).map(Self.logEntryDictionary)
        ]
        if let latestStatus {
            payload["latest_status"] = Self.statusDictionary(latestStatus)
        }
        return payload
    }

    private static func statusDictionary(_ status: AvionicsBeaconStatusPacket) -> [String: Any] {
        [
            "protocol_version": Int(status.protocolVersion),
            "boot_counter": Int(status.bootCounter),
            "boot_uuid": status.bootUUID,
            "advertisement_counter": Int(status.advertisementCounter),
            "reset_reason": status.resetReason.rawValue,
            "firmware_version": status.firmwareVersion,
            "uptime_seconds": Int(status.uptimeSeconds),
            "recorder_token_hex": status.recorderTokenHex,
            "last_recorder_contact_uptime_seconds": status.lastRecorderContactUptimeSeconds.map { Int($0) as Any } ?? NSNull(),
            "usb_diagnostic_kind": Int(status.usbDiagnosticKind),
            "usb_diagnostic_value": Int(status.usbDiagnosticValue)
        ]
    }

    private static func logEntryDictionary(_ entry: AvionicsBeaconLogEntry) -> [String: Any] {
        [
            "kind": entry.kind.rawValue,
            "timestamp": isoString(entry.timestamp),
            "marker": entry.marker,
            "event": entry.event ?? "",
            "peripheral_identifier": entry.peripheralIdentifier ?? "",
            "peripheral_name": entry.peripheralName ?? "",
            "advertised_local_name": entry.advertisedLocalName ?? "",
            "advertised_service_uuids": entry.advertisedServiceUUIDs,
            "manufacturer_data_hex": entry.manufacturerDataHex ?? "",
            "rssi": entry.rssi.map { $0 as Any } ?? NSNull(),
            "seconds_since_previous_advertisement": entry.secondsSincePreviousAdvertisement.map { $0 as Any } ?? NSNull(),
            "matched_custom_service": entry.matchedCustomService.map { $0 as Any } ?? NSNull()
        ]
    }

    private static func isoString(_ date: Date) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        return formatter.string(from: date)
    }

    private static func parseStatusPacket(_ data: Data) -> AvionicsBeaconStatusPacket? {
        guard data.count == AvionicsBeaconStatusPacket.expectedLength else { return nil }
        let bytes = [UInt8](data)
        guard bytes[0] == 1 else { return nil }
        let bootUUID = Data(bytes[5..<21]).ipcaUUIDHexString
        let token = Data(bytes[33..<49]).ipcaHexString
        let lastContactRaw = bytes.uint32LE(at: 49)
        return AvionicsBeaconStatusPacket(
            protocolVersion: bytes[0],
            bootCounter: bytes.uint32LE(at: 1),
            bootUUID: bootUUID,
            advertisementCounter: bytes.uint32LE(at: 21),
            resetReason: AvionicsBeaconResetReason(code: bytes[25]),
            firmwareVersion: "\(bytes[26]).\(bytes[27]).\(bytes[28])",
            uptimeSeconds: bytes.uint32LE(at: 29),
            recorderTokenHex: token,
            lastRecorderContactUptimeSeconds: lastContactRaw == UInt32.max ? nil : lastContactRaw,
            usbDiagnosticKind: bytes[53],
            usbDiagnosticValue: bytes.uint16LE(at: 54)
        )
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
            if let peripherals = dict[CBCentralManagerRestoredStatePeripheralsKey] as? [CBPeripheral] {
                for peripheral in peripherals {
                    peripheral.delegate = self
                    targetPeripheral = peripheral
                    logEvent("restored peripheral: \(peripheral.identifier.uuidString)")
                    if peripheral.state == .connected {
                        handleConnected(peripheral)
                    } else if central.state == .poweredOn {
                        central.connect(peripheral, options: nil)
                    }
                }
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

    nonisolated func centralManager(_ central: CBCentralManager, didConnect peripheral: CBPeripheral) {
        Task { @MainActor in
            handleConnected(peripheral)
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didFailToConnect peripheral: CBPeripheral, error: Error?) {
        Task { @MainActor in
            gattConnectionState = "Connect Failed"
            if let error {
                lastError = error.localizedDescription
                logEvent("beacon GATT connect failed: \(error.localizedDescription)")
            } else {
                logEvent("beacon GATT connect failed")
            }
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didDisconnectPeripheral peripheral: CBPeripheral, error: Error?) {
        Task { @MainActor in
            handleDisconnected(peripheral, error: error)
        }
    }
}

extension AvionicsBeaconManager: CBPeripheralDelegate {
    nonisolated func peripheral(_ peripheral: CBPeripheral, didDiscoverServices error: Error?) {
        Task { @MainActor in
            handleServicesDiscovered(peripheral, error: error)
        }
    }

    nonisolated func peripheral(_ peripheral: CBPeripheral, didDiscoverCharacteristicsFor service: CBService, error: Error?) {
        Task { @MainActor in
            handleCharacteristicsDiscovered(peripheral, service: service, error: error)
        }
    }

    nonisolated func peripheral(_ peripheral: CBPeripheral, didUpdateValueFor characteristic: CBCharacteristic, error: Error?) {
        Task { @MainActor in
            handleCharacteristicUpdate(characteristic, error: error)
        }
    }

    nonisolated func peripheral(_ peripheral: CBPeripheral, didWriteValueFor characteristic: CBCharacteristic, error: Error?) {
        Task { @MainActor in
            if let error {
                lastError = error.localizedDescription
                logEvent("recorder contact write failed: \(error.localizedDescription)")
            } else if characteristic.uuid == Self.recorderContactCharacteristicUUID {
                lastGATTActivityAt = Date()
                logEvent("recorder contact write acknowledged")
            }
        }
    }
}

private extension Data {
    var ipcaHexString: String {
        map { String(format: "%02X", $0) }.joined()
    }

    var ipcaUUIDHexString: String {
        guard count == 16 else { return ipcaHexString }
        let hex = ipcaHexString
        let parts = [
            hex.prefix(8),
            hex.dropFirst(8).prefix(4),
            hex.dropFirst(12).prefix(4),
            hex.dropFirst(16).prefix(4),
            hex.dropFirst(20)
        ]
        return parts.map(String.init).joined(separator: "-")
    }

    mutating func appendUInt16LE(_ value: UInt16) {
        append(UInt8(value & 0xFF))
        append(UInt8((value >> 8) & 0xFF))
    }

    mutating func appendUInt32LE(_ value: UInt32) {
        append(UInt8(value & 0xFF))
        append(UInt8((value >> 8) & 0xFF))
        append(UInt8((value >> 16) & 0xFF))
        append(UInt8((value >> 24) & 0xFF))
    }
}

private extension Array where Element == UInt8 {
    func uint16LE(at offset: Int) -> UInt16 {
        UInt16(self[offset]) |
            (UInt16(self[offset + 1]) << 8)
    }

    func uint32LE(at offset: Int) -> UInt32 {
        UInt32(self[offset]) |
            (UInt32(self[offset + 1]) << 8) |
            (UInt32(self[offset + 2]) << 16) |
            (UInt32(self[offset + 3]) << 24)
    }
}
