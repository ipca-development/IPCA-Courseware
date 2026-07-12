import Combine
import Foundation

@MainActor
final class AvionicsBeaconTestViewModel: ObservableObject {
    let manager = AvionicsBeaconManager()

    private var cancellables: Set<AnyCancellable> = []

    init() {
        manager.objectWillChange
            .sink { [weak self] _ in
                self?.objectWillChange.send()
            }
            .store(in: &cancellables)
    }

    func startScan(scanAll: Bool) {
        manager.startScan(scanAll: scanAll)
    }

    func stopScan() {
        manager.stopScan()
    }

    func clearLog() {
        manager.clearLog()
    }

    func mark(_ marker: String) {
        manager.mark(marker)
    }

    func exportFiles() throws -> [URL] {
        try AvionicsBeaconExportService.exportFiles(entries: manager.logEntries)
    }
}
