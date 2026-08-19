import SwiftUI

struct ReservationCard: View {
    let reservation: SchedulerReservation
    let clock: SchedulerClock
    var isNext = false
    var now = Date()

    private var foreground: Color { isNext ? .white : IPCAColors.text }
    private var secondary: Color { isNext ? .white.opacity(0.72) : IPCAColors.textSecondary }

    var body: some View {
        VStack(alignment: .leading, spacing: IPCASpacing.medium) {
            HStack(alignment: .firstTextBaseline) {
                Text(clock.timeRange(start: reservation.startLocal, end: reservation.endLocal))
                    .font(.system(.headline, design: .rounded, weight: .bold))
                    .foregroundStyle(foreground)
                Spacer()
                if !isNext {
                    StatusBadge(status: reservation.status)
                } else if let relative = clock.relativeStart(reservation.startLocal, now: now) {
                    Text(relative)
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 10)
                        .padding(.vertical, 6)
                        .background(.white.opacity(0.15), in: Capsule())
                }
            }

            VStack(alignment: .leading, spacing: 5) {
                Text(reservation.title)
                    .font(.system(isNext ? .title3 : .headline, design: .rounded, weight: .bold))
                    .foregroundStyle(foreground)
                    .lineLimit(2)
                if let mission = reservation.missionLine, mission != reservation.title {
                    Text(mission)
                        .font(.subheadline)
                        .foregroundStyle(secondary)
                        .lineLimit(1)
                }
            }

            HStack(spacing: IPCASpacing.standard) {
                Label(reservation.aircraft.registration, systemImage: "airplane")
                if let crew = reservation.crewSummary {
                    Label(crew, systemImage: "person.fill")
                        .lineLimit(1)
                }
            }
            .font(.subheadline.weight(.medium))
            .foregroundStyle(secondary)

            if isNext {
                HStack {
                    Text("View Details")
                        .font(.subheadline.weight(.semibold))
                    Spacer()
                    Image(systemName: "arrow.right")
                }
                .foregroundStyle(.white)
                .padding(.top, 2)
            }
        }
        .padding(IPCASpacing.large)
        .ipcaCard(highlighted: isNext)
        .contentShape(Rectangle())
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(accessibilitySummary)
        .accessibilityHint("Opens reservation details")
    }

    private var accessibilitySummary: String {
        var parts = [
            isNext ? "Next reservation" : reservation.status.capitalized,
            clock.timeRange(start: reservation.startLocal, end: reservation.endLocal),
            reservation.title,
            reservation.aircraft.registration
        ]
        if let crew = reservation.crewSummary { parts.append(crew) }
        return parts.joined(separator: ", ")
    }
}

struct WarningCard: View {
    let warning: SchedulerWarning

    var body: some View {
        HStack(alignment: .top, spacing: IPCASpacing.medium) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(IPCAColors.warning)
                .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 4) {
                Text("Scheduling warning")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(IPCAColors.text)
                Text(warning.message)
                    .font(.subheadline)
                    .foregroundStyle(IPCAColors.textSecondary)
                    .fixedSize(horizontal: false, vertical: true)
            }
        }
        .padding(IPCASpacing.standard)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(IPCAColors.warningSurface, in: RoundedRectangle(cornerRadius: IPCARadius.medium))
        .accessibilityElement(children: .combine)
    }
}

struct AircraftRow: View {
    let aircraft: AircraftSummary

    var body: some View {
        DetailRow(
            icon: "airplane",
            title: aircraft.registration,
            subtitle: [aircraft.aircraftType, aircraft.displayName]
                .compactMap { $0 }
                .filter { !$0.isEmpty }
                .joined(separator: " · ")
        )
    }
}

struct PersonRow: View {
    let person: CrewMember

    var body: some View {
        DetailRow(
            icon: person.role.lowercased().contains("instructor") ? "person.badge.shield.checkmark" : "person.fill",
            title: person.personName,
            subtitle: person.role.capitalized
        )
    }
}

struct DetailRow: View {
    let icon: String
    let title: String
    let subtitle: String?

