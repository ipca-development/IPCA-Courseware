import Foundation

/// Phase 2D local operational identity for offline Dispatch creation.
/// Additive only. Server intake wiring is intentionally deferred.
struct CVRLocalIdentityAlias: Codable, Equatable {
    var aliasType: String
    var aliasValue: String
    var aliasVersion: String?
    var targetType: String
    var linkageMethod: String
    var confidenceState: String
}

struct CVRLocalOperationalIdentity: Codable, Equatable {
    var reservationUUID: String
    var legUUID: String
    var organizationID: Int
    var reservationType: String
    var activityDomain: String
    var organizationTimezoneIANA: String
    var originAirport: String
    var destinationAirport: String
    var plannedStartAtUTC: String?
    var plannedEndAtUTC: String?
    var aliases: [CVRLocalIdentityAlias]
    var linkageMethod: String
}

enum CVROperationalIdentityLocalError: Error, Equatable {
    case immutableConflict
    case invalidUUID
}

enum CVROperationalIdentityLocal {
    static let policyKey = "operational_identity_canonical_write_enabled"
    static let linkageOfflineCreate = "offline_create"
    static let confidenceVerified = "VERIFIED"
    static let allowedAliasTypes: Set<String> = [
        "scheduler_record_id",
        "schedule_slot_id",
        "dispatch_uuid",
        "dispatch_uuid_version",
        "workflow_flight_record_uuid",
        "workflow_archive_id",
        "recording_uid",
        "server_recording_id",
        "server_dispatch_id",
    ]

    static func normalizeUUID(_ value: String) -> String? {
        let normalized = value.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard UUID(uuidString: normalized) != nil else { return nil }
        return normalized
    }

    static func normalizeAirport(_ value: String) -> String {
        String(value.trimmingCharacters(in: .whitespacesAndNewlines).uppercased().prefix(8))
    }

    /// Never replace a persisted non-empty airport with a blank value.
    static func preservingNonEmptyAirport(existing: String, incoming: String) -> String {
        let next = normalizeAirport(incoming)
        if !next.isEmpty { return next }
        return normalizeAirport(existing)
    }

    /// Mint a new offline identity bundle for a locally created Dispatch.
    static func createOfflineBundle(
        organizationID: Int,
        dispatchUUID: String,
        reservationType: String = "flight_training",
        activityDomain: String = "flight",
        organizationTimezoneIANA: String,
        originAirport: String,
        destinationAirport: String,
        plannedStartAtUTC: String? = nil,
        plannedEndAtUTC: String? = nil,
        schedulerRecordID: String? = nil,
        reservationUUID: String? = nil,
        legUUID: String? = nil
    ) throws -> CVRLocalOperationalIdentity {
        guard organizationID >= 1 else { throw CVROperationalIdentityLocalError.invalidUUID }
        guard activityDomain == "flight" else { throw CVROperationalIdentityLocalError.immutableConflict }
        guard let normalizedDispatch = normalizeUUID(dispatchUUID) else {
            throw CVROperationalIdentityLocalError.invalidUUID
        }
        let reservation = try requiredUUID(reservationUUID ?? UUID().uuidString.lowercased())
        let leg = try requiredUUID(legUUID ?? UUID().uuidString.lowercased())
        if reservation == leg {
            throw CVROperationalIdentityLocalError.immutableConflict
        }

        var aliases: [CVRLocalIdentityAlias] = [
            CVRLocalIdentityAlias(
                aliasType: "dispatch_uuid",
                aliasValue: normalizedDispatch,
                aliasVersion: nil,
                targetType: "leg",
                linkageMethod: linkageOfflineCreate,
                confidenceState: confidenceVerified
            )
        ]
        if let scheduler = schedulerRecordID?.trimmingCharacters(in: .whitespacesAndNewlines),
           !scheduler.isEmpty,
           let normalizedScheduler = normalizeUUID(scheduler) {
            aliases.append(
                CVRLocalIdentityAlias(
                    aliasType: "scheduler_record_id",
                    aliasValue: normalizedScheduler,
                    aliasVersion: nil,
                    targetType: "reservation",
                    linkageMethod: linkageOfflineCreate,
                    confidenceState: confidenceVerified
                )
            )
        }

        return CVRLocalOperationalIdentity(
            reservationUUID: reservation,
            legUUID: leg,
            organizationID: organizationID,
            reservationType: reservationType.lowercased(),
            activityDomain: activityDomain.lowercased(),
            organizationTimezoneIANA: organizationTimezoneIANA,
            originAirport: normalizeAirport(originAirport),
            destinationAirport: normalizeAirport(destinationAirport),
            plannedStartAtUTC: plannedStartAtUTC,
            plannedEndAtUTC: plannedEndAtUTC,
            aliases: aliases,
            linkageMethod: linkageOfflineCreate
        )
    }

