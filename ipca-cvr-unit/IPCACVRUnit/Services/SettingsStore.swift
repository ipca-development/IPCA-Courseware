import Combine
import Foundation

@MainActor
final class SettingsStore: ObservableObject {
    @Published var serverURL: String {
        didSet { UserDefaults.standard.set(serverURL, forKey: Keys.serverURL) }
    }

    @Published var language: String {
        didSet { UserDefaults.standard.set(language, forKey: Keys.language) }
    }

    @Published var selectedAircraftID: Int {
        didSet { UserDefaults.standard.set(selectedAircraftID, forKey: Keys.selectedAircraftID) }
    }

    @Published var allowCellularUpload: Bool {
        didSet { UserDefaults.standard.set(allowCellularUpload, forKey: Keys.allowCellularUpload) }
    }

    @Published var isBeaconTriggerEnabled: Bool {
        didSet { UserDefaults.standard.set(isBeaconTriggerEnabled, forKey: Keys.isBeaconTriggerEnabled) }
    }

    @Published var adminPIN: String {
        didSet { UserDefaults.standard.set(adminPIN, forKey: Keys.adminPIN) }
    }

    @Published private(set) var aircraft: [CockpitAircraft] = []
    @Published private(set) var aircraftError: String = ""

    let supportedLanguages: [(code: String, label: String)] = [
        ("en", "English")
    ]

    init() {
        serverURL = UserDefaults.standard.string(forKey: Keys.serverURL) ?? ""
        language = UserDefaults.standard.string(forKey: Keys.language) ?? "en"
        selectedAircraftID = UserDefaults.standard.integer(forKey: Keys.selectedAircraftID)
        allowCellularUpload = UserDefaults.standard.object(forKey: Keys.allowCellularUpload) as? Bool ?? true
        isBeaconTriggerEnabled = UserDefaults.standard.object(forKey: Keys.isBeaconTriggerEnabled) as? Bool ?? false
        adminPIN = UserDefaults.standard.string(forKey: Keys.adminPIN) ?? "2468"
    }

    var normalizedServerURL: URL? {
        Self.normalizedOrigin(from: serverURL)
    }

    var isServerURLConfigured: Bool {
        guard let url = normalizedServerURL, let host = url.host?.lowercased() else {
            return false
        }
        return host != "example.com" && host != "courseware.example.com"
    }

    var selectedAircraft: CockpitAircraft? {
        aircraft.first(where: { $0.id == selectedAircraftID })
    }

    func refreshAircraft() async {
        guard let url = normalizedServerURL else {
            aircraftError = "Server URL is invalid."
            aircraft = []
            return
        }

        do {
            let response = try await APIClient(serverURL: url).aircraft()
            if response.ok {
                aircraft = response.aircraft
                aircraftError = ""
                if selectedAircraftID != 0 && !aircraft.contains(where: { $0.id == selectedAircraftID }) {
                    selectedAircraftID = 0
                }
            } else {
                aircraftError = response.error ?? "Could not load aircraft."
            }
        } catch {
            aircraft = []
            aircraftError = error.localizedDescription
        }
    }

    private static func normalizedOrigin(from rawValue: String) -> URL? {
        var raw = rawValue.trimmingCharacters(in: .whitespacesAndNewlines)
        raw = raw.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard !raw.isEmpty else { return nil }

        if !raw.contains("://") {
            raw = "https://" + raw
        }

        guard var components = URLComponents(string: raw),
              let scheme = components.scheme,
              let host = components.host,
              scheme == "http" || scheme == "https",
              !host.isEmpty
        else {
            return nil
        }

        components.path = ""
        components.query = nil
        components.fragment = nil
        return components.url
    }

    private enum Keys {
        static let serverURL = "ipca.cvrUnit.serverURL"
        static let language = "ipca.cvrUnit.language"
        static let selectedAircraftID = "ipca.cvrUnit.selectedAircraftID"
        static let allowCellularUpload = "ipca.cvrUnit.allowCellularUpload"
        static let isBeaconTriggerEnabled = "ipca.cvrUnit.isBeaconTriggerEnabled"
        static let adminPIN = "ipca.cvrUnit.adminPIN"
    }
}
