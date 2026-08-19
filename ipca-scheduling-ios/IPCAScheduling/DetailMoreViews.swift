import SwiftUI

struct ReservationDetailView: View {
    @EnvironmentObject private var session: SchedulingSession
    @State private var current: SchedulerReservation
    @State private var warnings: [SchedulerWarning]

    init(reservation: SchedulerReservation) {
        _current = State(initialValue: reservation)
        _warnings = State(initialValue: [])
    }

    var body: some View {
        ZStack {
            IPCAColors.background.ignoresSafeArea()
            ScrollView {
                VStack(spacing: 0) {
                    hero
                    VStack(spacing: IPCASpacing.standard) {
                        ForEach(warnings) { warning in
                            WarningCard(warning: warning)
                        }

                        detailSection("Aircraft") {
                            AircraftRow(aircraft: current.aircraft)
                        }

                        if current.mission != nil || current.cohort != nil {
                            detailSection("Training") {
                                if let mission = current.mission {
                                    DetailRow(
                                        icon: "book.closed",
                                        title: mission.name ?? current.reservationTypeLabel,
                                        subtitle: mission.code
                                    )
                                }
                                if let cohort = current.cohort, let name = cohort.name, !name.isEmpty {
                                    Divider()
                                    DetailRow(icon: "person.3", title: name, subtitle: "Cohort")
                                }
                            }
                        }

                        if !current.crew.isEmpty {
                            detailSection("People") {
                                ForEach(Array(current.crew.enumerated()), id: \.element.id) { index, person in
                                    if index > 0 { Divider() }
                                    PersonRow(person: person)
                                }
                            }
                        }

                        if current.route.airportChain.count >= 2 {
                            detailSection("Route") {
                                RouteView(route: current.route)
                            }
                        }

                        if !current.notes.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                            detailSection("Notes") {
                                Text(current.notes)
                                    .font(.body)
                                    .foregroundStyle(IPCAColors.text)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                            }
                        }

                        detailSection("Status") {
                            HStack {
                                StatusBadge(status: current.status, locked: current.lock.locked)
                                Spacer()
                                if current.lock.locked, let reason = current.lock.reason, !reason.isEmpty {
                                    Text(reason)
                                        .font(.subheadline)
                                        .foregroundStyle(IPCAColors.textSecondary)
                                        .multilineTextAlignment(.trailing)
                                }
                            }
                        }
                    }
                    .padding(IPCASpacing.screen)
                }
            }
        }
        .navigationTitle("Reservation")
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCAColors.background, for: .navigationBar)
        .toolbar(.hidden, for: .tabBar)
        .task {
            let response = await session.detail(for: current)
            current = response.reservation
            warnings = response.validation?.warnings ?? []
        }
    }

    private var hero: some View {
        VStack(alignment: .leading, spacing: IPCASpacing.medium) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 7) {
                    Text(current.reservationTypeLabel.uppercased())
                        .font(.caption.weight(.bold))
                        .tracking(1.2)
                        .foregroundStyle(.white.opacity(0.62))
                    Text(current.title)
                        .font(.system(.title2, design: .rounded, weight: .bold))
                        .foregroundStyle(.white)
                }
                Spacer(minLength: 12)
                StatusBadge(status: current.status, locked: current.lock.locked)
                    .background(.white, in: Capsule())
            }

            VStack(alignment: .leading, spacing: 5) {
                if let date = session.clock.date(fromLocal: current.startLocal) {
                    Text(session.clock.longDate(date))
                        .font(.subheadline)
                        .foregroundStyle(.white.opacity(0.72))
                }
                Text(session.clock.timeRange(start: current.startLocal, end: current.endLocal))
                    .font(.system(.title3, design: .rounded, weight: .bold))
                    .foregroundStyle(.white)
            }
        }
        .padding(.horizontal, IPCASpacing.screen)
        .padding(.top, IPCASpacing.large)
        .padding(.bottom, IPCASpacing.xLarge)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(IPCAColors.brandGradient)
    }

    private func detailSection<Content: View>(
        _ title: String,
        @ViewBuilder content: () -> Content
    ) -> some View {
        VStack(alignment: .leading, spacing: IPCASpacing.medium) {
            Text(title.uppercased())
                .font(.caption.weight(.bold))
                .tracking(1)
                .foregroundStyle(IPCAColors.textSecondary)
            content()
        }
        .padding(IPCASpacing.standard)
        .frame(maxWidth: .infinity, alignment: .leading)
        .ipcaCard()
    }
}

