import Combine
import CoreBluetooth
import Foundation

@MainActor
final class AHRSBLEManager: NSObject, ObservableObject {
    @Published private(set) var connectionState: AHRSConnectionState = .disconnected
    @Published private(set) var latestSample: AHRSSample?
    @Published private(set) var lastLine: String = ""
    @Published private(set) var lastError: String = ""
    @Published private(set) var receiveRateHz: Double = 0
    @Published private(set) var receiveHealthMessage: String = "Waiting for AHRS samples."

    private let deviceName = "IPCA-CVR"
    private let serviceUUID = CBUUID(string: "7b7f1000-9a7b-4f6a-9f0c-6c9c1f8b0001")
    private let characteristicUUID = CBUUID(string: "7b7f1001-9a7b-4f6a-9f0c-6c9c1f8b0001")
    private let statusCharacteristicUUID = CBUUID(string: "7b7f1002-9a7b-4f6a-9f0c-6c9c1f8b0001")

    private var centralManager: CBCentralManager?
    private var peripheral: CBPeripheral?
    private var characteristic: CBCharacteristic?
    private var statusCharacteristic: CBCharacteristic?
    private var captureRecordingID: String?
    private var captureStartedAt: Date?
    private var capturedSamples: [AHRSSample] = []
    private var recentSampleTimes: [Date] = []

    func start() {
        if centralManager == nil {
            centralManager = CBCentralManager(delegate: self, queue: nil)
            return
        }
        startScanningIfPossible()
    }

    func startCapture(recordingID: String, startedAt: Date) {
        captureRecordingID = recordingID
        captureStartedAt = startedAt
        capturedSamples = []
        recentSampleTimes = []
        receiveRateHz = 0
        receiveHealthMessage = "Measuring AHRS receive rate."
    }

    func stopCaptureAndSave(recordingID: String) -> String? {
        guard captureRecordingID == recordingID else {
            return nil
        }
        captureRecordingID = nil
        captureStartedAt = nil
        guard !capturedSamples.isEmpty else {
            capturedSamples = []
            return nil
        }

        do {
            let directory = try RecordingStore.recordingsDirectory()
            let url = directory.appendingPathComponent("\(recordingID).ahrs.json")
            let encoder = JSONEncoder()
            encoder.outputFormatting = [.prettyPrinted, .sortedKeys]
            encoder.dateEncodingStrategy = .custom { @Sendable date, encoder in
                let formatter = ISO8601DateFormatter()
                formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
                formatter.timeZone = TimeZone(secondsFromGMT: 0)

                var container = encoder.singleValueContainer()
                try container.encode(formatter.string(from: date))
            }
            let data = try encoder.encode(capturedSamples)
            try data.write(to: url, options: [.atomic])
            capturedSamples = []
            return url.path
        } catch {
            lastError = "Could not save AHRS samples: \(error.localizedDescription)"
            capturedSamples = []
            return nil
        }
    }

    func sendStatusCommand(_ command: String) {
        guard connectionState == .connected,
              let peripheral,
              let statusCharacteristic,
              let data = command.data(using: .utf8)
        else {
            return
        }

        let writeType: CBCharacteristicWriteType = statusCharacteristic.properties.contains(.writeWithoutResponse) ? .withoutResponse : .withResponse
        peripheral.writeValue(data, for: statusCharacteristic, type: writeType)
    }

    private func startScanningIfPossible() {
        guard let centralManager, centralManager.state == .poweredOn else {
            return
        }
        lastError = ""
        connectionState = .scanning
        centralManager.scanForPeripherals(withServices: [serviceUUID], options: [
            CBCentralManagerScanOptionAllowDuplicatesKey: false
        ])
    }

    private func handle(line: String) {
        guard let sample = Self.parseAHRSLine(line, recordingStartedAt: captureStartedAt) else {
            return
        }
        latestSample = sample
        lastLine = line
        updateReceiveRate(sample.timestamp)
        if captureRecordingID != nil {
            capturedSamples.append(sample)
        }
    }

    private func updateReceiveRate(_ timestamp: Date) {
        recentSampleTimes.append(timestamp)
        recentSampleTimes = recentSampleTimes.filter { timestamp.timeIntervalSince($0) <= 10 }

        guard let first = recentSampleTimes.first,
              recentSampleTimes.count >= 2
        else {
            receiveRateHz = 0
            receiveHealthMessage = "Measuring AHRS receive rate."
            return
        }

        let span = timestamp.timeIntervalSince(first)
        guard span > 0 else { return }

        receiveRateHz = Double(recentSampleTimes.count - 1) / span
        if receiveRateHz < 2.0 && recentSampleTimes.count >= 5 {
            receiveHealthMessage = String(format: "Low AHRS rate: %.1f Hz. Expected near 4 Hz.", receiveRateHz)
        } else {
            receiveHealthMessage = String(format: "AHRS rate %.1f Hz", receiveRateHz)
        }
    }

