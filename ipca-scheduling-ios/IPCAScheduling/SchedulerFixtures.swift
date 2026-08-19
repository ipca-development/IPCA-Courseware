import Foundation

enum PreviewScreen: String {
    case login, today, schedule, details, filters
}

enum SchedulerFixtures {
    static let now = ISO8601DateFormatter().date(from: "2026-08-19T19:17:00Z")!

    static let bootstrap: SchedulerBootstrapResponse = decode(
        """
        {
          "ok": true,
          "user": {
            "id": 42,
            "uuid": "f0000000-0000-4000-8000-000000000042",
            "email": "kay@ipca.training",
            "name": "Kay Vereeken",
            "role": "supervisor"
          },
          "organization": {"id": 1},
          "capabilities": {
            "schedule_read": true,
            "reservation_create": true,
            "reservation_edit": true,
            "reservation_cancel": true,
            "reservation_undispatch": false,
            "manual_checkin": false,
            "dispatch": false,
            "view_training": true,
            "resource_search": true
          },
          "operational_timezone": "America/Los_Angeles",
          "scheduler": {
            "max_range_days": 31,
            "overlap_policy": "warning",
            "schedule_time_semantics": "timezone_free_operational_local",
            "recurring_reservations_supported": false,
            "comprehensive_availability_supported": false
          }
        }
        """
    )

