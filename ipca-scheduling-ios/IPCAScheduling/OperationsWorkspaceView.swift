import SwiftUI

struct OperationsWorkspaceView: View {
    @EnvironmentObject private var session: SchedulingSession

    @State private var section: OperationsWorkspaceSection = .today
    @State private var lens: OperationsLens = .aircraft
    @State private var scale: TimelineScale = .standard
    @State private var selectedReservation: SchedulerReservation?
    @State private var focusReservationID: String?
    @State private var showSearch = false
    @State private var showFilters = false
    @State private var showDatePicker = false
    @State private var showFullDetails = false

    var body: some View {
        GeometryReader { proxy in
            let showsSidebar = proxy.size.width >= 900
                && session.previewScreen != .workstationPortrait
            HStack(spacing: 0) {
                if showsSidebar {
                    sidebar
                        .frame(width: proxy.size.width >= 1_200 ? 196 : 178)
                }
                workspaceContent
            }
            .background(OperationsStyle.canvas)
        }
        .sheet(isPresented: $showSearch) {
            OperationsSearchView(
                selectReservation: locate,
                close: { showSearch = false }
            )
            .environmentObject(session)
        }
        .popover(isPresented: $showDatePicker, arrowEdge: .top) {
            DatePicker(
                "Select date",
                selection: $session.selectedDate,
                displayedComponents: .date
            )
            .datePickerStyle(.graphical)
            .tint(IPCAColors.navy)
            .padding()
            .frame(width: 390)
        }
        .onAppear(perform: installPreviewState)
    }

    @ViewBuilder
    private var workspaceContent: some View {
        switch section {
        case .today:
            todayWorkspace
        case .week:
            OperationsWeekView(lens: $lens) { date in
                session.selectDate(date)
                selectedReservation = nil
                section = .today
            }
        case .more:
            NavigationStack { MoreView() }
        }
    }