    private static func parseAHRSLine(_ rawLine: String, recordingStartedAt: Date?) -> AHRSSample? {
        let line = rawLine.trimmingCharacters(in: .whitespacesAndNewlines)
        guard line.hasPrefix("AHRS,") else {
            return nil
        }

        var values: [String: Double] = [:]
        for part in line.split(separator: ",").dropFirst() {
            let pair = part.split(separator: "=", maxSplits: 1)
            guard pair.count == 2 else { continue }
            values[String(pair[0])] = Double(pair[1])
        }

        guard let roll = values["ROLL"],
              let pitch = values["PITCH"],
              let yaw = values["YAW"],
              let acc = values["ACC"],
              let magHeading = values["MAGHDG"]
        else {
            return nil
        }

        let timestamp = Date()
        return AHRSSample(
            timestamp: timestamp,
            secondsSinceRecordingStart: recordingStartedAt.map { timestamp.timeIntervalSince($0) } ?? 0,
            roll: roll,
            pitch: pitch,
            yaw: yaw,
            acceleration: acc,
            magneticHeading: magHeading,
            rawLine: line
        )
    }

}

extension AHRSBLEManager: CBCentralManagerDelegate {
    nonisolated func centralManagerDidUpdateState(_ central: CBCentralManager) {
        Task { @MainActor in
            switch central.state {
            case .poweredOn:
                self.startScanningIfPossible()
            case .poweredOff:
                self.connectionState = .disconnected
                self.lastError = "Bluetooth is powered off."
            case .unauthorized:
                self.connectionState = .disconnected
                self.lastError = "Bluetooth permission is not authorized."
            case .unsupported:
                self.connectionState = .disconnected
                self.lastError = "Bluetooth is not supported on this device."
            default:
                self.connectionState = .disconnected
            }
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didDiscover peripheral: CBPeripheral, advertisementData: [String: Any], rssi RSSI: NSNumber) {
        let localName = advertisementData[CBAdvertisementDataLocalNameKey] as? String
        let name = peripheral.name ?? localName ?? ""
        guard name == "IPCA-CVR" else {
            return
        }

        Task { @MainActor in
            self.connectionState = .connecting
            self.peripheral = peripheral
            peripheral.delegate = self
            central.stopScan()
            central.connect(peripheral)
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didConnect peripheral: CBPeripheral) {
        Task { @MainActor in
            self.connectionState = .connected
            self.lastError = ""
            peripheral.discoverServices([self.serviceUUID])
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didFailToConnect peripheral: CBPeripheral, error: Error?) {
        Task { @MainActor in
            self.connectionState = .disconnected
            self.lastError = error?.localizedDescription ?? "Failed to connect to IPCA-CVR."
            self.startScanningIfPossible()
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didDisconnectPeripheral peripheral: CBPeripheral, error: Error?) {
        Task { @MainActor in
            self.connectionState = .disconnected
            self.characteristic = nil
            self.statusCharacteristic = nil
            self.receiveRateHz = 0
            self.receiveHealthMessage = "Waiting for AHRS samples."
            self.recentSampleTimes = []
            if let error {
                self.lastError = error.localizedDescription
            }
            self.startScanningIfPossible()
        }
    }
}

extension AHRSBLEManager: CBPeripheralDelegate {
    nonisolated func peripheral(_ peripheral: CBPeripheral, didDiscoverServices error: Error?) {
        Task { @MainActor in
            if let error {
                self.lastError = error.localizedDescription
                return
            }
            peripheral.services?
                .filter { $0.uuid == self.serviceUUID }
                .forEach { peripheral.discoverCharacteristics([self.characteristicUUID, self.statusCharacteristicUUID], for: $0) }
        }
    }

    nonisolated func peripheral(_ peripheral: CBPeripheral, didDiscoverCharacteristicsFor service: CBService, error: Error?) {
        Task { @MainActor in
            if let error {
                self.lastError = error.localizedDescription
                return
            }
            if let statusCharacteristic = service.characteristics?.first(where: { $0.uuid == self.statusCharacteristicUUID }) {
                self.statusCharacteristic = statusCharacteristic
                self.sendStatusCommand("IPAD=1")
            }

            guard let characteristic = service.characteristics?.first(where: { $0.uuid == self.characteristicUUID }) else {
                self.lastError = "AHRS characteristic not found."
                return
            }
            self.characteristic = characteristic
            peripheral.setNotifyValue(true, for: characteristic)
            peripheral.readValue(for: characteristic)
        }
    }

    nonisolated func peripheral(_ peripheral: CBPeripheral, didUpdateValueFor characteristic: CBCharacteristic, error: Error?) {
        Task { @MainActor in
            if let error {
                self.lastError = error.localizedDescription
                return
            }
            guard let data = characteristic.value,
                  let line = String(data: data, encoding: .utf8)
            else {
                return
            }
            self.handle(line: line)
        }
    }
}