    var body: some View {
        HStack(spacing: IPCASpacing.medium) {
            Image(systemName: icon)
                .font(.system(size: 16, weight: .semibold))
                .foregroundStyle(IPCAColors.blue)
                .frame(width: 38, height: 38)
                .background(IPCAColors.blue.opacity(0.1), in: RoundedRectangle(cornerRadius: 11))
                .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.body.weight(.semibold))
                    .foregroundStyle(IPCAColors.text)
                if let subtitle, !subtitle.isEmpty {
                    Text(subtitle)
                        .font(.subheadline)
                        .foregroundStyle(IPCAColors.textSecondary)
                }
            }
            Spacer()
        }
        .accessibilityElement(children: .combine)
    }
}

struct RouteView: View {
    let route: ReservationRoute

    var body: some View {
        if route.airportChain.count >= 2 {
            VStack(alignment: .leading, spacing: IPCASpacing.medium) {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(Array(route.airportChain.enumerated()), id: \.offset) { index, airport in
                            Text(airport)
                                .font(.subheadline.monospaced().weight(.bold))
                                .foregroundStyle(IPCAColors.navy)
                                .padding(.horizontal, 11)
                                .padding(.vertical, 8)
                                .background(IPCAColors.surfaceMuted, in: Capsule())
                            if index < route.airportChain.count - 1 {
                                Image(systemName: "arrow.right")
                                    .font(.caption.weight(.bold))
                                    .foregroundStyle(IPCAColors.textSecondary)
                            }
                        }
                    }
                }
                if route.legs.count > 1 {
                    Text("\(route.legs.count) planned legs")
                        .font(.caption)
                        .foregroundStyle(IPCAColors.textSecondary)
                }
            }
            .accessibilityElement(children: .ignore)
            .accessibilityLabel("Route \(route.airportChain.joined(separator: " to "))")
        }
    }
}

struct WeekDateStrip: View {
    let dates: [Date]
    let selectedDate: Date
    let todayKey: String
    let clock: SchedulerClock
    let select: (Date) -> Void

    var body: some View {
        HStack(spacing: 5) {
            ForEach(dates, id: \.timeIntervalSinceReferenceDate) { date in
                let selected = clock.calendar.isDate(date, inSameDayAs: selectedDate)
                let isToday = clock.dayKey(for: date) == todayKey
                Button {
                    select(date)
                } label: {
                    VStack(spacing: 7) {
                        Text(clock.weekdayNarrow(date))
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(selected ? .white.opacity(0.72) : IPCAColors.textSecondary)
                        Text(clock.dayNumber(date))
                        .font(.system(.body, design: .rounded, weight: .bold))
                        .foregroundStyle(selected ? .white : IPCAColors.text)
                        Circle()
                            .fill(isToday ? (selected ? Color.white : IPCAColors.blue) : .clear)
                            .frame(width: 4, height: 4)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 9)
                    .background(
                        Group {
                            if selected {
                                RoundedRectangle(cornerRadius: 14, style: .continuous)
                                    .fill(IPCAColors.brandGradient)
                            }
                        }
                    )
                }
                .buttonStyle(.plain)
                .frame(minWidth: 42, minHeight: 56)
                .accessibilityLabel(clock.longDate(date))
                .accessibilityAddTraits(selected ? .isSelected : [])
            }
        }
    }
}

struct EmptyScheduleView: View {
    let nextReservation: SchedulerReservation?
    let clock: SchedulerClock

    var body: some View {
        VStack(spacing: IPCASpacing.medium) {
            Image(systemName: "calendar.badge.checkmark")
                .font(.system(size: 36, weight: .medium))
                .foregroundStyle(IPCAColors.blue)
            Text("No training scheduled today")
                .font(.system(.headline, design: .rounded, weight: .bold))
                .foregroundStyle(IPCAColors.text)
            if let nextReservation,
               let date = clock.date(fromLocal: nextReservation.startLocal) {
                Text("Your next reservation is \(clock.weekdayLong(date)) at \(clock.time(nextReservation.startLocal)).")
                    .font(.subheadline)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(IPCAColors.textSecondary)
                Text("\(nextReservation.aircraft.registration) · \(nextReservation.title)")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(IPCAColors.navy)
            } else {
                Text("Your schedule is clear for now.")
                    .font(.subheadline)
                    .foregroundStyle(IPCAColors.textSecondary)
            }
        }
        .padding(IPCASpacing.xLarge)
        .frame(maxWidth: .infinity)
        .ipcaCard()
        .accessibilityElement(children: .combine)
    }
}
