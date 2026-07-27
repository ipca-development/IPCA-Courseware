import Foundation

@MainActor
final class MissionCatalogStore: ObservableObject {
    @Published private(set) var missions: [CVRMissionCatalogEntry] = []
    @Published private(set) var lastError = ""

    func load() {
        guard let url = Bundle.main.url(forResource: "mission_catalogue_SPC", withExtension: "csv") else {
            lastError = "Mission catalogue is not bundled."
            return
        }

        do {
            let text = try String(contentsOf: url, encoding: .utf8)
            missions = try Self.parse(text)
            lastError = ""
        } catch {
            missions = []
            lastError = "Mission catalogue could not be loaded: \(error.localizedDescription)"
        }
    }

    func mission(code: String) -> CVRMissionCatalogEntry? {
        let normalized = code.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        return missions.first { $0.missionCode.uppercased() == normalized }
    }

    private static func parse(_ text: String) throws -> [CVRMissionCatalogEntry] {
        let rows = csvRows(text)
        guard rows.count >= 2 else { return [] }
        let dataRows = rows.dropFirst().drop { row in
            row.first?.caseInsensitiveCompare("Program") != .orderedSame
        }.dropFirst()

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
