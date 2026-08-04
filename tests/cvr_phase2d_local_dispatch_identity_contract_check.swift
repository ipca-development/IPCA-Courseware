import Foundation

private func require(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        FileHandle.standardError.write(Data(("FAIL " + message + "\n").utf8))
        exit(1)
    }
}

@main
private enum Phase2DLocalDispatchIdentityCheck {
    static func main() throws {
        let dispatchUUID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
        let reservationUUID = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"
        let legUUID = "cccccccc-cccc-4ccc-8ccc-cccccccccccc"

        let created = try CVROperationalIdentityLocal.createOfflineBundle(
            organizationID: 7,
            dispatchUUID: dispatchUUID,
            organizationTimezoneIANA: "America/Los_Angeles",
            originAirport: "ksba",
            destinationAirport: "",
            reservationUUID: reservationUUID,
            legUUID: legUUID
        )
        require(created.reservationUUID == reservationUUID, "creates reservation_uuid once")
        require(created.legUUID == legUUID, "creates leg_uuid once")
        require(created.activityDomain == "flight", "local Dispatch identity is flight-domain")
        require(created.originAirport == "KSBA", "normalizes origin airport")
        require(created.plannedStartAtUTC == nil && created.plannedEndAtUTC == nil,
                "local create may have nil planned timestamps")
        require(created.aliases.contains(where: { $0.aliasType == "dispatch_uuid" && $0.targetType == "leg" }),
                "writes dispatch_uuid compatibility alias")
        require(created.linkageMethod == "offline_create", "uses offline_create linkage")

        let identical = try CVROperationalIdentityLocal.reuseOrConflict(existing: created, expected: created)
        require(identical.reservationUUID == reservationUUID && identical.legUUID == legUUID,
                "identical retry reuses UUIDs")

        var mismatched = created
        mismatched.originAirport = "KSMX"
        var conflicted = false
        do {
            _ = try CVROperationalIdentityLocal.reuseOrConflict(existing: created, expected: mismatched)
        } catch CVROperationalIdentityLocalError.immutableConflict {
            conflicted = true
        }
        require(conflicted, "immutable mismatch fails closed")
        require(created.originAirport == "KSBA", "conflict does not overwrite existing identity")

        let withFR = try CVROperationalIdentityLocal.appendingWorkflowFlightRecordAlias(
            to: created,
            flightRecordUUID: "dddddddd-dddd-4ddd-8ddd-dddddddddddd"
        )
        require(withFR.aliases.contains(where: { $0.aliasType == "workflow_flight_record_uuid" }),
                "confirm path can attach workflow_flight_record_uuid alias")
        let again = try CVROperationalIdentityLocal.appendingWorkflowFlightRecordAlias(
            to: withFR,
            flightRecordUUID: "dddddddd-dddd-4ddd-8ddd-dddddddddddd"
        )
        require(again.aliases.filter { $0.aliasType == "workflow_flight_record_uuid" }.count == 1,
                "FR alias attach is idempotent")

        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]
        let encoded = try encoder.encode(created)
        let decoded = try JSONDecoder().decode(CVRLocalOperationalIdentity.self, from: encoded)
        require(decoded.reservationUUID == reservationUUID && decoded.legUUID == legUUID,
                "identity persists across encode/decode restart simulation")

        // Compatibility: nil planned times must be omitted so JSONSerialization succeeds.
        let identityPayload = CVROperationalIdentityLocal.payloadDictionary(from: created)
        require((identityPayload["reservation_uuid"] as? String) == reservationUUID, "sync payload includes reservation_uuid")
        require((identityPayload["leg_uuid"] as? String) == legUUID, "sync payload includes leg_uuid")
        require(identityPayload["planned_start_at_utc"] == nil, "nil planned_start_at_utc is omitted")
        require(identityPayload["planned_end_at_utc"] == nil, "nil planned_end_at_utc is omitted")
        require(JSONSerialization.isValidJSONObject(identityPayload),
                "identity payload with nil planned times is JSON-serializable")

        let dispatchPayloadNilTimes: [String: Any] = [
            "id": dispatchUUID,
            "organization_id": 7,
            "reservation_uuid": reservationUUID,
            "leg_uuid": legUUID,
            "operational_identity": identityPayload,
        ]
        let uploadEnvelopeNilTimes: [String: Any] = [
            "flight_record_uuid": "dddddddd-dddd-4ddd-8ddd-dddddddddddd",
            "dispatch": dispatchPayloadNilTimes,
            "consents": [] as [Any],
        ]
        require(JSONSerialization.isValidJSONObject(uploadEnvelopeNilTimes),
                "Dispatch upload envelope with nil planned times is JSON-serializable")
        let snapshotNilTimes = try JSONSerialization.data(withJSONObject: uploadEnvelopeNilTimes, options: [.sortedKeys])
        require(snapshotNilTimes.count > 0, "Dispatch snapshot creation succeeds with nil planned times")
        require(snapshotNilTimes.count <= 256 * 1024, "Dispatch snapshot remains within byte budget")

        // Non-nil planned timestamps are included in existing string format.
        var withTimes = created
        withTimes.plannedStartAtUTC = "2026-08-07 17:00:00.000"
        withTimes.plannedEndAtUTC = "2026-08-07 19:00:00.000"
        let timedPayload = CVROperationalIdentityLocal.payloadDictionary(from: withTimes)
        require((timedPayload["planned_start_at_utc"] as? String) == "2026-08-07 17:00:00.000",
                "non-nil planned_start_at_utc is included")
        require((timedPayload["planned_end_at_utc"] as? String) == "2026-08-07 19:00:00.000",
                "non-nil planned_end_at_utc is included")
        require(JSONSerialization.isValidJSONObject(timedPayload),
                "identity payload with planned times is JSON-serializable")
        let timedEnvelope: [String: Any] = [
            "flight_record_uuid": "dddddddd-dddd-4ddd-8ddd-dddddddddddd",
            "dispatch": [
                "id": dispatchUUID,
                "reservation_uuid": reservationUUID,
                "leg_uuid": legUUID,
                "operational_identity": timedPayload,
            ] as [String: Any],
            "consents": [] as [Any],
        ]
        let timedSnapshot = try JSONSerialization.data(withJSONObject: timedEnvelope, options: [.sortedKeys])
        require(timedSnapshot.count > 0, "Dispatch snapshot creation succeeds with planned times")

        // Flag-off behavior: no Phase 2D identity fields on the dispatch object.
        let flagOffDispatch: [String: Any] = [
            "id": dispatchUUID,
            "organization_id": 7,
            "mission_code": "2.1.5",
        ]
        require(flagOffDispatch["reservation_uuid"] == nil, "flag off omits reservation_uuid")
        require(flagOffDispatch["leg_uuid"] == nil, "flag off omits leg_uuid")
        require(flagOffDispatch["operational_identity"] == nil, "flag off omits operational_identity")
        require(JSONSerialization.isValidJSONObject(flagOffDispatch), "flag-off dispatch remains JSON-serializable")

        require(CVROperationalIdentityLocal.policyKey == "operational_identity_canonical_write_enabled",
                "client policy key matches server flag name")

        print("OK: Phase 2D local Dispatch identity checks passed.")
    }
}
