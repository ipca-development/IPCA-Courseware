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
                                nextFlightSection(summary.nextFlight)
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
    private func nextFlightSection(_ flight: TrainingFlightDTO?) -> some View {
        IPCASectionHeader(title: "Next Flight")
        if let flight {
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
                    HStack(spacing: 6) {
                        Image(systemName: "airplane")
                            .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                        Text(flight.reservationLabel.isEmpty ? "Training Flight" : flight.reservationLabel)
                            .font(.headline)
                            .foregroundStyle(IPCATheme.Colors.lightText)
                    }
                    Text(flight.whenLabel)
                        .font(.subheadline)
                        .foregroundStyle(IPCATheme.Colors.lightSecondary)
                    if !flight.aircraftRegistration.isEmpty {
                        Text(flight.aircraftRegistration)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                    }
                    if !flight.withNames.isEmpty {
                        Text("Instructor: \(flight.withNames.joined(separator: ", "))")
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
            .padding(IPCATheme.Spacing.md)
            .background(IPCATheme.Colors.lightCard, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
            .padding(.horizontal, IPCATheme.Spacing.screen)
        } else {
            HStack(spacing: IPCATheme.Spacing.sm) {
                Image(systemName: "airplane")
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
                Text("No upcoming flights.")
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                Spacer()
            }
            .font(.subheadline)
            .padding(.horizontal, IPCATheme.Spacing.screen)
        }
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

    private func trainingActionCard(_ action: TrainingActionDTO) -> some View {
        let urgent = action.status.lowercased().contains("required") || action.status.lowercased().contains("urgent")
        return HStack(alignment: .top, spacing: IPCATheme.Spacing.sm) {
            IPCAIconTile(
                systemImage: urgent ? "doc.text.fill" : "bell.fill",
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
        let parser = DateFormatter()
        parser.locale = Locale(identifier: "en_US_POSIX")
        for format in formats {
            parser.dateFormat = format
            if let date = parser.date(from: flight.startsAt) {
                let weekday = date.formatted(.dateTime.weekday(.abbreviated)).uppercased()
                let day = date.formatted(.dateTime.day())
                let month = date.formatted(.dateTime.month(.abbreviated)).uppercased()
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