    static let schedule: ScheduleRangeResponse = decode(
        """
        {
          "ok": true,
          "range": {"start": "2026-08-12", "end": "2026-09-09"},
          "operational_timezone": "America/Los_Angeles",
          "refreshed_at": "2026-08-19T19:16:00Z",
          "reservations": [
            {
              "reservation_uuid": "10000000-0000-4000-8000-000000000001",
              "scheduler_record_id": "10000000-0000-4000-8000-000000000001",
              "reservation_type": "flight_training",
              "reservation_type_label": "Flight Training",
              "start_local": "2026-08-19T08:00:00.000",
              "end_local": "2026-08-19T10:00:00.000",
              "operational_timezone": "America/Los_Angeles",
              "status": "claimed",
              "lock": {"locked": true, "reason": "Dispatch is active"},
              "aircraft": {"id": 28, "registration": "N397EA", "display_name": "Alpha Trainer", "aircraft_type": "Pipistrel Alpha", "home_airport": "KTRM"},
              "mission": {"id": 17, "code": "PPL 17", "name": "Pattern Proficiency"},
              "cohort": {"id": 3, "name": "PPL 2026"},
              "crew": [
                {"person_id": 42, "person_name": "Kay Vereeken", "role": "instructor", "pilot_function": "PM", "is_pic": true},
                {"person_id": 101, "person_name": "Tasha Welvis", "role": "student", "pilot_function": "PF", "is_pic": false}
              ],
              "route": {
                "airport_chain": ["KTRM", "KPSP", "KTRM"],
                "legs": [
                  {"sequence_number": 1, "leg_uuid": "11000000-0000-4000-8000-000000000001", "origin_airport": "KTRM", "destination_airport": "KPSP", "status": "active"},
                  {"sequence_number": 2, "leg_uuid": "11000000-0000-4000-8000-000000000002", "origin_airport": "KPSP", "destination_airport": "KTRM", "status": "scheduled"}
                ]
              },
              "notes": "Focus on stabilized approaches and pattern consistency.",
              "evidence": {"has_dispatch": true, "has_recording": false, "has_closure": false, "has_debrief": false},
              "updated_at": "2026-08-19T07:42:11.312",
              "authorized_actions": {"edit": false, "reschedule": false, "cancel": false, "undispatch": false, "manual_checkin": false, "dispatch": false}
            },
            {
              "reservation_uuid": "20000000-0000-4000-8000-000000000001",
              "scheduler_record_id": "20000000-0000-4000-8000-000000000001",
              "reservation_type": "flight_training",
              "reservation_type_label": "Flight Training",
              "start_local": "2026-08-19T13:00:00.000",
              "end_local": "2026-08-19T15:00:00.000",
              "operational_timezone": "America/Los_Angeles",
              "status": "scheduled",
              "lock": {"locked": false, "reason": null},
              "aircraft": {"id": 42, "registration": "N428EA", "display_name": "Alpha Trainer", "aircraft_type": "Pipistrel Alpha", "home_airport": "KTRM"},
              "mission": {"id": 18, "code": "PPL 18", "name": "Navigation Training"},
              "cohort": {"id": 3, "name": "PPL 2026"},
              "crew": [
                {"person_id": 42, "person_name": "Kay Vereeken", "role": "instructor", "pilot_function": "PM", "is_pic": true},
                {"person_id": 102, "person_name": "Jarne Deruyck", "role": "student", "pilot_function": "PF", "is_pic": false}
              ],
              "route": {
                "airport_chain": ["KTRM", "KPSP", "KUDD", "KTRM"],
                "legs": [
                  {"sequence_number": 1, "leg_uuid": "21000000-0000-4000-8000-000000000001", "origin_airport": "KTRM", "destination_airport": "KPSP", "status": "scheduled"},
                  {"sequence_number": 2, "leg_uuid": "21000000-0000-4000-8000-000000000002", "origin_airport": "KPSP", "destination_airport": "KUDD", "status": "scheduled"},
                  {"sequence_number": 3, "leg_uuid": "21000000-0000-4000-8000-000000000003", "origin_airport": "KUDD", "destination_airport": "KTRM", "status": "scheduled"}
                ]
              },
              "notes": "Bring current navigation log and weather briefing.",
              "evidence": {"has_dispatch": false, "has_recording": false, "has_closure": false, "has_debrief": false},
              "updated_at": "2026-08-19T11:05:19.024",
              "authorized_actions": {"edit": true, "reschedule": true, "cancel": true, "undispatch": false, "manual_checkin": false, "dispatch": false}
            },
            {
              "reservation_uuid": "30000000-0000-4000-8000-000000000001",
              "scheduler_record_id": "30000000-0000-4000-8000-000000000001",
              "reservation_type": "briefing",
              "reservation_type_label": "Briefing",
              "start_local": "2026-08-19T06:30:00.000",
              "end_local": "2026-08-19T07:15:00.000",
              "operational_timezone": "America/Los_Angeles",
              "status": "completed",
              "lock": {"locked": true, "reason": "Completed"},
              "aircraft": {"id": 42, "registration": "N428EA", "display_name": "Alpha Trainer", "aircraft_type": "Pipistrel Alpha", "home_airport": "KTRM"},
              "mission": {"id": 16, "code": "PPL 16", "name": "Navigation Briefing"},
              "cohort": {"id": 3, "name": "PPL 2026"},
              "crew": [
                {"person_id": 42, "person_name": "Kay Vereeken", "role": "instructor", "pilot_function": "NONE", "is_pic": false},
                {"person_id": 102, "person_name": "Jarne Deruyck", "role": "student", "pilot_function": "NONE", "is_pic": false}
              ],
              "route": {"airport_chain": ["KTRM", "KTRM"], "legs": []},
              "notes": "",
              "evidence": {"has_dispatch": false, "has_recording": false, "has_closure": true, "has_debrief": true},
              "updated_at": "2026-08-19T07:20:00.000",
              "authorized_actions": {"edit": false, "reschedule": false, "cancel": false, "undispatch": false, "manual_checkin": false, "dispatch": false}
            },
            {
              "reservation_uuid": "40000000-0000-4000-8000-000000000001",
              "scheduler_record_id": "40000000-0000-4000-8000-000000000001",
              "reservation_type": "flight_training",
              "reservation_type_label": "Flight Training",
              "start_local": "2026-08-20T10:00:00.000",
              "end_local": "2026-08-20T12:00:00.000",
              "operational_timezone": "America/Los_Angeles",
              "status": "scheduled",
              "lock": {"locked": false, "reason": null},
              "aircraft": {"id": 42, "registration": "N428EA", "display_name": "Alpha Trainer", "aircraft_type": "Pipistrel Alpha", "home_airport": "KTRM"},
              "mission": {"id": 19, "code": "PPL 19", "name": "Cross-Country Planning"},
              "cohort": {"id": 3, "name": "PPL 2026"},
              "crew": [
                {"person_id": 42, "person_name": "Kay Vereeken", "role": "instructor", "pilot_function": "PM", "is_pic": true},
                {"person_id": 103, "person_name": "Alex Morgan", "role": "student", "pilot_function": "PF", "is_pic": false}
              ],
              "route": {"airport_chain": ["KTRM", "KCRQ", "KTRM"], "legs": []},
              "notes": "",
              "evidence": {"has_dispatch": false, "has_recording": false, "has_closure": false, "has_debrief": false},
              "updated_at": "2026-08-18T17:20:00.000",
              "authorized_actions": {"edit": true, "reschedule": true, "cancel": true, "undispatch": false, "manual_checkin": false, "dispatch": false}
            }
          ]
        }
        """
    )

    static let warning = SchedulerWarning(
        code: "aircraft_overlap",
        resourceType: "aircraft",
        resourceID: 42,
        message: "The selected aircraft overlaps another reservation during part of this period.",
        conflictingReservationUUID: "50000000-0000-4000-8000-000000000001"
    )

    static var featuredReservation: SchedulerReservation {
        schedule.reservations.first { $0.reservationUUID.hasPrefix("2000") }!
    }

    static var detail: ReservationDetailResponse {
        ReservationDetailResponse(
            ok: true,
            operationalTimezone: "America/Los_Angeles",
            reservation: featuredReservation,
            validation: SchedulerValidation(result: "allowed_with_warning", warnings: [warning])
        )
    }

    private static func decode<T: Decodable>(_ json: String) -> T {
        do {
            return try JSONDecoder().decode(T.self, from: Data(json.utf8))
        } catch {
            fatalError("Invalid scheduler fixture: \(error)")
        }
    }
}
