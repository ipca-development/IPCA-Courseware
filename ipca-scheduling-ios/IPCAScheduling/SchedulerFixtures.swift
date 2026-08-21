import Foundation

enum PreviewScreen: String {
    case login, today, schedule, details, filters
    case workstationAircraft = "workstation-aircraft"
    case workstationInstructor = "workstation-instructor"
    case workstationStudent = "workstation-student"
    case workstationInspector = "workstation-inspector"
    case workstationWarning = "workstation-warning"
    case workstationFullDay = "workstation-full-day"
    case workstationDetailed = "workstation-detailed"
    case workstationWeek = "workstation-week"
    case workstationWeekInstructor = "workstation-week-instructor"
    case workstationWeekStudent = "workstation-week-student"
    case workstationWeekWarning = "workstation-week-warning"
    case workstationWeekSparse = "workstation-week-sparse"
    case workstationOffline = "workstation-offline"
    case workstationPortrait = "workstation-portrait"
    case workstationSparse = "workstation-sparse"
    case workstationStress = "workstation-stress"
    case workstationFilters = "workstation-filters"
    case workstationExpanded = "workstation-expanded"
    case workstationCrew = "workstation-crew"
    case workstationNarrow = "workstation-narrow"
    case workstationTwilightMorning = "workstation-twilight-morning"
    case workstationTwilightEvening = "workstation-twilight-evening"
    case workstationTwilightFullDay = "workstation-twilight-full-day"
    case workstationTwilightMorningSelected = "workstation-twilight-morning-selected"
    case workstationTwilightEveningSelected = "workstation-twilight-evening-selected"

    var isWorkstation: Bool {
        switch self {
        case .workstationAircraft, .workstationInstructor, .workstationStudent,
             .workstationInspector, .workstationWarning, .workstationFullDay,
             .workstationDetailed, .workstationWeek, .workstationWeekInstructor,
             .workstationWeekStudent, .workstationWeekWarning,
             .workstationWeekSparse, .workstationOffline,
             .workstationPortrait, .workstationSparse, .workstationStress,
             .workstationFilters, .workstationExpanded, .workstationCrew,
             .workstationNarrow, .workstationTwilightMorning,
             .workstationTwilightEvening, .workstationTwilightFullDay,
             .workstationTwilightMorningSelected,
             .workstationTwilightEveningSelected:
            true
        default:
            false
        }
    }
}

enum SchedulerFixtures {
    static let now = ISO8601DateFormatter().date(from: "2026-08-19T19:17:00Z")!

    static let operationalHomeBase = SchedulerOperationalHomeBase(
        id: 1,
        organizationID: 1,
        displayName: "Jacqueline Cochran Regional Airport",
        airportIdentifier: "KTRM",
        latitude: 33.6267,
        longitude: -116.1597,
        operationalTimezone: "America/Los_Angeles",
        source: "tv_kiosk_config"
    )

    static let workstationAstronomy: [SchedulerAstronomyDay] = [
        SchedulerAstronomyDay(
            date: "2026-08-19",
            morningCivilTwilightBegin: "2026-08-19T05:43:55.000",
            sunrise: "2026-08-19T06:09:53.000",
            sunset: "2026-08-19T19:26:30.000",
            eveningCivilTwilightEnd: "2026-08-19T19:52:28.000",
            operationalTimezone: "America/Los_Angeles",
            locationID: 1,
            airportIdentifier: "KTRM",
            calculationMethod: "php_date_sun_info_civil_twilight_v1"
        )
    ]

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

    static let workstationAircraft: [SchedulerResourceItem] = [
        aircraft(28, "N397EA", "Alpha", "Pipistrel Alpha"),
        aircraft(39, "N392EA", "Warrior", "PA-28"),
        aircraft(42, "N428EA", "Alpha", "Pipistrel Alpha"),
        aircraft(46, "N446CS", "Skyhawk", "C172S"),
        aircraft(50, "N521IP", "Archer", "PA-28-181"),
        aircraft(51, "N613IP", "Arrow", "PA-28R"),
        aircraft(52, "SIM-1", "Redbird", "Simulator"),
        aircraft(53, "SIM-2", "G1000 Trainer", "Simulator"),
        aircraft(54, "N731IP", "Citabria", "7ECA"),
        aircraft(55, "N814IP", "Seminole", "PA-44")
    ]