    private var sidebar: some View {
        VStack(spacing: 0) {
            VStack(alignment: .leading, spacing: 6) {
                Image("IPCALogoWhite")
                    .resizable()
                    .scaledToFit()
                    .frame(width: 118)
                    .accessibilityLabel("IPCA")
                Text("SCHEDULING")
                    .font(.system(size: 9, weight: .semibold))
                    .tracking(2.2)
                    .foregroundStyle(.white.opacity(0.66))
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 20)
            .frame(height: 116)

            VStack(alignment: .leading, spacing: 3) {
                sidebarDestination("Today", icon: "calendar", destination: .today)
                sidebarDestination("Week", icon: "calendar.badge.clock", destination: .week)
                sidebarAction("Search", icon: "magnifyingglass") { showSearch = true }

                sidebarHeading("Resource Lens")
                    .padding(.top, 13)
                ForEach(OperationsLens.allCases) { item in
                    sidebarLens(item)
                }

                sidebarHeading("Filters")
                    .padding(.top, 13)
                sidebarAction("All Aircraft", icon: "airplane") { showFilters = true }
                sidebarAction("All Instructors", icon: "person") { showFilters = true }
                sidebarAction("All Cohorts", icon: "person.3") { showFilters = true }
                sidebarAction("All Types", icon: "line.3.horizontal.decrease") {
                    showFilters = true
                }

                Divider()
                    .overlay(.white.opacity(0.12))
                    .padding(.vertical, 8)
                sidebarDestination("More", icon: "ellipsis.circle", destination: .more)
                Spacer(minLength: 8)
            }
            .padding(.horizontal, 10)

            VStack(alignment: .leading, spacing: 4) {
                Text(session.user?.name ?? "IPCA User")
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundStyle(.white)
                    .lineLimit(1)
                if let role = session.user?.role, !role.isEmpty {
                    Text(role.replacingOccurrences(of: "_", with: " ").capitalized)
                        .font(.system(size: 10))
                        .foregroundStyle(.white.opacity(0.58))
                        .lineLimit(1)
                }
                HStack(spacing: 5) {
                    Circle()
                        .fill(session.isShowingCachedData ? IPCAColors.warning : Color(hex: 0x31C778))
                        .frame(width: 6, height: 6)
                    Text(session.isShowingCachedData ? "Saved schedule" : "Connected")
                        .font(.system(size: 10, weight: .medium))
                }
                .foregroundStyle(
                    session.isShowingCachedData ? IPCAColors.warning : Color(hex: 0x45D58B)
                )
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(14)
            .background(.white.opacity(0.035))
            .overlay(alignment: .top) {
                Divider().overlay(.white.opacity(0.1))
            }
        }
        .background(
            LinearGradient(
                colors: [OperationsStyle.sidebar, OperationsStyle.sidebarDeep],
                startPoint: .top,
                endPoint: .bottom
            )
        )
    }

    private var todayWorkspace: some View {
        GeometryReader { proxy in
            let compact = proxy.size.width < 1_060
            let persistentInspector = proxy.size.width >= 1_060
            VStack(spacing: 0) {
                commandHeader(compact: compact)
                if session.isShowingCachedData || !session.connectivity.isOnline {
                    OfflineBanner(
                        lastUpdated: session.lastUpdated,
                        isOffline: !session.connectivity.isOnline
                    )
                }
                dailySummary(compact: compact)
                HStack(spacing: 0) {
                    OperationsTimelineCanvas(
                        lens: lens,
                        scale: scale,
                        selectedReservation: $selectedReservation,
                        focusReservationID: focusReservationID,
                        resourceWidth: compact ? 132 : 144
                    )
                    .padding(.leading, 12)
                    .padding(.trailing, persistentInspector && selectedReservation != nil ? 0 : 12)
                    .padding(.top, 8)

                    if persistentInspector, let selectedReservation {
                        OperationsReservationInspector(
                            reservation: selectedReservation,
                            expanded: showFullDetails,
                            close: {
                                self.selectedReservation = nil
                                showFullDetails = false
                            },
                            toggleExpanded: { showFullDetails.toggle() }
                        )
                        .frame(
                            width: showFullDetails
                                ? min(540, max(480, proxy.size.width * 0.43))
                                : min(350, max(320, proxy.size.width * 0.28))
                        )
                        .padding(.top, 8)
                        .padding(.trailing, 12)
                        .transition(.move(edge: .trailing).combined(with: .opacity))
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .overlay(alignment: .trailing) {
                    if !persistentInspector, let selectedReservation {
                        OperationsReservationInspector(
                            reservation: selectedReservation,
                            expanded: showFullDetails,
                            close: {
                                self.selectedReservation = nil
                                showFullDetails = false
                            },
                            toggleExpanded: { showFullDetails.toggle() }
                        )
                        .frame(
                            width: showFullDetails
                                ? min(520, proxy.size.width * 0.76)
                                : min(340, proxy.size.width * 0.5)
                        )
                        .background(IPCAColors.surface)
                        .overlay(alignment: .leading) { Divider() }
                        .shadow(color: IPCAColors.navy.opacity(0.12), radius: 10, x: -4)
                        .transition(.move(edge: .trailing).combined(with: .opacity))
                    }
                }
                .animation(.easeInOut(duration: 0.2), value: selectedReservation?.id)
                bottomOperationsStrip(compact: compact)
                    .padding(.horizontal, 12)
                    .padding(.top, 8)
                    .padding(.bottom, 10)
            }
        }
    }

    private func commandHeader(compact: Bool) -> some View {
        HStack(spacing: compact ? 6 : 10) {
            if compact {
                Menu {
                    Button("Today", systemImage: "calendar") { section = .today }
                    Button("Week", systemImage: "calendar.badge.clock") { section = .week }
                    Button("Search", systemImage: "magnifyingglass") { showSearch = true }
                    Button("More", systemImage: "ellipsis.circle") { section = .more }
                } label: {
                    Image(systemName: "sidebar.left")
                        .font(.system(size: 12, weight: .semibold))
                        .foregroundStyle(OperationsStyle.ink)
                        .frame(width: 28, height: 28)
                }
                .accessibilityLabel("Workspaces")
            }
            Text(compact ? "IPCA" : "IPCA Scheduling")
                .font(.system(size: compact ? 17 : 19, weight: .bold))
                .foregroundStyle(OperationsStyle.ink)
                .lineLimit(1)

            HStack(spacing: 0) {
                headerIconButton("chevron.left", label: "Previous day") { changeDay(-1) }
                Divider().frame(height: 20)
                Button {
                    showDatePicker = true
                } label: {
                    HStack(spacing: 7) {
                        Image(systemName: "calendar")
                            .font(.system(size: 12, weight: .semibold))
                        Text(commandDate(compact: compact))
                        .lineLimit(1)
                    }
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundStyle(OperationsStyle.ink)
                    .padding(.horizontal, 10)
                    .frame(height: 34)
                }
                .buttonStyle(.plain)
                Divider().frame(height: 20)
                headerIconButton("chevron.right", label: "Next day") { changeDay(1) }
            }
            .background(Color.white)
            .clipShape(RoundedRectangle(cornerRadius: 9))
            .overlay(RoundedRectangle(cornerRadius: 9).stroke(OperationsStyle.line))

            Button("Today") {
                session.goToToday()
                selectedReservation = nil
            }
            .font(.system(size: 12, weight: .semibold))
            .foregroundStyle(OperationsStyle.ink)
            .buttonStyle(.plain)
            .padding(.horizontal, 12)
            .frame(height: 34)
            .background(Color.white)
            .clipShape(RoundedRectangle(cornerRadius: 9))
            .overlay(RoundedRectangle(cornerRadius: 9).stroke(OperationsStyle.line))

            lensSegment(compact: compact)
                .frame(width: compact ? 194 : 232)

            Menu {
                Picker("Timeline density", selection: $scale) {
                    ForEach(TimelineScale.allCases) { item in
                        Text(item.title).tag(item)
                    }
                }
            } label: {
                Image(systemName: "arrow.left.and.right")
                    .font(.system(size: 11, weight: .semibold))
                    .foregroundStyle(OperationsStyle.muted)
                    .frame(width: 28, height: 28)
            }
            .accessibilityLabel("Timeline density, \(scale.title)")

            Spacer(minLength: 4)

            if !compact {
                headerTextButton("magnifyingglass", title: "Search") { showSearch = true }
                    .keyboardShortcut("f", modifiers: .command)
                filterControl(compact: false)
            } else {
                headerIconButton("magnifyingglass", label: "Search") { showSearch = true }
                filterControl(compact: true)
            }

            let warningCount = warningReservations.count
            if warningCount > 0 {
                Button {
                    focus(warningReservations[0])
                } label: {
                    ZStack(alignment: .topTrailing) {
                        Image(systemName: "bell.fill")
                            .font(.system(size: 14))
                            .frame(width: 30, height: 30)
                        Text("\(warningCount)")
                            .font(.system(size: 8, weight: .bold))
                            .foregroundStyle(.white)
                            .frame(minWidth: 14, minHeight: 14)
                            .background(IPCAColors.destructive, in: Circle())
                            .offset(x: 3, y: -3)
                    }
                    .foregroundStyle(OperationsStyle.ink)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("\(warningCount) reservations need attention")
            }
        }
        .padding(.horizontal, 14)
        .frame(height: 62)
        .background(Color.white)
        .overlay(alignment: .bottom) { Divider().overlay(OperationsStyle.line) }
        .onChange(of: lens) { _, _ in preserveSelection() }
    }

    private func commandDate(compact: Bool) -> String {
        if compact {
            return session.selectedDate.formatted(
                .dateTime
                    .weekday(.abbreviated)
                    .month(.abbreviated)
                    .day()
                    .locale(Locale(identifier: "en_US"))
            )
        }
        return session.selectedDate.formatted(
            .dateTime
                .weekday(.abbreviated)
                .month(.abbreviated)
                .day()
                .year()
                .locale(Locale(identifier: "en_US"))
        )
    }

    private func dailySummary(compact: Bool) -> some View {
        let reservations = session.selectedDayReservations
        let aircraftCount = Set(reservations.map(\.aircraft.id)).count
        let activeCount = reservations.filter { $0.isInProgress }.count
        return HStack(spacing: 0) {
            summaryMetric(value: reservations.count, label: "Reservations")
            summaryMetric(value: aircraftCount, label: "Aircraft")
            summaryMetric(value: activeCount, label: "Active")
            summaryMetric(
                value: warningReservations.count,
                label: "Warnings",
                warning: !warningReservations.isEmpty
            )
            Spacer()
            timelineContext(compact: compact)
            if let updated = session.lastUpdated {
                Text("Updated \(session.clock.time(updated))")
                    .font(.system(size: 10, weight: .medium))
                    .foregroundStyle(OperationsStyle.muted)
            }
            Button {
                Task { await session.refresh(force: true) }
            } label: {
                if session.isRefreshing {
                    ProgressView().controlSize(.mini)
                } else {
                    Image(systemName: "arrow.clockwise")
                        .font(.system(size: 11, weight: .semibold))
                }
            }
            .buttonStyle(.plain)
            .foregroundStyle(OperationsStyle.muted)
            .frame(width: 32, height: 32)
        }
        .padding(.horizontal, 14)
        .frame(height: 50)
        .background(IPCAColors.surface)
        .overlay(alignment: .bottom) { Divider() }
        .accessibilityElement(children: .contain)
    }

    private func timelineContext(compact: Bool) -> some View {
        HStack(spacing: compact ? 6 : 9) {
            if session.astronomyDays.contains(where: {
                $0.date == session.clock.dayKey(for: session.selectedDate)
            }) {
                if !compact {
                    timelineLegendSwatch(Color(hex: 0xE7EDF4), label: "Night")
                    timelineLegendSwatch(
                        LinearGradient(
                            colors: [Color(hex: 0xE7EDF4), Color.white],
                            startPoint: .leading,
                            endPoint: .trailing
                        ),
                        label: "Civil Twilight"
                    )
                    timelineLegendSwatch(Color.white, label: "Day")
                }
                Text(session.operationalHomeBase?.airportIdentifier ?? "")
                    .font(.system(size: 9, weight: .semibold))
                    .foregroundStyle(OperationsStyle.ink)
            }
            Text("\(session.operationalTimezone) · 24H")
                .font(.system(size: 9, weight: .medium))
                .foregroundStyle(OperationsStyle.muted)
                .lineLimit(1)
        }
        .padding(.trailing, 12)
    }

    private func timelineLegendSwatch<S: ShapeStyle>(
        _ style: S,
        label: String
    ) -> some View {
        HStack(spacing: 4) {
            RoundedRectangle(cornerRadius: 2)
                .fill(style)
                .frame(width: 13, height: 8)
                .overlay(
                    RoundedRectangle(cornerRadius: 2)
                        .stroke(OperationsStyle.line, lineWidth: 0.6)
                )
            Text(label)
                .font(.system(size: 8.5, weight: .medium))
                .foregroundStyle(OperationsStyle.muted)
        }
    }

    private var warningReservations: [SchedulerReservation] {
        session.selectedDayReservations.filter {
            !(session.detailWarnings[$0.id] ?? []).isEmpty
        }
    }

    private func summaryMetric(value: Int, label: String, warning: Bool = false) -> some View {
        HStack(spacing: 7) {
            if warning {
                Image(systemName: "exclamationmark.triangle.fill")
                    .font(.system(size: 12))
                    .foregroundStyle(IPCAColors.warning)
            }
            Text("\(value)")
                .font(.system(size: 19, weight: .bold))
                .foregroundStyle(warning ? IPCAColors.warning : OperationsStyle.ink)
            Text(label)
                .font(.system(size: 10, weight: .medium))
                .foregroundStyle(OperationsStyle.muted)
        }
        .padding(.horizontal, 16)
        .overlay(alignment: .trailing) {
            Rectangle()
                .fill(OperationsStyle.line)
                .frame(width: 1, height: 28)
        }
    }

    private func lensSegment(compact: Bool) -> some View {
        HStack(spacing: 2) {
            ForEach(OperationsLens.allCases) { item in
                Button {
                    lens = item
                } label: {
                    Text(item.title)
                        .font(.system(size: compact ? 10 : 11, weight: .semibold))
                        .foregroundStyle(lens == item ? Color.white : OperationsStyle.ink)
                        .frame(maxWidth: .infinity)
                        .frame(height: 32)
                        .background(
                            lens == item ? OperationsStyle.ink : Color.clear,
                            in: RoundedRectangle(cornerRadius: 8)
                        )
                }
                .buttonStyle(.plain)
                .accessibilityAddTraits(lens == item ? .isSelected : [])
            }
        }
        .padding(2)
        .background(Color(hex: 0xF1F3F6), in: RoundedRectangle(cornerRadius: 10))
    }

    private func headerIconButton(
        _ icon: String,
        label: String,
        action: @escaping () -> Void
    ) -> some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.system(size: 12, weight: .semibold))
                .frame(width: 32, height: 32)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .foregroundStyle(OperationsStyle.ink)
        .accessibilityLabel(label)
    }

    private func headerTextButton(
        _ icon: String,
        title: String,
        action: @escaping () -> Void
    ) -> some View {
        Button(action: action) {
            Label(title, systemImage: icon)
                .font(.system(size: 11, weight: .semibold))
                .foregroundStyle(OperationsStyle.ink)
                .frame(height: 32)
        }
        .buttonStyle(.plain)
    }

    private func filterControl(compact: Bool) -> some View {
        Button {
            showFilters = true
        } label: {
            if compact {
                Image(systemName: session.filters.isEmpty
                      ? "line.3.horizontal"
                      : "line.3.horizontal.decrease.circle.fill")
                    .font(.system(size: 12, weight: .semibold))
                    .frame(width: 32, height: 32)
            } else {
                Label(
                    "Filters",
                    systemImage: session.filters.isEmpty
                        ? "line.3.horizontal"
                        : "line.3.horizontal.decrease.circle.fill"
                )
                .font(.system(size: 11, weight: .semibold))
                .frame(height: 32)
            }
        }
        .buttonStyle(.plain)
        .foregroundStyle(OperationsStyle.ink)
        .accessibilityLabel(session.filters.isEmpty ? "Filters" : "Filters applied")
        .popover(isPresented: $showFilters, arrowEdge: .top) {
            OperationsFilterPanel()
                .environmentObject(session)
                .frame(width: 372)
                .presentationCompactAdaptation(.popover)
        }
    }

    private func bottomOperationsStrip(compact: Bool) -> some View {
        let reservations = session.selectedDayReservations
        let next = nextReservation(in: reservations)
        let aircraftCount = Set(reservations.map(\.aircraft.id)).count
        let activeCount = reservations.filter(\.isInProgress).count
        return HStack(spacing: 0) {
            operationStripSection("Up Next") {
                if let next {
                    Text(session.clock.time(next.startLocal))
                        .font(.system(size: 11, weight: .bold))
                        .foregroundStyle(IPCAColors.blue)
                    Text(nextStudent(next) ?? next.title)
                        .font(.system(size: 11, weight: .semibold))
                        .lineLimit(1)
                    Text("\(next.aircraft.registration) – \(next.title)")
                        .font(.system(size: 9))
                        .foregroundStyle(OperationsStyle.muted)
                        .lineLimit(1)
                } else {
                    Text("No upcoming reservations")
                        .font(.system(size: 10))
                        .foregroundStyle(OperationsStyle.muted)
                }
            }

            operationStripSection("Attention") {
                if warningReservations.isEmpty {
                    Text("No warnings")
                        .font(.system(size: 10))
                        .foregroundStyle(OperationsStyle.muted)
                } else {
                    Label(
                        "\(warningReservations.count) warnings",
                        systemImage: "exclamationmark.triangle.fill"
                    )
                    .font(.system(size: 10, weight: .medium))
                    .foregroundStyle(IPCAColors.warning)
                    Button("Review") { focus(warningReservations[0]) }
                        .buttonStyle(.plain)
                        .font(.system(size: 9, weight: .semibold))
                        .foregroundStyle(IPCAColors.blue)
                }
            }

            operationStripSection("Day Summary") {
                Text("\(reservations.count) reservations  •  \(aircraftCount) aircraft")
                    .font(.system(size: 10, weight: .medium))
                Text("\(activeCount) active  •  \(warningReservations.count) warnings")
                    .font(.system(size: 9))
                    .foregroundStyle(OperationsStyle.muted)
            }

            if !compact {
                operationStripSection("Operational Timezone") {
                    Label(session.operationalTimezone, systemImage: "clock")
                        .font(.system(size: 10, weight: .medium))
                        .foregroundStyle(OperationsStyle.muted)
                        .lineLimit(1)
                }
            }
        }
        .frame(height: 88)
        .background(Color.white)
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(RoundedRectangle(cornerRadius: 12).stroke(OperationsStyle.line))
    }

    private func operationStripSection<Content: View>(
        _ title: String,
        @ViewBuilder content: () -> Content
    ) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title.uppercased())
                .font(.system(size: 8, weight: .semibold))
                .tracking(0.8)
                .foregroundStyle(OperationsStyle.muted)
            content()
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 18)
        .padding(.vertical, 11)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .overlay(alignment: .trailing) {
            Rectangle().fill(OperationsStyle.line).frame(width: 1)
        }
    }

    private func nextStudent(_ reservation: SchedulerReservation) -> String? {
        reservation.crew.first {
            $0.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() == "student"
        }?.personName
    }

    private func nextReservation(in reservations: [SchedulerReservation]) -> SchedulerReservation? {
        reservations
            .filter { !$0.isCompleted && !$0.isCancelled }
            .filter { session.clock.date(fromLocal: $0.startLocal).map { $0 > session.now } ?? false }
            .min { $0.startLocal < $1.startLocal }
    }

    private func changeDay(_ offset: Int) {
        session.moveDay(by: offset)
        if selectedReservation?.localDateKey != session.clock.dayKey(for: session.selectedDate) {
            selectedReservation = nil
        }
    }

    private func locate(_ reservation: SchedulerReservation) {
        guard let target = OperationsNavigator.target(for: reservation) else { return }
        if let date = session.clock.date(fromDayKey: target.dayKey) {
            session.selectDate(date)
        }
        lens = target.lens
        section = .today
        selectedReservation = reservation
        focusReservationID = nil
        DispatchQueue.main.async {
            focusReservationID = target.reservationID
        }
    }

    private func focus(_ reservation: SchedulerReservation) {
        selectedReservation = reservation
        focusReservationID = nil
        DispatchQueue.main.async { focusReservationID = reservation.id }
    }

    private func preserveSelection() {
        guard let selectedReservation else { return }
        let projection = OperationsProjector.project(
            reservations: session.selectedDayReservations,
            lens: lens,
            dayKey: session.clock.dayKey(for: session.selectedDate)
        )
        if !projection.contains(reservationID: selectedReservation.id) {
            self.selectedReservation = nil
        } else {
            focusReservationID = selectedReservation.id
        }
    }

    private func lensIcon(_ lens: OperationsLens) -> String {
        switch lens {
        case .aircraft: "airplane"
        case .instructors: "person.badge.shield.checkmark"
        case .students: "person.text.rectangle"
        }
    }

    private func sidebarDestination(
        _ title: String,
        icon: String,
        destination: OperationsWorkspaceSection
    ) -> some View {
        sidebarRow(title, icon: icon, selected: section == destination) {
            section = destination
        }
    }

    private func sidebarLens(_ item: OperationsLens) -> some View {
        sidebarRow(item.title, icon: lensIcon(item), selected: lens == item) {
            lens = item
            preserveSelection()
        }
    }

    private func sidebarAction(
        _ title: String,
        icon: String,
        action: @escaping () -> Void
    ) -> some View {
        sidebarRow(title, icon: icon, selected: false, action: action)
    }

    private func sidebarRow(
        _ title: String,
        icon: String,
        selected: Bool,
        action: @escaping () -> Void
    ) -> some View {
        Button {
            action()
        } label: {
            HStack(spacing: 11) {
                Image(systemName: icon)
                    .font(.system(size: 14, weight: .medium))
                    .frame(width: 18)
                Text(title)
                    .font(.system(size: 12, weight: selected ? .semibold : .regular))
                Spacer(minLength: 0)
            }
            .foregroundStyle(selected ? Color.white : Color.white.opacity(0.84))
            .padding(.horizontal, 12)
            .frame(height: 38)
            .background(
                selected ? Color(hex: 0x1D4F8F) : Color.clear,
                in: RoundedRectangle(cornerRadius: 7)
            )
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(selected ? .isSelected : [])
    }

    private func sidebarHeading(_ title: String) -> some View {
        Text(title.uppercased())
            .font(.system(size: 9, weight: .semibold))
            .tracking(0.9)
            .foregroundStyle(.white.opacity(0.46))
            .padding(.horizontal, 12)
            .padding(.bottom, 3)
    }

    private func installPreviewState() {
        if session.previewScreen?.isWorkstation == true
            && session.previewScreen != .workstationPortrait
            && session.previewScreen != .workstationNarrow {
            requestLandscapePreview()
        }
        switch session.previewScreen {
        case .workstationInstructor:
            lens = .instructors
        case .workstationStudent:
            lens = .students
        case .workstationInspector:
            selectedReservation = SchedulerFixtures.workstationFeaturedReservation
            focusReservationID = selectedReservation?.id
        case .workstationWarning:
            selectedReservation = SchedulerFixtures.workstationWarningReservation
            focusReservationID = selectedReservation?.id
        case .workstationFullDay:
            scale = .fullDay
        case .workstationDetailed:
            scale = .detailed
        case .workstationWeek:
            section = .week
        case .workstationWeekInstructor:
            section = .week
            lens = .instructors
        case .workstationWeekStudent:
            section = .week
            lens = .students
        case .workstationWeekWarning:
            section = .week
            lens = .aircraft
        case .workstationWeekSparse:
            section = .week
            lens = .aircraft
        case .workstationPortrait:
            break
        case .workstationFilters:
            DispatchQueue.main.async {
                showFilters = true
            }
        case .workstationExpanded:
            selectedReservation = SchedulerFixtures.workstationFeaturedReservation
            focusReservationID = selectedReservation?.id
            showFullDetails = true
        case .workstationCrew:
            selectedReservation = SchedulerFixtures.workstationCrewReservation
            focusReservationID = selectedReservation?.id
        case .workstationNarrow:
            selectedReservation = SchedulerFixtures.workstationFeaturedReservation
            focusReservationID = selectedReservation?.id
        case .workstationTwilightMorning:
            scale = .standard
            focusReservationID = SchedulerFixtures.twilightMorningReservation.id
        case .workstationTwilightEvening:
            scale = .fullDay
            focusReservationID = SchedulerFixtures.twilightEveningReservation.id
        case .workstationTwilightFullDay:
            scale = .fullDay
        case .workstationTwilightMorningSelected:
            scale = .standard
            selectedReservation = SchedulerFixtures.twilightMorningReservation
            focusReservationID = selectedReservation?.id
        case .workstationTwilightEveningSelected:
            scale = .fullDay
            selectedReservation = SchedulerFixtures.twilightEveningReservation
            focusReservationID = selectedReservation?.id
        default:
            break
        }
    }

    private func requestLandscapePreview() {
        guard let scene = UIApplication.shared.connectedScenes
            .compactMap({ $0 as? UIWindowScene })
            .first else {
            return
        }
        scene.requestGeometryUpdate(.iOS(interfaceOrientations: .landscape)) { _ in }
    }
}

