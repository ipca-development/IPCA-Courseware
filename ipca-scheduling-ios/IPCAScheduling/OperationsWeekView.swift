import SwiftUI

struct OperationsWeekView: View {
    @EnvironmentObject private var session: SchedulingSession

    @Binding var lens: OperationsLens
    let openDay: (Date) -> Void

    private let resourceWidth: CGFloat = 126
    private let compactResourceWidth: CGFloat = 108
    private let dayStartMinute = 6.0 * 60.0
    private let dayEndMinute = 22.0 * 60.0

    private var week: [Date] {
        session.clock.week(containing: session.selectedDate)
    }

    private var weekReservations: [SchedulerReservation] {
        let keys = Set(week.map { session.clock.dayKey(for: $0) })
        return session.reservations.filter {
            keys.contains($0.localDateKey) && !$0.isCancelled
        }
    }

    var body: some View {
        GeometryReader { proxy in
            let compact = proxy.size.width < 950
            VStack(spacing: 0) {
                header(compact: compact)
                weekSummary
                weekBoard(compact: compact)
                    .padding(.horizontal, 12)
                    .padding(.top, 8)
                    .padding(.bottom, 10)
            }
            .background(OperationsStyle.canvas)
        }
    }

    private func header(compact: Bool) -> some View {
        HStack(spacing: compact ? 8 : 14) {
            VStack(alignment: .leading, spacing: 2) {
                Text("WEEK")
                    .font(.system(size: 9, weight: .bold))
                    .tracking(1.1)
                    .foregroundStyle(IPCAColors.blue)
                Text(weekTitle)
                    .font(.system(size: compact ? 15 : 17, weight: .bold))
                    .foregroundStyle(OperationsStyle.ink)
            }

            Spacer(minLength: 6)

            weekNavigation
            lensControl(compact: compact)
        }
        .padding(.horizontal, 14)
        .frame(height: 62)
        .background(Color.white)
        .overlay(alignment: .bottom) {
            Rectangle().fill(OperationsStyle.line).frame(height: 1)
        }
    }

    private var weekNavigation: some View {
        HStack(spacing: 0) {
            headerButton("chevron.left", label: "Previous week") {
                session.moveWeek(by: -1)
            }
            Rectangle().fill(OperationsStyle.line).frame(width: 1, height: 20)
            Button("Today") { session.goToToday() }
                .buttonStyle(.plain)
                .font(.system(size: 11, weight: .semibold))
                .foregroundStyle(OperationsStyle.ink)
                .padding(.horizontal, 10)
                .frame(height: 32)
            Rectangle().fill(OperationsStyle.line).frame(width: 1, height: 20)
            headerButton("chevron.right", label: "Next week") {
                session.moveWeek(by: 1)
            }
        }
        .background(Color.white)
        .clipShape(RoundedRectangle(cornerRadius: 9))
        .overlay(
            RoundedRectangle(cornerRadius: 9)
                .stroke(OperationsStyle.line)
        )
    }

    private func lensControl(compact: Bool) -> some View {
        HStack(spacing: 2) {
            ForEach(OperationsLens.allCases) { item in
                Button {
                    lens = item
                    UISelectionFeedbackGenerator().selectionChanged()
                } label: {
                    Text(item.title)
                        .font(.system(size: compact ? 9 : 10, weight: .semibold))
                        .lineLimit(1)
                        .frame(maxWidth: .infinity)
                        .frame(height: 28)
                        .foregroundStyle(lens == item ? Color.white : OperationsStyle.ink)
                        .background(
                            lens == item ? OperationsStyle.ink : Color.clear,
                            in: RoundedRectangle(cornerRadius: 7)
                        )
                }
                .buttonStyle(.plain)
            }
        }
        .padding(2)
        .frame(width: compact ? 188 : 226)
        .background(Color(hex: 0xF1F3F6), in: RoundedRectangle(cornerRadius: 9))
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Week resource lens")
    }