    static let workstationPeople: [SchedulerResourceItem] = [
        person(42, "Kay Vereeken", "chief_instructor"),
        person(201, "Zane Haley", "instructor"),
        person(202, "Amelia-Rose Montgomery", "instructor"),
        person(203, "Christopher Van den Berghe", "instructor"),
        person(204, "Priya Ramanathan", "instructor"),
        person(205, "Maximilian De Smet", "instructor"),
        person(101, "Tasha Welvis", "student"),
        person(102, "Jarne Deruyck", "student"),
        person(103, "Viktor Kumps", "student"),
        person(104, "Alexandra-Christine Morgan", "student"),
        person(105, "Benjamin Rodríguez-Santos", "student"),
        person(106, "Noor Van der Auwera", "student"),
        person(107, "Emilia-Jane Thompson", "student"),
        person(108, "Matteo De la Cruz", "student")
    ]

    static let workstationSchedule: ScheduleRangeResponse = {
        let day = "2026-08-19"
        let reservations = [
            makeReservation(1, aircraft: 39, start: "06:00", end: "07:00", status: "completed", student: 106, instructor: 204, mission: "Early Morning Local Procedures", code: "PPL 09", route: ["KTRM", "KTRM"]),
            makeReservation(2, aircraft: 42, start: "06:30", end: "07:00", status: "completed", student: 102, instructor: 42, mission: "Cross-Country Weather Briefing", code: "PPL 16", type: "briefing", route: ["KTRM", "KTRM"]),
            makeReservation(3, aircraft: 28, start: "07:00", end: "09:00", status: "claimed", student: 101, instructor: 42, mission: "Pattern Proficiency and Stabilized Approaches", code: "PPL 17", route: ["KTRM", "KPSP", "KTRM"]),
            makeReservation(4, aircraft: 46, start: "07:30", end: "11:30", status: "claimed", student: 104, instructor: 202, mission: "Long Cross-Country Navigation and Diversion", code: "CPL XC", route: ["KTRM", "L35", "KPRB", "KTRM"]),
            makeReservation(5, aircraft: 42, start: "08:30", end: "09:30", status: "scheduled", student: 103, instructor: 201, mission: "Radio Navigation and Holding Procedures", code: "IR 08", route: ["KTRM", "KPSP", "KTRM"]),
            makeReservation(6, aircraft: 39, start: "09:00", end: "11:00", status: "scheduled", student: 105, instructor: 201, mission: "Private Pilot Stage Check Preparation", code: "PPL 24", route: ["KTRM", "KUDD", "KTRM"]),
            makeReservation(7, aircraft: 39, start: "09:30", end: "10:30", status: "scheduled", student: 108, instructor: 203, mission: "Aircraft Systems Review Flight", code: "PPL 12", route: ["KTRM", "KTRM"]),
            makeReservation(8, aircraft: 50, start: "10:00", end: "12:00", status: "scheduled", student: 107, instructor: 205, mission: "Commercial Maneuvers and Energy Management", code: "CPL 11", route: ["KTRM", "KTRM"]),
            makeReservation(9, aircraft: 52, start: "10:30", end: "12:30", status: "scheduled", student: 106, instructor: 204, mission: "Instrument Approaches in Low Visibility", code: "IR SIM 5", route: []),
            makeReservation(10, aircraft: 42, start: "11:00", end: "12:00", status: "scheduled", student: 102, instructor: 202, mission: "Short-Field Takeoff and Landing", code: "PPL 18", route: ["KTRM", "KTRM"]),
            makeReservation(11, aircraft: 28, start: "12:00", end: "13:00", status: "scheduled", student: 101, instructor: 42, mission: "Solo Readiness Review", code: "PPL 20", route: ["KTRM", "KTRM"]),
            makeReservation(12, aircraft: 46, start: "12:30", end: "14:00", status: "scheduled", student: 104, instructor: 203, mission: "Mountain Flying Techniques", code: "ADV 03", route: ["KTRM", "L35", "KTRM"]),
            makeReservation(13, aircraft: 42, start: "13:00", end: "15:00", status: "scheduled", student: 103, instructor: 201, mission: "Big Bear Radio Navigation Training", code: "IR 12", route: ["KTRM", "L35", "KTRM"], notes: "Review terrain clearance and diversion planning."),
            makeReservation(14, aircraft: 50, start: "13:30", end: "14:30", status: "scheduled", student: 108, instructor: 205, mission: "Complex Aircraft Familiarization", code: "CPL 07", route: ["KTRM", "KTRM"]),
            makeReservation(15, aircraft: 39, start: "14:00", end: "18:00", status: "scheduled", student: 105, instructor: 202, mission: "Four-Hour Commercial Cross-Country", code: "CPL XC 4", route: ["KTRM", "KCRQ", "KPRB", "KTRM"]),
            makeReservation(16, aircraft: 28, start: "15:00", end: "16:00", status: "scheduled", student: 107, instructor: 204, mission: "Emergency Procedures and Systems", code: "PPL 21", route: ["KTRM", "KTRM"]),
            makeReservation(17, aircraft: 46, start: "15:30", end: "17:30", status: "scheduled", student: 102, instructor: 203, mission: "Navigation Training with Diversion", code: "PPL 19", route: ["KTRM", "KPSP", "KUDD", "KTRM"]),
            makeReservation(18, aircraft: 42, start: "16:00", end: "17:00", status: "scheduled", student: 106, instructor: 42, mission: "Traffic Pattern Consolidation", code: "PPL 14", route: ["KTRM", "KTRM"])
        ].map { reservation in
            withDay(reservation, day: day)
        }
        var response = ScheduleRangeResponse(
            ok: true,
            range: ScheduleRange(start: "2026-08-17", end: "2026-08-23"),
            operationalTimezone: "America/Los_Angeles",
            reservations: reservations + weekReservations,
            refreshedAt: "2026-08-19T19:16:00Z"
        )
        response.operationalHomeBase = operationalHomeBase
        response.astronomyDays = workstationAstronomy
        return response
    }()