private enum OperationsWorkspaceSection: Hashable {
    case today
    case week
    case more
}

enum OperationsStyle {
    static let sidebar = Color(hex: 0x16233C)
    static let sidebarDeep = Color(hex: 0x0B1830)
    static let canvas = Color(hex: 0xF5F6F8)
    static let ink = Color(hex: 0x16233C)
    static let muted = Color(hex: 0x667286)
    static let line = Color(hex: 0xE1E5EB)
    static let gridMinor = Color(hex: 0xEEF1F4)
    static let gridMajor = Color(hex: 0xE1E5EA)
}

private struct OperationsFilterPanel: View {
    @EnvironmentObject private var session: SchedulingSession
    @State private var aircraft: [SchedulerResourceItem] = []
    @State private var people: [SchedulerResourceItem] = []

    var body: some View {
        VStack(spacing: 0) {
            HStack {
                Text("Filters")
                    .font(.system(size: 17, weight: .bold))
                    .foregroundStyle(OperationsStyle.ink)
                Spacer()
                Button("Reset") { apply(.empty) }
                    .buttonStyle(.plain)
                    .font(.system(size: 11, weight: .semibold))
                    .foregroundStyle(
                        session.filters.isEmpty ? OperationsStyle.muted : IPCAColors.blue
                    )
                    .disabled(session.filters.isEmpty)
            }
            .padding(.horizontal, 16)
            .frame(height: 50)

            Divider().overlay(OperationsStyle.line)

            aircraftMenu
            rowDivider
            personMenu
            rowDivider
            cohortMenu
            rowDivider
            typeMenu
        }
        .background(Color.white)
        .clipShape(RoundedRectangle(cornerRadius: 13))
        .overlay(
            RoundedRectangle(cornerRadius: 13)
                .stroke(OperationsStyle.line)
        )
        .shadow(color: OperationsStyle.ink.opacity(0.09), radius: 12, y: 5)
        .task {
            async let aircraftOptions = session.resourceOptions(type: "aircraft")
            async let peopleOptions = session.resourceOptions(type: "person")
            aircraft = await aircraftOptions
            people = await peopleOptions
        }
    }

