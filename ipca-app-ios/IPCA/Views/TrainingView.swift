import SwiftUI

struct TrainingView: View {
    @EnvironmentObject private var session: AppSession
    @State private var summary: TrainingSummaryDTO?
    @State private var loading = true
    @State private var loadFailed = false

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                IPCARootHeader(title: "Training", subtitle: "Your training at a glance")
                Group {
                    if loading && summary == nil {
                        ProgressView()
                            .tint(IPCATheme.Colors.ipcaBlue)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if loadFailed, summary == nil {
                        ContentUnavailableView(
                            "Couldn't load Training",
                            systemImage: "graduationcap",
                            description: Text("Pull to try again. Messages still work independently.")
                        )
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                    } else if let summary {
                        ScrollView {
                            VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                                scheduleSection(summary)
                                theorySection(summary.theory)
                                actionsSection(summary.actions)
                                deadlinesSection(summary.deadlines)
                            }
                            .padding(.bottom, IPCATheme.Spacing.xl)
                        }
                        .refreshable { await reload() }
                    } else {
                        ContentUnavailableView("Training", systemImage: "graduationcap")
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                    }
                }
            }
            .background(IPCABackground())
            .toolbar(.hidden, for: .navigationBar)
            .task { await reload() }
            .refreshable { await reload() }
        }
    }

    @ViewBuilder
    private func scheduleSection(_ summary: TrainingSummaryDTO) -> some View {
        IPCASectionHeader(title: "Schedule")
        let flights = summary.schedule
        if flights.isEmpty {
            HStack(spacing: IPCATheme.Spacing.sm) {
                Image(systemName: "airplane")
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
                Text("No upcoming flights.")
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                Spacer()
            }
            .font(.subheadline)
            .padding(.horizontal, IPCATheme.Spacing.screen)
        } else {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                ForEach(Array(scheduleDays(flights).enumerated()), id: \.offset) { _, day in
                    VStack(alignment: .leading, spacing: IPCATheme.Spacing.xs) {
                        Text(day.dateLabel)
                            .font(.subheadline.weight(.bold))
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                            .padding(.horizontal, IPCATheme.Spacing.screen)
                        ForEach(day.flights) { flight in
                            scheduleCard(flight, isNext: flight.id == flights.first?.id)
                        }
                    }
                }
            }
        }
    }

    private func scheduleCard(_ flight: TrainingFlightDTO, isNext: Bool) -> some View {
        VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
            HStack(alignment: .top, spacing: IPCATheme.Spacing.sm) {
                if let parts = flightDateParts(flight) {
                    VStack(spacing: 2) {
                        Text(parts.weekday)
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.lightSecondary)
                        Text(parts.day)
                            .font(.title.bold())
                            .foregroundStyle(IPCATheme.Colors.lightText)
                        Text(parts.month)
                            .font(.caption.weight(.bold))
                            .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                    }
                    .frame(width: 52)
                }
                VStack(alignment: .leading, spacing: 6) {
                    HStack(spacing: 8) {
                        Text(flight.reservationLabel.isEmpty ? "Training Flight" : flight.reservationLabel)
                            .font(.headline)
                            .foregroundStyle(IPCATheme.Colors.lightText)
                        if isNext {
                            Text("Next Flight")
                                .font(.caption.weight(.bold))
                                .foregroundStyle(.white)
                                .padding(.horizontal, 8)
                                .padding(.vertical, 3)
                                .background(IPCATheme.Colors.ipcaBlue, in: Capsule())
                        }
                    }
                    if !flight.timeLabel.isEmpty {
                        Text(flight.timeLabel)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.lightSecondary)
                    } else if !flight.whenLabel.isEmpty {
                        Text(flight.whenLabel)
                            .font(.subheadline)
                            .foregroundStyle(IPCATheme.Colors.lightSecondary)
                    }
                    if !flight.route.isEmpty {
                        HStack(spacing: 6) {
                            Image(systemName: "airplane")
                                .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                            Text(flight.route)
                                .font(.title3.weight(.bold))
                                .foregroundStyle(IPCATheme.Colors.lightText)
                        }
                    }
                    if !displayedMission(flight).isEmpty {
                        Text(displayedMission(flight))
                            .font(.subheadline)
                            .foregroundStyle(IPCATheme.Colors.lightSecondary)
                    }
                    if !flight.aircraftRegistration.isEmpty {
                        Text(flight.aircraftRegistration)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                    }
                    ForEach(crewLines(flight), id: \.self) { line in
                        Text(line)
                            .font(.subheadline)
                            .foregroundStyle(IPCATheme.Colors.lightSecondary)
                    }
                    if !flight.role.isEmpty {
                        Text(flight.role)
                            .font(.caption)
                            .foregroundStyle(IPCATheme.Colors.lightSecondary)
                    }
                }
                Spacer(minLength: 0)
            }
        }
        .padding(IPCATheme.Spacing.md)
        .background(IPCATheme.Colors.lightCard, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
        .padding(.horizontal, IPCATheme.Spacing.screen)
    }

    private func scheduleDays(_ flights: [TrainingFlightDTO]) -> [(dateLabel: String, flights: [TrainingFlightDTO])] {
        var grouped: [String: [TrainingFlightDTO]] = [:]
        var order: [String] = []
        for flight in flights {
            let label = flight.dateLabel.isEmpty ? flight.whenLabel : flight.dateLabel
            if grouped[label] == nil {
                order.append(label)
                grouped[label] = []
            }
            grouped[label, default: []].append(flight)
        }
        return order.map { (dateLabel: $0, flights: grouped[$0] ?? []) }
    }

    private func displayedMission(_ flight: TrainingFlightDTO) -> String {
        if !flight.missionLabel.isEmpty {
            return flight.missionLabel
        }
        if !flight.missionCode.isEmpty, !flight.missionName.isEmpty, flight.missionCode != flight.missionName {
            return "\(flight.missionCode) · \(flight.missionName)"
        }
        return flight.missionName.isEmpty ? flight.missionCode : flight.missionName
    }

    private func crewLines(_ flight: TrainingFlightDTO) -> [String] {
        let others = flight.crew.filter { !$0.isSelf && !$0.name.isEmpty }
        if others.isEmpty {
            return flight.withNames.map { "Instructor: \($0)" }
        }
        return others.map { member in
            let label = member.roleLabel.isEmpty ? prettyCrewRole(member.role) : member.roleLabel
            return "\(label): \(member.name)"
        }
    }

    private func prettyCrewRole(_ role: String) -> String {
        let trimmed = role.replacingOccurrences(of: "_", with: " ").replacingOccurrences(of: "-", with: " ")
        return trimmed.split(separator: " ").map { $0.capitalized }.joined(separator: " ")
    }

    @ViewBuilder
    private func theorySection(_ theory: TrainingTheoryDTO) -> some View {
        IPCASectionHeader(title: "Training Progress")
        if theory.enrolled {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
                HStack(spacing: IPCATheme.Spacing.md) {
                    IPCACircularProgress(percent: theory.percent, caption: "Theory")
                    VStack(alignment: .leading, spacing: 8) {
                        Text(theory.programTitle.isEmpty ? theory.cohortName : theory.programTitle)
                            .font(.headline)
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                        if !theory.cohortName.isEmpty, theory.cohortName != theory.programTitle {
                            Text(theory.cohortName)
                                .font(.subheadline)
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                        }
                        ProgressView(value: Double(theory.percent), total: 100)
                            .tint(IPCATheme.Colors.ipcaBlue)
                        Text("\(theory.completedLessons) of \(theory.totalLessons) theory lessons complete")
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                    }
                }
            }
            .padding(IPCATheme.Spacing.md)
            .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                    .stroke(IPCATheme.Colors.separator, lineWidth: 1)
            )
            .padding(.horizontal, IPCATheme.Spacing.screen)
        } else {
            Text("No theory enrollment on this account.")
                .font(.subheadline)
                .foregroundStyle(IPCATheme.Colors.textSecondary)
                .padding(.horizontal, IPCATheme.Spacing.screen)
        }
    }

    @ViewBuilder
    private func actionsSection(_ actions: [TrainingActionDTO]) -> some View {
        if !actions.isEmpty {
            IPCASectionHeader(title: "Needs Attention", accessory: "\(actions.count)")
            ForEach(actions) { action in
                trainingActionCard(action)
            }
        }
    }

    @ViewBuilder
    private func trainingActionCard(_ action: TrainingActionDTO) -> some View {
        if action.remoteSessionCodeID != nil {
            Button {
                if let codeID = action.remoteSessionCodeID {
                    session.openRemoteSessionCode(codeID)
                }
            } label: {
                trainingActionCardBody(action, tappable: true)
            }
            .buttonStyle(.plain)
        } else {
            trainingActionCardBody(action, tappable: false)
        }
    }

    private func trainingActionCardBody(_ action: TrainingActionDTO, tappable: Bool) -> some View {
        let urgent = action.status.lowercased().contains("required") || action.status.lowercased().contains("urgent")
        return HStack(alignment: .top, spacing: IPCATheme.Spacing.sm) {
            IPCAIconTile(
                systemImage: tappable ? "key.fill" : (urgent ? "doc.text.fill" : "bell.fill"),
                foreground: urgent ? IPCATheme.Colors.destructive : IPCATheme.Colors.ipcaBlue
            )
            VStack(alignment: .leading, spacing: 4) {
                if !action.status.isEmpty {
                    IPCAStatusBadge(text: action.status, tone: urgent ? .urgent : .info)
                }
                Text(action.title)
                    .font(.body.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                if !action.subtitle.isEmpty {
                    Text(action.subtitle)
                        .font(.subheadline)
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                }
                if !action.dueAt.isEmpty {
                    Text(action.dueAt)
                        .font(.caption)
                        .foregroundStyle(urgent ? IPCATheme.Colors.destructive : IPCATheme.Colors.textTertiary)
                }
            }
            Spacer()
            if tappable {
                Image(systemName: "chevron.right")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
                    .padding(.top, 8)
            }
        }
        .padding(IPCATheme.Spacing.sm)
        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
        .overlay(alignment: .leading) {
            RoundedRectangle(cornerRadius: 2)
                .fill(urgent ? IPCATheme.Colors.destructive : IPCATheme.Colors.ipcaBlue)
                .frame(width: 3)
                .padding(.vertical, 10)
        }
        .overlay(
            RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                .stroke(IPCATheme.Colors.separator, lineWidth: 1)
        )
        .padding(.horizontal, IPCATheme.Spacing.screen)
    }

    @ViewBuilder
    private func deadlinesSection(_ deadlines: [TrainingDeadlineDTO]) -> some View {
        IPCASectionHeader(title: "Upcoming Deadlines")
        if deadlines.isEmpty {
            Text("No open lesson deadlines.")
                .font(.subheadline)
                .foregroundStyle(IPCATheme.Colors.textSecondary)
                .padding(.horizontal, IPCATheme.Spacing.screen)
        } else {
            VStack(spacing: IPCATheme.Spacing.xs) {
                ForEach(deadlines) { deadline in
                    HStack(spacing: IPCATheme.Spacing.sm) {
                        IPCAIconTile(systemImage: "calendar", size: 34)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(deadline.title)
                                .font(.subheadline.weight(.semibold))
                                .foregroundStyle(IPCATheme.Colors.textPrimary)
                            Text(deadline.dueLabel)
                                .font(.caption)
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                        }
                        Spacer()
                        if let days = deadline.daysLeft {
                            IPCAStatusBadge(
                                text: days == 0 ? "Today" : "In \(days) days",
                                tone: days <= 2 ? .urgent : (days <= 5 ? .attention : .info)
                            )
                        }
                    }
                    .padding(IPCATheme.Spacing.sm)
                    .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                }
            }
            .padding(.horizontal, IPCATheme.Spacing.screen)
        }
    }

    private func flightDateParts(_ flight: TrainingFlightDTO) -> (weekday: String, day: String, month: String)? {
        let formats = ["yyyy-MM-dd HH:mm:ss", "yyyy-MM-dd'T'HH:mm:ss", "yyyy-MM-dd"]
        let zone = TimeZone(identifier: flight.timeZone.isEmpty ? "America/Los_Angeles" : flight.timeZone)
            ?? TimeZone(identifier: "America/Los_Angeles")
        let parser = DateFormatter()
        parser.locale = Locale(identifier: "en_US_POSIX")
        parser.timeZone = zone
        for format in formats {
            parser.dateFormat = format
            if let date = parser.date(from: flight.startsAt) {
                let display = DateFormatter()
                display.locale = Locale(identifier: "en_US_POSIX")
                display.timeZone = zone
                display.dateFormat = "EEE"
                let weekday = display.string(from: date).uppercased()
                display.dateFormat = "d"
                let day = display.string(from: date)
                display.dateFormat = "MMM"
                let month = display.string(from: date).uppercased()
                return (weekday, day, month)
            }
        }
        return nil
    }

    private func reload() async {
        let loaded = await session.loadTraining()
        summary = loaded
        loadFailed = loaded == nil
        loading = false
    }
}
