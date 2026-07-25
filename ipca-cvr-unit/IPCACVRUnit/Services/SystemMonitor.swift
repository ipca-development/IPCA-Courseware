import Combine
import Foundation
import UIKit

@MainActor
final class SystemMonitor: ObservableObject {
    @Published private(set) var batteryLevelPercent = 0
    @Published private(set) var batteryStateText = "Unknown"
    @Published private(set) var availableStorageBytes: Int64 = 0
    @Published private(set) var storageText = "0 bytes"

    private static let refreshInterval: TimeInterval = 30
    private static let storageFormatter: ByteCountFormatter = {
        let formatter = ByteCountFormatter()
        formatter.countStyle = .file
        return formatter
    }()
    private var timer: Timer?
    func start() {
        UIDevice.current.isBatteryMonitoringEnabled = true
        refresh()
        timer?.invalidate()
        timer = Timer.scheduledTimer(withTimeInterval: Self.refreshInterval, repeats: true) { [weak self] _ in
            Task { @MainActor in
                self?.refresh()
            }
        }
    }

    func refresh() {
        let rawLevel = UIDevice.current.batteryLevel
        batteryLevelPercent = rawLevel < 0 ? 0 : Int(rawLevel * 100)
        batteryStateText = Self.label(for: UIDevice.current.batteryState)
        availableStorageBytes = Self.availableStorageBytes()
        storageText = Self.storageFormatter.string(fromByteCount: availableStorageBytes)
    }

    private static func label(for state: UIDevice.BatteryState) -> String {
        switch state {
        case .charging: "Charging"
        case .full: "Full"
        case .unplugged: "Unplugged"
        case .unknown: "Unknown"
        @unknown default: "Unknown"
        }
    }

    private static func availableStorageBytes() -> Int64 {
        do {
            let url = try FileManager.default.url(
                for: .documentDirectory,
                in: .userDomainMask,
                appropriateFor: nil,
                create: true
            )
            let values = try url.resourceValues(forKeys: [.volumeAvailableCapacityForImportantUsageKey])
            return values.volumeAvailableCapacityForImportantUsage ?? 0
        } catch {
            return 0
        }
    }
}
