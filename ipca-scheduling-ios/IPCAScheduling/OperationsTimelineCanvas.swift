import SwiftUI
import Combine

struct OperationsTimelineCanvas: View {
    @EnvironmentObject private var session: SchedulingSession
    let lens: OperationsLens
    let scale: TimelineScale
    @Binding var selectedReservation: SchedulerReservation?
    let focusReservationID: String?
    let resourceWidth: CGFloat

    @State private var hasPositionedInitially = false
    @State private var currentTime = Date()

    private let axisHeight: CGFloat = 52
    private let metrics = OverlapLaneMetrics(
        minimumRowHeight: 88,
        laneHeight: 68,
        laneSpacing: 4,
        verticalPadding: 10
    )

    var body: some View {
        GeometryReader { viewport in
            let dayKey = session.clock.dayKey(for: session.selectedDate)
            let rows = projectedRows(dayKey: dayKey)
            let window = TimelineDayWindow.operationalDay(
                dayKey: dayKey,
                reservations: session.selectedDayReservations
            )
            let availableTimelineWidth = max(480, viewport.size.width - resourceWidth)
            let geometry = TimelineGeometry(
                window: window,
                viewportWidth: availableTimelineWidth,
                scale: scale
            )
            let layouts = rowLayouts(rows: rows, window: window)
            let positioned = positionedRows(rows: rows, layouts: layouts)
            let contentHeight = axisHeight + positioned.reduce(0) { $0 + $1.height }

            ScrollViewReader { reader in
                ScrollView([.horizontal, .vertical]) {
                    ZStack(alignment: .topLeading) {
                        astronomyBackground(
                            geometry: geometry,
                            contentHeight: contentHeight
                        )

                        timelineGrid(
                            geometry: geometry,
                            rows: positioned,
                            contentHeight: contentHeight
                        )

                        astronomyMarkers(
                            geometry: geometry,
                            contentHeight: contentHeight
                        )

                        ForEach(positioned) { row in
                            resourceLabel(row)
                                .frame(width: resourceWidth, height: row.height)
                                .position(
                                    x: resourceWidth / 2,
                                    y: axisHeight + row.y + row.height / 2
                                )
                                .visualEffect { content, proxy in
                                    content.offset(
                                        x: max(
                                            0,
                                            -proxy.frame(in: .scrollView(axis: .horizontal)).minX
                                        )
                                    )
                                }
                                .zIndex(4)

                            ForEach(row.items) { item in
                                if let frame = geometry.frame(for: item.reservation) {
                                    reservationButton(
                                        item: item,
                                        frame: frame,
                                        row: row
                                    )
                                }
                            }
                        }

                        currentTimeLine(
                            geometry: geometry,
                            contentHeight: contentHeight
                        )
                        .zIndex(3)

                        timeAxis(geometry: geometry)
                            .frame(width: geometry.contentWidth, height: axisHeight)
                            .position(
                                x: resourceWidth + geometry.contentWidth / 2,
                                y: axisHeight / 2
                            )
                            .visualEffect { content, proxy in
                                content.offset(
                                    y: max(
                                        0,
                                        -proxy.frame(in: .scrollView(axis: .vertical)).minY
                                    )
                                )
                            }
                            .zIndex(5)

                        topLeftCorner
                            .frame(width: resourceWidth, height: axisHeight)
                            .position(
                                x: resourceWidth / 2,
                                y: axisHeight / 2
                            )
                            .visualEffect { content, proxy in
                                let horizontal = proxy.frame(
                                    in: .scrollView(axis: .horizontal)
                                ).minX
                                let vertical = proxy.frame(
                                    in: .scrollView(axis: .vertical)
                                ).minY
                                return content.offset(
                                    x: max(0, -horizontal),
                                    y: max(0, -vertical)
                                )
                            }
                            .zIndex(6)

                        initialPositionAnchor(geometry: geometry)
                    }
                    .frame(
                        width: resourceWidth + geometry.contentWidth,
                        height: max(viewport.size.height, contentHeight),
                        alignment: .topLeading
                    )
                }
                .coordinateSpace(name: "operationsBoard")
                .scrollIndicators(.visible)
                .onAppear {
                    positionInitially(reader: reader, geometry: geometry)
                }
                .onChange(of: focusReservationID) { _, newValue in
                    guard let newValue else { return }
                    DispatchQueue.main.async {
                        withAnimation(.easeInOut(duration: 0.25)) {
                            reader.scrollTo(initialAnchorID, anchor: .top)
                        }
                    }
                }
                .onChange(of: scale) { _, _ in
                    hasPositionedInitially = false
                    positionInitially(reader: reader, geometry: geometry)
                }
                .onChange(of: dayKey) { _, _ in
                    hasPositionedInitially = false
                    positionInitially(reader: reader, geometry: geometry)
                }
                .onReceive(
                    Timer.publish(every: 60, on: .main, in: .common).autoconnect()
                ) { date in
                    if session.previewScreen == nil {
                        currentTime = date
                    }
                }
            }
            .background(IPCAColors.surface)
            .clipShape(RoundedRectangle(cornerRadius: 14))
            .overlay(
                RoundedRectangle(cornerRadius: 14)
                    .stroke(OperationsStyle.line)
            )
        }
    }

