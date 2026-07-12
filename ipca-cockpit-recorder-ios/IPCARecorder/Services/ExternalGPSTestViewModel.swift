import Combine
import CoreLocation
import Foundation

@MainActor
final class ExternalGPSTestViewModel: NSObject, ObservableObject {
    @Published private(set) var authorizationStatus: CLAuthorizationStatus = .notDetermined
    @Published private(set) var locationServicesEnabled = CLLocationManager.locationServicesEnabled()
    @Published private(set) var isRunning = false
    @Published private(set) var currentSource: ExternalGPSSourceState = .unknown
    @Published private(set) var isProducedByAccessory: Bool?
    @Published private(set) var isSimulatedBySoftware: Bool?
    @Published private(set) var sourceInformationAvailable = false
    @Published private(set) var lastLocation: CLLocation?
    @Published private(set) var ageOfLastLocation: TimeInterval?
    @Published private(set) var totalLocationUpdates = 0
    @Published private(set) var consecutiveAccessoryUpdates = 0
    @Published private(set) var consecutiveInternalUpdates = 0
    @Published private(set) var logEntries: [ExternalGPSLogEntry] = []
    @Published private(set) var sourceTransitions: [ExternalGPSSourceTransition] = []
    @Published private(set) var activeMarker = "Not marked"
    @Published private(set) var lastError = ""

    private let locationManager = CLLocationManager()
    private var timer: Timer?
    private var staleConditionActive = false
    private var firstLocationReceived = false
    private var confirmedNonStaleSource: ExternalGPSSourceState?
    private var consecutiveUnknownUpdates = 0

    override init() {
        super.init()
        locationManager.delegate = self
        configureLocationManager()
        authorizationStatus = locationManager.authorizationStatus
    }

    deinit {
        timer?.invalidate()
        locationManager.stopUpdatingLocation()
    }

    var bannerState: ExternalGPSSourceState {
        if staleConditionActive {
            return .noRecentData
        }
        return currentSource
    }

    var lastLocationTimestampText: String {
        guard let lastLocation else { return "None" }
        return Self.isoFormatter.string(from: lastLocation.timestamp)
    }

    var ageText: String {
        guard let ageOfLastLocation else { return "No location yet" }
        return String(format: "%.1f s", ageOfLastLocation)
    }

    func startTest() {
        configureLocationManager()
        locationServicesEnabled = CLLocationManager.locationServicesEnabled()
        authorizationStatus = locationManager.authorizationStatus
        lastError = ""

        switch authorizationStatus {
        case .notDetermined:
            locationManager.requestWhenInUseAuthorization()
        case .authorizedAlways, .authorizedWhenInUse:
            break
        case .denied, .restricted:
            logEvent("test start blocked: location permission denied or restricted")
            return
        @unknown default:
            logEvent("test start blocked: unknown authorization status")
            return
        }

        guard CLLocationManager.locationServicesEnabled() else {
            logEvent("test start blocked: location services disabled")
            return
        }

        isRunning = true
        startTimer()
        locationManager.startUpdatingLocation()
        logEvent("test started")
        debug("[ExternalGPSTest] test started")
    }

    func stopTest() {
        guard isRunning else { return }
        locationManager.stopUpdatingLocation()
        isRunning = false
        timer?.invalidate()
        timer = nil
        logEvent("test stopped")
        debug("[ExternalGPSTest] test stopped")
    }

    func clearLog() {
        logEntries = []
        sourceTransitions = []
        lastError = ""
        logEvent("log cleared")
    }

    func mark(_ marker: String) {
        activeMarker = marker
        logEvent("manual test-state marker changed: \(marker)")
        debug("[ExternalGPSTest] marker changed: \(marker)")
    }

    func exportFiles() throws -> [URL] {
        try ExternalGPSExportService.exportFiles(entries: logEntries)
    }

    private func configureLocationManager() {
        locationManager.desiredAccuracy = kCLLocationAccuracyBestForNavigation
        locationManager.distanceFilter = kCLDistanceFilterNone
        locationManager.pausesLocationUpdatesAutomatically = false
        locationManager.activityType = .airborne
    }

    private func startTimer() {
        timer?.invalidate()
        timer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { [weak self] _ in
            Task { @MainActor in
                self?.updateAgeAndStaleState()
            }
        }
        updateAgeAndStaleState()
    }

    private func handle(location: CLLocation) {
        totalLocationUpdates += 1
        lastLocation = location
        ageOfLastLocation = Date().timeIntervalSince(location.timestamp)

        let sourceInfoAvailable: Bool
        let accessory: Bool?
        let simulated: Bool?
        if #available(iOS 15.0, *) {
            let sourceInfo = location.sourceInformation
            sourceInfoAvailable = sourceInfo != nil
            accessory = sourceInfo?.isProducedByAccessory
            simulated = sourceInfo?.isSimulatedBySoftware
        } else {
            sourceInfoAvailable = false
            accessory = nil
            simulated = nil
        }

        sourceInformationAvailable = sourceInfoAvailable
        isProducedByAccessory = accessory
        isSimulatedBySoftware = simulated

        updateConfirmation(accessory: accessory)
        appendLocationLog(location, sourceInfoAvailable: sourceInfoAvailable, accessory: accessory, simulated: simulated)

        if !firstLocationReceived {
            firstLocationReceived = true
            logEvent("first location received")
        }

        if staleConditionActive {
            staleConditionActive = false
            logEvent("stale location condition ended")
            appendTransition(currentSource)
        }