    static let sparseSchedule: ScheduleRangeResponse = {
        var response = ScheduleRangeResponse(
            ok: true,
            range: ScheduleRange(start: "2026-08-17", end: "2026-08-23"),
            operationalTimezone: "America/Los_Angeles",
            reservations: [
                makeReservation(61, aircraft: 28, start: "07:30", end: "08:30", status: "completed", student: 101, instructor: 42, mission: "Pattern Proficiency", code: "PPL 17", route: ["KTRM", "KTRM"]),
                makeReservation(62, aircraft: 46, start: "10:00", end: "12:00", status: "scheduled", student: 104, instructor: 202, mission: "Navigation Training", code: "PPL 19", route: ["KTRM", "L35", "KTRM"]),
                makeReservation(63, aircraft: 52, start: "13:00", end: "13:30", status: "scheduled", student: 106, instructor: 204, mission: "Instrument Procedures", code: "IR SIM", route: []),
                makeReservation(64, aircraft: 55, start: "16:00", end: "18:00", status: "scheduled", student: 105, instructor: 205, mission: "Multi-Engine Operations", code: "ME 04", route: ["KTRM", "KTRM"])
            ],
            refreshedAt: "2026-08-19T19:16:00Z"
        )
        response.operationalHomeBase = operationalHomeBase
        response.astronomyDays = workstationAstronomy
        return response
    }()