    private func projectedRows(dayKey: String) -> [OperationsResourceRow] {
        let sorting = OperationsRowSortingStrategy.comparator {
            $0.primaryLabel.localizedStandardCompare($1.primaryLabel) == .orderedAscending
        }
        let base = OperationsProjector.project(
            reservations: session.selectedDayReservations,
            lens: lens,
            dayKey: dayKey,
            sorting: sorting
        )
        let existing = Dictionary(uniqueKeysWithValues: base.rows.map { ($0.id, $0) })
        let catalog = catalogResources()
        let mergedCatalogRows = catalog.map { resource in
            existing[resource.id] ?? OperationsResourceRow(resource: resource, items: [])
        }
        let catalogIDs = Set(mergedCatalogRows.map(\.id))
        let scheduleOnly = base.rows.filter { !catalogIDs.contains($0.id) }
        return sorting(mergedCatalogRows + scheduleOnly)
    }

    private func catalogResources() -> [OperationsResource] {
        switch lens {
        case .aircraft:
            return session.aircraftResources.compactMap { item -> OperationsResource? in
                guard let registration = item.registration, !registration.isEmpty else { return nil }
                return OperationsResource(
                    id: .aircraft(item.id),
                    lens: .aircraft,
                    primaryLabel: registration,
                    secondaryLabel: item.displayName ?? item.aircraftType,
                    tertiaryLabel: item.displayName == nil ? nil : item.aircraftType
                )
            }
        case .instructors:
            return session.personResources.compactMap { item -> OperationsResource? in
                let role = item.role?.lowercased() ?? ""
                guard role.contains("instructor"), let name = item.displayName, !name.isEmpty else {
                    return nil
                }
                return OperationsResource(
                    id: .person(id: item.id),
                    lens: .instructors,
                    primaryLabel: name,
                    secondaryLabel: "Instructor"
                )
            }
        case .students:
            return session.personResources.compactMap { item -> OperationsResource? in
                guard item.role?.lowercased() == "student",
                      let name = item.displayName, !name.isEmpty else {
                    return nil
                }
                return OperationsResource(
                    id: .person(id: item.id),
                    lens: .students,
                    primaryLabel: name,
                    secondaryLabel: "Student"
                )
            }
        }
    }

    private func rowLayouts(
        rows: [OperationsResourceRow],
        window: TimelineDayWindow
    ) -> [OverlapRowLayout<OperationsResourceID, String>] {
        let inputs: [OverlapRowInput<OperationsResourceID, String>] = rows.map { row in
            OverlapRowInput(
                id: row.id,
                intervals: row.items.compactMap { item in
                    guard let start = OperationalLocalClock.minute(
                        item.startLocal,
                        relativeTo: window.dayKey,
                        boundary: .start
                    ), let end = OperationalLocalClock.minute(
                        item.endLocal,
                        relativeTo: window.dayKey,
                        boundary: .end
                    ) else { return nil }
                    return OverlapLaneInput(id: item.id, start: start, end: end)
                }
            )
        }
        return OverlapLaneLayout.layoutRows(inputs, metrics: metrics)
    }