    private var weekSummary: some View {
        HStack(spacing: 0) {
            summaryItem("\(weekReservations.count)", "Reservations")
            summaryDivider
            summaryItem("\(usedResourceCount)", lens.title)
            if lens != .instructors {
                summaryDivider
                summaryItem("\(instructorCount)", "Instructors")
            }
            if warningCount > 0 {
                summaryDivider
                summaryItem("\(warningCount)", "Warnings", warning: true)
            }
            Spacer()
            Text("06:00–22:00 operational window")
                .font(.system(size: 9, weight: .medium))
                .foregroundStyle(OperationsStyle.muted)
        }
        .padding(.horizontal, 16)
        .frame(height: 38)
        .background(Color.white)
        .overlay(alignment: .bottom) {
            Rectangle().fill(OperationsStyle.line).frame(height: 1)
        }
    }

    private func weekBoard(compact: Bool) -> some View {
        let rows = projectedRows
        let labelWidth = compact ? compactResourceWidth : resourceWidth

        return VStack(spacing: 0) {
            HStack(spacing: 0) {
                resourceCorner(width: labelWidth)
                ForEach(week, id: \.self) { day in
                    dayHeader(day)
                }
            }
            .frame(height: compact ? 78 : 84)

            Rectangle().fill(OperationsStyle.gridMajor).frame(height: 1)

            ScrollView(.vertical) {
                LazyVStack(spacing: 0) {
                    ForEach(rows) { row in
                        HStack(spacing: 0) {
                            resourceLabel(row.resource, width: labelWidth, compact: compact)
                            ForEach(week, id: \.self) { day in
                                dayResourceCell(row: row, day: day, compact: compact)
                            }
                        }
                        .frame(height: compact ? 62 : 68)
                    }
                }
            }
        }
        .background(Color.white)
        .clipShape(RoundedRectangle(cornerRadius: 14))
        .overlay(
            RoundedRectangle(cornerRadius: 14)
                .stroke(OperationsStyle.line)
        )
    }

