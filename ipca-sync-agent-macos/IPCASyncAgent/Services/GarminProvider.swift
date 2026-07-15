import CryptoKit
import Foundation

protocol SyncProvider {
    var identifier: String { get }
    var displayName: String { get }

    func connectionStatus() async -> ProviderConnectionStatus
    func connect() async throws
    func verifyConnection() async throws -> ProviderVerificationResult
    func discoverNewItems() async throws -> [RemoteSyncItem]
    func download(item: RemoteSyncItem) async throws -> [DownloadedArtifact]
    func disconnect() async throws
}

final class ProviderRegistry {
    let garminProvider: GarminProvider

    init(garminProvider: GarminProvider) {
        self.garminProvider = garminProvider
    }

    var providers: [SyncProvider] { [garminProvider] }
}

enum GarminError: Error, LocalizedError {
    case loginRequired
    case humanVerification
    case unexpectedResponse(String)
    case noSources
    case downloadFailed(String)

    var errorDescription: String? {
        switch self {
        case .loginRequired: return "Garmin still needs login or MFA. Finish login in Chrome and try again."
        case .humanVerification: return "Garmin is still asking to verify you are human. Finish that step manually in Chrome."
        case .unexpectedResponse(let message): return message
        case .noSources: return "No Garmin source files were found for this entry."
        case .downloadFailed(let message): return message
        }
    }
}

final class GarminProvider: SyncProvider {
    let identifier = "garmin_flygarmin_logbook"
    let displayName = "Garmin FlyGarmin Logbook"
    private let browser: BrowserSessionController
    private let artifactStore: LocalArtifactStore
    private let cursorStore: CursorStore

    init(browser: BrowserSessionController, artifactStore: LocalArtifactStore, cursorStore: CursorStore) {
        self.browser = browser
        self.artifactStore = artifactStore
        self.cursorStore = cursorStore
    }

    func connectionStatus() async -> ProviderConnectionStatus {
        do {
            let result = try await verifyConnection()
            return result.connected ? .connected : .waitingForLogin
        } catch {
            return .authenticationRequired
        }
    }

    @MainActor
    func connect() async throws {
        try await browser.openGarminForLogin()
    }

    func verifyConnection() async throws -> ProviderVerificationResult {
        let value = try await browser.evaluate(Self.logbookExpression(cursor: nil))
        let object = try objectValue(value)
        try validateProbe(object)
        let cursor = object["cursor"]?.string
        if let cursor { cursorStore.setCursor(cursor, provider: identifier) }
        return ProviderVerificationResult(
            connected: true,
            entryCount: object["entryCount"]?.int ?? 0,
            cursor: cursor,
            topLevelKeys: object["topLevelKeys"]?.array?.compactMap(\.string) ?? [],
            firstSourceUUID: object["firstFlightDataLogUUID"]?.string
        )
    }

    func discoverNewItems() async throws -> [RemoteSyncItem] {
        let cursor = cursorStore.cursor(provider: identifier)
        let value = try await browser.evaluate(Self.logbookExpression(cursor: cursor))
        let object = try objectValue(value)
        try validateProbe(object)
        if let cursor = object["cursor"]?.string {
            cursorStore.setCursor(cursor, provider: identifier)
        }
        guard let entries = object["entries"]?.array else { return [] }
        return entries.compactMap { value in
            guard let entry = value.object,
                  let entryID = entry["uuid"]?.string ?? entry["id"]?.string,
                  let sources = entry["flightDataLogUUIDs"]?.array?.compactMap(\.string),
                  !sources.isEmpty else {
                return nil
            }
            return RemoteSyncItem(
                provider: identifier,
                entryID: entryID,
                version: entry["version"]?.string,
                aircraftRegistration: entry["aircraftRegistration"]?.string,
                generatedTrackStart: entry["generatedTrackStart"]?.string,
                generatedTrackStop: entry["generatedTrackStop"]?.string,
                sourceUUIDs: sources,
                rawEntry: entry
            )
        }
    }

    func download(item: RemoteSyncItem) async throws -> [DownloadedArtifact] {
        guard !item.sourceUUIDs.isEmpty else { throw GarminError.noSources }
        var artifacts: [DownloadedArtifact] = []
        for sourceUUID in item.sourceUUIDs {
            let value = try await browser.evaluate(Self.sourceDownloadExpression(sourceUUID: sourceUUID))
            let object = try objectValue(value)
            let artifact = try artifactStore.saveDownloadedSource(
                provider: identifier,
                entryID: item.entryID,
                sourceUUID: sourceUUID,
                response: object
            )
            artifacts.append(artifact)
        }
        return artifacts
    }

    func disconnect() async throws {
        await MainActor.run {
            browser.resetConnectionWithUserConfirmation()
        }
    }

    private func objectValue(_ value: JSONValue) throws -> [String: JSONValue] {
        guard let object = value.object else { throw GarminError.unexpectedResponse("Garmin returned an unexpected response.") }
        return object
    }

