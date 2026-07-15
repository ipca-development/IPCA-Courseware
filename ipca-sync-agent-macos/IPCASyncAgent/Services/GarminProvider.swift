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
    case timeout(String)
    case unexpectedResponse(String)
    case noSources
    case downloadFailed(String)

    var errorDescription: String? {
        switch self {
        case .loginRequired: return "Garmin still needs login or MFA. Finish login in Chrome and try again."
        case .humanVerification: return "Garmin is still asking to verify you are human. Finish that step manually in Chrome."
        case .timeout(let message): return message
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
        let value = try await withTimeout(seconds: 30, message: "Garmin Logbook verification timed out.") {
            try await self.browser.evaluate(Self.logbookExpression(cursor: nil))
        }
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
        let value = try await withTimeout(seconds: 45, message: "Garmin discovery timed out while reading the Logbook.") {
            try await self.browser.evaluate(Self.logbookExpression(cursor: cursor))
        }
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
            let value = try await withTimeout(seconds: 60, message: "Garmin source download timed out for \(sourceUUID).") {
                try await self.browser.evaluate(Self.sourceDownloadExpression(sourceUUID: sourceUUID))
            }
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

    private func withTimeout<T>(seconds: UInt64, message: String, operation: @escaping () async throws -> T) async throws -> T {
        try await withThrowingTaskGroup(of: T.self) { group in
            group.addTask {
                try await operation()
            }
            group.addTask {
                try await Task.sleep(nanoseconds: seconds * 1_000_000_000)
                throw GarminError.timeout(message)
            }
            guard let result = try await group.next() else {
                throw GarminError.timeout(message)
            }
            group.cancelAll()
            return result
        }
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
        if object["timeout"]?.bool == true {
            throw GarminError.timeout("Garmin Logbook request timed out. Check that the Logbook is open in Chrome and try Sync Now again.")
        }
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
          const controller = new AbortController();
          const timeout = setTimeout(() => controller.abort(), 30000);
          try {
          const response = await fetch('https://fly.garmin.com/fly-garmin/api/logbook/\(suffix)', {
            method: 'GET',
            credentials: 'include',
            cache: 'no-store',
            headers: { 'Accept': 'application/json, text/plain, */*' },
            signal: controller.signal
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
          } catch (error) {
            return { status: 0, ok: false, jsonOk: false, contentType: '', entryCount: 0, topLevelKeys: [], cursor: null, entries: [], timeout: error?.name === 'AbortError', errorMessage: String(error?.message || 'Garmin request failed') };
          } finally {
            clearTimeout(timeout);
          }
        })()
        """
    }

    static func sourceDownloadExpression(sourceUUID: String) -> String {
        let escaped = sourceUUID.replacingOccurrences(of: "'", with: "\\'")
        return """
        (async () => {
          const uuid = '\(escaped)';
          const candidates = [
            'https://fly.garmin.com/fly-garmin/api/logbook/flight-data/' + encodeURIComponent(uuid),
            'https://fly.garmin.com/fly-garmin/api/logbook/flight-data/' + encodeURIComponent(uuid) + '/download',
            'https://fly.garmin.com/fly-garmin/api/flight-data/' + encodeURIComponent(uuid)
          ];
          const attempts = [];
          for (const url of candidates) {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 60000);
            try {
              const response = await fetch(url, {
                method: 'GET',
                credentials: 'include',
                cache: 'no-store',
                headers: { 'Accept': 'text/csv, application/octet-stream, */*' },
                signal: controller.signal
              });
              const contentType = response.headers.get('content-type') || '';
              const contentDisposition = response.headers.get('content-disposition') || '';
              const buffer = await response.arrayBuffer();
              const bytes = new Uint8Array(buffer);
              let sample = '';
              if (contentType.toLowerCase().includes('html') || response.status === 401 || response.status === 403) {
                sample = new TextDecoder().decode(bytes.slice(0, 1200)).toLowerCase();
              }
              const loginRequired = response.status === 401 || response.status === 403 || sample.includes('password') || sample.includes('sign in') || sample.includes('multi-factor') || sample.includes('mfa') || sample.includes('verification code');
              const humanVerification = sample.includes('verify you are human') || sample.includes('human verification') || sample.includes('captcha') || sample.includes('cf-challenge');
              attempts.push({ url, status: response.status, contentType, loginRequired, humanVerification });
              if (!response.ok || loginRequired || humanVerification || bytes.byteLength === 0) {
                continue;
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
                finalUrl: response.url || url,
                contentType,
                contentDisposition,
                filename: filenameMatch ? decodeURIComponent(filenameMatch[1]) : (uuid + '.bin'),
                byteLength: bytes.byteLength,
                base64: btoa(binary),
                attempts,
                loginRequired: false,
                humanVerification: false
              };
            } catch (error) {
              attempts.push({ url, status: 0, contentType: '', timeout: error?.name === 'AbortError', errorMessage: String(error?.message || 'Garmin download failed') });
            } finally {
              clearTimeout(timeout);
            }
          }
          const last = attempts[attempts.length - 1] || { status: 0, contentType: '' };
          return {
            status: last.status || 0,
            ok: false,
            contentType: last.contentType || '',
            filename: uuid + '.bin',
            byteLength: 0,
            attempts,
            timeout: attempts.some((attempt) => attempt.timeout),
            loginRequired: attempts.some((attempt) => attempt.loginRequired),
            humanVerification: attempts.some((attempt) => attempt.humanVerification)
          };
        })()
        """
    }
}