struct MoreView: View {
    @EnvironmentObject private var session: SchedulingSession

    var body: some View {
        ZStack {
            IPCAColors.background.ignoresSafeArea()
            ScrollView {
                VStack(spacing: IPCASpacing.large) {
                    accountCard
                    informationCard
                    Button(role: .destructive) {
                        Task { await session.signOut() }
                    } label: {
                        Label("Sign Out", systemImage: "rectangle.portrait.and.arrow.right")
                            .font(.headline)
                            .frame(maxWidth: .infinity)
                            .frame(height: 52)
                    }
                    .buttonStyle(.bordered)
                    .tint(IPCAColors.destructive)
                }
                .padding(IPCASpacing.screen)
            }
        }
        .safeAreaInset(edge: .top, spacing: 0) {
            BrandHeader(eyebrow: "IPCA", title: "More", subtitle: "Account and app information")
        }
        .toolbar(.hidden, for: .navigationBar)
    }

    private var accountCard: some View {
        VStack(spacing: IPCASpacing.standard) {
            HStack(spacing: IPCASpacing.standard) {
                ZStack {
                    Circle().fill(IPCAColors.actionGradient)
                    Text(initials)
                        .font(.headline.weight(.bold))
                        .foregroundStyle(.white)
                }
                .frame(width: 54, height: 54)
                .accessibilityHidden(true)

                VStack(alignment: .leading, spacing: 3) {
                    Text(session.user?.name ?? "IPCA User")
                        .font(.headline)
                        .foregroundStyle(IPCAColors.text)
                    if let email = session.user?.email {
                        Text(email)
                            .font(.subheadline)
                            .foregroundStyle(IPCAColors.textSecondary)
                    }
                }
                Spacer()
            }

            Divider()

            HStack {
                Label("Schedule access", systemImage: "checkmark.shield")
                Spacer()
                Text(session.capabilities?.scheduleRead == true ? "Active" : "Unavailable")
                    .foregroundStyle(session.capabilities?.scheduleRead == true ? IPCAColors.success : IPCAColors.destructive)
            }
            .font(.subheadline.weight(.medium))
        }
        .padding(IPCASpacing.large)
        .ipcaCard()
        .accessibilityElement(children: .combine)
    }

    private var informationCard: some View {
        VStack(spacing: 0) {
            infoRow(icon: "globe.americas", title: "Schedule timezone", value: session.operationalTimezone)
            Divider().padding(.leading, 48)
            infoRow(icon: "arrow.triangle.2.circlepath", title: "Last updated", value: updatedLabel)
            Divider().padding(.leading, 48)
            infoRow(icon: "info.circle", title: "Version", value: Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0")
        }
        .padding(.horizontal, IPCASpacing.standard)
        .ipcaCard()
    }

    private func infoRow(icon: String, title: String, value: String) -> some View {
        HStack(spacing: 12) {
            Image(systemName: icon)
                .foregroundStyle(IPCAColors.blue)
                .frame(width: 28)
                .accessibilityHidden(true)
            Text(title)
                .foregroundStyle(IPCAColors.text)
            Spacer()
            Text(value)
                .font(.subheadline)
                .foregroundStyle(IPCAColors.textSecondary)
                .multilineTextAlignment(.trailing)
        }
        .frame(minHeight: 52)
        .accessibilityElement(children: .combine)
    }

    private var initials: String {
        (session.user?.name ?? "IPCA")
            .split(separator: " ")
            .prefix(2)
            .compactMap(\.first)
            .map(String.init)
            .joined()
            .uppercased()
    }

    private var updatedLabel: String {
        session.lastUpdated?.formatted(date: .abbreviated, time: .shortened) ?? "Not yet"
    }
}
