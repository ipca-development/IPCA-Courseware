import Foundation

enum ExternalGPSSourceState: String, Codable, Equatable {
    case externalAccessory
    case internalDevice
    case unknown
    case noRecentData

    var label: String {
        switch self {
        case .externalAccessory:
            return "External Accessory"
        case .internalDevice:
            return "Internal iPhone/iPad GPS"
        case .unknown:
            return "Unknown"
        case .noRecentData:
            return "No Recent Location Data"
        }
    }

    var bannerLabel: String {
        switch self {
        case .externalAccessory:
            return "EXTERNAL GPS ACTIVE"
        case .internalDevice:
            return "INTERNAL GPS ACTIVE"
        case .unknown:
            return "GPS SOURCE UNKNOWN"
        case .noRecentData:
            return "NO RECENT LOCATION DATA"
        }
    }
}

enum ExternalGPSLogEntryKind: String, Codable {
    case event
    case location
}

struct ExternalGPSLogEntry: Identifiable, Codable, Equatable {
    var id: UUID
    var kind: ExternalGPSLogEntryKind
    var timestamp: Date
    var marker: String
    var event: String?
    var latitude: Double?
    var longitude: Double?
    var altitudeMeters: Double?
    var horizontalAccuracy: Double?
    var verticalAccuracy: Double?
    var speedMetersPerSecond: Double?
    var speedKnots: Double?
    var courseDegrees: Double?
    var sourceInformationAvailable: Bool?
    var isProducedByAccessory: Bool?
    var isSimulatedBySoftware: Bool?
    var locationAgeSeconds: Double?

    init(
        kind: ExternalGPSLogEntryKind,
        timestamp: Date = Date(),
        marker: String,
        event: String? = nil,
        latitude: Double? = nil,
        longitude: Double? = nil,
        altitudeMeters: Double? = nil,
        horizontalAccuracy: Double? = nil,
        verticalAccuracy: Double? = nil,
        speedMetersPerSecond: Double? = nil,
        speedKnots: Double? = nil,
        courseDegrees: Double? = nil,
        sourceInformationAvailable: Bool? = nil,
        isProducedByAccessory: Bool? = nil,
        isSimulatedBySoftware: Bool? = nil,
        locationAgeSeconds: Double? = nil
    ) {
        self.id = UUID()
        self.kind = kind
        self.timestamp = timestamp
        self.marker = marker
        self.event = event
        self.latitude = latitude
        self.longitude = longitude
        self.altitudeMeters = altitudeMeters
        self.horizontalAccuracy = horizontalAccuracy
        self.verticalAccuracy = verticalAccuracy
        self.speedMetersPerSecond = speedMetersPerSecond
        self.speedKnots = speedKnots
        self.courseDegrees = courseDegrees
        self.sourceInformationAvailable = sourceInformationAvailable
        self.isProducedByAccessory = isProducedByAccessory
        self.isSimulatedBySoftware = isSimulatedBySoftware
        self.locationAgeSeconds = locationAgeSeconds
    }
}

struct ExternalGPSSourceTransition: Identifiable, Equatable {
    var id = UUID()
    var timestamp: Date
    var state: ExternalGPSSourceState

    var label: String {
        "\(Self.timeFormatter.string(from: timestamp)) - \(state.label)"
    }

    private static let timeFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm:ss"
        return formatter
    }()
}