    private func positionedRows(
        rows: [OperationsResourceRow],
        layouts: [OverlapRowLayout<OperationsResourceID, String>]
    ) -> [PositionedOperationsRow] {
        let layoutByID = Dictionary(uniqueKeysWithValues: layouts.map { ($0.id, $0) })
        var y: CGFloat = 0
        return rows.map { row in
            let layout = layoutByID[row.id]
            let height = CGFloat(layout?.height ?? metrics.minimumRowHeight)
            let placementByID = Dictionary(
                uniqueKeysWithValues: (layout?.placements ?? []).map { ($0.id, $0) }
            )
            let positioned = PositionedOperationsRow(
                resourceRow: row,
                y: y,
                height: height,
                items: row.items.map {
                    PositionedOperationsItem(
                        projection: $0,
                        lane: placementByID[$0.id]?.lane ?? 0
                    )
                }
            )
            y += height
            return positioned
        }
    }

    private func astronomyBackground(
        geometry: TimelineGeometry,
        contentHeight: CGFloat
    ) -> some View {
        let layout = astronomyLayout(geometry: geometry)
        let bodyHeight = max(0, contentHeight - axisHeight)
        let nightShade = Color(hex: 0xE7EDF4).opacity(0.72)

        return ZStack(alignment: .topLeading) {
            Color.white
            if let region = layout?.morningNight {
                Rectangle()
                    .fill(nightShade)
                    .frame(width: region.width, height: bodyHeight)
                    .offset(x: region.x)
            }
            if let region = layout?.morningTwilight {
                Rectangle()
                    .fill(
                        LinearGradient(
                            colors: [nightShade, Color.clear],
                            startPoint: .leading,
                            endPoint: .trailing
                        )
                    )
                    .frame(width: region.width, height: bodyHeight)
                    .offset(x: region.x)
            }
            if let region = layout?.eveningTwilight {
                Rectangle()
                    .fill(
                        LinearGradient(
                            colors: [Color.clear, nightShade],
                            startPoint: .leading,
                            endPoint: .trailing
                        )
                    )
                    .frame(width: region.width, height: bodyHeight)
                    .offset(x: region.x)
            }
            if let region = layout?.eveningNight {
                Rectangle()
                    .fill(nightShade)
                    .frame(width: region.width, height: bodyHeight)
                    .offset(x: region.x)
            }
        }
        .frame(width: geometry.contentWidth, height: bodyHeight)
        .position(
            x: resourceWidth + geometry.contentWidth / 2,
            y: axisHeight + bodyHeight / 2
        )
        .accessibilityHidden(true)
    }

    private func astronomyMarkers(
        geometry: TimelineGeometry,
        contentHeight: CGFloat
    ) -> some View {
        let layout = astronomyLayout(geometry: geometry)
        let bodyHeight = max(0, contentHeight - axisHeight)
        let markerColor = Color(hex: 0x718096).opacity(0.72)

        return Canvas { context, _ in
            func stroke(_ x: Double?, dashed: Bool) {
                guard let x else { return }
                var path = Path()
                path.move(to: CGPoint(x: x, y: 0))
                path.addLine(to: CGPoint(x: x, y: bodyHeight))
                context.stroke(
                    path,
                    with: .color(markerColor),
                    style: StrokeStyle(
                        lineWidth: dashed ? 0.8 : 1,
                        dash: dashed ? [4, 4] : []
                    )
                )
            }
            stroke(layout?.morningCivilTwilightX, dashed: true)
            stroke(layout?.sunriseX, dashed: false)
            stroke(layout?.sunsetX, dashed: false)
            stroke(layout?.eveningCivilTwilightX, dashed: true)
        }
        .frame(width: geometry.contentWidth, height: bodyHeight)
        .position(
            x: resourceWidth + geometry.contentWidth / 2,
            y: axisHeight + bodyHeight / 2
        )
        .accessibilityHidden(true)
    }

    private func currentTimeLine(
        geometry: TimelineGeometry,
        contentHeight: CGFloat
    ) -> some View {
        Canvas { context, _ in
            guard let nowX = currentTimeX(geometry: geometry) else { return }
            let x = resourceWidth + nowX
            var path = Path()
            path.move(to: CGPoint(x: x, y: axisHeight))
            path.addLine(to: CGPoint(x: x, y: contentHeight))
            context.stroke(
                path,
                with: .color(IPCAColors.blue.opacity(0.9)),
                lineWidth: 1.2
            )
        }
        .frame(
            width: resourceWidth + geometry.contentWidth,
            height: contentHeight
        )
        .accessibilityHidden(true)
    }