        debug("[ExternalGPSTest][LOCATION] marker=\(activeMarker) lat=\(location.coordinate.latitude) lon=\(location.coordinate.longitude) accessory=\(optionalBool(accessory)) simulated=\(optionalBool(simulated))")
    }

    private func updateConfirmation(accessory: Bool?) {
        let previous = currentSource

        switch accessory {
        case true:
            consecutiveAccessoryUpdates += 1
            consecutiveInternalUpdates = 0
            consecutiveUnknownUpdates = 0
            if consecutiveAccessoryUpdates >= 3 || confirmedNonStaleSource == nil {
                currentSource = .externalAccessory
            }
        case false:
            consecutiveInternalUpdates += 1
            consecutiveAccessoryUpdates = 0
            consecutiveUnknownUpdates = 0
            if consecutiveInternalUpdates >= 3 || confirmedNonStaleSource == nil {
                currentSource = .internalDevice
            }
        case nil:
            consecutiveAccessoryUpdates = 0
            consecutiveInternalUpdates = 0
            consecutiveUnknownUpdates += 1
            if consecutiveUnknownUpdates >= 3 || confirmedNonStaleSource == nil {
                currentSource = .unknown
            }
        }

        if currentSource == .externalAccessory || currentSource == .internalDevice {
            confirmedNonStaleSource = currentSource
        }

        if currentSource != previous {
            logSourceChange(from: previous, to: currentSource)
            appendTransition(currentSource)
        }
    }

    private func updateAgeAndStaleState() {
        if let lastLocation {
            ageOfLastLocation = Date().timeIntervalSince(lastLocation.timestamp)
        }

        let stale = ageOfLastLocation.map { $0 > 10 } ?? true
        if stale && !staleConditionActive {
            staleConditionActive = true
            logEvent("stale location condition started")
            appendTransition(.noRecentData)
        } else if !stale && staleConditionActive {
            staleConditionActive = false
            logEvent("stale location condition ended")
            appendTransition(currentSource)
        }
    }

    private func appendLocationLog(_ location: CLLocation, sourceInfoAvailable: Bool, accessory: Bool?, simulated: Bool?) {
        let speedMetersPerSecond = location.speed >= 0 ? location.speed : -1
        let course = location.course >= 0 ? location.course : -1
        let entry = ExternalGPSLogEntry(
            kind: .location,
            timestamp: Date(),
            marker: activeMarker,
            latitude: location.coordinate.latitude,
            longitude: location.coordinate.longitude,
            altitudeMeters: location.altitude,
            horizontalAccuracy: location.horizontalAccuracy,
            verticalAccuracy: location.verticalAccuracy,
            speedMetersPerSecond: speedMetersPerSecond,
            speedKnots: speedMetersPerSecond >= 0 ? speedMetersPerSecond * 1.943844492 : -1,
            courseDegrees: course,
            sourceInformationAvailable: sourceInfoAvailable,
            isProducedByAccessory: accessory,
            isSimulatedBySoftware: simulated,
            locationAgeSeconds: Date().timeIntervalSince(location.timestamp)
        )
        logEntries.append(entry)
    }

    private func logEvent(_ event: String) {
        let entry = ExternalGPSLogEntry(
            kind: .event,
            timestamp: Date(),
            marker: activeMarker,
            event: event,
            locationAgeSeconds: ageOfLastLocation
        )
        logEntries.append(entry)
    }

    private func logSourceChange(from previous: ExternalGPSSourceState, to next: ExternalGPSSourceState) {
        let event: String
        switch (previous, next) {
        case (.internalDevice, .externalAccessory):
            event = "source changed from internal to accessory"
        case (.externalAccessory, .internalDevice):
            event = "source changed from accessory to internal"
        case (_, .unknown):
            event = "source became unknown"
        default:
            event = "source changed from \(previous.label) to \(next.label)"
        }
        logEvent(event)
        debug("[ExternalGPSTest][SOURCE_CHANGE] \(event)")
    }

    private func appendTransition(_ state: ExternalGPSSourceState) {
        if sourceTransitions.last?.state == state {
            return
        }
        sourceTransitions.insert(ExternalGPSSourceTransition(timestamp: Date(), state: state), at: 0)
    }

    private func optionalBool(_ value: Bool?) -> String {
        guard let value else { return "null" }
        return value ? "true" : "false"
    }

    private func debug(_ message: String) {
        #if DEBUG
        print(message)
        #endif
    }

    private static let isoFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        return formatter
    }()
}

extension ExternalGPSTestViewModel: CLLocationManagerDelegate {
    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let status = manager.authorizationStatus
        Task { @MainActor in
            self.authorizationStatus = status
            self.locationServicesEnabled = CLLocationManager.locationServicesEnabled()
            self.logEvent("authorization changed: \(self.authorizationLabel)")
            self.debug("[ExternalGPSTest] authorization changed: \(self.authorizationLabel)")
            if self.isRunning, status == .authorizedAlways || status == .authorizedWhenInUse {
                self.locationManager.startUpdatingLocation()
            }
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        Task { @MainActor in
            for location in locations {
                self.handle(location: location)
            }
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        Task { @MainActor in
            self.lastError = error.localizedDescription
            self.logEvent("location manager error: \(error.localizedDescription)")
            self.debug("[ExternalGPSTest][ERROR] \(error.localizedDescription)")
        }
    }
}

extension ExternalGPSTestViewModel {
    var authorizationLabel: String {
        switch authorizationStatus {
        case .notDetermined:
            return "Not Determined"
        case .restricted:
            return "Restricted"
        case .denied:
            return "Denied"
        case .authorizedAlways:
            return "Authorized Always"
        case .authorizedWhenInUse:
            return "Authorized When In Use"
        @unknown default:
            return "Unknown"
        }
    }
}
