import Combine
import CoreLocation
import Foundation

@MainActor
final class GPSLocationManager: NSObject, ObservableObject {
    @Published private(set) var state: GPSConnectionState = .permissionNeeded
    @Published private(set) var latestSample: GPSSample?
    @Published private(set) var lastError: String = ""

    private static let captureDesiredAccuracy = kCLLocationAccuracyNearestTenMeters
    private static let captureDistanceFilter: CLLocationDistance = 10
    private static let minimumCaptureSampleInterval: TimeInterval = 2
    private static let minimumCaptureDistance: CLLocationDistance = 8

    private var locationManager: CLLocationManager?
    private var captureRecordingID: String?
    private var captureStartedAt: Date?
    private var capturedSamples: [GPSSample] = []
    private var lastCapturedLocation: CLLocation?
    private var lastCapturedSampleAt: Date?

    func prepare() {
        ensureManager()
        requestPermissionIfNeeded()
        updateAuthorizationState()
    }

    func requestPermission() {
        ensureManager()
        requestPermissionIfNeeded()
        updateAuthorizationState()
    }

    func startCapture(recordingID: String, startedAt: Date) {
        ensureManager()
        captureRecordingID = recordingID
        captureStartedAt = startedAt
        capturedSamples = []
        lastCapturedLocation = nil
        lastCapturedSampleAt = nil

        guard let locationManager else {
            state = .unavailable
            return
        }

        switch locationManager.authorizationStatus {
        case .notDetermined:
            state = .permissionNeeded
            locationManager.requestWhenInUseAuthorization()
        case .authorizedAlways, .authorizedWhenInUse:
            startLocationUpdates()
        case .denied, .restricted:
            state = .denied
            lastError = "Location permission is denied or restricted."
        @unknown default:
            state = .failed
            lastError = "Unknown location authorization state."
        }
    }

    func stopCaptureAndSave(recordingID: String) -> String? {
        guard captureRecordingID == recordingID else {
            return nil
        }
        captureRecordingID = nil
        captureStartedAt = nil
        lastCapturedLocation = nil
        lastCapturedSampleAt = nil
        locationManager?.stopUpdatingLocation()
        updateAuthorizationState()

        guard !capturedSamples.isEmpty else {
            capturedSamples = []
            return nil
        }

        do {
            let directory = try RecordingStore.recordingsDirectory()
            let url = directory.appendingPathComponent("\(recordingID).gps.json")
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
            lastError = "Could not save GPS samples: \(error.localizedDescription)"
            capturedSamples = []
            return nil
        }
    }

    private func ensureManager() {
        guard locationManager == nil else { return }
        let manager = CLLocationManager()
        manager.delegate = self
        manager.desiredAccuracy = Self.captureDesiredAccuracy
        manager.distanceFilter = Self.captureDistanceFilter
        manager.pausesLocationUpdatesAutomatically = false
        manager.allowsBackgroundLocationUpdates = true
        manager.showsBackgroundLocationIndicator = true
        if #available(iOS 12.0, *) {
            manager.activityType = .airborne
        } else {
            manager.activityType = .otherNavigation
        }
        locationManager = manager
    }

    private func requestPermissionIfNeeded() {
        guard let locationManager else {
            state = .unavailable
            return
        }
        if locationManager.authorizationStatus == .notDetermined {
            state = .permissionNeeded
            locationManager.requestWhenInUseAuthorization()
        }
    }

    private func startLocationUpdates() {
        locationManager?.desiredAccuracy = Self.captureDesiredAccuracy
        locationManager?.distanceFilter = Self.captureDistanceFilter
        state = .recording
        lastError = ""
        locationManager?.startUpdatingLocation()
    }

    private func updateAuthorizationState() {
        guard let locationManager else {
            state = .permissionNeeded
            return
        }
        switch locationManager.authorizationStatus {
        case .notDetermined:
            state = .permissionNeeded
        case .authorizedAlways, .authorizedWhenInUse:
            state = captureRecordingID == nil ? .ready : .recording
        case .denied, .restricted:
            state = .denied
        @unknown default:
            state = .failed
        }
    }

    private func handle(location: CLLocation) {
        let timestamp = location.timestamp
        let speedMetersPerSecond = max(0, location.speed)
        let course = location.course >= 0 ? location.course : -1
        let sample = GPSSample(
            timestamp: timestamp,
            secondsSinceRecordingStart: captureStartedAt.map { timestamp.timeIntervalSince($0) } ?? 0,
            latitude: location.coordinate.latitude,
            longitude: location.coordinate.longitude,
            altitude: location.altitude,
            speedMetersPerSecond: speedMetersPerSecond,
            speedKnots: speedMetersPerSecond * 1.943844492,
            course: course,
            horizontalAccuracy: location.horizontalAccuracy,
            verticalAccuracy: location.verticalAccuracy
        )

        latestSample = sample
        if captureRecordingID != nil, shouldCapture(location: location, timestamp: timestamp) {
            capturedSamples.append(sample)
            lastCapturedLocation = location
            lastCapturedSampleAt = timestamp
        }
    }

    private func shouldCapture(location: CLLocation, timestamp: Date) -> Bool {
        guard let lastCapturedSampleAt, let lastCapturedLocation else {
            return true
        }

        let secondsSinceLastSample = timestamp.timeIntervalSince(lastCapturedSampleAt)
        let metersSinceLastSample = location.distance(from: lastCapturedLocation)

        return secondsSinceLastSample >= Self.minimumCaptureSampleInterval
            || metersSinceLastSample >= Self.minimumCaptureDistance
    }
}

extension GPSLocationManager: CLLocationManagerDelegate {
    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        Task { @MainActor in
            self.updateAuthorizationState()
            if self.captureRecordingID != nil {
                switch manager.authorizationStatus {
                case .authorizedAlways, .authorizedWhenInUse:
                    self.startLocationUpdates()
                case .denied, .restricted:
                    self.lastError = "Location permission is denied or restricted."
                default:
                    break
                }
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
            self.state = .failed
            self.lastError = error.localizedDescription
        }
    }
}