    private func astronomyLayout(
        geometry: TimelineGeometry
    ) -> TimelineAstronomyLayout? {
        guard let day = session.astronomyDays.first(where: {
            $0.date == geometry.window.dayKey
        }) else {
            return nil
        }
        return TimelineAstronomyLayout.make(day: day, geometry: geometry)
    }

    private func timelineGrid(
        geometry: TimelineGeometry,
        rows: [PositionedOperationsRow],
        contentHeight: CGFloat
    ) -> some View {
        Canvas { context, _ in
            let timelineX = resourceWidth

            for minute in stride(
                from: geometry.window.startMinute,
                through: geometry.window.endMinute,
                by: 30
            ) {
                let x = timelineX + CGFloat(geometry.x(forMinute: minute))
                var path = Path()
                path.move(to: CGPoint(x: x, y: axisHeight))
                path.addLine(to: CGPoint(x: x, y: contentHeight))
                let major = Int(minute).isMultiple(of: 60)
                context.stroke(
                    path,
                    with: .color(major ? OperationsStyle.gridMajor : OperationsStyle.gridMinor),
                    lineWidth: major ? 0.8 : 0.45
                )
            }

            for row in rows {
                let y = axisHeight + row.y + row.height
                var path = Path()
                path.move(to: CGPoint(x: 0, y: y))
                path.addLine(to: CGPoint(x: resourceWidth + geometry.contentWidth, y: y))
                context.stroke(path, with: .color(OperationsStyle.gridMajor), lineWidth: 0.7)
            }

        }
        .frame(width: resourceWidth + geometry.contentWidth, height: contentHeight)
        .accessibilityHidden(true)
    }

    private func timeAxis(geometry: TimelineGeometry) -> some View {
        ZStack(alignment: .topLeading) {
            Rectangle().fill(IPCAColors.surface)
            astronomyAxisMarkers(geometry: geometry)
            ForEach(hourMarks(geometry.window), id: \.self) { minute in
                let x = CGFloat(
                    geometry.x(forMinute: minute)
                        + geometry.pointsPerMinute * 30
                )
                Text(hourLabel(minute))
                    .font(.system(size: 9, weight: .semibold))
                    .foregroundStyle(OperationsStyle.muted)
                    .fixedSize()
                    .frame(width: 42)
                    .offset(x: x - 21, y: 35)
            }
            astronomyAxisLabels(geometry: geometry)
            if let nowX = currentTimeX(geometry: geometry) {
                VStack(spacing: -1) {
                    Text(currentTimeLabel)
                        .font(.system(size: 8, weight: .bold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 4)
                        .frame(height: 16)
                        .background(IPCAColors.blue, in: RoundedRectangle(cornerRadius: 3))
                    Image(systemName: "arrowtriangle.down.fill")
                        .font(.system(size: 6))
                        .foregroundStyle(IPCAColors.blue)
                }
                .fixedSize()
                .offset(x: max(2, nowX - 24), y: 1)
            }
        }
        .overlay(alignment: .bottom) {
            Rectangle().fill(OperationsStyle.gridMajor).frame(height: 1)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Time axis from \(hourLabel(geometry.window.startMinute)) to \(hourLabel(geometry.window.endMinute))")
    }

    private func astronomyAxisMarkers(
        geometry: TimelineGeometry
    ) -> some View {
        let layout = astronomyLayout(geometry: geometry)
        let markerColor = Color(hex: 0x718096).opacity(0.72)
        return Canvas { context, _ in
            let markers: [(Double?, Bool)] = [
                (layout?.morningCivilTwilightX, true),
                (layout?.sunriseX, false),
                (layout?.sunsetX, false),
                (layout?.eveningCivilTwilightX, true),
            ]
            for (x, dashed) in markers {
                guard let x else { continue }
                var path = Path()
                path.move(to: CGPoint(x: x, y: 0))
                path.addLine(to: CGPoint(x: x, y: axisHeight))
                context.stroke(
                    path,
                    with: .color(markerColor),
                    style: StrokeStyle(
                        lineWidth: dashed ? 0.8 : 1,
                        dash: dashed ? [4, 4] : []
                    )
                )
            }
        }
        .frame(width: geometry.contentWidth, height: axisHeight)
        .accessibilityHidden(true)
    }

    @ViewBuilder
    private func astronomyAxisLabels(
        geometry: TimelineGeometry
    ) -> some View {
        if let day = session.astronomyDays.first(where: {
            $0.date == geometry.window.dayKey
        }), let layout = TimelineAstronomyLayout.make(day: day, geometry: geometry) {
            boundaryLabel(
                "Civil Twilight",
                time: day.morningCivilTwilightBegin,
                x: layout.morningCivilTwilightX,
                y: 1,
                geometry: geometry
            )
            boundaryLabel(
                "Sunrise",
                time: day.sunrise,
                x: layout.sunriseX,
                y: 13,
                geometry: geometry
            )
            boundaryLabel(
                "Sunset",
                time: day.sunset,
                x: layout.sunsetX,
                y: 1,
                geometry: geometry
            )
            boundaryLabel(
                "Civil Twilight",
                time: day.eveningCivilTwilightEnd,
                x: layout.eveningCivilTwilightX,
                y: 13,
                geometry: geometry
            )
        }
    }

    @ViewBuilder
    private func boundaryLabel(
        _ title: String,
        time: String?,
        x: Double?,
        y: CGFloat,
        geometry: TimelineGeometry
    ) -> some View {
        if let x, let time {
            Text("\(title) \(session.clock.time(time))")
                .font(.system(size: 6.5, weight: .semibold))
                .foregroundStyle(Color(hex: 0x59697C))
                .lineLimit(1)
                .fixedSize()
                .offset(
                    x: min(max(2, x + 3), geometry.contentWidth - 78),
                    y: y
                )
        }
    }

    private var topLeftCorner: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(lens.title.uppercased())
                .font(.system(size: 9, weight: .bold))
                .tracking(0.5)
                .foregroundStyle(OperationsStyle.ink)
        }
        .padding(.horizontal, 12)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
        .background(IPCAColors.surface)
        .overlay(alignment: .trailing) {
            Rectangle().fill(OperationsStyle.gridMajor).frame(width: 1)
        }
        .overlay(alignment: .bottom) {
            Rectangle().fill(OperationsStyle.gridMajor).frame(height: 1)
        }
        .accessibilityHidden(true)
    }

