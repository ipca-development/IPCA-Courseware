import Foundation

private func require(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        FileHandle.standardError.write(Data(("FAIL " + message + "\n").utf8))
        exit(1)
    }
}

@main
private enum LocalDispatchUIContractCheck {
    static func main() throws {
        // 1–3 Mission filter + no free-form / comma-route concepts in draft model.
        require(
            CVRLocalDispatchDraft.isAircraftFlightMission(
                code: "1-1-4",
                description: "Your First Flight - (1.5h DUAL)"
            ),
            "includes DUAL flight mission"
        )
        require(
            CVRLocalDispatchDraft.isAircraftFlightMission(
                code: "2-3-1",
                description: "Cross Country - (3.0h PIC)"
            ),
            "includes PIC flight mission"
        )
        require(
            !CVRLocalDispatchDraft.isAircraftFlightMission(
                code: "1-1-1",
                description: "Training Habits - (1.0h LB)"
            ),
            "excludes briefing/LB"
        )
        require(
            !CVRLocalDispatchDraft.isAircraftFlightMission(
                code: "1-1-2",
                description: "Familiarization - (1.0h SE/FSTD)"
            ),
            "excludes simulator/FSTD"
        )
        require(
            !CVRLocalDispatchDraft.isAircraftFlightMission(
                code: "T-1",
                description: "Theory lesson — Aerodynamics"
            ),
            "excludes theory"
        )
        require(
            !CVRLocalDispatchDraft.isAircraftFlightMission(
                code: "M-1",
                description: "Staff meeting"
            ),
            "excludes meetings"
        )
        require(
            CVRLocalDispatchDraft.missionPickerTitle(
                code: "PPL-4.2",
                description: "Navigation Training"
            ) == "PPL-4.2 — Navigation Training",
            "mission displays code and name clearly"
        )

        // 4 Initial form contains exactly one leg.
        var draft = CVRLocalDispatchDraft.fresh(homeAirport: "ktrm")
        require(draft.legs.count == 1, "initial form contains exactly one leg")
        require(draft.legs[0].departureAirport == "KTRM", "home airport seeds DEP AD")
        require(draft.legs[0].arrivalAirport.isEmpty, "initial ARR AD is blank")
        let leg1UUID = draft.legs[0].legUUID
        let reservationUUID = draft.reservationUUID

        // 12 Single-leg needs no special mode — validation works with one row.
        draft.selectedMissionCode = "1-1-4"
        draft.setArrival(legIndex: 0, airport: "KTRM")
        require(draft.canSubmit, "13 local flight KTRM → KTRM is valid")
        require(draft.airportChain == ["KTRM", "KTRM"], "single-leg airport chain")

        // 5–6 ADD LEG inherits previous arrival.
        draft.setArrival(legIndex: 0, airport: "KPSP")
        draft.addLeg()
        require(draft.legs.count == 2, "ADD LEG creates a second leg")
        require(draft.legs[1].departureAirport == "KPSP", "new leg departure inherits previous arrival")
        let leg2UUID = draft.legs[1].legUUID
        require(leg2UUID != leg1UUID, "new leg mints a distinct leg_uuid")

        // 7 Editing previous arrival updates next departure.
        draft.setArrival(legIndex: 0, airport: "KUDD")
        require(draft.legs[1].departureAirport == "KUDD", "editing previous arrival updates next departure")
        draft.setArrival(legIndex: 0, airport: "KPSP")
        require(draft.legs[1].departureAirport == "KPSP", "continuity restored after edit")

        // Multi-leg route build.
        draft.setArrival(legIndex: 1, airport: "KBUR")
        draft.addLeg()
        let leg3UUID = draft.legs[2].legUUID
        draft.setArrival(legIndex: 2, airport: "KTRM")
        require(draft.airportChain == ["KTRM", "KPSP", "KBUR", "KTRM"],
                "14 multi-leg KTRM → KPSP → KBUR → KTRM is valid")
        require(draft.canSubmit, "complete multi-leg route can submit")
        require(draft.legs.map(\.legUUID) == [leg1UUID, leg2UUID, leg3UUID],
                "leg UUIDs stable across edits")

        // 8–11 Swipe ERASE removes correct leg, reconnects, renumbers, keeps UUIDs.
        require(draft.eraseLeg(id: leg2UUID), "ERASE removes intermediate leg")
        require(draft.legs.count == 2, "two legs remain after erase")
        require(draft.legs[0].legUUID == leg1UUID, "unaffected leg 1 UUID unchanged")
        require(draft.legs[1].legUUID == leg3UUID, "unaffected former leg 3 UUID unchanged")
        require(draft.legs[0].departureAirport == "KTRM" && draft.legs[0].arrivalAirport == "KPSP",
                "leg 1 unchanged after intermediate erase")
        require(draft.legs[1].departureAirport == "KPSP" && draft.legs[1].arrivalAirport == "KTRM",
                "9 intermediate deletion reconnects route continuity")
        require(draft.airportChain == ["KTRM", "KPSP", "KTRM"], "10 remaining legs renumbered in sequence")

        // Sole leg cannot be erased.
        var single = CVRLocalDispatchDraft.fresh()
        require(!single.eraseLeg(id: single.legs[0].legUUID), "Leg 1 cannot be deleted when it is the only leg")

        // Non-planned status cannot be erased.
        draft.legs[0].status = "active"
        require(!draft.eraseLeg(id: draft.legs[0].legUUID), "active leg cannot be erased")
        draft.legs[0].status = "planned"

        // 15 Invalid / incomplete cannot submit.
        draft.selectedMissionCode = ""
        require(draft.validationMessage == "Select a flight mission.", "plain-language mission validation")
        draft.selectedMissionCode = "1-1-4"
        draft.setDeparture(legIndex: 0, airport: "")
        require(draft.validationMessage == "Enter the departure airport.", "plain-language departure validation")
        draft.setDeparture(legIndex: 0, airport: "KTRM")
        draft.setArrival(legIndex: 1, airport: "")
        require(draft.validationMessage == "Enter the destination for Leg 2.",
                "plain-language missing destination")
        draft.setArrival(legIndex: 1, airport: "12")
        require(draft.validationMessage == "Airport code must be a valid ICAO identifier.",
                "plain-language ICAO validation")
        require(!draft.canSubmit, "15 incomplete route cannot be submitted")

        // Comma-separated input is rejected by sanitizer (no multi-airport field).
        require(CVRLocalDispatchDraft.sanitizeAirportInput("KTRM,KPSP") == "KTRM",
                "3 comma-separated airport entry is not accepted")
        require(CVRLocalDispatchDraft.isValidICAOIdentifier("KTRM"), "valid ICAO accepted")
        require(!CVRLocalDispatchDraft.isValidICAOIdentifier("KT"), "too-short ICAO rejected")

        // 16 Offline persistence and restart preserve mission, airports, order, UUIDs.
        draft.setArrival(legIndex: 1, airport: "KTRM")
        draft.selectedMissionCode = "1-1-4"
        let suiteName = "cvr.local.dispatch.ui.test.\(UUID().uuidString)"
        let defaults = UserDefaults(suiteName: suiteName)!
        defer { defaults.removePersistentDomain(forName: suiteName) }
        draft.save(to: defaults)
        let restored = CVRLocalDispatchDraft.load(from: defaults)
        require(restored != nil, "draft persists offline")
        require(restored?.reservationUUID == reservationUUID, "reservation_uuid preserved across restart")
        require(restored?.selectedMissionCode == "1-1-4", "mission preserved across restart")
        require(restored?.legs.map(\.legUUID) == [leg1UUID, leg3UUID], "leg UUIDs preserved across restart")
        require(restored?.airportChain == ["KTRM", "KPSP", "KTRM"], "airports and order preserved")

        // Identity mint reuses draft UUIDs (no reminting).
        let dispatchUUIDs = [UUID().uuidString, UUID().uuidString, UUID().uuidString]
        let chain = ["KTRM", "KPSP", "KBUR", "KTRM"]
        let legUUIDs = [UUID().uuidString.lowercased(), UUID().uuidString.lowercased(), UUID().uuidString.lowercased()]
        let minted = try CVROperationalIdentityLocal.createOfflineMultiLegBundles(
            organizationID: 1,
            reservationUUID: reservationUUID,
            organizationTimezoneIANA: "America/Los_Angeles",
            airports: chain,
            dispatchUUIDs: dispatchUUIDs,
            legUUIDs: legUUIDs
        )
        require(minted.reservationUUID == reservationUUID, "create reuses draft reservation_uuid")
        require(minted.identities.map(\.legUUID) == legUUIDs, "create reuses draft leg_uuids without reminting")

        print("OK: Create Local Dispatch UI contract checks passed.")
    }
}