    /// Identical retry reuses; material mismatch fails closed without mutation.
    static func reuseOrConflict(
        existing: CVRLocalOperationalIdentity,
        expected: CVRLocalOperationalIdentity
    ) throws -> CVRLocalOperationalIdentity {
        guard existing.organizationID == expected.organizationID,
              existing.reservationUUID == expected.reservationUUID,
              existing.legUUID == expected.legUUID,
              existing.reservationType == expected.reservationType,
              existing.activityDomain == expected.activityDomain,
              existing.activityDomain == "flight",
              existing.organizationTimezoneIANA == expected.organizationTimezoneIANA,
              normalizeAirport(existing.originAirport) == normalizeAirport(expected.originAirport),
              normalizeAirport(existing.destinationAirport) == normalizeAirport(expected.destinationAirport),
              normalizeComparableUTC(existing.plannedStartAtUTC) == normalizeComparableUTC(expected.plannedStartAtUTC),
              normalizeComparableUTC(existing.plannedEndAtUTC) == normalizeComparableUTC(expected.plannedEndAtUTC),
              existing.linkageMethod == linkageOfflineCreate
        else {
            throw CVROperationalIdentityLocalError.immutableConflict
        }
        return existing
    }

    static func appendingWorkflowFlightRecordAlias(
        to identity: CVRLocalOperationalIdentity,
        flightRecordUUID: String
    ) throws -> CVRLocalOperationalIdentity {
        guard let normalized = normalizeUUID(flightRecordUUID) else {
            throw CVROperationalIdentityLocalError.invalidUUID
        }
        if identity.aliases.contains(where: {
            $0.aliasType == "workflow_flight_record_uuid" && $0.aliasValue == normalized
        }) {
            return identity
        }
        var updated = identity
        updated.aliases.append(
            CVRLocalIdentityAlias(
                aliasType: "workflow_flight_record_uuid",
                aliasValue: normalized,
                aliasVersion: nil,
                targetType: "leg",
                linkageMethod: linkageOfflineCreate,
                confidenceState: confidenceVerified
            )
        )
        return updated
    }

    static func appendingSchedulerAlias(
        to identity: CVRLocalOperationalIdentity,
        schedulerRecordID: String
    ) throws -> CVRLocalOperationalIdentity {
        guard let normalized = normalizeUUID(schedulerRecordID) else {
            throw CVROperationalIdentityLocalError.invalidUUID
        }
        if identity.aliases.contains(where: {
            $0.aliasType == "scheduler_record_id" && $0.aliasValue == normalized
        }) {
            return identity
        }
        var updated = identity
        updated.aliases.append(
            CVRLocalIdentityAlias(
                aliasType: "scheduler_record_id",
                aliasValue: normalized,
                aliasVersion: nil,
                targetType: "reservation",
                linkageMethod: linkageOfflineCreate,
                confidenceState: confidenceVerified
            )
        )
        return updated
    }

    /// Adopt server dual-read reservation/leg UUIDs onto an existing offline identity without reminting.
    static func adoptingScheduleIdentity(
        existing: CVRLocalOperationalIdentity,
        reservationUUID: String?,
        legUUID: String?,
        originAirport: String,
        destinationAirport: String
    ) throws -> CVRLocalOperationalIdentity {
        var updated = existing
        if let reservationUUID,
           let normalized = normalizeUUID(reservationUUID) {
            if updated.reservationUUID != normalized,
               updated.aliases.contains(where: { $0.aliasType == "scheduler_record_id" }) == false {
                // Prefer server reservation when opening a scheduled leg for the first time.
                updated.reservationUUID = normalized
            } else if updated.reservationUUID != normalized {
                throw CVROperationalIdentityLocalError.immutableConflict
            }
        }
        if let legUUID,
           let normalized = normalizeUUID(legUUID) {
            if updated.legUUID != normalized {
                // Opening a different leg under the same reservation is a new Dispatch identity.
                updated.legUUID = normalized
            }
        }
        updated.originAirport = normalizeAirport(originAirport)
        updated.destinationAirport = normalizeAirport(destinationAirport)
        return updated
    }