    private func resourceLabel(_ row: PositionedOperationsRow) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(row.resourceRow.resource.primaryLabel)
                .font(.system(size: lens == .aircraft ? 13 : 12, weight: .semibold))
                .foregroundStyle(OperationsStyle.ink)
                .lineLimit(2)
            if let secondary = row.resourceRow.resource.secondaryLabel, !secondary.isEmpty {
                Text(secondary)
                    .font(.system(size: 10))
                    .foregroundStyle(OperationsStyle.muted)
                    .lineLimit(1)
            }
            if let tertiary = row.resourceRow.resource.tertiaryLabel, !tertiary.isEmpty {
                Text(tertiary)
                    .font(.system(size: 9))
                    .foregroundStyle(OperationsStyle.muted.opacity(0.82))
                    .lineLimit(1)
            }
        }
        .padding(.horizontal, 12)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
        .background(IPCAColors.surface)
        .overlay(alignment: .trailing) {
            Rectangle().fill(OperationsStyle.gridMajor).frame(width: 1)
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(row.resourceRow.resource.primaryLabel), \(row.items.count) reservations")
    }

    private func reservationButton(
        item: PositionedOperationsItem,
        frame: TimelineItemFrame,
        row: PositionedOperationsRow
    ) -> some View {
        let visualWidth = max(2, CGFloat(frame.width) - 4)
        let hitWidth = max(44, visualWidth)
        let warningCount = session.detailWarnings[item.reservation.id]?.count ?? 0
        return Button {
            selectedReservation = item.reservation
            UISelectionFeedbackGenerator().selectionChanged()
        } label: {
            ZStack(alignment: .leading) {
                Color.clear.frame(width: hitWidth, height: CGFloat(metrics.laneHeight))
                OperationsReservationBlock(
                    reservation: item.reservation,
                    lens: lens,
                    resource: row.resourceRow.resource,
                    width: visualWidth,
                    warningCount: warningCount,
                    selected: selectedReservation?.id == item.reservation.id
                )
                .frame(width: visualWidth, height: CGFloat(metrics.laneHeight))
            }
            .frame(width: hitWidth, height: CGFloat(metrics.laneHeight), alignment: .leading)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .position(
            x: resourceWidth + CGFloat(frame.x) + hitWidth / 2 + 2,
            y: axisHeight
                + row.y
                + CGFloat(metrics.verticalPadding)
                + CGFloat(item.lane) * CGFloat(metrics.laneHeight + metrics.laneSpacing)
                + CGFloat(metrics.laneHeight) / 2
        )
        .id("reservation-\(item.reservation.id)")
        .zIndex(selectedReservation?.id == item.reservation.id ? 3 : 2)
        .accessibilityLabel(
            TimelineAccessibilityFormatter(operationalTimezone: session.operationalTimezone)
                .summary(
                    for: item.reservation,
                    lens: lens,
                    resource: row.resourceRow.resource,
                    warningCount: warningCount
                )
        )
        .accessibilityHint("Selects this reservation and opens its inspector")
        .accessibilityAddTraits(
            selectedReservation?.id == item.reservation.id ? [.isSelected] : []
        )
    }

    private func initialPositionAnchor(geometry: TimelineGeometry) -> some View {
        let minute = initialFocusMinute(window: geometry.window)
        let x = minute == geometry.window.startMinute
            ? 0
            : resourceWidth + CGFloat(geometry.x(forMinute: minute))
        return Color.clear
            .frame(width: 1, height: 1)
            .position(
                x: x,
                y: axisHeight + 1
            )
            .id(initialAnchorID)
            .accessibilityHidden(true)
    }

    private func positionInitially(reader: ScrollViewProxy, geometry: TimelineGeometry) {
        guard !hasPositionedInitially else { return }
        hasPositionedInitially = true
        DispatchQueue.main.async {
            reader.scrollTo(initialAnchorID, anchor: .top)
        }
    }

    private var initialAnchorID: String {
        "initial-time-\(focusReservationID ?? "default")"
    }

    private func initialFocusMinute(window: TimelineDayWindow) -> Double {
        if let focusReservationID,
           let reservation = session.reservations.first(where: {
               $0.id == focusReservationID
           }),
           let start = OperationalLocalClock.minute(
               reservation.startLocal,
               relativeTo: window.dayKey,
               boundary: .start
           ),
           let end = OperationalLocalClock.minute(
               reservation.endLocal,
               relativeTo: window.dayKey,
               boundary: .end
           ) {
            return min(window.endMinute, max(window.startMinute, (start + end) / 2))
        }
        if session.clock.dayKey(for: session.selectedDate) == session.todayKey {
            return window.startMinute
        }
        let first = session.selectedDayReservations
            .compactMap {
                OperationalLocalClock.minute(
                    $0.startLocal,
                    relativeTo: window.dayKey,
                    boundary: .start
                )
            }
            .min() ?? window.startMinute
        return min(window.endMinute, max(window.startMinute, first - 60))
    }

    private func currentTimeX(geometry: TimelineGeometry) -> CGFloat? {
        guard session.clock.dayKey(for: session.selectedDate) == session.todayKey else { return nil }
        let components = session.clock.calendar.dateComponents(
            [.hour, .minute, .second],
            from: referenceNow
        )
        let minute = Double((components.hour ?? 0) * 60 + (components.minute ?? 0))
            + Double(components.second ?? 0) / 60
        guard minute >= geometry.window.startMinute, minute <= geometry.window.endMinute else {
            return nil
        }
        return CGFloat(geometry.x(forMinute: minute))
    }

    private func hourMarks(_ window: TimelineDayWindow) -> [Double] {
        let first = ceil(window.startMinute / 60) * 60
        guard first <= window.endMinute else { return [] }
        return stride(from: first, through: window.endMinute, by: 60).map { $0 }
    }

    private func hourLabel(_ minute: Double) -> String {
        let hour = Int(minute / 60) % 24
        return String(format: "%02d:00", hour)
    }

    private var currentTimeLabel: String {
        let components = session.clock.calendar.dateComponents([.hour, .minute], from: referenceNow)
        let hour = components.hour ?? 0
        let minute = components.minute ?? 0
        return "\(String(format: "%02d", hour)):\(String(format: "%02d", minute))"
    }

    private var referenceNow: Date {
        session.previewScreen == nil ? currentTime : session.now
    }
}