    static let stressSchedule: ScheduleRangeResponse = {
        var items = workstationSchedule.reservations
        let aircraftIDs = workstationAircraft.map(\.id)
        let studentIDs = workstationPeople.filter { $0.role == "student" }.map(\.id)
        let instructorIDs = workstationPeople.filter { $0.role?.contains("instructor") == true }.map(\.id)
        for index in 0 ..< 25 {
            let hour = 6 + (index * 37 / 60) % 12
            let minute = (index * 37) % 60
            let length = [30, 60, 90, 120][index % 4]
            let startMinutes = hour * 60 + minute
            let endMinutes = startMinutes + length
            let start = String(format: "%02d:%02d", startMinutes / 60, startMinutes % 60)
            let end = String(format: "%02d:%02d", min(23, endMinutes / 60), endMinutes % 60)
            items.append(
                makeReservation(
                    100 + index,
                    aircraft: aircraftIDs[index % aircraftIDs.count],
                    start: start,
                    end: end,
                    status: index % 7 == 0 ? "completed" : "scheduled",
                    student: studentIDs[index % studentIDs.count],
                    instructor: instructorIDs[index % instructorIDs.count],
                    mission: "Operational Density Validation Mission \(index + 1)",
                    code: "OPS \(index + 1)",
                    route: index % 3 == 0 ? ["KTRM", "KPSP", "KTRM"] : ["KTRM", "KTRM"]
                )
            )
        }
        var response = ScheduleRangeResponse(
            ok: true,
            range: workstationSchedule.range,
            operationalTimezone: workstationSchedule.operationalTimezone,
            reservations: items,
            refreshedAt: workstationSchedule.refreshedAt
        )
        response.operationalHomeBase = operationalHomeBase
        response.astronomyDays = workstationAstronomy
        return response
    }()

    static let twilightSchedule: ScheduleRangeResponse = {
        var response = workstationSchedule
        response = ScheduleRangeResponse(
            ok: response.ok,
            range: response.range,
            operationalTimezone: response.operationalTimezone,
            reservations: response.reservations + [
                makeReservation(
                    901,
                    aircraft: 28,
                    start: "05:20",
                    end: "06:40",
                    status: "scheduled",
                    student: 101,
                    instructor: 42,
                    mission: "Morning Civil Twilight Training",
                    code: "NIGHT 01",
                    route: ["KTRM", "KTRM"]
                ),
                makeReservation(
                    902,
                    aircraft: 42,
                    start: "19:00",
                    end: "20:20",
                    status: "scheduled",
                    student: 103,
                    instructor: 201,
                    mission: "Evening Civil Twilight Training",
                    code: "NIGHT 02",
                    route: ["KTRM", "KTRM"]
                )
            ],
            refreshedAt: response.refreshedAt,
            operationalHomeBase: operationalHomeBase,
            astronomyDays: workstationAstronomy
        )
        return response
    }()

    static var twilightMorningReservation: SchedulerReservation {
        twilightSchedule.reservations.first { $0.id.hasSuffix("000000000901") }!
    }

    static var twilightEveningReservation: SchedulerReservation {
        twilightSchedule.reservations.first { $0.id.hasSuffix("000000000902") }!
    }

    static let workstationWarnings: [String: [SchedulerWarning]] = [
        "50000000-0000-4000-8000-000000000005": [
            SchedulerWarning(
                code: "crew_overlap",
                resourceType: "user",
                resourceID: 201,
                message: "Zane Haley is assigned to another reservation during part of this period.",
                conflictingReservationUUID: "50000000-0000-4000-8000-000000000006"
            )
        ],
        "50000000-0000-4000-8000-000000000006": [
            SchedulerWarning(
                code: "aircraft_overlap",
                resourceType: "aircraft",
                resourceID: 39,
                message: "N392EA overlaps another reservation during part of this period.",
                conflictingReservationUUID: "50000000-0000-4000-8000-000000000007"
            ),
            SchedulerWarning(
                code: "crew_overlap",
                resourceType: "user",
                resourceID: 201,
                message: "Zane Haley is assigned to another reservation during part of this period.",
                conflictingReservationUUID: "50000000-0000-4000-8000-000000000005"
            )
        ],
        "50000000-0000-4000-8000-000000000007": [
            SchedulerWarning(
                code: "aircraft_overlap",
                resourceType: "aircraft",
                resourceID: 39,
                message: "N392EA overlaps another reservation during part of this period.",
                conflictingReservationUUID: "50000000-0000-4000-8000-000000000006"
            )
        ]
    ]

