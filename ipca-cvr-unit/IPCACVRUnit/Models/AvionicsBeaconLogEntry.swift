import Foundation

enum AvionicsBeaconState: String, Codable, Equatable {
    case bluetoothUnavailable
    case scanning
    case candidateOn
    case avionicsOn
    case temporarilyMissing
    case avionicsOff
    case unknown

    var label: String {
        switch self {
        case .bluetoothUnavailable:
            return "Bluetooth Unavailable"
        case .scanning:
            return "Scanning"
        case .candidateOn:
            return "Candidate ON"
        case .avionicsOn:
            return "AVIONICS ON"
        case .temporarilyMissing:
            return "Temporarily Missing"
        case .avionicsOff:
            return "AVIONICS OFF"
        case .unknown:
            return "UNKNOWN"
        }
    }
}

enum AvionicsPowerState: String, Codable, Equatable {
    case on
    case off
}

enum AvionicsBeaconOperationalSeverity {
    case nominal
    case warning
    case danger
    case inactive
}

struct AvionicsBeaconOperationalStatus {
    static let weakSignalAfter: TimeInterval = 12

    var label: String
    var severity: AvionicsBeaconOperationalSeverity
}

extension AvionicsBeaconState {
    func operationalStatus(secondsSinceLastAdvertisement: TimeInterval?) -> AvionicsBeaconOperationalStatus {
        switch self {
        case .avionicsOn:
            return AvionicsBeaconOperationalStatus(label: "AVIONICS ON", severity: .nominal)
        case .temporarilyMissing:
            if let secondsSinceLastAdvertisement,
               secondsSinceLastAdvertisement >= AvionicsBeaconOperationalStatus.weakSignalAfter {
                return AvionicsBeaconOperationalStatus(label: "Beacon Signal Weak", severity: .warning)
            }
            return AvionicsBeaconOperationalStatus(label: "AVIONICS ON", severity: .nominal)
        case .avionicsOff:
            return AvionicsBeaconOperationalStatus(label: "AVIONICS OFF", severity: .danger)
        case .candidateOn:
            return AvionicsBeaconOperationalStatus(label: "Confirming Beacon", severity: .warning)
        case .scanning:
            return AvionicsBeaconOperationalStatus(label: "Listening", severity: .warning)
        case .bluetoothUnavailable:
            return AvionicsBeaconOperationalStatus(label: "Bluetooth Unavailable", severity: .danger)
        case .unknown:
            return AvionicsBeaconOperationalStatus(label: "UNKNOWN", severity: .inactive)
        }
    }
}

enum AvionicsBeaconLogEntryKind: String, Codable {
    case event
    case discovery
}

struct AvionicsBeaconLogEntry: Identifiable, Codable, Equatable {
    var id: UUID
    var kind: AvionicsBeaconLogEntryKind
    var timestamp: Date
    var marker: String
    var event: String?
    var peripheralIdentifier: String?
    var peripheralName: String?
    var advertisedLocalName: String?
    var advertisedServiceUUIDs: [String]
    var manufacturerDataHex: String?
    var rssi: Int?
    var secondsSincePreviousAdvertisement: Double?
    var matchedCustomService: Bool?

    init(
        kind: AvionicsBeaconLogEntryKind,
        timestamp: Date = Date(),
        marker: String,
        event: String? = nil,
        peripheralIdentifier: String? = nil,
        peripheralName: String? = nil,
        advertisedLocalName: String? = nil,
        advertisedServiceUUIDs: [String] = [],
        manufacturerDataHex: String? = nil,
        rssi: Int? = nil,
        secondsSincePreviousAdvertisement: Double? = nil,
        matchedCustomService: Bool? = nil
    ) {
        self.id = UUID()
        self.kind = kind
        self.timestamp = timestamp
        self.marker = marker
        self.event = event
        self.peripheralIdentifier = peripheralIdentifier
        self.peripheralName = peripheralName
        self.advertisedLocalName = advertisedLocalName
        self.advertisedServiceUUIDs = advertisedServiceUUIDs
        self.manufacturerDataHex = manufacturerDataHex
        self.rssi = rssi
        self.secondsSincePreviousAdvertisement = secondsSincePreviousAdvertisement
        self.matchedCustomService = matchedCustomService
    }
}
