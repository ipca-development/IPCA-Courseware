import Combine
import Foundation
import UIKit

@MainActor
final class SystemMonitor: ObservableObject {
    @Published private(set) var batteryLevelPercent = 0
    @Published private(set) var batteryStateText = "Unknown"
    @Published private(set) var availableStorageBytes: Int64 = 0

    private var timer: Timer?

    var storageText: String {
        ByteCountFormatter.string(fromByteCount: availableStorageBytes, countStyle: .file)
    }

    func start() {
        UIDevice.current.isBatteryMonitoringEnabled = true
        refresh()
        timer?.invalidate()
        timer = Timer.scheduledTimer(withTimeInterval: 10, repeats: true) { [weak self] _ in
            Task { @MainActor in
                self?.refresh()
            }
        }
    }

    private func refresh() {
        let rawLevel = UIDevice.current.batteryLevel
        batteryLevelPercent = rawLevel < 0 ? 0 : Int(rawLevel * 100)
        batteryStateText = Self.label(for: UIDevice.current.batteryState)
        availableStorageBytes = Self.availableStorageBytes()
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
