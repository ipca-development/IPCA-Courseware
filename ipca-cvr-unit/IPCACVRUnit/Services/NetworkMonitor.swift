import Combine
import Foundation
import Network

@MainActor
final class NetworkMonitor: ObservableObject {
    @Published private(set) var isSatisfied = false
    @Published private(set) var isWiFi = false
    @Published private(set) var isCellular = false
    @Published private(set) var isExpensive = false
    @Published private(set) var statusText = "Offline"

    private let monitor = NWPathMonitor()
    private let queue = DispatchQueue(label: "ipca.cvrUnit.network")

    func start() {
        monitor.pathUpdateHandler = { [weak self] path in
            Task { @MainActor in
                self?.update(path)
            }
        }
        monitor.start(queue: queue)
    }

    func canUpload(allowCellular: Bool) -> Bool {
        guard isSatisfied else { return false }
        if isWiFi { return true }
        if isCellular && allowCellular { return true }
        return false
    }

    private func update(_ path: NWPath) {
        isSatisfied = path.status == .satisfied
        isWiFi = path.usesInterfaceType(.wifi)
        isCellular = path.usesInterfaceType(.cellular)
        isExpensive = path.isExpensive

        if !isSatisfied {
            statusText = "Offline"
        } else if isWiFi {
            statusText = "WiFi"
        } else if isCellular {
            statusText = "5G / Cellular"
        } else {
            statusText = "Online"
        }
    }
}
