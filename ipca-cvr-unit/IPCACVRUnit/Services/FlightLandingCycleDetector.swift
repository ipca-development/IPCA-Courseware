import CoreLocation
import Foundation

enum LandingCycleKind: String, Codable, Equatable {
    case touchAndGo = "touch_and_go"
    case stopAndGo = "stop_and_go"
    case fullStop = "full_stop"
}

enum TakeoffCycleKind: String, Codable, Equatable {
    case initial = "initial"
    case cycle = "cycle"
}

enum GPSFlightTransition: Equatable {
    case takeoff(timestamp: Date, sample: GPSSample, kind: TakeoffCycleKind)
    case landing(timestamp: Date, sample: GPSSample, kind: LandingCycleKind)
}

struct AirportReference: Equatable {
    let icao: String
    let latitude: Double
    let longitude: Double
    let elevationFeet: Double
    let boundaryRadiusNM: Double
}

enum AirportGeofenceCatalog {
    static let bundled: [AirportReference] = [
        AirportReference(icao: "KTRM", latitude: 33.626667, longitude: -116.159722, elevationFeet: -115, boundaryRadiusNM: 3.0),
        AirportReference(icao: "KPSP", latitude: 33.829667, longitude: -116.506389, elevationFeet: 477, boundaryRadiusNM: 4.0),
        AirportReference(icao: "KCRQ", latitude: 33.128333, longitude: -117.280278, elevationFeet: 331, boundaryRadiusNM: 3.5),
        AirportReference(icao: "KSAN", latitude: 32.733556, longitude: -117.189667, elevationFeet: 17, boundaryRadiusNM: 4.0),
        AirportReference(icao: "KLAX", latitude: 33.942522, longitude: -118.408051, elevationFeet: 125, boundaryRadiusNM: 5.0),
        AirportReference(icao: "KVNY", latitude: 34.209722, longitude: -118.489444, elevationFeet: 802, boundaryRadiusNM: 3.5),
        AirportReference(icao: "KBUR", latitude: 34.200667, longitude: -118.358667, elevationFeet: 778, boundaryRadiusNM: 3.0),
        AirportReference(icao: "KONT", latitude: 34.055999, longitude: -117.601148, elevationFeet: 944, boundaryRadiusNM: 3.5),
        AirportReference(icao: "KSNA", latitude: 33.675667, longitude: -117.868222, elevationFeet: 56, boundaryRadiusNM: 3.5),
        AirportReference(icao: "KAJO", latitude: 33.574722, longitude: -117.128889, elevationFeet: 56, boundaryRadiusNM: 2.5),
    ]

    static func resolve(icaoCodes: [String]) -> [AirportReference] {
        let normalized = Set(icaoCodes.map { $0.uppercased().trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty })
        guard !normalized.isEmpty else { return bundled }
        let matches = bundled.filter { normalized.contains($0.icao) }
        return matches.isEmpty ? bundled : matches
    }

    static func nearestAirport(latitude: Double, longitude: Double, within airports: [AirportReference]) -> AirportReference? {
        var best: (AirportReference, Double)?
        for airport in airports {
            let distanceNM = haversineNM(
                lat1: latitude,
                lon1: longitude,
                lat2: airport.latitude,
                lon2: airport.longitude
            )
            if distanceNM <= airport.boundaryRadiusNM {
                if best == nil || distanceNM < best!.1 {
                    best = (airport, distanceNM)
                }
            }
        }
        return best?.0
    }

    static func haversineNM(lat1: Double, lon1: Double, lat2: Double, lon2: Double) -> Double {
        let earthRadiusNM = 3440.065
        let dLat = (lat2 - lat1) * .pi / 180
        let dLon = (lon2 - lon1) * .pi / 180
        let a = sin(dLat / 2) * sin(dLat / 2)
            + cos(lat1 * .pi / 180) * cos(lat2 * .pi / 180) * sin(dLon / 2) * sin(dLon / 2)
        return 2 * earthRadiusNM * asin(min(1, sqrt(a)))
    }
}

struct FlightLandingCycleDetector {
    private enum Phase {
        case ground
        case airborne
        case airportRoll(RollState)
    }

    private struct RollState {
        var enteredAt: Date
        var airportICAO: String
        var belowStopSpeedSince: Date?
        var takeoffCandidateAt: Date?
        /// First time speed dropped to approach/landing range after entering the airport.
        var landingMomentAt: Date?
    }

    private static let takeoffSpeedKnots = 40.0
    private static let takeoffConfirmationSeconds: TimeInterval = 6
    private static let takeoffAfterRollConfirmationSeconds: TimeInterval = 3
    private static let nonPatternLandingSpeedKnots = 30.0
    private static let nonPatternLandingConfirmationSeconds: TimeInterval = 4
    private static let approachEntrySpeedKnots = 40.0
    private static let approachEntrySeconds: TimeInterval = 1
    private static let stopSpeedKnots = 25.0
    private static let stopHoldSeconds: TimeInterval = 3
    private static let touchGoArmSeconds: TimeInterval = 1.5
    private static let groundAGLFeet = 120.0

    private var phase: Phase = .ground
    private var takeoffCandidateAt: Date?
    private var landingCandidateAt: Date?
    private var approachEntrySince: Date?
    private var hasInitialTakeoff = false
    private var airports: [AirportReference] = AirportGeofenceCatalog.bundled

    mutating func reset(airportICAOs: [String] = []) {
        phase = .ground
        takeoffCandidateAt = nil
        landingCandidateAt = nil
        approachEntrySince = nil
        hasInitialTakeoff = false
        airports = AirportGeofenceCatalog.resolve(icaoCodes: airportICAOs)
    }

