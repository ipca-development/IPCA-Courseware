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

    @Published var logoStyle: String {
        didSet { UserDefaults.standard.set(logoStyle, forKey: Keys.logoStyle) }
    }

    @Published private(set) var aircraft: [CockpitAircraft] = []
    @Published private(set) var aircraftError: String = ""

    let supportedLanguages: [(code: String, label: String)] = [
        ("en", "English")
    ]

    let supportedLogoStyles: [(code: String, label: String)] = [
        ("standard", "Standard"),
        ("dark", "Dark"),
        ("alternate", "Alternate")
    ]

    init() {
        serverURL = UserDefaults.standard.string(forKey: Keys.serverURL) ?? ""
        language = UserDefaults.standard.string(forKey: Keys.language) ?? "en"
        selectedAircraftID = UserDefaults.standard.integer(forKey: Keys.selectedAircraftID)
        logoStyle = UserDefaults.standard.string(forKey: Keys.logoStyle) ?? "standard"
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

    var uploadEndpointPreview: String {
        guard let url = normalizedServerURL else {
            return "Invalid server URL"
        }
        return url.appending(path: "api/recordings/upload.php").absoluteString
    }

    var selectedAircraft: CockpitAircraft? {
        aircraft.first(where: { $0.id == selectedAircraftID })
    }

    var aircraftEndpointPreview: String {
        guard let url = normalizedServerURL else {
            return "Invalid server URL"
        }
        return url.appending(path: "api/recordings/aircraft.php").absoluteString
    }

    var serverURLHelp: String {
        "Enter the courseware site origin only. If you paste /api/recordings/upload.php, the app will strip it and use the site origin."
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
        static let serverURL = "ipca.recorder.serverURL"
        static let language = "ipca.recorder.language"
        static let selectedAircraftID = "ipca.recorder.selectedAircraftID"
        static let logoStyle = "ipca.recorder.logoStyle"
    }
}
