import Foundation

@MainActor
final class MissionCatalogStore: ObservableObject {
    @Published private(set) var missions: [CVRMissionCatalogEntry] = []
    @Published private(set) var lastError = ""

    func loadBundledFallback() {
        guard let url = Bundle.main.url(forResource: "mission_catalogue_SPC", withExtension: "csv") else {
            lastError = "Mission catalogue is not bundled."
            return
        }

        do {
            let text = try String(contentsOf: url, encoding: .utf8)
            replaceMissions(try Self.parse(text))
            lastError = ""
        } catch {
            replaceMissions([])
            lastError = "Mission catalogue could not be loaded: \(error.localizedDescription)"
        }
    }

    func refreshFromServer(settings: SettingsStore) async {
        if missions.isEmpty {
            loadBundledFallback()
        }
        guard let url = settings.normalizedServerURL else {
            if missions.isEmpty {
                lastError = "Server URL is invalid and no bundled mission catalogue is available."
            }
            return
        }

        do {
            let response = try await APIClient(serverURL: url).missions()
            if response.ok {
                if !response.missions.isEmpty {
                    replaceMissions(response.missions)
                    lastError = ""
                } else if missions.isEmpty {
                    lastError = "Server mission catalogue is empty."
                }
            } else if missions.isEmpty {
                lastError = response.error ?? "Could not load server mission catalogue."
            }
        } catch {
            if missions.isEmpty {
                lastError = "Could not load server mission catalogue: \(error.localizedDescription)"
            }
        }
    }

    /// Avoid publishing identical catalogues — parent Menu/List blink when `missions` is reassigned.
    private func replaceMissions(_ next: [CVRMissionCatalogEntry]) {
        let sorted = Self.chronological(next)
        if missions == sorted { return }
        missions = sorted
    }

    func mission(code: String) -> CVRMissionCatalogEntry? {
        let normalized = code.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        return missions.first { $0.missionCode.uppercased() == normalized }
    }

    /// Missions valid for aircraft Flight Training / approved flight activities on this device.
    var flightMissions: [CVRMissionCatalogEntry] {
        Self.chronological(
            missions.filter {
                CVRLocalDispatchDraft.isAircraftFlightMission(
                    code: $0.missionCode,
                    description: $0.missionDescription
                )
            }
        )
    }

    func flightMissionPickerTitle(_ entry: CVRMissionCatalogEntry) -> String {
        CVRLocalDispatchDraft.missionPickerTitle(
            code: entry.missionCode,
            description: entry.missionDescription
        )
    }

    /// Curriculum order: Program → Stage → Phase → Scenario (numeric), not string code order.
    /// Prevents `1-1-11` / `1-1-12` appearing before `1-1-4`.
    static func chronological(_ entries: [CVRMissionCatalogEntry]) -> [CVRMissionCatalogEntry] {
        entries.sorted { lhs, rhs in
            if lhs.program != rhs.program { return lhs.program < rhs.program }
            if lhs.stage != rhs.stage { return lhs.stage < rhs.stage }
            if lhs.phase != rhs.phase { return lhs.phase < rhs.phase }
            if lhs.scenario != rhs.scenario { return lhs.scenario < rhs.scenario }
            return lhs.missionCode.localizedStandardCompare(rhs.missionCode) == .orderedAscending
        }
    }

    private static func parse(_ text: String) throws -> [CVRMissionCatalogEntry] {
        let rows = csvRows(text)
        guard rows.count >= 2,
              let headerIndex = rows.firstIndex(where: { row in
                  row.first?
                      .trimmingCharacters(in: .whitespacesAndNewlines)
                      .caseInsensitiveCompare("Program") == .orderedSame
              }) else {
            return []
        }
        let dataRows = rows.dropFirst(headerIndex + 1)

        return dataRows.compactMap { row in
            guard row.count >= 6,
                  let program = Int(row[0]),
                  let stage = Int(row[1]),
                  let phase = Int(row[2]),
                  let scenario = Int(row[3]) else {
                return nil
            }
            let code = row[4].trimmingCharacters(in: .whitespacesAndNewlines)
            guard !code.isEmpty else { return nil }
            return CVRMissionCatalogEntry(
                program: program,
                stage: stage,
                phase: phase,
                scenario: scenario,
                missionCode: code,
                missionDescription: row[5].trimmingCharacters(in: .whitespacesAndNewlines)
            )
        }
    }

    private static func csvRows(_ text: String) -> [[String]] {
        var rows: [[String]] = []
        var row: [String] = []
        var field = ""
        var insideQuotes = false
        var iterator = text.makeIterator()

        while let character = iterator.next() {
            if character == "\"" {
                if insideQuotes, let next = iterator.next() {
                    if next == "\"" {
                        field.append("\"")
                    } else {
                        insideQuotes.toggle()
                        if next == "," {
                            row.append(field)
                            field = ""
                        } else if next == "\n" {
                            row.append(field)
                            rows.append(row)
                            row = []
                            field = ""
                        } else if next != "\r" {
                            field.append(next)
                        }
                    }
                } else {
                    insideQuotes.toggle()
                }
            } else if character == "," && !insideQuotes {
                row.append(field)
                field = ""
            } else if character == "\n" && !insideQuotes {
                row.append(field)
                rows.append(row)
                row = []
                field = ""
            } else if character != "\r" {
                field.append(character)
            }
        }

        if !field.isEmpty || !row.isEmpty {
            row.append(field)
            rows.append(row)
        }

        return rows
    }
}