    mutating func evaluate(sample: GPSSample) -> [GPSFlightTransition] {
        var transitions: [GPSFlightTransition] = []
        let inAirport = airportContext(for: sample) != nil

        switch phase {
        case .ground:
            transitions.append(contentsOf: evaluateGroundTakeoff(sample: sample))
        case .airborne:
            if inAirport, sample.speedKnots <= Self.approachEntrySpeedKnots {
                let entry = approachEntrySince ?? sample.timestamp
                approachEntrySince = entry
                if sample.timestamp.timeIntervalSince(entry) >= Self.approachEntrySeconds {
                    let airport = airportContext(for: sample)!
                    phase = .airportRoll(RollState(
                        enteredAt: sample.timestamp,
                        airportICAO: airport.icao,
                        belowStopSpeedSince: sample.speedKnots <= Self.stopSpeedKnots ? sample.timestamp : nil,
                        takeoffCandidateAt: nil,
                        landingMomentAt: sample.timestamp
                    ))
                    approachEntrySince = nil
                    landingCandidateAt = nil
                }
            } else {
                approachEntrySince = nil
                transitions.append(contentsOf: evaluateAirborneLanding(sample: sample, inAirport: inAirport))
            }
        case .airportRoll(var roll):
            transitions.append(contentsOf: evaluateAirportRoll(sample: sample, roll: &roll))
            if case .airportRoll = phase {
                phase = .airportRoll(roll)
            }
        }

        return transitions
    }

    private mutating func evaluateGroundTakeoff(sample: GPSSample) -> [GPSFlightTransition] {
        landingCandidateAt = nil
        if sample.speedKnots >= Self.takeoffSpeedKnots {
            let candidate = takeoffCandidateAt ?? sample.timestamp
            takeoffCandidateAt = candidate
            if sample.timestamp.timeIntervalSince(candidate) >= Self.takeoffConfirmationSeconds {
                takeoffCandidateAt = nil
                phase = .airborne
                let kind: TakeoffCycleKind = hasInitialTakeoff ? .cycle : .initial
                hasInitialTakeoff = true
                return [.takeoff(timestamp: candidate, sample: sample, kind: kind)]
            }
        } else {
            takeoffCandidateAt = nil
        }
        return []
    }

    private mutating func evaluateAirborneLanding(sample: GPSSample, inAirport: Bool) -> [GPSFlightTransition] {
        takeoffCandidateAt = nil
        guard !inAirport else { return [] }

        if sample.speedKnots <= Self.nonPatternLandingSpeedKnots {
            let candidate = landingCandidateAt ?? sample.timestamp
            landingCandidateAt = candidate
            if sample.timestamp.timeIntervalSince(candidate) >= Self.nonPatternLandingConfirmationSeconds {
                landingCandidateAt = nil
                phase = .ground
                return [.landing(timestamp: candidate, sample: sample, kind: .fullStop)]
            }
        } else {
            landingCandidateAt = nil
        }
        return []
    }

    private mutating func evaluateAirportRoll(sample: GPSSample, roll: inout RollState) -> [GPSFlightTransition] {
        var transitions: [GPSFlightTransition] = []
        guard airports.contains(where: { $0.icao == roll.airportICAO }) else {
            phase = .airborne
            return transitions
        }

        if airportContext(for: sample)?.icao != roll.airportICAO {
            // Left airport after a landing moment without re-takeoff — treat as full stop.
            if let landingAt = roll.landingMomentAt {
                transitions.append(.landing(timestamp: landingAt, sample: sample, kind: .fullStop))
                phase = .ground
            } else {
                phase = .airborne
            }
            return transitions
        }

        if sample.speedKnots <= Self.approachEntrySpeedKnots {
            roll.landingMomentAt = roll.landingMomentAt ?? sample.timestamp
        }

        if sample.speedKnots <= Self.stopSpeedKnots {
            roll.belowStopSpeedSince = roll.belowStopSpeedSince ?? sample.timestamp
        } else {
            roll.belowStopSpeedSince = nil
        }

        // Full stop: commit quickly once slowed on the airport surface.
        if let belowStop = roll.belowStopSpeedSince,
           sample.timestamp.timeIntervalSince(belowStop) >= Self.stopHoldSeconds {
            transitions.append(.landing(timestamp: belowStop, sample: sample, kind: .fullStop))
            phase = .ground
            return transitions
        }

        // Touch-and-go: after a brief landing-speed period, accelerate again.
        if let landingAt = roll.landingMomentAt,
           sample.timestamp.timeIntervalSince(landingAt) >= Self.touchGoArmSeconds,
           sample.speedKnots >= Self.takeoffSpeedKnots {
            let candidate = roll.takeoffCandidateAt ?? sample.timestamp
            roll.takeoffCandidateAt = candidate
            if sample.timestamp.timeIntervalSince(candidate) >= Self.takeoffAfterRollConfirmationSeconds {
                transitions.append(.landing(timestamp: landingAt, sample: sample, kind: .touchAndGo))
                transitions.append(.takeoff(timestamp: candidate, sample: sample, kind: .cycle))
                phase = .airborne
                return transitions
            }
        } else if sample.speedKnots < Self.takeoffSpeedKnots {
            roll.takeoffCandidateAt = nil
        }

        return transitions
    }

    private func airportContext(for sample: GPSSample) -> AirportReference? {
        AirportGeofenceCatalog.nearestAirport(
            latitude: sample.latitude,
            longitude: sample.longitude,
            within: airports
        )
    }
}