    private var aircraftMenu: some View {
        Menu {
            Button("All Aircraft") {
                var filters = session.filters
                filters.aircraftID = nil
                filters.aircraftLabel = nil
                apply(filters)
            }
            ForEach(aircraft) { item in
                Button(item.label) {
                    var filters = session.filters
                    filters.aircraftID = item.id
                    filters.aircraftLabel = item.label
                    apply(filters)
                }
            }
        } label: {
            filterRow(
                title: "Aircraft",
                value: session.filters.aircraftLabel ?? "All Aircraft"
            )
        }
    }

    private var personMenu: some View {
        Menu {
            Button("All Instructors") {
                var filters = session.filters
                filters.participantUserID = nil
                filters.participantLabel = nil
                apply(filters)
            }
            ForEach(people) { item in
                Button(item.label) {
                    var filters = session.filters
                    filters.participantUserID = item.id
                    filters.participantLabel = item.label
                    apply(filters)
                }
            }
        } label: {
            filterRow(
                title: "Instructor / Person",
                value: session.filters.participantLabel ?? "All Instructors"
            )
        }
    }

    private var cohortMenu: some View {
        Menu {
            Button("All Cohorts") {
                var filters = session.filters
                filters.cohortID = nil
                filters.cohortLabel = nil
                apply(filters)
            }
            ForEach(cohortOptions, id: \.0) { id, name in
                Button(name) {
                    var filters = session.filters
                    filters.cohortID = id
                    filters.cohortLabel = name
                    apply(filters)
                }
            }
        } label: {
            filterRow(
                title: "Cohort",
                value: session.filters.cohortLabel ?? "All Cohorts"
            )
        }
    }