    /// Mint one reservation and N ordered legs for Create Local Dispatch multi-leg.
    /// When `legUUIDs` is provided, those values are reused (no reminting) for draft continuity.
    static func createOfflineMultiLegBundles(
        organizationID: Int,
        reservationUUID: String? = nil,
        organizationTimezoneIANA: String,
        airports: [String],
        dispatchUUIDs: [String],
        legUUIDs: [String]? = nil
    ) throws -> (reservationUUID: String, identities: [CVRLocalOperationalIdentity]) {
        let legCount = airports.count - 1
        guard airports.count >= 2, dispatchUUIDs.count == legCount else {
            throw CVROperationalIdentityLocalError.immutableConflict
        }
        if let legUUIDs, legUUIDs.count != legCount {
            throw CVROperationalIdentityLocalError.immutableConflict
        }
        let reservation = try requiredUUID(reservationUUID ?? UUID().uuidString.lowercased())
        var identities: [CVRLocalOperationalIdentity] = []
        for index in 0..<legCount {
            let identity = try createOfflineBundle(
                organizationID: organizationID,
                dispatchUUID: dispatchUUIDs[index],
                organizationTimezoneIANA: organizationTimezoneIANA,
                originAirport: airports[index],
                destinationAirport: airports[index + 1],
                reservationUUID: reservation,
                legUUID: legUUIDs?[index] ?? UUID().uuidString.lowercased()
            )
            identities.append(identity)
        }
        return (reservation, identities)
    }

    static func payloadDictionary(from identity: CVRLocalOperationalIdentity) -> [String: Any] {
        var payload: [String: Any] = [
            "reservation_uuid": identity.reservationUUID,
            "leg_uuid": identity.legUUID,
            "organization_id": identity.organizationID,
            "reservation_type": identity.reservationType,
            "activity_domain": identity.activityDomain,
            "organization_timezone_iana": identity.organizationTimezoneIANA,
            "origin_airport": normalizeAirport(identity.originAirport),
            "destination_airport": normalizeAirport(identity.destinationAirport),
            "linkage_method": identity.linkageMethod,
            "aliases": identity.aliases.map { alias -> [String: Any] in
                var row: [String: Any] = [
                    "alias_type": alias.aliasType,
                    "alias_value": alias.aliasValue,
                    "target_type": alias.targetType,
                    "linkage_method": alias.linkageMethod,
                    "confidence_state": alias.confidenceState,
                ]
                if let version = alias.aliasVersion {
                    row["alias_version"] = version
                }
                return row
            },
        ]
        if let plannedStartAtUTC = identity.plannedStartAtUTC {
            payload["planned_start_at_utc"] = plannedStartAtUTC
        }
        if let plannedEndAtUTC = identity.plannedEndAtUTC {
            payload["planned_end_at_utc"] = plannedEndAtUTC
        }
        return payload
    }

    static func logSanitized(_ code: String, fields: [String: String]) {
        let safe = fields
            .filter { !$0.key.lowercased().contains("sql") && !$0.key.lowercased().contains("stack") }
            .map { "\($0.key)=\($0.value)" }
            .sorted()
            .joined(separator: " ")
        NSLog("cvr_operational_identity_local:%@ %@", code, safe)
    }

    private static func requiredUUID(_ value: String) throws -> String {
        guard let normalized = normalizeUUID(value) else {
            throw CVROperationalIdentityLocalError.invalidUUID
        }
        return normalized
    }

    private static func normalizeComparableUTC(_ value: String?) -> String {
        guard let value, !value.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            return ""
        }
        return value.replacingOccurrences(of: "T", with: " ").trimmingCharacters(in: .whitespacesAndNewlines)
    }
}
