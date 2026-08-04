import Combine
import Foundation
import Security

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

    @Published var cvrUnitIdentifier: String {
        didSet { UserDefaults.standard.set(Self.normalizedUnitIdentifier(cvrUnitIdentifier), forKey: Keys.cvrUnitIdentifier) }
    }

    @Published var allowCellularUpload: Bool {
        didSet { UserDefaults.standard.set(allowCellularUpload, forKey: Keys.allowCellularUpload) }
    }

    @Published var isBeaconTriggerEnabled: Bool {
        didSet { UserDefaults.standard.set(isBeaconTriggerEnabled, forKey: Keys.isBeaconTriggerEnabled) }
    }

    @Published var expectedBeaconIdentityHex: String {
        didSet { UserDefaults.standard.set(Self.normalizedBeaconIdentity(expectedBeaconIdentityHex), forKey: Keys.expectedBeaconIdentityHex) }
    }

    @Published var adminPIN: String {
        didSet { UserDefaults.standard.set(adminPIN, forKey: Keys.adminPIN) }
    }

    @Published var postRecordingGainDB: Double {
        didSet { UserDefaults.standard.set(postRecordingGainDB, forKey: Keys.postRecordingGainDB) }
    }

    @Published var isSimulationModeEnabled: Bool {
        didSet { UserDefaults.standard.set(isSimulationModeEnabled, forKey: Keys.isSimulationModeEnabled) }
    }

    @Published var garminVaultRetentionDays: Int {
        didSet { UserDefaults.standard.set(garminVaultRetentionDays, forKey: Keys.garminVaultRetentionDays) }
    }

    @Published var garminVaultMaxMegabytes: Int {
        didSet { UserDefaults.standard.set(garminVaultMaxMegabytes, forKey: Keys.garminVaultMaxMegabytes) }
    }

    @Published private(set) var garminSDCardFolderLabel: String = ""
    @Published private(set) var garminSDCardSetupMessage: String = ""
    @Published private(set) var garminSDCardLastAccessError: String = ""

    @Published private(set) var aircraft: [CockpitAircraft] = []
    @Published private(set) var aircraftError: String = ""
    @Published private(set) var crewUsers: [CVRCrewUser] = []
    @Published private(set) var crewUsersError: String = ""
    @Published var enrollmentCode: String = ""
    @Published private(set) var deviceEnrollmentStatus: String = "Not enrolled"
    @Published private(set) var deviceEnrollmentError: String = ""

    let supportedLanguages: [(code: String, label: String)] = [
        ("en", "English")
    ]

    init() {
        serverURL = UserDefaults.standard.string(forKey: Keys.serverURL) ?? ""
        language = UserDefaults.standard.string(forKey: Keys.language) ?? "en"
        selectedAircraftID = UserDefaults.standard.integer(forKey: Keys.selectedAircraftID)
        cvrUnitIdentifier = Self.normalizedUnitIdentifier(UserDefaults.standard.string(forKey: Keys.cvrUnitIdentifier) ?? "CVR UNIT 03")
        allowCellularUpload = UserDefaults.standard.object(forKey: Keys.allowCellularUpload) as? Bool ?? true
        isBeaconTriggerEnabled = UserDefaults.standard.object(forKey: Keys.isBeaconTriggerEnabled) as? Bool ?? false
        expectedBeaconIdentityHex = Self.normalizedBeaconIdentity(UserDefaults.standard.string(forKey: Keys.expectedBeaconIdentityHex) ?? "")
        adminPIN = UserDefaults.standard.string(forKey: Keys.adminPIN) ?? "2468"
        postRecordingGainDB = UserDefaults.standard.object(forKey: Keys.postRecordingGainDB) as? Double ?? 0
        isSimulationModeEnabled = UserDefaults.standard.object(forKey: Keys.isSimulationModeEnabled) as? Bool ?? false
        garminVaultRetentionDays = UserDefaults.standard.object(forKey: Keys.garminVaultRetentionDays) as? Int ?? 30
        garminVaultMaxMegabytes = UserDefaults.standard.object(forKey: Keys.garminVaultMaxMegabytes) as? Int ?? 500
        garminSDCardFolderLabel = UserDefaults.standard.string(forKey: Keys.garminSDCardFolderLabel) ?? ""
        deviceEnrollmentStatus = Self.keychainValue(for: Keys.deviceCredential) == nil ? "Not enrolled" : "Enrolled"
    }

    var garminVaultMaxBytes: Int64 {
        Int64(max(50, garminVaultMaxMegabytes)) * 1024 * 1024
    }

    var garminSDCardBookmarkData: Data? {
        UserDefaults.standard.data(forKey: Keys.garminSDCardBookmark)
    }

    func setGarminSDCardFolder(_ url: URL) {
        garminSDCardSetupMessage = ""
        garminSDCardLastAccessError = ""

        let accessed = url.startAccessingSecurityScopedResource()
        defer {
            if accessed {
                url.stopAccessingSecurityScopedResource()
            }
        }

        do {
            // Apple requires .minimalBookmark for persistent access to directories
            // selected by UIDocumentPickerViewController on iOS.
            let bookmark = try url.bookmarkData(
                options: .minimalBookmark,
                includingResourceValuesForKeys: nil,
                relativeTo: nil
            )
            var stale = false
            let resolved = try URL(
                resolvingBookmarkData: bookmark,
                options: [],
                relativeTo: nil,
                bookmarkDataIsStale: &stale
            )
            let verifiedAccess = resolved.startAccessingSecurityScopedResource()
            defer {
                if verifiedAccess {
                    resolved.stopAccessingSecurityScopedResource()
                }
            }

            var isDirectory: ObjCBool = false
            guard FileManager.default.fileExists(atPath: resolved.path, isDirectory: &isDirectory),
                  isDirectory.boolValue else {
                throw GarminSDCardSetupError.folderNotReadable
            }

            UserDefaults.standard.set(bookmark, forKey: Keys.garminSDCardBookmark)
            garminSDCardFolderLabel = friendlyGarminSDCardLabel(for: url)
            UserDefaults.standard.set(garminSDCardFolderLabel, forKey: Keys.garminSDCardFolderLabel)
            garminSDCardSetupMessage = stale
                ? "Folder saved, but iOS marked the bookmark stale. If scans fail, re-select the folder with the card inserted."
                : "SD card folder saved and verified."
        } catch {
            UserDefaults.standard.removeObject(forKey: Keys.garminSDCardBookmark)
            garminSDCardFolderLabel = ""
            UserDefaults.standard.removeObject(forKey: Keys.garminSDCardFolderLabel)
            garminSDCardSetupMessage = (error as? GarminSDCardSetupError)?.message
                ?? "Could not save SD card folder access: \(error.localizedDescription)"
        }
    }

    func clearGarminSDCardFolder() {
        UserDefaults.standard.removeObject(forKey: Keys.garminSDCardBookmark)
        garminSDCardFolderLabel = ""
        UserDefaults.standard.removeObject(forKey: Keys.garminSDCardFolderLabel)
        garminSDCardSetupMessage = ""
        garminSDCardLastAccessError = ""
    }

    func resolvedGarminSDCardRootURL() -> URL? {
        resolveGarminSDCardBookmark()?.url
    }

    var garminSDCardBookmarkIsStale: Bool {
        resolveGarminSDCardBookmark()?.isStale ?? false
    }

    struct GarminSDCardAccess {
        let url: URL
        let stop: () -> Void

        func stopAccess() {
            stop()
        }
    }

    func beginGarminSDCardAccess() -> GarminSDCardAccess? {
        garminSDCardLastAccessError = ""
        guard let resolved = resolveGarminSDCardBookmark() else {
            garminSDCardLastAccessError = "Could not resolve the saved folder bookmark."
            return nil
        }

        let url = resolved.url
        let started = url.startAccessingSecurityScopedResource()
        var isDirectory: ObjCBool = false
        let readable = FileManager.default.fileExists(atPath: url.path, isDirectory: &isDirectory) && isDirectory.boolValue

        guard readable else {
            if started {
                url.stopAccessingSecurityScopedResource()
            }
            if resolved.isStale {
                garminSDCardLastAccessError = "The saved folder bookmark is stale. Re-select the Garmin folder with the SD card inserted."
            } else if started {
                garminSDCardLastAccessError = "The saved folder path is not available. Insert the SD card and re-select the folder in Admin."
            } else {
                garminSDCardLastAccessError = "Security-scoped access to the SD card folder was denied. Re-select the folder in Admin with the card inserted."
            }
            return nil
        }

        return GarminSDCardAccess(url: url) {
            if started {
                url.stopAccessingSecurityScopedResource()
            }
        }
    }

    private enum GarminSDCardSetupError: LocalizedError {
        case folderNotReadable

        var message: String {
            switch self {
            case .folderNotReadable:
                return "The selected folder could not be read. Keep the SD card inserted and try again."
            }
        }
    }

    private func resolveGarminSDCardBookmark() -> (url: URL, isStale: Bool)? {
        guard let bookmark = garminSDCardBookmarkData else { return nil }
        var stale = false

        if let url = try? URL(
            resolvingBookmarkData: bookmark,
            options: [],
            relativeTo: nil,
            bookmarkDataIsStale: &stale
        ) {
            return (url, stale)
        }

        stale = false
        if let url = try? URL(
            resolvingBookmarkData: bookmark,
            options: [.withoutImplicitStartAccessing],
            relativeTo: nil,
            bookmarkDataIsStale: &stale
        ) {
            return (url, stale)
        }

        return nil
    }

    private func friendlyGarminSDCardLabel(for url: URL) -> String {
        let components = url.pathComponents.filter { $0 != "/" }
        if components.count >= 2 {
            return components.suffix(2).joined(separator: "/")
        }
        return url.lastPathComponent.isEmpty ? url.path : url.lastPathComponent
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

    var deviceUUID: String {
        if let existing = UserDefaults.standard.string(forKey: Keys.deviceUUID), UUID(uuidString: existing) != nil {
            return existing.lowercased()
        }
        let created = UUID().uuidString.lowercased()
        UserDefaults.standard.set(created, forKey: Keys.deviceUUID)
        return created
    }

    var deviceCredential: String? {
        Self.keychainValue(for: Keys.deviceCredential)
    }

    func enrollDevice() async -> Bool {
        let code = enrollmentCode.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        guard !code.isEmpty else {
            deviceEnrollmentError = "Enter the enrollment code generated in IPCA.training."
            return false
        }
        guard let url = normalizedServerURL else {
            deviceEnrollmentError = "Server URL is invalid."
            return false
        }

        deviceEnrollmentStatus = "Enrolling..."
        deviceEnrollmentError = ""
        do {
            let response = try await APIClient(serverURL: url).enrollDevice(
                code: code,
                deviceUUID: deviceUUID,
                displayName: cvrUnitIdentifier
            )
            guard response.ok, let credential = response.credential, !credential.isEmpty else {
                throw APIClientError.badResponse(response.error ?? "Enrollment failed.")
            }
            try Self.setKeychainValue(credential, for: Keys.deviceCredential)
            if let aircraftID = response.aircraftID, aircraftID > 0 {
                selectedAircraftID = aircraftID
            }
            enrollmentCode = ""
            deviceEnrollmentStatus = "Enrolled"
            deviceEnrollmentError = ""
            return true
        } catch {
            deviceEnrollmentStatus = deviceCredential == nil ? "Not enrolled" : "Enrolled"
            deviceEnrollmentError = error.localizedDescription
            return false
        }
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

    func refreshCrewUsers() async {
        guard let url = normalizedServerURL else {
            crewUsersError = "Server URL is invalid."
            crewUsers = []
            return
        }

        do {
            let response = try await APIClient(serverURL: url).crewUsers()
            if response.ok {
                crewUsers = response.users.filter { !Self.isAdministrativeCrewUser($0) }
                crewUsersError = ""
            } else {
                crewUsersError = response.error ?? "Could not load crew users."
            }
        } catch {
            crewUsers = []
            crewUsersError = Self.userFacingAPIError(error, fallback: "Could not load crew users.")
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

    static func normalizedBeaconIdentity(_ rawValue: String) -> String {
        let normalized = rawValue
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
            .filter { $0.isHexDigit }
        return normalized.count == 8 ? String(normalized) : ""
    }

    static func normalizedUnitIdentifier(_ rawValue: String) -> String {
        let normalized = rawValue
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
        return normalized.isEmpty ? "CVR UNIT 03" : String(normalized.prefix(32))
    }

    private static func userFacingAPIError(_ error: Error, fallback: String) -> String {
        let message = error.localizedDescription
        if message.contains("HTTP 404") {
            return "\(fallback) Server endpoint is not available yet. Deploy api/recordings/crew_users.php and refresh again."
        }
        if message.localizedCaseInsensitiveContains("<html")
            || message.localizedCaseInsensitiveContains("<body")
            || message.localizedCaseInsensitiveContains("nginx") {
            return "\(fallback) Server returned an HTML error page instead of JSON."
        }
        return message.isEmpty ? fallback : message
    }

    private static func isAdministrativeCrewUser(_ user: CVRCrewUser) -> Bool {
        let role = user.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return role == "admin"
            || role == "administrator"
            || role == "super_admin"
            || role == "system_admin"
    }

    private enum Keys {
        static let serverURL = "ipca.cvrUnit.serverURL"
        static let language = "ipca.cvrUnit.language"
        static let selectedAircraftID = "ipca.cvrUnit.selectedAircraftID"
        static let cvrUnitIdentifier = "ipca.cvrUnit.cvrUnitIdentifier"
        static let allowCellularUpload = "ipca.cvrUnit.allowCellularUpload"
        static let isBeaconTriggerEnabled = "ipca.cvrUnit.isBeaconTriggerEnabled"
        static let expectedBeaconIdentityHex = "ipca.cvrUnit.expectedBeaconIdentityHex"
        static let adminPIN = "ipca.cvrUnit.adminPIN"
        static let postRecordingGainDB = "ipca.cvrUnit.postRecordingGainDB"
        static let isSimulationModeEnabled = "ipca.cvrUnit.isSimulationModeEnabled"
        static let garminSDCardBookmark = "ipca.cvrUnit.garminSDCardBookmark"
        static let garminSDCardFolderLabel = "ipca.cvrUnit.garminSDCardFolderLabel"
        static let garminVaultRetentionDays = "ipca.cvrUnit.garminVaultRetentionDays"
        static let garminVaultMaxMegabytes = "ipca.cvrUnit.garminVaultMaxMegabytes"
        static let deviceUUID = "ipca.cvrUnit.deviceUUID"
        static let deviceCredential = "ipca.cvrUnit.deviceCredential"
    }

    private static let keychainService = "training.ipca.cvr-unit"

    private static func keychainValue(for account: String) -> String? {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: keychainService,
            kSecAttrAccount as String: account,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data,
              let value = String(data: data, encoding: .utf8),
              !value.isEmpty else {
            return nil
        }
        return value
    }

    private static func setKeychainValue(_ value: String, for account: String) throws {
        let baseQuery: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: keychainService,
            kSecAttrAccount as String: account
        ]
        let data = Data(value.utf8)
        let updateStatus = SecItemUpdate(
            baseQuery as CFDictionary,
            [kSecValueData as String: data] as CFDictionary
        )
        if updateStatus == errSecSuccess {
            return
        }
        guard updateStatus == errSecItemNotFound else {
            throw APIClientError.badResponse("Could not update the secure device credential.")
        }
        var addQuery = baseQuery
        addQuery[kSecValueData as String] = data
        addQuery[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        guard SecItemAdd(addQuery as CFDictionary, nil) == errSecSuccess else {
            throw APIClientError.badResponse("Could not save the secure device credential.")
        }
    }
}