    private func resourceCorner(width: CGFloat) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(lens.title.uppercased())
                .font(.system(size: 9, weight: .bold))
                .tracking(0.65)
                .foregroundStyle(OperationsStyle.ink)
            Text("RESOURCE")
                .font(.system(size: 8, weight: .semibold))
                .tracking(0.6)
                .foregroundStyle(OperationsStyle.muted)
        }
        .padding(.horizontal, 10)
        .frame(width: width)
        .frame(maxHeight: .infinity, alignment: .leading)
        .background(Color(hex: 0xF7F8FA))
        .overlay(alignment: .trailing) {
            Rectangle().fill(OperationsStyle.gridMajor).frame(width: 1)
        }
    }

    private func dayHeader(_ day: Date) -> some View {
        let key = session.clock.dayKey(for: day)
        let reservations = reservations(on: day)
        let resources = resourcesUsed(on: day)
        let warnings = warnings(on: day)
        let isToday = key == session.todayKey

        return Button {
            openDay(day)
        } label: {
            VStack(spacing: 3) {
                HStack(spacing: 4) {
                    Text(day.formatted(
                        .dateTime
                            .weekday(.abbreviated)
                            .locale(Locale(identifier: "en_US"))
                    ).uppercased())
                    Text(day.formatted(.dateTime.day()))
                }
                .font(.system(size: 10, weight: .bold))
                .foregroundStyle(isToday ? IPCAColors.blue : OperationsStyle.ink)

                if reservations.isEmpty {
                    Text("No reservations")
                        .font(.system(size: 8, weight: .medium))
                        .foregroundStyle(OperationsStyle.muted)
                } else {
                    Text(
                        "\(reservations.count) \(reservations.count == 1 ? "reservation" : "reservations") · \(resources) \(resourceMetricLabel(resources))"
                    )
                        .font(.system(size: 8, weight: .medium))
                        .foregroundStyle(OperationsStyle.muted)
                        .lineLimit(1)
                        .minimumScaleFactor(0.7)
                }

                if warnings > 0 {
                    Label("\(warnings) warning\(warnings == 1 ? "" : "s")", systemImage: "exclamationmark.triangle.fill")
                        .font(.system(size: 7, weight: .semibold))
                        .foregroundStyle(IPCAColors.warning)
                } else if isToday {
                    Text("TODAY")
                        .font(.system(size: 7, weight: .bold))
                        .tracking(0.5)
                        .foregroundStyle(IPCAColors.blue)
                } else {
                    Text(" ")
                        .font(.system(size: 7))
                }

                HStack {
                    Text("06")
                    Spacer()
                    Text("12")
                    Spacer()
                    Text("18")
                    Spacer()
                    Text("22")
                }
                .font(.system(size: 6, weight: .medium))
                .foregroundStyle(OperationsStyle.muted.opacity(0.8))
                .padding(.horizontal, 5)
            }
            .padding(.horizontal, 4)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(isToday ? IPCAColors.blue.opacity(0.055) : Color.white)
            .overlay(alignment: .top) {
                if isToday {
                    Rectangle().fill(IPCAColors.blue).frame(height: 3)
                }
            }
            .overlay(alignment: .leading) {
                Rectangle().fill(OperationsStyle.gridMajor).frame(width: 1)
            }
        }
        .buttonStyle(.plain)
        .accessibilityLabel(dayHeaderAccessibility(day))
        .accessibilityHint("Open detailed schedule")
    }

    private func resourceLabel(
        _ resource: OperationsResource,
        width: CGFloat,
        compact: Bool
    ) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(resource.primaryLabel)
                .font(.system(size: compact ? 9 : 10, weight: .bold))
                .foregroundStyle(OperationsStyle.ink)
                .lineLimit(1)
            if let secondary = resource.secondaryLabel {
                Text(secondary)
                    .font(.system(size: 7))
                    .foregroundStyle(OperationsStyle.muted)
                    .lineLimit(1)
            }
        }
        .padding(.horizontal, 10)
        .frame(width: width)
        .frame(maxHeight: .infinity, alignment: .leading)
        .background(Color(hex: 0xFAFBFC))
        .overlay(alignment: .trailing) {
            Rectangle().fill(OperationsStyle.gridMajor).frame(width: 1)
        }
        .overlay(alignment: .bottom) {
            Rectangle().fill(OperationsStyle.gridMajor).frame(height: 1)
        }
    }

    private func dayResourceCell(
        row: OperationsResourceRow,
        day: Date,
        compact: Bool
    ) -> some View {
        let key = session.clock.dayKey(for: day)
        let items = row.items.filter { $0.reservation.localDateKey == key }
        let isToday = key == session.todayKey

        return Button {
            openDay(day)
        } label: {
            GeometryReader { proxy in
                ZStack(alignment: .topLeading) {
                    ForEach([0.0, 0.375, 0.75, 1.0], id: \.self) { fraction in
                        Rectangle()
                            .fill(OperationsStyle.gridMinor)
                            .frame(width: 0.5)
                            .offset(x: proxy.size.width * fraction)
                    }
                    ForEach(positioned(items, dayKey: key), id: \.item.id) { positioned in
                        miniReservation(
                            positioned.item.reservation,
                            width: proxy.size.width,
                            lane: positioned.lane,
                            compact: compact
                        )
                    }
                }
            }
            .padding(.horizontal, 4)
            .padding(.vertical, compact ? 14 : 17)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(isToday ? IPCAColors.blue.opacity(0.035) : Color.white)
            .overlay(alignment: .leading) {
                Rectangle().fill(OperationsStyle.gridMajor).frame(width: 1)
            }
            .overlay(alignment: .bottom) {
                Rectangle().fill(OperationsStyle.gridMajor).frame(height: 1)
            }
        }
        .buttonStyle(.plain)
        .accessibilityLabel(
            "\(row.resource.primaryLabel), \(items.count) reservations on \(session.clock.weekdayLong(day))"
        )
        .accessibilityHint("Open detailed schedule")
    }

    private func miniReservation(
        _ reservation: SchedulerReservation,
        width: CGFloat,
        lane: Int,
        compact: Bool
    ) -> some View {
        let dayKey = reservation.localDateKey
        let start = OperationalLocalClock.minute(
            reservation.startLocal,
            relativeTo: dayKey,
            boundary: .start
        ) ?? dayStartMinute
        let end = OperationalLocalClock.minute(
            reservation.endLocal,
            relativeTo: dayKey,
            boundary: .end
        ) ?? start + 30
        let clampedStart = min(dayEndMinute, max(dayStartMinute, start))
        let clampedEnd = min(dayEndMinute, max(clampedStart + 1, end))
        let span = dayEndMinute - dayStartMinute
        let x = width * CGFloat((clampedStart - dayStartMinute) / span)
        let blockWidth = max(3, width * CGFloat((clampedEnd - clampedStart) / span))
        let hasWarning = warningCount(for: reservation) > 0

        return RoundedRectangle(cornerRadius: 2.5)
            .fill(hasWarning ? IPCAColors.warningSurface : miniFill(reservation))
            .overlay(
                RoundedRectangle(cornerRadius: 2.5)
                    .stroke(
                        hasWarning ? IPCAColors.warning : miniBorder(reservation),
                        lineWidth: 0.7
                    )
            )
            .frame(width: blockWidth, height: compact ? 6 : 7)
            .offset(x: x, y: CGFloat(min(lane, 3)) * (compact ? 7 : 8))
    }

    private func positioned(
        _ items: [OperationsProjectionItem],
        dayKey: String
    ) -> [(item: OperationsProjectionItem, lane: Int)] {
        let intervals = items.compactMap { item -> OverlapLaneInput<String>? in
            guard let start = OperationalLocalClock.minute(
                item.startLocal,
                relativeTo: dayKey,
                boundary: .start
            ), let end = OperationalLocalClock.minute(
                item.endLocal,
                relativeTo: dayKey,
                boundary: .end
            ) else { return nil }
            return OverlapLaneInput(id: item.id, start: start, end: end)
        }
        let lanes = Dictionary(
            uniqueKeysWithValues: OverlapLaneLayout.layout(intervals).map { ($0.id, $0.lane) }
        )
        return items.map { ($0, lanes[$0.id] ?? 0) }
    }

    private var projectedRows: [OperationsResourceRow] {
        let sorting = OperationsRowSortingStrategy.comparator {
            $0.primaryLabel.localizedStandardCompare($1.primaryLabel) == .orderedAscending
        }
        let projection = OperationsProjector.project(
            reservations: weekReservations,
            lens: lens,
            sorting: sorting
        )
        let scheduled = Dictionary(uniqueKeysWithValues: projection.rows.map { ($0.id, $0) })
        let catalogRows = catalogResources.map { resource in
            scheduled[resource.id] ?? OperationsResourceRow(resource: resource, items: [])
        }
        let catalogIDs = Set(catalogRows.map(\.id))
        return sorting(catalogRows + projection.rows.filter { !catalogIDs.contains($0.id) })
    }

    private var catalogResources: [OperationsResource] {
        switch lens {
        case .aircraft:
            return session.aircraftResources.compactMap { item -> OperationsResource? in
                guard let registration = item.registration, !registration.isEmpty else { return nil }
                return OperationsResource(
                    id: .aircraft(item.id),
                    lens: lens,
                    primaryLabel: registration,
                    secondaryLabel: item.displayName ?? item.aircraftType,
                    tertiaryLabel: item.aircraftType
                )
            }
        case .instructors, .students:
            let role = lens == .instructors ? "instructor" : "student"
            return session.personResources.compactMap { item -> OperationsResource? in
                guard item.role?.lowercased().contains(role) == true,
                      let name = item.displayName,
                      !name.isEmpty else { return nil }
                return OperationsResource(
                    id: .person(id: item.id),
                    lens: lens,
                    primaryLabel: name,
                    secondaryLabel: nil
                )
            }
        }
    }

    private func reservations(on day: Date) -> [SchedulerReservation] {
        let key = session.clock.dayKey(for: day)
        return weekReservations.filter { $0.localDateKey == key }
    }

    private func resourcesUsed(on day: Date) -> Int {
        let key = session.clock.dayKey(for: day)
        return Set(
            projectedRows
                .flatMap(\.items)
                .filter { $0.reservation.localDateKey == key }
                .map(\.resourceID)
        ).count
    }

    private func warnings(on day: Date) -> Int {
        reservations(on: day).reduce(0) { $0 + warningCount(for: $1) }
    }

    private func warningCount(for reservation: SchedulerReservation) -> Int {
        reservation.validation?.warnings.count
            ?? session.detailWarnings[reservation.id]?.count
            ?? 0
    }

    private var usedResourceCount: Int {
        Set(
            projectedRows
                .flatMap(\.items)
                .map(\.resourceID)
        ).count
    }

    private var instructorCount: Int {
        Set(
            weekReservations
                .flatMap(\.crew)
                .filter { $0.role.lowercased().contains("instructor") }
                .compactMap(\.personID)
        ).count
    }

    private var warningCount: Int {
        weekReservations.reduce(0) { $0 + warningCount(for: $1) }
    }

    private func resourceMetricLabel(_ count: Int) -> String {
        switch lens {
        case .aircraft: "aircraft"
        case .instructors: count == 1 ? "instructor" : "instructors"
        case .students: count == 1 ? "student" : "students"
        }
    }

    private func miniFill(_ reservation: SchedulerReservation) -> Color {
        switch reservation.status.lowercased() {
        case "claimed", "active": IPCAColors.blue.opacity(0.19)
        case "completed": IPCAColors.success.opacity(0.13)
        case "cancelled": OperationsStyle.muted.opacity(0.08)
        default: Color(hex: 0xEAF2FC)
        }
    }

    private func miniBorder(_ reservation: SchedulerReservation) -> Color {
        switch reservation.status.lowercased() {
        case "claimed", "active": IPCAColors.blue.opacity(0.8)
        case "completed": IPCAColors.success.opacity(0.6)
        case "cancelled": OperationsStyle.muted.opacity(0.35)
        default: Color(hex: 0x78A5DC)
        }
    }

    private func summaryItem(
        _ value: String,
        _ label: String,
        warning: Bool = false
    ) -> some View {
        HStack(spacing: 5) {
            if warning {
                Image(systemName: "exclamationmark.triangle.fill")
                    .font(.system(size: 8))
            }
            Text(value)
                .font(.system(size: 13, weight: .bold))
            Text(label)
                .font(.system(size: 8, weight: .medium))
        }
        .foregroundStyle(warning ? IPCAColors.warning : OperationsStyle.ink)
    }

    private var summaryDivider: some View {
        Rectangle()
            .fill(OperationsStyle.line)
            .frame(width: 1, height: 22)
            .padding(.horizontal, 12)
    }

    private func headerButton(
        _ icon: String,
        label: String,
        action: @escaping () -> Void
    ) -> some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.system(size: 10, weight: .semibold))
                .frame(width: 30, height: 32)
        }
        .buttonStyle(.plain)
        .foregroundStyle(OperationsStyle.ink)
        .accessibilityLabel(label)
    }

    private var weekTitle: String {
        guard let first = week.first, let last = week.last else { return "" }
        return "\(session.clock.monthDay(first)) – \(session.clock.monthDay(last))"
    }

    private func dayHeaderAccessibility(_ day: Date) -> String {
        let count = reservations(on: day).count
        let warningTotal = warnings(on: day)
        return [
            session.clock.weekdayLong(day),
            session.clock.monthDay(day),
            "\(count) reservations",
            warningTotal > 0 ? "\(warningTotal) warnings" : nil
        ]
        .compactMap { $0 }
        .joined(separator: ", ")
    }
}
