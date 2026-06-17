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
        serverURL = UserDefaults.standard.string(forKey: Keys.serverURL) ?? "https://example.com"
        language = UserDefaults.standard.string(forKey: Keys.language) ?? "en"
    }

    var normalizedServerURL: URL? {
        URL(string: serverURL.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "/")))
    }

    private enum Keys {
        static let serverURL = "ipca.recorder.serverURL"
        static let language = "ipca.recorder.language"
    }
}
