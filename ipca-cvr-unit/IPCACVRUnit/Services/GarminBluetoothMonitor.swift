import Combine
import CoreBluetooth
import Foundation

@MainActor
final class GarminBluetoothMonitor: NSObject, ObservableObject {
    @Published private(set) var bluetoothState = "Unknown"
    @Published private(set) var isG3XConnected = false
    @Published private(set) var isGNX375Connected = false
    @Published private(set) var g3xName = "G3X"
    @Published private(set) var gnx375Name = "GNX375"
    @Published private(set) var lastError = ""

    var bothGarminDevicesConnected: Bool {
        isG3XConnected && isGNX375Connected
    }

    private var centralManager: CBCentralManager?
    private var g3xPeripheral: CBPeripheral?
    private var gnx375Peripheral: CBPeripheral?
    private var configuredServiceUUIDs: [CBUUID] = []

    func configure(settings: SettingsStore) {
        g3xName = settings.garminG3XName
        gnx375Name = settings.garminGNX375Name
        configuredServiceUUIDs = settings.configuredGarminServiceUUIDs.map { CBUUID(string: $0) }
    }

    func start(settings: SettingsStore) {
        configure(settings: settings)
        if centralManager == nil {
            centralManager = CBCentralManager(delegate: self, queue: nil)
            return
        }
        startScanningIfPossible()
    }

    func refresh(settings: SettingsStore) {
        configure(settings: settings)
        startScanningIfPossible()
    }

    private func startScanningIfPossible() {
        guard let centralManager, centralManager.state == .poweredOn else { return }
        lastError = configuredServiceUUIDs.isEmpty
            ? "Scanning by advertised name. Configure Garmin BLE service UUIDs for more reliable background discovery."
            : ""
        centralManager.scanForPeripherals(
            withServices: configuredServiceUUIDs.isEmpty ? nil : configuredServiceUUIDs,
            options: [CBCentralManagerScanOptionAllowDuplicatesKey: true]
        )
    }

    private func handleDiscovered(_ peripheral: CBPeripheral, advertisementData: [String: Any]) {
        let localName = advertisementData[CBAdvertisementDataLocalNameKey] as? String
        let name = peripheral.name ?? localName ?? ""
        guard !name.isEmpty else { return }

        if matches(name: name, expected: g3xName), g3xPeripheral?.identifier != peripheral.identifier {
            g3xPeripheral = peripheral
            centralManager?.connect(peripheral)
        }

        if matches(name: name, expected: gnx375Name), gnx375Peripheral?.identifier != peripheral.identifier {
            gnx375Peripheral = peripheral
            centralManager?.connect(peripheral)
        }
    }

    private func matches(name: String, expected: String) -> Bool {
        let trimmedExpected = expected.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmedExpected.isEmpty else { return false }
        return name.localizedCaseInsensitiveContains(trimmedExpected)
    }

    private func markConnected(_ peripheral: CBPeripheral, connected: Bool) {
        if g3xPeripheral?.identifier == peripheral.identifier {
            isG3XConnected = connected
        }
        if gnx375Peripheral?.identifier == peripheral.identifier {
            isGNX375Connected = connected
        }
    }
}

extension GarminBluetoothMonitor: CBCentralManagerDelegate {
    nonisolated func centralManagerDidUpdateState(_ central: CBCentralManager) {
        Task { @MainActor in
            switch central.state {
            case .poweredOn:
                bluetoothState = "Powered On"
                startScanningIfPossible()
            case .poweredOff:
                bluetoothState = "Powered Off"
                isG3XConnected = false
                isGNX375Connected = false
            case .unauthorized:
                bluetoothState = "Unauthorized"
                lastError = "Bluetooth permission is required."
            case .unsupported:
                bluetoothState = "Unsupported"
            case .resetting:
                bluetoothState = "Resetting"
            case .unknown:
                bluetoothState = "Unknown"
            @unknown default:
                bluetoothState = "Unknown"
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
            handleDiscovered(peripheral, advertisementData: advertisementData)
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didConnect peripheral: CBPeripheral) {
        Task { @MainActor in
            markConnected(peripheral, connected: true)
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didFailToConnect peripheral: CBPeripheral, error: Error?) {
        Task { @MainActor in
            markConnected(peripheral, connected: false)
            lastError = error?.localizedDescription ?? "Could not connect to Garmin device."
            startScanningIfPossible()
        }
    }

    nonisolated func centralManager(_ central: CBCentralManager, didDisconnectPeripheral peripheral: CBPeripheral, error: Error?) {
        Task { @MainActor in
            markConnected(peripheral, connected: false)
            if let error {
                lastError = error.localizedDescription
            }
            startScanningIfPossible()
        }
    }
}
