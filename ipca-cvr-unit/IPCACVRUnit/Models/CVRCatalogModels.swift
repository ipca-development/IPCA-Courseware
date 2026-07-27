import Foundation

struct CVRCrewUser: Identifiable, Codable, Equatable {
    var id: Int
    var name: String
    var email: String
    var role: String

    var displayName: String {
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmed.isEmpty ? email : trimmed
    }
}

struct CVRMissionCatalogEntry: Identifiable, Codable, Equatable {
    var program: Int
    var stage: Int
    var phase: Int
    var scenario: Int
    var missionCode: String
    var missionDescription: String

    var id: String { missionCode }

    var displayTitle: String {
        "\(missionCode) - \(missionDescription)"
    }
}

struct CrewUsersResponse: Codable {
    var ok: Bool
    var users: [CVRCrewUser]
    var error: String?
}

struct MissionCatalogResponse: Codable {
    var ok: Bool
    var missions: [CVRMissionCatalogEntry]
    var error: String?
}
