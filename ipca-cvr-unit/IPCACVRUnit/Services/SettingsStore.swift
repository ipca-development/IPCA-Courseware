import Combine
import Foundation
import Security

/// Balances `startAccessingSecurityScopedResource()` / `stopAccessingSecurityScopedResource()`
/// for the Garmin SD card folder bookmark. Callers must call `stop()` once finished;
/// `deinit` stops automatically as a safety net so access is never leaked.
final class GarminSDCardAccessToken {
    let url: URL
    private var didStartAccess: Bool
    private var isStopped = false

    fileprivate init(url: URL, didStartAccess: Bool) {
        self.url = url
        self.didStartAccess = didStartAccess
    }

    func stop() {
        guard !isStopped else { return }
        isStopped = true
        if didStartAccess {
            url.stopAccessingSecurityScopedResource()
        }
    }

    deinit {
        stop()
    }
}

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

    /// Local mirror of server policy `operational_identity_canonical_write_enabled`.
    /// Default off. Offline create must not depend on network to read this flag.
    @Published var operationalIdentityCanonicalWriteEnabled: Bool {
        didSet { UserDefaults.standard.set(operationalIdentityCanonicalWriteEnabled, forKey: Keys.operationalIdentityCanonicalWriteEnabled) }
    }
    /// Offline-capable mirror of the Stage 1 rollout. Default off until an
    /// authenticated schedule refresh confirms this exact device is allowlisted.
    @Published var operationalSessionModelEnabled: Bool {
        didSet { UserDefaults.standard.set(operationalSessionModelEnabled, forKey: Keys.operationalSessionModelEnabled) }
    }

    /// Garmin SD Card folder bookmark metadata. The bookmark `Data` itself lives only in
    /// UserDefaults (see `Keys.garminSDCardBookmark`) — never published directly.
    @Published private(set) var garminSDCardFolderLabel: String = ""
    @Published private(set) var garminSDCardVolumeName: String = ""
    @Published private(set) var garminSDCardConfiguredAt: Date?
    @Published private(set) var garminSDCardAssociatedTail: String = ""
    @Published private(set) var bookmarkIsStale: Bool = false

    @Published private(set) var aircraft: [CockpitAircraft] = []
    @Published private(set) var aircraftError: String = ""
    @Published private(set) var crewUsers: [CVRCrewUser] = []
    @Published private(set) var crewUsersError: String = ""
    @Published var enrollmentCode: String = ""
    @Published private(set) var deviceEnrollmentStatus: String = "Not enrolled"
    @Published private(set) var deviceEnrollmentError: String = ""
    /// Live tank quantity from Master Logbook (closure or admin/CVR uplift).
    @Published private(set) var serverFuelState: AircraftFuelStateResponse?

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
        operationalIdentityCanonicalWriteEnabled =
            UserDefaults.standard.object(forKey: Keys.operationalIdentityCanonicalWriteEnabled) as? Bool ?? false
        operationalSessionModelEnabled =
            UserDefaults.standard.object(forKey: Keys.operationalSessionModelEnabled) as? Bool ?? false
        deviceEnrollmentStatus = Self.keychainValue(for: Keys.deviceCredential) == nil ? "Not enrolled" : "Enrolled"
        garminSDCardFolderLabel = UserDefaults.standard.string(forKey: Keys.garminSDCardFolderLabel) ?? ""
        garminSDCardVolumeName = UserDefaults.standard.string(forKey: Keys.garminSDCardVolumeName) ?? ""
        garminSDCardConfiguredAt = UserDefaults.standard.object(forKey: Keys.garminSDCardConfiguredAt) as? Date
        garminSDCardAssociatedTail = UserDefaults.standard.string(forKey: Keys.garminSDCardAssociatedTail) ?? ""
    }

    var garminVaultMaxBytes: Int64 {
        Int64(max(50, garminVaultMaxMegabytes)) * 1024 * 1024
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
        await refreshFuelState()
    }

    /// Pull authoritative fuel quantity (admin uplift or latest closure) for the enrolled aircraft.
    @discardableResult
    func refreshFuelState() async -> AircraftFuelStateResponse? {
        guard let url = normalizedServerURL,
              let credential = deviceCredential,
              !credential.isEmpty else {
            return serverFuelState
        }
        do {
            let response = try await APIClient(serverURL: url).deviceStatus(credential: credential)
            if response.ok {
                serverFuelState = response.fuelState
            }
        } catch {
            // Keep last known fuel state when offline.
        }
        return serverFuelState
    }

    /// Formatted USG string for Dispatch fuel onboard, if the server has a quantity.
    var serverFuelOnboardText: String? {
        guard let qty = serverFuelState?.quantityUSG else { return nil }
        return String(format: "%.1f", qty)
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

    // MARK: - Garmin SD Card Folder Bookmark

    var hasGarminSDCardFolderConfigured: Bool {
        UserDefaults.standard.data(forKey: Keys.garminSDCardBookmark) != nil
    }

    var garminSDCardFolderDisplayInfo: GarminExternalFolderDisplayInfo? {
        guard hasGarminSDCardFolderConfigured else { return nil }
        return GarminExternalFolderDisplayInfo(
            folderName: garminSDCardFolderLabel,
            volumeName: garminSDCardVolumeName,
            configuredAt: garminSDCardConfiguredAt,
            associatedTail: garminSDCardAssociatedTail
        )
    }

    /// Verifies the selected folder (resolve, access, is-directory, one enumeration pass)
    /// before persisting. The previously configured bookmark is left untouched until the
    /// new selection is fully verified, so a bad pick never destroys a working configuration.
    @discardableResult
    func setGarminSDCardFolder(_ url: URL) -> Result<GarminExternalFolderDisplayInfo, GarminExternalFolderAccessError> {
        let accessedNow = url.startAccessingSecurityScopedResource()
        defer {
            if accessedNow {
                url.stopAccessingSecurityScopedResource()
            }
        }

        var isDirectoryFlag: ObjCBool = false
        guard FileManager.default.fileExists(atPath: url.path, isDirectory: &isDirectoryFlag), isDirectoryFlag.boolValue else {
            return .failure(.verificationFailed("The selected item is not a folder."))
        }

        let bookmarkData: Data
        do {
            bookmarkData = try url.bookmarkData(options: .minimalBookmark, includingResourceValuesForKeys: nil, relativeTo: nil)
        } catch {
            return .failure(.verificationFailed("Could not create a persistent bookmark: \(error.localizedDescription)"))
        }

        // Round-trip through bookmark resolution to verify future access will work,
        // and enumerate once to confirm the folder is actually readable.
        var isStale = false
        let resolvedURL: URL
        do {
            resolvedURL = try URL(resolvingBookmarkData: bookmarkData, options: [], relativeTo: nil, bookmarkDataIsStale: &isStale)
        } catch {
            return .failure(.verificationFailed("Could not verify access to the selected folder."))
        }
        let resolvedAccessed = resolvedURL.startAccessingSecurityScopedResource()
        defer {
            if resolvedAccessed {
                resolvedURL.stopAccessingSecurityScopedResource()
            }
        }
        guard (try? FileManager.default.contentsOfDirectory(
            at: resolvedURL,
            includingPropertiesForKeys: nil,
            options: [.skipsHiddenFiles]
        )) != nil else {
            return .failure(.verificationFailed("Could not read the contents of the selected folder."))
        }

        let volumeName = (try? resolvedURL.resourceValues(forKeys: [.volumeNameKey]))?.volumeName
            ?? resolvedURL.deletingLastPathComponent().lastPathComponent
        let label = url.lastPathComponent
        let tail = selectedAircraft?.registration.trimmingCharacters(in: .whitespacesAndNewlines).uppercased() ?? ""
        let now = Date()

        UserDefaults.standard.set(bookmarkData, forKey: Keys.garminSDCardBookmark)
        UserDefaults.standard.set(label, forKey: Keys.garminSDCardFolderLabel)
        UserDefaults.standard.set(volumeName, forKey: Keys.garminSDCardVolumeName)
        UserDefaults.standard.set(now, forKey: Keys.garminSDCardConfiguredAt)
        UserDefaults.standard.set(tail, forKey: Keys.garminSDCardAssociatedTail)

        garminSDCardFolderLabel = label
        garminSDCardVolumeName = volumeName
        garminSDCardConfiguredAt = now
        garminSDCardAssociatedTail = tail
        bookmarkIsStale = false

        return .success(GarminExternalFolderDisplayInfo(
            folderName: label,
            volumeName: volumeName,
            configuredAt: now,
            associatedTail: tail
        ))
    }

    func clearGarminSDCardFolder() {
        UserDefaults.standard.removeObject(forKey: Keys.garminSDCardBookmark)
        UserDefaults.standard.removeObject(forKey: Keys.garminSDCardFolderLabel)
        UserDefaults.standard.removeObject(forKey: Keys.garminSDCardVolumeName)
        UserDefaults.standard.removeObject(forKey: Keys.garminSDCardConfiguredAt)
        UserDefaults.standard.removeObject(forKey: Keys.garminSDCardAssociatedTail)
        garminSDCardFolderLabel = ""
        garminSDCardVolumeName = ""
        garminSDCardConfiguredAt = nil
        garminSDCardAssociatedTail = ""
        bookmarkIsStale = false
    }

    /// Resolves the persisted bookmark and starts security-scoped access. Callers MUST call
    /// `stop()` on the returned token (via `defer`) to balance start/stop access.
    func beginGarminSDCardAccess() throws -> GarminSDCardAccessToken {
        guard let bookmarkData = UserDefaults.standard.data(forKey: Keys.garminSDCardBookmark) else {
            throw GarminExternalFolderAccessError.notConfigured
        }
        var isStale = false
        let resolvedURL: URL
        do {
            resolvedURL = try URL(resolvingBookmarkData: bookmarkData, options: [], relativeTo: nil, bookmarkDataIsStale: &isStale)
        } catch {
            bookmarkIsStale = true
            throw GarminExternalFolderAccessError.accessNeedsRestoration
        }
        bookmarkIsStale = isStale
        guard resolvedURL.startAccessingSecurityScopedResource() else {
            throw GarminExternalFolderAccessError.accessNeedsRestoration
        }
        return GarminSDCardAccessToken(url: resolvedURL, didStartAccess: true)
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
        static let garminVaultRetentionDays = "ipca.cvrUnit.garminVaultRetentionDays"
        static let garminVaultMaxMegabytes = "ipca.cvrUnit.garminVaultMaxMegabytes"
        static let operationalIdentityCanonicalWriteEnabled = "ipca.cvrUnit.operationalIdentityCanonicalWriteEnabled"
        static let operationalSessionModelEnabled = "ipca.cvrUnit.operationalSessionModelEnabled"
        static let deviceUUID = "ipca.cvrUnit.deviceUUID"
        static let deviceCredential = "ipca.cvrUnit.deviceCredential"
        static let garminSDCardBookmark = "ipca.cvrUnit.garminSDCardBookmark"
        static let garminSDCardFolderLabel = "ipca.cvrUnit.garminSDCardFolderLabel"
        static let garminSDCardVolumeName = "ipca.cvrUnit.garminSDCardVolumeName"
        static let garminSDCardConfiguredAt = "ipca.cvrUnit.garminSDCardConfiguredAt"
        static let garminSDCardAssociatedTail = "ipca.cvrUnit.garminSDCardAssociatedTail"
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