    static var workstationFeaturedReservation: SchedulerReservation {
        workstationSchedule.reservations.first { $0.id.hasSuffix("000000000013") }!
    }

    static var workstationWarningReservation: SchedulerReservation {
        workstationSchedule.reservations.first { $0.id.hasSuffix("000000000006") }!
    }

    static var workstationCrewReservation: SchedulerReservation {
        let source = workstationFeaturedReservation
        let additionalCrew = [
            CrewMember(
                personID: 202,
                personName: "Amelia-Rose Montgomery",
                role: "instructor",
                pilotFunction: "Observer",
                isPIC: false
            ),
            CrewMember(
                personID: 203,
                personName: "Christopher Van den Berghe",
                role: "instructor",
                pilotFunction: "Check Instructor",
                isPIC: false
            )
        ]
        return replacingCrew(in: source, with: source.crew + additionalCrew)
    }

    static var workstationNoInstructorReservation: SchedulerReservation {
        let source = workstationFeaturedReservation
        return replacingCrew(
            in: source,
            with: source.crew.filter { !$0.role.lowercased().contains("instructor") }
        )
    }

    private static let weekReservations: [SchedulerReservation] = [
        withDay(makeReservation(31, aircraft: 28, start: "09:00", end: "11:00", status: "completed", student: 101, instructor: 42, mission: "Pattern Proficiency", code: "PPL 17", route: ["KTRM", "KTRM"]), day: "2026-08-17"),
        withDay(makeReservation(32, aircraft: 42, start: "13:00", end: "15:00", status: "scheduled", student: 103, instructor: 201, mission: "Navigation Training", code: "PPL 19", route: ["KTRM", "KPSP", "KTRM"]), day: "2026-08-18"),
        withDay(makeReservation(33, aircraft: 46, start: "08:00", end: "12:00", status: "scheduled", student: 104, instructor: 202, mission: "Commercial Cross-Country", code: "CPL XC", route: ["KTRM", "KPRB", "KTRM"]), day: "2026-08-20"),
        withDay(makeReservation(34, aircraft: 50, start: "10:00", end: "11:30", status: "scheduled", student: 107, instructor: 205, mission: "Commercial Maneuvers", code: "CPL 11", route: ["KTRM", "KTRM"]), day: "2026-08-21"),
        withDay(makeReservation(35, aircraft: 39, start: "07:00", end: "09:00", status: "scheduled", student: 105, instructor: 203, mission: "Stage Check", code: "PPL 24", route: ["KTRM", "KTRM"]), day: "2026-08-22")
    ]

    private static func aircraft(
        _ id: Int,
        _ registration: String,
        _ displayName: String,
        _ type: String
    ) -> SchedulerResourceItem {
        SchedulerResourceItem(
            id: id,
            registration: registration,
            displayName: displayName,
            aircraftType: type,
            homeAirport: "KTRM",
            role: nil,
            code: nil,
            name: nil
        )
    }

    private static func person(_ id: Int, _ name: String, _ role: String) -> SchedulerResourceItem {
        SchedulerResourceItem(
            id: id,
            registration: nil,
            displayName: name,
            aircraftType: nil,
            homeAirport: nil,
            role: role,
            code: nil,
            name: nil
        )
    }