private struct PositionedOperationsRow: Identifiable {
    let resourceRow: OperationsResourceRow
    let y: CGFloat
    let height: CGFloat
    let items: [PositionedOperationsItem]

    var id: OperationsResourceID { resourceRow.id }
}

private struct PositionedOperationsItem: Identifiable {
    let projection: OperationsProjectionItem
    let lane: Int

    var id: String { projection.id }
    var reservation: SchedulerReservation { projection.reservation }
}

private struct OperationsReservationBlock: View {
    let reservation: SchedulerReservation
    let lens: OperationsLens
    let resource: OperationsResource
    let width: CGFloat
    let warningCount: Int
    let selected: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            if width >= 85 {
                Text(compactTimeRange)
                .font(.system(size: 8, weight: .medium))
                .foregroundStyle(OperationsStyle.muted)
                .lineLimit(1)
            }
            Text(primaryIdentity)
                .font(.system(size: 10, weight: .semibold))
                .foregroundStyle(OperationsStyle.ink)
                .lineLimit(1)
            if width >= 104 {
                Text(secondaryIdentity)
                    .font(.system(size: 8.5))
                    .foregroundStyle(OperationsStyle.ink.opacity(0.82))
                    .lineLimit(1)
            }
            if width >= 134 {
                Text(reservation.title)
                    .font(.system(size: 8.5, weight: .medium))
                    .foregroundStyle(OperationsStyle.ink)
                    .lineLimit(1)
            }
        }
        .padding(.horizontal, width < 70 ? 5 : 8)
        .padding(.vertical, 6)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .background(blockFill)
        .clipShape(RoundedRectangle(cornerRadius: 6))
        .overlay(
            RoundedRectangle(cornerRadius: 6)
                .stroke(
                    selected ? IPCAColors.blue : blockBorder,
                    lineWidth: selected ? 2 : 0.8
                )
        )
        .overlay(alignment: .topTrailing) {
            if warningCount > 0 {
                Image(systemName: "exclamationmark.triangle.fill")
                    .font(.system(size: 10))
                    .foregroundStyle(IPCAColors.warning)
                    .padding(6)
                    .accessibilityLabel("\(warningCount) warnings")
            }
        }
        .opacity(reservation.isCompleted ? 0.64 : 1)
    }

    private var primaryIdentity: String {
        switch lens {
        case .aircraft, .instructors:
            return crew(role: "student").first ?? reservation.title
        case .students:
            return crew(role: "instructor").first ?? reservation.aircraft.registration
        }
    }

    private var secondaryIdentity: String {
        switch lens {
        case .aircraft:
            let instructors = crew(role: "instructor")
            guard let first = instructors.first else { return reservation.reservationTypeLabel }
            return instructors.count > 1 ? "with \(first) +\(instructors.count - 1)" : "with \(first)"
        case .instructors:
            return reservation.aircraft.registration
        case .students:
            return "\(reservation.aircraft.registration) · \(reservation.reservationTypeLabel)"
        }
    }

    private func crew(role: String) -> [String] {
        reservation.crew.compactMap {
            $0.role.lowercased() == role && !$0.personName.isEmpty ? $0.personName : nil
        }
    }

    private var compactTimeRange: String {
        func time(_ value: String) -> String {
            guard value.count >= 16 else { return value }
            return String(value.dropFirst(11).prefix(5))
        }
        return "\(time(reservation.startLocal)) – \(time(reservation.endLocal))"
    }

    private var blockBorder: Color {
        if warningCount > 0 { return Color(hex: 0xD7A34B) }
        switch reservation.status.lowercased() {
        case "active", "claimed": return Color(hex: 0x6B9BD3)
        case "completed": return Color(hex: 0x79B795)
        case "cancelled": return Color(hex: 0xA7ADB7)
        default: return Color(hex: 0x8AA8C9)
        }
    }

    private var blockFill: Color {
        if warningCount > 0 { return Color(hex: 0xFFF8E9) }
        switch reservation.status.lowercased() {
        case "active", "claimed": return Color(hex: 0xEDF4FD)
        case "completed": return Color(hex: 0xEFF8F2)
        case "cancelled": return Color(hex: 0xF1F2F4)
        default: return Color(hex: 0xF1F6FC)
        }
    }
}