    private var typeMenu: some View {
        Menu {
            Button("All Types") {
                var filters = session.filters
                filters.reservationType = nil
                apply(filters)
            }
            ForEach(typeOptions, id: \.0) { value, label in
                Button(label) {
                    var filters = session.filters
                    filters.reservationType = value
                    apply(filters)
                }
            }
        } label: {
            filterRow(
                title: "Reservation Type",
                value: typeOptions.first {
                    $0.0 == session.filters.reservationType
                }?.1 ?? "All Types"
            )
        }
    }

    private func filterRow(title: String, value: String) -> some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 4) {
                Text(title.uppercased())
                    .font(.system(size: 9, weight: .semibold))
                    .tracking(0.6)
                    .foregroundStyle(OperationsStyle.muted)
                Text(value)
                    .font(.system(size: 12, weight: .medium))
                    .foregroundStyle(OperationsStyle.ink)
                    .lineLimit(1)
            }
            Spacer()
            Image(systemName: "chevron.right")
                .font(.system(size: 10, weight: .semibold))
                .foregroundStyle(OperationsStyle.muted)
        }
        .padding(.horizontal, 16)
        .frame(height: 62)
        .contentShape(Rectangle())
    }

    private var rowDivider: some View {
        Rectangle()
            .fill(OperationsStyle.line)
            .frame(height: 1)
            .padding(.leading, 16)
    }

    private var cohortOptions: [(Int, String)] {
        var found: [Int: String] = [:]
        for reservation in session.reservations {
            if let id = reservation.cohort?.id,
               let name = reservation.cohort?.name,
               !name.isEmpty {
                found[id] = name
            }
        }
        return found.sorted { $0.value < $1.value }
    }

    private var typeOptions: [(String, String)] {
        var found: [String: String] = [:]
        for reservation in session.reservations {
            found[reservation.reservationType] = reservation.reservationTypeLabel
        }
        return found.sorted { $0.value < $1.value }
    }

    private func apply(_ filters: ScheduleFilters) {
        Task { await session.applyFilters(filters) }
    }
}