    private func validateProbe(_ object: [String: JSONValue]) throws {
        if object["humanVerification"]?.bool == true { throw GarminError.humanVerification }
        if object["loginRequired"]?.bool == true { throw GarminError.loginRequired }
        let status = object["status"]?.int ?? 0
        guard (200...299).contains(status), object["jsonOk"]?.bool == true else {
            throw GarminError.unexpectedResponse("Garmin Logbook could not be verified. HTTP \(status).")
        }
    }

    static func logbookExpression(cursor: String?) -> String {
        let suffix = cursor.map { "?version=\($0)" } ?? ""
        return """
        (async () => {
          const response = await fetch('https://fly.garmin.com/fly-garmin/api/logbook/\(suffix)', {
            method: 'GET',
            credentials: 'include',
            cache: 'no-store',
            headers: { 'Accept': 'application/json, text/plain, */*' }
          });
          const contentType = response.headers.get('content-type') || '';
          const text = await response.text();
          const sample = text.slice(0, 1200).toLowerCase();
          let json = null;
          let jsonOk = false;
          try { json = JSON.parse(text); jsonOk = true; } catch (_) {}
          const list = Array.isArray(json?.entries) ? json.entries :
            (Array.isArray(json?.logbookEntries) ? json.logbookEntries :
            (Array.isArray(json?.flights) ? json.flights :
            (Array.isArray(json?.items) ? json.items :
            (Array.isArray(json) ? json : []))));
          const normalize = (entry) => {
            const values = [];
            const seen = new Set();
            const collect = (value) => {
              if (!value) return;
              if (typeof value === 'string' && /^[a-f0-9-]{36}$/i.test(value)) {
                const lower = value.toLowerCase();
                if (!seen.has(lower)) { seen.add(lower); values.push(lower); }
                return;
              }
              if (Array.isArray(value)) { value.forEach(collect); return; }
              if (typeof value === 'object') {
                ['flightDataLogUUID','flightDataLogUuid','flightDataLogId','uuid','id'].forEach((key) => collect(value[key]));
              }
            };
            ['flightDataLogUUID','flightDataLogUuid','flightDataLogId','flightDataLogUUIDs','flightDataLogUuids','flightDataLogs','dataLogs','trackLogs'].forEach((key) => collect(entry[key]));
            return {
              uuid: entry.uuid || entry.id || entry.logbookEntryUUID || entry.logbookEntryUuid || null,
              version: entry.version || entry.versionId || entry.modifiedVersion || null,
              aircraftRegistration: entry.aircraftRegistration || entry.aircraftTailNumber || entry.aircraftIdent || entry.tailNumber || null,
              generatedTrackStart: entry.generatedTrackStart || entry.trackStart || entry.startTime || entry.departureTime || null,
              generatedTrackStop: entry.generatedTrackStop || entry.trackStop || entry.endTime || entry.arrivalTime || null,
              flightDataLogUUIDs: values,
              raw: entry
            };
          };
          const entries = list.map(normalize).filter((entry) => entry.uuid && entry.flightDataLogUUIDs.length > 0);
          return {
            status: response.status,
            ok: response.ok,
            contentType,
            jsonOk,
            topLevelKeys: json && typeof json === 'object' && !Array.isArray(json) ? Object.keys(json).slice(0, 30) : [],
            entryCount: entries.length,
            cursor: json?.version || json?.cursor || json?.nextCursor || json?.metadata?.version || null,
            firstFlightDataLogUUID: entries[0]?.flightDataLogUUIDs?.[0] || null,
            entries,
            loginRequired: sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code'),
            humanVerification: sample.includes('verify you are human') || sample.includes('human verification') || sample.includes('captcha') || sample.includes('cf-challenge')
          };
        })()
        """
    }

    static func sourceDownloadExpression(sourceUUID: String) -> String {
        let escaped = sourceUUID.replacingOccurrences(of: "'", with: "\\'")
        return """
        (async () => {
          const uuid = '\(escaped)';
          const response = await fetch('https://fly.garmin.com/fly-garmin/api/logbook/flight-data/' + encodeURIComponent(uuid), {
            method: 'GET',
            credentials: 'include',
            cache: 'no-store',
            headers: { 'Accept': 'text/csv, application/octet-stream, */*' }
          });
          const contentType = response.headers.get('content-type') || '';
          const contentDisposition = response.headers.get('content-disposition') || '';
          const buffer = await response.arrayBuffer();
          const bytes = new Uint8Array(buffer);
          let sample = '';
          if (contentType.toLowerCase().includes('html')) {
            sample = new TextDecoder().decode(bytes.slice(0, 1200)).toLowerCase();
          }
          let binary = '';
          const chunkSize = 0x8000;
          for (let i = 0; i < bytes.length; i += chunkSize) {
            binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
          }
          const filenameMatch = contentDisposition.match(/filename\\*?=(?:UTF-8'')?"?([^";]+)"?/i);
          return {
            status: response.status,
            ok: response.ok,
            contentType,
            contentDisposition,
            filename: filenameMatch ? decodeURIComponent(filenameMatch[1]) : (uuid + '.bin'),
            byteLength: bytes.byteLength,
            base64: btoa(binary),
            loginRequired: sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code'),
            humanVerification: sample.includes('verify you are human') || sample.includes('human verification') || sample.includes('captcha') || sample.includes('cf-challenge')
          };
        })()
        """
    }
}