    private static func makeReservation(
        _ index: Int,
        aircraft aircraftID: Int,
        start: String,
        end: String,
        status: String,
        student studentID: Int,
        instructor instructorID: Int,
        mission: String,
        code: String,
        type: String = "flight_training",
        route: [String],
        notes: String = ""
    ) -> SchedulerReservation {
        let aircraft = workstationAircraft.first { $0.id == aircraftID }!
        let student = workstationPeople.first { $0.id == studentID }!
        let instructor = workstationPeople.first { $0.id == instructorID }!
        let uuid = String(format: "50000000-0000-4000-8000-%012d", index)
        let legs = zip(route, route.dropFirst()).enumerated().map { offset, airports in
            ReservationLeg(
                sequenceNumber: offset + 1,
                legUUID: String(format: "51000000-0000-4000-8000-%012d", index * 10 + offset),
                originAirport: airports.0,
                destinationAirport: airports.1,
                status: status
            )
        }
        return SchedulerReservation(
            reservationUUID: uuid,
            schedulerRecordID: uuid,
            reservationType: type,
            reservationTypeLabel: type == "briefing" ? "Briefing" : "Flight Training",
            startLocal: "2026-08-19T\(start):00.000",
            endLocal: "2026-08-19T\(end):00.000",
            operationalTimezone: "America/Los_Angeles",
            status: status,
            lock: ReservationLock(locked: status == "claimed" || status == "completed", reason: status == "claimed" ? "dispatch_claimed" : status == "completed" ? "completed" : nil),
            aircraft: AircraftSummary(
                id: aircraftID,
                registration: aircraft.registration ?? "",
                displayName: aircraft.displayName,
                aircraftType: aircraft.aircraftType,
                homeAirport: aircraft.homeAirport
            ),
            mission: MissionSummary(id: index, code: code, name: mission),
            cohort: CohortSummary(id: 3, name: "PPL 2026"),
            crew: [
                CrewMember(personID: studentID, personName: student.displayName ?? "", role: "student", pilotFunction: "PF", isPIC: false),
                CrewMember(personID: instructorID, personName: instructor.displayName ?? "", role: "instructor", pilotFunction: "PM", isPIC: true)
            ],
            route: ReservationRoute(airportChain: route, legs: legs),
            notes: notes,
            evidence: nil,
            updatedAt: "2026-08-19T05:00:00.000",
            authorizedActions: AuthorizedActions(edit: false, reschedule: false, cancel: false, undispatch: false, manualCheckin: false, dispatch: false)
        )
    }

    private static func withDay(_ reservation: SchedulerReservation, day: String) -> SchedulerReservation {
        SchedulerReservation(
            reservationUUID: reservation.reservationUUID,
            schedulerRecordID: reservation.schedulerRecordID,
            reservationType: reservation.reservationType,
            reservationTypeLabel: reservation.reservationTypeLabel,
            startLocal: day + String(reservation.startLocal.dropFirst(10)),
            endLocal: day + String(reservation.endLocal.dropFirst(10)),
            operationalTimezone: reservation.operationalTimezone,
            status: reservation.status,
            lock: reservation.lock,
            aircraft: reservation.aircraft,
            mission: reservation.mission,
            cohort: reservation.cohort,
            crew: reservation.crew,
            route: reservation.route,
            notes: reservation.notes,
            evidence: reservation.evidence,
            updatedAt: reservation.updatedAt,
            authorizedActions: reservation.authorizedActions
        )
    }

    private static func replacingCrew(
        in reservation: SchedulerReservation,
        with crew: [CrewMember]
    ) -> SchedulerReservation {
        SchedulerReservation(
            reservationUUID: reservation.reservationUUID,
            schedulerRecordID: reservation.schedulerRecordID,
            reservationType: reservation.reservationType,
            reservationTypeLabel: reservation.reservationTypeLabel,
            startLocal: reservation.startLocal,
            endLocal: reservation.endLocal,
            operationalTimezone: reservation.operationalTimezone,
            status: reservation.status,
            lock: reservation.lock,
            aircraft: reservation.aircraft,
            mission: reservation.mission,
            cohort: reservation.cohort,
            crew: crew,
            route: reservation.route,
            notes: reservation.notes,
            evidence: reservation.evidence,
            updatedAt: reservation.updatedAt,
            authorizedActions: reservation.authorizedActions
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
