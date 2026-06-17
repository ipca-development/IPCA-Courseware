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

    let supportedLanguages: [(code: String, label: String)] = [
        ("en", "English")
    ]

    init() {
        serverURL = UserDefaults.standard.string(forKey: Keys.serverURL) ?? ""
        language = UserDefaults.standard.string(forKey: Keys.language) ?? "en"
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

    var serverURLHelp: String {
        "Enter the courseware site origin only. If you paste /api/recordings/upload.php, the app will strip it and use the site origin."
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
    }
}
